<?php
/**
 * Template_Tools: tools de templates Elementor (elementor_library).
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
 * CRUD de templates Elementor via MCP.
 */
class Template_Tools extends Base_Tools {

	/**
	 * Registra as tools de template.
	 *
	 * @param Tool_Registry $registry Registro.
	 * @return void
	 */
	public static function register( Tool_Registry $registry ) {

		$registry->register(
			'list_templates',
			__( 'Lista templates Elementor (biblioteca), opcionalmente por tipo.', 'marreira-mcp-builders' ),
			self::schema(
				array(
					'type' => array(
						'type'        => 'string',
						'description' => 'Filtra por tipo: page, section, container, header, footer, single-post, archive, popup, loop-item, etc.',
					),
				)
			),
			array( __CLASS__, 'list_templates' )
		);

		$registry->register(
			'create_template',
			__( 'Cria um template Elementor. Tipos do Theme Builder (header, footer, single, archive, popup, loop-item) exigem Elementor Pro.', 'marreira-mcp-builders' ),
			self::schema(
				array(
					'title'    => array(
						'type'        => 'string',
						'description' => 'Titulo do template.',
					),
					'type'     => array(
						'type'        => 'string',
						'description' => 'Tipo: page, section, container (free) ou header, footer, single-post, archive, popup, loop-item (Pro).',
					),
					'status'   => array(
						'type'        => 'string',
						'description' => 'Status. Padrao: publish.',
					),
					'elements' => array(
						'type'        => 'array',
						'description' => 'Arvore aninhada de elementos.',
					),
				),
				array( 'title', 'type' )
			),
			array( __CLASS__, 'create_template' )
		);

		$registry->register(
			'update_template',
			__( 'Substitui a arvore de elementos de um template.', 'marreira-mcp-builders' ),
			self::schema(
				array(
					'template_id' => array(
						'type'        => 'integer',
						'description' => 'ID do template.',
					),
					'elements'    => array(
						'type'        => 'array',
						'description' => 'Nova arvore aninhada de elementos.',
					),
				),
				array( 'template_id', 'elements' )
			),
			array( __CLASS__, 'update_template' )
		);

		$registry->register(
			'set_template_conditions',
			__( 'Define onde um template do Theme Builder aparece (Elementor Pro). Conditions sao strings como "include/general" ou "include/singular/post".', 'marreira-mcp-builders' ),
			self::schema(
				array(
					'template_id' => array(
						'type'        => 'integer',
						'description' => 'ID do template.',
					),
					'conditions'  => array(
						'type'        => 'array',
						'description' => 'Lista de strings de condicao do Theme Builder.',
					),
				),
				array( 'template_id', 'conditions' )
			),
			array( __CLASS__, 'set_template_conditions' )
		);
	}

	/**
	 * Handler: list_templates.
	 *
	 * @param array $args Argumentos.
	 * @return array
	 */
	public static function list_templates( array $args ) {
		$cap = self::require_cap( 'edit_pages' );
		if ( $cap ) {
			return $cap;
		}
		$type = isset( $args['type'] ) ? sanitize_key( $args['type'] ) : null;
		return Tool_Registry::success_result( Elementor_Gateway::list_templates( $type ) );
	}

	/**
	 * Handler: create_template.
	 *
	 * @param array $args Argumentos.
	 * @return array
	 */
	public static function create_template( array $args ) {
		$elementor = self::require_elementor();
		if ( $elementor ) {
			return $elementor;
		}
		$cap = self::require_cap( 'publish_pages' );
		if ( $cap ) {
			return $cap;
		}

		$prepared = Sanitizer::prepare_tree( isset( $args['elements'] ) ? $args['elements'] : array() );
		if ( is_wp_error( $prepared ) ) {
			return self::from_error( $prepared );
		}

		$template_id = Elementor_Gateway::create_template(
			array(
				'title'    => isset( $args['title'] ) ? $args['title'] : '',
				'type'     => isset( $args['type'] ) ? $args['type'] : 'section',
				'status'   => isset( $args['status'] ) ? $args['status'] : 'publish',
				'elements' => $prepared,
			)
		);
		if ( is_wp_error( $template_id ) ) {
			return self::from_error( $template_id );
		}

		return Tool_Registry::success_result(
			Elementor_Gateway::summarize_post( $template_id ),
			__( 'Template Elementor criado.', 'marreira-mcp-builders' ) . self::code_warning( $prepared )
		);
	}

	/**
	 * Handler: update_template.
	 *
	 * @param array $args Argumentos.
	 * @return array
	 */
	public static function update_template( array $args ) {
		$template_id = isset( $args['template_id'] ) ? (int) $args['template_id'] : 0;
		$post        = get_post( $template_id );

		if ( ! $post || Elementor_Gateway::LIBRARY_CPT !== $post->post_type ) {
			return Tool_Registry::error_result( __( 'Template Elementor inexistente.', 'marreira-mcp-builders' ) );
		}
		if ( ! current_user_can( 'edit_post', $template_id ) ) {
			return Tool_Registry::error_result( __( 'Sem permissao para editar este template.', 'marreira-mcp-builders' ) );
		}

		$prepared = Sanitizer::prepare_tree( isset( $args['elements'] ) ? $args['elements'] : array() );
		if ( is_wp_error( $prepared ) ) {
			return self::from_error( $prepared );
		}

		$saved = Elementor_Gateway::save_elements( $template_id, $prepared );
		if ( is_wp_error( $saved ) ) {
			return self::from_error( $saved );
		}

		return Tool_Registry::success_result(
			Elementor_Gateway::summarize_post( $template_id ),
			__( 'Template atualizado.', 'marreira-mcp-builders' ) . self::code_warning( $prepared )
		);
	}

	/**
	 * Handler: set_template_conditions.
	 *
	 * @param array $args Argumentos.
	 * @return array
	 */
	public static function set_template_conditions( array $args ) {
		$cap = self::require_cap( 'edit_theme_options' );
		if ( $cap ) {
			return $cap;
		}
		$template_id = isset( $args['template_id'] ) ? (int) $args['template_id'] : 0;
		$conditions  = isset( $args['conditions'] ) && is_array( $args['conditions'] ) ? $args['conditions'] : array();

		$res = Elementor_Gateway::set_template_conditions( $template_id, $conditions );
		if ( is_wp_error( $res ) ) {
			return self::from_error( $res );
		}

		return Tool_Registry::success_result(
			array(
				'template_id' => $template_id,
				'conditions'  => $conditions,
			),
			__( 'Condicoes do template definidas.', 'marreira-mcp-builders' )
		);
	}
}
