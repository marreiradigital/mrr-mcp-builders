<?php
/**
 * Element_Inspector: introspecta os widgets registrados no Elementor.
 *
 * Expoe o catalogo de widgets (lista e schema de controles) de forma segura,
 * sem disparar render. Toda interacao com a API do Elementor e protegida por
 * try/catch para nunca causar fatal na requisicao MCP.
 *
 * @package Marreira\MCP_Builders
 */

namespace Marreira\MCP_Builders\Builders\Elementor;

use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Le o registro `widgets_manager->get_widget_types()` e os controles de cada
 * widget via `get_controls()` / `get_stack()`.
 *
 * Apenas leitura — nunca instancia um widget no contexto de render (sem HTML gerado).
 */
class Element_Inspector {

	/**
	 * Indica se o Elementor esta disponivel para introspeccao.
	 *
	 * Verifica a presenca do Plugin singleton e do widgets_manager inicializado.
	 *
	 * @return bool
	 */
	public static function available() {
		return class_exists( '\Elementor\Plugin' )
			&& isset( \Elementor\Plugin::$instance->widgets_manager );
	}

	/**
	 * Lista todos os widgets registrados (resumo, sem schema de controles).
	 *
	 * Itera `get_widget_types()` — assoc array keyed by name => instance.
	 * Cada widget que lancar excecao e silenciosamente ignorado.
	 * Resultado ordenado por name.
	 *
	 * @return array
	 */
	public static function list_all() {
		if ( ! self::available() ) {
			return array();
		}

		$out          = array();
		$widget_types = array();

		try {
			$widget_types = \Elementor\Plugin::$instance->widgets_manager->get_widget_types();
		} catch ( \Throwable $e ) {
			return array();
		}

		if ( ! is_array( $widget_types ) ) {
			return array();
		}

		foreach ( $widget_types as $w ) {
			try {
				$out[] = array(
					'name'       => $w->get_name(),
					'title'      => $w->get_title(),
					'categories' => (array) $w->get_categories(),
					'icon'       => method_exists( $w, 'get_icon' ) ? $w->get_icon() : '',
					'pro'        => self::is_pro_widget( $w ),
				);
			} catch ( \Throwable $e ) {
				// Widget quebrado: ignora e continua.
				continue;
			}
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
	 * Retorna o schema de controles de um widget especifico.
	 *
	 * Usa `get_controls()` (aciona a inicializacao lazy da stack de controles).
	 * Se retornar vazio, tenta `get_stack(false)['controls']` como fallback.
	 *
	 * @param string $widget_type  Nome do widget (ex.: "heading", "button").
	 * @param bool   $include_full Se true, inclui `selectors` e `condition` brutos.
	 * @return array|WP_Error Schema reduzido ou WP_Error em caso de falha.
	 */
	public static function get_schema( $widget_type, $include_full = false ) {
		if ( ! self::available() ) {
			return new WP_Error(
				'mme_elementor_off',
				__( 'Elementor não está ativo ou não foi inicializado.', 'marreira-mcp-builders' ),
				array( 'status' => 503 )
			);
		}

		$widget_type = trim( (string) $widget_type );
		$w           = null;

		try {
			$widget_types = \Elementor\Plugin::$instance->widgets_manager->get_widget_types();
			if ( is_array( $widget_types ) && isset( $widget_types[ $widget_type ] ) ) {
				$w = $widget_types[ $widget_type ];
			}
		} catch ( \Throwable $e ) {
			return new WP_Error( 'mme_widgets_error', $e->getMessage(), array( 'status' => 500 ) );
		}

		if ( null === $w ) {
			return new WP_Error(
				'mme_widget_missing',
				/* translators: %s: nome do widget solicitado */
				sprintf(
					__( 'Widget "%s" não encontrado. Use list_elements para ver os nomes válidos.', 'marreira-mcp-builders' ),
					esc_html( $widget_type )
				),
				array( 'status' => 404 )
			);
		}

		// Leitura dos controles — get_controls() aciona a stack lazy.
		$controls = array();
		try {
			$controls = $w->get_controls();

			// Fallback: se get_controls() retornar vazio, tenta get_stack().
			if ( empty( $controls ) && method_exists( $w, 'get_stack' ) ) {
				$stack    = $w->get_stack( false );
				$controls = isset( $stack['controls'] ) && is_array( $stack['controls'] )
					? $stack['controls']
					: array();
			}
		} catch ( \Throwable $e ) {
			return new WP_Error( 'mme_controls_error', $e->getMessage(), array( 'status' => 500 ) );
		}

		// Campos basicos do widget.
		$name       = '';
		$title      = '';
		$categories = array();
		$pro        = false;
		try {
			$name       = $w->get_name();
			$title      = $w->get_title();
			$categories = (array) $w->get_categories();
			$pro        = self::is_pro_widget( $w );
		} catch ( \Throwable $e ) {
			$name = $widget_type;
		}

		return array(
			'name'       => $name,
			'title'      => $title,
			'categories' => $categories,
			'pro'        => $pro,
			'controls'   => self::trim_controls( (array) $controls, $include_full ),
			'note'       => __(
				'Configurações responsivas usam sufixo _tablet/_mobile (ex.: font_size_tablet). Grupos de controle (ex.: typography_, _padding) expandem em várias chaves no settings do widget. Para referenciar cores/fontes globais, use a chave __globals__ com o formato "globals/colors?id=<_id>" ou "globals/typography?id=<_id>".',
				'marreira-mcp-builders'
			),
		);
	}

	// -------------------------------------------------------------------------
	// Metodos privados de suporte
	// -------------------------------------------------------------------------

	/**
	 * Verifica se o widget pertence ao Elementor Pro.
	 *
	 * Considera Pro se o nome da classe iniciar com `ElementorPro\`.
	 * O check usa ltrim + strpos para ser robusto contra diferentes representacoes
	 * de namespace (com ou sem barra inicial).
	 *
	 * @param object $w Instancia do widget.
	 * @return bool
	 */
	private static function is_pro_widget( $w ) {
		$class = get_class( $w );
		// Normaliza para sempre ter barra inicial e busca o prefixo do namespace Pro.
		return false !== strpos( '\\' . ltrim( $class, '\\' ), '\\ElementorPro\\' );
	}

	/**
	 * Reduz cada controle ao essencial para consumo pela IA.
	 *
	 * Campos sempre presentes: type, label, tab, section.
	 * Campos condicionais: default, options, placeholder, description, responsive.
	 * Com $include_full: selectors, condition (brutos do Elementor).
	 *
	 * @param array $controls    Array de controles brutos do widget.
	 * @param bool  $include_full Inclui selectors/condition quando true.
	 * @return array Mapa keyed pelo nome do controle.
	 */
	private static function trim_controls( array $controls, $include_full ) {
		$out = array();

		foreach ( $controls as $key => $c ) {
			if ( ! is_array( $c ) ) {
				continue;
			}

			$entry = array(
				'type'    => isset( $c['type'] ) ? $c['type'] : '',
				'label'   => isset( $c['label'] ) ? $c['label'] : '',
				'tab'     => isset( $c['tab'] ) ? $c['tab'] : '',
				'section' => isset( $c['section'] ) ? $c['section'] : '',
			);

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
			if ( ! empty( $c['responsive'] ) ) {
				$entry['responsive'] = true;
			}

			if ( $include_full ) {
				if ( isset( $c['selectors'] ) ) {
					$entry['selectors'] = $c['selectors'];
				}
				if ( isset( $c['condition'] ) ) {
					$entry['condition'] = $c['condition'];
				}
			}

			$out[ $key ] = $entry;
		}

		return $out;
	}
}
