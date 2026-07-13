<?php
/**
 * Elementor_Gateway: ponto unico de leitura/escrita dos dados do Elementor.
 *
 * @package Marreira\MCP_Builders
 */

namespace Marreira\MCP_Builders\Builders\Elementor;

use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Encapsula todo acesso ao formato nativo do Elementor (postmeta) garantindo
 * round-trip com o editor.
 *
 * Sempre que possivel a escrita e delegada ao Document API do Elementor
 * (`Document::save()`), que cuida de kses, versionamento, marca de edicao e
 * regeneracao de CSS. Quando o Document API nao esta disponivel, ha um fallback
 * por postmeta cru (com os mesmos efeitos minimos).
 */
class Elementor_Gateway {

	const META_DATA          = '_elementor_data';
	const META_EDIT_MODE     = '_elementor_edit_mode';
	const META_TEMPLATE_TYPE = '_elementor_template_type';
	const META_VERSION       = '_elementor_version';
	const META_PAGE_SETTINGS = '_elementor_page_settings';
	const META_CONDITIONS    = '_elementor_conditions';

	const LIBRARY_CPT      = 'elementor_library';
	const LIBRARY_TAXONOMY = 'elementor_library_type';

	/**
	 * Tipos de template do Theme Builder que exigem Elementor Pro.
	 *
	 * @var string[]
	 */
	const PRO_TEMPLATE_TYPES = array(
		'header',
		'footer',
		'single',
		'single-post',
		'single-page',
		'archive',
		'search-results',
		'error-404',
		'loop-item',
		'loop-grid',
		'popup',
		'mega-menu',
	);

	/**
	 * Indica se o Elementor (core) esta ativo.
	 *
	 * @return bool
	 */
	public static function is_elementor_active() {
		return defined( 'ELEMENTOR_VERSION' ) && class_exists( '\Elementor\Plugin' );
	}

	/**
	 * Indica se o Elementor Pro esta ativo.
	 *
	 * @return bool
	 */
	public static function is_pro_active() {
		return defined( 'ELEMENTOR_PRO_VERSION' );
	}

	/**
	 * Versao do Elementor core (ou string vazia).
	 *
	 * @return string
	 */
	public static function elementor_version() {
		return defined( 'ELEMENTOR_VERSION' ) ? ELEMENTOR_VERSION : '';
	}

	/**
	 * Le a arvore de elementos (aninhada) de um post.
	 *
	 * @param int $post_id Post.
	 * @return array
	 */
	public static function get_elements( $post_id ) {
		$post_id = (int) $post_id;

		// Caminho preferido: Document API.
		$doc = self::get_document( $post_id );
		if ( $doc && method_exists( $doc, 'get_elements_data' ) ) {
			try {
				$data = $doc->get_elements_data();
				if ( is_array( $data ) ) {
					return $data;
				}
			} catch ( \Throwable $e ) {
				// Cai no fallback.
			}
		}

		// Fallback: postmeta cru.
		$raw = get_post_meta( $post_id, self::META_DATA, true );
		if ( is_string( $raw ) && '' !== $raw ) {
			$decoded = json_decode( $raw, true );
			return is_array( $decoded ) ? $decoded : array();
		}
		return array();
	}

	/**
	 * Grava a arvore de elementos num post, marcando-o como pagina Elementor.
	 *
	 * @param int   $post_id  Post.
	 * @param array $elements Arvore aninhada (ja validada).
	 * @return true|WP_Error
	 */
	public static function save_elements( $post_id, array $elements ) {
		$post_id = (int) $post_id;
		if ( ! get_post( $post_id ) ) {
			return new WP_Error( 'mme_post_missing', __( 'Post inexistente.', 'marreira-mcp-builders' ), array( 'status' => 404 ) );
		}

		// Caminho preferido: Document::save() (kses + regen CSS + versao).
		$doc = self::get_document( $post_id );
		if ( $doc && method_exists( $doc, 'save' ) ) {
			try {
				$doc->save( array( 'elements' => $elements ) );
				return true;
			} catch ( \Throwable $e ) {
				// Cai no fallback cru.
			}
		}

		// Fallback: postmeta cru (Elementor faz stripslashes ao gravar metas,
		// por isso o wp_slash sobre o JSON, identico ao que o proprio Elementor faz).
		update_post_meta( $post_id, self::META_DATA, wp_slash( wp_json_encode( $elements ) ) );
		update_post_meta( $post_id, self::META_EDIT_MODE, 'builder' );
		update_post_meta( $post_id, self::META_VERSION, self::elementor_version() ? self::elementor_version() : '3.0.0' );

		if ( class_exists( '\Marreira\MCP_Builders\Builders\Elementor\Css_Regenerator' ) ) {
			Css_Regenerator::regenerate( $post_id );
		}

		return true;
	}

	/**
	 * Cria uma nova pagina/post Elementor e grava os elementos.
	 *
	 * @param array $args {
	 *     @type string $title     Titulo.
	 *     @type string $post_type Tipo (default 'page').
	 *     @type string $status    Status (default 'draft').
	 *     @type array  $elements  Arvore.
	 *     @type string $slug      Slug opcional.
	 * }
	 * @return int|WP_Error Id do post criado.
	 */
	public static function create_page( array $args ) {
		$title     = isset( $args['title'] ) ? sanitize_text_field( $args['title'] ) : __( 'Pagina Elementor', 'marreira-mcp-builders' );
		$post_type = isset( $args['post_type'] ) ? sanitize_key( $args['post_type'] ) : 'page';
		$status    = self::sanitize_status( isset( $args['status'] ) ? $args['status'] : 'draft' );
		$elements  = isset( $args['elements'] ) && is_array( $args['elements'] ) ? $args['elements'] : array();

		if ( ! post_type_exists( $post_type ) ) {
			return new WP_Error( 'mme_invalid_post_type', sprintf( /* translators: %s: post type */ __( 'Tipo de post inexistente: %s', 'marreira-mcp-builders' ), $post_type ), array( 'status' => 422 ) );
		}

		$postarr = array(
			'post_title'   => $title,
			'post_type'    => $post_type,
			'post_status'  => $status,
			'post_content' => '',
		);
		if ( ! empty( $args['slug'] ) ) {
			$postarr['post_name'] = sanitize_title( $args['slug'] );
		}

		$post_id = wp_insert_post( $postarr, true );
		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		// Marca como documento Elementor antes de salvar (necessario para o
		// Document API reconhecer o post).
		update_post_meta( $post_id, self::META_EDIT_MODE, 'builder' );
		update_post_meta( $post_id, self::META_TEMPLATE_TYPE, 'post' === $post_type ? 'wp-post' : 'wp-page' );

		$saved = self::save_elements( $post_id, $elements );
		if ( is_wp_error( $saved ) ) {
			return $saved;
		}

		return (int) $post_id;
	}

	/**
	 * Cria um template Elementor (elementor_library).
	 *
	 * @param array $args {
	 *     @type string $title    Titulo.
	 *     @type string $type     Tipo (page, section, container, header, footer, ...).
	 *     @type string $status   Status (default 'publish').
	 *     @type array  $elements Arvore.
	 * }
	 * @return int|WP_Error
	 */
	public static function create_template( array $args ) {
		$title    = isset( $args['title'] ) ? sanitize_text_field( $args['title'] ) : __( 'Template Elementor', 'marreira-mcp-builders' );
		$type     = isset( $args['type'] ) ? sanitize_key( $args['type'] ) : 'section';
		$status   = self::sanitize_status( isset( $args['status'] ) ? $args['status'] : 'publish' );
		$elements = isset( $args['elements'] ) && is_array( $args['elements'] ) ? $args['elements'] : array();

		if ( in_array( $type, self::PRO_TEMPLATE_TYPES, true ) && ! self::is_pro_active() ) {
			return new WP_Error(
				'mme_pro_required',
				sprintf( /* translators: %s: template type */ __( 'O tipo de template "%s" exige o Elementor Pro (Theme Builder).', 'marreira-mcp-builders' ), $type ),
				array( 'status' => 422 )
			);
		}

		$post_id = wp_insert_post(
			array(
				'post_title'   => $title,
				'post_type'    => self::LIBRARY_CPT,
				'post_status'  => $status,
				'post_content' => '',
			),
			true
		);
		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		update_post_meta( $post_id, self::META_EDIT_MODE, 'builder' );
		update_post_meta( $post_id, self::META_TEMPLATE_TYPE, $type );

		// Taxonomia que o Elementor usa para classificar templates.
		if ( taxonomy_exists( self::LIBRARY_TAXONOMY ) ) {
			wp_set_object_terms( $post_id, $type, self::LIBRARY_TAXONOMY );
		}

		$saved = self::save_elements( $post_id, $elements );
		if ( is_wp_error( $saved ) ) {
			return $saved;
		}

		return (int) $post_id;
	}

	/**
	 * Le as page settings (document settings) de um post.
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
			return new WP_Error( 'mme_post_missing', __( 'Post inexistente.', 'marreira-mcp-builders' ), array( 'status' => 404 ) );
		}
		$current = self::get_page_settings( $post_id );
		$merged  = array_merge( $current, $settings );
		update_post_meta( $post_id, self::META_PAGE_SETTINGS, $merged );

		// O "Page Layout" do Elementor (Canvas / Header-Footer / Default) so vale
		// se o template real do WordPress (_wp_page_template) for sincronizado.
		// Sem isso, o tema continua renderizando header/footer. Espelha o que o
		// editor do Elementor faz ao salvar.
		if ( isset( $settings['template'] ) ) {
			self::sync_wp_page_template( $post_id, (string) $settings['template'] );
		}

		if ( class_exists( '\Marreira\MCP_Builders\Builders\Elementor\Css_Regenerator' ) ) {
			Css_Regenerator::regenerate( $post_id );
		}
		return true;
	}

	/**
	 * Sincroniza o template de pagina do WordPress (_wp_page_template) com o
	 * Page Layout escolhido no Elementor.
	 *
	 * @param int    $post_id  Post.
	 * @param string $template Valor: default, elementor_canvas ou elementor_header_footer.
	 * @return void
	 */
	private static function sync_wp_page_template( $post_id, $template ) {
		$allowed = array( 'default', 'elementor_canvas', 'elementor_header_footer' );
		if ( ! in_array( $template, $allowed, true ) ) {
			return;
		}
		if ( 'default' === $template ) {
			delete_post_meta( $post_id, '_wp_page_template' );
			return;
		}
		update_post_meta( $post_id, '_wp_page_template', $template );
	}

	/**
	 * Define as condicoes de exibicao de um template do Theme Builder (Pro).
	 *
	 * @param int   $template_id Id do template.
	 * @param array $conditions  Lista de strings de condicao (ex.: "include/general").
	 * @return true|WP_Error
	 */
	public static function set_template_conditions( $template_id, array $conditions ) {
		$template_id = (int) $template_id;
		$post        = get_post( $template_id );
		if ( ! $post || self::LIBRARY_CPT !== $post->post_type ) {
			return new WP_Error( 'mme_not_template', __( 'Template Elementor inexistente.', 'marreira-mcp-builders' ), array( 'status' => 404 ) );
		}
		if ( ! self::is_pro_active() ) {
			return new WP_Error( 'mme_pro_required', __( 'Condicoes de exibicao exigem o Elementor Pro (Theme Builder).', 'marreira-mcp-builders' ), array( 'status' => 422 ) );
		}

		$clean = array();
		foreach ( $conditions as $c ) {
			if ( is_string( $c ) && '' !== $c ) {
				$clean[] = sanitize_text_field( $c );
			}
		}

		update_post_meta( $template_id, self::META_CONDITIONS, $clean );

		// Limpa o cache do Theme Builder, se disponivel.
		if ( class_exists( '\Elementor\Plugin' ) ) {
			try {
				if ( isset( \Elementor\Plugin::$instance->files_manager ) ) {
					\Elementor\Plugin::$instance->files_manager->clear_cache();
				}
			} catch ( \Throwable $e ) {
				// Ignora.
			}
		}

		return true;
	}

	/**
	 * Lista posts/paginas construidos com Elementor (exclui a biblioteca).
	 *
	 * @param array $args {
	 *     @type string $post_type Tipo (default 'any').
	 *     @type int    $limit     Maximo (default 50).
	 *     @type string $status    Status (default 'any').
	 * }
	 * @return array
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
						'key'   => self::META_EDIT_MODE,
						'value' => 'builder',
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
	 * Lista templates Elementor, opcionalmente filtrando por tipo.
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
				'post_type'      => self::LIBRARY_CPT,
				'post_status'    => 'any',
				'posts_per_page' => 200,
				'meta_query'     => $meta_query, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				'fields'         => 'ids',
			)
		);

		$out = array();
		foreach ( $query->posts as $pid ) {
			$summary                  = self::summarize_post( (int) $pid );
			$summary['template_type'] = get_post_meta( (int) $pid, self::META_TEMPLATE_TYPE, true );
			$summary['conditions']    = get_post_meta( (int) $pid, self::META_CONDITIONS, true );
			$out[]                    = $summary;
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
			return array( 'id' => (int) $post_id );
		}
		return array(
			'id'            => (int) $post_id,
			'title'         => get_the_title( $post_id ),
			'post_type'     => $post->post_type,
			'status'        => $post->post_status,
			'slug'          => $post->post_name,
			'template_type' => get_post_meta( (int) $post_id, self::META_TEMPLATE_TYPE, true ),
			'edit_url'      => self::editor_url( $post_id ),
			'view_url'      => get_permalink( $post_id ),
		);
	}

	/**
	 * Monta a URL de edicao no editor do Elementor.
	 *
	 * @param int $post_id Post.
	 * @return string
	 */
	public static function editor_url( $post_id ) {
		return add_query_arg(
			array(
				'post'   => (int) $post_id,
				'action' => 'elementor',
			),
			admin_url( 'post.php' )
		);
	}

	/**
	 * Exclui um post (lixeira por padrao).
	 *
	 * @param int  $post_id Post.
	 * @param bool $force   Se true, exclui permanentemente.
	 * @return true|WP_Error
	 */
	public static function delete_post( $post_id, $force = false ) {
		$post_id = (int) $post_id;
		if ( ! get_post( $post_id ) ) {
			return new WP_Error( 'mme_post_missing', __( 'Post inexistente.', 'marreira-mcp-builders' ), array( 'status' => 404 ) );
		}
		$res = wp_delete_post( $post_id, (bool) $force );
		if ( ! $res ) {
			return new WP_Error( 'mme_delete_failed', __( 'Falha ao excluir o post.', 'marreira-mcp-builders' ), array( 'status' => 500 ) );
		}
		return true;
	}

	/**
	 * Indica se um post foi construido com Elementor.
	 *
	 * @param int $post_id Post.
	 * @return bool
	 */
	public static function is_elementor_post( $post_id ) {
		return 'builder' === get_post_meta( (int) $post_id, self::META_EDIT_MODE, true );
	}

	/**
	 * Obtem o Document do Elementor para um post (ou null).
	 *
	 * @param int $post_id Post.
	 * @return object|null
	 */
	private static function get_document( $post_id ) {
		if ( ! self::is_elementor_active() ) {
			return null;
		}
		try {
			if ( isset( \Elementor\Plugin::$instance->documents ) ) {
				$doc = \Elementor\Plugin::$instance->documents->get( (int) $post_id );
				return $doc ? $doc : null;
			}
		} catch ( \Throwable $e ) {
			return null;
		}
		return null;
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
}
