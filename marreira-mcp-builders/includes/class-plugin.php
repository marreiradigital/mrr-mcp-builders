<?php
/**
 * Bootstrap singleton do plugin.
 *
 * @package Marreira\MCP_Builders
 */

namespace Marreira\MCP_Builders;

use Marreira\MCP_Builders\Security\Audit_Log;
use Marreira\MCP_Builders\MCP\MCP_Server;
use Marreira\MCP_Builders\OAuth\Router as OAuth_Router;
use Marreira\MCP_Builders\CLI\WP_CLI_Commands;
use Marreira\MCP_Builders\CLI\Rest_Controller;
use Marreira\MCP_Builders\CLI\Snippets;
use Marreira\MCP_Builders\Admin\Admin;

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

		// Conector OAuth para IAs externas (Claude.ai / ChatGPT): endpoints de
		// discovery, registro (DCR), consentimento e token servidos na RAIZ do
		// site (fora do /wp-json), interceptados no hook init.
		OAuth_Router::init();

		// CLI geral de WordPress (F5): rotas /cli/* (desligadas por padrao nas
		// settings; as rotas existem mas os handlers barram com 403 ate ligar).
		Rest_Controller::register_hooks();

		// Snippets PHP: executa os ativos (criacao e gated por ability + flag).
		Snippets::init();

		// WP-CLI local (F6): comandos wp mmcb ... para o dono operar no terminal.
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			WP_CLI_Commands::register();
		}

		// Painel admin (F7): SPA de onboarding, tokens, logs e configuracoes.
		if ( is_admin() ) {
			( new Admin() )->register_hooks();
		}
	}
}
