<?php
/**
 * Css_Regenerator: regenera os arquivos CSS do Elementor apos escritas programaticas.
 *
 * Usa a classe nativa `\Elementor\Core\Files\CSS\Post` (que serve tanto para
 * posts comuns quanto para o kit) e o `files_manager` para limpeza global.
 * Nunca lanca excecao; retorna bool para facilitar o consumo pelo chamador.
 *
 * @package Marreira\MCP_Builders
 */

namespace Marreira\MCP_Builders\Builders\Elementor;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Regenera ou limpa o cache de CSS do Elementor.
 *
 * Metodos principais:
 *  - regenerate( int $post_id )  — regenera o CSS de um post especifico.
 *  - clear_all()                 — limpa todo o cache via files_manager.
 *  - regenerate_kit( int $id )   — alias semantico para regenerate() usado em kit.
 */
class Css_Regenerator {

	/**
	 * Indica se o Elementor esta disponivel.
	 *
	 * @return bool
	 */
	public static function available() {
		return class_exists( '\Elementor\Plugin' );
	}

	/**
	 * Regenera o CSS de um post (ou limpa tudo se $post_id for 0).
	 *
	 * Caminho preferencial: \Elementor\Core\Files\CSS\Post::create() + update().
	 * O mesmo mecanismo funciona para o kit, pois o kit e um post CPT.
	 *
	 * @param int $post_id ID do post. 0 para limpar todo o cache.
	 * @return bool True em sucesso, false em falha.
	 */
	public static function regenerate( $post_id = 0 ) {
		$post_id = (int) $post_id;

		if ( $post_id <= 0 ) {
			return self::clear_all();
		}

		// Caminho nativo: classe CSS\Post do Elementor.
		if ( class_exists( '\Elementor\Core\Files\CSS\Post' ) ) {
			try {
				$css_file = \Elementor\Core\Files\CSS\Post::create( $post_id );
				$css_file->update();
				return true;
			} catch ( \Throwable $e ) {
				// Falha silenciosa: tenta fallback abaixo.
			}
		}

		// Fallback: limpa o cache geral para forcar recompilacao no proximo acesso.
		return self::clear_all();
	}

	/**
	 * Limpa todo o cache de CSS via files_manager do Elementor.
	 *
	 * Equivalente ao "Regenerate CSS" do painel — todos os arquivos .css gerados
	 * serao recriados no proximo acesso a cada pagina.
	 *
	 * @return bool True em sucesso, false se files_manager nao estiver disponivel.
	 */
	public static function clear_all() {
		if ( ! self::available() ) {
			return false;
		}

		try {
			if ( isset( \Elementor\Plugin::$instance->files_manager ) ) {
				\Elementor\Plugin::$instance->files_manager->clear_cache();
				return true;
			}
		} catch ( \Throwable $e ) {
			// Falha silenciosa.
		}

		return false;
	}

	/**
	 * Regenera o CSS do kit ativo (alias semantico de regenerate()).
	 *
	 * O kit e armazenado como um post CPT `elementor_library`, portanto
	 * o mesmo fluxo de CSS\Post se aplica.
	 *
	 * @param int $kit_id ID do post do kit.
	 * @return bool
	 */
	public static function regenerate_kit( $kit_id ) {
		return self::regenerate( (int) $kit_id );
	}
}
