<?php
/**
 * Element_Tools: edicao fina de elementos dentro de um documento Elementor.
 *
 * @package Marreira\MCP_Builders
 */

namespace Marreira\MCP_Builders\Builders\Elementor\Tools;

use Marreira\MCP_Builders\MCP\Tool_Registry;
use Marreira\MCP_Builders\Builders\Elementor\Elementor_Gateway;
use Marreira\MCP_Builders\Builders\Elementor\Element_Tree;
use Marreira\MCP_Builders\Builders\Elementor\Security\Sanitizer;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Insere, atualiza, move, duplica e remove elementos de um post Elementor.
 * Todas as operacoes sao read-modify-write sobre a arvore aninhada.
 */
class Element_Tools extends Base_Tools {

	/**
	 * Registra as tools de elemento.
	 *
	 * @param Tool_Registry $registry Registro.
	 * @return void
	 */
	public static function register( Tool_Registry $registry ) {

		$registry->register(
			'insert_element',
			__( 'Insere um elemento (ou subarvore) sob um parent (por id) ou na raiz. IDs sao regenerados para nao colidir.', 'marreira-mcp-builders' ),
			self::schema(
				array(
					'post_id'   => array(
						'type'        => 'integer',
						'description' => 'ID do post.',
					),
					'parent_id' => array(
						'type'        => 'string',
						'description' => 'ID do elemento pai. Vazio ou "root" insere no nivel raiz.',
					),
					'position'  => array(
						'type'        => 'integer',
						'description' => 'Posicao entre os filhos do pai (0 = inicio). Padrao: fim.',
					),
					'element'   => array(
						'type'        => 'object',
						'description' => 'Um unico node (id, elType, widgetType, settings, elements).',
					),
					'elements'  => array(
						'type'        => 'array',
						'description' => 'Varios nodes a inserir (alternativa a "element").',
					),
				),
				array( 'post_id' )
			),
			array( __CLASS__, 'insert_element' )
		);

		$registry->register(
			'update_element_settings',
			__( 'Atualiza (merge ou replace) as settings de um elemento por id.', 'marreira-mcp-builders' ),
			self::schema(
				array(
					'post_id'    => array(
						'type'        => 'integer',
						'description' => 'ID do post.',
					),
					'element_id' => array(
						'type'        => 'string',
						'description' => 'ID do elemento.',
					),
					'settings'   => array(
						'type'        => 'object',
						'description' => 'Settings a aplicar.',
					),
					'replace'    => array(
						'type'        => 'boolean',
						'description' => 'Se true, substitui todas as settings; senao faz merge raso. Padrao: false.',
					),
				),
				array( 'post_id', 'element_id', 'settings' )
			),
			array( __CLASS__, 'update_element_settings' )
		);

		$registry->register(
			'move_element',
			__( 'Move um elemento para outro pai e/ou posicao.', 'marreira-mcp-builders' ),
			self::schema(
				array(
					'post_id'    => array(
						'type'        => 'integer',
						'description' => 'ID do post.',
					),
					'element_id' => array(
						'type'        => 'string',
						'description' => 'ID do elemento a mover.',
					),
					'new_parent' => array(
						'type'        => 'string',
						'description' => 'ID do novo pai. Vazio ou "root" move para a raiz.',
					),
					'position'   => array(
						'type'        => 'integer',
						'description' => 'Posicao no destino. Padrao: fim.',
					),
				),
				array( 'post_id', 'element_id' )
			),
			array( __CLASS__, 'move_element' )
		);

		$registry->register(
			'delete_element',
			__( 'Remove um elemento e toda a sua subarvore.', 'marreira-mcp-builders' ),
			self::schema(
				array(
					'post_id'    => array(
						'type'        => 'integer',
						'description' => 'ID do post.',
					),
					'element_id' => array(
						'type'        => 'string',
						'description' => 'ID do elemento a remover.',
					),
				),
				array( 'post_id', 'element_id' )
			),
			array( __CLASS__, 'delete_element' )
		);

		$registry->register(
			'duplicate_element',
			__( 'Duplica um elemento como irmao imediato, com novos IDs.', 'marreira-mcp-builders' ),
			self::schema(
				array(
					'post_id'    => array(
						'type'        => 'integer',
						'description' => 'ID do post.',
					),
					'element_id' => array(
						'type'        => 'string',
						'description' => 'ID do elemento a duplicar.',
					),
				),
				array( 'post_id', 'element_id' )
			),
			array( __CLASS__, 'duplicate_element' )
		);
	}

	/**
	 * Carrega o post e checa Elementor + capability de edicao.
	 *
	 * @param int $post_id Post.
	 * @return array|null error_result em caso de falha, ou null se ok.
	 */
	private static function guard_post( $post_id ) {
		$elementor = self::require_elementor();
		if ( $elementor ) {
			return $elementor;
		}
		if ( ! get_post( $post_id ) ) {
			return Tool_Registry::error_result( __( 'Post inexistente.', 'marreira-mcp-builders' ) );
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return Tool_Registry::error_result( __( 'Sem permissao para editar este post.', 'marreira-mcp-builders' ) );
		}
		return null;
	}

	/**
	 * Handler: insert_element.
	 *
	 * @param array $args Argumentos.
	 * @return array
	 */
	public static function insert_element( array $args ) {
		$post_id = isset( $args['post_id'] ) ? (int) $args['post_id'] : 0;
		$guard   = self::guard_post( $post_id );
		if ( $guard ) {
			return $guard;
		}

		$input = array();
		if ( isset( $args['elements'] ) && is_array( $args['elements'] ) ) {
			$input = $args['elements'];
		} elseif ( isset( $args['element'] ) && is_array( $args['element'] ) ) {
			$input = array( $args['element'] );
		}

		$current = Elementor_Gateway::get_elements( $post_id );
		$taken   = Element_Tree::collect_ids( $current );

		$prepared = Sanitizer::prepare_subtree( $input, $taken );
		if ( is_wp_error( $prepared ) ) {
			return self::from_error( $prepared );
		}

		$parent_id = isset( $args['parent_id'] ) ? (string) $args['parent_id'] : '';
		$position  = isset( $args['position'] ) ? (int) $args['position'] : null;

		$new_tree = Element_Tree::insert( $current, $prepared, $parent_id, $position );
		if ( is_wp_error( $new_tree ) ) {
			return self::from_error( $new_tree );
		}

		$saved = Elementor_Gateway::save_elements( $post_id, $new_tree );
		if ( is_wp_error( $saved ) ) {
			return self::from_error( $saved );
		}

		return Tool_Registry::success_result(
			array(
				'post_id'      => $post_id,
				'inserted_ids' => Element_Tree::collect_ids( $prepared ),
			),
			__( 'Elemento(s) inserido(s).', 'marreira-mcp-builders' ) . self::code_warning( $prepared )
		);
	}

	/**
	 * Handler: update_element_settings.
	 *
	 * @param array $args Argumentos.
	 * @return array
	 */
	public static function update_element_settings( array $args ) {
		$post_id = isset( $args['post_id'] ) ? (int) $args['post_id'] : 0;
		$guard   = self::guard_post( $post_id );
		if ( $guard ) {
			return $guard;
		}

		$element_id = isset( $args['element_id'] ) ? (string) $args['element_id'] : '';
		$replace    = ! empty( $args['replace'] );

		$settings = Sanitizer::prepare_element_settings( isset( $args['settings'] ) ? $args['settings'] : array() );
		if ( is_wp_error( $settings ) ) {
			return self::from_error( $settings );
		}

		$current  = Elementor_Gateway::get_elements( $post_id );
		$new_tree = Element_Tree::update_settings( $current, $element_id, $settings, $replace );
		if ( is_wp_error( $new_tree ) ) {
			return self::from_error( $new_tree );
		}

		$saved = Elementor_Gateway::save_elements( $post_id, $new_tree );
		if ( is_wp_error( $saved ) ) {
			return self::from_error( $saved );
		}

		return Tool_Registry::success_result(
			array(
				'post_id'    => $post_id,
				'element_id' => $element_id,
				'element'    => Element_Tree::find( $new_tree, $element_id ),
			),
			__( 'Settings do elemento atualizadas.', 'marreira-mcp-builders' )
		);
	}

	/**
	 * Handler: move_element.
	 *
	 * @param array $args Argumentos.
	 * @return array
	 */
	public static function move_element( array $args ) {
		$post_id = isset( $args['post_id'] ) ? (int) $args['post_id'] : 0;
		$guard   = self::guard_post( $post_id );
		if ( $guard ) {
			return $guard;
		}

		$element_id = isset( $args['element_id'] ) ? (string) $args['element_id'] : '';
		$new_parent = isset( $args['new_parent'] ) ? (string) $args['new_parent'] : '';
		$position   = isset( $args['position'] ) ? (int) $args['position'] : null;

		$current  = Elementor_Gateway::get_elements( $post_id );
		$new_tree = Element_Tree::move( $current, $element_id, $new_parent, $position );
		if ( is_wp_error( $new_tree ) ) {
			return self::from_error( $new_tree );
		}

		$saved = Elementor_Gateway::save_elements( $post_id, $new_tree );
		if ( is_wp_error( $saved ) ) {
			return self::from_error( $saved );
		}

		return Tool_Registry::success_result(
			array(
				'post_id'    => $post_id,
				'element_id' => $element_id,
			),
			__( 'Elemento movido.', 'marreira-mcp-builders' )
		);
	}

	/**
	 * Handler: delete_element.
	 *
	 * @param array $args Argumentos.
	 * @return array
	 */
	public static function delete_element( array $args ) {
		$post_id = isset( $args['post_id'] ) ? (int) $args['post_id'] : 0;
		$guard   = self::guard_post( $post_id );
		if ( $guard ) {
			return $guard;
		}

		$element_id = isset( $args['element_id'] ) ? (string) $args['element_id'] : '';

		$current  = Elementor_Gateway::get_elements( $post_id );
		$new_tree = Element_Tree::remove( $current, $element_id );
		if ( is_wp_error( $new_tree ) ) {
			return self::from_error( $new_tree );
		}

		$saved = Elementor_Gateway::save_elements( $post_id, $new_tree );
		if ( is_wp_error( $saved ) ) {
			return self::from_error( $saved );
		}

		return Tool_Registry::success_result(
			array(
				'post_id'    => $post_id,
				'element_id' => $element_id,
			),
			__( 'Elemento removido.', 'marreira-mcp-builders' )
		);
	}

	/**
	 * Handler: duplicate_element.
	 *
	 * @param array $args Argumentos.
	 * @return array
	 */
	public static function duplicate_element( array $args ) {
		$post_id = isset( $args['post_id'] ) ? (int) $args['post_id'] : 0;
		$guard   = self::guard_post( $post_id );
		if ( $guard ) {
			return $guard;
		}

		$element_id = isset( $args['element_id'] ) ? (string) $args['element_id'] : '';

		$current  = Elementor_Gateway::get_elements( $post_id );
		$new_tree = Element_Tree::duplicate( $current, $element_id );
		if ( is_wp_error( $new_tree ) ) {
			return self::from_error( $new_tree );
		}

		$saved = Elementor_Gateway::save_elements( $post_id, $new_tree );
		if ( is_wp_error( $saved ) ) {
			return self::from_error( $saved );
		}

		return Tool_Registry::success_result(
			array(
				'post_id'    => $post_id,
				'element_id' => $element_id,
			),
			__( 'Elemento duplicado.', 'marreira-mcp-builders' )
		);
	}
}
