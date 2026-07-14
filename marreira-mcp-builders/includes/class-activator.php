<?php
/**
 * Rotinas de ativacao/desativacao e criacao de tabelas.
 *
 * @package Marreira\MCP_Builders
 */

namespace Marreira\MCP_Builders;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Ciclo de vida do plugin unificado.
 *
 * Cria as tabelas (tokens, logs, snippets), semeia as options padrao de
 * seguranca e marca a versao instalada. O builder ativo e o tier de IA
 * comecam vazios para forcar o onboarding no painel.
 */
class Activator {

	/**
	 * Option com os toggles de seguranca/configuracao.
	 *
	 * @var string
	 */
	const SETTINGS_OPTION = MMCB_OPTION_PREFIX . 'settings';

	/**
	 * Option com a versao instalada (para migracoes de schema).
	 *
	 * @var string
	 */
	const VERSION_OPTION = MMCB_OPTION_PREFIX . 'db_version';

	/**
	 * Versao do schema do banco. Incrementar quando as tabelas mudarem.
	 *
	 * @var string
	 */
	const DB_VERSION = '2';

	/**
	 * Nome completo da tabela de tokens.
	 *
	 * @return string
	 */
	public static function table_tokens() {
		global $wpdb;
		return $wpdb->prefix . MMCB_TABLE_TOKENS;
	}

	/**
	 * Nome completo da tabela de logs.
	 *
	 * @return string
	 */
	public static function table_logs() {
		global $wpdb;
		return $wpdb->prefix . MMCB_TABLE_LOGS;
	}

	/**
	 * Nome completo da tabela de snippets.
	 *
	 * @return string
	 */
	public static function table_snippets() {
		global $wpdb;
		return $wpdb->prefix . MMCB_TABLE_SNIPPETS;
	}

	/**
	 * Nome completo da tabela de clients OAuth (Dynamic Client Registration).
	 *
	 * @return string
	 */
	public static function table_oauth_clients() {
		global $wpdb;
		return $wpdb->prefix . MMCB_TABLE_OAUTH_CLIENTS;
	}

	/**
	 * Nome completo da tabela de authorization codes OAuth.
	 *
	 * @return string
	 */
	public static function table_oauth_codes() {
		global $wpdb;
		return $wpdb->prefix . MMCB_TABLE_OAUTH_CODES;
	}

	/**
	 * Defaults seguros das configuracoes.
	 *
	 * @return array
	 */
	public static function default_settings() {
		return array(
			// Builder ativo. Vazio => onboarding obrigatorio no painel.
			'active_builder'     => '',      // 'bricks' | 'elementor'.
			// Modo de consumo de contexto pela IA. Vazio => onboarding.
			'ai_tier'            => '',       // 'premium' | 'economy'.
			'onboarding_done'    => false,

			// Seguranca do endpoint MCP (herdado dos plugins atuais).
			'https_only'         => true,
			'rate_limit'         => 60,       // Requisicoes por janela (por token).
			'rate_window'        => 60,       // Janela em segundos.
			'block_code'         => true,     // Code_Guard anti-RCE ligado.
			'service_user_id'    => 0,        // Fallback; multi-token usa created_by.

			// CLI geral de WordPress: DESLIGADO de fabrica (double-gating).
			'enable_general_cli' => false,
			'allow_php_exec'     => false,
			'allow_db_query'     => false,
			'allow_file_write'   => false,

			// Conector OAuth para IAs externas (Claude.ai / ChatGPT).
			// enable_oauth LIGADO: expoe o discovery e o fluxo OAuth. A seguranca
			// vem do consentimento humano (o admin aprova cada client) — nao do
			// desligamento. oauth_auto_approve DESLIGADO: clients registrados via
			// DCR ficam 'pending' ate o admin aprovar no painel.
			'enable_oauth'       => true,
			'oauth_auto_approve' => false,

			// Rede e retencao.
			'allowed_ips'        => '',       // Vazio = qualquer IP (respeita throttle).
			'log_retention_days' => 90,
			'db_blacklist'       => '',       // Tabelas ocultas do explorador de DB.
		);
	}

	/**
	 * Ativacao: cria tabelas, semeia options, marca versao.
	 *
	 * @return void
	 */
	public static function activate() {
		self::install_tables();

		// Semeia configuracoes apenas se ainda nao existirem (nao sobrescreve).
		if ( false === get_option( self::SETTINGS_OPTION, false ) ) {
			add_option( self::SETTINGS_OPTION, self::default_settings(), '', false );
		}

		update_option( self::VERSION_OPTION, self::DB_VERSION, false );

		// Garante que as rotas REST sejam reconhecidas.
		flush_rewrite_rules();
	}

	/**
	 * Desativacao.
	 *
	 * @return void
	 */
	public static function deactivate() {
		// Limpa o cron de retencao de logs.
		$timestamp = wp_next_scheduled( 'mmcb_daily_purge' );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, 'mmcb_daily_purge' );
		}

		flush_rewrite_rules();
	}

	/**
	 * Cria/atualiza as tabelas via dbDelta.
	 *
	 * @return void
	 */
	public static function install_tables() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = $wpdb->get_charset_collate();
		$tokens          = self::table_tokens();
		$logs            = self::table_logs();
		$snippets        = self::table_snippets();

		// Tabela de tokens (multi-token com escopos). As colunas source/refresh_hash/
		// oauth_client_id suportam os access tokens emitidos pelo fluxo OAuth
		// (source='oauth'); tokens estaticos ficam com source='static'. dbDelta
		// adiciona as colunas novas em bancos ja existentes.
		$sql_tokens = "CREATE TABLE {$tokens} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			name varchar(191) NOT NULL DEFAULT '',
			token_hash char(64) NOT NULL,
			prefix varchar(32) NOT NULL DEFAULT '',
			abilities longtext NULL,
			status varchar(20) NOT NULL DEFAULT 'active',
			source varchar(20) NOT NULL DEFAULT 'static',
			refresh_hash char(64) NULL,
			refresh_expires_at datetime NULL,
			oauth_client_id varchar(255) NULL,
			created_by bigint(20) unsigned NOT NULL DEFAULT 0,
			expires_at datetime NULL,
			last_used_at datetime NULL,
			last_used_ip varchar(64) NOT NULL DEFAULT '',
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY token_hash (token_hash),
			KEY status (status),
			KEY refresh_hash (refresh_hash),
			KEY oauth_client_id (oauth_client_id)
		) {$charset_collate};";

		// Tabela de audit log.
		$sql_logs = "CREATE TABLE {$logs} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			token_id bigint(20) unsigned NOT NULL DEFAULT 0,
			user_id bigint(20) unsigned NOT NULL DEFAULT 0,
			method varchar(10) NOT NULL DEFAULT '',
			route varchar(191) NOT NULL DEFAULT '',
			action varchar(191) NOT NULL DEFAULT '',
			status_code smallint(5) unsigned NOT NULL DEFAULT 0,
			ip varchar(64) NOT NULL DEFAULT '',
			user_agent varchar(255) NOT NULL DEFAULT '',
			request_payload longtext NULL,
			response_summary longtext NULL,
			success tinyint(1) NOT NULL DEFAULT 0,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY token_id (token_id),
			KEY created_at (created_at)
		) {$charset_collate};";

		// Tabela de snippets PHP (subsistema do CLI geral).
		$sql_snippets = "CREATE TABLE {$snippets} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			name varchar(191) NOT NULL DEFAULT '',
			description longtext NULL,
			code longtext NULL,
			scope varchar(20) NOT NULL DEFAULT 'global',
			active tinyint(1) NOT NULL DEFAULT 0,
			priority int(11) NOT NULL DEFAULT 10,
			created_by bigint(20) unsigned NOT NULL DEFAULT 0,
			updated_by bigint(20) unsigned NOT NULL DEFAULT 0,
			created_at datetime NOT NULL,
			updated_at datetime NULL,
			PRIMARY KEY  (id),
			KEY active (active)
		) {$charset_collate};";

		// Tabela de clients OAuth (Dynamic Client Registration, RFC 7591). Clients
		// nascem 'pending' e so operam apos aprovacao do admin (status 'approved').
		$oauth_clients = self::table_oauth_clients();
		$sql_oauth_clients = "CREATE TABLE {$oauth_clients} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			client_id varchar(255) NOT NULL,
			client_name varchar(255) NOT NULL DEFAULT '',
			redirect_uris longtext NULL,
			status varchar(20) NOT NULL DEFAULT 'pending',
			reg_ip varchar(64) NOT NULL DEFAULT '',
			reg_token_hash char(64) NULL,
			created_at datetime NOT NULL,
			approved_at datetime NULL,
			approved_by bigint(20) unsigned NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY client_id (client_id),
			KEY status (status)
		) {$charset_collate};";

		// Tabela de authorization codes OAuth. Codes guardados so como hash HMAC,
		// single-use (used_at) e com TTL curto (expires_at ~60s).
		$oauth_codes = self::table_oauth_codes();
		$sql_oauth_codes = "CREATE TABLE {$oauth_codes} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			code_hash char(64) NOT NULL,
			client_id varchar(255) NOT NULL,
			user_id bigint(20) unsigned NOT NULL DEFAULT 0,
			scope varchar(1000) NOT NULL DEFAULT '',
			redirect_uri varchar(2000) NOT NULL DEFAULT '',
			code_challenge varchar(128) NOT NULL DEFAULT '',
			code_challenge_method varchar(10) NOT NULL DEFAULT 'S256',
			expires_at datetime NOT NULL,
			used_at datetime NULL,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY code_hash (code_hash),
			KEY client_id (client_id),
			KEY expires_at (expires_at)
		) {$charset_collate};";

		dbDelta( $sql_tokens );
		dbDelta( $sql_logs );
		dbDelta( $sql_snippets );
		dbDelta( $sql_oauth_clients );
		dbDelta( $sql_oauth_codes );
	}

	/**
	 * Roda migracao de schema quando a versao muda (chamado no boot).
	 *
	 * @return void
	 */
	public static function maybe_upgrade() {
		$installed = get_option( self::VERSION_OPTION, '0' );
		if ( (string) $installed !== self::DB_VERSION ) {
			self::install_tables();
			update_option( self::VERSION_OPTION, self::DB_VERSION, false );
		}
	}
}
