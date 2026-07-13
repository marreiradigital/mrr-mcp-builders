<?php
/**
 * Page_Tools: tools de paginas/posts Bricks.
 *
 * @package Marreira\MCP_Builders
 */

namespace Marreira\MCP_Builders\Builders\Bricks\Tools;

use Marreira\MCP_Builders\MCP\Tool_Registry;
use Marreira\MCP_Builders\Builders\Bricks\Bricks_Gateway;
use Marreira\MCP_Builders\Builders\Bricks\Css_Regenerator;
use Marreira\MCP_Builders\Builders\Bricks\Security\Sanitizer;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * CRUD de paginas Bricks via MCP.
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
			__( 'Lista paginas/posts que usam o Bricks Builder.', 'marreira-mcp-builders' ),
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
			__( 'Retorna a arvore de elementos Bricks e as page settings de um post.', 'marreira-mcp-builders' ),
			self::schema(
				array(
					'post_id' => array(
						'type'        => 'integer',
						'description' => 'ID do post.',
					),
					'area'    => array(
						'type'        => 'string',
						'description' => 'Area: content, header ou footer. Padrao: content.',
					),
				),
				array( 'post_id' )
			),
			array( __CLASS__, 'get_page' )
		);

		$registry->register(
			'create_bricks_page',
			__( 'Cria uma nova pagina Bricks com a arvore de elementos informada (aceita formato clipboard).', 'marreira-mcp-builders' ),
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
						'description' => 'Arvore plana de elementos Bricks (id, name, parent, children, settings). Tambem aceita { content: [...] }.',
					),
				),
				array( 'title' )
			),
			array( __CLASS__, 'create_bricks_page' )
		);

		$registry->register(
			'update_bricks_page',
			__( 'Substitui a arvore de elementos de uma area do post (round-trip-safe).', 'marreira-mcp-builders' ),
			self::schema(
				array(
					'post_id'  => array(
						'type'        => 'integer',
						'description' => 'ID do post.',
					),
					'area'     => array(
						'type'        => 'string',
						'description' => 'content, header ou footer. Padrao: content.',
					),
					'elements' => array(
						'type'        => 'array',
						'description' => 'Nova arvore plana de elementos.',
					),
				),
				array( 'post_id', 'elements' )
			),
			array( __CLASS__, 'update_bricks_page' )
		);

		$registry->register(
			'set_page_settings',
			__( 'Atualiza page settings (SEO, visibilidade de header/footer). Scripts sao recusados.', 'marreira-mcp-builders' ),
			self::schema(
				array(
					'post_id'  => array(
						'type'        => 'integer',
						'description' => 'ID do post.',
					),
					'settings' => array(
						'type'        => 'object',
						'description' => 'Page settings a mesclar (metaDescription, headerDisabled, footerDisabled, etc.).',
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
		$pages = Bricks_Gateway::list_pages(
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
		$area    = isset( $args['area'] ) ? sanitize_key( $args['area'] ) : 'content';

		if ( ! get_post( $post_id ) ) {
			return Tool_Registry::error_result( __( 'Post inexistente.', 'marreira-mcp-builders' ) );
		}

		return Tool_Registry::success_result(
			array(
				'post'          => Bricks_Gateway::summarize_post( $post_id ),
				'area'          => $area,
				'elements'      => Bricks_Gateway::get_elements( $post_id, $area ),
				'page_settings' => Bricks_Gateway::get_page_settings( $post_id ),
			)
		);
	}

	/**
	 * Handler: create_bricks_page.
	 *
	 * @param array $args Argumentos.
	 * @return array
	 */
	public static function create_bricks_page( array $args ) {
		$bricks = self::require_bricks();
		if ( $bricks ) {
			return $bricks;
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

		$post_id = Bricks_Gateway::create_page(
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

		Css_Regenerator::regenerate( $post_id );

		return Tool_Registry::success_result(
			Bricks_Gateway::summarize_post( $post_id ),
			__( 'Pagina Bricks criada.', 'marreira-mcp-builders' ) . self::code_warning( $prepared )
		);
	}

	/**
	 * Handler: update_bricks_page.
	 *
	 * @param array $args Argumentos.
	 * @return array
	 */
	public static function update_bricks_page( array $args ) {
		$cap = self::require_cap( 'edit_pages' );
		if ( $cap ) {
			return $cap;
		}
		$post_id = isset( $args['post_id'] ) ? (int) $args['post_id'] : 0;
		$area    = isset( $args['area'] ) ? sanitize_key( $args['area'] ) : 'content';

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

		$saved = Bricks_Gateway::save_elements( $post_id, $prepared, $area );
		if ( is_wp_error( $saved ) ) {
			return self::from_error( $saved );
		}

		Css_Regenerator::regenerate( $post_id );

		return Tool_Registry::success_result(
			Bricks_Gateway::summarize_post( $post_id ),
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

		$settings = Sanitizer::prepare_page_settings( isset( $args['settings'] ) ? $args['settings'] : array() );
		if ( is_wp_error( $settings ) ) {
			return self::from_error( $settings );
		}

		$res = Bricks_Gateway::update_page_settings( $post_id, $settings );
		if ( is_wp_error( $res ) ) {
			return self::from_error( $res );
		}

		return Tool_Registry::success_result(
			Bricks_Gateway::get_page_settings( $post_id ),
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

		$res = Bricks_Gateway::delete_post( $post_id, $force );
		if ( is_wp_error( $res ) ) {
			return self::from_error( $res );
		}

		return Tool_Registry::success_result(
			array( 'deleted' => $post_id, 'forced' => $force ),
			__( 'Post excluido.', 'marreira-mcp-builders' )
		);
	}
}
