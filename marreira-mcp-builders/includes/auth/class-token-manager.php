<?php
/**
 * Gerenciamento de tokens de acesso (multi-token com escopos).
 *
 * @package Marreira\MCP_Builders
 */

namespace Marreira\MCP_Builders\Auth;

use Marreira\MCP_Builders\Activator;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Gera, valida e revoga tokens Bearer com escopos (abilities).
 *
 * O texto puro do token NUNCA e gravado: guardamos apenas o HMAC-SHA256
 * (com wp_salt('auth')). O texto puro e exibido uma unica vez ao admin
 * no momento da geracao. Cada token carrega uma lista de abilities que
 * limita o que ele pode fazer (ex.: um token so-builder nao roda exec).
 *
 * Portado e endurecido a partir do MRR_WP_CLI_Auth.
 */
class Token_Manager {

	/**
	 * Conjunto fechado de abilities reconhecidas.
	 *
	 * - '*'        acesso total.
	 * - builder    tools MCP do builder (paginas/templates/elementos/estilos).
	 * - read       leituras gerais (site, usuarios, logs, schema do DB).
	 * - content    posts/termos/comentarios/midia.
	 * - plugins/themes/core/files/snippets/db/db_query/exec  poderes gerais WP.
	 * - cli        uso das meta-operacoes de batch/CLI.
	 *
	 * @var string[]
	 */
	const KNOWN_ABILITIES = array(
		'*', 'builder', 'read', 'content',
		'plugins', 'themes', 'core', 'files', 'snippets',
		'db', 'db_query', 'exec', 'cli',
	);

	/**
	 * Calcula o hash HMAC-SHA256 do token em texto puro.
	 *
	 * @param string $plaintext Token em texto puro.
	 * @return string Hash hexadecimal (64 chars).
	 */
	public static function hash( $plaintext ) {
		return hash_hmac( 'sha256', (string) $plaintext, wp_salt( 'auth' ) );
	}

	/**
	 * Gera um novo token, grava o hash e retorna o texto puro (uma vez).
	 *
	 * @param string   $name            Rotulo do token.
	 * @param string[] $abilities       Abilities concedidas.
	 * @param int|null $expires_in_days  Dias ate expirar (null = nao expira).
	 * @param int      $user_id          Dono do token (0 = usuario atual).
	 * @return array{id:int,name:string,plaintext:string,prefix:string,abilities:string[]}
	 */
	public static function generate( $name, array $abilities = array( 'builder' ), $expires_in_days = null, $user_id = 0 ) {
		global $wpdb;
		$table = Activator::table_tokens();

		// So aceita abilities conhecidas.
		$abilities = array_values( array_intersect( $abilities, self::KNOWN_ABILITIES ) );
		if ( empty( $abilities ) ) {
			$abilities = array( 'builder' );
		}

		$prefix    = 'mmcb_' . wp_generate_password( 6, false, false );
		$secret    = wp_generate_password( 48, false, false );
		$plaintext = $prefix . '.' . $secret;
		$hash      = self::hash( $plaintext );

		$expires_at = null;
		if ( $expires_in_days && (int) $expires_in_days > 0 ) {
			$expires_at = gmdate( 'Y-m-d H:i:s', time() + ( (int) $expires_in_days * DAY_IN_SECONDS ) );
		}

		$owner = $user_id > 0 ? (int) $user_id : get_current_user_id();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->insert(
			$table,
			array(
				'name'       => sanitize_text_field( $name ),
				'token_hash' => $hash,
				'prefix'     => $prefix,
				'abilities'  => wp_json_encode( $abilities ),
				'status'     => 'active',
				'created_by' => $owner,
				'expires_at' => $expires_at,
				'created_at' => current_time( 'mysql' ),
			),
			array( '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s' )
		);

		return array(
			'id'        => (int) $wpdb->insert_id,
			'name'      => $name,
			'plaintext' => $plaintext,
			'prefix'    => $prefix,
			'abilities' => $abilities,
		);
	}

	/**
	 * Busca um token pela versao em texto puro (lookup por hash).
	 *
	 * @param string $plaintext Token recebido.
	 * @return array|null Linha do token ou null.
	 */
	public static function find_by_plaintext( $plaintext ) {
		global $wpdb;
		$table = Activator::table_tokens();
		$hash  = self::hash( $plaintext );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE token_hash = %s LIMIT 1", $hash ), ARRAY_A );
		return $row ? $row : null;
	}

	/**
	 * Marca o ultimo uso do token.
	 *
	 * @param int    $id Id do token.
	 * @param string $ip IP do cliente.
	 * @return void
	 */
	public static function touch_used( $id, $ip ) {
		global $wpdb;
		$table = Activator::table_tokens();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update(
			$table,
			array(
				'last_used_at' => current_time( 'mysql' ),
				'last_used_ip' => substr( (string) $ip, 0, 64 ),
			),
			array( 'id' => (int) $id ),
			array( '%s', '%s' ),
			array( '%d' )
		);
	}

	/**
	 * Revoga (desativa) um token sem apagar.
	 *
	 * @param int $id Id.
	 * @return bool
	 */
	public static function revoke( $id ) {
		global $wpdb;
		$table = Activator::table_tokens();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return false !== $wpdb->update( $table, array( 'status' => 'revoked' ), array( 'id' => (int) $id ), array( '%s' ), array( '%d' ) );
	}

	/**
	 * Reativa um token revogado.
	 *
	 * @param int $id Id.
	 * @return bool
	 */
	public static function activate_token( $id ) {
		global $wpdb;
		$table = Activator::table_tokens();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return false !== $wpdb->update( $table, array( 'status' => 'active' ), array( 'id' => (int) $id ), array( '%s' ), array( '%d' ) );
	}

	/**
	 * Apaga um token de vez.
	 *
	 * @param int $id Id.
	 * @return bool
	 */
	public static function delete( $id ) {
		global $wpdb;
		$table = Activator::table_tokens();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return false !== $wpdb->delete( $table, array( 'id' => (int) $id ), array( '%d' ) );
	}

	/**
	 * Lista os tokens (sem expor o hash).
	 *
	 * @return array
	 */
	public static function list_tokens() {
		global $wpdb;
		$table = Activator::table_tokens();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( "SELECT id, name, prefix, abilities, status, created_by, expires_at, last_used_at, last_used_ip, created_at FROM {$table} ORDER BY id DESC", ARRAY_A );
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Quantos tokens ativos existem.
	 *
	 * @return int
	 */
	public static function count_active() {
		global $wpdb;
		$table = Activator::table_tokens();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE status = 'active'" );
	}

	/**
	 * Verifica se a linha do token concede uma ability.
	 *
	 * @param array  $token   Linha do token.
	 * @param string $ability Ability requerida.
	 * @return bool
	 */
	public static function token_can( array $token, $ability ) {
		$abilities = isset( $token['abilities'] ) ? json_decode( (string) $token['abilities'], true ) : array();
		if ( ! is_array( $abilities ) ) {
			return false;
		}
		if ( in_array( '*', $abilities, true ) ) {
			return true;
		}
		return in_array( $ability, $abilities, true );
	}
}
