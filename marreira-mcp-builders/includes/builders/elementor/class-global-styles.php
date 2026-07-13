<?php
/**
 * Global_Styles: le e escreve os tokens de design globais do Elementor.
 *
 * Os tokens (cores e tipografia) ficam no kit ativo — um CPT `elementor_library`
 * do subtipo `kit`. A fonte de verdade e o postmeta `_elementor_page_settings`.
 * Toda escrita e um ciclo read-modify-write sobre esse meta.
 *
 * Estrutura do meta:
 *   system_colors    => [ [ '_id'=>string, 'title'=>string, 'color'=>'#hex' ], ... ]
 *   custom_colors    => [ [ '_id'=>string, 'title'=>string, 'color'=>'#hex' ], ... ]
 *   system_typography => [ [ '_id'=>string, 'title'=>string, 'typography_*'=>... ], ... ]
 *   custom_typography => [ [ '_id'=>string, 'title'=>string, 'typography_*'=>... ], ... ]
 *
 * @package Marreira\MCP_Builders
 */

namespace Marreira\MCP_Builders\Builders\Elementor;

use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Gerencia os design tokens (cores e tipografia) do kit ativo do Elementor.
 *
 * system_colors / system_typography sao os slots nativos (primary, secondary,
 * text, accent) — expostos apenas para leitura.
 * custom_colors / custom_typography sao os slots do usuario — leitura e escrita.
 */
class Global_Styles {

	/**
	 * Chave do postmeta que armazena as configuracoes do kit.
	 */
	const KIT_META_KEY = '_elementor_page_settings';

	/**
	 * Indica se o Elementor esta disponivel com kit ativo configurado.
	 *
	 * @return bool
	 */
	public static function available() {
		if ( ! class_exists( '\Elementor\Plugin' ) ) {
			return false;
		}
		if ( ! isset( \Elementor\Plugin::$instance->kits_manager ) ) {
			return false;
		}
		try {
			return self::get_active_kit_id() > 0;
		} catch ( \Throwable $e ) {
			return false;
		}
	}

	/**
	 * Retorna o ID do post do kit ativo.
	 *
	 * @return int
	 */
	public static function get_active_kit_id() {
		try {
			return (int) \Elementor\Plugin::$instance->kits_manager->get_active_id();
		} catch ( \Throwable $e ) {
			return 0;
		}
	}

	/**
	 * Retorna o array completo de settings do kit (postmeta `_elementor_page_settings`).
	 *
	 * Prefere a leitura direta do postmeta para round-trip seguro. Se o meta
	 * estiver vazio, tenta `$kit->get_settings()` como fallback.
	 *
	 * @return array
	 */
	public static function get_kit_settings() {
		$kit_id = self::get_active_kit_id();
		if ( $kit_id <= 0 ) {
			return array();
		}

		// Leitura direta do postmeta — mais segura para round-trip.
		$settings = get_post_meta( $kit_id, self::KIT_META_KEY, true );
		if ( is_array( $settings ) && ! empty( $settings ) ) {
			return $settings;
		}

		// Fallback: get_settings() do objeto kit.
		try {
			$kit = \Elementor\Plugin::$instance->kits_manager->get_active_kit();
			if ( $kit && method_exists( $kit, 'get_settings' ) ) {
				$s = $kit->get_settings();
				if ( is_array( $s ) && ! empty( $s ) ) {
					return $s;
				}
			}
		} catch ( \Throwable $e ) {
			// Retorna array vazio abaixo.
		}

		return array();
	}

	/**
	 * Lista as cores globais (system e custom).
	 *
	 * @return array {
	 *   @type array $system_colors Cores do sistema (primary/secondary/text/accent).
	 *   @type array $custom_colors Cores criadas pelo usuario.
	 * }
	 */
	public static function list_global_colors() {
		$settings = self::get_kit_settings();
		return array(
			'system_colors' => isset( $settings['system_colors'] ) && is_array( $settings['system_colors'] )
				? $settings['system_colors']
				: array(),
			'custom_colors' => isset( $settings['custom_colors'] ) && is_array( $settings['custom_colors'] )
				? $settings['custom_colors']
				: array(),
		);
	}

	/**
	 * Lista as tipografias globais (system e custom).
	 *
	 * @return array {
	 *   @type array $system_typography Tipografias do sistema.
	 *   @type array $custom_typography Tipografias criadas pelo usuario.
	 * }
	 */
	public static function list_global_fonts() {
		$settings = self::get_kit_settings();
		return array(
			'system_typography' => isset( $settings['system_typography'] ) && is_array( $settings['system_typography'] )
				? $settings['system_typography']
				: array(),
			'custom_typography' => isset( $settings['custom_typography'] ) && is_array( $settings['custom_typography'] )
				? $settings['custom_typography']
				: array(),
		);
	}

	/**
	 * Cria ou atualiza uma cor em `custom_colors`.
	 *
	 * Se `$id` for informado e existir, atualiza. Caso contrario, cria nova entrada.
	 * system_colors nao sao tocadas por este metodo.
	 *
	 * @param string $title Titulo da cor (ex.: "Cor da marca").
	 * @param string $color Valor hex ou rgb/rgba (ex.: "#ff5733" ou "rgba(255,87,51,1)").
	 * @param string $id    _id existente para atualizacao; vazio para criacao.
	 * @return array|WP_Error Entrada upsertada ou WP_Error em falha.
	 */
	public static function upsert_custom_color( $title, $color, $id = '' ) {
		$kit_id = self::get_active_kit_id();
		if ( $kit_id <= 0 ) {
			return new WP_Error( 'mme_no_kit', __( 'Nenhum kit ativo encontrado no Elementor.', 'marreira-mcp-builders' ), array( 'status' => 503 ) );
		}

		// Sanitizacao do titulo.
		$title = sanitize_text_field( $title );
		if ( '' === $title ) {
			return new WP_Error( 'mme_invalid_title', __( 'O título da cor não pode ser vazio.', 'marreira-mcp-builders' ), array( 'status' => 422 ) );
		}

		// Sanitizacao e validacao da cor.
		$color_clean = sanitize_hex_color( $color );
		if ( null === $color_clean || '' === $color_clean ) {
			// Aceita rgb/rgba ou hex sem # como alternativa ao sanitize_hex_color.
			if ( ! preg_match( '/^#?[0-9a-fA-F]{3,8}$/', $color )
				&& ! preg_match( '/^rgba?\(\s*\d+\s*,\s*\d+\s*,\s*\d+(\s*,\s*[\d.]+)?\s*\)$/i', $color )
			) {
				return new WP_Error(
					'mme_invalid_color',
					/* translators: %s: valor de cor informado */
					sprintf( __( 'Valor de cor inválido: "%s". Use formato hex (#rrggbb) ou rgb/rgba.', 'marreira-mcp-builders' ), esc_html( $color ) ),
					array( 'status' => 422 )
				);
			}
			$color_clean = $color; // Passa como esta (rgb/rgba valido).
		}

		$settings      = self::get_kit_settings();
		$custom_colors = isset( $settings['custom_colors'] ) && is_array( $settings['custom_colors'] )
			? $settings['custom_colors']
			: array();

		$id      = (string) $id;
		$updated = false;
		$entry   = array();

		if ( '' !== $id ) {
			// Tenta atualizar entrada existente pelo _id.
			foreach ( $custom_colors as &$item ) {
				if ( isset( $item['_id'] ) && (string) $item['_id'] === $id ) {
					$item['title'] = $title;
					$item['color'] = $color_clean;
					$entry         = $item;
					$updated       = true;
					break;
				}
			}
			unset( $item );
		}

		if ( ! $updated ) {
			// Cria nova entrada com _id unico de 7 chars hex lowercase.
			$new_id = self::generate_unique_id( $custom_colors );
			$entry  = array(
				'_id'   => $new_id,
				'title' => $title,
				'color' => $color_clean,
			);
			$custom_colors[] = $entry;
		}

		$settings['custom_colors'] = $custom_colors;
		update_post_meta( $kit_id, self::KIT_META_KEY, $settings );
		self::maybe_regenerate_kit( $kit_id );

		return $entry;
	}

	/**
	 * Remove uma cor de `custom_colors` pelo `_id`.
	 *
	 * @param string $id _id da cor a remover.
	 * @return array|WP_Error Array ['deleted' => $id] ou WP_Error 404 se nao encontrada.
	 */
	public static function delete_custom_color( $id ) {
		$id     = (string) $id;
		$kit_id = self::get_active_kit_id();
		if ( $kit_id <= 0 ) {
			return new WP_Error( 'mme_no_kit', __( 'Nenhum kit ativo encontrado no Elementor.', 'marreira-mcp-builders' ), array( 'status' => 503 ) );
		}

		$settings      = self::get_kit_settings();
		$custom_colors = isset( $settings['custom_colors'] ) && is_array( $settings['custom_colors'] )
			? $settings['custom_colors']
			: array();

		$before = count( $custom_colors );
		$custom_colors = array_values(
			array_filter(
				$custom_colors,
				static function ( $item ) use ( $id ) {
					return ! ( isset( $item['_id'] ) && (string) $item['_id'] === $id );
				}
			)
		);

		if ( count( $custom_colors ) === $before ) {
			return new WP_Error(
				'mme_color_not_found',
				/* translators: %s: _id da cor */
				sprintf( __( 'Cor com _id "%s" não encontrada em custom_colors.', 'marreira-mcp-builders' ), esc_html( $id ) ),
				array( 'status' => 404 )
			);
		}

		$settings['custom_colors'] = $custom_colors;
		update_post_meta( $kit_id, self::KIT_META_KEY, $settings );
		self::maybe_regenerate_kit( $kit_id );

		return array( 'deleted' => $id );
	}

	/**
	 * Cria ou atualiza uma tipografia em `custom_typography`.
	 *
	 * `$typography` e um array associativo de chaves `typography_*` (ex.:
	 * `typography_font_family`, `typography_font_size`, etc.). Chaves que nao
	 * comecem com `typography_` sao ignoradas. Valores devem ser scalares.
	 * `typography_typography` e forcado para `'custom'`.
	 *
	 * @param string $title      Titulo da tipografia (ex.: "Titulo Principal").
	 * @param array  $typography Array de chaves typography_*.
	 * @param string $id         _id existente para atualizacao; vazio para criacao.
	 * @return array|WP_Error Entrada upsertada ou WP_Error em falha.
	 */
	public static function upsert_custom_font( $title, array $typography, $id = '' ) {
		$kit_id = self::get_active_kit_id();
		if ( $kit_id <= 0 ) {
			return new WP_Error( 'mme_no_kit', __( 'Nenhum kit ativo encontrado no Elementor.', 'marreira-mcp-builders' ), array( 'status' => 503 ) );
		}

		$title = sanitize_text_field( $title );
		if ( '' === $title ) {
			return new WP_Error( 'mme_invalid_title', __( 'O título da tipografia não pode ser vazio.', 'marreira-mcp-builders' ), array( 'status' => 422 ) );
		}

		// Sanitizacao superficial: so aceita chaves typography_* com valores escalares.
		$safe_typography = array();
		foreach ( $typography as $key => $value ) {
			if ( 0 !== strpos( (string) $key, 'typography_' ) ) {
				continue; // Ignora chaves que nao comecam com typography_.
			}
			if ( ! is_scalar( $value ) ) {
				continue; // Ignora valores nao escalares (arrays, objetos).
			}
			$safe_typography[ sanitize_key( $key ) ] = $value;
		}
		// Forca o campo de controle de grupo de tipografia.
		$safe_typography['typography_typography'] = 'custom';

		$settings          = self::get_kit_settings();
		$custom_typography = isset( $settings['custom_typography'] ) && is_array( $settings['custom_typography'] )
			? $settings['custom_typography']
			: array();

		$id      = (string) $id;
		$updated = false;
		$entry   = array();

		if ( '' !== $id ) {
			// Tenta atualizar entrada existente pelo _id.
			foreach ( $custom_typography as &$item ) {
				if ( isset( $item['_id'] ) && (string) $item['_id'] === $id ) {
					$item['title'] = $title;
					// Mescla, preservando _id e title, atualizando os campos typography_*.
					foreach ( $safe_typography as $k => $v ) {
						$item[ $k ] = $v;
					}
					$entry   = $item;
					$updated = true;
					break;
				}
			}
			unset( $item );
		}

		if ( ! $updated ) {
			// Cria nova entrada.
			$new_id = self::generate_unique_id( $custom_typography );
			$entry  = array_merge(
				array(
					'_id'   => $new_id,
					'title' => $title,
				),
				$safe_typography
			);
			$custom_typography[] = $entry;
		}

		$settings['custom_typography'] = $custom_typography;
		update_post_meta( $kit_id, self::KIT_META_KEY, $settings );
		self::maybe_regenerate_kit( $kit_id );

		return $entry;
	}

	// -------------------------------------------------------------------------
	// Metodos privados de suporte
	// -------------------------------------------------------------------------

	/**
	 * Gera um _id unico de 7 chars hex lowercase, sem colisao com ids ja existentes.
	 *
	 * @param array $existing_items Array de entradas que contem o campo '_id'.
	 * @return string
	 */
	private static function generate_unique_id( array $existing_items ) {
		$existing_ids = array();
		foreach ( $existing_items as $item ) {
			if ( isset( $item['_id'] ) ) {
				$existing_ids[] = (string) $item['_id'];
			}
		}

		do {
			$new_id = substr( md5( uniqid( '', true ) ), 0, 7 );
		} while ( in_array( $new_id, $existing_ids, true ) );

		return $new_id;
	}

	/**
	 * Dispara a regeneracao de CSS do kit se o Css_Regenerator estiver disponivel.
	 *
	 * @param int $kit_id ID do post do kit.
	 * @return void
	 */
	private static function maybe_regenerate_kit( $kit_id ) {
		if ( class_exists( '\Marreira\MCP_Builders\Builders\Elementor\Css_Regenerator' ) ) {
			try {
				Css_Regenerator::regenerate_kit( $kit_id );
			} catch ( \Throwable $e ) {
				// Falha de CSS nao deve interromper a persistencia de dados.
			}
		}
	}
}
