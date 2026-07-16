<?php
/**
 * Controlador REST do CLI geral de WordPress.
 *
 * Portado de MRR_WP_CLI_REST_Controller, adaptado ao sistema de auth,
 * configuracoes e audit do MarreiraMCP Builders. Toda autenticacao passa
 * pelo Rest_Guard; o Audit_Log registra automaticamente via rest_post_dispatch.
 *
 * Regras de seguranca:
 *  - Todas as rotas: show_in_index => false.
 *  - Rotas de descoberta (/cli/status, /cli/site, /cli/describe): qualquer
 *    token valido (Rest_Guard::check). Sem master switch.
 *  - Demais rotas: Rest_Guard::ability_gate( '<ability>' ) + require_cli_enabled()
 *    no topo de cada handler.
 *  - /cli/exec/php: double-gate adicional (allow_php_exec + re-check ability).
 *  - /cli/theme/file POST e /cli/theme/functions PUT: double-gate allow_file_write.
 *  - /cli/db/query: DB_Explorer::safe_query verifica allow_db_query internamente.
 *  - Self-protection: deactivate/delete plugin bloqueia MMCB_PLUGIN_BASENAME;
 *    delete theme bloqueia o tema ativo.
 *
 * @package Marreira\MCP_Builders
 */

namespace Marreira\MCP_Builders\CLI;

use Marreira\MCP_Builders\Auth\Rest_Guard;
use Marreira\MCP_Builders\Security\Audit_Log;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Rotas /cli/* do CLI geral de WordPress.
 */
class Rest_Controller {

	/* ------------------------------------------------------------------ */
	/*  Bootstrap                                                           */
	/* ------------------------------------------------------------------ */

	/**
	 * Registra o hook de inicializacao do REST.
	 *
	 * @return void
	 */
	public static function register_hooks(): void {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	/**
	 * Registra todas as rotas /cli/* no namespace do plugin.
	 *
	 * @return void
	 */
	public static function register_routes(): void {
		$ns      = MMCB_REST_NAMESPACE;
		$any_tok = array( Rest_Guard::class, 'check' );

		/* ---------- Discovery (token valido; sem master switch) ---------- */

		register_rest_route( $ns, '/cli/status', array(
			'methods'             => \WP_REST_Server::READABLE,
			'callback'            => array( __CLASS__, 'status' ),
			'permission_callback' => $any_tok,
			'show_in_index'       => false,
		) );

		register_rest_route( $ns, '/cli/site', array(
			'methods'             => \WP_REST_Server::READABLE,
			'callback'            => array( __CLASS__, 'site_info' ),
			'permission_callback' => $any_tok,
			'show_in_index'       => false,
		) );

		register_rest_route( $ns, '/cli/describe', array(
			'methods'             => \WP_REST_Server::READABLE,
			'callback'            => array( __CLASS__, 'describe' ),
			'permission_callback' => $any_tok,
			'show_in_index'       => false,
		) );

		/* ---------- Plugins ---------- */

		register_rest_route( $ns, '/cli/plugins', array(
			'methods'             => \WP_REST_Server::READABLE,
			'callback'            => array( __CLASS__, 'list_plugins' ),
			'permission_callback' => Rest_Guard::ability_gate( 'plugins' ),
			'show_in_index'       => false,
		) );

		register_rest_route( $ns, '/cli/plugins/install', array(
			'methods'             => \WP_REST_Server::CREATABLE,
			'callback'            => array( __CLASS__, 'install_plugin' ),
			'permission_callback' => Rest_Guard::ability_gate( 'plugins' ),
			'show_in_index'       => false,
		) );

		register_rest_route( $ns, '/cli/plugins/activate', array(
			'methods'             => \WP_REST_Server::CREATABLE,
			'callback'            => array( __CLASS__, 'activate_plugin' ),
			'permission_callback' => Rest_Guard::ability_gate( 'plugins' ),
			'show_in_index'       => false,
		) );

		register_rest_route( $ns, '/cli/plugins/deactivate', array(
			'methods'             => \WP_REST_Server::CREATABLE,
			'callback'            => array( __CLASS__, 'deactivate_plugin' ),
			'permission_callback' => Rest_Guard::ability_gate( 'plugins' ),
			'show_in_index'       => false,
		) );

		register_rest_route( $ns, '/cli/plugins/update', array(
			'methods'             => \WP_REST_Server::CREATABLE,
			'callback'            => array( __CLASS__, 'update_plugin' ),
			'permission_callback' => Rest_Guard::ability_gate( 'plugins' ),
			'show_in_index'       => false,
		) );

		register_rest_route( $ns, '/cli/plugins/delete', array(
			'methods'             => \WP_REST_Server::CREATABLE,
			'callback'            => array( __CLASS__, 'delete_plugin' ),
			'permission_callback' => Rest_Guard::ability_gate( 'plugins' ),
			'show_in_index'       => false,
		) );

		/* ---------- Themes ---------- */

		register_rest_route( $ns, '/cli/themes', array(
			'methods'             => \WP_REST_Server::READABLE,
			'callback'            => array( __CLASS__, 'list_themes' ),
			'permission_callback' => Rest_Guard::ability_gate( 'themes' ),
			'show_in_index'       => false,
		) );

		register_rest_route( $ns, '/cli/themes/install', array(
			'methods'             => \WP_REST_Server::CREATABLE,
			'callback'            => array( __CLASS__, 'install_theme' ),
			'permission_callback' => Rest_Guard::ability_gate( 'themes' ),
			'show_in_index'       => false,
		) );

		register_rest_route( $ns, '/cli/themes/activate', array(
			'methods'             => \WP_REST_Server::CREATABLE,
			'callback'            => array( __CLASS__, 'activate_theme' ),
			'permission_callback' => Rest_Guard::ability_gate( 'themes' ),
			'show_in_index'       => false,
		) );

		register_rest_route( $ns, '/cli/themes/update', array(
			'methods'             => \WP_REST_Server::CREATABLE,
			'callback'            => array( __CLASS__, 'update_theme' ),
			'permission_callback' => Rest_Guard::ability_gate( 'themes' ),
			'show_in_index'       => false,
		) );

		register_rest_route( $ns, '/cli/themes/delete', array(
			'methods'             => \WP_REST_Server::CREATABLE,
			'callback'            => array( __CLASS__, 'delete_theme' ),
			'permission_callback' => Rest_Guard::ability_gate( 'themes' ),
			'show_in_index'       => false,
		) );

		/* ---------- Core ---------- */

		register_rest_route( $ns, '/cli/core', array(
			'methods'             => \WP_REST_Server::READABLE,
			'callback'            => array( __CLASS__, 'core_info' ),
			'permission_callback' => Rest_Guard::ability_gate( 'core' ),
			'show_in_index'       => false,
		) );

		register_rest_route( $ns, '/cli/core/update', array(
			'methods'             => \WP_REST_Server::CREATABLE,
			'callback'            => array( __CLASS__, 'core_update' ),
			'permission_callback' => Rest_Guard::ability_gate( 'core' ),
			'show_in_index'       => false,
		) );

		/* ---------- Theme files & functions ---------- */

		register_rest_route( $ns, '/cli/theme/files', array(
			'methods'             => \WP_REST_Server::READABLE,
			'callback'            => array( __CLASS__, 'theme_files' ),
			'permission_callback' => Rest_Guard::ability_gate( 'files' ),
			'show_in_index'       => false,
		) );

		// theme/file: GET (leitura) e POST (escrita com double-gate) separados.
		register_rest_route( $ns, '/cli/theme/file', array(
			'methods'             => \WP_REST_Server::READABLE,
			'callback'            => array( __CLASS__, 'theme_file_read' ),
			'permission_callback' => Rest_Guard::ability_gate( 'files' ),
			'show_in_index'       => false,
		) );

		register_rest_route( $ns, '/cli/theme/file', array(
			'methods'             => \WP_REST_Server::CREATABLE,
			'callback'            => array( __CLASS__, 'theme_file_write' ),
			'permission_callback' => Rest_Guard::ability_gate( 'files' ),
			'show_in_index'       => false,
		) );

		// theme/functions: GET e PUT separados.
		register_rest_route( $ns, '/cli/theme/functions', array(
			'methods'             => \WP_REST_Server::READABLE,
			'callback'            => array( __CLASS__, 'functions_read' ),
			'permission_callback' => Rest_Guard::ability_gate( 'files' ),
			'show_in_index'       => false,
		) );

		register_rest_route( $ns, '/cli/theme/functions', array(
			'methods'             => \WP_REST_Server::EDITABLE,
			'callback'            => array( __CLASS__, 'functions_write' ),
			'permission_callback' => Rest_Guard::ability_gate( 'files' ),
			'show_in_index'       => false,
		) );

		/* ---------- Snippets ---------- */

		register_rest_route( $ns, '/cli/snippets', array(
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'list_snippets' ),
				'permission_callback' => Rest_Guard::ability_gate( 'snippets' ),
				'show_in_index'       => false,
			),
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'create_snippet' ),
				'permission_callback' => Rest_Guard::ability_gate( 'snippets' ),
				'show_in_index'       => false,
			),
		) );

		register_rest_route( $ns, '/cli/snippets/(?P<id>\d+)', array(
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'get_snippet' ),
				'permission_callback' => Rest_Guard::ability_gate( 'snippets' ),
				'show_in_index'       => false,
			),
			array(
				'methods'             => \WP_REST_Server::EDITABLE,
				'callback'            => array( __CLASS__, 'update_snippet' ),
				'permission_callback' => Rest_Guard::ability_gate( 'snippets' ),
				'show_in_index'       => false,
			),
			array(
				'methods'             => \WP_REST_Server::DELETABLE,
				'callback'            => array( __CLASS__, 'delete_snippet' ),
				'permission_callback' => Rest_Guard::ability_gate( 'snippets' ),
				'show_in_index'       => false,
			),
		) );

		register_rest_route( $ns, '/cli/snippets/(?P<id>\d+)/toggle', array(
			'methods'             => \WP_REST_Server::CREATABLE,
			'callback'            => array( __CLASS__, 'toggle_snippet' ),
			'permission_callback' => Rest_Guard::ability_gate( 'snippets' ),
			'show_in_index'       => false,
		) );

		/* ---------- Users ---------- */

		register_rest_route( $ns, '/cli/users', array(
			'methods'             => \WP_REST_Server::READABLE,
			'callback'            => array( __CLASS__, 'list_users' ),
			'permission_callback' => Rest_Guard::ability_gate( 'read' ),
			'show_in_index'       => false,
		) );

		/* ---------- Logs ---------- */

		register_rest_route( $ns, '/cli/logs', array(
			'methods'             => \WP_REST_Server::READABLE,
			'callback'            => array( __CLASS__, 'list_logs' ),
			'permission_callback' => Rest_Guard::ability_gate( 'read' ),
			'show_in_index'       => false,
		) );

		/* ---------- Exec PHP (triplo gate: cli_on + allow_php_exec + ability exec) ---------- */

		register_rest_route( $ns, '/cli/exec/php', array(
			'methods'             => \WP_REST_Server::CREATABLE,
			'callback'            => array( __CLASS__, 'exec_php' ),
			'permission_callback' => Rest_Guard::ability_gate( 'exec' ),
			'show_in_index'       => false,
		) );

		/* ---------- Content ---------- */

		register_rest_route( $ns, '/cli/posts', array(
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'list_posts' ),
				'permission_callback' => Rest_Guard::ability_gate( 'content' ),
				'show_in_index'       => false,
			),
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'create_post' ),
				'permission_callback' => Rest_Guard::ability_gate( 'content' ),
				'show_in_index'       => false,
			),
		) );

		register_rest_route( $ns, '/cli/posts/(?P<id>\d+)', array(
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'get_post' ),
				'permission_callback' => Rest_Guard::ability_gate( 'content' ),
				'show_in_index'       => false,
			),
			array(
				'methods'             => \WP_REST_Server::EDITABLE,
				'callback'            => array( __CLASS__, 'update_post' ),
				'permission_callback' => Rest_Guard::ability_gate( 'content' ),
				'show_in_index'       => false,
			),
			array(
				'methods'             => \WP_REST_Server::DELETABLE,
				'callback'            => array( __CLASS__, 'delete_post' ),
				'permission_callback' => Rest_Guard::ability_gate( 'content' ),
				'show_in_index'       => false,
			),
		) );

		register_rest_route( $ns, '/cli/post-types', array(
			'methods'             => \WP_REST_Server::READABLE,
			'callback'            => array( __CLASS__, 'post_types' ),
			'permission_callback' => Rest_Guard::ability_gate( 'content' ),
			'show_in_index'       => false,
		) );

		register_rest_route( $ns, '/cli/taxonomies', array(
			'methods'             => \WP_REST_Server::READABLE,
			'callback'            => array( __CLASS__, 'taxonomies' ),
			'permission_callback' => Rest_Guard::ability_gate( 'content' ),
			'show_in_index'       => false,
		) );

		register_rest_route( $ns, '/cli/terms', array(
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'list_terms' ),
				'permission_callback' => Rest_Guard::ability_gate( 'content' ),
				'show_in_index'       => false,
			),
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'create_term' ),
				'permission_callback' => Rest_Guard::ability_gate( 'content' ),
				'show_in_index'       => false,
			),
		) );

		register_rest_route( $ns, '/cli/terms/(?P<id>\d+)', array(
			array(
				'methods'             => \WP_REST_Server::EDITABLE,
				'callback'            => array( __CLASS__, 'update_term' ),
				'permission_callback' => Rest_Guard::ability_gate( 'content' ),
				'show_in_index'       => false,
			),
			array(
				'methods'             => \WP_REST_Server::DELETABLE,
				'callback'            => array( __CLASS__, 'delete_term' ),
				'permission_callback' => Rest_Guard::ability_gate( 'content' ),
				'show_in_index'       => false,
			),
		) );

		register_rest_route( $ns, '/cli/comments', array(
			'methods'             => \WP_REST_Server::READABLE,
			'callback'            => array( __CLASS__, 'list_comments' ),
			'permission_callback' => Rest_Guard::ability_gate( 'content' ),
			'show_in_index'       => false,
		) );

		register_rest_route( $ns, '/cli/comments/(?P<id>\d+)/(?P<action>approve|unapprove|spam|trash|delete)', array(
			'methods'             => \WP_REST_Server::CREATABLE,
			'callback'            => array( __CLASS__, 'moderate_comment' ),
			'permission_callback' => Rest_Guard::ability_gate( 'content' ),
			'show_in_index'       => false,
		) );

		register_rest_route( $ns, '/cli/media', array(
			'methods'             => \WP_REST_Server::READABLE,
			'callback'            => array( __CLASS__, 'list_media' ),
			'permission_callback' => Rest_Guard::ability_gate( 'content' ),
			'show_in_index'       => false,
		) );

		/* ---------- DB Explorer ---------- */

		register_rest_route( $ns, '/cli/db/tables', array(
			'methods'             => \WP_REST_Server::READABLE,
			'callback'            => array( __CLASS__, 'db_tables' ),
			'permission_callback' => Rest_Guard::ability_gate( 'db' ),
			'show_in_index'       => false,
		) );

		register_rest_route( $ns, '/cli/db/tables/(?P<name>[A-Za-z0-9_]+)/schema', array(
			'methods'             => \WP_REST_Server::READABLE,
			'callback'            => array( __CLASS__, 'db_schema' ),
			'permission_callback' => Rest_Guard::ability_gate( 'db' ),
			'show_in_index'       => false,
		) );

		register_rest_route( $ns, '/cli/db/tables/(?P<name>[A-Za-z0-9_]+)/count', array(
			'methods'             => \WP_REST_Server::READABLE,
			'callback'            => array( __CLASS__, 'db_count' ),
			'permission_callback' => Rest_Guard::ability_gate( 'db' ),
			'show_in_index'       => false,
		) );

		register_rest_route( $ns, '/cli/db/tables/(?P<name>[A-Za-z0-9_]+)/sample', array(
			'methods'             => \WP_REST_Server::READABLE,
			'callback'            => array( __CLASS__, 'db_sample' ),
			'permission_callback' => Rest_Guard::ability_gate( 'db' ),
			'show_in_index'       => false,
		) );

		register_rest_route( $ns, '/cli/db/relations', array(
			'methods'             => \WP_REST_Server::READABLE,
			'callback'            => array( __CLASS__, 'db_relations' ),
			'permission_callback' => Rest_Guard::ability_gate( 'db' ),
			'show_in_index'       => false,
		) );

		register_rest_route( $ns, '/cli/db/query', array(
			'methods'             => \WP_REST_Server::CREATABLE,
			'callback'            => array( __CLASS__, 'db_query' ),
			'permission_callback' => Rest_Guard::ability_gate( 'db_query' ),
			'show_in_index'       => false,
		) );
	}

	/* ------------------------------------------------------------------ */
	/*  Master switch                                                       */
	/* ------------------------------------------------------------------ */

	/**
	 * Exige que o CLI geral esteja habilitado nas configuracoes.
	 * Chamar no topo de todo handler que nao seja discovery.
	 *
	 * @return true|\WP_Error
	 */
	private static function require_cli_enabled() {
		$settings = Rest_Guard::settings();
		if ( empty( $settings['enable_general_cli'] ) ) {
			return new \WP_Error(
				'mmcb_cli_disabled',
				'CLI geral de WordPress desativado nas configuracoes.',
				array( 'status' => 403 )
			);
		}
		return true;
	}

	/**
	 * Exige allow_php_exec para ATIVAR um snippet.
	 *
	 * Um snippet ativo roda PHP arbitrario em toda requisicao do WordPress — e
	 * execucao de PHP tanto quanto o /cli/exec/php, so que por outra porta. Sem
	 * esta trava, um token com a ability `snippets` (e sem `exec`) criava um
	 * snippet com active=1 e executava o que quisesse, mesmo com allow_php_exec
	 * desligado: a flag que o admin usa justamente para dizer "nao quero execucao
	 * de PHP" era contornada.
	 *
	 * Vale so para a ativacao: criar, ler, editar e apagar snippet inativo
	 * seguem exigindo apenas a ability `snippets`.
	 *
	 * @return true|\WP_Error
	 */
	private static function require_php_exec_to_activate() {
		$settings = Rest_Guard::settings();
		if ( empty( $settings['allow_php_exec'] ) ) {
			return new \WP_Error(
				'mmcb_php_exec_disabled',
				'Ativar um snippet exige a configuracao allow_php_exec ligada: snippet ativo executa PHP em toda requisicao.',
				array( 'status' => 403 )
			);
		}
		return true;
	}

	/* ------------------------------------------------------------------ */
	/*  Filesystem / admin includes                                         */
	/* ------------------------------------------------------------------ */

	private static function require_fs(): void {
		if ( ! function_exists( 'request_filesystem_credentials' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		if ( ! class_exists( 'Plugin_Upgrader' ) ) {
			require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
		}
		if ( ! class_exists( 'WP_Ajax_Upgrader_Skin' ) ) {
			require_once ABSPATH . 'wp-admin/includes/class-wp-ajax-upgrader-skin.php';
		}
		if ( ! function_exists( 'plugins_api' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin-install.php';
		}
		if ( ! function_exists( 'themes_api' ) ) {
			require_once ABSPATH . 'wp-admin/includes/theme.php';
		}
		if ( ! function_exists( 'WP_Filesystem' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		WP_Filesystem();
	}

	/* ------------------------------------------------------------------ */
	/*  Path-safety helpers (criticos — portados fielmente)                */
	/* ------------------------------------------------------------------ */

	/**
	 * Valida e resolve um caminho relativo dentro do diretorio do tema ativo.
	 * Bloqueia path traversal, NUL bytes e caminhos absolutos.
	 *
	 * @param string $relative Caminho relativo recebido da requisicao.
	 * @return string|\WP_Error Caminho absoluto validado ou erro.
	 */
	private static function safe_theme_path( string $relative ) {
		$relative = wp_normalize_path( $relative );
		$relative = ltrim( $relative, '/' );

		if (
			'' === $relative
			|| str_contains( $relative, "\0" )
			|| preg_match( '#(^|/)\.\.(/|$)#', $relative )
			|| preg_match( '#^[A-Za-z]:#', $relative )
			|| preg_match( '#^[\\\\/]#', $relative )
		) {
			return new \WP_Error( 'mmcb_path_traversal', 'Caminho inválido.', array( 'status' => 400 ) );
		}

		$theme_dir = wp_normalize_path( get_stylesheet_directory() );
		$theme_dir = rtrim( $theme_dir, '/' );
		$abs       = $theme_dir . '/' . $relative;

		if ( file_exists( $abs ) ) {
			$resolved      = wp_normalize_path( (string) realpath( $abs ) );
			$resolved_root = wp_normalize_path( (string) realpath( $theme_dir ) );
			if ( ! $resolved || ! $resolved_root || strpos( $resolved, $resolved_root . '/' ) !== 0 ) {
				return new \WP_Error( 'mmcb_path_traversal', 'Caminho fora do diretório do tema.', array( 'status' => 400 ) );
			}
			return $resolved;
		}

		// O arquivo nao existe: sobe ate o PRIMEIRO ancestral que existe e valida
		// ele. Checar so o pai imediato deixava um furo: com "a/b/c.php", se "a" e
		// um symlink pra fora do tema e "a/b" ainda nao existe, o pai imediato nao
		// existe, a checagem era pulada, e o theme_file_write() em seguida fazia
		// wp_mkdir_p() criando "b" dentro do symlink — gravando fora do tema.
		$ancestor = dirname( $abs );
		while ( ! file_exists( $ancestor ) && strlen( $ancestor ) > strlen( $theme_dir ) ) {
			$parent = dirname( $ancestor );
			if ( $parent === $ancestor ) {
				break; // Chegou na raiz do filesystem.
			}
			$ancestor = $parent;
		}

		$resolved_ancestor = wp_normalize_path( (string) realpath( $ancestor ) );
		$resolved_root     = wp_normalize_path( (string) realpath( $theme_dir ) );
		if (
			! $resolved_ancestor || ! $resolved_root
			|| ( $resolved_ancestor !== $resolved_root && strpos( $resolved_ancestor, $resolved_root . '/' ) !== 0 )
		) {
			return new \WP_Error( 'mmcb_path_traversal', 'Diretório fora do tema.', array( 'status' => 400 ) );
		}

		return $abs;
	}

	/**
	 * Valida o identificador de arquivo de plugin (plugin-slug/plugin-file.php).
	 * Bloqueia path traversal e caminhos absolutos.
	 *
	 * @param string $file Identificador recebido.
	 * @return string|\WP_Error
	 */
	private static function safe_plugin_file( string $file ) {
		$file = wp_normalize_path( $file );
		if (
			'' === $file
			|| str_contains( $file, "\0" )
			|| preg_match( '#(^|/)\.\.(/|$)#', $file )
			|| preg_match( '#^[\\\\/]#', $file )
			|| preg_match( '#^[A-Za-z]:#', $file )
		) {
			return new \WP_Error( 'mmcb_bad_file', 'Identificador de plugin inválido.', array( 'status' => 400 ) );
		}
		return $file;
	}

	/**
	 * Valida o stylesheet do tema (somente alfanumericos, hifens e underscores).
	 *
	 * @param string $stylesheet Stylesheet recebido.
	 * @return string|\WP_Error
	 */
	private static function safe_stylesheet( string $stylesheet ) {
		if ( ! preg_match( '/^[A-Za-z0-9_\-]+$/', $stylesheet ) ) {
			return new \WP_Error( 'mmcb_bad_stylesheet', 'Stylesheet inválido.', array( 'status' => 400 ) );
		}
		return $stylesheet;
	}

	/**
	 * Garante que o diretorio de backups exista e esteja protegido.
	 *
	 * @param string $dir Caminho absoluto do diretorio.
	 * @return void
	 */
	private static function ensure_protected_dir( string $dir ): void {
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
		}
		$htaccess = $dir . '/.htaccess';
		if ( ! file_exists( $htaccess ) ) {
			@file_put_contents( $htaccess, "Require all denied\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents, WordPress.PHP.NoSilencedErrors.Discouraged
		}
		$index = $dir . '/index.php';
		if ( ! file_exists( $index ) ) {
			@file_put_contents( $index, "<?php // silence is golden\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents, WordPress.PHP.NoSilencedErrors.Discouraged
		}
		$webconfig = $dir . '/web.config';
		if ( ! file_exists( $webconfig ) ) {
			@file_put_contents( $webconfig, "<?xml version=\"1.0\"?>\n<configuration><system.webServer><authorization><deny users=\"*\" /></authorization></system.webServer></configuration>\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents, WordPress.PHP.NoSilencedErrors.Discouraged
		}
	}

	/* ------------------------------------------------------------------ */
	/*  Discovery handlers (sem master switch)                             */
	/* ------------------------------------------------------------------ */

	/**
	 * GET /cli/status — health check + estado do CLI.
	 *
	 * @return \WP_REST_Response
	 */
	public static function status(): \WP_REST_Response {
		$settings = Rest_Guard::settings();
		return rest_ensure_response( array(
			'ok'          => true,
			'plugin'      => 'MarreiraMCP Builders',
			'version'     => MMCB_VERSION,
			'site_url'    => site_url(),
			'wp_version'  => get_bloginfo( 'version' ),
			'php'         => PHP_VERSION,
			'time'        => current_time( 'mysql' ),
			'cli_enabled' => ! empty( $settings['enable_general_cli'] ),
		) );
	}

	/**
	 * GET /cli/site — resumo do site.
	 *
	 * @return \WP_REST_Response
	 */
	public static function site_info(): \WP_REST_Response {
		global $wp_version;
		return rest_ensure_response( array(
			'name'        => get_bloginfo( 'name' ),
			'description' => get_bloginfo( 'description' ),
			'url'         => site_url(),
			'admin_email' => get_bloginfo( 'admin_email' ),
			'language'    => get_bloginfo( 'language' ),
			'wp_version'  => $wp_version,
			'php'         => PHP_VERSION,
			'mysql'       => $GLOBALS['wpdb']->db_version(),
			'multisite'   => is_multisite(),
			'theme'       => wp_get_theme()->get( 'Name' ),
			'memory'      => WP_MEMORY_LIMIT,
			'debug'       => defined( 'WP_DEBUG' ) && WP_DEBUG,
		) );
	}

	/**
	 * GET /cli/describe — auto-discovery para IAs; lista rotas, abilities e
	 * estado (habilitado/desabilitado) de cada endpoint.
	 *
	 * @return \WP_REST_Response
	 */
	public static function describe(): \WP_REST_Response {
		$settings = Rest_Guard::settings();
		$cli_on   = ! empty( $settings['enable_general_cli'] );
		$exec_on  = $cli_on && ! empty( $settings['allow_php_exec'] );
		$qry_on   = $cli_on && ! empty( $settings['allow_db_query'] );
		$fw_on    = $cli_on && ! empty( $settings['allow_file_write'] );
		$base     = trailingslashit( rest_url( MMCB_REST_NAMESPACE ) );

		$routes = array(
			array( 'method' => 'GET',    'path' => '/cli/status',                                          'ability' => null,       'enabled' => true ),
			array( 'method' => 'GET',    'path' => '/cli/site',                                            'ability' => null,       'enabled' => true ),
			array( 'method' => 'GET',    'path' => '/cli/describe',                                        'ability' => null,       'enabled' => true ),
			array( 'method' => 'GET',    'path' => '/cli/plugins',                                         'ability' => 'plugins',  'enabled' => $cli_on ),
			array( 'method' => 'POST',   'path' => '/cli/plugins/install',                                 'ability' => 'plugins',  'enabled' => $cli_on ),
			array( 'method' => 'POST',   'path' => '/cli/plugins/activate',                                'ability' => 'plugins',  'enabled' => $cli_on ),
			array( 'method' => 'POST',   'path' => '/cli/plugins/deactivate',                              'ability' => 'plugins',  'enabled' => $cli_on ),
			array( 'method' => 'POST',   'path' => '/cli/plugins/update',                                  'ability' => 'plugins',  'enabled' => $cli_on ),
			array( 'method' => 'POST',   'path' => '/cli/plugins/delete',                                  'ability' => 'plugins',  'enabled' => $cli_on ),
			array( 'method' => 'GET',    'path' => '/cli/themes',                                          'ability' => 'themes',   'enabled' => $cli_on ),
			array( 'method' => 'POST',   'path' => '/cli/themes/install',                                  'ability' => 'themes',   'enabled' => $cli_on ),
			array( 'method' => 'POST',   'path' => '/cli/themes/activate',                                 'ability' => 'themes',   'enabled' => $cli_on ),
			array( 'method' => 'POST',   'path' => '/cli/themes/update',                                   'ability' => 'themes',   'enabled' => $cli_on ),
			array( 'method' => 'POST',   'path' => '/cli/themes/delete',                                   'ability' => 'themes',   'enabled' => $cli_on ),
			array( 'method' => 'GET',    'path' => '/cli/core',                                            'ability' => 'core',     'enabled' => $cli_on ),
			array( 'method' => 'POST',   'path' => '/cli/core/update',                                     'ability' => 'core',     'enabled' => $cli_on ),
			array( 'method' => 'GET',    'path' => '/cli/theme/files',                                     'ability' => 'files',    'enabled' => $cli_on ),
			array( 'method' => 'GET',    'path' => '/cli/theme/file',                                      'ability' => 'files',    'enabled' => $cli_on ),
			array( 'method' => 'POST',   'path' => '/cli/theme/file',                                      'ability' => 'files',    'enabled' => $fw_on, 'note' => 'requer allow_file_write' ),
			array( 'method' => 'GET',    'path' => '/cli/theme/functions',                                 'ability' => 'files',    'enabled' => $cli_on ),
			array( 'method' => 'PUT',    'path' => '/cli/theme/functions',                                 'ability' => 'files',    'enabled' => $fw_on, 'note' => 'requer allow_file_write' ),
			array( 'method' => 'GET',    'path' => '/cli/snippets',                                        'ability' => 'snippets', 'enabled' => $cli_on ),
			array( 'method' => 'POST',   'path' => '/cli/snippets',                                        'ability' => 'snippets', 'enabled' => $cli_on ),
			array( 'method' => 'GET',    'path' => '/cli/snippets/{id}',                                   'ability' => 'snippets', 'enabled' => $cli_on ),
			array( 'method' => 'PUT',    'path' => '/cli/snippets/{id}',                                   'ability' => 'snippets', 'enabled' => $cli_on ),
			array( 'method' => 'DELETE', 'path' => '/cli/snippets/{id}',                                   'ability' => 'snippets', 'enabled' => $cli_on ),
			array( 'method' => 'POST',   'path' => '/cli/snippets/{id}/toggle',                            'ability' => 'snippets', 'enabled' => $cli_on ),
			array( 'method' => 'GET',    'path' => '/cli/users',                                           'ability' => 'read',     'enabled' => $cli_on ),
			array( 'method' => 'GET',    'path' => '/cli/logs',                                            'ability' => 'read',     'enabled' => $cli_on ),
			array( 'method' => 'POST',   'path' => '/cli/exec/php',                                        'ability' => 'exec',     'enabled' => $exec_on, 'note' => 'requer allow_php_exec' ),
			array( 'method' => 'GET',    'path' => '/cli/posts',                                           'ability' => 'content',  'enabled' => $cli_on ),
			array( 'method' => 'POST',   'path' => '/cli/posts',                                           'ability' => 'content',  'enabled' => $cli_on ),
			array( 'method' => 'GET',    'path' => '/cli/posts/{id}',                                      'ability' => 'content',  'enabled' => $cli_on ),
			array( 'method' => 'PUT',    'path' => '/cli/posts/{id}',                                      'ability' => 'content',  'enabled' => $cli_on ),
			array( 'method' => 'DELETE', 'path' => '/cli/posts/{id}',                                      'ability' => 'content',  'enabled' => $cli_on ),
			array( 'method' => 'GET',    'path' => '/cli/post-types',                                      'ability' => 'content',  'enabled' => $cli_on ),
			array( 'method' => 'GET',    'path' => '/cli/taxonomies',                                      'ability' => 'content',  'enabled' => $cli_on ),
			array( 'method' => 'GET',    'path' => '/cli/terms',                                           'ability' => 'content',  'enabled' => $cli_on ),
			array( 'method' => 'POST',   'path' => '/cli/terms',                                           'ability' => 'content',  'enabled' => $cli_on ),
			array( 'method' => 'PUT',    'path' => '/cli/terms/{id}',                                      'ability' => 'content',  'enabled' => $cli_on ),
			array( 'method' => 'DELETE', 'path' => '/cli/terms/{id}',                                      'ability' => 'content',  'enabled' => $cli_on ),
			array( 'method' => 'GET',    'path' => '/cli/comments',                                        'ability' => 'content',  'enabled' => $cli_on ),
			array( 'method' => 'POST',   'path' => '/cli/comments/{id}/{action}',                          'ability' => 'content',  'enabled' => $cli_on ),
			array( 'method' => 'GET',    'path' => '/cli/media',                                           'ability' => 'content',  'enabled' => $cli_on ),
			array( 'method' => 'GET',    'path' => '/cli/db/tables',                                       'ability' => 'db',       'enabled' => $cli_on ),
			array( 'method' => 'GET',    'path' => '/cli/db/tables/{name}/schema',                         'ability' => 'db',       'enabled' => $cli_on ),
			array( 'method' => 'GET',    'path' => '/cli/db/tables/{name}/count',                          'ability' => 'db',       'enabled' => $cli_on ),
			array( 'method' => 'GET',    'path' => '/cli/db/tables/{name}/sample',                         'ability' => 'db',       'enabled' => $cli_on ),
			array( 'method' => 'GET',    'path' => '/cli/db/relations',                                    'ability' => 'db',       'enabled' => $cli_on ),
			array( 'method' => 'POST',   'path' => '/cli/db/query',                                        'ability' => 'db_query', 'enabled' => $qry_on, 'note' => 'requer allow_db_query' ),
		);

		return rest_ensure_response( array(
			'plugin'     => 'MarreiraMCP Builders',
			'version'    => MMCB_VERSION,
			'site'       => site_url(),
			'base'       => $base,
			'cli_enabled' => $cli_on,
			'auth'       => array(
				'header' => 'Authorization: Bearer <token>',
				'alt'    => 'X-MMCB-Token: <token>',
			),
			'abilities'  => array( '*', 'plugins', 'themes', 'core', 'files', 'snippets', 'content', 'db', 'db_query', 'read', 'exec', 'builder', 'cli' ),
			'routes'     => $routes,
		) );
	}

	/* ------------------------------------------------------------------ */
	/*  Plugins                                                             */
	/* ------------------------------------------------------------------ */

	/**
	 * GET /cli/plugins — lista todos os plugins instalados.
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function list_plugins() {
		$check = self::require_cli_enabled();
		if ( is_wp_error( $check ) ) { return $check; }

		self::require_fs();
		$all     = get_plugins();
		$active  = (array) get_option( 'active_plugins', array() );
		$updates = get_site_transient( 'update_plugins' );
		$updates = $updates && isset( $updates->response ) ? $updates->response : array();

		$out = array();
		foreach ( $all as $file => $data ) {
			$out[] = array(
				'file'        => $file,
				'name'        => $data['Name'] ?? '',
				'version'     => $data['Version'] ?? '',
				'description' => wp_strip_all_tags( $data['Description'] ?? '' ),
				'author'      => wp_strip_all_tags( $data['Author'] ?? '' ),
				'plugin_uri'  => $data['PluginURI'] ?? '',
				'active'      => in_array( $file, $active, true ),
				'update'      => isset( $updates[ $file ] ) ? array(
					'new_version' => $updates[ $file ]->new_version ?? '',
					'package'     => $updates[ $file ]->package ?? '',
				) : null,
			);
		}
		return rest_ensure_response( array( 'plugins' => $out, 'total' => count( $out ) ) );
	}

	/**
	 * POST /cli/plugins/install — instala (e opcionalmente ativa) um plugin.
	 *
	 * @param \WP_REST_Request $r Requisicao.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function install_plugin( \WP_REST_Request $r ) {
		$check = self::require_cli_enabled();
		if ( is_wp_error( $check ) ) { return $check; }

		self::require_fs();
		$slug     = sanitize_key( (string) $r->get_param( 'slug' ) );
		$zip_url  = esc_url_raw( (string) $r->get_param( 'zip_url' ) );
		$activate = (bool) $r->get_param( 'activate' );

		if ( ! $slug && ! $zip_url ) {
			return new \WP_Error( 'mmcb_bad_request', 'Informe slug ou zip_url.', array( 'status' => 400 ) );
		}

		$package = $zip_url;
		if ( ! $package && $slug ) {
			$api = plugins_api( 'plugin_information', array( 'slug' => $slug, 'fields' => array( 'sections' => false ) ) );
			if ( is_wp_error( $api ) ) {
				return $api;
			}
			$package = $api->download_link ?? '';
		}
		if ( ! $package ) {
			return new \WP_Error( 'mmcb_no_package', 'Pacote não encontrado.', array( 'status' => 404 ) );
		}

		$skin     = new \WP_Ajax_Upgrader_Skin();
		$upgrader = new \Plugin_Upgrader( $skin );
		$result   = $upgrader->install( $package );

		if ( is_wp_error( $result ) ) {
			return $result;
		}
		if ( ! $result ) {
			return new \WP_Error( 'mmcb_install_failed', 'Falha ao instalar.', array( 'status' => 500, 'errors' => $skin->get_errors()->get_error_messages() ) );
		}

		$installed_file = $upgrader->plugin_info();

		if ( $activate && $installed_file ) {
			$res = activate_plugin( $installed_file );
			if ( is_wp_error( $res ) ) {
				return $res;
			}
		}

		return rest_ensure_response( array( 'installed' => true, 'file' => $installed_file, 'activated' => $activate ) );
	}

	/**
	 * POST /cli/plugins/activate — ativa um plugin instalado.
	 *
	 * @param \WP_REST_Request $r Requisicao.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function activate_plugin( \WP_REST_Request $r ) {
		$check = self::require_cli_enabled();
		if ( is_wp_error( $check ) ) { return $check; }

		self::require_fs();
		$file = self::safe_plugin_file( (string) $r->get_param( 'file' ) );
		if ( is_wp_error( $file ) ) { return $file; }
		if ( ! array_key_exists( $file, get_plugins() ) ) {
			return new \WP_Error( 'mmcb_not_found', 'Plugin não encontrado.', array( 'status' => 404 ) );
		}
		$res = activate_plugin( $file );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		return rest_ensure_response( array( 'activated' => true, 'file' => $file ) );
	}

	/**
	 * POST /cli/plugins/deactivate — desativa um plugin.
	 * Self-protection: bloqueia o proprio plugin (MMCB_PLUGIN_BASENAME).
	 *
	 * @param \WP_REST_Request $r Requisicao.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function deactivate_plugin( \WP_REST_Request $r ) {
		$check = self::require_cli_enabled();
		if ( is_wp_error( $check ) ) { return $check; }

		self::require_fs();
		$file = self::safe_plugin_file( (string) $r->get_param( 'file' ) );
		if ( is_wp_error( $file ) ) { return $file; }
		if ( ! array_key_exists( $file, get_plugins() ) ) {
			return new \WP_Error( 'mmcb_not_found', 'Plugin não encontrado.', array( 'status' => 404 ) );
		}
		// Self-protection.
		if ( $file === MMCB_PLUGIN_BASENAME ) {
			return new \WP_Error( 'mmcb_self_protection', 'Nao e permitido desativar/remover o proprio plugin.', array( 'status' => 403 ) );
		}
		deactivate_plugins( $file );
		return rest_ensure_response( array( 'deactivated' => true, 'file' => $file ) );
	}

	/**
	 * POST /cli/plugins/update — atualiza um plugin.
	 *
	 * @param \WP_REST_Request $r Requisicao.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function update_plugin( \WP_REST_Request $r ) {
		$check = self::require_cli_enabled();
		if ( is_wp_error( $check ) ) { return $check; }

		self::require_fs();
		$file = self::safe_plugin_file( (string) $r->get_param( 'file' ) );
		if ( is_wp_error( $file ) ) { return $file; }
		if ( ! array_key_exists( $file, get_plugins() ) ) {
			return new \WP_Error( 'mmcb_not_found', 'Plugin não encontrado.', array( 'status' => 404 ) );
		}
		wp_update_plugins();
		$skin     = new \WP_Ajax_Upgrader_Skin();
		$upgrader = new \Plugin_Upgrader( $skin );
		$result   = $upgrader->upgrade( $file );

		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return rest_ensure_response( array( 'updated' => (bool) $result, 'file' => $file ) );
	}

	/**
	 * POST /cli/plugins/delete — desativa e remove um plugin.
	 * Self-protection: bloqueia o proprio plugin (MMCB_PLUGIN_BASENAME).
	 *
	 * @param \WP_REST_Request $r Requisicao.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function delete_plugin( \WP_REST_Request $r ) {
		$check = self::require_cli_enabled();
		if ( is_wp_error( $check ) ) { return $check; }

		self::require_fs();
		$file = self::safe_plugin_file( (string) $r->get_param( 'file' ) );
		if ( is_wp_error( $file ) ) { return $file; }
		if ( ! array_key_exists( $file, get_plugins() ) ) {
			return new \WP_Error( 'mmcb_not_found', 'Plugin não encontrado.', array( 'status' => 404 ) );
		}
		// Self-protection.
		if ( $file === MMCB_PLUGIN_BASENAME ) {
			return new \WP_Error( 'mmcb_self_protection', 'Nao e permitido desativar/remover o proprio plugin.', array( 'status' => 403 ) );
		}
		deactivate_plugins( $file );
		$res = delete_plugins( array( $file ) );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		return rest_ensure_response( array( 'deleted' => true === $res, 'file' => $file ) );
	}

	/* ------------------------------------------------------------------ */
	/*  Themes                                                              */
	/* ------------------------------------------------------------------ */

	/**
	 * GET /cli/themes — lista todos os temas instalados.
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function list_themes() {
		$check = self::require_cli_enabled();
		if ( is_wp_error( $check ) ) { return $check; }

		$themes  = wp_get_themes();
		$active  = wp_get_theme();
		$updates = get_site_transient( 'update_themes' );
		$updates = $updates && isset( $updates->response ) ? $updates->response : array();

		$out = array();
		foreach ( $themes as $stylesheet => $theme ) {
			$out[] = array(
				'stylesheet'  => $stylesheet,
				'name'        => $theme->get( 'Name' ),
				'version'     => $theme->get( 'Version' ),
				'description' => wp_strip_all_tags( (string) $theme->get( 'Description' ) ),
				'author'      => wp_strip_all_tags( (string) $theme->get( 'Author' ) ),
				'parent'      => $theme->parent() ? $theme->parent()->get_stylesheet() : null,
				'active'      => $stylesheet === $active->get_stylesheet(),
				'update'      => $updates[ $stylesheet ] ?? null,
			);
		}
		return rest_ensure_response( array( 'themes' => $out, 'active' => $active->get_stylesheet() ) );
	}

	/**
	 * POST /cli/themes/install — instala (e opcionalmente ativa) um tema.
	 *
	 * @param \WP_REST_Request $r Requisicao.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function install_theme( \WP_REST_Request $r ) {
		$check = self::require_cli_enabled();
		if ( is_wp_error( $check ) ) { return $check; }

		self::require_fs();
		$slug     = sanitize_key( (string) $r->get_param( 'slug' ) );
		$zip_url  = esc_url_raw( (string) $r->get_param( 'zip_url' ) );
		$activate = (bool) $r->get_param( 'activate' );

		$package = $zip_url;
		if ( ! $package && $slug ) {
			$api = themes_api( 'theme_information', array( 'slug' => $slug ) );
			if ( is_wp_error( $api ) ) {
				return $api;
			}
			$package = $api->download_link ?? '';
		}
		if ( ! $package ) {
			return new \WP_Error( 'mmcb_no_package', 'Pacote não encontrado.', array( 'status' => 404 ) );
		}

		$skin     = new \WP_Ajax_Upgrader_Skin();
		$upgrader = new \Theme_Upgrader( $skin );
		$result   = $upgrader->install( $package );

		if ( is_wp_error( $result ) ) {
			return $result;
		}
		if ( ! $result ) {
			return new \WP_Error( 'mmcb_install_failed', 'Falha ao instalar tema.', array( 'status' => 500 ) );
		}

		$stylesheet = $upgrader->theme_info() ? $upgrader->theme_info()->get_stylesheet() : null;
		if ( $activate && $stylesheet ) {
			switch_theme( $stylesheet );
		}
		return rest_ensure_response( array( 'installed' => true, 'stylesheet' => $stylesheet, 'activated' => $activate ) );
	}

	/**
	 * POST /cli/themes/activate — ativa um tema instalado.
	 *
	 * @param \WP_REST_Request $r Requisicao.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function activate_theme( \WP_REST_Request $r ) {
		$check = self::require_cli_enabled();
		if ( is_wp_error( $check ) ) { return $check; }

		$stylesheet = self::safe_stylesheet( (string) $r->get_param( 'stylesheet' ) );
		if ( is_wp_error( $stylesheet ) ) { return $stylesheet; }
		$theme = wp_get_theme( $stylesheet );
		if ( ! $theme->exists() ) {
			return new \WP_Error( 'mmcb_not_found', 'Tema não encontrado.', array( 'status' => 404 ) );
		}
		switch_theme( $stylesheet );
		return rest_ensure_response( array( 'activated' => true, 'stylesheet' => $stylesheet ) );
	}

	/**
	 * POST /cli/themes/update — atualiza um tema.
	 *
	 * @param \WP_REST_Request $r Requisicao.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function update_theme( \WP_REST_Request $r ) {
		$check = self::require_cli_enabled();
		if ( is_wp_error( $check ) ) { return $check; }

		self::require_fs();
		$stylesheet = self::safe_stylesheet( (string) $r->get_param( 'stylesheet' ) );
		if ( is_wp_error( $stylesheet ) ) { return $stylesheet; }
		wp_update_themes();
		$skin     = new \WP_Ajax_Upgrader_Skin();
		$upgrader = new \Theme_Upgrader( $skin );
		$result   = $upgrader->upgrade( $stylesheet );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return rest_ensure_response( array( 'updated' => (bool) $result, 'stylesheet' => $stylesheet ) );
	}

	/**
	 * POST /cli/themes/delete — remove um tema.
	 * Self-protection: bloqueia o tema ativo (get_stylesheet / get_template).
	 *
	 * @param \WP_REST_Request $r Requisicao.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function delete_theme( \WP_REST_Request $r ) {
		$check = self::require_cli_enabled();
		if ( is_wp_error( $check ) ) { return $check; }

		self::require_fs();
		$stylesheet = self::safe_stylesheet( (string) $r->get_param( 'stylesheet' ) );
		if ( is_wp_error( $stylesheet ) ) { return $stylesheet; }
		// Self-protection: nao permite remover o tema ativo.
		if ( $stylesheet === get_stylesheet() || $stylesheet === get_template() ) {
			return new \WP_Error( 'mmcb_self_protection', 'Não é possível apagar o tema ativo.', array( 'status' => 403 ) );
		}
		$res = delete_theme( $stylesheet );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		return rest_ensure_response( array( 'deleted' => true === $res, 'stylesheet' => $stylesheet ) );
	}

	/* ------------------------------------------------------------------ */
	/*  Core                                                                */
	/* ------------------------------------------------------------------ */

	/**
	 * GET /cli/core — informacoes de versao e atualizacoes do nucleo.
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function core_info() {
		$check = self::require_cli_enabled();
		if ( is_wp_error( $check ) ) { return $check; }

		require_once ABSPATH . 'wp-admin/includes/update.php';
		wp_version_check();
		$updates = get_core_updates();
		return rest_ensure_response( array(
			'current' => get_bloginfo( 'version' ),
			'updates' => $updates,
		) );
	}

	/**
	 * POST /cli/core/update — atualiza o nucleo do WordPress.
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function core_update() {
		$check = self::require_cli_enabled();
		if ( is_wp_error( $check ) ) { return $check; }

		self::require_fs();
		require_once ABSPATH . 'wp-admin/includes/update.php';
		require_once ABSPATH . 'wp-admin/includes/class-core-upgrader.php';

		wp_version_check();
		$updates = get_core_updates();
		if ( empty( $updates ) || 'latest' === $updates[0]->response ) {
			return rest_ensure_response( array( 'updated' => false, 'message' => 'Já está na versão mais recente.' ) );
		}

		$skin     = new \WP_Ajax_Upgrader_Skin();
		$upgrader = new \Core_Upgrader( $skin );
		$result   = $upgrader->upgrade( $updates[0] );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return rest_ensure_response( array( 'updated' => true, 'version' => $result ) );
	}

	/* ------------------------------------------------------------------ */
	/*  Theme files & functions                                             */
	/* ------------------------------------------------------------------ */

	/**
	 * GET /cli/theme/files — lista arquivos do tema ativo.
	 *
	 * @param \WP_REST_Request $r Requisicao.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function theme_files( \WP_REST_Request $r ) {
		$check = self::require_cli_enabled();
		if ( is_wp_error( $check ) ) { return $check; }

		$base    = wp_normalize_path( get_stylesheet_directory() );
		$pattern = $base . '/{,*/,*/*/,*/*/*/}*.{php,css,js,json,txt,md,html}';

		$files = glob( $pattern, GLOB_BRACE );
		$out   = array();
		foreach ( (array) $files as $f ) {
			if ( is_file( $f ) ) {
				$out[] = array(
					'path'  => ltrim( str_replace( $base, '', wp_normalize_path( $f ) ), '/' ),
					'size'  => filesize( $f ),
					'mtime' => filemtime( $f ),
				);
			}
		}
		return rest_ensure_response( array( 'theme' => wp_get_theme()->get_stylesheet(), 'files' => $out ) );
	}

	/**
	 * GET /cli/theme/file — le o conteudo de um arquivo do tema.
	 *
	 * @param \WP_REST_Request $r Requisicao.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function theme_file_read( \WP_REST_Request $r ) {
		$check = self::require_cli_enabled();
		if ( is_wp_error( $check ) ) { return $check; }

		$path = (string) $r->get_param( 'path' );
		if ( ! $path ) {
			return new \WP_Error( 'mmcb_bad_request', 'path é obrigatório.', array( 'status' => 400 ) );
		}
		$abs = self::safe_theme_path( $path );
		if ( is_wp_error( $abs ) ) {
			return $abs;
		}
		if ( ! file_exists( $abs ) ) {
			return new \WP_Error( 'mmcb_not_found', 'Arquivo não encontrado.', array( 'status' => 404 ) );
		}
		$content = file_get_contents( $abs ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		return rest_ensure_response( array( 'path' => $path, 'content' => $content, 'size' => strlen( (string) $content ) ) );
	}

	/**
	 * POST /cli/theme/file — grava um arquivo no tema.
	 * Double-gate: require_cli_enabled + allow_file_write.
	 * PHP files passam por lint antes de gravar. Backup automatico.
	 *
	 * @param \WP_REST_Request $r Requisicao.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function theme_file_write( \WP_REST_Request $r ) {
		$check = self::require_cli_enabled();
		if ( is_wp_error( $check ) ) { return $check; }

		$settings = Rest_Guard::settings();
		if ( empty( $settings['allow_file_write'] ) ) {
			return new \WP_Error( 'mmcb_cli_file_write_disabled', 'Edição de arquivos do tema está desativada nas configurações.', array( 'status' => 403 ) );
		}

		$path    = (string) $r->get_param( 'path' );
		$content = (string) $r->get_param( 'content' );
		if ( ! $path ) {
			return new \WP_Error( 'mmcb_bad_request', 'path é obrigatório.', array( 'status' => 400 ) );
		}
		$abs = self::safe_theme_path( $path );
		if ( is_wp_error( $abs ) ) {
			return $abs;
		}

		// Backup antes de sobrescrever.
		$backup = null;
		if ( file_exists( $abs ) ) {
			$backup_dir = WP_CONTENT_DIR . '/mmcb-backups';
			self::ensure_protected_dir( $backup_dir );
			$backup = $backup_dir . '/' . sanitize_file_name( basename( $path ) ) . '.' . time() . '.bak';
			copy( $abs, $backup );
		}

		// Lint para .php.
		if ( str_ends_with( strtolower( $abs ), '.php' ) ) {
			$lint = Snippets::lint( $content );
			if ( ! $lint['ok'] ) {
				return new \WP_Error( 'mmcb_php_lint', 'Erro de sintaxe PHP: ' . ( $lint['error'] ?? '' ), array( 'status' => 400 ) );
			}
		}

		$dir = dirname( $abs );
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
		}

		$bytes = file_put_contents( $abs, $content ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		if ( false === $bytes ) {
			return new \WP_Error( 'mmcb_write_failed', 'Falha ao gravar arquivo.', array( 'status' => 500 ) );
		}
		return rest_ensure_response( array( 'written' => true, 'path' => $path, 'bytes' => $bytes, 'backup' => $backup ? basename( $backup ) : null ) );
	}

	/**
	 * GET /cli/theme/functions — le o functions.php do tema ativo.
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function functions_read() {
		$check = self::require_cli_enabled();
		if ( is_wp_error( $check ) ) { return $check; }

		$abs = self::safe_theme_path( 'functions.php' );
		if ( is_wp_error( $abs ) ) {
			return $abs;
		}
		$content = file_exists( $abs ) ? file_get_contents( $abs ) : ''; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		return rest_ensure_response( array( 'content' => $content, 'size' => strlen( (string) $content ) ) );
	}

	/**
	 * PUT /cli/theme/functions — grava o functions.php do tema ativo.
	 * Delega para theme_file_write (inclui double-gate allow_file_write + lint).
	 *
	 * @param \WP_REST_Request $r Requisicao.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function functions_write( \WP_REST_Request $r ) {
		$check = self::require_cli_enabled();
		if ( is_wp_error( $check ) ) { return $check; }

		$r->set_param( 'path', 'functions.php' );
		return self::theme_file_write( $r );
	}

	/* ------------------------------------------------------------------ */
	/*  Snippets                                                            */
	/* ------------------------------------------------------------------ */

	/**
	 * GET /cli/snippets — lista todos os snippets.
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function list_snippets() {
		$check = self::require_cli_enabled();
		if ( is_wp_error( $check ) ) { return $check; }

		return rest_ensure_response( array( 'snippets' => Snippets::all() ) );
	}

	/**
	 * GET /cli/snippets/{id} — retorna um snippet.
	 *
	 * @param \WP_REST_Request $r Requisicao.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function get_snippet( \WP_REST_Request $r ) {
		$check = self::require_cli_enabled();
		if ( is_wp_error( $check ) ) { return $check; }

		$row = Snippets::get( (int) $r['id'] );
		if ( ! $row ) {
			return new \WP_Error( 'mmcb_not_found', 'Snippet não encontrado.', array( 'status' => 404 ) );
		}
		return rest_ensure_response( $row );
	}

	/**
	 * POST /cli/snippets — cria um snippet (lint obrigatorio).
	 *
	 * @param \WP_REST_Request $r Requisicao.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function create_snippet( \WP_REST_Request $r ) {
		$check = self::require_cli_enabled();
		if ( is_wp_error( $check ) ) { return $check; }

		$data = $r->get_json_params() ?: $r->get_params();
		if ( ! empty( $data['active'] ) ) {
			$gate = self::require_php_exec_to_activate();
			if ( is_wp_error( $gate ) ) { return $gate; }
		}
		if ( ! empty( $data['code'] ) ) {
			$lint = Snippets::lint( (string) $data['code'] );
			if ( ! $lint['ok'] ) {
				return new \WP_Error( 'mmcb_php_lint', 'Erro de sintaxe PHP: ' . ( $lint['error'] ?? '' ), array( 'status' => 400 ) );
			}
		}
		$id = Snippets::create( $data );
		return rest_ensure_response( Snippets::get( $id ) );
	}

	/**
	 * PUT /cli/snippets/{id} — atualiza um snippet (lint obrigatorio se code enviado).
	 *
	 * @param \WP_REST_Request $r Requisicao.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function update_snippet( \WP_REST_Request $r ) {
		$check = self::require_cli_enabled();
		if ( is_wp_error( $check ) ) { return $check; }

		$id   = (int) $r['id'];
		$data = $r->get_json_params() ?: $r->get_params();
		if ( ! empty( $data['active'] ) ) {
			$gate = self::require_php_exec_to_activate();
			if ( is_wp_error( $gate ) ) { return $gate; }
		}
		if ( ! empty( $data['code'] ) ) {
			$lint = Snippets::lint( (string) $data['code'] );
			if ( ! $lint['ok'] ) {
				return new \WP_Error( 'mmcb_php_lint', 'Erro de sintaxe PHP: ' . ( $lint['error'] ?? '' ), array( 'status' => 400 ) );
			}
		}
		Snippets::update( $id, $data );
		return rest_ensure_response( Snippets::get( $id ) );
	}

	/**
	 * DELETE /cli/snippets/{id} — remove um snippet.
	 *
	 * @param \WP_REST_Request $r Requisicao.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function delete_snippet( \WP_REST_Request $r ) {
		$check = self::require_cli_enabled();
		if ( is_wp_error( $check ) ) { return $check; }

		Snippets::delete( (int) $r['id'] );
		return rest_ensure_response( array( 'deleted' => true ) );
	}

	/**
	 * POST /cli/snippets/{id}/toggle — liga/desliga um snippet.
	 *
	 * @param \WP_REST_Request $r Requisicao.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function toggle_snippet( \WP_REST_Request $r ) {
		$check = self::require_cli_enabled();
		if ( is_wp_error( $check ) ) { return $check; }

		$row = Snippets::get( (int) $r['id'] );
		if ( ! $row ) {
			return new \WP_Error( 'mmcb_not_found', 'Snippet não encontrado.', array( 'status' => 404 ) );
		}
		$turning_on = empty( $row['active'] );
		if ( $turning_on ) {
			$gate = self::require_php_exec_to_activate();
			if ( is_wp_error( $gate ) ) { return $gate; }
		}
		Snippets::update( (int) $row['id'], array( 'active' => $turning_on ? 1 : 0 ) );
		return rest_ensure_response( Snippets::get( (int) $row['id'] ) );
	}

	/* ------------------------------------------------------------------ */
	/*  Users                                                               */
	/* ------------------------------------------------------------------ */

	/**
	 * GET /cli/users — lista usuarios paginados.
	 *
	 * @param \WP_REST_Request $r Requisicao.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function list_users( \WP_REST_Request $r ) {
		$check = self::require_cli_enabled();
		if ( is_wp_error( $check ) ) { return $check; }

		$per_page = max( 1, min( 100, (int) ( $r->get_param( 'per_page' ) ?: 20 ) ) );
		$page     = max( 1, (int) ( $r->get_param( 'page' ) ?: 1 ) );
		$users    = get_users( array( 'number' => $per_page, 'paged' => $page, 'fields' => array( 'ID', 'user_login', 'user_email', 'display_name' ) ) );
		$out      = array();
		foreach ( $users as $u ) {
			$wp_user = get_user_by( 'id', $u->ID );
			$out[]   = array(
				'id'    => (int) $u->ID,
				'login' => $u->user_login,
				'email' => $u->user_email,
				'name'  => $u->display_name,
				'roles' => $wp_user ? $wp_user->roles : array(),
			);
		}
		return rest_ensure_response( array( 'users' => $out ) );
	}

	/* ------------------------------------------------------------------ */
	/*  Logs                                                                */
	/* ------------------------------------------------------------------ */

	/**
	 * GET /cli/logs — retorna o audit log paginado (via Audit_Log).
	 *
	 * @param \WP_REST_Request $r Requisicao.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function list_logs( \WP_REST_Request $r ) {
		$check = self::require_cli_enabled();
		if ( is_wp_error( $check ) ) { return $check; }

		$result = Audit_Log::get_logs( array(
			'per_page' => (int) ( $r->get_param( 'per_page' ) ?: 50 ),
			'page'     => (int) ( $r->get_param( 'page' ) ?: 1 ),
		) );
		return rest_ensure_response( array(
			'logs'  => $result['items'],
			'total' => $result['total'],
		) );
	}

	/* ------------------------------------------------------------------ */
	/*  Exec PHP (triplo gate: cli_on + allow_php_exec + ability exec)     */
	/* ------------------------------------------------------------------ */

	/**
	 * POST /cli/exec/php — executa PHP arbitrario no contexto do WP.
	 *
	 * Triplo gate:
	 *  1. require_cli_enabled()  — master switch.
	 *  2. allow_php_exec setting — feature switch.
	 *  3. Rest_Guard::require_ability('exec') — revalida o escopo do token.
	 *
	 * @param \WP_REST_Request $r Requisicao.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function exec_php( \WP_REST_Request $r ) {
		$check = self::require_cli_enabled();
		if ( is_wp_error( $check ) ) { return $check; }

		$settings = Rest_Guard::settings();
		if ( empty( $settings['allow_php_exec'] ) ) {
			return new \WP_Error( 'mmcb_php_exec_disabled', 'Execução de PHP arbitrário está desativada. Habilite em Configurações.', array( 'status' => 403 ) );
		}

		// Revalida a ability no handler (belt-and-suspenders).
		$gate = Rest_Guard::require_ability( 'exec' );
		if ( is_wp_error( $gate ) ) { return $gate; }

		$code = (string) $r->get_param( 'code' );
		if ( '' === trim( $code ) ) {
			return new \WP_Error( 'mmcb_bad_request', 'code é obrigatório.', array( 'status' => 400 ) );
		}

		$lint = Snippets::lint( $code );
		if ( ! $lint['ok'] ) {
			return new \WP_Error( 'mmcb_php_lint', $lint['error'] ?? 'Erro de sintaxe.', array( 'status' => 400 ) );
		}

		$code = preg_replace( '/^\s*<\?php/i', '', $code );
		$code = preg_replace( '/\?>\s*$/', '', (string) $code );

		ob_start();
		$result = null;
		$error  = null;
		try {
			$result = eval( $code ); // phpcs:ignore Squiz.PHP.Eval.Discouraged
		} catch ( \Throwable $e ) {
			$error = $e->getMessage();
		}
		$output = ob_get_clean();

		return rest_ensure_response( array(
			'output' => $output,
			'return' => $result,
			'error'  => $error,
		) );
	}

	/* ------------------------------------------------------------------ */
	/*  Content (posts, termos, comentarios, midia)                        */
	/* ------------------------------------------------------------------ */

	/** @return \WP_REST_Response|\WP_Error */
	public static function list_posts( \WP_REST_Request $r ) {
		$check = self::require_cli_enabled();
		if ( is_wp_error( $check ) ) { return $check; }
		return rest_ensure_response( Content::list_posts( $r->get_params() ) );
	}

	/** @return \WP_REST_Response|\WP_Error */
	public static function get_post( \WP_REST_Request $r ) {
		$check = self::require_cli_enabled();
		if ( is_wp_error( $check ) ) { return $check; }
		$res = Content::get_post( (int) $r['id'] );
		return is_wp_error( $res ) ? $res : rest_ensure_response( $res );
	}

	/** @return \WP_REST_Response|\WP_Error */
	public static function create_post( \WP_REST_Request $r ) {
		$check = self::require_cli_enabled();
		if ( is_wp_error( $check ) ) { return $check; }
		$res = Content::create_post( $r->get_json_params() ?: $r->get_params() );
		return is_wp_error( $res ) ? $res : rest_ensure_response( $res );
	}

	/** @return \WP_REST_Response|\WP_Error */
	public static function update_post( \WP_REST_Request $r ) {
		$check = self::require_cli_enabled();
		if ( is_wp_error( $check ) ) { return $check; }
		$res = Content::update_post( (int) $r['id'], $r->get_json_params() ?: $r->get_params() );
		return is_wp_error( $res ) ? $res : rest_ensure_response( $res );
	}

	/** @return \WP_REST_Response|\WP_Error */
	public static function delete_post( \WP_REST_Request $r ) {
		$check = self::require_cli_enabled();
		if ( is_wp_error( $check ) ) { return $check; }
		$force = (bool) $r->get_param( 'force' );
		$res   = Content::delete_post( (int) $r['id'], $force );
		return is_wp_error( $res ) ? $res : rest_ensure_response( $res );
	}

	/** @return \WP_REST_Response|\WP_Error */
	public static function post_types() {
		$check = self::require_cli_enabled();
		if ( is_wp_error( $check ) ) { return $check; }
		return rest_ensure_response( array( 'post_types' => Content::list_post_types() ) );
	}

	/** @return \WP_REST_Response|\WP_Error */
	public static function taxonomies() {
		$check = self::require_cli_enabled();
		if ( is_wp_error( $check ) ) { return $check; }
		return rest_ensure_response( array( 'taxonomies' => Content::list_taxonomies() ) );
	}

	/** @return \WP_REST_Response|\WP_Error */
	public static function list_terms( \WP_REST_Request $r ) {
		$check = self::require_cli_enabled();
		if ( is_wp_error( $check ) ) { return $check; }
		return rest_ensure_response( Content::list_terms( $r->get_params() ) );
	}

	/** @return \WP_REST_Response|\WP_Error */
	public static function create_term( \WP_REST_Request $r ) {
		$check = self::require_cli_enabled();
		if ( is_wp_error( $check ) ) { return $check; }
		$res = Content::create_term( $r->get_json_params() ?: $r->get_params() );
		return is_wp_error( $res ) ? $res : rest_ensure_response( $res );
	}

	/** @return \WP_REST_Response|\WP_Error */
	public static function update_term( \WP_REST_Request $r ) {
		$check = self::require_cli_enabled();
		if ( is_wp_error( $check ) ) { return $check; }
		$res = Content::update_term( (int) $r['id'], $r->get_json_params() ?: $r->get_params() );
		return is_wp_error( $res ) ? $res : rest_ensure_response( $res );
	}

	/** @return \WP_REST_Response|\WP_Error */
	public static function delete_term( \WP_REST_Request $r ) {
		$check = self::require_cli_enabled();
		if ( is_wp_error( $check ) ) { return $check; }
		$res = Content::delete_term( (int) $r['id'] );
		return is_wp_error( $res ) ? $res : rest_ensure_response( $res );
	}

	/** @return \WP_REST_Response|\WP_Error */
	public static function list_comments( \WP_REST_Request $r ) {
		$check = self::require_cli_enabled();
		if ( is_wp_error( $check ) ) { return $check; }
		return rest_ensure_response( Content::list_comments( $r->get_params() ) );
	}

	/** @return \WP_REST_Response|\WP_Error */
	public static function moderate_comment( \WP_REST_Request $r ) {
		$check = self::require_cli_enabled();
		if ( is_wp_error( $check ) ) { return $check; }
		$res = Content::moderate_comment( (int) $r['id'], (string) $r['action'] );
		return is_wp_error( $res ) ? $res : rest_ensure_response( $res );
	}

	/** @return \WP_REST_Response|\WP_Error */
	public static function list_media( \WP_REST_Request $r ) {
		$check = self::require_cli_enabled();
		if ( is_wp_error( $check ) ) { return $check; }
		return rest_ensure_response( Content::list_media( $r->get_params() ) );
	}

	/* ------------------------------------------------------------------ */
	/*  DB Explorer                                                         */
	/* ------------------------------------------------------------------ */

	/** @return \WP_REST_Response|\WP_Error */
	public static function db_tables() {
		$check = self::require_cli_enabled();
		if ( is_wp_error( $check ) ) { return $check; }
		return rest_ensure_response( array( 'tables' => DB_Explorer::tables() ) );
	}

	/** @return \WP_REST_Response|\WP_Error */
	public static function db_schema( \WP_REST_Request $r ) {
		$check = self::require_cli_enabled();
		if ( is_wp_error( $check ) ) { return $check; }
		$res = DB_Explorer::schema( (string) $r['name'] );
		return is_wp_error( $res ) ? $res : rest_ensure_response( $res );
	}

	/** @return \WP_REST_Response|\WP_Error */
	public static function db_count( \WP_REST_Request $r ) {
		$check = self::require_cli_enabled();
		if ( is_wp_error( $check ) ) { return $check; }
		$res = DB_Explorer::count( (string) $r['name'] );
		return is_wp_error( $res ) ? $res : rest_ensure_response( $res );
	}

	/** @return \WP_REST_Response|\WP_Error */
	public static function db_sample( \WP_REST_Request $r ) {
		$check = self::require_cli_enabled();
		if ( is_wp_error( $check ) ) { return $check; }
		$res = DB_Explorer::sample( (string) $r['name'], (int) ( $r->get_param( 'limit' ) ?: 10 ), (int) ( $r->get_param( 'offset' ) ?: 0 ) );
		return is_wp_error( $res ) ? $res : rest_ensure_response( $res );
	}

	/** @return \WP_REST_Response|\WP_Error */
	public static function db_relations() {
		$check = self::require_cli_enabled();
		if ( is_wp_error( $check ) ) { return $check; }
		return rest_ensure_response( array( 'relations' => DB_Explorer::relations_map() ) );
	}

	/**
	 * POST /cli/db/query — executa SELECT seguro.
	 * DB_Explorer::safe_query verifica allow_db_query internamente e retorna
	 * WP_Error 403 quando desabilitado.
	 *
	 * @param \WP_REST_Request $r Requisicao.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function db_query( \WP_REST_Request $r ) {
		$check = self::require_cli_enabled();
		if ( is_wp_error( $check ) ) { return $check; }

		$sql  = (string) $r->get_param( 'sql' );
		$args = (array) ( $r->get_param( 'args' ) ?? array() );
		if ( '' === trim( $sql ) ) {
			return new \WP_Error( 'mmcb_bad_request', 'Parâmetro "sql" é obrigatório.', array( 'status' => 400 ) );
		}
		// safe_query checa allow_db_query e retorna WP_Error 403 se desabilitado.
		$res = DB_Explorer::safe_query( $sql, $args );
		return is_wp_error( $res ) ? $res : rest_ensure_response( $res );
	}
}
