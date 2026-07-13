<?php
/**
 * Code_Guard: recusa widgets/settings que executam codigo (anti-RCE).
 *
 * @package Marreira\MCP_Builders
 */

namespace Marreira\MCP_Builders\Builders\Elementor;

use Marreira\MCP_Builders\Auth\Rest_Guard;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Varre a arvore aninhada de elementos e as page settings em busca de vetores
 * de execucao de codigo arbitrario.
 *
 * Diferenca importante para o Bricks: no Elementor NAO existe etapa de
 * "assinatura". O widget "HTML" renderiza o conteudo cru no frontend e o
 * widget "Shortcode" executa shortcodes (que podem rodar PHP). Por isso:
 *
 *  - Bloqueio LIGADO (padrao): widgets `html`/`shortcode` e qualquer `<script>`
 *    sao recusados (403).
 *  - Bloqueio DESLIGADO: a IA pode criar `html`/`shortcode` — mas eles EXECUTAM
 *    de fato no frontend (sem etapa de aprovacao). O resultado da tool avisa.
 *
 * Injecao de `<script>`/`javascript:` em widgets comuns e em `custom_css` e
 * SEMPRE recusada, independentemente do toggle (nao ha protecao posterior).
 */
class Code_Guard {

	/**
	 * Tipos de widget que executam codigo (recusados quando o bloqueio esta ligado).
	 *
	 * @var string[]
	 */
	const FORBIDDEN_WIDGETS = array( 'html', 'shortcode' );

	/**
	 * Chaves de settings que carregam codigo/script bruto.
	 *
	 * @var string[]
	 */
	const CODE_SETTING_KEYS = array( 'html', 'shortcode' );

	/**
	 * Indica se o bloqueio de codigo esta ativo nas configuracoes.
	 *
	 * @return bool
	 */
	public static function is_blocking() {
		$settings = Rest_Guard::settings();
		return ! empty( $settings['block_code'] );
	}

	/**
	 * Inspeciona a arvore aninhada de elementos. Retorna WP_Error se houver
	 * violacao que deva barrar a gravacao.
	 *
	 * @param array $elements Arvore aninhada.
	 * @return true|WP_Error
	 */
	public static function inspect_elements( $elements ) {
		if ( ! is_array( $elements ) ) {
			return true;
		}

		$blocking = self::is_blocking();

		foreach ( $elements as $el ) {
			if ( ! is_array( $el ) ) {
				continue;
			}

			$widget_type = isset( $el['widgetType'] ) ? (string) $el['widgetType'] : '';
			$settings    = isset( $el['settings'] ) && is_array( $el['settings'] ) ? $el['settings'] : array();

			// Widget que executa codigo (html/shortcode).
			if ( in_array( $widget_type, self::FORBIDDEN_WIDGETS, true ) ) {
				if ( $blocking ) {
					return self::violation(
						sprintf(
							/* translators: %s: widget type */
							__( 'Widget de codigo recusado ("%s"): o bloqueio anti-RCE esta ligado. Desligue-o no painel para permitir (atencao: esse widget EXECUTA no frontend, sem etapa de aprovacao).', 'marreira-mcp-builders' ),
							$widget_type
						)
					);
				}
				// Permitido conscientemente: nao escaneia o proprio codigo do widget.
				continue;
			}

			// Demais widgets: injecao de script/codigo NUNCA e permitida.
			$check = self::inspect_settings( $settings );
			if ( is_wp_error( $check ) ) {
				return $check;
			}

			// Recursao nos filhos.
			if ( isset( $el['elements'] ) && is_array( $el['elements'] ) ) {
				$nested = self::inspect_elements( $el['elements'] );
				if ( is_wp_error( $nested ) ) {
					return $nested;
				}
			}
		}

		return true;
	}

	/**
	 * Indica se a arvore contem algum widget de codigo (para avisar o usuario).
	 *
	 * @param array $elements Arvore aninhada.
	 * @return bool
	 */
	public static function contains_code( $elements ) {
		if ( ! is_array( $elements ) ) {
			return false;
		}
		foreach ( $elements as $el ) {
			if ( ! is_array( $el ) ) {
				continue;
			}
			$widget_type = isset( $el['widgetType'] ) ? (string) $el['widgetType'] : '';
			if ( in_array( $widget_type, self::FORBIDDEN_WIDGETS, true ) ) {
				return true;
			}
			if ( isset( $el['elements'] ) && is_array( $el['elements'] ) && self::contains_code( $el['elements'] ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Aviso anexado ao resultado quando ha widget de codigo (bloqueio desligado).
	 *
	 * @return string
	 */
	public static function run_warning() {
		return __( 'ATENCAO: ha widget(s) de codigo (HTML/Shortcode) na pagina. No Elementor NAO ha etapa de assinatura: esses widgets EXECUTAM diretamente no frontend. Confira o conteudo antes de publicar.', 'marreira-mcp-builders' );
	}

	/**
	 * Inspeciona recursivamente um array de settings por codigo perigoso.
	 *
	 * @param array $settings Settings.
	 * @return true|WP_Error
	 */
	public static function inspect_settings( $settings ) {
		foreach ( $settings as $key => $value ) {
			// custom_css (Pro, por elemento): permite CSS, recusa script/expression.
			if ( 'custom_css' === $key && is_string( $value ) ) {
				if ( preg_match( '/<\s*\/?\s*(script|style)\b|javascript:|expression\s*\(/i', $value ) ) {
					return self::violation(
						__( 'CSS personalizado (custom_css) com conteudo perigoso recusado.', 'marreira-mcp-builders' )
					);
				}
				continue;
			}

			if ( is_string( $value ) ) {
				$check = self::inspect_string( $value );
				if ( is_wp_error( $check ) ) {
					return $check;
				}
			} elseif ( is_array( $value ) ) {
				$check = self::inspect_settings( $value );
				if ( is_wp_error( $check ) ) {
					return $check;
				}
			}
		}

		return true;
	}

	/**
	 * Inspeciona as page settings (document settings) por injecao de script.
	 *
	 * @param array $page_settings Page settings.
	 * @return true|WP_Error
	 */
	public static function inspect_page_settings( $page_settings ) {
		if ( ! is_array( $page_settings ) ) {
			return true;
		}

		// custom_css no nivel do documento (Pro).
		if ( ! empty( $page_settings['custom_css'] ) && is_string( $page_settings['custom_css'] ) ) {
			if ( preg_match( '/<\s*\/?\s*(script|style)\b|javascript:|expression\s*\(/i', $page_settings['custom_css'] ) ) {
				return self::violation(
					__( 'CSS personalizado do documento com conteudo perigoso recusado.', 'marreira-mcp-builders' )
				);
			}
		}

		// Varre o restante por <script>.
		return self::inspect_settings(
			array_diff_key( $page_settings, array( 'custom_css' => true ) )
		);
	}

	/**
	 * Inspeciona uma string por tags de script.
	 *
	 * @param string $value String.
	 * @return true|WP_Error
	 */
	private static function inspect_string( $value ) {
		if ( preg_match( '/<\s*script\b/i', $value ) ) {
			return self::violation(
				__( 'Tag <script> em conteudo recusada.', 'marreira-mcp-builders' )
			);
		}
		return true;
	}

	/**
	 * Constroi o WP_Error de violacao.
	 *
	 * @param string $message Mensagem.
	 * @return WP_Error
	 */
	private static function violation( $message ) {
		return new WP_Error( 'mme_code_blocked', $message, array( 'status' => 403 ) );
	}
}
