<?php
/**
 * Template_Tools: tools de templates Bricks (header/footer/content/section).
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
 * CRUD de templates Bricks via MCP.
 */
class Template_Tools extends Base_Tools {

	/**
	 * Tipos de template aceitos.
	 *
	 * @var string[]
	 */
	const TYPES = array( 'header', 'footer', 'content', 'section', 'single', 'archive', 'search', 'error', 'popup' );

	/**
	 * Registra as tools de template.
	 *
	 * @param Tool_Registry $registry Registro.
	 * @return void
	 */
	public static function register( Tool_Registry $registry ) {

		$registry->register(
			'list_templates',
			__( 'Lista templates Bricks, opcionalmente filtrando por tipo.', 'marreira-mcp-builders' ),
			self::schema(
				array(
					'type' => array(
						'type'        => 'string',
						'description' => 'Filtra por tipo: header, footer, content, section, etc.',
					),
				)
			),
			array( __CLASS__, 'list_templates' )
		);

		$registry->register(
			'create_template',
			__( 'Cria um template Bricks de um tipo (header, footer, content, section...).', 'marreira-mcp-builders' ),
			self::schema(
				array(
					'title'    => array(
						'type'        => 'string',
						'description' => 'Titulo do template.',
					),
					'type'     => array(
						'type'        => 'string',
						'description' => 'Tipo: ' . implode( ', ', self::TYPES ) . '. Padrao: content.',
					),
					'status'   => array(
						'type'        => 'string',
						'description' => 'draft ou publish. Padrao: publish.',
					),
					'elements' => array(
						'type'        => 'array',
						'description' => 'Arvore de elementos (aceita formato clipboard).',
					),
				),
				array( 'title', 'type' )
			),
			array( __CLASS__, 'create_template' )
		);

		$registry->register(
			'update_template',
			__( 'Substitui a arvore de elementos de um template (round-trip-safe).', 'marreira-mcp-builders' ),
			self::schema(
				array(
					'template_id' => array(
						'type'        => 'integer',
						'description' => 'ID do template.',
					),
					'elements'    => array(
						'type'        => 'array',
						'description' => 'Nova arvore de elementos.',
					),
				),
				array( 'template_id', 'elements' )
			),
			array( __CLASS__, 'update_template' )
		);

		$registry->register(
			'set_template_conditions',
			__( 'Define as condicoes de aplicacao de um template (templateConditions).', 'marreira-mcp-builders' ),
			self::schema(
				array(
					'template_id' => array(
						'type'        => 'integer',
						'description' => 'ID do template.',
					),
					'conditions'  => array(
						'type'        => 'array',
						'description' => 'Array de condicoes, ex.: [{ "main": "any" }] ou [{ "main": "postType", "postType": ["page"] }].',
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
		return Tool_Registry::success_result( Bricks_Gateway::list_templates( $type ) );
	}

	/**
	 * Handler: create_template.
	 *
	 * @param array $args Argumentos.
	 * @return array
	 */
	public static function create_template( array $args ) {
		$bricks = self::require_bricks();
		if ( $bricks ) {
			return $bricks;
		}
		$cap = self::require_cap( 'edit_theme_options' );
		if ( $cap ) {
			return $cap;
		}

		$type = isset( $args['type'] ) ? sanitize_key( $args['type'] ) : 'content';
		if ( ! in_array( $type, self::TYPES, true ) ) {
			return Tool_Registry::error_result(
				sprintf(
					/* translators: %s: list of types */
					__( 'Tipo de template invalido. Use um de: %s', 'marreira-mcp-builders' ),
					implode( ', ', self::TYPES )
				)
			);
		}

		$prepared = Sanitizer::prepare_tree( isset( $args['elements'] ) ? $args['elements'] : array() );
		if ( is_wp_error( $prepared ) ) {
			return self::from_error( $prepared );
		}

		$id = Bricks_Gateway::create_template(
			array(
				'title'    => isset( $args['title'] ) ? $args['title'] : '',
				'type'     => $type,
				'status'   => isset( $args['status'] ) ? $args['status'] : 'publish',
				'elements' => $prepared,
			)
		);
		if ( is_wp_error( $id ) ) {
			return self::from_error( $id );
		}

		Css_Regenerator::regenerate( $id );

		$summary         = Bricks_Gateway::summarize_post( $id );
		$summary['type'] = $type;
		return Tool_Registry::success_result( $summary, __( 'Template criado.', 'marreira-mcp-builders' ) . self::code_warning( $prepared ) );
	}

	/**
	 * Handler: update_template.
	 *
	 * @param array $args Argumentos.
	 * @return array
	 */
	public static function update_template( array $args ) {
		$cap = self::require_cap( 'edit_theme_options' );
		if ( $cap ) {
			return $cap;
		}
		$template_id = isset( $args['template_id'] ) ? (int) $args['template_id'] : 0;
		$post        = get_post( $template_id );
		if ( ! $post || Bricks_Gateway::TEMPLATE_CPT !== $post->post_type ) {
			return Tool_Registry::error_result( __( 'Template Bricks inexistente.', 'marreira-mcp-builders' ) );
		}

		$type = get_post_meta( $template_id, Bricks_Gateway::META_TEMPLATE_TYPE, true );
		$area = in_array( $type, array( 'header', 'footer' ), true ) ? $type : 'content';

		$prepared = Sanitizer::prepare_tree( isset( $args['elements'] ) ? $args['elements'] : array() );
		if ( is_wp_error( $prepared ) ) {
			return self::from_error( $prepared );
		}

		$saved = Bricks_Gateway::save_elements( $template_id, $prepared, $area );
		if ( is_wp_error( $saved ) ) {
			return self::from_error( $saved );
		}

		Css_Regenerator::regenerate( $template_id );

		return Tool_Registry::success_result(
			Bricks_Gateway::summarize_post( $template_id ),
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
		$conditions  = isset( $args['conditions'] ) ? $args['conditions'] : array();

		$clean = Sanitizer::deep_clean( $conditions );
		if ( is_wp_error( $clean ) ) {
			return self::from_error( $clean );
		}
		if ( ! is_array( $clean ) ) {
			return Tool_Registry::error_result( __( 'conditions precisa ser um array.', 'marreira-mcp-builders' ) );
		}

		$res = Bricks_Gateway::set_template_conditions( $template_id, $clean );
		if ( is_wp_error( $res ) ) {
			return self::from_error( $res );
		}

		return Tool_Registry::success_result(
			array( 'template_id' => $template_id, 'conditions' => $clean ),
			__( 'Condicoes do template definidas.', 'marreira-mcp-builders' )
		);
	}
}
