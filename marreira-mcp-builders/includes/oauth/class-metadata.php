<?php
/**
 * Documentos de discovery OAuth (RFC 9728 e RFC 8414).
 *
 * @package Marreira\MCP_Builders
 */

namespace Marreira\MCP_Builders\OAuth;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Monta os metadados que o cliente MCP (Claude.ai / ChatGPT) le para descobrir
 * o Authorization Server e iniciar o fluxo OAuth. Todas as URLs sao computadas
 * em runtime (home_url / rest_url) — nunca hardcode de dominio.
 */
class Metadata {

	/**
	 * Escopos anunciados no discovery (mapeiam para as abilities dos tokens).
	 * Anunciar nao concede: a emissao do token aplica a trava dupla (settings +
	 * consentimento) para os escopos perigosos.
	 *
	 * @return string[]
	 */
	public static function scopes_supported() {
		return array(
			'builder', 'read', 'content',
			'plugins', 'themes', 'core', 'files', 'snippets',
			'db', 'db_query', 'exec', 'cli',
		);
	}

	/**
	 * Emissor (issuer) = raiz do site, para o metadata do Authorization Server
	 * ficar em /.well-known/oauth-authorization-server sem sufixo de path.
	 *
	 * @return string
	 */
	public static function issuer() {
		return untrailingslashit( home_url() );
	}

	/**
	 * Protected Resource Metadata (RFC 9728). `resource` e a URL canonica do
	 * endpoint MCP protegido; `authorization_servers` aponta para o issuer.
	 *
	 * @return array
	 */
	public static function protected_resource() {
		return array(
			'resource'                 => esc_url_raw( rest_url( MMCB_REST_NAMESPACE . MMCB_REST_ROUTE ) ),
			'authorization_servers'    => array( self::issuer() ),
			'bearer_methods_supported' => array( 'header' ),
			'scopes_supported'         => self::scopes_supported(),
			'resource_documentation'   => esc_url_raw( rest_url( MMCB_REST_NAMESPACE . '/skill' ) ),
		);
	}

	/**
	 * Authorization Server Metadata (RFC 8414). Aponta os endpoints authorize/
	 * token/register servidos na raiz do site (fora do /wp-json).
	 *
	 * @return array
	 */
	public static function authorization_server() {
		$issuer = self::issuer();
		return array(
			'issuer'                                => $issuer,
			'authorization_endpoint'                => esc_url_raw( home_url( MMCB_OAUTH_BASE_PATH . '/authorize' ) ),
			'token_endpoint'                        => esc_url_raw( home_url( MMCB_OAUTH_BASE_PATH . '/token' ) ),
			'registration_endpoint'                 => esc_url_raw( home_url( MMCB_OAUTH_BASE_PATH . '/register' ) ),
			'response_types_supported'              => array( 'code' ),
			'grant_types_supported'                 => array( 'authorization_code', 'refresh_token' ),
			'code_challenge_methods_supported'      => array( 'S256' ),
			'token_endpoint_auth_methods_supported' => array( 'none' ),
			'scopes_supported'                      => self::scopes_supported(),
			'service_documentation'                 => esc_url_raw( rest_url( MMCB_REST_NAMESPACE . '/skill' ) ),
		);
	}
}
