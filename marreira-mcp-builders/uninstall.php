<?php
/**
 * Desinstalacao: remove options e tabelas do plugin.
 *
 * @package Marreira\MCP_Builders
 */

// Executado apenas pelo WordPress no momento de desinstalar o plugin.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

// Options do plugin.
delete_option( 'mmcb_settings' );
delete_option( 'mmcb_db_version' );
delete_option( 'mmcb_terms_acceptance' );

// Tabelas do plugin. Precisa listar TODAS — as duas de OAuth ficaram de fora
// quando foram criadas (1.3.x) e sobreviviam a desinstalacao, guardando
// client_ids, redirect_uris e IPs de registro no banco pra sempre.
$mmcb_tables = array(
	$wpdb->prefix . 'mmcb_tokens',
	$wpdb->prefix . 'mmcb_logs',
	$wpdb->prefix . 'mmcb_snippets',
	$wpdb->prefix . 'mmcb_oauth_clients',
	$wpdb->prefix . 'mmcb_oauth_codes',
);

foreach ( $mmcb_tables as $mmcb_table ) {
	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
	$wpdb->query( "DROP TABLE IF EXISTS {$mmcb_table}" );
}
