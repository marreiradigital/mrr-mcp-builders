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
	 * Emite um par access token + refresh token para um client OAuth.
	 *
	 * O access token e um token MMCB normal (mesma tabela, mesmo pipeline HMAC do
	 * Rest_Guard), marcado com source='oauth' e vida curta (default 1h). O refresh
	 * token e guardado apenas como hash HMAC e serve para emitir novos pares via
	 * rotate_refresh(). expires_at em UTC (gmdate), como em generate().
	 *
	 * @param string[] $abilities   Abilities ja filtradas pela trava dupla (Scopes).
	 * @param int      $user_id     Admin que consentiu (dono do token).
	 * @param string   $client_id   Client OAuth.
	 * @param int      $access_ttl  Validade do access token em segundos.
	 * @param int      $refresh_ttl Validade do refresh token em segundos.
	 * @return array{id:int,access_token:string,refresh_token:string,expires_in:int,abilities:string[]}
	 */
	public static function generate_oauth( array $abilities, $user_id, $client_id, $access_ttl = 3600, $refresh_ttl = 2592000 ) {
		global $wpdb;
		$table = Activator::table_tokens();

		$abilities = array_values( array_intersect( $abilities, self::KNOWN_ABILITIES ) );
		if ( empty( $abilities ) ) {
			$abilities = array( 'builder', 'read', 'content' );
		}

		$prefix        = 'oat_' . wp_generate_password( 6, false, false );
		$secret        = wp_generate_password( 48, false, false );
		$access_plain  = $prefix . '.' . $secret;
		$refresh_plain = wp_generate_password( 64, false, false );
		$now           = time();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->insert(
			$table,
			array(
				'name'               => 'OAuth: ' . substr( (string) $client_id, 0, 60 ),
				'token_hash'         => self::hash( $access_plain ),
				'prefix'             => $prefix,
				'abilities'          => wp_json_encode( $abilities ),
				'status'             => 'active',
				'source'             => 'oauth',
				'refresh_hash'       => self::hash( $refresh_plain ),
				'refresh_expires_at' => gmdate( 'Y-m-d H:i:s', $now + (int) $refresh_ttl ),
				'oauth_client_id'    => (string) $client_id,
				'created_by'         => (int) $user_id,
				'expires_at'         => gmdate( 'Y-m-d H:i:s', $now + (int) $access_ttl ),
				'created_at'         => current_time( 'mysql' ),
			),
			array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s' )
		);

		return array(
			'id'            => (int) $wpdb->insert_id,
			'access_token'  => $access_plain,
			'refresh_token' => $refresh_plain,
			'expires_in'    => (int) $access_ttl,
			'abilities'     => $abilities,
		);
	}

	/**
	 * Rotaciona um refresh token: revoga o par atual e emite um novo par para o
	 * mesmo client/usuario/abilities. Rotacao obrigatoria (o refresh antigo deixa
	 * de valer). Detecta reuso: refresh que nao bate em token ativo e recusado.
	 *
	 * @param string $refresh_plain Refresh token recebido.
	 * @param string $client_id     Client que solicita a rotacao.
	 * @return array|\WP_Error Novo par (como generate_oauth) ou erro invalid_grant.
	 */
	public static function rotate_refresh( $refresh_plain, $client_id ) {
		global $wpdb;
		$table = Activator::table_tokens();
		$hash  = self::hash( (string) $refresh_plain );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE refresh_hash = %s AND source = 'oauth' LIMIT 1", $hash ), ARRAY_A );

		if ( ! $row || 'active' !== $row['status'] ) {
			return new \WP_Error( 'invalid_grant', __( 'Refresh token invalido ou revogado.', 'marreira-mcp-builders' ) );
		}
		if ( ! hash_equals( (string) $row['oauth_client_id'], (string) $client_id ) ) {
			return new \WP_Error( 'invalid_grant', __( 'Client nao corresponde ao refresh token.', 'marreira-mcp-builders' ) );
		}
		if ( ! empty( $row['refresh_expires_at'] ) && gmdate( 'Y-m-d H:i:s' ) > (string) $row['refresh_expires_at'] ) {
			return new \WP_Error( 'invalid_grant', __( 'Refresh token expirado.', 'marreira-mcp-builders' ) );
		}

		// Revoga o par antigo (rotacao) antes de emitir o novo.
		self::revoke( (int) $row['id'] );

		$abilities = json_decode( (string) $row['abilities'], true );
		$abilities = is_array( $abilities ) ? $abilities : array( 'builder', 'read', 'content' );

		return self::generate_oauth( $abilities, (int) $row['created_by'], (string) $client_id );
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
