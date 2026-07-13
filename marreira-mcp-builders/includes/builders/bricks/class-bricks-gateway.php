<?php
/**
 * Bricks_Gateway: ponto unico de leitura/escrita dos dados do Bricks.
 *
 * @package Marreira\MCP_Builders
 */

namespace Marreira\MCP_Builders\Builders\Bricks;

use Marreira\MCP_Builders\Builders\Bricks\Security\Sanitizer;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Encapsula todo acesso ao formato nativo do Bricks (postmeta + options),
 * garantindo round-trip com o editor: leitura preserva o formato e a escrita
 * e read-modify-write.
 */
class Bricks_Gateway {

	const META_CONTENT       = '_bricks_page_content_2';
	const META_HEADER        = '_bricks_page_header_2';
	const META_FOOTER        = '_bricks_page_footer_2';
	const META_PAGE_SETTINGS = '_bricks_page_settings';
	const META_EDITOR_MODE   = '_bricks_editor_mode';
	const META_TEMPLATE_TYPE = '_bricks_template_type';
	const META_TEMPLATE_SETTINGS = '_bricks_template_settings';

	const TEMPLATE_CPT = 'bricks_template';

	/**
	 * Resolve a meta key da area de conteudo conforme a "area".
	 *
	 * @param string $area content|header|footer.
	 * @return string
	 */
	public static function area_meta_key( $area ) {
		switch ( $area ) {
			case 'header':
				return self::META_HEADER;
			case 'footer':
				return self::META_FOOTER;
			default:
				return self::META_CONTENT;
		}
	}

	/**
	 * Le a arvore de elementos de um post para uma area.
	 *
	 * @param int    $post_id Post.
	 * @param string $area    content|header|footer.
	 * @return array Arvore plana (vazia se nao houver).
	 */
	public static function get_elements( $post_id, $area = 'content' ) {
		$elements = get_post_meta( (int) $post_id, self::area_meta_key( $area ), true );
		return is_array( $elements ) ? $elements : array();
	}

	/**
	 * Grava a arvore de elementos numa area, marcando o post como Bricks.
	 *
	 * @param int    $post_id  Post.
	 * @param array  $elements Arvore plana (ja validada).
	 * @param string $area     content|header|footer.
	 * @return true|WP_Error
	 */
	public static function save_elements( $post_id, array $elements, $area = 'content' ) {
		$post_id = (int) $post_id;
		if ( ! get_post( $post_id ) ) {
			return new WP_Error( 'mmb_post_missing', __( 'Post inexistente.', 'marreira-mcp-builders' ), array( 'status' => 404 ) );
		}

		update_post_meta( $post_id, self::area_meta_key( $area ), $elements );

		// Marca como pagina Bricks (obrigatorio para o Bricks renderizar/editar).
		update_post_meta( $post_id, self::META_EDITOR_MODE, 'bricks' );

		return true;
	}

	/**
	 * Cria um novo post Bricks (page/post/CPT suportado) e grava elementos.
	 *
	 * @param array $args {
	 *     @type string $title     Titulo.
	 *     @type string $post_type Tipo (default 'page').
	 *     @type string $status    Status (default 'draft').
	 *     @type array  $elements  Arvore de conteudo.
	 *     @type string $slug      Slug opcional.
	 * }
	 * @return int|WP_Error Id do post criado.
	 */
	public static function create_page( array $args ) {
		$title     = isset( $args['title'] ) ? sanitize_text_field( $args['title'] ) : __( 'Pagina Bricks', 'marreira-mcp-builders' );
		$post_type = isset( $args['post_type'] ) ? sanitize_key( $args['post_type'] ) : 'page';
		$status    = self::sanitize_status( isset( $args['status'] ) ? $args['status'] : 'draft' );
		$elements  = isset( $args['elements'] ) && is_array( $args['elements'] ) ? $args['elements'] : array();

		if ( ! post_type_exists( $post_type ) ) {
			return new WP_Error( 'mmb_invalid_post_type', sprintf( /* translators: %s: post type */ __( 'Tipo de post inexistente: %s', 'marreira-mcp-builders' ), $post_type ), array( 'status' => 422 ) );
		}

		$postarr = array(
			'post_title'   => $title,
			'post_type'    => $post_type,
			'post_status'  => $status,
			'post_content' => '', // Bricks usa postmeta; mantem o content vazio.
		);
		if ( ! empty( $args['slug'] ) ) {
			$postarr['post_name'] = sanitize_title( $args['slug'] );
		}

		$post_id = wp_insert_post( $postarr, true );
		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		$saved = self::save_elements( $post_id, $elements, 'content' );
		if ( is_wp_error( $saved ) ) {
			return $saved;
		}

		return (int) $post_id;
	}

	/**
	 * Cria um template Bricks (header/footer/content/section/etc.).
	 *
	 * @param array $args {
	 *     @type string $title     Titulo.
	 *     @type string $type      Tipo do template (header, footer, content, section, ...).
	 *     @type string $status    Status (default 'publish').
	 *     @type array  $elements  Arvore de elementos.
	 * }
	 * @return int|WP_Error Id do template.
	 */
	public static function create_template( array $args ) {
		$title  = isset( $args['title'] ) ? sanitize_text_field( $args['title'] ) : __( 'Template Bricks', 'marreira-mcp-builders' );
		$type   = isset( $args['type'] ) ? sanitize_key( $args['type'] ) : 'content';
		$status = self::sanitize_status( isset( $args['status'] ) ? $args['status'] : 'publish' );
		$elements = isset( $args['elements'] ) && is_array( $args['elements'] ) ? $args['elements'] : array();

		$post_id = wp_insert_post(
			array(
				'post_title'   => $title,
				'post_type'    => self::TEMPLATE_CPT,
				'post_status'  => $status,
				'post_content' => '',
			),
			true
		);
		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		// A area depende do tipo: header->header, footer->footer, demais->content.
		$area = in_array( $type, array( 'header', 'footer' ), true ) ? $type : 'content';

		update_post_meta( $post_id, self::area_meta_key( $area ), $elements );
		update_post_meta( $post_id, self::META_EDITOR_MODE, 'bricks' );
		update_post_meta( $post_id, self::META_TEMPLATE_TYPE, $type );

		return (int) $post_id;
	}

	/**
	 * Le as page settings de um post.
	 *
	 * @param int $post_id Post.
	 * @return array
	 */
	public static function get_page_settings( $post_id ) {
		$settings = get_post_meta( (int) $post_id, self::META_PAGE_SETTINGS, true );
		return is_array( $settings ) ? $settings : array();
	}

	/**
	 * Atualiza page settings (read-modify-write, merge raso).
	 *
	 * @param int   $post_id  Post.
	 * @param array $settings Settings a mesclar.
	 * @return true|WP_Error
	 */
	public static function update_page_settings( $post_id, array $settings ) {
		$post_id = (int) $post_id;
		if ( ! get_post( $post_id ) ) {
			return new WP_Error( 'mmb_post_missing', __( 'Post inexistente.', 'marreira-mcp-builders' ), array( 'status' => 404 ) );
		}
		$current = self::get_page_settings( $post_id );
		$merged  = array_merge( $current, $settings );
		update_post_meta( $post_id, self::META_PAGE_SETTINGS, $merged );
		return true;
	}

	/**
	 * Define as condicoes de um template.
	 *
	 * @param int   $template_id Id do template.
	 * @param array $conditions  Array de condicoes (templateConditions).
	 * @return true|WP_Error
	 */
	public static function set_template_conditions( $template_id, array $conditions ) {
		$template_id = (int) $template_id;
		$post        = get_post( $template_id );
		if ( ! $post || self::TEMPLATE_CPT !== $post->post_type ) {
			return new WP_Error( 'mmb_not_template', __( 'Template Bricks inexistente.', 'marreira-mcp-builders' ), array( 'status' => 404 ) );
		}
		$current                        = get_post_meta( $template_id, self::META_TEMPLATE_SETTINGS, true );
		$current                        = is_array( $current ) ? $current : array();
		$current['templateConditions']  = $conditions;
		update_post_meta( $template_id, self::META_TEMPLATE_SETTINGS, $current );
		return true;
	}

	/**
	 * Lista posts/paginas que usam o Bricks.
	 *
	 * @param array $args {
	 *     @type string $post_type Tipo (default 'any').
	 *     @type int    $limit     Maximo (default 50).
	 *     @type string $status    Status (default 'any').
	 * }
	 * @return array Lista resumida.
	 */
	public static function list_pages( array $args = array() ) {
		$post_type = isset( $args['post_type'] ) ? sanitize_key( $args['post_type'] ) : 'any';
		$limit     = isset( $args['limit'] ) ? min( 200, max( 1, (int) $args['limit'] ) ) : 50;
		$status    = isset( $args['status'] ) ? sanitize_key( $args['status'] ) : 'any';

		$query = new \WP_Query(
			array(
				'post_type'      => 'any' === $post_type ? array( 'page', 'post' ) : $post_type,
				'post_status'    => $status,
				'posts_per_page' => $limit,
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					array(
						'key'   => self::META_EDITOR_MODE,
						'value' => 'bricks',
					),
				),
				'fields'         => 'ids',
			)
		);

		$out = array();
		foreach ( $query->posts as $pid ) {
			$out[] = self::summarize_post( (int) $pid );
		}
		return $out;
	}

	/**
	 * Lista templates Bricks, opcionalmente filtrando por tipo.
	 *
	 * @param string|null $type Tipo do template ou null para todos.
	 * @return array
	 */
	public static function list_templates( $type = null ) {
		$meta_query = array();
		if ( $type ) {
			$meta_query[] = array(
				'key'   => self::META_TEMPLATE_TYPE,
				'value' => sanitize_key( $type ),
			);
		}

		$query = new \WP_Query(
			array(
				'post_type'      => self::TEMPLATE_CPT,
				'post_status'    => 'any',
				'posts_per_page' => 200,
				'meta_query'     => $meta_query, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				'fields'         => 'ids',
			)
		);

		$out = array();
		foreach ( $query->posts as $pid ) {
			$summary         = self::summarize_post( (int) $pid );
			$summary['type'] = get_post_meta( (int) $pid, self::META_TEMPLATE_TYPE, true );
			$out[]           = $summary;
		}
		return $out;
	}

	/**
	 * Resume um post para listagem.
	 *
	 * @param int $post_id Post.
	 * @return array
	 */
	public static function summarize_post( $post_id ) {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return array( 'id' => $post_id );
		}
		return array(
			'id'        => (int) $post_id,
			'title'     => get_the_title( $post_id ),
			'post_type' => $post->post_type,
			'status'    => $post->post_status,
			'slug'      => $post->post_name,
			'edit_url'  => self::builder_url( $post_id ),
			'view_url'  => get_permalink( $post_id ),
		);
	}

	/**
	 * Monta a URL de edicao no builder do Bricks.
	 *
	 * @param int $post_id Post.
	 * @return string
	 */
	public static function builder_url( $post_id ) {
		return add_query_arg(
			array(
				'bricks' => 'run',
			),
			get_permalink( $post_id )
		);
	}

	/**
	 * Exclui um post (move para lixeira por padrao).
	 *
	 * @param int  $post_id Post.
	 * @param bool $force   Se true, exclui permanentemente.
	 * @return true|WP_Error
	 */
	public static function delete_post( $post_id, $force = false ) {
		$post_id = (int) $post_id;
		if ( ! get_post( $post_id ) ) {
			return new WP_Error( 'mmb_post_missing', __( 'Post inexistente.', 'marreira-mcp-builders' ), array( 'status' => 404 ) );
		}
		$res = wp_delete_post( $post_id, (bool) $force );
		if ( ! $res ) {
			return new WP_Error( 'mmb_delete_failed', __( 'Falha ao excluir o post.', 'marreira-mcp-builders' ), array( 'status' => 500 ) );
		}
		return true;
	}

	/**
	 * Normaliza o status do post para um conjunto seguro.
	 *
	 * @param string $status Status pedido.
	 * @return string
	 */
	private static function sanitize_status( $status ) {
		$allowed = array( 'draft', 'publish', 'pending', 'private' );
		$status  = sanitize_key( $status );
		return in_array( $status, $allowed, true ) ? $status : 'draft';
	}

	/**
	 * Indica se um post existe e e do Bricks.
	 *
	 * @param int $post_id Post.
	 * @return bool
	 */
	public static function is_bricks_post( $post_id ) {
		return 'bricks' === get_post_meta( (int) $post_id, self::META_EDITOR_MODE, true );
	}
}
