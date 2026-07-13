<?php
/**
 * Style_Tools: cores e fontes globais do Kit do Elementor.
 *
 * @package Marreira\MCP_Builders
 */

namespace Marreira\MCP_Builders\Builders\Elementor\Tools;

use Marreira\MCP_Builders\MCP\Tool_Registry;
use Marreira\MCP_Builders\Builders\Elementor\Global_Styles;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Leitura e escrita dos tokens globais do site (Kit ativo): cores globais,
 * fontes globais e settings do Kit.
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
			'list_global_colors',
			__( 'Lista as cores globais do site (system_colors + custom_colors do Kit ativo).', 'marreira-mcp-builders' ),
			self::schema( array() ),
			array( __CLASS__, 'list_global_colors' )
		);

		$registry->register(
			'list_global_fonts',
			__( 'Lista as fontes/tipografias globais do site (system_typography + custom_typography do Kit ativo).', 'marreira-mcp-builders' ),
			self::schema( array() ),
			array( __CLASS__, 'list_global_fonts' )
		);

		$registry->register(
			'get_kit_settings',
			__( 'Retorna as settings do Kit ativo (design system: cores, tipografia, layout, breakpoints).', 'marreira-mcp-builders' ),
			self::schema( array() ),
			array( __CLASS__, 'get_kit_settings' )
		);

		$registry->register(
			'upsert_global_color',
			__( 'Cria ou atualiza uma cor global customizada no Kit (referenciavel via __globals__: globals/colors?id=<id>).', 'marreira-mcp-builders' ),
			self::schema(
				array(
					'title' => array(
						'type'        => 'string',
						'description' => 'Nome da cor (ex.: "Marca Primaria").',
					),
					'color' => array(
						'type'        => 'string',
						'description' => 'Valor da cor (#hex, rgb() ou rgba()).',
					),
					'id'    => array(
						'type'        => 'string',
						'description' => 'ID da cor para atualizar uma existente. Omita para criar.',
					),
				),
				array( 'title', 'color' )
			),
			array( __CLASS__, 'upsert_global_color' )
		);

		$registry->register(
			'delete_global_color',
			__( 'Remove uma cor global customizada do Kit pelo id.', 'marreira-mcp-builders' ),
			self::schema(
				array(
					'id' => array(
						'type'        => 'string',
						'description' => 'ID da cor customizada.',
					),
				),
				array( 'id' )
			),
			array( __CLASS__, 'delete_global_color' )
		);

		$registry->register(
			'upsert_global_font',
			__( 'Cria ou atualiza uma tipografia global customizada no Kit (chaves typography_*).', 'marreira-mcp-builders' ),
			self::schema(
				array(
					'title'      => array(
						'type'        => 'string',
						'description' => 'Nome da tipografia (ex.: "Titulos").',
					),
					'typography' => array(
						'type'        => 'object',
						'description' => 'Objeto com chaves typography_* (typography_font_family, typography_font_weight, typography_font_size, ...).',
					),
					'id'         => array(
						'type'        => 'string',
						'description' => 'ID da tipografia para atualizar uma existente. Omita para criar.',
					),
				),
				array( 'title', 'typography' )
			),
			array( __CLASS__, 'upsert_global_font' )
		);
	}

	/**
	 * Garante Kit disponivel.
	 *
	 * @return array|null
	 */
	private static function require_kit() {
		$elementor = self::require_elementor();
		if ( $elementor ) {
			return $elementor;
		}
		if ( ! Global_Styles::available() ) {
			return Tool_Registry::error_result( __( 'O Kit ativo do Elementor nao esta disponivel.', 'marreira-mcp-builders' ) );
		}
		return null;
	}

	/**
	 * Handler: list_global_colors.
	 *
	 * @param array $args Argumentos.
	 * @return array
	 */
	public static function list_global_colors( array $args ) {
		$cap = self::require_cap( 'edit_pages' );
		if ( $cap ) {
			return $cap;
		}
		$kit = self::require_kit();
		if ( $kit ) {
			return $kit;
		}
		return Tool_Registry::success_result( Global_Styles::list_global_colors() );
	}

	/**
	 * Handler: list_global_fonts.
	 *
	 * @param array $args Argumentos.
	 * @return array
	 */
	public static function list_global_fonts( array $args ) {
		$cap = self::require_cap( 'edit_pages' );
		if ( $cap ) {
			return $cap;
		}
		$kit = self::require_kit();
		if ( $kit ) {
			return $kit;
		}
		return Tool_Registry::success_result( Global_Styles::list_global_fonts() );
	}

	/**
	 * Handler: get_kit_settings.
	 *
	 * @param array $args Argumentos.
	 * @return array
	 */
	public static function get_kit_settings( array $args ) {
		$cap = self::require_cap( 'edit_pages' );
		if ( $cap ) {
			return $cap;
		}
		$kit = self::require_kit();
		if ( $kit ) {
			return $kit;
		}
		return Tool_Registry::success_result(
			array(
				'kit_id'   => Global_Styles::get_active_kit_id(),
				'settings' => Global_Styles::get_kit_settings(),
			)
		);
	}

	/**
	 * Handler: upsert_global_color.
	 *
	 * @param array $args Argumentos.
	 * @return array
	 */
	public static function upsert_global_color( array $args ) {
		$cap = self::require_cap( 'edit_theme_options' );
		if ( $cap ) {
			return $cap;
		}
		$kit = self::require_kit();
		if ( $kit ) {
			return $kit;
		}

		$res = Global_Styles::upsert_custom_color(
			isset( $args['title'] ) ? (string) $args['title'] : '',
			isset( $args['color'] ) ? (string) $args['color'] : '',
			isset( $args['id'] ) ? (string) $args['id'] : ''
		);
		if ( is_wp_error( $res ) ) {
			return self::from_error( $res );
		}

		return Tool_Registry::success_result( $res, __( 'Cor global salva.', 'marreira-mcp-builders' ) );
	}

	/**
	 * Handler: delete_global_color.
	 *
	 * @param array $args Argumentos.
	 * @return array
	 */
	public static function delete_global_color( array $args ) {
		$cap = self::require_cap( 'edit_theme_options' );
		if ( $cap ) {
			return $cap;
		}
		$kit = self::require_kit();
		if ( $kit ) {
			return $kit;
		}

		$res = Global_Styles::delete_custom_color( isset( $args['id'] ) ? (string) $args['id'] : '' );
		if ( is_wp_error( $res ) ) {
			return self::from_error( $res );
		}

		return Tool_Registry::success_result( $res, __( 'Cor global removida.', 'marreira-mcp-builders' ) );
	}

	/**
	 * Handler: upsert_global_font.
	 *
	 * @param array $args Argumentos.
	 * @return array
	 */
	public static function upsert_global_font( array $args ) {
		$cap = self::require_cap( 'edit_theme_options' );
		if ( $cap ) {
			return $cap;
		}
		$kit = self::require_kit();
		if ( $kit ) {
			return $kit;
		}

		$typography = isset( $args['typography'] ) && is_array( $args['typography'] ) ? $args['typography'] : array();

		$res = Global_Styles::upsert_custom_font(
			isset( $args['title'] ) ? (string) $args['title'] : '',
			$typography,
			isset( $args['id'] ) ? (string) $args['id'] : ''
		);
		if ( is_wp_error( $res ) ) {
			return self::from_error( $res );
		}

		return Tool_Registry::success_result( $res, __( 'Tipografia global salva.', 'marreira-mcp-builders' ) );
	}
}
