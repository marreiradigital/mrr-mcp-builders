<?php
/**
 * Global_Styles: classes globais, paletas de cor, theme styles e fontes.
 *
 * @package Marreira\MCP_Builders
 */

namespace Marreira\MCP_Builders\Builders\Bricks;

use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Le e escreve os recursos globais do Bricks armazenados em wp_options
 * (e o CPT de fontes). Toda escrita e read-modify-write.
 */
class Global_Styles {

	const OPT_GLOBAL_CLASSES = 'bricks_global_classes';
	const OPT_COLOR_PALETTE  = 'bricks_color_palette';
	const OPT_THEME_STYLES   = 'bricks_theme_styles';
	const FONT_CPT           = 'bricks_font';

	/**
	 * Lista as classes globais.
	 *
	 * @return array
	 */
	public static function get_global_classes() {
		$classes = get_option( self::OPT_GLOBAL_CLASSES, array() );
		return is_array( $classes ) ? $classes : array();
	}

	/**
	 * Cria ou atualiza uma classe global pelo id (ou pelo name se id ausente).
	 *
	 * @param array $class {
	 *     @type string $id       Id da classe (gerado se ausente).
	 *     @type string $name     Nome CSS da classe (obrigatorio).
	 *     @type array  $settings Settings no formato do Bricks.
	 *     @type string $category Categoria opcional.
	 * }
	 * @return array|WP_Error A classe upsertada.
	 */
	public static function upsert_global_class( array $class ) {
		if ( empty( $class['name'] ) || ! is_string( $class['name'] ) ) {
			return new WP_Error( 'mmb_class_name', __( 'A classe global precisa de "name".', 'marreira-mcp-builders' ), array( 'status' => 422 ) );
		}

		$class['name'] = sanitize_html_class( $class['name'] );
		$classes       = self::get_global_classes();

		$existing_ids = array();
		foreach ( $classes as $c ) {
			if ( isset( $c['id'] ) ) {
				$existing_ids[] = (string) $c['id'];
			}
		}

		// Resolve id: usa o informado, ou procura por name, ou gera novo.
		$id = isset( $class['id'] ) ? (string) $class['id'] : '';
		if ( '' === $id ) {
			foreach ( $classes as $c ) {
				if ( isset( $c['name'] ) && $c['name'] === $class['name'] ) {
					$id = (string) $c['id'];
					break;
				}
			}
		}
		if ( '' === $id ) {
			$id = Element_Tree::generate_id( $existing_ids );
		}

		$record = array(
			'id'       => $id,
			'name'     => $class['name'],
			'settings' => isset( $class['settings'] ) && is_array( $class['settings'] ) ? $class['settings'] : array(),
		);
		if ( ! empty( $class['category'] ) ) {
			$record['category'] = sanitize_text_field( $class['category'] );
		}

		// Read-modify-write: substitui se existir, senao acrescenta.
		$replaced = false;
		foreach ( $classes as &$c ) {
			if ( isset( $c['id'] ) && (string) $c['id'] === $id ) {
				// Preserva campos desconhecidos do registro existente.
				$c        = array_merge( $c, $record );
				$replaced = true;
				break;
			}
		}
		unset( $c );

		if ( ! $replaced ) {
			$classes[] = $record;
		}

		update_option( self::OPT_GLOBAL_CLASSES, $classes );

		return $record;
	}

	/**
	 * Remove uma classe global pelo id.
	 *
	 * @param string $id Id da classe.
	 * @return true|WP_Error
	 */
	public static function delete_global_class( $id ) {
		$id      = (string) $id;
		$classes = self::get_global_classes();
		$before  = count( $classes );

		$classes = array_values(
			array_filter(
				$classes,
				static function ( $c ) use ( $id ) {
					return ! ( isset( $c['id'] ) && (string) $c['id'] === $id );
				}
			)
		);

		if ( count( $classes ) === $before ) {
			return new WP_Error( 'mmb_class_missing', __( 'Classe global nao encontrada.', 'marreira-mcp-builders' ), array( 'status' => 404 ) );
		}

		update_option( self::OPT_GLOBAL_CLASSES, $classes );
		return true;
	}

	/**
	 * Lista a paleta de cores global.
	 *
	 * @return array
	 */
	public static function get_color_palette() {
		$palette = get_option( self::OPT_COLOR_PALETTE, array() );
		return is_array( $palette ) ? $palette : array();
	}

	/**
	 * Le os theme styles.
	 *
	 * @return array
	 */
	public static function get_theme_styles() {
		$styles = get_option( self::OPT_THEME_STYLES, array() );
		return is_array( $styles ) ? $styles : array();
	}

	/**
	 * Lista as fontes customizadas (CPT bricks_font).
	 *
	 * @return array
	 */
	public static function list_fonts() {
		if ( ! post_type_exists( self::FONT_CPT ) ) {
			return array();
		}
		$query = new \WP_Query(
			array(
				'post_type'      => self::FONT_CPT,
				'post_status'    => 'any',
				'posts_per_page' => 200,
				'fields'         => 'ids',
			)
		);
		$out = array();
		foreach ( $query->posts as $pid ) {
			$out[] = array(
				'id'   => (int) $pid,
				'name' => get_the_title( $pid ),
			);
		}
		return $out;
	}
}
