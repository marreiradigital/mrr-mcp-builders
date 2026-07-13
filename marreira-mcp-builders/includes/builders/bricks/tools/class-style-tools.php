<?php
/**
 * Style_Tools: classes globais, paletas, theme styles e fontes.
 *
 * @package Marreira\MCP_Builders
 */

namespace Marreira\MCP_Builders\Builders\Bricks\Tools;

use Marreira\MCP_Builders\MCP\Tool_Registry;
use Marreira\MCP_Builders\Builders\Bricks\Global_Styles;
use Marreira\MCP_Builders\Builders\Bricks\Css_Regenerator;
use Marreira\MCP_Builders\Builders\Bricks\Security\Sanitizer;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Tools de estilos globais do Bricks.
 */
class Style_Tools extends Base_Tools {

	/**
	 * Registra as tools de estilo.
	 *
	 * @param Tool_Registry $registry Registro.
	 * @return void
	 */
	public static function register( Tool_Registry $registry ) {

		$registry->register(
			'list_global_classes',
			__( 'Lista as classes globais (CSS) do Bricks.', 'marreira-mcp-builders' ),
			self::schema( array() ),
			array( __CLASS__, 'list_global_classes' )
		);

		$registry->register(
			'upsert_global_class',
			__( 'Cria ou atualiza uma classe global do Bricks (por id ou name).', 'marreira-mcp-builders' ),
			self::schema(
				array(
					'id'       => array( 'type' => 'string', 'description' => 'ID da classe (opcional; gerado se ausente).' ),
					'name'     => array( 'type' => 'string', 'description' => 'Nome CSS da classe (ex.: "footer-link").' ),
					'settings' => array( 'type' => 'object', 'description' => 'Settings no formato do Bricks (mesmas chaves dos elementos).' ),
					'category' => array( 'type' => 'string', 'description' => 'Categoria opcional.' ),
				),
				array( 'name' )
			),
			array( __CLASS__, 'upsert_global_class' )
		);

		$registry->register(
			'delete_global_class',
			__( 'Remove uma classe global pelo id.', 'marreira-mcp-builders' ),
			self::schema(
				array(
					'id' => array( 'type' => 'string', 'description' => 'ID da classe global.' ),
				),
				array( 'id' )
			),
			array( __CLASS__, 'delete_global_class' )
		);

		$registry->register(
			'list_color_palette',
			__( 'Retorna a paleta de cores global do Bricks.', 'marreira-mcp-builders' ),
			self::schema( array() ),
			array( __CLASS__, 'list_color_palette' )
		);

		$registry->register(
			'get_theme_styles',
			__( 'Retorna os theme styles do Bricks.', 'marreira-mcp-builders' ),
			self::schema( array() ),
			array( __CLASS__, 'get_theme_styles' )
		);

		$registry->register(
			'list_fonts',
			__( 'Lista as fontes customizadas (CPT bricks_font).', 'marreira-mcp-builders' ),
			self::schema( array() ),
			array( __CLASS__, 'list_fonts' )
		);
	}

	/**
	 * Handler: list_global_classes.
	 *
	 * @param array $args Argumentos.
	 * @return array
	 */
	public static function list_global_classes( array $args ) {
		$cap = self::require_cap( 'edit_pages' );
		if ( $cap ) {
			return $cap;
		}
		return Tool_Registry::success_result( Global_Styles::get_global_classes() );
	}

	/**
	 * Handler: upsert_global_class.
	 *
	 * @param array $args Argumentos.
	 * @return array
	 */
	public static function upsert_global_class( array $args ) {
		$cap = self::require_cap( 'edit_theme_options' );
		if ( $cap ) {
			return $cap;
		}

		$settings = array();
		if ( isset( $args['settings'] ) ) {
			$settings = Sanitizer::prepare_settings( $args['settings'] );
			if ( is_wp_error( $settings ) ) {
				return self::from_error( $settings );
			}
		}

		$record = Global_Styles::upsert_global_class(
			array(
				'id'       => isset( $args['id'] ) ? (string) $args['id'] : '',
				'name'     => isset( $args['name'] ) ? (string) $args['name'] : '',
				'settings' => $settings,
				'category' => isset( $args['category'] ) ? (string) $args['category'] : '',
			)
		);
		if ( is_wp_error( $record ) ) {
			return self::from_error( $record );
		}

		Css_Regenerator::regenerate();

		return Tool_Registry::success_result( $record, __( 'Classe global salva.', 'marreira-mcp-builders' ) );
	}

	/**
	 * Handler: delete_global_class.
	 *
	 * @param array $args Argumentos.
	 * @return array
	 */
	public static function delete_global_class( array $args ) {
		$cap = self::require_cap( 'edit_theme_options' );
		if ( $cap ) {
			return $cap;
		}
		$res = Global_Styles::delete_global_class( isset( $args['id'] ) ? (string) $args['id'] : '' );
		if ( is_wp_error( $res ) ) {
			return self::from_error( $res );
		}
		Css_Regenerator::regenerate();
		return Tool_Registry::success_result( array( 'deleted' => (string) $args['id'] ), __( 'Classe global removida.', 'marreira-mcp-builders' ) );
	}

	/**
	 * Handler: list_color_palette.
	 *
	 * @param array $args Argumentos.
	 * @return array
	 */
	public static function list_color_palette( array $args ) {
		$cap = self::require_cap( 'edit_pages' );
		if ( $cap ) {
			return $cap;
		}
		return Tool_Registry::success_result( Global_Styles::get_color_palette() );
	}

	/**
	 * Handler: get_theme_styles.
	 *
	 * @param array $args Argumentos.
	 * @return array
	 */
	public static function get_theme_styles( array $args ) {
		$cap = self::require_cap( 'edit_pages' );
		if ( $cap ) {
			return $cap;
		}
		return Tool_Registry::success_result( Global_Styles::get_theme_styles() );
	}

	/**
	 * Handler: list_fonts.
	 *
	 * @param array $args Argumentos.
	 * @return array
	 */
	public static function list_fonts( array $args ) {
		$cap = self::require_cap( 'edit_pages' );
		if ( $cap ) {
			return $cap;
		}
		return Tool_Registry::success_result( Global_Styles::list_fonts() );
	}
}
