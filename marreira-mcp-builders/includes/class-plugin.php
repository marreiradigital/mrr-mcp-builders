<?php
/**
 * Bootstrap singleton do plugin.
 *
 * @package Marreira\MCP_Builders
 */

namespace Marreira\MCP_Builders;

use Marreira\MCP_Builders\Security\Audit_Log;
use Marreira\MCP_Builders\MCP\MCP_Server;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Orquestra a inicializacao dos subsistemas (auth, MCP, CLI, admin).
 *
 * O metodo boot() cresce a cada fase de desenvolvimento; cada subsistema so
 * e instanciado quando sua classe existe, mantendo o carregamento seguro.
 */
final class Plugin {

	/**
	 * Instancia unica.
	 *
	 * @var Plugin|null
	 */
	private static $instance = null;

	/**
	 * Retorna a instancia unica.
	 *
	 * @return Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Construtor privado (singleton).
	 */
	private function __construct() {}

	/**
	 * Inicializa o plugin.
	 *
	 * @return void
	 */
	public function boot() {
		// Migracao de schema quando a versao das tabelas muda.
		Activator::maybe_upgrade();

		load_plugin_textdomain(
			'marreira-mcp-builders',
			false,
			dirname( MMCB_PLUGIN_BASENAME ) . '/languages'
		);

		// Audit log (F1): hook de rest_post_dispatch + cron de retencao.
		Audit_Log::init();

		// Servidor MCP (F3): rotas /mcp, /skill, /describe + dispatch JSON-RPC.
		( new MCP_Server() )->register_hooks();

		// Os subsistemas abaixo sao ligados nas fases seguintes:
		// - CLI\Rest_Controller   (F5)  rotas /cli/*
		// - CLI\WP_CLI_Commands   (F6)  comandos wp mmcb ...
		// - Admin\Admin           (F7)  painel SPA (onboarding, tokens, logs, settings)
	}
}
