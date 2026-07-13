<?php
/**
 * Page_Tools: tools de paginas/posts Elementor.
 *
 * @package Marreira\MCP_Builders
 */

namespace Marreira\MCP_Builders\Builders\Elementor\Tools;

use Marreira\MCP_Builders\MCP\Tool_Registry;
use Marreira\MCP_Builders\Builders\Elementor\Elementor_Gateway;
use Marreira\MCP_Builders\Builders\Elementor\Security\Sanitizer;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * CRUD de paginas Elementor via MCP.
 */
class Page_Tools extends Base_Tools {

	/**
	 * Registra as tools de pagina.
	 *
	 * @param Tool_Registry $registry Registro.
	 * @return void
	 */
	public static function register( Tool_Registry $registry ) {

		$registry->register(
			'list_pages',
			__( 'Lista paginas/posts construidos com o Elementor.', 'marreira-mcp-builders' ),
			self::schema(
				array(
					'post_type' => array(
						'type'        => 'string',
						'description' => 'Tipo de post (page, post, any). Padrao: any.',
					),
					'status'    => array(
						'type'        => 'string',
						'description' => 'Status do post (publish, draft, any). Padrao: any.',
					),
					'limit'     => array(
						'type'        => 'integer',
						'description' => 'Maximo de itens (1-200). Padrao: 50.',
					),
				)
			),
			array( __CLASS__, 'list_pages' )
		);

		$registry->register(
			'get_page',
			__( 'Retorna a arvore de elementos Elementor (aninhada) e as page settings de um post.', 'marreira-mcp-builders' ),
			self::schema(
				array(
					'post_id' => array(
						'type'        => 'integer',
						'description' => 'ID do post.',
					),
				),
				array( 'post_id' )
			),
			array( __CLASS__, 'get_page' )
		);

		$registry->register(
			'create_elementor_page',
			__( 'Cria uma nova pagina Elementor com a arvore de elementos informada (aceita formato export/clipboard).', 'marreira-mcp-builders' ),
			self::schema(
				array(
					'title'     => array(
						'type'        => 'string',
						'description' => 'Titulo da pagina.',
					),
					'post_type' => array(
						'type'        => 'string',
						'description' => 'Tipo de post. Padrao: page.',
					),
					'status'    => array(
						'type'        => 'string',
						'description' => 'draft, publish, pending ou private. Padrao: draft.',
					),
					'slug'      => array(
						'type'        => 'string',
						'description' => 'Slug opcional.',
					),
					'elements'  => array(
						'type'        => 'array',
						'description' => 'Arvore aninhada de elementos Elementor (id, elType, widgetType, settings, elements). Tambem aceita { content: [...] }.',
					),
				),
				array( 'title' )
			),
			array( __CLASS__, 'create_elementor_page' )
		);

		$registry->register(
			'update_elementor_page',
			__( 'Substitui a arvore de elementos de um post Elementor (round-trip-safe via Document API).', 'marreira-mcp-builders' ),
			self::schema(
				array(
					'post_id'  => array(
						'type'        => 'integer',
						'description' => 'ID do post.',
					),
					'elements' => array(
						'type'        => 'array',
						'description' => 'Nova arvore aninhada de elementos.',
					),
				),
				array( 'post_id', 'elements' )
			),
			array( __CLASS__, 'update_elementor_page' )
		);

		$registry->register(
			'set_page_settings',
			__( 'Atualiza page settings do documento (layout, SEO, custom_css). Scripts sao recusados.', 'marreira-mcp-builders' ),
			self::schema(
				array(
					'post_id'  => array(
						'type'        => 'integer',
						'description' => 'ID do post.',
					),
					'settings' => array(
						'type'        => 'object',
						'description' => 'Page settings a mesclar (ex.: hide_title, template, custom_css).',
					),
				),
				array( 'post_id', 'settings' )
			),
			array( __CLASS__, 'set_page_settings' )
		);

		$registry->register(
			'delete_page',
			__( 'Exclui um post (lixeira por padrao).', 'marreira-mcp-builders' ),
			self::schema(
				array(
					'post_id' => array(
						'type'        => 'integer',
						'description' => 'ID do post.',
					),
					'force'   => array(
						'type'        => 'boolean',
						'description' => 'Se true, exclui permanentemente. Padrao: false.',
					),
				),
				array( 'post_id' )
			),
			array( __CLASS__, 'delete_page' )
		);
	}

	/**
	 * Handler: list_pages.
	 *
	 * @param array $args Argumentos.
	 * @return array
	 */
	public static function list_pages( array $args ) {
		$cap = self::require_cap( 'edit_pages' );
		if ( $cap ) {
			return $cap;
		}
		$pages = Elementor_Gateway::list_pages(
			array(
				'post_type' => isset( $args['post_type'] ) ? $args['post_type'] : 'any',
				'status'    => isset( $args['status'] ) ? $args['status'] : 'any',
				'limit'     => isset( $args['limit'] ) ? (int) $args['limit'] : 50,
			)
		);
		return Tool_Registry::success_result( $pages );
	}

	/**
	 * Handler: get_page.
	 *
	 * @param array $args Argumentos.
	 * @return array
	 */
	public static function get_page( array $args ) {
		$cap = self::require_cap( 'edit_pages' );
		if ( $cap ) {
			return $cap;
		}
		$post_id = isset( $args['post_id'] ) ? (int) $args['post_id'] : 0;

		if ( ! get_post( $post_id ) ) {
			return Tool_Registry::error_result( __( 'Post inexistente.', 'marreira-mcp-builders' ) );
		}

		return Tool_Registry::success_result(
			array(
				'post'          => Elementor_Gateway::summarize_post( $post_id ),
				'elements'      => Elementor_Gateway::get_elements( $post_id ),
				'page_settings' => Elementor_Gateway::get_page_settings( $post_id ),
			)
		);
	}

	/**
	 * Handler: create_elementor_page.
	 *
	 * @param array $args Argumentos.
	 * @return array
	 */
	public static function create_elementor_page( array $args ) {
		$elementor = self::require_elementor();
		if ( $elementor ) {
			return $elementor;
		}
		$cap = self::require_cap( 'publish_pages' );
		if ( $cap ) {
			return $cap;
		}

		$elements = isset( $args['elements'] ) ? $args['elements'] : array();
		$prepared = Sanitizer::prepare_tree( $elements );
		if ( is_wp_error( $prepared ) ) {
			return self::from_error( $prepared );
		}

		$post_id = Elementor_Gateway::create_page(
			array(
				'title'     => isset( $args['title'] ) ? $args['title'] : '',
				'post_type' => isset( $args['post_type'] ) ? $args['post_type'] : 'page',
				'status'    => isset( $args['status'] ) ? $args['status'] : 'draft',
				'slug'      => isset( $args['slug'] ) ? $args['slug'] : '',
				'elements'  => $prepared,
			)
		);
		if ( is_wp_error( $post_id ) ) {
			return self::from_error( $post_id );
		}

		return Tool_Registry::success_result(
			Elementor_Gateway::summarize_post( $post_id ),
			__( 'Pagina Elementor criada.', 'marreira-mcp-builders' ) . self::code_warning( $prepared )
		);
	}

	/**
	 * Handler: update_elementor_page.
	 *
	 * @param array $args Argumentos.
	 * @return array
	 */
	public static function update_elementor_page( array $args ) {
		$elementor = self::require_elementor();
		if ( $elementor ) {
			return $elementor;
		}
		$post_id = isset( $args['post_id'] ) ? (int) $args['post_id'] : 0;

		if ( ! get_post( $post_id ) ) {
			return Tool_Registry::error_result( __( 'Post inexistente.', 'marreira-mcp-builders' ) );
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return Tool_Registry::error_result( __( 'Sem permissao para editar este post.', 'marreira-mcp-builders' ) );
		}

		$prepared = Sanitizer::prepare_tree( isset( $args['elements'] ) ? $args['elements'] : array() );
		if ( is_wp_error( $prepared ) ) {
			return self::from_error( $prepared );
		}

		$saved = Elementor_Gateway::save_elements( $post_id, $prepared );
		if ( is_wp_error( $saved ) ) {
			return self::from_error( $saved );
		}

		return Tool_Registry::success_result(
			Elementor_Gateway::summarize_post( $post_id ),
			__( 'Arvore atualizada.', 'marreira-mcp-builders' ) . self::code_warning( $prepared )
		);
	}

	/**
	 * Handler: set_page_settings.
	 *
	 * @param array $args Argumentos.
	 * @return array
	 */
	public static function set_page_settings( array $args ) {
		$cap = self::require_cap( 'edit_pages' );
		if ( $cap ) {
			return $cap;
		}
		$post_id = isset( $args['post_id'] ) ? (int) $args['post_id'] : 0;

		if ( ! get_post( $post_id ) ) {
			return Tool_Registry::error_result( __( 'Post inexistente.', 'marreira-mcp-builders' ) );
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return Tool_Registry::error_result( __( 'Sem permissao para editar este post.', 'marreira-mcp-builders' ) );
		}

		$settings = Sanitizer::prepare_page_settings( isset( $args['settings'] ) ? $args['settings'] : array() );
		if ( is_wp_error( $settings ) ) {
			return self::from_error( $settings );
		}

		$res = Elementor_Gateway::update_page_settings( $post_id, $settings );
		if ( is_wp_error( $res ) ) {
			return self::from_error( $res );
		}

		return Tool_Registry::success_result(
			Elementor_Gateway::get_page_settings( $post_id ),
			__( 'Page settings atualizadas.', 'marreira-mcp-builders' )
		);
	}

	/**
	 * Handler: delete_page.
	 *
	 * @param array $args Argumentos.
	 * @return array
	 */
	public static function delete_page( array $args ) {
		$post_id = isset( $args['post_id'] ) ? (int) $args['post_id'] : 0;
		$force   = ! empty( $args['force'] );

		if ( ! current_user_can( 'delete_post', $post_id ) ) {
			return Tool_Registry::error_result( __( 'Sem permissao para excluir este post.', 'marreira-mcp-builders' ) );
		}

		$res = Elementor_Gateway::delete_post( $post_id, $force );
		if ( is_wp_error( $res ) ) {
			return self::from_error( $res );
		}

		return Tool_Registry::success_result(
			array(
				'deleted' => $post_id,
				'forced'  => $force,
			),
			__( 'Post excluido.', 'marreira-mcp-builders' )
		);
	}
}
