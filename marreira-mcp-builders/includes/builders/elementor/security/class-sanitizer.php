<?php
/**
 * Sanitizer: pipeline de validacao das arvores e settings antes de persistir.
 *
 * @package Marreira\MCP_Builders
 */

namespace Marreira\MCP_Builders\Builders\Elementor\Security;

use Marreira\MCP_Builders\Builders\Elementor\Element_Tree;
use Marreira\MCP_Builders\Builders\Elementor\Code_Guard;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Normaliza + verifica anti-RCE + valida integridade de uma arvore aninhada
 * de elementos Elementor. A persistencia em si fica no Elementor_Gateway, que
 * delega ao Document::save() do Elementor (kses + regen de CSS).
 */
class Sanitizer {

	/**
	 * Desembrulha formatos aceitos para a arvore de elementos.
	 *
	 * Aceita: array de nodes; ou objeto { "content": [...] } / { "elements": [...] }
	 * (formato de export/clipboard do Elementor).
	 *
	 * @param mixed $input Entrada.
	 * @return array
	 */
	public static function unwrap( $input ) {
		if ( is_array( $input ) ) {
			if ( isset( $input['content'] ) && is_array( $input['content'] ) ) {
				return $input['content'];
			}
			if ( isset( $input['elements'] ) && is_array( $input['elements'] ) ) {
				return $input['elements'];
			}
			return $input;
		}
		return array();
	}

	/**
	 * Prepara uma arvore para gravacao: desembrulha, normaliza (ids), verifica
	 * anti-RCE e valida integridade.
	 *
	 * @param mixed $input Arvore (ou clipboard).
	 * @return array|WP_Error Arvore preparada ou erro.
	 */
	public static function prepare_tree( $input ) {
		$elements = self::unwrap( $input );

		if ( ! is_array( $elements ) ) {
			return new WP_Error( 'mme_invalid_tree', __( 'Arvore de elementos invalida.', 'marreira-mcp-builders' ), array( 'status' => 422 ) );
		}

		$taken      = array();
		$normalized = Element_Tree::normalize( $elements, $taken );

		$guard = Code_Guard::inspect_elements( $normalized );
		if ( is_wp_error( $guard ) ) {
			return $guard;
		}

		$valid = Element_Tree::validate( $normalized );
		if ( is_wp_error( $valid ) ) {
			return $valid;
		}

		return $normalized;
	}

	/**
	 * Prepara um subconjunto de nodes para insercao num documento existente:
	 * regenera ids para nao colidir com os ids ja presentes no destino.
	 *
	 * @param mixed $input Node ou arvore (ou clipboard).
	 * @param array $taken Ids ja em uso no documento destino.
	 * @return array|WP_Error
	 */
	public static function prepare_subtree( $input, array $taken ) {
		$elements = self::unwrap( $input );
		if ( ! is_array( $elements ) || empty( $elements ) ) {
			return new WP_Error( 'mme_invalid_tree', __( 'Nenhum elemento informado para inserir.', 'marreira-mcp-builders' ), array( 'status' => 422 ) );
		}

		// Normaliza primeiro (preenche campos), depois regenera ids contra o destino.
		$tmp        = array();
		$normalized = Element_Tree::normalize( $elements, $tmp );
		$regen      = Element_Tree::regenerate_ids( $normalized, $taken );

		$guard = Code_Guard::inspect_elements( $regen );
		if ( is_wp_error( $guard ) ) {
			return $guard;
		}

		$valid = Element_Tree::validate( $regen );
		if ( is_wp_error( $valid ) ) {
			return $valid;
		}

		return $regen;
	}

	/**
	 * Prepara page settings (document settings): verifica anti-RCE.
	 *
	 * @param mixed $settings Settings.
	 * @return array|WP_Error
	 */
	public static function prepare_page_settings( $settings ) {
		if ( ! is_array( $settings ) ) {
			return new WP_Error( 'mme_invalid_settings', __( 'Page settings invalidas.', 'marreira-mcp-builders' ), array( 'status' => 422 ) );
		}

		$guard = Code_Guard::inspect_page_settings( $settings );
		if ( is_wp_error( $guard ) ) {
			return $guard;
		}

		return $settings;
	}

	/**
	 * Prepara settings de um unico elemento (para update_element_settings).
	 *
	 * @param mixed $settings Settings.
	 * @return array|WP_Error
	 */
	public static function prepare_element_settings( $settings ) {
		if ( ! is_array( $settings ) ) {
			return new WP_Error( 'mme_invalid_settings', __( 'Settings invalidas.', 'marreira-mcp-builders' ), array( 'status' => 422 ) );
		}

		$guard = Code_Guard::inspect_settings( $settings );
		if ( is_wp_error( $guard ) ) {
			return $guard;
		}

		return $settings;
	}
}
