<?php
/**
 * Bootstrap singleton do plugin.
 *
 * @package Marreira\MCP_Builders
 */

namespace Marreira\MCP_Builders;

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

		// Os subsistemas abaixo sao ligados nas fases seguintes:
		// - Auth\Rest_Guard / Auth\Token_Manager (F1)
		// - Security\Audit_Log                    (F1)
		// - MCP\MCP_Server                         (F3)
		// - CLI\Rest_Controller                    (F5)
		// - CLI\WP_CLI_Commands                    (F6)
		// - Admin\Admin                            (F1/F7)
	}
}
