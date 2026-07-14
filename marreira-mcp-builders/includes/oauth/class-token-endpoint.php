<?php
/**
 * Endpoint /token: troca authorization_code -> token e refresh_token -> token.
 *
 * @package Marreira\MCP_Builders
 */

namespace Marreira\MCP_Builders\OAuth;

use Marreira\MCP_Builders\Activator;
use Marreira\MCP_Builders\Auth\Token_Manager;
use Marreira\MCP_Builders\Security\Audit_Log;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Emite access tokens OAuth. Endpoint publico (client publico + PKCE, sem
 * client_secret). Toda validacao acontece internamente. O corpo pode vir como
 * application/x-www-form-urlencoded (padrao OAuth) ou JSON.
 */
class Token_Endpoint {

	/**
	 * Trata POST /marreira-mcp-oauth/token.
	 *
	 * @return array{status:int,body:array}
	 */
	public static function handle() {
		$params     = self::read_params();
		$grant_type = isset( $params['grant_type'] ) ? (string) $params['grant_type'] : '';

		if ( 'authorization_code' === $grant_type ) {
			return self::grant_authorization_code( $params );
		}
		if ( 'refresh_token' === $grant_type ) {
			return self::grant_refresh_token( $params );
		}

		return self::error( 'unsupported_grant_type', 'grant_type não suportado.' );
	}

	/**
	 * Grant authorization_code (com verificacao PKCE S256).
	 *
	 * @param array $params Parametros do corpo.
	 * @return array{status:int,body:array}
	 */
	private static function grant_authorization_code( array $params ) {
		$code          = isset( $params['code'] ) ? (string) $params['code'] : '';
		$redirect_uri  = isset( $params['redirect_uri'] ) ? (string) $params['redirect_uri'] : '';
		$client_id     = isset( $params['client_id'] ) ? (string) $params['client_id'] : '';
		$code_verifier = isset( $params['code_verifier'] ) ? (string) $params['code_verifier'] : '';

		if ( '' === $code || '' === $client_id || '' === $code_verifier || '' === $redirect_uri ) {
			return self::error( 'invalid_request', 'Parâmetros obrigatórios ausentes (code, client_id, redirect_uri, code_verifier).' );
		}

		// consume() valida client/redirect/expiracao E PKCE antes de marcar o
		// code como usado (nao queima o code se o PKCE falhar).
		$row = Code_Manager::consume( $code, $client_id, $redirect_uri, $code_verifier );
		if ( is_wp_error( $row ) ) {
			return self::error( 'invalid_grant', $row->get_error_message() );
		}

		$settings  = wp_parse_args( (array) get_option( Activator::SETTINGS_OPTION, array() ), Activator::default_settings() );
		$abilities = Scopes::to_abilities( Scopes::parse( (string) $row['scope'] ), $settings );

		$pair = Token_Manager::generate_oauth( $abilities, (int) $row['user_id'], $client_id );

		self::audit( 'oauth:token_issued', $client_id, 'grant=authorization_code escopos=' . implode( ',', $abilities ) );

		return self::token_response( $pair, $abilities );
	}

	/**
	 * Grant refresh_token (rotacao).
	 *
	 * @param array $params Parametros do corpo.
	 * @return array{status:int,body:array}
	 */
	private static function grant_refresh_token( array $params ) {
		$refresh   = isset( $params['refresh_token'] ) ? (string) $params['refresh_token'] : '';
		$client_id = isset( $params['client_id'] ) ? (string) $params['client_id'] : '';

		if ( '' === $refresh || '' === $client_id ) {
			return self::error( 'invalid_request', 'Parâmetros obrigatórios ausentes (refresh_token, client_id).' );
		}

		$pair = Token_Manager::rotate_refresh( $refresh, $client_id );
		if ( is_wp_error( $pair ) ) {
			return self::error( 'invalid_grant', $pair->get_error_message() );
		}

		self::audit( 'oauth:token_refreshed', $client_id, 'grant=refresh_token' );

		return self::token_response( $pair, isset( $pair['abilities'] ) ? $pair['abilities'] : array() );
	}

	/**
	 * Monta a resposta de sucesso do token.
	 *
	 * @param array    $pair      Retorno de generate_oauth/rotate_refresh.
	 * @param string[] $abilities Abilities concedidas (viram o campo scope).
	 * @return array{status:int,body:array}
	 */
	private static function token_response( array $pair, array $abilities ) {
		return array(
			'status' => 200,
			'body'   => array(
				'access_token'  => $pair['access_token'],
				'token_type'    => 'bearer',
				'expires_in'    => (int) $pair['expires_in'],
				'refresh_token' => $pair['refresh_token'],
				'scope'         => implode( ' ', $abilities ),
			),
		);
	}

	/**
	 * Le os parametros do corpo (form-urlencoded via $_POST ou JSON).
	 *
	 * @return array
	 */
	private static function read_params() {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- endpoint OAuth publico; a seguranca vem do code+PKCE, nao de nonce.
		if ( ! empty( $_POST ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing
			return array_map( 'sanitize_text_field', wp_unslash( $_POST ) );
		}
		$raw  = file_get_contents( 'php://input' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$data = json_decode( (string) $raw, true );
		return is_array( $data ) ? $data : array();
	}

	/**
	 * Registra um evento no audit log (sem tokens/segredos no payload).
	 *
	 * @param string $action    Acao.
	 * @param string $client_id Client.
	 * @param string $summary   Resumo curto.
	 * @return void
	 */
	private static function audit( $action, $client_id, $summary ) {
		Audit_Log::log(
			array(
				'method'           => 'POST',
				'route'            => MMCB_OAUTH_BASE_PATH . '/token',
				'action'           => $action,
				'status_code'      => 200,
				'ip'               => Audit_Log::client_ip(),
				'response_summary' => $client_id . ': ' . $summary,
				'success'          => true,
			)
		);
	}

	/**
	 * Resposta de erro OAuth (RFC 6749 §5.2).
	 *
	 * @param string $error Codigo do erro.
	 * @param string $desc  Descricao.
	 * @return array{status:int,body:array}
	 */
	private static function error( $error, $desc ) {
		$status = 'invalid_client' === $error ? 401 : 400;
		return array(
			'status' => $status,
			'body'   => array(
				'error'             => $error,
				'error_description' => $desc,
			),
		);
	}
}
