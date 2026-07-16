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
	 * Profundidade maxima de aninhamento aceita.
	 *
	 * Bem mais alta que o teto de 30 do driver do Bricks, e de proposito: a
	 * arvore do Bricks e PLANA (cada elemento aponta o pai por id), entao 30
	 * niveis de array la sao muito mais do que qualquer pagina real usa. A do
	 * Elementor e ANINHADA — cada nivel de elemento custa dois niveis de array (o
	 * array `elements` + o array do elemento) e os containers do Elementor Pro
	 * aninham a vontade, mais a profundidade das proprias settings. Um teto de 30
	 * recusaria pagina legitima, que seria pior que o problema que isto resolve.
	 *
	 * O objetivo aqui e so evitar estouro de pilha na recursao do guard/validate,
	 * e para isso 200 ja e ordens de grandeza abaixo do limite do PHP.
	 *
	 * @var int
	 */
	const MAX_DEPTH = 200;

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

		// Antes de percorrer: a arvore do Elementor e recursiva e o normalize/
		// guard/validate descem nela sem teto de profundidade.
		$elements = self::deep_clean( $elements );
		if ( is_wp_error( $elements ) ) {
			return $elements;
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

		$elements = self::deep_clean( $elements );
		if ( is_wp_error( $elements ) ) {
			return $elements;
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

		$settings = self::deep_clean( $settings );
		if ( is_wp_error( $settings ) ) {
			return $settings;
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

		$settings = self::deep_clean( $settings );
		if ( is_wp_error( $settings ) ) {
			return $settings;
		}

		$guard = Code_Guard::inspect_settings( $settings );
		if ( is_wp_error( $guard ) ) {
			return $guard;
		}

		return $settings;
	}

	/**
	 * Limpa recursivamente um valor, mantendo apenas arrays e escalares.
	 *
	 * Simetrico ao deep_clean do driver do Bricks, que ja fazia isso. O ponto
	 * principal nao e o objeto PHP (a REST desserializa JSON como array, entao na
	 * pratica nao chega objeto): e o LIMITE DE PROFUNDIDADE. O Code_Guard e o
	 * Element_Tree percorrem a arvore recursivamente, e o PHP nao tem teto de
	 * recursao — uma arvore absurdamente aninhada derrubava o processo antes de
	 * qualquer validacao. O Bricks estava protegido por este limite; o Elementor
	 * nao estava.
	 *
	 * @param mixed $value Valor.
	 * @param int   $depth Profundidade atual.
	 * @return mixed|WP_Error
	 */
	public static function deep_clean( $value, $depth = 0 ) {
		if ( $depth > self::MAX_DEPTH ) {
			return new WP_Error( 'mme_too_deep', __( 'Estrutura aninhada profunda demais.', 'marreira-mcp-builders' ), array( 'status' => 422 ) );
		}

		if ( is_array( $value ) ) {
			$clean = array();
			foreach ( $value as $k => $v ) {
				// Chaves apenas escalares.
				if ( ! is_string( $k ) && ! is_int( $k ) ) {
					continue;
				}
				$key     = is_string( $k ) ? sanitize_text_field( $k ) : $k;
				$cleaned = self::deep_clean( $v, $depth + 1 );
				if ( is_wp_error( $cleaned ) ) {
					return $cleaned;
				}
				$clean[ $key ] = $cleaned;
			}
			return $clean;
		}

		if ( is_string( $value ) ) {
			// Nao escapa HTML: o Elementor guarda valores estruturados e faz o
			// escape na renderizacao. Aqui so tira byte nulo.
			return str_replace( "\0", '', $value );
		}

		if ( is_int( $value ) || is_float( $value ) || is_bool( $value ) || null === $value ) {
			return $value;
		}

		// Objeto, resource, closure: nao entram na arvore.
		return null;
	}
}
