<?php
/**
 * Tools de nucleo (agnosticas de builder): batch e mapa.
 *
 * @package Marreira\MCP_Builders
 */

namespace Marreira\MCP_Builders\MCP;

use Marreira\MCP_Builders\Builders\Builder_Manager;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registra tools que valem para qualquer builder:
 * - run_batch: aplica varias mudancas numa unica requisicao.
 * - get_map:   mapa compacto do site (para o modo economico).
 */
class Core_Tools {

	/**
	 * Registra as tools de nucleo.
	 *
	 * @param Tool_Registry $registry Registro.
	 * @return void
	 */
	public static function register( Tool_Registry $registry ) {
		$registry->register(
			'run_batch',
			__( 'Aplica varios comandos (tool + arguments) numa UNICA requisicao, em ordem. Prefira sempre esta tool para agrupar mudancas em vez de disparar muitas chamadas separadas. Aceita stop_on_error e dry_run. Retorna o status por comando.', 'marreira-mcp-builders' ),
			array(
				'type'       => 'object',
				'properties' => array(
					'commands'      => array(
						'type'        => 'array',
						'description' => 'Lista ordenada de comandos, cada um { "tool": <nome>, "arguments": { ... } }. Ex.: [{"tool":"create_bricks_page","arguments":{...}},{"tool":"regenerate_css","arguments":{}}].',
					),
					'stop_on_error' => array(
						'type'        => 'boolean',
						'description' => 'Interrompe no primeiro erro. Padrao: false (best-effort, segue e reporta cada um).',
					),
					'dry_run'       => array(
						'type'        => 'boolean',
						'description' => 'Valida os comandos sem aplicar (usa validate_tree quando ha arvore). Padrao: false.',
					),
				),
				'required'   => array( 'commands' ),
			),
			array( __CLASS__, 'run_batch' ),
			// Despachante: basta um token valido; cada sub-comando e gateado
			// individualmente pelo Tool_Registry::call().
			array( 'ability' => '' )
		);

		$registry->register(
			'get_map',
			__( 'Retorna um mapa COMPACTO do site do builder ativo (paginas, templates e resumo de estilos), sem as arvores completas. Use para se orientar e depois buscar so o que precisar (ideal no modo economico).', 'marreira-mcp-builders' ),
			array(
				'type'       => 'object',
				'properties' => array(
					'limit' => array(
						'type'        => 'integer',
						'description' => 'Maximo de paginas no mapa. Padrao: conforme o tier de IA.',
					),
				),
			),
			array( __CLASS__, 'get_map' )
		);
	}

	/**
	 * Handler: run_batch.
	 *
	 * @param array $args Argumentos.
	 * @return array
	 */
	public static function run_batch( array $args ) {
		$commands = isset( $args['commands'] ) && is_array( $args['commands'] ) ? $args['commands'] : array();
		if ( empty( $commands ) ) {
			return Tool_Registry::error_result( __( 'Informe "commands": uma lista de { tool, arguments }.', 'marreira-mcp-builders' ) );
		}

		$opts = array(
			'stop_on_error' => ! empty( $args['stop_on_error'] ),
			'dry_run'       => ! empty( $args['dry_run'] ),
		);

		$registry = MCP_Server::build_registry();
		$out      = Batch_Runner::run( $registry, $commands, $opts );

		$message = sprintf(
			/* translators: 1: ok count, 2: failed count, 3: total */
			__( 'Lote %1$s: %2$d ok, %3$d falha(s).', 'marreira-mcp-builders' ),
			$opts['dry_run'] ? __( 'validado (dry-run)', 'marreira-mcp-builders' ) : __( 'executado', 'marreira-mcp-builders' ),
			(int) $out['summary']['ok'],
			(int) $out['summary']['failed']
		);

		return Tool_Registry::success_result( $out, $message );
	}

	/**
	 * Handler: get_map.
	 *
	 * @param array $args Argumentos.
	 * @return array
	 */
	public static function get_map( array $args ) {
		$driver = Builder_Manager::active_driver();
		if ( ! $driver ) {
			return Tool_Registry::error_result( __( 'Nenhum builder ativo. Conclua o onboarding no painel.', 'marreira-mcp-builders' ) );
		}

		$limit = isset( $args['limit'] ) && (int) $args['limit'] > 0
			? (int) $args['limit']
			: Context_Strategy::default_limit( 50, 15 );

		$map = $driver->map( array( 'limit' => $limit ) );
		return Tool_Registry::success_result( $map );
	}
}
