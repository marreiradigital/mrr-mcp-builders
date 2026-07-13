<?php
/**
 * Util_Tools: utilidades (capabilities, validacao, regeneracao de CSS).
 *
 * @package Marreira\MCP_Builders
 */

namespace Marreira\MCP_Builders\Builders\Bricks\Tools;

use Marreira\MCP_Builders\MCP\Tool_Registry;
use Marreira\MCP_Builders\Builders\Bricks\Bricks_Gateway;
use Marreira\MCP_Builders\Builders\Bricks\Css_Regenerator;
use Marreira\MCP_Builders\Builders\Bricks\Element_Tree;
use Marreira\MCP_Builders\Builders\Bricks\Code_Guard;
use Marreira\MCP_Builders\Builders\Bricks\Element_Inspector;
use Marreira\MCP_Builders\Builders\Bricks\Security\Sanitizer;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Tools de apoio para a IA entender o ambiente e validar antes de gravar.
 */
class Util_Tools extends Base_Tools {

	/**
	 * Registra as tools utilitarias.
	 *
	 * @param Tool_Registry $registry Registro.
	 * @return void
	 */
	public static function register( Tool_Registry $registry ) {

		$registry->register(
			'get_capabilities',
			__( 'Retorna informacoes do ambiente: versao do Bricks, modo de CSS, breakpoints e flags de seguranca.', 'marreira-mcp-builders' ),
			self::schema( array() ),
			array( __CLASS__, 'get_capabilities' )
		);

		$registry->register(
			'validate_tree',
			__( 'Valida uma arvore de elementos (dry-run): integridade, IDs e guard de codigo. Nao grava nada.', 'marreira-mcp-builders' ),
			self::schema(
				array(
					'elements' => array(
						'type'        => 'array',
						'description' => 'Arvore plana ou payload clipboard { content: [...] }.',
					),
				),
				array( 'elements' )
			),
			array( __CLASS__, 'validate_tree' )
		);

		$registry->register(
			'regenerate_css',
			__( 'Forca a regeneracao do CSS do Bricks (relevante no modo External Files).', 'marreira-mcp-builders' ),
			self::schema(
				array(
					'post_id' => array(
						'type'        => 'integer',
						'description' => 'Post especifico a regenerar (opcional).',
					),
				)
			),
			array( __CLASS__, 'regenerate_css' )
		);

		$registry->register(
			'list_elements',
			__( 'Lista todos os elementos/widgets do Bricks registrados neste site (inclui Pro e de terceiros): nome, label, categoria, se e nestable.', 'marreira-mcp-builders' ),
			self::schema( array() ),
			array( __CLASS__, 'list_elements' )
		);

		$registry->register(
			'get_element_schema',
			__( 'Retorna o schema de settings de um widget do Bricks (tipos, defaults, opcoes), lido direto da definicao do elemento. Use para saber quais settings um widget aceita.', 'marreira-mcp-builders' ),
			self::schema(
				array(
					'name'        => array(
						'type'        => 'string',
						'description' => 'Nome do elemento (ex.: heading, button, form, slider-nested). Veja list_elements.',
					),
					'include_css' => array(
						'type'        => 'boolean',
						'description' => 'Se true, inclui o mapeamento CSS de cada controle. Padrao: false.',
					),
				),
				array( 'name' )
			),
			array( __CLASS__, 'get_element_schema' )
		);
	}

	/**
	 * Handler: get_capabilities.
	 *
	 * @param array $args Argumentos.
	 * @return array
	 */
	public static function get_capabilities( array $args ) {
		$breakpoints = get_option( 'bricks_breakpoints', array() );

		return Tool_Registry::success_result(
			array(
				'plugin_version'   => MMCB_VERSION,
				'mcp_protocol'     => MMCB_MCP_PROTOCOL_VERSION,
				'bricks_active'    => ( class_exists( '\Bricks\Elements' ) || defined( 'BRICKS_VERSION' ) ),
				'bricks_version'   => defined( 'BRICKS_VERSION' ) ? BRICKS_VERSION : null,
				'css_mode'         => Css_Regenerator::loading_method(),
				'css_needs_regen'  => Css_Regenerator::needs_regeneration(),
				'code_blocking'    => Code_Guard::is_blocking(),
				'content_meta_key' => Bricks_Gateway::META_CONTENT,
				'breakpoints'      => is_array( $breakpoints ) ? $breakpoints : array(),
				'default_breakpoints' => array( 'tablet_portrait', 'mobile_landscape', 'mobile_portrait' ),
			)
		);
	}

	/**
	 * Handler: validate_tree.
	 *
	 * @param array $args Argumentos.
	 * @return array
	 */
	public static function validate_tree( array $args ) {
		$elements = Sanitizer::extract_content( isset( $args['elements'] ) ? $args['elements'] : array() );
		if ( ! is_array( $elements ) ) {
			return Tool_Registry::error_result( __( 'elements precisa ser um array.', 'marreira-mcp-builders' ) );
		}

		$clean = Sanitizer::deep_clean( $elements );
		if ( is_wp_error( $clean ) ) {
			return Tool_Registry::success_result( array( 'valid' => false, 'reason' => $clean->get_error_message() ) );
		}

		$normalized = Element_Tree::normalize( $clean );

		$guard = Code_Guard::inspect_elements( $normalized );
		if ( is_wp_error( $guard ) ) {
			return Tool_Registry::success_result( array( 'valid' => false, 'reason' => $guard->get_error_message() ) );
		}

		$valid = Element_Tree::validate( $normalized );
		if ( is_wp_error( $valid ) ) {
			return Tool_Registry::success_result( array( 'valid' => false, 'reason' => $valid->get_error_message() ) );
		}

		return Tool_Registry::success_result(
			array(
				'valid'         => true,
				'element_count' => count( $normalized ),
				'ids'           => Element_Tree::collect_ids( $normalized ),
			),
			__( 'Arvore valida.', 'marreira-mcp-builders' )
		);
	}

	/**
	 * Handler: regenerate_css.
	 *
	 * @param array $args Argumentos.
	 * @return array
	 */
	public static function regenerate_css( array $args ) {
		$cap = self::require_cap( 'edit_theme_options' );
		if ( $cap ) {
			return $cap;
		}
		$post_id = isset( $args['post_id'] ) ? (int) $args['post_id'] : null;
		$result  = Css_Regenerator::regenerate( $post_id );
		return Tool_Registry::success_result( $result, __( 'Regeneracao de CSS solicitada.', 'marreira-mcp-builders' ) );
	}

	/**
	 * Handler: list_elements.
	 *
	 * @param array $args Argumentos.
	 * @return array
	 */
	public static function list_elements( array $args ) {
		$bricks = self::require_bricks();
		if ( $bricks ) {
			return $bricks;
		}
		$cap = self::require_cap( 'edit_pages' );
		if ( $cap ) {
			return $cap;
		}
		$elements = Element_Inspector::list_all();
		return Tool_Registry::success_result(
			array(
				'count'    => count( $elements ),
				'elements' => $elements,
			)
		);
	}

	/**
	 * Handler: get_element_schema.
	 *
	 * @param array $args Argumentos.
	 * @return array
	 */
	public static function get_element_schema( array $args ) {
		$bricks = self::require_bricks();
		if ( $bricks ) {
			return $bricks;
		}
		$cap = self::require_cap( 'edit_pages' );
		if ( $cap ) {
			return $cap;
		}

		$name = isset( $args['name'] ) ? (string) $args['name'] : '';
		if ( '' === $name ) {
			return Tool_Registry::error_result( __( 'Informe o "name" do elemento.', 'marreira-mcp-builders' ) );
		}

		$schema = Element_Inspector::get_schema( $name, ! empty( $args['include_css'] ) );
		if ( is_wp_error( $schema ) ) {
			return self::from_error( $schema );
		}
		return Tool_Registry::success_result( $schema );
	}
}
