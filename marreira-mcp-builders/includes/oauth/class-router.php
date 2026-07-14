<?php
/**
 * Roteador OAuth: serve os endpoints na RAIZ do site (fora do /wp-json).
 *
 * @package Marreira\MCP_Builders
 */

namespace Marreira\MCP_Builders\OAuth;

use Marreira\MCP_Builders\Activator;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Intercepta no hook `init` os caminhos OAuth servidos na raiz do dominio:
 *
 *   GET  /.well-known/oauth-protected-resource   (RFC 9728)
 *   GET  /.well-known/oauth-authorization-server (RFC 8414)
 *   POST /marreira-mcp-oauth/register            (DCR, RFC 7591)
 *   GET|POST /marreira-mcp-oauth/authorize       (consentimento, fase 3)
 *   POST /marreira-mcp-oauth/token               (troca de token, fase 3)
 *
 * Fica FORA do /wp-json (nao usa o REST router). Para qualquer outra URL —
 * inclusive outros /.well-known (ex.: ACME/certbot) — o metodo nao faz nada e o
 * WordPress segue normalmente.
 */
class Router {

	/**
	 * Registra o hook.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'init', array( __CLASS__, 'maybe_handle' ), 1 );
	}

	/**
	 * Examina a URL atual e serve o endpoint OAuth correspondente (ou sai).
	 *
	 * @return void
	 */
	public static function maybe_handle() {
		if ( empty( $_SERVER['REQUEST_URI'] ) ) {
			return;
		}
		$uri  = wp_unslash( $_SERVER['REQUEST_URI'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$path = (string) wp_parse_url( $uri, PHP_URL_PATH );
		if ( '' === $path ) {
			return;
		}
		$path = '/' . ltrim( $path, '/' );
		if ( '/' !== $path ) {
			$path = untrailingslashit( $path );
		}

		$base        = self::oauth_base_path();
		$is_wk_pr    = self::matches_well_known( $path, '/.well-known/oauth-protected-resource' );
		$is_wk_as    = self::matches_well_known( $path, '/.well-known/oauth-authorization-server' );
		$is_register = ( $path === $base . '/register' );

		if ( ! $is_wk_pr && ! $is_wk_as && ! $is_register ) {
			return; // Nao e um caminho nosso: deixa o WordPress seguir.
		}

		// Conector OAuth desligado nas settings: comporta-se como 404 (nao serve).
		if ( ! self::oauth_enabled() ) {
			return;
		}

		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : 'GET';

		if ( 'OPTIONS' === $method ) {
			self::preflight();
		}

		if ( $is_wk_pr ) {
			self::emit_json( 200, Metadata::protected_resource() );
		}
		if ( $is_wk_as ) {
			self::emit_json( 200, Metadata::authorization_server() );
		}
		if ( $is_register ) {
			if ( 'POST' !== $method ) {
				self::emit_json(
					405,
					array(
						'error'             => 'invalid_request',
						'error_description' => 'Use POST.',
					)
				);
			}
			$result = Client_Manager::handle_register();
			self::emit_json( (int) $result['status'], (array) $result['body'] );
		}
	}

	/**
	 * Caminho base OAuth com o prefixo do home (suporta WordPress em subdiretorio).
	 *
	 * @return string
	 */
	private static function oauth_base_path() {
		$home_path = (string) wp_parse_url( home_url(), PHP_URL_PATH );
		$home_path = ( '' === $home_path || '/' === $home_path ) ? '' : untrailingslashit( '/' . ltrim( $home_path, '/' ) );
		return $home_path . MMCB_OAUTH_BASE_PATH;
	}

	/**
	 * Casa um caminho de well-known: exato ou com sufixo de resource path.
	 *
	 * @param string $path  Caminho da requisicao.
	 * @param string $canon Caminho canonico do well-known.
	 * @return bool
	 */
	private static function matches_well_known( $path, $canon ) {
		return $path === $canon || 0 === strpos( $path, $canon . '/' );
	}

	/**
	 * Indica se o conector OAuth esta habilitado nas settings.
	 *
	 * @return bool
	 */
	private static function oauth_enabled() {
		$settings = wp_parse_args( (array) get_option( Activator::SETTINGS_OPTION, array() ), Activator::default_settings() );
		return ! empty( $settings['enable_oauth'] );
	}

	/**
	 * Responde a um preflight CORS e encerra.
	 *
	 * @return void
	 */
	private static function preflight() {
		status_header( 204 );
		self::cors_headers();
		header( 'Access-Control-Max-Age: 600' );
		exit;
	}

	/**
	 * Emite cabecalhos CORS permissivos (endpoints publicos de discovery/registro).
	 *
	 * @return void
	 */
	private static function cors_headers() {
		header( 'Access-Control-Allow-Origin: *' );
		header( 'Access-Control-Allow-Methods: GET, POST, OPTIONS' );
		header( 'Access-Control-Allow-Headers: Content-Type, Authorization' );
	}

	/**
	 * Emite uma resposta JSON e encerra a requisicao.
	 *
	 * @param int   $status Codigo HTTP.
	 * @param array $body   Corpo.
	 * @return void
	 */
	public static function emit_json( $status, array $body ) {
		status_header( (int) $status );
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Cache-Control: no-store' );
		header( 'X-Robots-Tag: noindex' );
		self::cors_headers();
		echo wp_json_encode( $body ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		exit;
	}
}
