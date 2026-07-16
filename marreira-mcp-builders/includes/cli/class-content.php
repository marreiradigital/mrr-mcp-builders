<?php
/**
 * Helpers de conteudo: posts, termos, comentarios e midia.
 *
 * Portado de MRR_WP_CLI_Content, adaptado ao namespace e codigos de erro
 * deste plugin. Logica identica ao original; apenas namespace, prefixo de
 * erro e dominio de traducao foram alterados.
 *
 * @package Marreira\MCP_Builders
 */

namespace Marreira\MCP_Builders\CLI;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Operacoes de CRUD sobre posts, taxonomias, termos, comentarios e midia.
 */
class Content {

	/* ------------------------------------------------------------------ */
	/*  Posts                                                               */
	/* ------------------------------------------------------------------ */

	private static function post_to_array( \WP_Post $post, bool $full = false ): array {
		$out = array(
			'id'             => $post->ID,
			'type'           => $post->post_type,
			'status'         => $post->post_status,
			'title'          => $post->post_title,
			'slug'           => $post->post_name,
			'author'         => (int) $post->post_author,
			'date'           => $post->post_date_gmt,
			'modified'       => $post->post_modified_gmt,
			'parent'         => (int) $post->post_parent,
			'menu_order'     => (int) $post->menu_order,
			'comment_status' => $post->comment_status,
			'permalink'      => get_permalink( $post ) ?: null,
			'excerpt'        => has_excerpt( $post ) ? get_the_excerpt( $post ) : '',
		);
		if ( $full ) {
			$out['content']  = $post->post_content;
			$out['template'] = get_page_template_slug( $post ) ?: null;
			$out['terms']    = self::collect_terms( $post );
			$out['meta']     = self::collect_safe_meta( $post->ID );
		}
		return $out;
	}

	private static function collect_terms( \WP_Post $post ): array {
		$out = array();
		foreach ( get_object_taxonomies( $post->post_type ) as $tax ) {
			$terms = get_the_terms( $post, $tax );
			if ( is_wp_error( $terms ) || empty( $terms ) ) {
				continue;
			}
			$out[ $tax ] = array_map(
				fn( $t ) => array( 'id' => (int) $t->term_id, 'name' => $t->name, 'slug' => $t->slug ),
				$terms
			);
		}
		return $out;
	}

	private static function collect_safe_meta( int $post_id ): array {
		$all = get_post_meta( $post_id );
		$out = array();
		foreach ( $all as $key => $values ) {
			// Pula campos privados (comecam com _) por padrao.
			if ( str_starts_with( $key, '_' ) ) {
				continue;
			}
			$out[ $key ] = count( $values ) === 1 ? maybe_unserialize( $values[0] ) : array_map( 'maybe_unserialize', $values );
		}
		return $out;
	}

	public static function list_posts( array $args ): array {
		$query_args = array(
			'post_type'      => $args['type'] ?? 'any',
			'post_status'    => $args['status'] ?? 'any',
			'posts_per_page' => max( 1, min( 100, (int) ( $args['per_page'] ?? 20 ) ) ),
			'paged'          => max( 1, (int) ( $args['page'] ?? 1 ) ),
			'orderby'        => $args['orderby'] ?? 'date',
			'order'          => strtoupper( (string) ( $args['order'] ?? 'DESC' ) ) === 'ASC' ? 'ASC' : 'DESC',
			's'              => isset( $args['search'] ) ? (string) $args['search'] : '',
			'no_found_rows'  => false,
		);
		if ( ! empty( $args['author'] ) ) {
			$query_args['author'] = (int) $args['author'];
		}
		if ( isset( $args['parent'] ) ) {
			$query_args['post_parent'] = (int) $args['parent'];
		}
		if ( ! empty( $args['include'] ) ) {
			$query_args['post__in'] = array_map( 'intval', (array) $args['include'] );
		}

		$q     = new \WP_Query( $query_args );
		$posts = array_map( fn( \WP_Post $p ) => self::post_to_array( $p, false ), $q->posts );

		return array(
			'posts'    => $posts,
			'total'    => (int) $q->found_posts,
			'pages'    => (int) $q->max_num_pages,
			'page'     => $query_args['paged'],
			'per_page' => $query_args['posts_per_page'],
		);
	}

	/** @return array|\WP_Error */
	public static function get_post( int $id ) {
		$post = get_post( $id );
		if ( ! $post instanceof \WP_Post ) {
			return new \WP_Error( 'mmcb_not_found', 'Post não encontrado.', array( 'status' => 404 ) );
		}
		return self::post_to_array( $post, true );
	}

	/** @return array|\WP_Error */
	public static function create_post( array $data ) {
		$allowed_types = array_keys( get_post_types( array( 'show_ui' => true ), 'names' ) );
		$type          = sanitize_key( (string) ( $data['type'] ?? 'post' ) );
		if ( ! in_array( $type, $allowed_types, true ) && ! in_array( $type, array( 'post', 'page' ), true ) ) {
			return new \WP_Error( 'mmcb_bad_type', 'Tipo de post não permitido.', array( 'status' => 400 ) );
		}

		$args = array(
			'post_type'    => $type,
			'post_status'  => sanitize_key( (string) ( $data['status'] ?? 'draft' ) ),
			'post_title'   => sanitize_text_field( (string) ( $data['title'] ?? '' ) ),
			'post_content' => (string) ( $data['content'] ?? '' ),
			'post_excerpt' => (string) ( $data['excerpt'] ?? '' ),
			'post_author'  => (int) ( $data['author'] ?? get_current_user_id() ),
		);
		if ( ! empty( $data['slug'] ) ) {
			$args['post_name'] = sanitize_title( (string) $data['slug'] );
		}
		if ( isset( $data['parent'] ) ) {
			$args['post_parent'] = (int) $data['parent'];
		}
		if ( isset( $data['menu_order'] ) ) {
			$args['menu_order'] = (int) $data['menu_order'];
		}
		if ( ! empty( $data['date'] ) ) {
			$args['post_date_gmt'] = sanitize_text_field( (string) $data['date'] );
		}

		$id = wp_insert_post( wp_slash( $args ), true );
		if ( is_wp_error( $id ) ) {
			return $id;
		}

		self::apply_terms( (int) $id, $data['terms'] ?? array() );
		self::apply_meta( (int) $id, $data['meta'] ?? array() );

		return self::get_post( (int) $id );
	}

	/** @return array|\WP_Error */
	public static function update_post( int $id, array $data ) {
		if ( ! get_post( $id ) ) {
			return new \WP_Error( 'mmcb_not_found', 'Post não encontrado.', array( 'status' => 404 ) );
		}
		$args = array( 'ID' => $id );
		$map  = array(
			'title'      => 'post_title',
			'content'    => 'post_content',
			'excerpt'    => 'post_excerpt',
			'status'     => 'post_status',
			'slug'       => 'post_name',
			'author'     => 'post_author',
			'parent'     => 'post_parent',
			'menu_order' => 'menu_order',
		);
		foreach ( $map as $key => $wp_key ) {
			if ( array_key_exists( $key, $data ) ) {
				$args[ $wp_key ] = is_string( $data[ $key ] ) ? wp_slash( $data[ $key ] ) : $data[ $key ];
			}
		}
		$res = wp_update_post( $args, true );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		if ( isset( $data['terms'] ) ) {
			self::apply_terms( $id, (array) $data['terms'] );
		}
		if ( isset( $data['meta'] ) ) {
			self::apply_meta( $id, (array) $data['meta'] );
		}
		return self::get_post( $id );
	}

	/** @return array|\WP_Error */
	public static function delete_post( int $id, bool $force = false ) {
		if ( ! get_post( $id ) ) {
			return new \WP_Error( 'mmcb_not_found', 'Post não encontrado.', array( 'status' => 404 ) );
		}
		$res = wp_delete_post( $id, $force );
		if ( ! $res ) {
			return new \WP_Error( 'mmcb_delete_failed', 'Falha ao excluir.', array( 'status' => 500 ) );
		}
		return array( 'deleted' => true, 'id' => $id, 'force' => $force );
	}

	private static function apply_terms( int $post_id, array $terms ): void {
		foreach ( $terms as $taxonomy => $values ) {
			$taxonomy = sanitize_key( (string) $taxonomy );
			if ( ! taxonomy_exists( $taxonomy ) ) {
				continue;
			}
			$values = (array) $values;
			$ids    = array();
			foreach ( $values as $v ) {
				if ( is_numeric( $v ) ) {
					$ids[] = (int) $v;
				} else {
					$term = term_exists( (string) $v, $taxonomy );
					if ( ! $term ) {
						$term = wp_insert_term( (string) $v, $taxonomy );
					}
					if ( ! is_wp_error( $term ) && isset( $term['term_id'] ) ) {
						$ids[] = (int) $term['term_id'];
					}
				}
			}
			wp_set_object_terms( $post_id, $ids, $taxonomy, false );
		}
	}

	private static function apply_meta( int $post_id, array $meta ): void {
		// Bloqueia chaves perigosas / privadas.
		$blocked = array( '_edit_lock', '_edit_last' );
		foreach ( $meta as $key => $value ) {
			$key = (string) $key;
			if ( str_starts_with( $key, '_' ) || in_array( $key, $blocked, true ) ) {
				continue;
			}
			update_post_meta( $post_id, $key, $value );
		}
	}

	/* ------------------------------------------------------------------ */
	/*  Tipos / Taxonomias                                                  */
	/* ------------------------------------------------------------------ */

	public static function list_post_types(): array {
		$out = array();
		foreach ( get_post_types( array(), 'objects' ) as $name => $obj ) {
			$out[] = array(
				'name'         => $name,
				'label'        => $obj->label,
				'public'       => (bool) $obj->public,
				'hierarchical' => (bool) $obj->hierarchical,
				'taxonomies'   => get_object_taxonomies( $name ),
				'supports'     => array_keys( get_all_post_type_supports( $name ) ),
				'count'        => array_sum( (array) wp_count_posts( $name ) ),
			);
		}
		return $out;
	}

	public static function list_taxonomies(): array {
		$out = array();
		foreach ( get_taxonomies( array(), 'objects' ) as $name => $obj ) {
			$out[] = array(
				'name'         => $name,
				'label'        => $obj->label,
				'hierarchical' => (bool) $obj->hierarchical,
				'object_types' => $obj->object_type,
				'public'       => (bool) $obj->public,
			);
		}
		return $out;
	}

	/* ------------------------------------------------------------------ */
	/*  Termos                                                              */
	/* ------------------------------------------------------------------ */

	public static function list_terms( array $args ): array {
		$tax = sanitize_key( (string) ( $args['taxonomy'] ?? 'category' ) );
		if ( ! taxonomy_exists( $tax ) ) {
			return array( 'terms' => array(), 'total' => 0 );
		}
		$terms = get_terms( array(
			'taxonomy'   => $tax,
			'hide_empty' => false,
			'number'     => max( 1, min( 200, (int) ( $args['per_page'] ?? 50 ) ) ),
			'offset'     => max( 0, ( max( 1, (int) ( $args['page'] ?? 1 ) ) - 1 ) * (int) ( $args['per_page'] ?? 50 ) ),
			'search'     => (string) ( $args['search'] ?? '' ),
		) );
		$out = array();
		foreach ( $terms as $t ) {
			$out[] = array(
				'id'          => (int) $t->term_id,
				'name'        => $t->name,
				'slug'        => $t->slug,
				'description' => $t->description,
				'count'       => (int) $t->count,
				'parent'      => (int) $t->parent,
				'taxonomy'    => $t->taxonomy,
			);
		}
		return array( 'terms' => $out, 'total' => count( $out ) );
	}

	/** @return array|\WP_Error */
	public static function create_term( array $data ) {
		$tax = sanitize_key( (string) ( $data['taxonomy'] ?? '' ) );
		if ( ! taxonomy_exists( $tax ) ) {
			return new \WP_Error( 'mmcb_bad_taxonomy', 'Taxonomia inválida.', array( 'status' => 400 ) );
		}
		$res = wp_insert_term(
			sanitize_text_field( (string) ( $data['name'] ?? '' ) ),
			$tax,
			array(
				'description' => sanitize_textarea_field( (string) ( $data['description'] ?? '' ) ),
				'slug'        => sanitize_title( (string) ( $data['slug'] ?? '' ) ),
				'parent'      => (int) ( $data['parent'] ?? 0 ),
			)
		);
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		return array( 'id' => (int) $res['term_id'], 'taxonomy' => $tax );
	}

	/** @return array|\WP_Error */
	public static function update_term( int $id, array $data ) {
		$term = get_term( $id );
		if ( ! $term || is_wp_error( $term ) ) {
			return new \WP_Error( 'mmcb_not_found', 'Termo não encontrado.', array( 'status' => 404 ) );
		}
		$res = wp_update_term( $id, $term->taxonomy, array(
			'name'        => isset( $data['name'] )        ? sanitize_text_field( (string) $data['name'] )        : $term->name,
			'description' => isset( $data['description'] ) ? sanitize_textarea_field( (string) $data['description'] ) : $term->description,
			'slug'        => isset( $data['slug'] )        ? sanitize_title( (string) $data['slug'] )             : $term->slug,
			'parent'      => isset( $data['parent'] )      ? (int) $data['parent']                                : $term->parent,
		) );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		return array( 'id' => $id, 'updated' => true );
	}

	/** @return array|\WP_Error */
	public static function delete_term( int $id ) {
		$term = get_term( $id );
		if ( ! $term || is_wp_error( $term ) ) {
			return new \WP_Error( 'mmcb_not_found', 'Termo não encontrado.', array( 'status' => 404 ) );
		}
		$res = wp_delete_term( $id, $term->taxonomy );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		return array( 'deleted' => (bool) $res, 'id' => $id );
	}

	/* ------------------------------------------------------------------ */
	/*  Comentarios                                                         */
	/* ------------------------------------------------------------------ */

	public static function list_comments( array $args ): array {
		$query = new \WP_Comment_Query( array(
			'number'  => max( 1, min( 100, (int) ( $args['per_page'] ?? 20 ) ) ),
			'paged'   => max( 1, (int) ( $args['page'] ?? 1 ) ),
			'status'  => (string) ( $args['status'] ?? 'all' ),
			'post_id' => isset( $args['post_id'] ) ? (int) $args['post_id'] : 0,
			'search'  => (string) ( $args['search'] ?? '' ),
		) );
		$out = array();
		foreach ( $query->get_comments() as $c ) {
			$out[] = array(
				'id'       => (int) $c->comment_ID,
				'post_id'  => (int) $c->comment_post_ID,
				'author'   => $c->comment_author,
				'email'    => $c->comment_author_email,
				'date'     => $c->comment_date_gmt,
				'content'  => $c->comment_content,
				'approved' => $c->comment_approved,
				'parent'   => (int) $c->comment_parent,
			);
		}
		return array( 'comments' => $out );
	}

	/** @return array|\WP_Error */
	public static function moderate_comment( int $id, string $action ) {
		$comment = get_comment( $id );
		if ( ! $comment ) {
			return new \WP_Error( 'mmcb_not_found', 'Comentário não encontrado.', array( 'status' => 404 ) );
		}
		switch ( $action ) {
			case 'approve':   wp_set_comment_status( $id, 'approve' ); break;
			case 'unapprove': wp_set_comment_status( $id, 'hold' );    break;
			case 'spam':      wp_spam_comment( $id );                  break;
			case 'trash':     wp_trash_comment( $id );                 break;
			case 'delete':    wp_delete_comment( $id, true );          break;
			default:
				return new \WP_Error( 'mmcb_bad_action', 'Ação inválida.', array( 'status' => 400 ) );
		}
		return array( 'id' => $id, 'action' => $action, 'ok' => true );
	}

	/* ------------------------------------------------------------------ */
	/*  Midia                                                               */
	/* ------------------------------------------------------------------ */

	public static function list_media( array $args ): array {
		return self::list_posts( array_merge( $args, array( 'type' => 'attachment', 'status' => 'inherit' ) ) );
	}
}
