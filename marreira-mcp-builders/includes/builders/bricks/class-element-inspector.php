<?php
/**
 * Element_Inspector: introspecciona os elementos (widgets) registrados do Bricks
 * para expor o schema de settings de cada um.
 *
 * @package Marreira\MCP_Builders
 */

namespace Marreira\MCP_Builders\Builders\Bricks;

use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Lê o registro `\Bricks\Elements::$elements` e os controles (`set_controls`)
 * de cada elemento — incluindo elementos do Bricks Pro e de terceiros.
 *
 * Apenas leitura: instancia o elemento e chama `set_control_groups()` +
 * `set_controls()` (nunca `load()`/`init()`, que disparam render). Tudo
 * protegido por try/catch para nunca causar fatal.
 */
class Element_Inspector {

	/**
	 * Indica se o Bricks esta disponivel para introspeccao.
	 *
	 * @return bool
	 */
	public static function available() {
		return class_exists( '\Bricks\Elements' ) && isset( \Bricks\Elements::$elements );
	}

	/**
	 * Lista os elementos registrados (resumo, sem schema).
	 *
	 * @return array
	 */
	public static function list_all() {
		if ( ! self::available() ) {
			return array();
		}

		$out = array();
		foreach ( \Bricks\Elements::$elements as $key => $data ) {
			$name     = is_array( $data ) && ! empty( $data['name'] ) ? (string) $data['name'] : (string) $key;
			$instance = self::instantiate( $name, $data );

			$out[] = array(
				'name'     => $name,
				'label'    => $instance ? self::safe_label( $instance, $name ) : $name,
				'category' => $instance && isset( $instance->category ) ? $instance->category : ( is_array( $data ) && isset( $data['category'] ) ? $data['category'] : '' ),
				'nestable' => $instance && isset( $instance->nestable ) ? (bool) $instance->nestable : false,
				'icon'     => $instance && isset( $instance->icon ) ? $instance->icon : '',
			);
		}

		usort(
			$out,
			static function ( $a, $b ) {
				return strcmp( $a['name'], $b['name'] );
			}
		);

		return $out;
	}

	/**
	 * Retorna o schema de settings de um elemento.
	 *
	 * @param string $name    Nome do elemento (ex.: "heading", "form", "slider-nested").
	 * @param bool   $include_css Se true, inclui o mapeamento CSS de cada controle.
	 * @return array|WP_Error
	 */
	public static function get_schema( $name, $include_css = false ) {
		if ( ! self::available() ) {
			return new WP_Error( 'mmb_bricks_off', __( 'Bricks nao esta ativo.', 'marreira-mcp-builders' ), array( 'status' => 400 ) );
		}

		$name = trim( (string) $name );
		$reg  = \Bricks\Elements::$elements;
		$data = null;

		if ( isset( $reg[ $name ] ) ) {
			$data = $reg[ $name ];
		} else {
			// Procura pelo campo 'name' caso a chave seja diferente.
			foreach ( $reg as $d ) {
				if ( is_array( $d ) && isset( $d['name'] ) && $d['name'] === $name ) {
					$data = $d;
					break;
				}
			}
		}

		if ( null === $data ) {
			return new WP_Error(
				'mmb_element_missing',
				sprintf( /* translators: %s: element name */ __( 'Elemento "%s" nao encontrado. Use list_elements para ver os nomes validos.', 'marreira-mcp-builders' ), $name ),
				array( 'status' => 404 )
			);
		}

		$instance = self::instantiate( $name, $data );
		if ( ! $instance ) {
			return new WP_Error( 'mmb_element_load', sprintf( /* translators: %s: element name */ __( 'Nao foi possivel carregar a classe do elemento "%s".', 'marreira-mcp-builders' ), $name ), array( 'status' => 422 ) );
		}

		try {
			if ( method_exists( $instance, 'set_control_groups' ) ) {
				$instance->set_control_groups();
			}
			if ( method_exists( $instance, 'set_controls' ) ) {
				$instance->set_controls();
			}
		} catch ( \Throwable $e ) {
			return new WP_Error( 'mmb_element_controls', $e->getMessage(), array( 'status' => 500 ) );
		}

		$controls = isset( $instance->controls ) && is_array( $instance->controls ) ? $instance->controls : array();
		$groups   = isset( $instance->control_groups ) && is_array( $instance->control_groups ) ? $instance->control_groups : array();

		return array(
			'name'           => $name,
			'label'          => self::safe_label( $instance, $name ),
			'category'       => isset( $instance->category ) ? $instance->category : '',
			'nestable'       => isset( $instance->nestable ) ? (bool) $instance->nestable : false,
			'control_groups' => $groups,
			'controls'       => self::trim_controls( $controls, $include_css ),
			'note'           => __( 'Chaves com prefixo "_" (ex.: _typography, _padding) sao settings universais de estilo, comuns a todos os elementos.', 'marreira-mcp-builders' ),
		);
	}

	/**
	 * Instancia um elemento de forma segura (sem efeitos colaterais de render).
	 *
	 * @param string $name Nome do elemento.
	 * @param mixed  $data Entrada do registro.
	 * @return object|null
	 */
	private static function instantiate( $name, $data ) {
		$class = is_array( $data ) && ! empty( $data['class'] ) ? $data['class'] : '';

		// Garante que o arquivo da classe esteja carregado.
		if ( ( '' === $class || ! class_exists( $class ) ) && is_array( $data ) && ! empty( $data['file'] ) && is_readable( $data['file'] ) ) {
			require_once $data['file'];
		}

		if ( '' === $class || ! class_exists( $class ) ) {
			$class = self::class_from_name( $name );
		}

		if ( ! class_exists( $class ) ) {
			return null;
		}

		try {
			return new $class(
				array(
					'id'       => 'schemaprobe',
					'name'     => $name,
					'settings' => array(),
					'parent'   => 0,
					'children' => array(),
				)
			);
		} catch ( \Throwable $e ) {
			return null;
		}
	}

	/**
	 * Deriva o nome da classe a partir do nome do elemento.
	 * Ex.: "text-basic" => \Bricks\Element_Text_Basic.
	 *
	 * @param string $name Nome.
	 * @return string
	 */
	private static function class_from_name( $name ) {
		$parts = preg_split( '/[-_]/', $name );
		$camel = implode( '_', array_map( 'ucfirst', $parts ) );
		return '\\Bricks\\Element_' . $camel;
	}

	/**
	 * Obtem o label do elemento com seguranca.
	 *
	 * @param object $instance Instancia.
	 * @param string $fallback Fallback.
	 * @return string
	 */
	private static function safe_label( $instance, $fallback ) {
		try {
			if ( method_exists( $instance, 'get_label' ) ) {
				$l = $instance->get_label();
				if ( is_string( $l ) && '' !== $l ) {
					return $l;
				}
			}
		} catch ( \Throwable $e ) {
			// ignora.
		}
		return $fallback;
	}

	/**
	 * Reduz cada controle ao essencial para a IA (tipo, label, default, opcoes).
	 *
	 * @param array $controls    Controles brutos.
	 * @param bool  $include_css Inclui o mapeamento CSS.
	 * @return array
	 */
	private static function trim_controls( $controls, $include_css ) {
		$out = array();
		foreach ( $controls as $key => $c ) {
			if ( ! is_array( $c ) ) {
				continue;
			}
			$entry = array(
				'type'  => isset( $c['type'] ) ? $c['type'] : '',
				'label' => isset( $c['label'] ) ? $c['label'] : '',
				'tab'   => isset( $c['tab'] ) ? $c['tab'] : '',
			);
			if ( isset( $c['group'] ) ) {
				$entry['group'] = $c['group'];
			}
			if ( array_key_exists( 'default', $c ) ) {
				$entry['default'] = $c['default'];
			}
			if ( isset( $c['options'] ) ) {
				$entry['options'] = $c['options'];
			}
			if ( isset( $c['placeholder'] ) ) {
				$entry['placeholder'] = $c['placeholder'];
			}
			if ( isset( $c['description'] ) ) {
				$entry['description'] = $c['description'];
			}
			if ( isset( $c['required'] ) ) {
				$entry['required'] = $c['required'];
			}
			if ( $include_css && isset( $c['css'] ) ) {
				$entry['css'] = $c['css'];
			}
			$out[ $key ] = $entry;
		}
		return $out;
	}
}
