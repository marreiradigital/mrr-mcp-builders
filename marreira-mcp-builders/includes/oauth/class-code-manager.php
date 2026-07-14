<?php
/**
 * Authorization codes OAuth (single-use, PKCE, TTL curto).
 *
 * @package Marreira\MCP_Builders
 */

namespace Marreira\MCP_Builders\OAuth;

use Marreira\MCP_Builders\Activator;
use Marreira\MCP_Builders\Auth\Token_Manager;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Gera e consome authorization codes. O texto puro do code volta ao cliente no
 * redirect e nunca e regravado — guardamos apenas o HMAC-SHA256. Cada code vive
 * 60s, e ligado a client/redirect_uri/PKCE/escopo/usuario e so pode ser trocado
 * uma vez (used_at + lock otimista contra corrida).
 *
 * Datas gravadas em UTC (gmdate) e comparadas como string ISO ordenavel, para
 * o TTL de 60s nao sofrer com fuso horario.
 */
class Code_Manager {

	/**
	 * Validade do code em segundos.
	 *
	 * @var int
	 */
	const TTL = 60;

	/**
	 * Gera um authorization code, grava o hash e devolve o texto puro.
	 *
	 * @param string $client_id      Client.
	 * @param int    $user_id        Admin que consentiu.
	 * @param string $scope          Escopos concedidos (separados por espaco).
	 * @param string $redirect_uri   Redirect exato.
	 * @param string $code_challenge Desafio PKCE (base64url do SHA256 do verifier).
	 * @param string $method         Metodo PKCE (sempre 'S256').
	 * @return string Code em texto puro.
	 */
	public static function generate( $client_id, $user_id, $scope, $redirect_uri, $code_challenge, $method = 'S256' ) {
		global $wpdb;
		$table = Activator::table_oauth_codes();

		$code = wp_generate_password( 48, false, false );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->insert(
			$table,
			array(
				'code_hash'             => Token_Manager::hash( $code ),
				'client_id'             => (string) $client_id,
				'user_id'               => (int) $user_id,
				'scope'                 => substr( (string) $scope, 0, 1000 ),
				'redirect_uri'          => substr( (string) $redirect_uri, 0, 2000 ),
				'code_challenge'        => substr( (string) $code_challenge, 0, 128 ),
				'code_challenge_method' => 'S256',
				'expires_at'            => gmdate( 'Y-m-d H:i:s', time() + self::TTL ),
				'used_at'               => null,
				'created_at'            => gmdate( 'Y-m-d H:i:s' ),
			),
			array( '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
		);

		return $code;
	}

	/**
	 * Valida e consome (single-use) um authorization code.
	 *
	 * A verificacao PKCE acontece ANTES de marcar o code como usado: se o
	 * code_verifier estiver errado, o code NAO e queimado (o cliente legitimo
	 * ainda pode trocar). So depois de tudo validado o UPDATE atomico marca o uso.
	 *
	 * @param string $code_plain    Code recebido.
	 * @param string $client_id     Client que troca.
	 * @param string $redirect_uri  Redirect enviado na troca.
	 * @param string $code_verifier Verifier PKCE (S256).
	 * @return array|WP_Error Linha do code consumido ou erro invalid_grant.
	 */
	public static function consume( $code_plain, $client_id, $redirect_uri, $code_verifier ) {
		global $wpdb;
		$table = Activator::table_oauth_codes();
		$hash  = Token_Manager::hash( (string) $code_plain );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE code_hash = %s LIMIT 1", $hash ), ARRAY_A );

		if ( ! $row ) {
			return self::invalid( 'Authorization code invalido.' );
		}
		if ( ! empty( $row['used_at'] ) ) {
			return self::invalid( 'Authorization code ja utilizado.' );
		}
		if ( gmdate( 'Y-m-d H:i:s' ) > (string) $row['expires_at'] ) {
			return self::invalid( 'Authorization code expirado.' );
		}
		if ( ! hash_equals( (string) $row['client_id'], (string) $client_id ) ) {
			return self::invalid( 'Client nao corresponde ao code.' );
		}
		if ( ! hash_equals( (string) $row['redirect_uri'], (string) $redirect_uri ) ) {
			return self::invalid( 'redirect_uri nao corresponde ao code.' );
		}

		// PKCE S256: base64url(sha256(code_verifier)) == code_challenge. Feito
		// ANTES do UPDATE para nao queimar o code em caso de verifier errado.
		$computed = rtrim( strtr( base64_encode( hash( 'sha256', (string) $code_verifier, true ) ), '+/', '-_' ), '=' );
		if ( ! hash_equals( (string) $row['code_challenge'], $computed ) ) {
			return self::invalid( 'Falha na verificacao PKCE.' );
		}

		// Lock otimista: so um troca vence a corrida (used_at IS NULL).
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$affected = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET used_at = %s WHERE id = %d AND used_at IS NULL", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				gmdate( 'Y-m-d H:i:s' ),
				(int) $row['id']
			)
		);
		if ( 1 !== (int) $affected ) {
			return self::invalid( 'Authorization code ja utilizado.' );
		}

		return $row;
	}

	/**
	 * Remove codes expirados (chamado pela rotina de limpeza).
	 *
	 * @return void
	 */
	public static function purge_expired() {
		global $wpdb;
		$table = Activator::table_oauth_codes();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE expires_at < %s", gmdate( 'Y-m-d H:i:s', time() - HOUR_IN_SECONDS ) ) );
	}

	/**
	 * Erro invalid_grant.
	 *
	 * @param string $desc Descricao.
	 * @return WP_Error
	 */
	private static function invalid( $desc ) {
		return new WP_Error( 'invalid_grant', $desc );
	}
}
