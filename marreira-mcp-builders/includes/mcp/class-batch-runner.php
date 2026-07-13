<?php
/**
 * Executor de lotes: varias operacoes numa unica requisicao.
 *
 * @package Marreira\MCP_Builders
 */

namespace Marreira\MCP_Builders\MCP;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Roda uma lista de comandos (tool + arguments) contra o registry, num unico
 * request. Isso permite a IA aplicar todas as mudancas de uma vez, em vez de
 * disparar muitos POSTs remotos (que alguns hosts tratam como intrusao).
 *
 * Semantica: best-effort com status por comando. stop_on_error interrompe no
 * primeiro erro; dry_run valida sem aplicar. Uma unica entrada de auditoria e
 * gravada para o lote inteiro (a requisicao HTTP e uma so).
 */
class Batch_Runner {

	/**
	 * Maximo de comandos por lote.
	 *
	 * @var int
	 */
	const MAX_COMMANDS = 100;

	/**
	 * Executa um lote.
	 *
	 * @param Tool_Registry $registry Registro de tools.
	 * @param array         $commands Lista de { tool|name, arguments|args }.
	 * @param array         $opts     stop_on_error, dry_run.
	 * @return array
	 */
	public static function run( Tool_Registry $registry, array $commands, array $opts = array() ) {
		$stop_on_error = ! empty( $opts['stop_on_error'] );
		$dry_run       = ! empty( $opts['dry_run'] );

		$total = count( $commands );
		if ( $total > self::MAX_COMMANDS ) {
			$commands = array_slice( $commands, 0, self::MAX_COMMANDS );
		}

		$results    = array();
		$ok         = 0;
		$failed     = 0;
		$stopped_at = null;

		foreach ( array_values( $commands ) as $i => $cmd ) {
			$entry = self::run_one( $registry, $cmd, $i, $dry_run );
			$results[] = $entry;

			if ( ! empty( $entry['ok'] ) ) {
				$ok++;
			} else {
				$failed++;
				if ( $stop_on_error ) {
					$stopped_at = $i;
					break;
				}
			}
		}

		return array(
			'dry_run'    => $dry_run,
			'stopped_at' => $stopped_at,
			'summary'    => array(
				'total'  => $total,
				'ran'    => count( $results ),
				'ok'     => $ok,
				'failed' => $failed,
			),
			'results'    => $results,
		);
	}

	/**
	 * Executa (ou pre-valida) um comando.
	 *
	 * @param Tool_Registry $registry Registro.
	 * @param mixed         $cmd      Comando.
	 * @param int           $index    Indice.
	 * @param bool          $dry_run  Modo simulacao.
	 * @return array
	 */
	private static function run_one( Tool_Registry $registry, $cmd, $index, $dry_run ) {
		if ( ! is_array( $cmd ) ) {
			return self::entry( $index, '', false, __( 'Comando invalido: esperado objeto { tool, arguments }.', 'marreira-mcp-builders' ) );
		}

		$tool = '';
		foreach ( array( 'tool', 'name', 'cmd' ) as $k ) {
			if ( isset( $cmd[ $k ] ) && is_string( $cmd[ $k ] ) && '' !== $cmd[ $k ] ) {
				$tool = $cmd[ $k ];
				break;
			}
		}

		$args = array();
		foreach ( array( 'arguments', 'args' ) as $k ) {
			if ( isset( $cmd[ $k ] ) && is_array( $cmd[ $k ] ) ) {
				$args = $cmd[ $k ];
				break;
			}
		}

		if ( '' === $tool ) {
			return self::entry( $index, '', false, __( 'Comando sem "tool".', 'marreira-mcp-builders' ) );
		}

		// Evita recursao / lotes aninhados.
		if ( 'run_batch' === $tool ) {
			return self::entry( $index, $tool, false, __( 'run_batch nao pode ser aninhado dentro de um lote.', 'marreira-mcp-builders' ) );
		}

		if ( ! $registry->has( $tool ) ) {
			return self::entry( $index, $tool, false, sprintf( /* translators: %s: tool */ __( 'Tool desconhecida: %s', 'marreira-mcp-builders' ), $tool ) );
		}

		if ( $dry_run ) {
			return self::preview( $registry, $tool, $args, $index );
		}

		$res     = $registry->call( $tool, $args );
		$is_error = ! empty( $res['isError'] );
		$text     = isset( $res['content'][0]['text'] ) ? (string) $res['content'][0]['text'] : '';

		return self::entry( $index, $tool, ! $is_error, $text );
	}

	/**
	 * Pre-valida um comando sem aplicar (dry-run).
	 *
	 * @param Tool_Registry $registry Registro.
	 * @param string        $tool     Tool.
	 * @param array         $args     Argumentos.
	 * @param int           $index    Indice.
	 * @return array
	 */
	private static function preview( Tool_Registry $registry, $tool, array $args, $index ) {
		// Se o comando carrega uma arvore e existe validate_tree, valida-a.
		if ( isset( $args['elements'] ) && $registry->has( 'validate_tree' ) ) {
			$res  = $registry->call( 'validate_tree', array( 'elements' => $args['elements'] ) );
			$text = isset( $res['content'][0]['text'] ) ? (string) $res['content'][0]['text'] : '';
			$data = json_decode( $text, true );
			$valid = is_array( $data ) && ! empty( $data['valid'] );
			return self::entry( $index, $tool, $valid, $valid ? __( 'preview: arvore valida (nao aplicada).', 'marreira-mcp-builders' ) : $text );
		}

		return self::entry( $index, $tool, true, __( 'preview: comando reconhecido (nao aplicado).', 'marreira-mcp-builders' ) );
	}

	/**
	 * Monta uma entrada de resultado por comando.
	 *
	 * @param int    $index Indice.
	 * @param string $tool  Tool.
	 * @param bool   $ok    Sucesso.
	 * @param string $text  Texto do resultado.
	 * @return array
	 */
	private static function entry( $index, $tool, $ok, $text ) {
		return array(
			'index'   => (int) $index,
			'tool'    => (string) $tool,
			'ok'      => (bool) $ok,
			'isError' => ! $ok,
			'text'    => (string) $text,
		);
	}
}
