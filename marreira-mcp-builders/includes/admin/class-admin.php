<?php
/**
 * Admin: menu proprio (top-level) + painel 100% AJAX (SPA).
 *
 * @package Marreira\MCP_Builders
 */

namespace Marreira\MCP_Builders\Admin;

use Marreira\MCP_Builders\Activator;
use Marreira\MCP_Builders\Auth\Token_Manager;
use Marreira\MCP_Builders\Builders\Builder_Manager;
use Marreira\MCP_Builders\MCP\MCP_Server;
use Marreira\MCP_Builders\OAuth\Client_Manager;
use Marreira\MCP_Builders\Security\Audit_Log;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Painel administrativo com menu proprio e interacoes via AJAX (admin-ajax),
 * sem recarregar a pagina. Inclui wizard de onboarding e abas de gerenciamento.
 */
class Admin {

	/**
	 * Slug do menu.
	 *
	 * @var string
	 */
	const MENU_SLUG = 'marreira-mcp-builders';

	/**
	 * Nome do nonce AJAX.
	 *
	 * @var string
	 */
	const NONCE = 'mmcb_admin';

	/**
	 * Capability necessaria.
	 *
	 * @var string
	 */
	const CAP = 'manage_options';

	/**
	 * Hook da pagina (para carregar assets so nela).
	 *
	 * @var string
	 */
	private $page_hook = '';

	/**
	 * Registra hooks de admin e AJAX.
	 *
	 * @return void
	 */
	public function register_hooks() {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );

		add_action( 'wp_ajax_mmcb_status', array( $this, 'ajax_status' ) );
		add_action( 'wp_ajax_mmcb_complete_onboarding', array( $this, 'ajax_complete_onboarding' ) );
		add_action( 'wp_ajax_mmcb_save_settings', array( $this, 'ajax_save_settings' ) );
		add_action( 'wp_ajax_mmcb_switch_builder', array( $this, 'ajax_switch_builder' ) );
		add_action( 'wp_ajax_mmcb_set_tier', array( $this, 'ajax_set_tier' ) );
		add_action( 'wp_ajax_mmcb_generate_token', array( $this, 'ajax_generate_token' ) );
		add_action( 'wp_ajax_mmcb_list_tokens', array( $this, 'ajax_list_tokens' ) );
		add_action( 'wp_ajax_mmcb_revoke_token', array( $this, 'ajax_revoke_token' ) );
		add_action( 'wp_ajax_mmcb_activate_token', array( $this, 'ajax_activate_token' ) );
		add_action( 'wp_ajax_mmcb_delete_token', array( $this, 'ajax_delete_token' ) );
		add_action( 'wp_ajax_mmcb_logs', array( $this, 'ajax_logs' ) );
		add_action( 'wp_ajax_mmcb_selftest', array( $this, 'ajax_selftest' ) );
		add_action( 'wp_ajax_mmcb_list_oauth_clients', array( $this, 'ajax_list_oauth_clients' ) );
		add_action( 'wp_ajax_mmcb_approve_oauth_client', array( $this, 'ajax_approve_oauth_client' ) );
		add_action( 'wp_ajax_mmcb_revoke_oauth_client', array( $this, 'ajax_revoke_oauth_client' ) );
	}

	/**
	 * Cria o menu de topo proprio + submenu.
	 *
	 * @return void
	 */
	public function add_menu() {
		$this->page_hook = add_menu_page(
			__( 'MarreiraMCP Builders', 'marreira-mcp-builders' ),
			__( 'MarreiraMCP', 'marreira-mcp-builders' ),
			self::CAP,
			self::MENU_SLUG,
			array( $this, 'render' ),
			$this->menu_icon(),
			58
		);

		add_submenu_page(
			self::MENU_SLUG,
			__( 'Painel', 'marreira-mcp-builders' ),
			__( 'Painel', 'marreira-mcp-builders' ),
			self::CAP,
			self::MENU_SLUG,
			array( $this, 'render' )
		);
	}

	/**
	 * Icone do menu (SVG inline em data URI).
	 *
	 * @return string
	 */
	private function menu_icon() {
		$svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="#a7aaad">'
			. '<rect x="1" y="1" width="8" height="8" rx="1.5"/>'
			. '<rect x="11" y="1" width="8" height="8" rx="1.5"/>'
			. '<rect x="1" y="11" width="8" height="8" rx="1.5"/>'
			. '<rect x="11" y="11" width="8" height="8" rx="1.5"/></svg>';
		return 'data:image/svg+xml;base64,' . base64_encode( $svg );
	}

	/**
	 * Enfileira CSS/JS apenas na nossa pagina.
	 *
	 * @param string $hook Hook atual.
	 * @return void
	 */
	public function enqueue_assets( $hook ) {
		if ( $hook !== $this->page_hook ) {
			return;
		}
		wp_enqueue_style( 'mmcb-admin', MMCB_PLUGIN_URL . 'assets/admin.css', array(), MMCB_VERSION );
		wp_enqueue_script( 'mmcb-admin', MMCB_PLUGIN_URL . 'assets/admin.js', array(), MMCB_VERSION, true );
		wp_localize_script(
			'mmcb-admin',
			'MMCB',
			array(
				'ajaxurl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( self::NONCE ),
			)
		);
	}

	/**
	 * Shell da SPA. Todo o conteudo e renderizado pelo JS via AJAX.
	 *
	 * @return void
	 */
	public function render() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'Acesso negado.', 'marreira-mcp-builders' ) );
		}
		echo '<div class="mmcb-app" id="mmcb-app"><div class="mmcb-loading">' . esc_html__( 'Carregando painel…', 'marreira-mcp-builders' ) . '</div></div>';
	}

	// -------------------------------------------------------------------------
	// AJAX — helpers internos
	// -------------------------------------------------------------------------

	/**
	 * Valida nonce + capability em toda chamada AJAX.
	 *
	 * @return void
	 */
	private function verify() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_send_json_error( array( 'message' => __( 'Acesso negado.', 'marreira-mcp-builders' ) ), 403 );
		}
		check_ajax_referer( self::NONCE );
	}

	/**
	 * Monta o payload de status completo do painel.
	 *
	 * @return array
	 */
	private function status_payload() {
		$settings = wp_parse_args( get_option( Activator::SETTINGS_OPTION, array() ), Activator::default_settings() );

		// Catalogo de drivers conhecidos.
		$builders = array();
		foreach ( Builder_Manager::all() as $driver ) {
			$builders[] = array(
				'slug'      => $driver->slug(),
				'label'     => $driver->label(),
				'is_active' => (bool) $driver->is_active(),
				'version'   => $driver->version(),
			);
		}

		$registry = MCP_Server::build_registry();
		$defs     = $registry->definitions();

		return array(
			'plugin_version'     => MMCB_VERSION,
			'active_builder'     => (string) $settings['active_builder'],
			'available_builders' => Builder_Manager::detect_available(),
			'builders'           => $builders,
			'ai_tier'            => (string) $settings['ai_tier'],
			'onboarding_done'    => ! empty( $settings['onboarding_done'] ),
			'endpoints'          => array(
				'mcp'      => esc_url_raw( rest_url( MMCB_REST_NAMESPACE . MMCB_REST_ROUTE ) ),
				'skill'    => esc_url_raw( rest_url( MMCB_REST_NAMESPACE . '/skill' ) ),
				'describe' => esc_url_raw( rest_url( MMCB_REST_NAMESPACE . '/describe' ) ),
				'cli'      => esc_url_raw( rest_url( MMCB_REST_NAMESPACE . '/cli' ) ),
			),
			'settings'           => array(
				'active_builder'     => (string) $settings['active_builder'],
				'ai_tier'            => (string) $settings['ai_tier'],
				'https_only'         => ! empty( $settings['https_only'] ),
				'rate_limit'         => (int) $settings['rate_limit'],
				'rate_window'        => (int) $settings['rate_window'],
				'block_code'         => ! empty( $settings['block_code'] ),
				'service_user_id'    => (int) $settings['service_user_id'],
				'enable_general_cli' => ! empty( $settings['enable_general_cli'] ),
				'allow_php_exec'     => ! empty( $settings['allow_php_exec'] ),
				'allow_db_query'     => ! empty( $settings['allow_db_query'] ),
				'allow_file_write'   => ! empty( $settings['allow_file_write'] ),
				'allowed_ips'        => (string) $settings['allowed_ips'],
				'log_retention_days' => (int) $settings['log_retention_days'],
				'db_blacklist'       => isset( $settings['db_blacklist'] ) ? (string) $settings['db_blacklist'] : '',
				'enable_oauth'       => ! empty( $settings['enable_oauth'] ),
				'oauth_auto_approve' => ! empty( $settings['oauth_auto_approve'] ),
			),
			'oauth'              => array(
				'enabled'      => ! empty( $settings['enable_oauth'] ),
				'auto_approve' => ! empty( $settings['oauth_auto_approve'] ),
				'pending'      => Client_Manager::count_pending(),
				'endpoints'    => array(
					'protected_resource'   => esc_url_raw( home_url( '/.well-known/oauth-protected-resource' ) ),
					'authorization_server' => esc_url_raw( home_url( '/.well-known/oauth-authorization-server' ) ),
					'register'             => esc_url_raw( home_url( MMCB_OAUTH_BASE_PATH . '/register' ) ),
					'authorize'            => esc_url_raw( home_url( MMCB_OAUTH_BASE_PATH . '/authorize' ) ),
					'token'                => esc_url_raw( home_url( MMCB_OAUTH_BASE_PATH . '/token' ) ),
				),
			),
			'token_count'        => Token_Manager::count_active(),
			'tools'              => $defs,
			'tools_count'        => count( $defs ),
		);
	}

	// -------------------------------------------------------------------------
	// AJAX — handlers publicos
	// -------------------------------------------------------------------------

	/**
	 * AJAX: status do painel.
	 *
	 * @return void
	 */
	public function ajax_status() {
		$this->verify();
		wp_send_json_success( $this->status_payload() );
	}

	/**
	 * AJAX: conclui o onboarding definindo builder + tier.
	 *
	 * @return void
	 */
	public function ajax_complete_onboarding() {
		$this->verify();

		$builder = isset( $_POST['builder'] ) ? sanitize_key( wp_unslash( $_POST['builder'] ) ) : '';
		$tier    = isset( $_POST['tier'] ) ? sanitize_key( wp_unslash( $_POST['tier'] ) ) : '';

		if ( ! in_array( $builder, Builder_Manager::SLUGS, true ) ) {
			wp_send_json_error( array( 'message' => 'Builder inválido.' ) );
		}
		if ( ! in_array( $tier, array( 'premium', 'economy' ), true ) ) {
			wp_send_json_error( array( 'message' => 'Tier inválido.' ) );
		}

		$settings                    = wp_parse_args( get_option( Activator::SETTINGS_OPTION, array() ), Activator::default_settings() );
		$settings['active_builder']  = $builder;
		$settings['ai_tier']         = $tier;
		$settings['onboarding_done'] = true;
		update_option( Activator::SETTINGS_OPTION, $settings, false );

		wp_send_json_success( $this->status_payload() );
	}

	/**
	 * AJAX: salvar configuracoes editaveis.
	 *
	 * @return void
	 */
	public function ajax_save_settings() {
		$this->verify();

		$settings = wp_parse_args( get_option( Activator::SETTINGS_OPTION, array() ), Activator::default_settings() );

		$settings['https_only']         = ! empty( $_POST['https_only'] ) && 'false' !== $_POST['https_only'];
		$settings['rate_limit']         = isset( $_POST['rate_limit'] ) ? max( 0, absint( wp_unslash( $_POST['rate_limit'] ) ) ) : 60;
		$settings['rate_window']        = isset( $_POST['rate_window'] ) ? max( 1, absint( wp_unslash( $_POST['rate_window'] ) ) ) : 60;
		$settings['block_code']         = ! empty( $_POST['block_code'] ) && 'false' !== $_POST['block_code'];
		$settings['service_user_id']    = isset( $_POST['service_user_id'] ) ? absint( wp_unslash( $_POST['service_user_id'] ) ) : 0;
		$settings['enable_general_cli'] = ! empty( $_POST['enable_general_cli'] ) && 'false' !== $_POST['enable_general_cli'];
		$settings['allow_php_exec']     = ! empty( $_POST['allow_php_exec'] ) && 'false' !== $_POST['allow_php_exec'];
		$settings['allow_db_query']     = ! empty( $_POST['allow_db_query'] ) && 'false' !== $_POST['allow_db_query'];
		$settings['allow_file_write']   = ! empty( $_POST['allow_file_write'] ) && 'false' !== $_POST['allow_file_write'];
		$settings['enable_oauth']       = ! empty( $_POST['enable_oauth'] ) && 'false' !== $_POST['enable_oauth'];
		$settings['oauth_auto_approve'] = ! empty( $_POST['oauth_auto_approve'] ) && 'false' !== $_POST['oauth_auto_approve'];
		$settings['allowed_ips']        = isset( $_POST['allowed_ips'] ) ? sanitize_textarea_field( wp_unslash( $_POST['allowed_ips'] ) ) : '';
		$settings['log_retention_days'] = isset( $_POST['log_retention_days'] ) ? max( 1, absint( wp_unslash( $_POST['log_retention_days'] ) ) ) : 90;
		$settings['db_blacklist']       = isset( $_POST['db_blacklist'] ) ? sanitize_textarea_field( wp_unslash( $_POST['db_blacklist'] ) ) : '';

		if ( isset( $_POST['ai_tier'] ) ) {
			$tier = sanitize_key( wp_unslash( $_POST['ai_tier'] ) );
			if ( in_array( $tier, array( 'premium', 'economy' ), true ) ) {
				$settings['ai_tier'] = $tier;
			}
		}

		if ( isset( $_POST['active_builder'] ) ) {
			$ab = sanitize_key( wp_unslash( $_POST['active_builder'] ) );
			if ( '' === $ab || in_array( $ab, Builder_Manager::SLUGS, true ) ) {
				$settings['active_builder'] = $ab;
			}
		}

		update_option( Activator::SETTINGS_OPTION, $settings, false );
		wp_send_json_success( $this->status_payload() );
	}

	/**
	 * AJAX: troca o builder ativo.
	 *
	 * @return void
	 */
	public function ajax_switch_builder() {
		$this->verify();

		$builder = isset( $_POST['builder'] ) ? sanitize_key( wp_unslash( $_POST['builder'] ) ) : '';
		if ( ! in_array( $builder, Builder_Manager::SLUGS, true ) ) {
			wp_send_json_error( array( 'message' => 'Builder inválido.' ) );
		}

		$settings                   = wp_parse_args( get_option( Activator::SETTINGS_OPTION, array() ), Activator::default_settings() );
		$settings['active_builder'] = $builder;
		update_option( Activator::SETTINGS_OPTION, $settings, false );

		wp_send_json_success( $this->status_payload() );
	}

	/**
	 * AJAX: define o tier de IA.
	 *
	 * @return void
	 */
	public function ajax_set_tier() {
		$this->verify();

		$tier = isset( $_POST['tier'] ) ? sanitize_key( wp_unslash( $_POST['tier'] ) ) : '';
		if ( ! in_array( $tier, array( 'premium', 'economy' ), true ) ) {
			wp_send_json_error( array( 'message' => 'Tier inválido.' ) );
		}

		$settings            = wp_parse_args( get_option( Activator::SETTINGS_OPTION, array() ), Activator::default_settings() );
		$settings['ai_tier'] = $tier;
		update_option( Activator::SETTINGS_OPTION, $settings, false );

		wp_send_json_success( $this->status_payload() );
	}

	/**
	 * AJAX: gerar token (retorna o texto puro uma unica vez).
	 *
	 * @return void
	 */
	public function ajax_generate_token() {
		$this->verify();

		$name = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : 'Token';
		if ( '' === $name ) {
			$name = 'Token';
		}

		// Abilities: array de strings enviado como abilities[].
		$raw_abilities = array();
		if ( isset( $_POST['abilities'] ) && is_array( $_POST['abilities'] ) ) {
			$raw_abilities = array_map( 'sanitize_key', wp_unslash( $_POST['abilities'] ) );
		}
		$abilities = array_values( array_intersect( $raw_abilities, Token_Manager::KNOWN_ABILITIES ) );
		if ( empty( $abilities ) ) {
			$abilities = array( 'builder' );
		}

		$expires_days = isset( $_POST['expires_days'] ) ? absint( wp_unslash( $_POST['expires_days'] ) ) : 0;
		$expires_arg  = $expires_days > 0 ? $expires_days : null;

		$result = Token_Manager::generate( $name, $abilities, $expires_arg, get_current_user_id() );

		wp_send_json_success(
			array(
				'token'  => $result['plaintext'],
				'tokens' => Token_Manager::list_tokens(),
				'status' => $this->status_payload(),
			)
		);
	}

	/**
	 * AJAX: lista tokens.
	 *
	 * @return void
	 */
	public function ajax_list_tokens() {
		$this->verify();
		wp_send_json_success( array( 'tokens' => Token_Manager::list_tokens() ) );
	}

	/**
	 * AJAX: revogar token.
	 *
	 * @return void
	 */
	public function ajax_revoke_token() {
		$this->verify();
		$id = isset( $_POST['id'] ) ? absint( wp_unslash( $_POST['id'] ) ) : 0;
		Token_Manager::revoke( $id );
		wp_send_json_success( array( 'tokens' => Token_Manager::list_tokens() ) );
	}

	/**
	 * AJAX: reativar token.
	 *
	 * @return void
	 */
	public function ajax_activate_token() {
		$this->verify();
		$id = isset( $_POST['id'] ) ? absint( wp_unslash( $_POST['id'] ) ) : 0;
		Token_Manager::activate_token( $id );
		wp_send_json_success( array( 'tokens' => Token_Manager::list_tokens() ) );
	}

	/**
	 * AJAX: apagar token permanentemente.
	 *
	 * @return void
	 */
	public function ajax_delete_token() {
		$this->verify();
		$id = isset( $_POST['id'] ) ? absint( wp_unslash( $_POST['id'] ) ) : 0;
		Token_Manager::delete( $id );
		wp_send_json_success( array( 'tokens' => Token_Manager::list_tokens() ) );
	}

	/**
	 * AJAX: audit log paginado.
	 *
	 * @return void
	 */
	public function ajax_logs() {
		$this->verify();
		$page     = isset( $_POST['page'] ) ? max( 1, absint( wp_unslash( $_POST['page'] ) ) ) : 1;
		$per_page = isset( $_POST['per_page'] ) ? max( 1, min( 200, absint( wp_unslash( $_POST['per_page'] ) ) ) ) : 50;
		wp_send_json_success( Audit_Log::get_logs( array( 'page' => $page, 'per_page' => $per_page ) ) );
	}

	/**
	 * AJAX: autoteste interno.
	 *
	 * @return void
	 */
	public function ajax_selftest() {
		$this->verify();

		$registry = MCP_Server::build_registry();

		if ( $registry->has( 'get_capabilities' ) ) {
			$result = $registry->call( 'get_capabilities', array() );
			$ok     = empty( $result['isError'] );
			$text   = isset( $result['content'][0]['text'] ) ? $result['content'][0]['text'] : '';
			wp_send_json_success(
				array(
					'ok'   => $ok,
					'data' => json_decode( $text, true ),
				)
			);
			return;
		}

		$driver = Builder_Manager::active_driver();
		if ( $driver ) {
			wp_send_json_success( array( 'ok' => true, 'data' => $driver->capabilities() ) );
			return;
		}

		wp_send_json_success( array( 'ok' => false, 'message' => 'Nenhum builder ativo. Conclua o onboarding.' ) );
	}

	/**
	 * Serializa os clients OAuth para o painel (redirect_uris decodificados).
	 *
	 * @return array
	 */
	private function oauth_clients_payload() {
		$out = array();
		foreach ( Client_Manager::list_all() as $c ) {
			$uris  = json_decode( (string) $c['redirect_uris'], true );
			$out[] = array(
				'id'            => (int) $c['id'],
				'client_id'     => (string) $c['client_id'],
				'client_name'   => (string) $c['client_name'],
				'redirect_uris' => is_array( $uris ) ? $uris : array(),
				'status'        => (string) $c['status'],
				'reg_ip'        => (string) $c['reg_ip'],
				'created_at'    => (string) $c['created_at'],
				'approved_at'   => (string) $c['approved_at'],
			);
		}
		return $out;
	}

	/**
	 * AJAX: lista clients OAuth.
	 *
	 * @return void
	 */
	public function ajax_list_oauth_clients() {
		$this->verify();
		wp_send_json_success(
			array(
				'clients' => $this->oauth_clients_payload(),
				'pending' => Client_Manager::count_pending(),
			)
		);
	}

	/**
	 * AJAX: aprovar um client OAuth.
	 *
	 * @return void
	 */
	public function ajax_approve_oauth_client() {
		$this->verify();
		$id = isset( $_POST['id'] ) ? absint( wp_unslash( $_POST['id'] ) ) : 0;
		if ( $id > 0 ) {
			Client_Manager::approve( $id, get_current_user_id() );
			Audit_Log::log(
				array(
					'method'           => 'AJAX',
					'route'            => 'admin/oauth/approve',
					'action'           => 'oauth:client_approved',
					'status_code'      => 200,
					'user_id'          => get_current_user_id(),
					'ip'               => Audit_Log::client_ip(),
					'response_summary' => 'Client OAuth #' . $id . ' aprovado.',
					'success'          => true,
				)
			);
		}
		wp_send_json_success(
			array(
				'clients' => $this->oauth_clients_payload(),
				'pending' => Client_Manager::count_pending(),
			)
		);
	}

	/**
	 * AJAX: revogar um client OAuth.
	 *
	 * @return void
	 */
	public function ajax_revoke_oauth_client() {
		$this->verify();
		$id = isset( $_POST['id'] ) ? absint( wp_unslash( $_POST['id'] ) ) : 0;
		if ( $id > 0 ) {
			Client_Manager::revoke( $id );
			Audit_Log::log(
				array(
					'method'           => 'AJAX',
					'route'            => 'admin/oauth/revoke',
					'action'           => 'oauth:client_revoked',
					'status_code'      => 200,
					'user_id'          => get_current_user_id(),
					'ip'               => Audit_Log::client_ip(),
					'response_summary' => 'Client OAuth #' . $id . ' revogado.',
					'success'          => true,
				)
			);
		}
		wp_send_json_success(
			array(
				'clients' => $this->oauth_clients_payload(),
				'pending' => Client_Manager::count_pending(),
			)
		);
	}
}
