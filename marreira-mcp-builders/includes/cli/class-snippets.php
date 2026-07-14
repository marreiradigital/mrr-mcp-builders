<?php
/**
 * Snippets PHP: CRUD, execucao com escopo e lint de sintaxe (token_get_all).
 *
 * Portado do MRR_WP_CLI_Snippets, adaptado ao namespace/tabela deste plugin.
 * A execucao (eval) e gated pela ability "snippets" do token e pelo lint
 * previo; snippets so rodam quando active = 1.
 *
 * @package Marreira\MCP_Builders
 */

namespace Marreira\MCP_Builders\CLI;

use Marreira\MCP_Builders\Activator;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Gerencia e executa snippets PHP salvos no banco.
 */
class Snippets {

	/**
	 * Registra o hook de execucao dos snippets ativos.
	 *
	 * Usa o hook 'init' (e nao 'plugins_loaded') porque o plugin so faz o
	 * bootstrap em plugins_loaded — registrar um callback de plugins_loaded a
	 * partir dali ja seria tarde. 'init' dispara logo em seguida.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( 'init', array( __CLASS__, 'execute_active' ), 5 );
	}

	/**
	 * Executa os snippets ativos conforme o escopo.
	 *
	 * @return void
	 */
	public static function execute_active(): void {
		global $wpdb;
		$table = Activator::table_snippets();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
			return;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( "SELECT id, name, code, scope FROM {$table} WHERE active = 1 ORDER BY priority ASC, id ASC", ARRAY_A );
		if ( empty( $rows ) ) {
			return;
		}

		$is_admin = is_admin();
		$is_front = ! $is_admin;

		foreach ( $rows as $row ) {
			$scope = $row['scope'] ?? 'global';
			if ( 'admin' === $scope && ! $is_admin ) {
				continue;
			}
			if ( 'frontend' === $scope && ! $is_front ) {
				continue;
			}
			self::run_snippet( (int) $row['id'], (string) $row['name'], (string) $row['code'] );
		}
	}

	/**
	 * Executa um snippet isolado, capturando erros fatais.
	 *
	 * @param int    $id   Id.
	 * @param string $name Nome.
	 * @param string $code Codigo PHP.
	 * @return void
	 */
	private static function run_snippet( int $id, string $name, string $code ): void {
		$code = trim( $code );
		if ( '' === $code ) {
			return;
		}
		if ( str_starts_with( $code, '<?php' ) ) {
			$code = substr( $code, 5 );
		}
		if ( str_ends_with( $code, '?>' ) ) {
			$code = substr( $code, 0, -2 );
		}

		try {
			$closure = static function () use ( $code ) {
				eval( $code ); // phpcs:ignore Squiz.PHP.Eval.Discouraged
			};
			$closure();
		} catch ( \Throwable $e ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( sprintf( '[MarreiraMCP Builders] Snippet #%d "%s" falhou: %s', $id, $name, $e->getMessage() ) );
		}
	}

	/**
	 * Lista todos os snippets.
	 *
	 * @return array
	 */
	public static function all(): array {
		global $wpdb;
		$table = Activator::table_snippets();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_results( "SELECT * FROM {$table} ORDER BY id DESC", ARRAY_A );
	}

	/**
	 * Busca um snippet.
	 *
	 * @param int $id Id.
	 * @return array|null
	 */
	public static function get( int $id ): ?array {
		global $wpdb;
		$table = Activator::table_snippets();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A );
		return $row ?: null;
	}

	/**
	 * Cria um snippet.
	 *
	 * @param array $data Dados.
	 * @return int
	 */
	public static function create( array $data ): int {
		global $wpdb;
		$table = Activator::table_snippets();

		$now = current_time( 'mysql' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->insert(
			$table,
			array(
				'name'        => (string) ( $data['name'] ?? '' ),
				'description' => (string) ( $data['description'] ?? '' ),
				'code'        => (string) ( $data['code'] ?? '' ),
				'scope'       => (string) ( $data['scope'] ?? 'global' ),
				'active'      => ! empty( $data['active'] ) ? 1 : 0,
				'priority'    => (int) ( $data['priority'] ?? 10 ),
				'created_by'  => get_current_user_id(),
				'updated_by'  => get_current_user_id(),
				'created_at'  => $now,
				'updated_at'  => $now,
			),
			array( '%s', '%s', '%s', '%s', '%d', '%d', '%d', '%d', '%s', '%s' )
		);
		return (int) $wpdb->insert_id;
	}

	/**
	 * Atualiza um snippet.
	 *
	 * @param int   $id   Id.
	 * @param array $data Dados.
	 * @return bool
	 */
	public static function update( int $id, array $data ): bool {
		global $wpdb;
		$table = Activator::table_snippets();

		$fields  = array();
		$formats = array();

		foreach ( array( 'name', 'description', 'code', 'scope' ) as $f ) {
			if ( array_key_exists( $f, $data ) ) {
				$fields[ $f ] = (string) $data[ $f ];
				$formats[]    = '%s';
			}
		}
		if ( array_key_exists( 'active', $data ) ) {
			$fields['active'] = ! empty( $data['active'] ) ? 1 : 0;
			$formats[]        = '%d';
		}
		if ( array_key_exists( 'priority', $data ) ) {
			$fields['priority'] = (int) $data['priority'];
			$formats[]          = '%d';
		}

		if ( empty( $fields ) ) {
			return false;
		}

		$fields['updated_by'] = get_current_user_id();
		$formats[]            = '%d';
		$fields['updated_at'] = current_time( 'mysql' );
		$formats[]            = '%s';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return false !== $wpdb->update( $table, $fields, array( 'id' => $id ), $formats, array( '%d' ) );
	}

	/**
	 * Apaga um snippet.
	 *
	 * @param int $id Id.
	 * @return bool
	 */
	public static function delete( int $id ): bool {
		global $wpdb;
		$table = Activator::table_snippets();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return false !== $wpdb->delete( $table, array( 'id' => $id ), array( '%d' ) );
	}

	/**
	 * Faz lint (checagem de sintaxe) do codigo, sem executa-lo.
	 *
	 * Usa token_get_all() com a flag TOKEN_PARSE, que roda o parser do PHP e
	 * lanca \ParseError em erro de sintaxe SEM executar o codigo — funciona em
	 * qualquer SAPI (inclusive PHP-FPM/CloudPanel), sem depender de wp_tempnam(),
	 * de arquivos temporarios ou do binario `php` de CLI via exec().
	 *
	 * Mantem um fallback para `php -l` via exec() apenas quando TOKEN_PARSE nao
	 * existir (PHP < 7.0), com o require de wp-admin/includes/file.php corrigido
	 * (era a causa do fatal "Call to undefined function wp_tempnam()" que
	 * derrubava /cli/exec/php e /cli/snippets neste contexto REST).
	 *
	 * @param string $code Codigo PHP.
	 * @return array{ok:bool,error?:string}
	 */
	public static function lint( string $code ): array {
		$code = trim( $code );
		if ( '' === $code ) {
			return array( 'ok' => false, 'error' => __( 'Codigo vazio.', 'marreira-mcp-builders' ) );
		}
		if ( ! str_starts_with( $code, '<?php' ) ) {
			$code = "<?php\n" . $code;
		}

		// Caminho principal: parser do proprio PHP, sem executar nada.
		if ( defined( 'TOKEN_PARSE' ) ) {
			try {
				token_get_all( $code, TOKEN_PARSE );
				return array( 'ok' => true );
			} catch ( \ParseError $e ) {
				return array( 'ok' => false, 'error' => $e->getMessage() );
			} catch ( \Throwable $e ) {
				return array( 'ok' => false, 'error' => $e->getMessage() );
			}
		}

		// Fallback (PHP < 7.0): `php -l` via exec, se disponivel.
		$disabled = array_map( 'trim', explode( ',', (string) ini_get( 'disable_functions' ) ) );
		if ( ! function_exists( 'exec' ) || in_array( 'exec', $disabled, true ) ) {
			// Sem meio seguro de checar sintaxe: nao bloquear — a execucao ja
			// captura \Throwable e registra o erro.
			return array( 'ok' => true );
		}

		if ( ! function_exists( 'wp_tempnam' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		$tmp = wp_tempnam( 'mmcb-snippet-' );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		file_put_contents( $tmp, $code );

		$cmd    = escapeshellcmd( PHP_BINARY ) . ' -l ' . escapeshellarg( $tmp );
		$output = array();
		$status = 0;
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions, WordPress.PHP.NoSilencedErrors.Discouraged
		@exec( $cmd . ' 2>&1', $output, $status );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink, WordPress.PHP.NoSilencedErrors.Discouraged
		@unlink( $tmp );

		if ( 0 === $status ) {
			return array( 'ok' => true );
		}
		return array( 'ok' => false, 'error' => implode( "\n", $output ) );
	}
}
