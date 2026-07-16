<?php
/**
 * Audit log com scrub de PII.
 *
 * @package Marreira\MCP_Builders
 */

namespace Marreira\MCP_Builders\Security;

use Marreira\MCP_Builders\Activator;
use Marreira\MCP_Builders\Auth\Rest_Guard;
use WP_REST_Request;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registra cada requisicao aos endpoints do plugin, mascarando dados
 * sensiveis (tokens, senhas, codigo) antes de gravar. Portado do
 * MRR_WP_CLI_Logger.
 */
class Audit_Log {

	/**
	 * Chaves cujo valor e sempre mascarado.
	 *
	 * @var string[]
	 */
	const SENSITIVE_KEYS = array( 'password', 'pass', 'token', 'secret', 'api_key', 'apikey', 'authorization', 'auth', 'cookie', 'nonce', 'code', 'code_verifier', 'access_token', 'refresh_token', 'registration_access_token', 'client_secret' );

	/**
	 * Chaves volumosas: guardamos so o tamanho, nao o conteudo.
	 *
	 * @var string[]
	 */
	const BULKY_KEYS = array( 'content', 'code', 'zip_url', 'package', 'elements', 'settings', 'tree' );

	/**
	 * Registra os hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_filter( 'rest_post_dispatch', array( __CLASS__, 'log_response' ), 10, 3 );
		add_action( 'mmcb_daily_purge', array( __CLASS__, 'purge_old_default' ) );

		if ( ! wp_next_scheduled( 'mmcb_daily_purge' ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'mmcb_daily_purge' );
		}
	}

	/**
	 * Loga toda requisicao ao namespace do plugin (menos /skill).
	 *
	 * @param mixed            $result  Resposta.
	 * @param mixed            $server  Servidor REST.
	 * @param WP_REST_Request  $request Requisicao.
	 * @return mixed
	 */
	public static function log_response( $result, $server, $request ) {
		if ( ! $request instanceof WP_REST_Request ) {
			return $result;
		}

		$route = (string) $request->get_route();
		if ( 0 !== strpos( ltrim( $route, '/' ), MMCB_REST_NAMESPACE ) ) {
			return $result;
		}
		// Nao loga a rota publica de documentacao (ruido).
		if ( false !== strpos( $route, '/skill' ) ) {
			return $result;
		}

		$status = 200;
		if ( is_object( $result ) && method_exists( $result, 'get_status' ) ) {
			$status = (int) $result->get_status();
		}

		$token = Rest_Guard::current_token();

		self::log(
			array(
				'token_id'         => $token ? (int) $token['id'] : 0,
				'user_id'          => get_current_user_id(),
				'method'           => $request->get_method(),
				'route'            => $route,
				'action'           => self::derive_action( $request ),
				'status_code'      => $status,
				'ip'               => self::client_ip(),
				'user_agent'       => substr( (string) $request->get_header( 'user_agent' ), 0, 255 ),
				'request_payload'  => self::scrub( (array) $request->get_params() ),
				'response_summary' => '',
				'success'          => ( $status >= 200 && $status < 300 ) ? 1 : 0,
			)
		);

		return $result;
	}

	/**
	 * Deriva um rotulo de acao legivel a partir da requisicao.
	 *
	 * @param WP_REST_Request $request Requisicao.
	 * @return string
	 */
	private static function derive_action( WP_REST_Request $request ) {
		// Endpoint MCP JSON-RPC: usa o method e o nome da tool.
		$rpc_method = $request->get_param( 'method' );
		if ( is_string( $rpc_method ) && '' !== $rpc_method ) {
			if ( 'tools/call' === $rpc_method ) {
				$params = (array) $request->get_param( 'params' );
				$name   = isset( $params['name'] ) ? (string) $params['name'] : '?';
				if ( 'run_batch' === $name ) {
					$args     = isset( $params['arguments'] ) && is_array( $params['arguments'] ) ? $params['arguments'] : array();
					$commands = isset( $args['commands'] ) && is_array( $args['commands'] ) ? count( $args['commands'] ) : 0;
					return sprintf( 'run_batch (%d cmds)', $commands );
				}
				return 'tool:' . $name;
			}
			return 'rpc:' . $rpc_method;
		}

		// Rotas /cli/*: usa o final da rota.
		$route = (string) $request->get_route();
		$tail  = trim( str_replace( '/' . MMCB_REST_NAMESPACE, '', $route ), '/' );
		return $tail !== '' ? $tail : $route;
	}

	/**
	 * Insere uma entrada no log.
	 *
	 * @param array $entry Entrada.
	 * @return void
	 */
	public static function log( array $entry ) {
		global $wpdb;
		$table = Activator::table_logs();

		$payload = isset( $entry['request_payload'] ) ? $entry['request_payload'] : '';
		if ( is_array( $payload ) ) {
			$payload = wp_json_encode( $payload );
		}
		$payload = self::truncate( (string) $payload, 4096 );

		$summary = isset( $entry['response_summary'] ) ? (string) $entry['response_summary'] : '';
		$summary = self::truncate( $summary, 4096 );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->insert(
			$table,
			array(
				'token_id'         => isset( $entry['token_id'] ) ? (int) $entry['token_id'] : 0,
				'user_id'          => isset( $entry['user_id'] ) ? (int) $entry['user_id'] : 0,
				'method'           => isset( $entry['method'] ) ? substr( (string) $entry['method'], 0, 10 ) : '',
				'route'            => isset( $entry['route'] ) ? substr( (string) $entry['route'], 0, 191 ) : '',
				'action'           => isset( $entry['action'] ) ? substr( (string) $entry['action'], 0, 191 ) : '',
				'status_code'      => isset( $entry['status_code'] ) ? (int) $entry['status_code'] : 0,
				'ip'               => isset( $entry['ip'] ) ? substr( (string) $entry['ip'], 0, 64 ) : '',
				'user_agent'       => isset( $entry['user_agent'] ) ? substr( (string) $entry['user_agent'], 0, 255 ) : '',
				'request_payload'  => $payload,
				'response_summary' => $summary,
				'success'          => ! empty( $entry['success'] ) ? 1 : 0,
				'created_at'       => current_time( 'mysql' ),
			),
			array( '%d', '%d', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%d', '%s' )
		);
	}

	/**
	 * Mascara recursivamente dados sensiveis e encurta valores volumosos.
	 *
	 * @param mixed $data Dados.
	 * @param int   $depth Profundidade atual (limite de seguranca).
	 * @return mixed
	 */
	public static function scrub( $data, $depth = 0 ) {
		if ( $depth > 6 ) {
			return '[...]';
		}

		if ( is_array( $data ) ) {
			$out = array();
			foreach ( $data as $key => $value ) {
				$lkey = is_string( $key ) ? strtolower( $key ) : $key;

				if ( is_string( $lkey ) && in_array( $lkey, self::SENSITIVE_KEYS, true ) ) {
					$out[ $key ] = '***';
					continue;
				}
				if ( is_string( $lkey ) && in_array( $lkey, self::BULKY_KEYS, true ) ) {
					$out[ $key ] = '[' . self::size_of( $value ) . ' chars]';
					continue;
				}
				$out[ $key ] = self::scrub( $value, $depth + 1 );
			}
			return $out;
		}

		if ( is_string( $data ) ) {
			return self::truncate( $data, 512 );
		}

		return $data;
	}

	/**
	 * Tamanho aproximado (chars) de um valor para logar sem expor conteudo.
	 *
	 * @param mixed $value Valor.
	 * @return int
	 */
	private static function size_of( $value ) {
		if ( is_string( $value ) ) {
			return strlen( $value );
		}
		$encoded = wp_json_encode( $value );
		return is_string( $encoded ) ? strlen( $encoded ) : 0;
	}

	/**
	 * Encurta uma string com marcador.
	 *
	 * @param string $str String.
	 * @param int    $max Maximo de chars.
	 * @return string
	 */
	private static function truncate( $str, $max ) {
		if ( strlen( $str ) <= $max ) {
			return $str;
		}
		return substr( $str, 0, $max ) . '…[' . strlen( $str ) . ' chars]';
	}

	/**
	 * IP do cliente. Headers de proxy so quando MMCB_TRUST_PROXY.
	 *
	 * @return string
	 */
	public static function client_ip() {
		$remote = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';

		if ( ! self::proxy_is_trusted( $remote ) ) {
			return $remote;
		}

		foreach ( array( 'HTTP_CF_CONNECTING_IP', 'HTTP_X_REAL_IP', 'HTTP_X_FORWARDED_FOR' ) as $header ) {
			if ( empty( $_SERVER[ $header ] ) ) {
				continue;
			}
			$value = explode( ',', (string) wp_unslash( $_SERVER[ $header ] ) )[0]; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			$value = trim( $value );

			// So aceita se for IP de verdade. Antes qualquer string passava direto
			// para o throttle, para a allowlist e para o audit log.
			if ( filter_var( $value, FILTER_VALIDATE_IP ) ) {
				return $value;
			}
		}

		return $remote;
	}

	/**
	 * O IP de origem da conexao e um proxy em que confiamos?
	 *
	 * Headers de proxy sao texto que o cliente escreve: so valem se quem os
	 * entregou for de fato o proxy. Sem esta checagem, um atacante que alcance a
	 * origem direto (sem passar pelo Cloudflare/nginx) manda
	 * "X-Real-IP: <ip da allowlist>" e entra, ou rotaciona IPs forjados e nunca
	 * atinge o throttle.
	 *
	 * MMCB_TRUST_PROXY aceita:
	 *   - lista de IPs (string separada por virgula/espaco, ou array): so confia
	 *     nos headers quando o REMOTE_ADDR e um desses. E a forma recomendada.
	 *   - true: confia em qualquer origem. Mantido por compatibilidade; so e
	 *     seguro se a origem for inalcancavel sem passar pelo proxy.
	 *
	 * @param string $remote REMOTE_ADDR da conexao.
	 * @return bool
	 */
	public static function proxy_is_trusted( $remote ) {
		if ( ! defined( 'MMCB_TRUST_PROXY' ) || ! MMCB_TRUST_PROXY ) {
			return false;
		}

		if ( is_string( MMCB_TRUST_PROXY ) || is_array( MMCB_TRUST_PROXY ) ) {
			$list = is_array( MMCB_TRUST_PROXY ) ? MMCB_TRUST_PROXY : preg_split( '/[\s,]+/', (string) MMCB_TRUST_PROXY );
			$list = array_filter( array_map( 'trim', (array) $list ) );
			return '' !== $remote && in_array( $remote, $list, true );
		}

		return true;
	}

	/**
	 * Retorna entradas de log paginadas.
	 *
	 * @param array $args page, per_page.
	 * @return array{items:array,total:int}
	 */
	public static function get_logs( array $args = array() ) {
		global $wpdb;
		$table    = Activator::table_logs();
		$per_page = isset( $args['per_page'] ) ? max( 1, min( 200, (int) $args['per_page'] ) ) : 50;
		$page     = isset( $args['page'] ) ? max( 1, (int) $args['page'] ) : 1;
		$offset   = ( $page - 1 ) * $per_page;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$items = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} ORDER BY id DESC LIMIT %d OFFSET %d", $per_page, $offset ), ARRAY_A );

		return array(
			'items' => is_array( $items ) ? $items : array(),
			'total' => $total,
		);
	}

	/**
	 * Remove logs mais antigos que a retencao configurada.
	 *
	 * @param int|null $days Dias de retencao (null = usa a option).
	 * @return void
	 */
	public static function purge_old( $days = null ) {
		global $wpdb;
		$table = Activator::table_logs();

		if ( null === $days ) {
			$settings = Rest_Guard::settings();
			$days     = isset( $settings['log_retention_days'] ) ? (int) $settings['log_retention_days'] : 90;
		}
		$days = max( 1, (int) $days );

		$cutoff = gmdate( 'Y-m-d H:i:s', time() - ( $days * DAY_IN_SECONDS ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE created_at < %s", $cutoff ) );
	}

	/**
	 * Callback do cron diario.
	 *
	 * @return void
	 */
	public static function purge_old_default() {
		self::purge_old( null );
	}
}
