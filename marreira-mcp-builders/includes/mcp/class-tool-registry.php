<?php
/**
 * Registro central das MCP tools (compartilhado por todos os builders).
 *
 * @package Marreira\MCP_Builders
 */

namespace Marreira\MCP_Builders\MCP;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Mantem o catalogo de tools e despacha as chamadas.
 *
 * Cada tool e registrada com: name, description, inputSchema (JSON Schema)
 * e um callable handler( array $arguments ): array. E preenchido em runtime
 * pelo driver de builder ativo + pelas tools de nucleo (batch, mapa).
 */
class Tool_Registry {

	/**
	 * Tools registradas, indexadas por nome.
	 *
	 * @var array<string,array>
	 */
	private $tools = array();

	/**
	 * Registra uma tool.
	 *
	 * @param string   $name        Nome unico da tool.
	 * @param string   $description Descricao legivel para a IA.
	 * @param array    $schema      JSON Schema do inputSchema.
	 * @param callable $handler     Handler que recebe os argumentos e devolve o resultado.
	 * @return void
	 */
	public function register( $name, $description, array $schema, callable $handler ) {
		$this->tools[ $name ] = array(
			'name'        => $name,
			'description' => $description,
			'inputSchema' => $schema,
			'handler'     => $handler,
		);
	}

	/**
	 * Lista as definicoes publicas das tools (sem o handler) para tools/list.
	 *
	 * @return array
	 */
	public function definitions() {
		$out = array();
		foreach ( $this->tools as $tool ) {
			$out[] = array(
				'name'        => $tool['name'],
				'description' => $tool['description'],
				'inputSchema' => $tool['inputSchema'],
			);
		}
		return $out;
	}

	/**
	 * Nomes das tools registradas.
	 *
	 * @return string[]
	 */
	public function names() {
		return array_keys( $this->tools );
	}

	/**
	 * Indica se uma tool existe.
	 *
	 * @param string $name Nome.
	 * @return bool
	 */
	public function has( $name ) {
		return isset( $this->tools[ $name ] );
	}

	/**
	 * Executa uma tool.
	 *
	 * @param string $name      Nome da tool.
	 * @param array  $arguments Argumentos.
	 * @return array Resultado no formato { content: [...], isError: bool }.
	 */
	public function call( $name, array $arguments ) {
		if ( ! $this->has( $name ) ) {
			return self::error_result(
				sprintf(
					/* translators: %s: tool name */
					__( 'Tool desconhecida: %s', 'marreira-mcp-builders' ),
					$name
				)
			);
		}

		try {
			return call_user_func( $this->tools[ $name ]['handler'], $arguments );
		} catch ( \Throwable $e ) {
			return self::error_result( $e->getMessage() );
		}
	}

	/**
	 * Monta um resultado de sucesso a partir de dados arbitrarios.
	 *
	 * @param mixed  $data    Dados a serializar como JSON no bloco de texto.
	 * @param string $message Mensagem opcional.
	 * @return array
	 */
	public static function success_result( $data, $message = '' ) {
		$text  = '' !== $message ? $message . "\n" : '';
		$text .= wp_json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );

		return array(
			'content' => array(
				array(
					'type' => 'text',
					'text' => $text,
				),
			),
			'isError' => false,
		);
	}

	/**
	 * Monta um resultado de erro.
	 *
	 * @param string $message Mensagem.
	 * @return array
	 */
	public static function error_result( $message ) {
		return array(
			'content' => array(
				array(
					'type' => 'text',
					'text' => $message,
				),
			),
			'isError' => true,
		);
	}
}
