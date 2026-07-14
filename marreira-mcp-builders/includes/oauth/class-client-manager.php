<?php
/**
 * Dynamic Client Registration (RFC 7591) e CRUD de clients OAuth.
 *
 * @package Marreira\MCP_Builders
 */

namespace Marreira\MCP_Builders\OAuth;

use Marreira\MCP_Builders\Activator;
use Marreira\MCP_Builders\Auth\Token_Manager;
use Marreira\MCP_Builders\Security\Audit_Log;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registra e gerencia os clients OAuth. Clients nascem 'pending' e so operam
 * (a tela de consentimento so aceita) apos aprovacao de um admin — salvo se a
 * setting oauth_auto_approve estiver ligada. Registro aberto e mitigado com
 * rate limit por IP.
 */
class Client_Manager {

	/**
	 * Maximo de registros por IP na janela.
	 *
	 * @var int
	 */
	const REG_RATE_MAX = 5;

	/**
	 * Maximo de redirect_uris por client.
	 *
	 * @var int
	 */
	const MAX_REDIRECT_URIS = 10;

	/**
	 * Trata POST /marreira-mcp-oauth/register.
	 *
	 * @return array{status:int,body:array} Status HTTP e corpo JSON.
	 */
	public static function handle_register() {
		$ip  = Audit_Log::client_ip();
		$key = 'mmcb_reg_' . md5( (string) $ip );

		$count = (int) get_transient( $key );
		if ( $count >= self::REG_RATE_MAX ) {
			return array(
				'status' => 429,
				'body'   => array(
					'error'             => 'temporarily_unavailable',
					'error_description' => 'Muitas tentativas de registro. Tente novamente mais tarde.',
				),
			);
		}
		set_transient( $key, $count + 1, HOUR_IN_SECONDS );

		$raw  = file_get_contents( 'php://input' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$data = json_decode( (string) $raw, true );
		if ( ! is_array( $data ) ) {
			return self::invalid_request( 'Corpo JSON invalido.' );
		}

		$redirect_uris = isset( $data['redirect_uris'] ) && is_array( $data['redirect_uris'] ) ? $data['redirect_uris'] : array();
		if ( empty( $redirect_uris ) ) {
			return self::invalid_request( 'redirect_uris e obrigatorio.' );
		}
		if ( count( $redirect_uris ) > self::MAX_REDIRECT_URIS ) {
			return self::invalid_request( 'Numero excessivo de redirect_uris.' );
		}

		$clean_uris = array();
		foreach ( $redirect_uris as $uri ) {
			if ( ! self::is_valid_redirect_uri( $uri ) ) {
				return array(
					'status' => 400,
					'body'   => array(
						'error'             => 'invalid_redirect_uri',
						'error_description' => 'redirect_uri invalido: exige HTTPS (ou http em localhost) e sem fragmento (#).',
					),
				);
			}
			$clean_uris[] = esc_url_raw( $uri );
		}
		$clean_uris = array_values( array_unique( $clean_uris ) );

		$client_name = isset( $data['client_name'] ) ? sanitize_text_field( (string) $data['client_name'] ) : '';

		$client_id  = 'mmcb_cli_' . wp_generate_password( 24, false, false );
		$reg_token  = wp_generate_password( 48, false, false );
		$settings   = get_option( Activator::SETTINGS_OPTION, array() );
		$auto       = is_array( $settings ) && ! empty( $settings['oauth_auto_approve'] );
		$status     = $auto ? 'approved' : 'pending';

		global $wpdb;
		$table = Activator::table_oauth_clients();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->insert(
			$table,
			array(
				'client_id'      => $client_id,
				'client_name'    => $client_name,
				'redirect_uris'  => wp_json_encode( $clean_uris ),
				'status'         => $status,
				'reg_ip'         => substr( (string) $ip, 0, 64 ),
				'reg_token_hash' => Token_Manager::hash( $reg_token ),
				'created_at'     => current_time( 'mysql' ),
				'approved_at'    => $auto ? current_time( 'mysql' ) : null,
				'approved_by'    => $auto ? 0 : null,
			),
			array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d' )
		);

		Audit_Log::log(
			array(
				'method'           => 'POST',
				'route'            => MMCB_OAUTH_BASE_PATH . '/register',
				'action'           => 'oauth:register',
				'status_code'      => 201,
				'ip'               => $ip,
				'response_summary' => 'Client registrado (' . $status . '): ' . $client_name,
				'success'          => true,
			)
		);

		return array(
			'status' => 201,
			'body'   => array(
				'client_id'                  => $client_id,
				'client_id_issued_at'        => time(),
				'client_name'                => $client_name,
				'redirect_uris'              => $clean_uris,
				'grant_types'                => array( 'authorization_code', 'refresh_token' ),
				'response_types'             => array( 'code' ),
				'token_endpoint_auth_method' => 'none',
				'registration_access_token'  => $reg_token,
			),
		);
	}

	/**
	 * Valida um redirect_uri: HTTPS obrigatorio (http so em localhost), sem
	 * fragmento, host presente.
	 *
	 * @param mixed $uri URI candidato.
	 * @return bool
	 */
	public static function is_valid_redirect_uri( $uri ) {
		if ( ! is_string( $uri ) || '' === $uri || strlen( $uri ) > 2000 ) {
			return false;
		}
		$parts = wp_parse_url( $uri );
		if ( ! is_array( $parts ) || empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
			return false;
		}
		if ( isset( $parts['fragment'] ) ) {
			return false;
		}
		$scheme = strtolower( $parts['scheme'] );
		$host   = strtolower( $parts['host'] );
		if ( 'https' === $scheme ) {
			return true;
		}
		if ( 'http' === $scheme && in_array( $host, array( 'localhost', '127.0.0.1', '::1' ), true ) ) {
			return true;
		}
		return false;
	}

	/**
	 * Busca um client pelo client_id.
	 *
	 * @param string $client_id Identificador publico.
	 * @return array|null
	 */
	public static function find_by_client_id( $client_id ) {
		global $wpdb;
		$table = Activator::table_oauth_clients();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE client_id = %s LIMIT 1", (string) $client_id ), ARRAY_A );
		return $row ? $row : null;
	}

	/**
	 * Lista os redirect_uris de uma linha de client (decodifica o JSON).
	 *
	 * @param array $client Linha do client.
	 * @return string[]
	 */
	public static function redirect_uris( array $client ) {
		$uris = isset( $client['redirect_uris'] ) ? json_decode( (string) $client['redirect_uris'], true ) : array();
		return is_array( $uris ) ? $uris : array();
	}

	/**
	 * Aprova um client (admin).
	 *
	 * @param int $id      Id do client.
	 * @param int $user_id Admin que aprovou.
	 * @return bool
	 */
	public static function approve( $id, $user_id ) {
		global $wpdb;
		$table = Activator::table_oauth_clients();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return false !== $wpdb->update(
			$table,
			array(
				'status'      => 'approved',
				'approved_at' => current_time( 'mysql' ),
				'approved_by' => (int) $user_id,
			),
			array( 'id' => (int) $id ),
			array( '%s', '%s', '%d' ),
			array( '%d' )
		);
	}

	/**
	 * Revoga um client.
	 *
	 * @param int $id Id do client.
	 * @return bool
	 */
	public static function revoke( $id ) {
		global $wpdb;
		$table = Activator::table_oauth_clients();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return false !== $wpdb->update( $table, array( 'status' => 'revoked' ), array( 'id' => (int) $id ), array( '%s' ), array( '%d' ) );
	}

	/**
	 * Lista os clients (mais recentes primeiro), sem expor hashes.
	 *
	 * @return array
	 */
	public static function list_all() {
		global $wpdb;
		$table = Activator::table_oauth_clients();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( "SELECT id, client_id, client_name, redirect_uris, status, reg_ip, created_at, approved_at, approved_by FROM {$table} ORDER BY id DESC", ARRAY_A );
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Quantos clients estao pendentes de aprovacao.
	 *
	 * @return int
	 */
	public static function count_pending() {
		global $wpdb;
		$table = Activator::table_oauth_clients();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE status = 'pending'" );
	}

	/**
	 * Resposta padrao de invalid_request.
	 *
	 * @param string $desc Descricao.
	 * @return array{status:int,body:array}
	 */
	private static function invalid_request( $desc ) {
		return array(
			'status' => 400,
			'body'   => array(
				'error'             => 'invalid_request',
				'error_description' => $desc,
			),
		);
	}
}
