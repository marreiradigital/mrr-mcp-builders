<?php
/**
 * Plugin Name:       MarreiraMCP Builders
 * Plugin URI:        https://marreiradigital.com.br/marreira-mcp-builders
 * Description:        Servidor MCP (Model Context Protocol) unificado para criar e editar paginas e templates do Bricks Builder OU do Elementor via IA, com CLI completo (estilo WP-CLI), multi-token com escopos, batch de uma requisicao, audit log e endpoints ocultos do indice publico do REST.
 * Version:           1.0.2
 * Requires at least: 6.4
 * Requires PHP:      8.0
 * Author:            Paulo Marreira
 * Author URI:        https://marreiradigital.com.br
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       marreira-mcp-builders
 * Domain Path:       /languages
 *
 * @package Marreira\MCP_Builders
 */

// Bloqueia acesso direto ao arquivo.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// ---------------------------------------------------------------------------
// Constantes do plugin.
// ---------------------------------------------------------------------------
define( 'MMCB_VERSION', '1.0.2' );
define( 'MMCB_PLUGIN_FILE', __FILE__ );
define( 'MMCB_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'MMCB_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'MMCB_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

// Namespace e rotas do endpoint MCP/CLI (ocultas do indice publico).
define( 'MMCB_REST_NAMESPACE', 'marreira-mcp/v1' );
define( 'MMCB_REST_ROUTE', '/mcp' );

// Prefixo das options no banco.
define( 'MMCB_OPTION_PREFIX', 'mmcb_' );

// Nomes-base das tabelas (sem o prefixo do $wpdb).
define( 'MMCB_TABLE_TOKENS', 'mmcb_tokens' );
define( 'MMCB_TABLE_LOGS', 'mmcb_logs' );
define( 'MMCB_TABLE_SNIPPETS', 'mmcb_snippets' );

// Versao do protocolo MCP preferida (a mais recente que suportamos). O servidor
// negocia no initialize: ecoa a versao pedida pelo cliente se estiver na lista
// suportada (ver MCP_Server::SUPPORTED_PROTOCOL_VERSIONS), senao devolve esta.
define( 'MMCB_MCP_PROTOCOL_VERSION', '2025-06-18' );

// ---------------------------------------------------------------------------
// Autoloader simples (PSR-ish) mapeando o namespace para /includes.
// Marreira\MCP_Builders\Auth\Token_Manager  => includes/auth/class-token-manager.php
// Marreira\MCP_Builders\Builders\Builder_Driver => includes/builders/interface-builder-driver.php
// Marreira\MCP_Builders\Builders\Bricks\Bricks_Driver => includes/builders/bricks/class-bricks-driver.php
// ---------------------------------------------------------------------------
spl_autoload_register(
	static function ( $class ) {
		$prefix = 'Marreira\\MCP_Builders\\';
		if ( 0 !== strpos( $class, $prefix ) ) {
			return;
		}

		$relative = substr( $class, strlen( $prefix ) );
		$parts    = explode( '\\', $relative );
		$class_nm = array_pop( $parts );

		// Sub-namespaces viram diretorios em minusculo.
		$sub_path = '';
		if ( ! empty( $parts ) ) {
			$sub_path = strtolower( implode( '/', $parts ) ) . '/';
		}

		// Foo_Bar => foo-bar. Tenta class-*, interface-*, trait-*.
		$slug = strtolower( str_replace( '_', '-', $class_nm ) );
		$base = MMCB_PLUGIN_DIR . 'includes/' . $sub_path;

		foreach ( array( 'class-', 'interface-', 'trait-' ) as $kind ) {
			$file = $base . $kind . $slug . '.php';
			if ( is_readable( $file ) ) {
				require_once $file;
				return;
			}
		}
	}
);

// ---------------------------------------------------------------------------
// Hooks de ciclo de vida.
// ---------------------------------------------------------------------------
register_activation_hook( __FILE__, array( '\Marreira\MCP_Builders\Activator', 'activate' ) );
register_deactivation_hook( __FILE__, array( '\Marreira\MCP_Builders\Activator', 'deactivate' ) );

// ---------------------------------------------------------------------------
// Bootstrap.
// ---------------------------------------------------------------------------
add_action(
	'plugins_loaded',
	static function () {
		\Marreira\MCP_Builders\Plugin::instance()->boot();
	}
);
