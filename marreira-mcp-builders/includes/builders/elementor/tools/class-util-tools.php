<?php
/**
 * Util_Tools: capacidades, validacao, regen de CSS e introspeccao de widgets.
 *
 * @package Marreira\MCP_Builders
 */

namespace Marreira\MCP_Builders\Builders\Elementor\Tools;

use Marreira\MCP_Builders\MCP\Tool_Registry;
use Marreira\MCP_Builders\Builders\Elementor\Elementor_Gateway;
use Marreira\MCP_Builders\Builders\Elementor\Element_Tree;
use Marreira\MCP_Builders\Builders\Elementor\Element_Inspector;
use Marreira\MCP_Builders\Builders\Elementor\Global_Styles;
use Marreira\MCP_Builders\Builders\Elementor\Css_Regenerator;
use Marreira\MCP_Builders\Builders\Elementor\Code_Guard;
use Marreira\MCP_Builders\Auth\Rest_Guard;
use Marreira\MCP_Builders\Builders\Elementor\Security\Sanitizer;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Ferramentas utilitarias: ambiente, validacao e descoberta de widgets.
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
			__( 'Retorna o ambiente: versoes do plugin/Elementor/Pro, flags de seguranca, breakpoints e disponibilidade do Kit.', 'marreira-mcp-builders' ),
			self::schema( array() ),
			array( __CLASS__, 'get_capabilities' )
		);

		$registry->register(
			'validate_tree',
			__( 'Valida uma arvore de elementos (integridade, IDs e anti-RCE) sem gravar (dry-run).', 'marreira-mcp-builders' ),
			self::schema(
				array(
					'elements' => array(
						'type'        => 'array',
						'description' => 'Arvore aninhada de elementos (tambem aceita { content: [...] }).',
					),
				),
				array( 'elements' )
			),
			array( __CLASS__, 'validate_tree' )
		);

		$registry->register(
			'regenerate_css',
			__( 'Forca a regeneracao do CSS do Elementor (de um post, ou limpa todo o cache).', 'marreira-mcp-builders' ),
			self::schema(
				array(
					'post_id' => array(
						'type'        => 'integer',
						'description' => 'ID do post para regenerar. Omita para limpar todo o cache de CSS.',
					),
				)
			),
			array( __CLASS__, 'regenerate_css' )
		);

		$registry->register(
			'list_elements',
			__( 'Lista todos os widgets registrados no site (nome, titulo, categorias, se e Pro). Inclui Pro e de terceiros.', 'marreira-mcp-builders' ),
			self::schema( array() ),
			array( __CLASS__, 'list_elements' )
		);

		$registry->register(
			'get_element_schema',
			__( 'Retorna o schema de controles (settings) de um widget pelo widgetType.', 'marreira-mcp-builders' ),
			self::schema(
				array(
					'widget_type'  => array(
						'type'        => 'string',
						'description' => 'widgetType do widget (ex.: heading, image, button, form, posts).',
					),
					'include_full' => array(
						'type'        => 'boolean',
						'description' => 'Se true, inclui selectors/condition de cada controle. Padrao: false.',
					),
				),
				array( 'widget_type' )
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
		$settings = Rest_Guard::settings();

		return Tool_Registry::success_result(
			array(
				'plugin_version'    => MMCB_VERSION,
				'protocol_version'  => MMCB_MCP_PROTOCOL_VERSION,
				'elementor_active'  => Elementor_Gateway::is_elementor_active(),
				'elementor_version' => Elementor_Gateway::elementor_version(),
				'pro_active'        => Elementor_Gateway::is_pro_active(),
				'pro_version'       => defined( 'ELEMENTOR_PRO_VERSION' ) ? ELEMENTOR_PRO_VERSION : '',
				'kit_available'     => Global_Styles::available(),
				'kit_id'            => Global_Styles::available() ? Global_Styles::get_active_kit_id() : 0,
				'block_code'        => ! empty( $settings['block_code'] ),
				'https_only'        => ! empty( $settings['https_only'] ),
				'breakpoints'       => array(
					'mobile'        => 'sufixo _mobile',
					'tablet'        => 'sufixo _tablet',
					'note'          => 'Controles responsivos usam sufixos (_tablet, _mobile). Breakpoints reais ficam nas settings do Kit.',
				),
				'el_types'          => Element_Tree::EL_TYPES,
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
		$elements = isset( $args['elements'] ) ? $args['elements'] : array();
		$prepared = Sanitizer::prepare_tree( $elements );

		if ( is_wp_error( $prepared ) ) {
			return Tool_Registry::success_result(
				array(
					'valid' => false,
					'error' => $prepared->get_error_message(),
				),
				__( 'Arvore invalida.', 'marreira-mcp-builders' )
			);
		}

		return Tool_Registry::success_result(
			array(
				'valid'         => true,
				'element_count' => count( Element_Tree::collect_ids( $prepared ) ),
				'has_code'      => Code_Guard::contains_code( $prepared ),
			),
			__( 'Arvore valida.', 'marreira-mcp-builders' ) . self::code_warning( $prepared )
		);
	}

	/**
	 * Handler: regenerate_css.
	 *
	 * @param array $args Argumentos.
	 * @return array
	 */
	public static function regenerate_css( array $args ) {
		$cap = self::require_cap( 'edit_pages' );
		if ( $cap ) {
			return $cap;
		}
		$elementor = self::require_elementor();
		if ( $elementor ) {
			return $elementor;
		}

		$post_id = isset( $args['post_id'] ) ? (int) $args['post_id'] : 0;
		$ok      = Css_Regenerator::regenerate( $post_id );

		return Tool_Registry::success_result(
			array(
				'post_id' => $post_id,
				'ok'      => $ok,
				'scope'   => $post_id > 0 ? 'post' : 'all',
			),
			$post_id > 0 ? __( 'CSS do post regenerado.', 'marreira-mcp-builders' ) : __( 'Cache de CSS limpo.', 'marreira-mcp-builders' )
		);
	}

	/**
	 * Handler: list_elements.
	 *
	 * @param array $args Argumentos.
	 * @return array
	 */
	public static function list_elements( array $args ) {
		$cap = self::require_cap( 'edit_pages' );
		if ( $cap ) {
			return $cap;
		}
		if ( ! Element_Inspector::available() ) {
			return Tool_Registry::error_result( __( 'O Elementor nao esta ativo para introspeccao de widgets.', 'marreira-mcp-builders' ) );
		}
		return Tool_Registry::success_result( Element_Inspector::list_all() );
	}

	/**
	 * Handler: get_element_schema.
	 *
	 * @param array $args Argumentos.
	 * @return array
	 */
	public static function get_element_schema( array $args ) {
		$cap = self::require_cap( 'edit_pages' );
		if ( $cap ) {
			return $cap;
		}
		$widget_type  = isset( $args['widget_type'] ) ? sanitize_text_field( $args['widget_type'] ) : '';
		$include_full = ! empty( $args['include_full'] );

		$schema = Element_Inspector::get_schema( $widget_type, $include_full );
		if ( is_wp_error( $schema ) ) {
			return self::from_error( $schema );
		}

		return Tool_Registry::success_result( $schema );
	}
}
