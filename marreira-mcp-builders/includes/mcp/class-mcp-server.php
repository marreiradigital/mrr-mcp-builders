<?php
/**
 * Servidor MCP: rotas REST ocultas + dispatch JSON-RPC 2.0.
 *
 * @package Marreira\MCP_Builders
 */

namespace Marreira\MCP_Builders\MCP;

use Marreira\MCP_Builders\Auth\Rest_Guard;
use Marreira\MCP_Builders\Builders\Builder_Manager;
use WP_REST_Request;
use WP_REST_Response;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Expoe o endpoint MCP (Streamable HTTP / JSON-RPC) escondido do indice
 * publico do WP REST, servindo as tools do builder ativo + tools de nucleo.
 */
class MCP_Server {

	/**
	 * Registro de tools (lazy).
	 *
	 * @var Tool_Registry|null
	 */
	private $registry = null;

	/**
	 * Registra os hooks.
	 *
	 * @return void
	 */
	public function register_hooks() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
		add_filter( 'rest_index', array( $this, 'hide_from_index' ) );
		add_filter( 'rest_namespace_index', array( $this, 'hide_namespace_index' ), 10, 2 );
	}

	/**
	 * Inicializa o registro de tools sob demanda.
	 *
	 * @return Tool_Registry
	 */
	private function registry() {
		if ( null === $this->registry ) {
			$this->registry = self::build_registry();
		}
		return $this->registry;
	}

	/**
	 * Constroi o registro de tools (fonte unica, reusavel pelo admin/CLI).
	 *
	 * Registra as tools do builder ativo + as tools de nucleo (batch/mapa).
	 *
	 * @return Tool_Registry
	 */
	public static function build_registry() {
		$registry = new Tool_Registry();

		$driver = Builder_Manager::active_driver();
		if ( $driver ) {
			$driver->register_tools( $registry );
		}

		// Tools de nucleo (batch/mapa) — registradas quando presentes (F4).
		if ( class_exists( '\Marreira\MCP_Builders\MCP\Core_Tools' ) ) {
			Core_Tools::register( $registry );
		}

		/**
		 * Permite que outros modulos registrem tools adicionais.
		 *
		 * @param Tool_Registry              $registry Registro de tools.
		 * @param \Marreira\MCP_Builders\Builders\Builder_Driver|null $driver Driver ativo.
		 */
		do_action( 'mmcb_register_tools', $registry, $driver );

		return $registry;
	}

	/**
	 * Registra as rotas REST (ocultas do indice).
	 *
	 * @return void
	 */
	public function register_routes() {
		register_rest_route(
			MMCB_REST_NAMESPACE,
			MMCB_REST_ROUTE,
			array(
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'handle' ),
					'permission_callback' => array( Rest_Guard::class, 'check' ),
					'show_in_index'       => false,
				),
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'handle_get' ),
					'permission_callback' => array( Rest_Guard::class, 'check' ),
					'show_in_index'       => false,
				),
			)
		);

		// Rota PUBLICA de documentacao (skill) para a IA/IDE ler.
		register_rest_route(
			MMCB_REST_NAMESPACE,
			'/skill',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'serve_skill' ),
				'permission_callback' => '__return_true',
				'show_in_index'       => false,
			)
		);

		// Rota de auto-descoberta (com token): tools, abilities e ambiente.
		register_rest_route(
			MMCB_REST_NAMESPACE,
			'/describe',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'describe' ),
				'permission_callback' => array( Rest_Guard::class, 'check' ),
				'show_in_index'       => false,
			)
		);
	}

	/**
	 * Serve o SKILL.md (ou a variante enxuta no modo economico).
	 *
	 * @return void
	 */
	public function serve_skill() {
		$file = Context_Strategy::skill_file();

		if ( ! is_readable( $file ) ) {
			status_header( 404 );
			header( 'Content-Type: text/plain; charset=utf-8' );
			echo 'SKILL.md nao encontrado.';
			exit;
		}

		nocache_headers();
		header( 'Content-Type: text/markdown; charset=utf-8' );
		header( 'X-Robots-Tag: noindex' );
		readfile( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile
		exit;
	}

	/**
	 * Auto-descoberta: builder ativo, tier, tools e abilities do token.
	 *
	 * @return WP_REST_Response
	 */
	public function describe() {
		$driver = Builder_Manager::active_driver();
		$token  = Rest_Guard::current_token();

		$abilities = array();
		if ( $token && isset( $token['abilities'] ) ) {
			$decoded   = json_decode( (string) $token['abilities'], true );
			$abilities = is_array( $decoded ) ? $decoded : array();
		}

		return new WP_REST_Response(
			array(
				'server'          => 'MarreiraMCP Builders',
				'version'         => MMCB_VERSION,
				'mcp_protocol'    => MMCB_MCP_PROTOCOL_VERSION,
				'active_builder'  => $driver ? $driver->slug() : null,
				'builder_active'  => $driver ? $driver->is_active() : false,
				'ai_tier'         => Context_Strategy::tier(),
				'token_abilities' => $abilities,
				'endpoints'       => array(
					'mcp'   => esc_url_raw( rest_url( MMCB_REST_NAMESPACE . MMCB_REST_ROUTE ) ),
					'skill' => esc_url_raw( rest_url( MMCB_REST_NAMESPACE . '/skill' ) ),
					'cli'   => esc_url_raw( rest_url( MMCB_REST_NAMESPACE . '/cli' ) ),
				),
				'tools'           => $this->registry()->definitions(),
			),
			200
		);
	}

	/**
	 * Remove o namespace do indice raiz /wp-json/.
	 *
	 * @param WP_REST_Response $response Resposta do indice.
	 * @return WP_REST_Response
	 */
	public function hide_from_index( $response ) {
		$data = $response->get_data();

		if ( isset( $data['namespaces'] ) && is_array( $data['namespaces'] ) ) {
			$data['namespaces'] = array_values(
				array_filter(
					$data['namespaces'],
					static function ( $ns ) {
						return MMCB_REST_NAMESPACE !== $ns;
					}
				)
			);
		}

		if ( isset( $data['routes'] ) && is_array( $data['routes'] ) ) {
			foreach ( array_keys( $data['routes'] ) as $route ) {
				if ( 0 === strpos( ltrim( $route, '/' ), MMCB_REST_NAMESPACE ) ) {
					unset( $data['routes'][ $route ] );
				}
			}
		}

		$response->set_data( $data );
		return $response;
	}

	/**
	 * Esconde o conteudo do indice do proprio namespace.
	 *
	 * @param WP_REST_Response $response  Resposta.
	 * @param string           $namespace Namespace solicitado.
	 * @return WP_REST_Response
	 */
	public function hide_namespace_index( $response, $namespace ) {
		if ( MMCB_REST_NAMESPACE === $namespace ) {
			$data           = $response->get_data();
			$data['routes'] = array();
			$response->set_data( $data );
		}
		return $response;
	}

	/**
	 * Trata GET no endpoint MCP (sem stream): 405.
	 *
	 * @return WP_REST_Response
	 */
	public function handle_get() {
		return new WP_REST_Response(
			array(
				'jsonrpc' => '2.0',
				'error'   => array(
					'code'    => -32000,
					'message' => 'Method Not Allowed: use POST.',
				),
				'id'      => null,
			),
			405
		);
	}

	/**
	 * Trata o POST JSON-RPC.
	 *
	 * @param WP_REST_Request $request Requisicao.
	 * @return WP_REST_Response
	 */
	public function handle( WP_REST_Request $request ) {
		// Protecao contra DNS rebinding: valida Origin quando presente.
		$origin = $request->get_header( 'origin' );
		if ( $origin && ! $this->is_origin_allowed( $origin ) ) {
			return $this->rpc_error( null, -32001, 'Origin not allowed.', 403 );
		}

		$body = json_decode( $request->get_body(), true );

		if ( ! is_array( $body ) || ! isset( $body['jsonrpc'] ) || '2.0' !== $body['jsonrpc'] ) {
			return $this->rpc_error( null, -32700, 'Parse error: invalid JSON-RPC 2.0 envelope.', 200 );
		}

		$method          = isset( $body['method'] ) ? (string) $body['method'] : '';
		$id              = isset( $body['id'] ) ? $body['id'] : null;
		$params          = isset( $body['params'] ) && is_array( $body['params'] ) ? $body['params'] : array();
		$is_notification = ! array_key_exists( 'id', $body );

		switch ( $method ) {
			case 'initialize':
				$driver = Builder_Manager::active_driver();
				return $this->rpc_result(
					$id,
					array(
						'protocolVersion' => MMCB_MCP_PROTOCOL_VERSION,
						'capabilities'    => array(
							'tools' => array( 'listChanged' => false ),
						),
						'serverInfo'      => array(
							'name'    => 'MarreiraMCP Builders',
							'version' => MMCB_VERSION,
							'builder' => $driver ? $driver->slug() : null,
						),
						'instructions'    => Context_Strategy::handshake_instructions(),
					)
				);

			case 'notifications/initialized':
			case 'initialized':
				return new WP_REST_Response( null, 202 );

			case 'ping':
				return $this->rpc_result( $id, array() );

			case 'tools/list':
				return $this->rpc_result( $id, array( 'tools' => $this->registry()->definitions() ) );

			case 'tools/call':
				// Escopo: o endpoint MCP exige a ability builder.
				$ability = Rest_Guard::require_ability( 'builder' );
				if ( is_wp_error( $ability ) ) {
					return $this->rpc_error( $id, -32003, $ability->get_error_message(), 403 );
				}

				$name      = isset( $params['name'] ) ? (string) $params['name'] : '';
				$arguments = isset( $params['arguments'] ) && is_array( $params['arguments'] ) ? $params['arguments'] : array();

				if ( ! $this->registry()->has( $name ) ) {
					return $this->rpc_error( $id, -32602, 'Unknown tool: ' . $name, 200 );
				}

				$result = $this->registry()->call( $name, $arguments );
				return $this->rpc_result( $id, $result );

			default:
				if ( $is_notification ) {
					return new WP_REST_Response( null, 202 );
				}
				return $this->rpc_error( $id, -32601, 'Method not found: ' . $method, 200 );
		}
	}

	/**
	 * Verifica se a origin e permitida (mesma origem do site).
	 *
	 * @param string $origin Header Origin.
	 * @return bool
	 */
	private function is_origin_allowed( $origin ) {
		$site_host   = wp_parse_url( home_url(), PHP_URL_HOST );
		$origin_host = wp_parse_url( $origin, PHP_URL_HOST );

		$allowed_hosts = apply_filters(
			'mmcb_allowed_origin_hosts',
			array( $site_host, 'localhost', '127.0.0.1' )
		);

		return in_array( $origin_host, $allowed_hosts, true );
	}

	/**
	 * Resposta JSON-RPC de sucesso.
	 *
	 * @param mixed $id     Id.
	 * @param array $result Resultado.
	 * @return WP_REST_Response
	 */
	private function rpc_result( $id, array $result ) {
		return new WP_REST_Response(
			array(
				'jsonrpc' => '2.0',
				'id'      => $id,
				'result'  => $result,
			),
			200
		);
	}

	/**
	 * Resposta JSON-RPC de erro.
	 *
	 * @param mixed  $id      Id.
	 * @param int    $code    Codigo JSON-RPC.
	 * @param string $message Mensagem.
	 * @param int    $status  Status HTTP.
	 * @return WP_REST_Response
	 */
	private function rpc_error( $id, $code, $message, $status ) {
		return new WP_REST_Response(
			array(
				'jsonrpc' => '2.0',
				'id'      => $id,
				'error'   => array(
					'code'    => $code,
					'message' => $message,
				),
			),
			$status
		);
	}
}
