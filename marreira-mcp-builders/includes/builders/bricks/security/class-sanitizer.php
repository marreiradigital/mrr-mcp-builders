<?php
/**
 * Sanitizer: limpeza e validacao de dados vindos da IA antes de persistir.
 *
 * @package Marreira\MCP_Builders
 */

namespace Marreira\MCP_Builders\Builders\Bricks\Security;

use Marreira\MCP_Builders\Builders\Bricks\Code_Guard;
use Marreira\MCP_Builders\Builders\Bricks\Element_Tree;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Garante que apenas estruturas seguras (arrays e escalares) sejam gravadas,
 * rejeitando objetos/recursos e aplicando os guards de codigo e integridade.
 */
class Sanitizer {

	/**
	 * Profundidade maxima permitida em estruturas aninhadas (anti-DoS).
	 *
	 * @var int
	 */
	const MAX_DEPTH = 30;

	/**
	 * Limpa recursivamente um valor, mantendo apenas arrays e escalares.
	 *
	 * @param mixed $value Valor.
	 * @param int   $depth Profundidade atual.
	 * @return mixed|WP_Error
	 */
	public static function deep_clean( $value, $depth = 0 ) {
		if ( $depth > self::MAX_DEPTH ) {
			return new WP_Error( 'mmb_too_deep', __( 'Estrutura aninhada profunda demais.', 'marreira-mcp-builders' ), array( 'status' => 422 ) );
		}

		if ( is_array( $value ) ) {
			$clean = array();
			foreach ( $value as $k => $v ) {
				// Chaves apenas escalares.
				if ( ! is_string( $k ) && ! is_int( $k ) ) {
					continue;
				}
				// Nunca aceitar uma assinatura de codigo vinda da IA: so um humano
				// pode assinar codigo no editor do Bricks. Removida sempre.
				if ( 'signature' === $k ) {
					continue;
				}
				$key = is_string( $k ) ? sanitize_text_field( $k ) : $k;
				// Preserva chaves com sufixo responsivo/pseudo (ex.: _padding:tablet_portrait:hover).
				$cleaned = self::deep_clean( $v, $depth + 1 );
				if ( is_wp_error( $cleaned ) ) {
					return $cleaned;
				}
				$clean[ $key ] = $cleaned;
			}
			return $clean;
		}

		if ( is_string( $value ) ) {
			// Nao escapa HTML aqui (Bricks armazena valores estruturados); apenas
			// remove bytes nulos e normaliza. A renderizacao do Bricks faz o escape.
			return self::sanitize_string_value( $value );
		}

		if ( is_int( $value ) || is_float( $value ) || is_bool( $value ) || null === $value ) {
			return $value;
		}

		// Objetos, recursos e closures sao rejeitados.
		return new WP_Error( 'mmb_unsafe_value', __( 'Valor de tipo nao permitido detectado.', 'marreira-mcp-builders' ), array( 'status' => 422 ) );
	}

	/**
	 * Sanitiza uma string de valor de setting.
	 *
	 * @param string $value Valor.
	 * @return string
	 */
	private static function sanitize_string_value( $value ) {
		// Remove bytes nulos e caracteres de controle invisiveis perigosos.
		$value = str_replace( chr( 0 ), '', $value );
		return $value;
	}

	/**
	 * Valida e prepara uma arvore de elementos para persistencia.
	 *
	 * Pipeline: deep_clean -> normalize -> code guard -> validacao de integridade.
	 *
	 * @param mixed $elements Arvore (ou payload clipboard).
	 * @return array|WP_Error Arvore pronta para gravar.
	 */
	public static function prepare_tree( $elements ) {
		$elements = self::extract_content( $elements );

		if ( ! is_array( $elements ) ) {
			return new WP_Error( 'mmb_tree_type', __( 'A arvore de elementos precisa ser um array.', 'marreira-mcp-builders' ), array( 'status' => 422 ) );
		}

		$clean = self::deep_clean( $elements );
		if ( is_wp_error( $clean ) ) {
			return $clean;
		}

		$normalized = Element_Tree::normalize( $clean );

		// Guard anti-RCE.
		$guard = Code_Guard::inspect_elements( $normalized );
		if ( is_wp_error( $guard ) ) {
			return $guard;
		}

		// Integridade da arvore (so valida se houver elementos).
		if ( ! empty( $normalized ) ) {
			$valid = Element_Tree::validate( $normalized );
			if ( is_wp_error( $valid ) ) {
				return $valid;
			}
		}

		return $normalized;
	}

	/**
	 * Extrai o array de elementos de um payload que pode ser:
	 *  - a propria arvore (array de elementos);
	 *  - o formato clipboard do Bricks ({ content: [...] }).
	 *
	 * @param mixed $payload Payload.
	 * @return mixed
	 */
	public static function extract_content( $payload ) {
		if ( is_array( $payload ) && isset( $payload['content'] ) && is_array( $payload['content'] ) ) {
			return $payload['content'];
		}
		return $payload;
	}

	/**
	 * Valida e limpa um array de settings isolado (para update_element_settings).
	 *
	 * @param mixed $settings Settings.
	 * @return array|WP_Error
	 */
	public static function prepare_settings( $settings ) {
		if ( ! is_array( $settings ) ) {
			return new WP_Error( 'mmb_settings_type', __( 'Settings precisam ser um array.', 'marreira-mcp-builders' ), array( 'status' => 422 ) );
		}
		$clean = self::deep_clean( $settings );
		if ( is_wp_error( $clean ) ) {
			return $clean;
		}
		$guard = Code_Guard::inspect_settings( $clean );
		if ( is_wp_error( $guard ) ) {
			return $guard;
		}
		return $clean;
	}

	/**
	 * Valida e limpa page settings (rejeita scripts).
	 *
	 * @param mixed $settings Page settings.
	 * @return array|WP_Error
	 */
	public static function prepare_page_settings( $settings ) {
		if ( ! is_array( $settings ) ) {
			return new WP_Error( 'mmb_page_settings_type', __( 'Page settings precisam ser um array.', 'marreira-mcp-builders' ), array( 'status' => 422 ) );
		}
		$clean = self::deep_clean( $settings );
		if ( is_wp_error( $clean ) ) {
			return $clean;
		}
		$guard = Code_Guard::inspect_page_settings( $clean );
		if ( is_wp_error( $guard ) ) {
			return $guard;
		}
		return $clean;
	}
}
