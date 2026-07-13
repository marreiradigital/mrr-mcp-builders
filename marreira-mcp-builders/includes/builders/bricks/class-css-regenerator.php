<?php
/**
 * Css_Regenerator: regenera o CSS do Bricks apos escritas programaticas.
 *
 * @package Marreira\MCP_Builders
 */

namespace Marreira\MCP_Builders\Builders\Bricks;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Detecta o modo de carregamento de CSS do Bricks e regenera os arquivos
 * quando necessario.
 *
 * - Modo "inline" (padrao): o CSS e recompilado a cada render a partir do
 *   postmeta/options, entao nenhuma acao e necessaria.
 * - Modo "external_files": e preciso regenerar os arquivos .css.
 */
class Css_Regenerator {

	/**
	 * Retorna o metodo de carregamento de CSS configurado no Bricks.
	 *
	 * @return string 'inline' ou 'external_files'.
	 */
	public static function loading_method() {
		$settings = get_option( 'bricks_global_settings', array() );
		if ( is_array( $settings ) && ! empty( $settings['cssLoadingMethod'] ) ) {
			return (string) $settings['cssLoadingMethod'];
		}
		return 'inline';
	}

	/**
	 * Indica se o modo atual exige regeneracao de arquivos.
	 *
	 * @return bool
	 */
	public static function needs_regeneration() {
		return 'external_files' === self::loading_method();
	}

	/**
	 * Regenera o CSS quando necessario.
	 *
	 * Em modo inline, retorna imediatamente. Em modo external_files, tenta os
	 * mecanismos nativos do Bricks na ordem de disponibilidade.
	 *
	 * @param int|null $post_id Post especifico a regenerar (quando aplicavel).
	 * @return array Resultado: { regenerated: bool, method: string }.
	 */
	public static function regenerate( $post_id = null ) {
		if ( ! self::needs_regeneration() ) {
			return array(
				'regenerated' => false,
				'method'      => 'inline',
				'note'        => 'CSS inline: recompilado automaticamente no proximo render.',
			);
		}

		$done   = false;
		$how    = 'none';

		// 1) Classe nativa de assets, se expuser metodo de geracao por post.
		if ( class_exists( '\Bricks\Assets' ) ) {
			if ( $post_id && method_exists( '\Bricks\Assets', 'generate_post_css_file' ) ) {
				\Bricks\Assets::generate_post_css_file( (int) $post_id );
				$done = true;
				$how  = 'Bricks\\Assets::generate_post_css_file';
			} elseif ( method_exists( '\Bricks\Assets', 'regenerate_css_files' ) ) {
				\Bricks\Assets::regenerate_css_files();
				$done = true;
				$how  = 'Bricks\\Assets::regenerate_css_files';
			}
		}

		// 2) Fallback: marca para regeneracao no proximo carregamento limpando
		//    o cache de assets do Bricks via action publica.
		if ( ! $done ) {
			/**
			 * Permite que integracoes disparem a regeneracao via WP-CLI/cron.
			 *
			 * @param int|null $post_id Post alvo.
			 */
			do_action( 'mmb_request_css_regeneration', $post_id );
			$how = 'deferred';
		}

		// Limpa caches de objeto apos a regeneracao.
		if ( function_exists( 'wp_cache_flush' ) ) {
			wp_cache_flush();
		}

		return array(
			'regenerated' => $done,
			'method'      => $how,
			'note'        => $done
				? 'Arquivos CSS regenerados.'
				: 'Regeneracao adiada: rode "wp bricks regenerate_assets" no servidor.',
		);
	}
}
