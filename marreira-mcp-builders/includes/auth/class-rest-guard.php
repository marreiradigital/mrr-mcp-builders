<?php
/**
 * Guarda de seguranca unificada dos endpoints MCP e CLI.
 *
 * @package Marreira\MCP_Builders
 */

namespace Marreira\MCP_Builders\Auth;

use Marreira\MCP_Builders\Activator;
use Marreira\MCP_Builders\Security\Audit_Log;
use WP_Error;
use WP_REST_Request;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Centraliza autenticacao, throttle, allowlist e escopos (abilities).
 *
 * Pipeline (portado/endurecido do MRR_WP_CLI_Auth):
 *   HTTPS -> throttle por IP -> extrai Bearer -> valida formato -> lookup por
 *   hash -> status/expiracao -> IP allowlist -> revalida admin dono -> assume
 *   o dono como usuario atual -> rate limit por token.
 */
class Rest_Guard {

	/**
	 * Token autenticado na requisicao atual.
	 *
	 * @var array|null
	 */
	private static $current_token = null;

	/**
	 * permission_callback base do endpoint MCP: exige token valido (qualquer
	 * ability). A checagem fina por ability acontece por tool/rota.
	 *
	 * @param WP_REST_Request $request Requisicao.
	 * @return true|WP_Error
	 */
	public static function check( WP_REST_Request $request ) {
		$auth = self::authenticate( $request );
		if ( is_wp_error( $auth ) ) {
			return $auth;
		}
		return true;
	}

	/**
	 * Fabrica um permission_callback que exige uma ability especifica.
	 * Usado pelas rotas /cli/*.
	 *
	 * @param string $ability Ability requerida.
	 * @return callable
	 */
	public static function ability_gate( $ability ) {
		return static function ( WP_REST_Request $request ) use ( $ability ) {
			$auth = self::authenticate( $request );
			if ( is_wp_error( $auth ) ) {
				return $auth;
			}
			return self::require_ability( $ability );
		};
	}

	/**
	 * Executa o pipeline completo de autenticacao.
	 *
	 * @param WP_REST_Request $request Requisicao.
	 * @return array|WP_Error Linha do token autenticado ou erro.
	 */
	public static function authenticate( WP_REST_Request $request ) {
		$settings = self::settings();
		$ip       = Audit_Log::client_ip();

		// 1) HTTPS obrigatorio (a menos que desligado).
		if ( ! empty( $settings['https_only'] ) && ! self::is_request_https() ) {
			return new WP_Error( 'mmcb_https_required', __( 'O endpoint exige HTTPS.', 'marreira-mcp-builders' ), array( 'status' => 403 ) );
		}

		// 2) Throttle por IP contra brute-force/probing (20 falhas / 5 min).
		$throttle_key = 'mmcb_fail_' . md5( (string) $ip );
		$failures     = (int) get_transient( $throttle_key );
		if ( $failures >= 20 ) {
			return new WP_Error( 'mmcb_too_many', __( 'Muitas tentativas falhas. Tente novamente em alguns minutos.', 'marreira-mcp-builders' ), array( 'status' => 429 ) );
		}

		// 3) Extrai o token Bearer.
		$plaintext = self::extract_bearer( $request );
		if ( '' === $plaintext ) {
			return new WP_Error( 'mmcb_missing_token', __( 'Token ausente. Envie Authorization: Bearer <token>.', 'marreira-mcp-builders' ), array( 'status' => 401 ) );
		}

		// 4) Validacao leve de formato antes de tocar no banco.
		if ( strlen( $plaintext ) > 200 || strlen( $plaintext ) < 16 || ! preg_match( '/^[A-Za-z0-9._\-]+$/', $plaintext ) ) {
			set_transient( $throttle_key, $failures + 1, 5 * MINUTE_IN_SECONDS );
			return new WP_Error( 'mmcb_invalid_token', __( 'Token invalido.', 'marreira-mcp-builders' ), array( 'status' => 401 ) );
		}

		// 5) Lookup por hash.
		$row = Token_Manager::find_by_plaintext( $plaintext );
		if ( ! $row ) {
			set_transient( $throttle_key, $failures + 1, 5 * MINUTE_IN_SECONDS );
			return new WP_Error( 'mmcb_invalid_token', __( 'Token invalido.', 'marreira-mcp-builders' ), array( 'status' => 401 ) );
		}

		// 6) Status e expiracao. Contam como falha no throttle igual as etapas
		//    anteriores: token revogado/expirado e credencial invalida do mesmo
		//    jeito, e nao incrementar deixava o pipeline assimetrico (dava pra
		//    sondar tokens conhecidos sem nunca gastar o limite).
		if ( 'active' !== $row['status'] ) {
			set_transient( $throttle_key, $failures + 1, 5 * MINUTE_IN_SECONDS );
			return new WP_Error( 'mmcb_token_revoked', __( 'Token revogado.', 'marreira-mcp-builders' ), array( 'status' => 401 ) );
		}
		// expires_at e gravado com gmdate (UTC) e o WordPress forca o fuso do PHP
		// pra UTC (wp-settings.php), entao strtotime interpreta em UTC e a
		// comparacao com time() esta correta.
		if ( ! empty( $row['expires_at'] ) && strtotime( $row['expires_at'] ) < time() ) {
			set_transient( $throttle_key, $failures + 1, 5 * MINUTE_IN_SECONDS );
			return new WP_Error( 'mmcb_token_expired', __( 'Token expirado.', 'marreira-mcp-builders' ), array( 'status' => 401 ) );
		}

		// 7) IP allowlist (se configurada).
		$allowed = isset( $settings['allowed_ips'] ) ? trim( (string) $settings['allowed_ips'] ) : '';
		if ( '' !== $allowed ) {
			$list = array_filter( array_map( 'trim', preg_split( '/[\s,]+/', $allowed ) ) );
			if ( ! empty( $list ) && ! in_array( $ip, $list, true ) ) {
				return new WP_Error( 'mmcb_ip_not_allowed', __( 'IP nao autorizado.', 'marreira-mcp-builders' ), array( 'status' => 403 ) );
			}
		}

		// 8) O dono do token precisa continuar sendo admin. Se foi apagado ou
		//    despromovido, o token deixa de valer (nao basta confiar no token).
		$user_id = (int) $row['created_by'];
		if ( $user_id <= 0 ) {
			return new WP_Error( 'mmcb_no_owner', __( 'Token sem usuario dono valido.', 'marreira-mcp-builders' ), array( 'status' => 401 ) );
		}
		$user = get_user_by( 'id', $user_id );
		if ( ! $user instanceof \WP_User || ! user_can( $user, 'manage_options' ) ) {
			return new WP_Error( 'mmcb_owner_demoted', __( 'O dono do token perdeu privilegios de administracao.', 'marreira-mcp-builders' ), array( 'status' => 403 ) );
		}

		// 9) Sucesso: zera contador de falhas do IP e assume o dono.
		delete_transient( $throttle_key );
		wp_set_current_user( $user_id );

		// 10) Rate limit por token (volume de requisicoes bem-sucedidas).
		$limited = self::rate_limit( (int) $row['id'], $settings );
		if ( is_wp_error( $limited ) ) {
			return $limited;
		}

		Token_Manager::touch_used( (int) $row['id'], $ip );
		self::$current_token = $row;

		return $row;
	}

	/**
	 * Retorna o token autenticado na requisicao atual.
	 *
	 * @return array|null
	 */
	public static function current_token() {
		return self::$current_token;
	}

	/**
	 * Indica se o token atual concede uma ability.
	 *
	 * @param string $ability Ability.
	 * @return bool
	 */
	public static function token_can( $ability ) {
		if ( null === self::$current_token ) {
			return false;
		}
		return Token_Manager::token_can( self::$current_token, $ability );
	}

	/**
	 * Exige uma ability do token atual; devolve WP_Error se faltar.
	 *
	 * @param string $ability Ability.
	 * @return true|WP_Error
	 */
	public static function require_ability( $ability ) {
		if ( self::token_can( $ability ) ) {
			return true;
		}
		return new WP_Error(
			'mmcb_forbidden',
			sprintf(
				/* translators: %s: ability name */
				__( 'O token nao possui a permissao "%s".', 'marreira-mcp-builders' ),
				$ability
			),
			array( 'status' => 403 )
		);
	}

	/**
	 * HTTPS direto ou TLS terminado em proxy confiavel (MMCB_TRUST_PROXY).
	 *
	 * @return bool
	 */
	private static function is_request_https() {
		if ( is_ssl() ) {
			return true;
		}

		// X-Forwarded-Proto so vale vindo de um proxy confiavel — senao qualquer
		// um manda "X-Forwarded-Proto: https" numa conexao HTTP em claro e passa
		// pelo HTTPS-only, com o token Bearer trafegando exposto.
		$remote = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		if ( Audit_Log::proxy_is_trusted( $remote ) && ! empty( $_SERVER['HTTP_X_FORWARDED_PROTO'] ) ) {
			$proto = strtolower( trim( explode( ',', (string) wp_unslash( $_SERVER['HTTP_X_FORWARDED_PROTO'] ) )[0] ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			return 'https' === $proto;
		}

		return false;
	}

	/**
	 * Extrai o token de Authorization: Bearer xxx (com fallbacks CGI).
	 *
	 * @param WP_REST_Request $request Requisicao.
	 * @return string Token ou string vazia.
	 */
	private static function extract_bearer( WP_REST_Request $request ) {
		$header = $request->get_header( 'authorization' );

		if ( ! $header && isset( $_SERVER['HTTP_AUTHORIZATION'] ) ) {
			$header = wp_unslash( $_SERVER['HTTP_AUTHORIZATION'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		}
		if ( ! $header && isset( $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ) ) {
			$header = wp_unslash( $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		}
		// Fallback proprio (X-MMCB-Token) para ambientes que filtram Authorization.
		if ( ! $header ) {
			$alt = $request->get_header( 'x_mmcb_token' );
			if ( $alt ) {
				return trim( $alt );
			}
		}

		if ( ! is_string( $header ) || '' === $header ) {
			return '';
		}
		if ( 0 === stripos( $header, 'Bearer ' ) ) {
			return trim( substr( $header, 7 ) );
		}
		return trim( $header );
	}

	/**
	 * Rate limit por token usando transients.
	 *
	 * @param int   $token_id Id do token.
	 * @param array $settings Configuracoes.
	 * @return true|WP_Error
	 */
	private static function rate_limit( $token_id, $settings ) {
		$max    = isset( $settings['rate_limit'] ) ? (int) $settings['rate_limit'] : 60;
		$window = isset( $settings['rate_window'] ) ? (int) $settings['rate_window'] : 60;

		if ( $max <= 0 ) {
			return true; // Desativado.
		}

		$key   = 'mmcb_rl_' . (int) $token_id;
		$count = (int) get_transient( $key );

		if ( $count >= $max ) {
			return new WP_Error( 'mmcb_rate_limited', __( 'Limite de requisicoes excedido. Tente novamente em instantes.', 'marreira-mcp-builders' ), array( 'status' => 429 ) );
		}

		set_transient( $key, $count + 1, $window );
		return true;
	}

	/**
	 * Configuracoes com defaults aplicados.
	 *
	 * @return array
	 */
	public static function settings() {
		$settings = get_option( Activator::SETTINGS_OPTION, array() );
		if ( ! is_array( $settings ) ) {
			$settings = array();
		}
		return wp_parse_args( $settings, Activator::default_settings() );
	}
}
