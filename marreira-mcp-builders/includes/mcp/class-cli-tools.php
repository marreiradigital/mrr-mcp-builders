<?php
/**
 * Expoe o CLI geral de WordPress como tools MCP.
 *
 * Ate aqui o CLI so existia como rotas REST /cli/*. Um cliente MCP puro
 * (conector Claude.ai/ChatGPT) so consegue chamar o que aparece em tools/list —
 * ele nao faz HTTP arbitrario. Registrando as operacoes do CLI como tools, a IA
 * passa a enxergar e chamar plugins, temas, posts, options, banco, exec etc.
 * direto pelo MCP.
 *
 * DRY: cada handler apenas monta um WP_REST_Request e delega para o MESMO metodo
 * estatico do Rest_Controller que a rota REST usa — nenhuma logica e duplicada.
 * As travas se somam em duas camadas:
 *  - Tool_Registry::call() confere a ability do token + as flags declaradas aqui;
 *  - o proprio metodo REST reconfere o master switch (require_cli_enabled) e as
 *    flags criticas (allow_php_exec / allow_db_query / allow_file_write).
 *
 * As tools so sao registradas quando o CLI geral esta ligado (enable_general_cli),
 * para nao poluir o tools/list por padrao.
 *
 * @package Marreira\MCP_Builders
 */

namespace Marreira\MCP_Builders\MCP;

use Marreira\MCP_Builders\CLI\Rest_Controller;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Catalogo de tools MCP que espelham as rotas /cli/*.
 */
class CLI_Tools {

	/**
	 * Flags de trava dupla comuns.
	 */
	const REQ_CLI = array( 'enable_general_cli' );

	/**
	 * Registra todas as tools de CLI no registro.
	 *
	 * @param Tool_Registry $registry Registro.
	 * @return void
	 */
	public static function register( Tool_Registry $registry ) {
		foreach ( self::specs() as $spec ) {
			$registry->register(
				$spec['name'],
				$spec['description'],
				self::schema( $spec['props'] ?? array(), $spec['required'] ?? array() ),
				self::make_handler( $spec['cb'], $spec['http'] ),
				array(
					'ability'  => $spec['ability'],
					'requires' => $spec['requires'] ?? self::REQ_CLI,
				)
			);
		}
	}

	/**
	 * Constroi um handler que delega ao metodo REST correspondente.
	 *
	 * @param string $method Nome do metodo estatico em Rest_Controller.
	 * @param string $http   Verbo HTTP (so informativo para o request sintetico).
	 * @return callable
	 */
	private static function make_handler( $method, $http ) {
		return static function ( array $args ) use ( $method, $http ) {
			$request = new WP_REST_Request( $http, '' );
			foreach ( $args as $key => $value ) {
				$request->set_param( $key, $value );
			}
			$result = call_user_func( array( Rest_Controller::class, $method ), $request );
			return self::to_result( $result );
		};
	}

	/**
	 * Converte a resposta REST (WP_REST_Response|WP_Error|array) em resultado MCP.
	 *
	 * @param mixed $result Resultado do metodo REST.
	 * @return array
	 */
	private static function to_result( $result ) {
		if ( is_wp_error( $result ) ) {
			return Tool_Registry::error_result( $result->get_error_message() );
		}
		$data = ( $result instanceof WP_REST_Response ) ? $result->get_data() : $result;
		return Tool_Registry::success_result( $data );
	}

	/**
	 * Monta um JSON Schema a partir de um mapa compacto "chave => 'tipo:descricao'".
	 *
	 * @param array $props    Propriedades.
	 * @param array $required Chaves obrigatorias.
	 * @return array
	 */
	private static function schema( array $props, array $required = array() ) {
		$properties = array();
		foreach ( $props as $key => $spec ) {
			$parts = array_pad( explode( ':', (string) $spec, 2 ), 2, '' );
			// Tipo vazio => propriedade sem "type" (aceita qualquer tipo JSON).
			$prop = array( 'description' => $parts[1] );
			if ( '' !== $parts[0] ) {
				$prop['type'] = $parts[0];
			}
			$properties[ $key ] = $prop;
		}
		$schema = array(
			'type'       => 'object',
			'properties' => $properties,
		);
		if ( ! empty( $required ) ) {
			$schema['required'] = $required;
		}
		return $schema;
	}

	/**
	 * Tabela declarativa de tools. Cada item: name, cb (metodo REST), http,
	 * ability, requires (flags), description, props (schema compacto), required.
	 *
	 * @return array<int,array>
	 */
	private static function specs() {
		$req_cli = self::REQ_CLI;

		return array(
			/* ---------------- Plugins (ability: plugins) ---------------- */
			array(
				'name' => 'wp_list_plugins', 'cb' => 'list_plugins', 'http' => 'GET', 'ability' => 'plugins',
				'description' => 'Lista TODOS os plugins instalados (inclusive de terceiros): arquivo, nome, versao, ativo e atualizacao disponivel.',
			),
			array(
				'name' => 'wp_install_plugin', 'cb' => 'install_plugin', 'http' => 'POST', 'ability' => 'plugins',
				'description' => 'Instala um plugin do repositorio (por slug) ou de um zip (por zip_url). Opcionalmente ativa.',
				'props' => array( 'slug' => 'string:Slug no WordPress.org, ex.: woocommerce.', 'zip_url' => 'string:URL de um .zip (alternativa ao slug).', 'activate' => 'boolean:Ativar apos instalar. Padrao false.' ),
			),
			array(
				'name' => 'wp_activate_plugin', 'cb' => 'activate_plugin', 'http' => 'POST', 'ability' => 'plugins',
				'description' => 'Ativa um plugin instalado.',
				'props' => array( 'file' => 'string:Arquivo do plugin, ex.: woocommerce/woocommerce.php.' ), 'required' => array( 'file' ),
			),
			array(
				'name' => 'wp_deactivate_plugin', 'cb' => 'deactivate_plugin', 'http' => 'POST', 'ability' => 'plugins',
				'description' => 'Desativa um plugin. O proprio MarreiraMCP nao pode ser desativado (self-protection).',
				'props' => array( 'file' => 'string:Arquivo do plugin.' ), 'required' => array( 'file' ),
			),
			array(
				'name' => 'wp_update_plugin', 'cb' => 'update_plugin', 'http' => 'POST', 'ability' => 'plugins',
				'description' => 'Atualiza um plugin para a ultima versao disponivel.',
				'props' => array( 'file' => 'string:Arquivo do plugin.' ), 'required' => array( 'file' ),
			),
			array(
				'name' => 'wp_delete_plugin', 'cb' => 'delete_plugin', 'http' => 'POST', 'ability' => 'plugins',
				'description' => 'Desativa e remove um plugin. O proprio MarreiraMCP nao pode ser removido (self-protection).',
				'props' => array( 'file' => 'string:Arquivo do plugin.' ), 'required' => array( 'file' ),
			),

			/* ---------------- Themes (ability: themes) ---------------- */
			array(
				'name' => 'wp_list_themes', 'cb' => 'list_themes', 'http' => 'GET', 'ability' => 'themes',
				'description' => 'Lista todos os temas instalados, com versao, parent e qual esta ativo.',
			),
			array(
				'name' => 'wp_install_theme', 'cb' => 'install_theme', 'http' => 'POST', 'ability' => 'themes',
				'description' => 'Instala um tema por slug (WordPress.org) ou por zip_url. Opcionalmente ativa.',
				'props' => array( 'slug' => 'string:Slug do tema.', 'zip_url' => 'string:URL de um .zip.', 'activate' => 'boolean:Ativar apos instalar.' ),
			),
			array(
				'name' => 'wp_activate_theme', 'cb' => 'activate_theme', 'http' => 'POST', 'ability' => 'themes',
				'description' => 'Ativa um tema instalado.',
				'props' => array( 'stylesheet' => 'string:Stylesheet do tema (pasta), ex.: astra.' ), 'required' => array( 'stylesheet' ),
			),
			array(
				'name' => 'wp_update_theme', 'cb' => 'update_theme', 'http' => 'POST', 'ability' => 'themes',
				'description' => 'Atualiza um tema.',
				'props' => array( 'stylesheet' => 'string:Stylesheet do tema.' ), 'required' => array( 'stylesheet' ),
			),
			array(
				'name' => 'wp_delete_theme', 'cb' => 'delete_theme', 'http' => 'POST', 'ability' => 'themes',
				'description' => 'Remove um tema. O tema ativo nao pode ser removido (self-protection).',
				'props' => array( 'stylesheet' => 'string:Stylesheet do tema.' ), 'required' => array( 'stylesheet' ),
			),

			/* ---------------- Core (ability: core) ---------------- */
			array(
				'name' => 'wp_core_check_updates', 'cb' => 'core_info', 'http' => 'GET', 'ability' => 'core',
				'description' => 'Informa a versao do WordPress e as atualizacoes de nucleo disponiveis.',
			),
			array(
				'name' => 'wp_core_update', 'cb' => 'core_update', 'http' => 'POST', 'ability' => 'core',
				'description' => 'Atualiza o nucleo do WordPress para a ultima versao disponivel.',
			),

			/* ---------------- Content (ability: content) ---------------- */
			array(
				'name' => 'wp_list_posts', 'cb' => 'list_posts', 'http' => 'GET', 'ability' => 'content',
				'description' => 'Lista posts de QUALQUER tipo (inclusive CPTs de terceiros, ex.: product). Sem "type", inclui todos os tipos registrados.',
				'props' => array(
					'type' => 'string:Post type (ex.: post, page, product). Omita para todos.',
					'status' => 'string:Status (ex.: publish, draft, any). Padrao any.',
					'per_page' => 'integer:Itens por pagina (max 100).', 'page' => 'integer:Pagina.',
					'search' => 'string:Termo de busca.', 'orderby' => 'string:Campo de ordenacao.',
					'order' => 'string:ASC ou DESC.', 'author' => 'integer:ID do autor.', 'parent' => 'integer:ID do pai.',
				),
			),
			array(
				'name' => 'wp_get_post', 'cb' => 'get_post', 'http' => 'GET', 'ability' => 'content',
				'description' => 'Retorna um post completo: conteudo, termos e meta. Use include_private=true para incluir meta com prefixo _ (ex.: _price, _sku).',
				'props' => array( 'id' => 'integer:ID do post.', 'include_private' => 'boolean:Incluir meta privada (chaves _). Padrao false.' ), 'required' => array( 'id' ),
			),
			array(
				'name' => 'wp_create_post', 'cb' => 'create_post', 'http' => 'POST', 'ability' => 'content',
				'description' => 'Cria um post de qualquer tipo registrado (inclusive CPTs de terceiros). Aceita terms e meta (inclusive chaves privadas _).',
				'props' => array(
					'type' => 'string:Post type. Padrao post.', 'title' => 'string:Titulo.', 'content' => 'string:Conteudo.',
					'status' => 'string:Status. Padrao draft.', 'excerpt' => 'string:Resumo.', 'slug' => 'string:Slug.',
					'author' => 'integer:ID do autor.', 'parent' => 'integer:ID do pai.', 'menu_order' => 'integer:Ordem.',
					'terms' => 'object:Mapa taxonomia => [termos].', 'meta' => 'object:Mapa meta_key => valor.',
				),
			),
			array(
				'name' => 'wp_update_post', 'cb' => 'update_post', 'http' => 'POST', 'ability' => 'content',
				'description' => 'Atualiza um post. Campos parciais; terms e meta opcionais (meta aceita chaves privadas _).',
				'props' => array(
					'id' => 'integer:ID do post.', 'title' => 'string:Titulo.', 'content' => 'string:Conteudo.',
					'status' => 'string:Status.', 'excerpt' => 'string:Resumo.', 'slug' => 'string:Slug.',
					'author' => 'integer:ID do autor.', 'parent' => 'integer:ID do pai.', 'menu_order' => 'integer:Ordem.',
					'terms' => 'object:Mapa taxonomia => [termos].', 'meta' => 'object:Mapa meta_key => valor.',
				), 'required' => array( 'id' ),
			),
			array(
				'name' => 'wp_delete_post', 'cb' => 'delete_post', 'http' => 'POST', 'ability' => 'content',
				'description' => 'Exclui um post (lixeira ou permanente com force=true).',
				'props' => array( 'id' => 'integer:ID do post.', 'force' => 'boolean:Excluir permanentemente. Padrao false.' ), 'required' => array( 'id' ),
			),
			array(
				'name' => 'wp_list_post_types', 'cb' => 'post_types', 'http' => 'GET', 'ability' => 'content',
				'description' => 'Lista TODOS os post types registrados (builtin e de terceiros) com label, publico, taxonomias, supports e contagem.',
			),
			array(
				'name' => 'wp_list_taxonomies', 'cb' => 'taxonomies', 'http' => 'GET', 'ability' => 'content',
				'description' => 'Lista TODAS as taxonomias registradas (builtin e de terceiros).',
			),
			array(
				'name' => 'wp_list_terms', 'cb' => 'list_terms', 'http' => 'GET', 'ability' => 'content',
				'description' => 'Lista termos de uma taxonomia (inclui meta do termo).',
				'props' => array( 'taxonomy' => 'string:Taxonomia, ex.: category, product_cat.', 'search' => 'string:Busca.', 'hide_empty' => 'boolean:Ocultar sem posts.', 'per_page' => 'integer:Itens.', 'page' => 'integer:Pagina.' ),
			),
			array(
				'name' => 'wp_create_term', 'cb' => 'create_term', 'http' => 'POST', 'ability' => 'content',
				'description' => 'Cria um termo em qualquer taxonomia. Aceita meta.',
				'props' => array( 'taxonomy' => 'string:Taxonomia.', 'name' => 'string:Nome do termo.', 'slug' => 'string:Slug.', 'parent' => 'integer:Termo pai.', 'description' => 'string:Descricao.', 'meta' => 'object:Mapa meta_key => valor.' ), 'required' => array( 'taxonomy', 'name' ),
			),
			array(
				'name' => 'wp_update_term', 'cb' => 'update_term', 'http' => 'POST', 'ability' => 'content',
				'description' => 'Atualiza um termo. Aceita meta.',
				'props' => array( 'id' => 'integer:ID do termo.', 'name' => 'string:Nome.', 'slug' => 'string:Slug.', 'parent' => 'integer:Termo pai.', 'description' => 'string:Descricao.', 'meta' => 'object:Mapa meta_key => valor.' ), 'required' => array( 'id' ),
			),
			array(
				'name' => 'wp_delete_term', 'cb' => 'delete_term', 'http' => 'POST', 'ability' => 'content',
				'description' => 'Exclui um termo.',
				'props' => array( 'id' => 'integer:ID do termo.' ), 'required' => array( 'id' ),
			),
			array(
				'name' => 'wp_list_comments', 'cb' => 'list_comments', 'http' => 'GET', 'ability' => 'content',
				'description' => 'Lista comentarios (paginado).',
				'props' => array( 'status' => 'string:Status (hold, approve, spam, trash).', 'post_id' => 'integer:Filtrar por post.', 'per_page' => 'integer:Itens.', 'page' => 'integer:Pagina.' ),
			),
			array(
				'name' => 'wp_moderate_comment', 'cb' => 'moderate_comment', 'http' => 'POST', 'ability' => 'content',
				'description' => 'Modera um comentario: approve, unapprove, spam, trash ou delete.',
				'props' => array( 'id' => 'integer:ID do comentario.', 'action' => 'string:approve|unapprove|spam|trash|delete.' ), 'required' => array( 'id', 'action' ),
			),
			array(
				'name' => 'wp_list_media', 'cb' => 'list_media', 'http' => 'GET', 'ability' => 'content',
				'description' => 'Lista itens da biblioteca de midia (attachments).',
				'props' => array( 'per_page' => 'integer:Itens.', 'page' => 'integer:Pagina.', 'search' => 'string:Busca.' ),
			),

			/* ---------------- Users / Logs (ability: read) ---------------- */
			array(
				'name' => 'wp_list_users', 'cb' => 'list_users', 'http' => 'GET', 'ability' => 'read',
				'description' => 'Lista usuarios: id, login, email, nome e papeis.',
				'props' => array( 'per_page' => 'integer:Itens.', 'page' => 'integer:Pagina.', 'role' => 'string:Filtrar por papel.', 'search' => 'string:Busca.' ),
			),
			array(
				'name' => 'wp_get_user', 'cb' => 'get_user', 'http' => 'GET', 'ability' => 'read',
				'description' => 'Retorna um usuario com sua meta (campos ACF/preferencias). Sessoes e segredos sao redigidos.',
				'props' => array( 'id' => 'integer:ID do usuario.' ), 'required' => array( 'id' ),
			),
			array(
				'name' => 'wp_set_user_meta', 'cb' => 'set_user_meta', 'http' => 'POST', 'ability' => 'options',
				'description' => 'Grava uma user meta (ex.: campo ACF de usuario). Chaves de capabilities/nivel/sessao sao bloqueadas (sem escalonamento de privilegio).',
				'props' => array( 'id' => 'integer:ID do usuario.', 'meta_key' => 'string:Chave.', 'meta_value' => ':Valor (qualquer tipo JSON).' ), 'required' => array( 'id', 'meta_key', 'meta_value' ),
			),
			array(
				'name' => 'wp_get_logs', 'cb' => 'list_logs', 'http' => 'GET', 'ability' => 'read',
				'description' => 'Retorna o audit log paginado das requisicoes ao plugin.',
				'props' => array( 'per_page' => 'integer:Itens.', 'page' => 'integer:Pagina.' ),
			),

			/* ---------------- Options (ability: options) ---------------- */
			array(
				'name' => 'wp_get_option', 'cb' => 'option_read', 'http' => 'GET', 'ability' => 'options',
				'description' => 'Le o valor de uma option (config de plugin/site). Ex.: name=woocommerce_currency. Segredos (chaves/senhas) sao redigidos.',
				'props' => array( 'name' => 'string:Nome da option.' ), 'required' => array( 'name' ),
			),
			array(
				'name' => 'wp_list_options', 'cb' => 'option_read', 'http' => 'GET', 'ability' => 'options',
				'description' => 'Busca options por trecho do nome (ex.: search=woocommerce) para descobrir as chaves de configuracao de um plugin.',
				'props' => array( 'search' => 'string:Trecho do nome da option.', 'limit' => 'integer:Maximo de resultados (1..500, padrao 100).' ),
			),
			array(
				'name' => 'wp_update_option', 'cb' => 'option_write', 'http' => 'POST', 'ability' => 'options',
				'description' => 'Cria ou atualiza uma option. Configura plugins de terceiros. Options do proprio MarreiraMCP sao protegidas.',
				'props' => array( 'name' => 'string:Nome da option.', 'value' => ':Valor (qualquer tipo JSON: string, numero, booleano, objeto ou array).', 'autoload' => 'boolean:Autoload (padrao: decisao do WP).' ), 'required' => array( 'name', 'value' ),
			),
			array(
				'name' => 'wp_delete_option', 'cb' => 'option_delete', 'http' => 'DELETE', 'ability' => 'options',
				'description' => 'Remove uma option. Options do proprio MarreiraMCP sao protegidas.',
				'props' => array( 'name' => 'string:Nome da option.' ), 'required' => array( 'name' ),
			),

			/* ---------------- DB Explorer (ability: db) ---------------- */
			array(
				'name' => 'wp_db_tables', 'cb' => 'db_tables', 'http' => 'GET', 'ability' => 'db',
				'description' => 'Lista as tabelas do banco (inclusive tabelas proprias de plugins de terceiros, ex.: wp_wc_orders) com linhas e tamanho.',
			),
			array(
				'name' => 'wp_db_schema', 'cb' => 'db_schema', 'http' => 'GET', 'ability' => 'db',
				'description' => 'Retorna o schema de uma tabela: colunas, indices e relacoes inferidas.',
				'props' => array( 'name' => 'string:Nome da tabela.' ), 'required' => array( 'name' ),
			),
			array(
				'name' => 'wp_db_count', 'cb' => 'db_count', 'http' => 'GET', 'ability' => 'db',
				'description' => 'Conta as linhas de uma tabela.',
				'props' => array( 'name' => 'string:Nome da tabela.' ), 'required' => array( 'name' ),
			),
			array(
				'name' => 'wp_db_sample', 'cb' => 'db_sample', 'http' => 'GET', 'ability' => 'db',
				'description' => 'Amostra linhas de uma tabela (com redacao de colunas sensiveis).',
				'props' => array( 'name' => 'string:Nome da tabela.', 'limit' => 'integer:Linhas (padrao 10).', 'offset' => 'integer:Deslocamento.' ), 'required' => array( 'name' ),
			),
			array(
				'name' => 'wp_db_relations', 'cb' => 'db_relations', 'http' => 'GET', 'ability' => 'db',
				'description' => 'Mapa de relacoes inferidas entre as tabelas.',
			),
			array(
				'name' => 'wp_db_query', 'cb' => 'db_query', 'http' => 'POST', 'ability' => 'db_query',
				'requires' => array( 'enable_general_cli', 'allow_db_query' ),
				'description' => 'Executa uma consulta SOMENTE-LEITURA (SELECT/SHOW/DESCRIBE/EXPLAIN, 1 instrucao). Use %s + args para valores. Exige allow_db_query.',
				'props' => array( 'sql' => 'string:SQL de leitura, uma instrucao, sem comentarios.', 'args' => 'array:Valores para os placeholders %s.' ), 'required' => array( 'sql' ),
			),

			/* ---------------- Snippets (ability: snippets) ---------------- */
			array(
				'name' => 'wp_list_snippets', 'cb' => 'list_snippets', 'http' => 'GET', 'ability' => 'snippets',
				'description' => 'Lista os snippets PHP cadastrados.',
			),
			array(
				'name' => 'wp_get_snippet', 'cb' => 'get_snippet', 'http' => 'GET', 'ability' => 'snippets',
				'description' => 'Retorna um snippet por ID.',
				'props' => array( 'id' => 'integer:ID do snippet.' ), 'required' => array( 'id' ),
			),
			array(
				'name' => 'wp_create_snippet', 'cb' => 'create_snippet', 'http' => 'POST', 'ability' => 'snippets',
				'description' => 'Cria um snippet PHP (lint obrigatorio). Ativar exige allow_php_exec.',
				'props' => array( 'name' => 'string:Nome.', 'code' => 'string:Codigo PHP.', 'active' => 'boolean:Ativar (exige allow_php_exec).' ), 'required' => array( 'name', 'code' ),
			),
			array(
				'name' => 'wp_update_snippet', 'cb' => 'update_snippet', 'http' => 'POST', 'ability' => 'snippets',
				'description' => 'Atualiza um snippet. Ativar exige allow_php_exec.',
				'props' => array( 'id' => 'integer:ID.', 'name' => 'string:Nome.', 'code' => 'string:Codigo PHP.', 'active' => 'boolean:Ativar (exige allow_php_exec).' ), 'required' => array( 'id' ),
			),
			array(
				'name' => 'wp_delete_snippet', 'cb' => 'delete_snippet', 'http' => 'POST', 'ability' => 'snippets',
				'description' => 'Exclui um snippet.',
				'props' => array( 'id' => 'integer:ID.' ), 'required' => array( 'id' ),
			),
			array(
				'name' => 'wp_toggle_snippet', 'cb' => 'toggle_snippet', 'http' => 'POST', 'ability' => 'snippets',
				'description' => 'Liga/desliga um snippet. Ligar exige allow_php_exec.',
				'props' => array( 'id' => 'integer:ID.', 'active' => 'boolean:Estado desejado.' ), 'required' => array( 'id' ),
			),

			/* ---------------- Theme files (ability: files) ---------------- */
			array(
				'name' => 'wp_list_theme_files', 'cb' => 'theme_files', 'http' => 'GET', 'ability' => 'files',
				'description' => 'Lista os arquivos editaveis do tema ativo.',
			),
			array(
				'name' => 'wp_read_theme_file', 'cb' => 'theme_file_read', 'http' => 'GET', 'ability' => 'files',
				'description' => 'Le o conteudo de um arquivo dentro do tema ativo.',
				'props' => array( 'path' => 'string:Caminho relativo dentro do tema.' ), 'required' => array( 'path' ),
			),
			array(
				'name' => 'wp_write_theme_file', 'cb' => 'theme_file_write', 'http' => 'POST', 'ability' => 'files',
				'requires' => array( 'enable_general_cli', 'allow_file_write' ),
				'description' => 'Escreve um arquivo no tema ativo (lint PHP + backup). Exige allow_file_write.',
				'props' => array( 'path' => 'string:Caminho relativo.', 'content' => 'string:Conteudo.' ), 'required' => array( 'path', 'content' ),
			),
			array(
				'name' => 'wp_read_functions', 'cb' => 'functions_read', 'http' => 'GET', 'ability' => 'files',
				'description' => 'Le o functions.php do tema ativo.',
			),
			array(
				'name' => 'wp_write_functions', 'cb' => 'functions_write', 'http' => 'PUT', 'ability' => 'files',
				'requires' => array( 'enable_general_cli', 'allow_file_write' ),
				'description' => 'Grava o functions.php do tema ativo (lint + backup). Exige allow_file_write.',
				'props' => array( 'content' => 'string:Conteudo completo do functions.php.' ), 'required' => array( 'content' ),
			),

			/* ---------------- Exec PHP (ability: exec) ---------------- */
			array(
				'name' => 'wp_exec_php', 'cb' => 'exec_php', 'http' => 'POST', 'ability' => 'exec',
				'requires' => array( 'enable_general_cli', 'allow_php_exec' ),
				'description' => 'Executa PHP arbitrario no contexto do WordPress (lint antes). Ultimo recurso: prefira as tools estruturadas. Exige allow_php_exec.',
				'props' => array( 'code' => 'string:Codigo PHP a executar.' ), 'required' => array( 'code' ),
			),
		);
	}
}
