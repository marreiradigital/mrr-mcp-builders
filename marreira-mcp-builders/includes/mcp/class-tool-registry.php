<?php
/**
 * Registro central das MCP tools (compartilhado por todos os builders).
 *
 * @package Marreira\MCP_Builders
 */

namespace Marreira\MCP_Builders\MCP;

use Marreira\MCP_Builders\Auth\Rest_Guard;

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
	 * @param array    $meta        Autorizacao da tool:
	 *                              - 'ability'  (string) ability exigida do token. Padrao 'builder'.
	 *                              - 'requires' (string[]) flags de settings exigidas (trava dupla),
	 *                                 ex.: ['enable_general_cli','allow_php_exec'].
	 * @return void
	 */
	public function register( $name, $description, array $schema, callable $handler, array $meta = array() ) {
		$this->tools[ $name ] = array(
			'name'        => $name,
			'description' => $description,
			'inputSchema' => $schema,
			'handler'     => $handler,
			'ability'     => isset( $meta['ability'] ) ? (string) $meta['ability'] : 'builder',
			'requires'    => isset( $meta['requires'] ) ? (array) $meta['requires'] : array(),
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
				'inputSchema' => self::normalize_schema( $tool['inputSchema'] ),
			);
		}
		return $out;
	}

	/**
	 * Deixa um JSON Schema pronto pra serializar sem mentir sobre o tipo.
	 *
	 * O JSON Schema exige que `properties` seja um OBJETO. Em PHP, um schema sem
	 * argumentos nasce como `'properties' => array()`, e o json_encode nao tem
	 * como saber que aquele array vazio queria ser objeto: serializa como `[]`.
	 * Client MCP com validacao estrita (Claude Code, por exemplo) recusa o
	 * `tools/list` INTEIRO por causa disso — o servidor conecta e nenhuma tool
	 * fica disponivel ("expected record, received array"). Um `stdClass` vazio
	 * serializa como `{}` e resolve.
	 *
	 * A normalizacao fica aqui, no unico ponto de saida do catalogo (alimenta
	 * tools/list, /describe, o painel e o WP-CLI), e nao nos helpers schema() de
	 * cada driver: assim vale pra qualquer tool, tenha ela sido registrada pelo
	 * helper do driver, inline pelo nucleo ou por um driver futuro.
	 *
	 * A recursao e ciente de schema — desce so em `properties` (cujos valores sao
	 * schemas) e em `items` — em vez de procurar a chave 'properties' em qualquer
	 * lugar. Uma propriedade legitimamente CHAMADA "properties" continua
	 * intocada.
	 *
	 * @param mixed $schema JSON Schema (ou sub-schema).
	 * @return mixed
	 */
	private static function normalize_schema( $schema ) {
		if ( ! is_array( $schema ) ) {
			return $schema;
		}

		if ( array_key_exists( 'properties', $schema ) ) {
			if ( array() === $schema['properties'] ) {
				$schema['properties'] = new \stdClass();
			} elseif ( is_array( $schema['properties'] ) ) {
				foreach ( $schema['properties'] as $name => $sub_schema ) {
					$schema['properties'][ $name ] = self::normalize_schema( $sub_schema );
				}
			}
		}

		if ( isset( $schema['items'] ) ) {
			$schema['items'] = self::normalize_schema( $schema['items'] );
		}

		return $schema;
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

		$gate = self::authorize( $this->tools[ $name ] );
		if ( is_wp_error( $gate ) ) {
			return self::error_result( $gate->get_error_message() );
		}

		try {
			return call_user_func( $this->tools[ $name ]['handler'], $arguments );
		} catch ( \Throwable $e ) {
			return self::error_result( $e->getMessage() );
		}
	}

	/**
	 * Autoriza a execucao de uma tool: ability do token + flags de trava dupla.
	 *
	 * O enforcamento vive AQUI (e nao so no tools/call) de proposito: o
	 * run_batch despacha cada sub-comando por este mesmo call(), entao gatear
	 * aqui fecha o vetor de um token so-builder chamar uma tool de CLI perigosa
	 * atraves do batch. Quando nao ha token (WP-CLI local ou AJAX do painel, ja
	 * autenticados por outra camada), a chamada e plenamente confiavel e passa.
	 *
	 * @param array $tool Entrada da tool (com 'ability' e 'requires').
	 * @return true|\WP_Error
	 */
	private static function authorize( array $tool ) {
		// Sem token = contexto local confiavel (WP-CLI / painel admin).
		if ( null === Rest_Guard::current_token() ) {
			return true;
		}

		// Ability vazia = basta um token valido (ex.: run_batch, cujos
		// sub-comandos ja sao gateados individualmente).
		$ability = isset( $tool['ability'] ) ? (string) $tool['ability'] : 'builder';
		if ( '' !== $ability ) {
			$check = Rest_Guard::require_ability( $ability );
			if ( is_wp_error( $check ) ) {
				return $check;
			}
		}

		$requires = isset( $tool['requires'] ) ? (array) $tool['requires'] : array();
		foreach ( $requires as $flag ) {
			$flag_check = Rest_Guard::require_flag( (string) $flag );
			if ( is_wp_error( $flag_check ) ) {
				return $flag_check;
			}
		}

		return true;
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
