<?php
/**
 * Comandos WP-CLI locais (wp mmcb ...).
 *
 * @package Marreira\MCP_Builders
 */

namespace Marreira\MCP_Builders\CLI;

use Marreira\MCP_Builders\Activator;
use Marreira\MCP_Builders\Auth\Token_Manager;
use Marreira\MCP_Builders\Builders\Builder_Manager;
use Marreira\MCP_Builders\MCP\Batch_Runner;
use Marreira\MCP_Builders\MCP\Context_Strategy;
use Marreira\MCP_Builders\MCP\MCP_Server;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Comandos locais para o dono do site operar o builder pelo terminal, sem
 * passar pela rede/token. Reusa o mesmo registry e o Batch_Runner do MCP.
 *
 * Uso:
 *   wp mmcb tools
 *   wp mmcb call <tool> --args='{"post_id":12}'
 *   wp mmcb batch <arquivo.json> [--stop-on-error] [--dry-run]
 *   wp mmcb describe
 *   wp mmcb builder [<slug>]
 *   wp mmcb token create <nome> [--abilities=builder,read] [--expires=<dias>]
 *   wp mmcb token list
 *   wp mmcb token revoke <id> | wp mmcb token delete <id>
 */
class WP_CLI_Commands {

	/**
	 * Registra os comandos no WP-CLI.
	 *
	 * @return void
	 */
	public static function register() {
		\WP_CLI::add_command( 'mmcb', __CLASS__ );
		\WP_CLI::add_command( 'mmcb token', WP_CLI_Token_Commands::class );
	}

	/**
	 * Lista as tools registradas do builder ativo.
	 *
	 * ## EXAMPLES
	 *     wp mmcb tools
	 *
	 * @param array $args       Args posicionais.
	 * @param array $assoc_args Flags.
	 * @return void
	 */
	public function tools( $args, $assoc_args ) {
		$defs = MCP_Server::build_registry()->definitions();
		if ( empty( $defs ) ) {
			\WP_CLI::warning( 'Nenhuma tool registrada. Ha um builder ativo? (wp mmcb builder)' );
			return;
		}
		$items = array();
		foreach ( $defs as $d ) {
			$items[] = array(
				'tool'        => $d['name'],
				'description' => wp_strip_all_tags( $d['description'] ),
			);
		}
		\WP_CLI\Utils\format_items( 'table', $items, array( 'tool', 'description' ) );
	}

	/**
	 * Chama uma tool.
	 *
	 * ## OPTIONS
	 * <tool>
	 * : Nome da tool.
	 *
	 * [--args=<json>]
	 * : Argumentos em JSON. Padrao: {}.
	 *
	 * ## EXAMPLES
	 *     wp mmcb call get_capabilities
	 *     wp mmcb call get_page --args='{"post_id":12}'
	 *
	 * @param array $args       Args posicionais.
	 * @param array $assoc_args Flags.
	 * @return void
	 */
	public function call( $args, $assoc_args ) {
		$tool = isset( $args[0] ) ? (string) $args[0] : '';
		if ( '' === $tool ) {
			\WP_CLI::error( 'Informe o nome da tool.' );
		}

		$json      = isset( $assoc_args['args'] ) ? (string) $assoc_args['args'] : '{}';
		$arguments = json_decode( $json, true );
		if ( ! is_array( $arguments ) ) {
			\WP_CLI::error( 'O --args precisa ser um JSON de objeto valido.' );
		}

		$registry = MCP_Server::build_registry();
		if ( ! $registry->has( $tool ) ) {
			\WP_CLI::error( 'Tool desconhecida: ' . $tool );
		}

		$result = $registry->call( $tool, $arguments );
		$text   = isset( $result['content'][0]['text'] ) ? $result['content'][0]['text'] : '';
		if ( ! empty( $result['isError'] ) ) {
			\WP_CLI::error( $text );
		}
		\WP_CLI::line( $text );
		\WP_CLI::success( 'OK' );
	}

	/**
	 * Executa um lote de comandos a partir de um arquivo JSON.
	 *
	 * ## OPTIONS
	 * <file>
	 * : Caminho para um JSON { "commands": [ { "tool":..., "arguments":{...} } ] }.
	 *
	 * [--stop-on-error]
	 * : Interrompe no primeiro erro.
	 *
	 * [--dry-run]
	 * : Valida sem aplicar.
	 *
	 * @param array $args       Args posicionais.
	 * @param array $assoc_args Flags.
	 * @return void
	 */
	public function batch( $args, $assoc_args ) {
		$file = isset( $args[0] ) ? (string) $args[0] : '';
		if ( '' === $file || ! is_readable( $file ) ) {
			\WP_CLI::error( 'Arquivo nao encontrado: ' . $file );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$payload = json_decode( (string) file_get_contents( $file ), true );
		$commands = is_array( $payload ) && isset( $payload['commands'] ) && is_array( $payload['commands'] ) ? $payload['commands'] : array();
		if ( empty( $commands ) ) {
			\WP_CLI::error( 'JSON invalido: esperado { "commands": [ ... ] }.' );
		}

		$opts = array(
			'stop_on_error' => isset( $assoc_args['stop-on-error'] ),
			'dry_run'       => isset( $assoc_args['dry-run'] ),
		);

		$out = Batch_Runner::run( MCP_Server::build_registry(), $commands, $opts );

		foreach ( $out['results'] as $r ) {
			$line = sprintf( '#%d %s: %s', $r['index'], $r['tool'], $r['ok'] ? 'OK' : 'FALHA' );
			if ( $r['ok'] ) {
				\WP_CLI::log( $line );
			} else {
				\WP_CLI::warning( $line . ' — ' . $r['text'] );
			}
		}
		\WP_CLI::success( sprintf( 'Lote: %d ok, %d falha(s) de %d.', $out['summary']['ok'], $out['summary']['failed'], $out['summary']['total'] ) );
	}

	/**
	 * Mostra o builder ativo, o tier de IA e a contagem de tools.
	 *
	 * @param array $args       Args posicionais.
	 * @param array $assoc_args Flags.
	 * @return void
	 */
	public function describe( $args, $assoc_args ) {
		$driver = Builder_Manager::active_driver();
		\WP_CLI::line( 'Builder ativo:  ' . ( $driver ? $driver->slug() . ' (' . ( $driver->is_active() ? 'ativo no site' : 'inativo' ) . ')' : '(nenhum)' ) );
		\WP_CLI::line( 'Tier de IA:     ' . ( Context_Strategy::tier() ?: '(nao definido)' ) );
		\WP_CLI::line( 'Disponiveis:    ' . implode( ', ', Builder_Manager::detect_available() ) );
		\WP_CLI::line( 'Tools:          ' . count( MCP_Server::build_registry()->definitions() ) );
	}

	/**
	 * Mostra ou troca o builder ativo.
	 *
	 * ## OPTIONS
	 * [<slug>]
	 * : bricks ou elementor. Sem valor, apenas mostra o atual.
	 *
	 * @param array $args       Args posicionais.
	 * @param array $assoc_args Flags.
	 * @return void
	 */
	public function builder( $args, $assoc_args ) {
		if ( empty( $args[0] ) ) {
			\WP_CLI::line( 'Builder ativo: ' . ( Builder_Manager::active_slug() ?: '(nenhum)' ) );
			return;
		}
		$slug = sanitize_key( $args[0] );
		if ( ! in_array( $slug, Builder_Manager::SLUGS, true ) ) {
			\WP_CLI::error( 'Slug invalido. Use: ' . implode( ', ', Builder_Manager::SLUGS ) );
		}
		$settings                   = get_option( Activator::SETTINGS_OPTION, array() );
		$settings                   = is_array( $settings ) ? $settings : array();
		$settings['active_builder'] = $slug;
		update_option( Activator::SETTINGS_OPTION, $settings, false );
		\WP_CLI::success( 'Builder ativo agora: ' . $slug );
	}
}

/**
 * Subcomandos de token (wp mmcb token ...).
 */
class WP_CLI_Token_Commands {

	/**
	 * Cria um token e mostra o texto puro uma unica vez.
	 *
	 * ## OPTIONS
	 * <name>
	 * : Rotulo do token.
	 *
	 * [--abilities=<csv>]
	 * : Abilities separadas por virgula. Padrao: builder.
	 *
	 * [--expires=<dias>]
	 * : Dias ate expirar. Padrao: nunca.
	 *
	 * @param array $args       Args posicionais.
	 * @param array $assoc_args Flags.
	 * @return void
	 */
	public function create( $args, $assoc_args ) {
		$name = isset( $args[0] ) ? (string) $args[0] : 'cli-token';

		$abilities = array( 'builder' );
		if ( ! empty( $assoc_args['abilities'] ) ) {
			$abilities = array_filter( array_map( 'trim', explode( ',', (string) $assoc_args['abilities'] ) ) );
		}
		$expires = isset( $assoc_args['expires'] ) ? (int) $assoc_args['expires'] : null;

		$token = Token_Manager::generate( $name, $abilities, $expires );
		\WP_CLI::success( 'Token criado (id ' . $token['id'] . '). Guarde agora, nao sera mostrado de novo:' );
		\WP_CLI::line( $token['plaintext'] );
	}

	/**
	 * Lista os tokens.
	 *
	 * @param array $args       Args posicionais.
	 * @param array $assoc_args Flags.
	 * @return void
	 */
	public function list( $args, $assoc_args ) {
		$rows  = Token_Manager::list_tokens();
		$items = array();
		foreach ( $rows as $r ) {
			$items[] = array(
				'id'        => $r['id'],
				'name'      => $r['name'],
				'abilities' => $r['abilities'],
				'status'    => $r['status'],
				'last_used' => $r['last_used_at'],
			);
		}
		\WP_CLI\Utils\format_items( 'table', $items, array( 'id', 'name', 'abilities', 'status', 'last_used' ) );
	}

	/**
	 * Revoga um token.
	 *
	 * ## OPTIONS
	 * <id>
	 * : Id do token.
	 *
	 * @param array $args       Args posicionais.
	 * @param array $assoc_args Flags.
	 * @return void
	 */
	public function revoke( $args, $assoc_args ) {
		$id = isset( $args[0] ) ? (int) $args[0] : 0;
		Token_Manager::revoke( $id );
		\WP_CLI::success( 'Token ' . $id . ' revogado.' );
	}

	/**
	 * Apaga um token.
	 *
	 * ## OPTIONS
	 * <id>
	 * : Id do token.
	 *
	 * @param array $args       Args posicionais.
	 * @param array $assoc_args Flags.
	 * @return void
	 */
	public function delete( $args, $assoc_args ) {
		$id = isset( $args[0] ) ? (int) $args[0] : 0;
		Token_Manager::delete( $id );
		\WP_CLI::success( 'Token ' . $id . ' apagado.' );
	}
}
