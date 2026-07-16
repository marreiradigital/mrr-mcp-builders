<?php
/**
 * Code_Guard: recusa elementos/settings que executam codigo (anti-RCE).
 *
 * @package Marreira\MCP_Builders
 */

namespace Marreira\MCP_Builders\Builders\Bricks;

use Marreira\MCP_Builders\Auth\Rest_Guard;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Varre arvores de elementos e page settings em busca de vetores de execucao
 * de codigo arbitrario. Quando o bloqueio esta ativo (padrao), qualquer
 * ocorrencia faz a operacao falhar com erro 403.
 *
 * Vetores cobertos:
 *  - Elemento "code" (PHP/JS/HTML executavel) e "svg" com codigo embutido.
 *  - Settings com codigo: executeCode, code, javascriptCode, phpCode, signature.
 *  - Page settings com scripts (customScripts*) ou customCss perigoso.
 *  - Tags dinamicas perigosas: {echo:...} e {do_action:...} em strings.
 */
class Code_Guard {

	/**
	 * Nomes de elementos proibidos quando o bloqueio esta ativo.
	 *
	 * @var string[]
	 */
	const FORBIDDEN_ELEMENTS = array( 'code' );

	/**
	 * Chaves de settings que indicam codigo executavel.
	 *
	 * @var string[]
	 */
	const FORBIDDEN_SETTING_KEYS = array(
		'executeCode',
		'code',
		'javascriptCode',
		'phpCode',
		'signature',
	);

	/**
	 * Chaves de page settings proibidas (injecao de script).
	 *
	 * @var string[]
	 */
	const FORBIDDEN_PAGE_SETTING_KEYS = array(
		'customScriptsHeader',
		'customScriptsBodyHeader',
		'customScriptsBodyFooter',
	);

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
	 * Inspeciona uma arvore de elementos. Retorna WP_Error se houver violacao.
	 *
	 * @param array $elements Arvore plana de elementos.
	 * @return true|WP_Error
	 */
	public static function inspect_elements( $elements ) {
		if ( ! is_array( $elements ) ) {
			return true;
		}

		$blocking = self::is_blocking();

		foreach ( $elements as $element ) {
			if ( ! is_array( $element ) ) {
				continue;
			}

			$name     = isset( $element['name'] ) ? (string) $element['name'] : '';
			$settings = isset( $element['settings'] ) && is_array( $element['settings'] ) ? $element['settings'] : array();

			// Elemento de codigo (ou SVG com codigo). Quando o anti-RCE esta
			// LIGADO, e recusado. Quando DESLIGADO, e PERMITIDO porem inerte: a
			// IA nunca consegue assinar (a `signature` e removida pelo Sanitizer),
			// e o Bricks nao executa codigo nao-assinado. O usuario precisa abrir
			// no editor e assinar ("Sign code") para que rode.
			if ( in_array( $name, self::FORBIDDEN_ELEMENTS, true ) || ( 'svg' === $name && isset( $settings['code'] ) ) ) {
				if ( $blocking ) {
					return self::violation(
						__( 'Elemento de codigo recusado: o bloqueio anti-RCE esta ligado. Desligue-o no painel para permitir criar codigo (que entra SEM assinatura, exigindo assinatura manual no editor do Bricks).', 'marreira-mcp-builders' )
					);
				}
				// Permitido e inerte: nao escaneia o conteudo do proprio codigo.
				continue;
			}

			// Demais elementos: injecao de codigo NUNCA e permitida (nao ha etapa
			// de assinatura que os proteja), independente do toggle anti-RCE.
			$check = self::inspect_settings( $settings );
			if ( is_wp_error( $check ) ) {
				return $check;
			}
		}

		return true;
	}

	/**
	 * Indica se a arvore contem algum elemento de codigo (para avisar o usuario).
	 *
	 * @param array $elements Arvore plana.
	 * @return bool
	 */
	public static function contains_code( $elements ) {
		if ( ! is_array( $elements ) ) {
			return false;
		}
		foreach ( $elements as $element ) {
			if ( ! is_array( $element ) ) {
				continue;
			}
			$name = isset( $element['name'] ) ? (string) $element['name'] : '';
			if ( 'code' === $name ) {
				return true;
			}
			if ( 'svg' === $name && isset( $element['settings']['code'] ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Aviso a ser anexado ao resultado quando ha elemento de codigo.
	 *
	 * @return string
	 */
	public static function sign_warning() {
		return __( 'ATENCAO: ha elemento(s) de codigo inseridos SEM assinatura. Eles NAO sao executados ate que voce os abra no editor do Bricks e clique em "Sign code" (Codigo > Assinar). Sem assinatura, o Bricks exibe apenas um placeholder.', 'marreira-mcp-builders' );
	}

	/**
	 * Inspeciona um array de settings (recursivamente) por chaves/strings perigosas.
	 *
	 * @param array $settings Settings.
	 * @return true|WP_Error
	 */
	public static function inspect_settings( $settings ) {
		foreach ( $settings as $key => $value ) {
			if ( in_array( $key, self::FORBIDDEN_SETTING_KEYS, true ) ) {
				return self::violation(
					sprintf(
						/* translators: %s: setting key */
						__( 'Setting de codigo recusada: "%s".', 'marreira-mcp-builders' ),
						$key
					)
				);
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
	 * Inspeciona page settings.
	 *
	 * Ao contrario do elemento "code", NAO depende do toggle anti-RCE: os
	 * customScripts* nao tem etapa de assinatura que os segure. O elemento "code"
	 * pode ser liberado com o toggle porque entra inerte (sem `signature`, o
	 * Bricks nao executa ate o humano assinar no editor); um customScriptsHeader
	 * e injetado no <head> e executa no primeiro carregamento da pagina. Com o
	 * early-return no toggle, desligar o anti-RCE pra permitir elemento de codigo
	 * abria, de brinde, injecao de <script> direto no site. Mesmo principio que o
	 * inspect_elements() ja aplica aos elementos que nao sao "code".
	 *
	 * @param array $page_settings Page settings.
	 * @return true|WP_Error
	 */
	public static function inspect_page_settings( $page_settings ) {
		if ( ! is_array( $page_settings ) ) {
			return true;
		}

		foreach ( self::FORBIDDEN_PAGE_SETTING_KEYS as $key ) {
			if ( ! empty( $page_settings[ $key ] ) ) {
				return self::violation(
					sprintf(
						/* translators: %s: page setting key */
						__( 'Injecao de script em page settings recusada: "%s".', 'marreira-mcp-builders' ),
						$key
					)
				);
			}
		}

		// customCss com tags de codigo/expressao perigosa.
		if ( ! empty( $page_settings['customCss'] ) && is_string( $page_settings['customCss'] ) ) {
			if ( preg_match( '/<\s*script|javascript:|expression\s*\(/i', $page_settings['customCss'] ) ) {
				return self::violation(
					__( 'CSS personalizado com conteudo perigoso recusado.', 'marreira-mcp-builders' )
				);
			}
		}

		return true;
	}

	/**
	 * Inspeciona uma string por tags dinamicas perigosas.
	 *
	 * @param string $value String.
	 * @return true|WP_Error
	 */
	private static function inspect_string( $value ) {
		if ( preg_match( '/\{\s*(echo|do_action)\s*:/i', $value ) ) {
			return self::violation(
				__( 'Tag dinamica que executa codigo recusada ({echo:} ou {do_action:}).', 'marreira-mcp-builders' )
			);
		}
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
		return new WP_Error( 'mmb_code_blocked', $message, array( 'status' => 403 ) );
	}
}
