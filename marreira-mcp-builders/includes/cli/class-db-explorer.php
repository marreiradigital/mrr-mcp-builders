<?php
/**
 * Explorador de banco: introspeccao + SELECT seguro (allowlist).
 *
 * Portado do MRR_WP_CLI_DB_Explorer, adaptado ao settings/namespace deste
 * plugin. Mantem os validadores de seguranca (validate_select, redacao de
 * colunas sensiveis) fieis ao original.
 *
 * @package Marreira\MCP_Builders
 */

namespace Marreira\MCP_Builders\CLI;

use Marreira\MCP_Builders\Auth\Rest_Guard;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Leitura segura do banco: SHOW/DESCRIBE/EXPLAIN e SELECT com allowlist.
 */
class DB_Explorer {

	/**
	 * Colunas sempre redatadas, em qualquer tabela.
	 *
	 * @var string[]
	 */
	private const REDACTED_COLUMNS = array(
		'user_pass', 'user_activation_key', 'session_tokens',
	);

	/**
	 * option_name a redatar quando expostos.
	 *
	 * @var string[]
	 */
	private const REDACTED_OPTION_PATTERNS = array(
		'auth_key', 'auth_salt', 'logged_in_key', 'logged_in_salt',
		'nonce_key', 'nonce_salt', 'secure_auth_key', 'secure_auth_salt',
	);

	/**
	 * meta_key a redatar.
	 *
	 * @var string[]
	 */
	private const REDACTED_META_PATTERNS = array(
		'session_tokens', 'user_activation_key',
	);

	/**
	 * Lista tabelas com status.
	 *
	 * @return array
	 */
	public static function tables(): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		$rows   = $wpdb->get_results( 'SHOW TABLE STATUS', ARRAY_A );
		$prefix = $wpdb->prefix;
		$out    = array();
		foreach ( (array) $rows as $r ) {
			$name = (string) $r['Name'];
			if ( self::is_blacklisted( $name ) ) {
				continue;
			}
			$out[] = array(
				'name'         => $name,
				'is_wp'        => str_starts_with( $name, $prefix ),
				'rows'         => isset( $r['Rows'] ) ? (int) $r['Rows'] : null,
				'engine'       => $r['Engine'] ?? null,
				'collation'    => $r['Collation'] ?? null,
				'data_length'  => isset( $r['Data_length'] ) ? (int) $r['Data_length'] : null,
				'index_length' => isset( $r['Index_length'] ) ? (int) $r['Index_length'] : null,
				'comment'      => $r['Comment'] ?? '',
			);
		}
		return $out;
	}

	/**
	 * Schema de uma tabela (colunas, indices, FKs, relacoes inferidas).
	 *
	 * @param string $table Tabela.
	 * @return array|\WP_Error
	 */
	public static function schema( string $table ) {
		global $wpdb;
		$check = self::validate_table_name( $table );
		if ( is_wp_error( $check ) ) {
			return $check;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$columns = $wpdb->get_results( 'SHOW FULL COLUMNS FROM `' . $table . '`', ARRAY_A );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$indexes = $wpdb->get_results( 'SHOW INDEXES FROM `' . $table . '`', ARRAY_A );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		$fks = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT COLUMN_NAME, REFERENCED_TABLE_NAME, REFERENCED_COLUMN_NAME
				   FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE
				  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s
				    AND REFERENCED_TABLE_NAME IS NOT NULL",
				$table
			),
			ARRAY_A
		);

		$inferred = self::infer_relations( $table, (array) $columns );

		return array(
			'table'              => $table,
			'columns'            => $columns,
			'indexes'            => $indexes,
			'foreign_keys'       => $fks,
			'inferred_relations' => $inferred,
		);
	}

	/**
	 * Contagem de linhas.
	 *
	 * @param string $table Tabela.
	 * @return array|\WP_Error
	 */
	public static function count( string $table ) {
		global $wpdb;
		$check = self::validate_table_name( $table );
		if ( is_wp_error( $check ) ) {
			return $check;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM `' . $table . '`' );
		return array( 'table' => $table, 'count' => $rows );
	}

	/**
	 * Amostra de linhas (com redacao de colunas sensiveis).
	 *
	 * @param string $table  Tabela.
	 * @param int    $limit  Limite.
	 * @param int    $offset Offset.
	 * @return array|\WP_Error
	 */
	public static function sample( string $table, int $limit = 10, int $offset = 0 ) {
		global $wpdb;
		$check = self::validate_table_name( $table );
		if ( is_wp_error( $check ) ) {
			return $check;
		}
		$limit  = max( 1, min( 100, $limit ) );
		$offset = max( 0, $offset );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare( 'SELECT * FROM `' . $table . '` LIMIT %d OFFSET %d', $limit, $offset ),
			ARRAY_A
		);
		$rows = self::redact_rows( $table, (array) $rows );

		return array( 'table' => $table, 'rows' => $rows, 'limit' => $limit, 'offset' => $offset );
	}

	/**
	 * Mapa de relacoes inferidas de todas as tabelas.
	 *
	 * @return array
	 */
	public static function relations_map(): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		$tables = $wpdb->get_col( 'SHOW TABLES' );
		$out    = array();
		foreach ( $tables as $t ) {
			if ( self::is_blacklisted( $t ) ) {
				continue;
			}
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$cols = $wpdb->get_col( 'SHOW COLUMNS FROM `' . $t . '`' );
			$rels = self::infer_relations( $t, array_map( static fn( $c ) => array( 'Field' => $c ), $cols ) );
			if ( ! empty( $rels ) ) {
				$out[ $t ] = $rels;
			}
		}
		return $out;
	}

	/**
	 * SELECT seguro. Aceita SELECT/SHOW/DESCRIBE/EXPLAIN. Double-gated pela
	 * flag allow_db_query nas configuracoes.
	 *
	 * @param string $sql  SQL.
	 * @param array  $args Args para prepare.
	 * @return array|\WP_Error
	 */
	public static function safe_query( string $sql, array $args = array() ) {
		global $wpdb;

		$settings = Rest_Guard::settings();
		if ( empty( $settings['allow_db_query'] ) ) {
			return new \WP_Error( 'mmcb_db_query_disabled', __( 'Endpoint /db/query desativado nas configuracoes.', 'marreira-mcp-builders' ), array( 'status' => 403 ) );
		}

		$check = self::validate_select( $sql );
		if ( is_wp_error( $check ) ) {
			return $check;
		}
		// $sql = o original validado (o que roda). $probe = copia com as strings
		// literais esvaziadas, so para analise.
		$sql   = $check['sql'];
		$probe = $check['probe'];

		// Aplica LIMIT maximo de 1000 se nao houver. A checagem vai no probe: no
		// SQL original um literal como WHERE t = 'limit 5' faria o LIMIT parecer
		// presente e a query voltaria sem teto de linhas.
		if ( ! preg_match( '/\blimit\s+\d+/i', $probe ) ) {
			$sql .= ' LIMIT 1000';
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$prepared = $args ? $wpdb->prepare( $sql, $args ) : $sql;
		$wpdb->suppress_errors( true );
		$wpdb->hide_errors();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		$rows  = $wpdb->get_results( $prepared, ARRAY_A );
		$error = $wpdb->last_error;
		$wpdb->suppress_errors( false );

		if ( $error ) {
			return new \WP_Error( 'mmcb_db_error', $error, array( 'status' => 400 ) );
		}

		$rows = self::redact_rows( '', (array) $rows );

		return array(
			'rows'     => $rows,
			'rowcount' => count( (array) $rows ),
			'sql'      => $prepared,
		);
	}

	/* -------------------- Helpers -------------------- */

	/**
	 * Tabela na blacklist configurada?
	 *
	 * @param string $table Tabela.
	 * @return bool
	 */
	private static function is_blacklisted( string $table ): bool {
		$settings = Rest_Guard::settings();
		$list     = isset( $settings['db_blacklist'] ) ? (string) $settings['db_blacklist'] : '';
		if ( '' === trim( $list ) ) {
			return false;
		}
		$arr = array_filter( array_map( 'trim', preg_split( '/[\s,]+/', $list ) ) );
		return in_array( $table, $arr, true );
	}

	/**
	 * Valida nome de tabela e existencia.
	 *
	 * @param string $table Tabela.
	 * @return bool|\WP_Error
	 */
	private static function validate_table_name( string $table ) {
		global $wpdb;
		if ( ! preg_match( '/^[A-Za-z0-9_]+$/', $table ) ) {
			return new \WP_Error( 'mmcb_bad_table', __( 'Nome de tabela invalido.', 'marreira-mcp-builders' ), array( 'status' => 400 ) );
		}
		if ( self::is_blacklisted( $table ) ) {
			return new \WP_Error( 'mmcb_blacklisted', __( 'Tabela bloqueada.', 'marreira-mcp-builders' ), array( 'status' => 403 ) );
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		$existing = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
		if ( $existing !== $table ) {
			return new \WP_Error( 'mmcb_table_not_found', __( 'Tabela nao existe.', 'marreira-mcp-builders' ), array( 'status' => 404 ) );
		}
		return true;
	}

	/**
	 * Garante SQL somente-leitura. Rejeita multiplas instrucoes, comentarios e
	 * tokens perigosos.
	 *
	 * Devolve o SQL ORIGINAL (para executar) e o probe (para analise). O probe e
	 * uma copia com o conteudo das strings literais esvaziado, usada so para
	 * procurar keywords sem falso positivo (ex.: WHERE t = 'UPDATE ...'). O probe
	 * NUNCA pode ser o SQL executado: 'publish' viraria '' e a query devolveria
	 * dados errados em silencio.
	 *
	 * @param string $sql SQL.
	 * @return array{sql:string,probe:string}|\WP_Error
	 */
	private static function validate_select( string $sql ) {
		$sql = trim( $sql );

		if ( '' === $sql ) {
			return new \WP_Error( 'mmcb_bad_sql', __( 'SQL vazio.', 'marreira-mcp-builders' ), array( 'status' => 400 ) );
		}

		// Comentarios SQL sao recusados — e por seguranca, nao por estilo. O MySQL
		// EXECUTA comentarios versionados (/*!40000 DROP TABLE x */): analisar o
		// SQL com os comentarios removidos e depois executar o original deixaria
		// passar exatamente as keywords barradas abaixo. Recusar de saida mantem
		// probe e SQL executado equivalentes token a token. Valores vao por
		// placeholder ($args + wpdb::prepare), nao embutidos junto de comentario.
		if ( preg_match( '#/\*|--|\##', $sql ) ) {
			return new \WP_Error(
				'mmcb_sql_comment',
				__( 'Comentarios SQL (--, #, /* */) nao sao aceitos. Passe valores por placeholder.', 'marreira-mcp-builders' ),
				array( 'status' => 400 )
			);
		}

		// Esvazia o conteudo de strings literais APENAS para a analise abaixo.
		$probe = preg_replace( "/'(?:\\\\.|''|[^'\\\\])*'/s", "''", $sql );
		$probe = preg_replace( '/"(?:\\\\.|""|[^"\\\\])*"/s', '""', (string) $probe );
		$probe = trim( (string) $probe );

		if ( '' === $probe ) {
			return new \WP_Error( 'mmcb_bad_sql', __( 'SQL vazio.', 'marreira-mcp-builders' ), array( 'status' => 400 ) );
		}

		$trimmed = rtrim( $probe, ';' );
		if ( str_contains( $trimmed, ';' ) ) {
			return new \WP_Error( 'mmcb_multi_statement', __( 'Multiplas instrucoes nao sao permitidas.', 'marreira-mcp-builders' ), array( 'status' => 400 ) );
		}

		if ( ! preg_match( '/^\s*(SELECT|SHOW|DESCRIBE|DESC|EXPLAIN)\b/i', $trimmed ) ) {
			return new \WP_Error( 'mmcb_only_select', __( 'Apenas SELECT/SHOW/DESCRIBE/EXPLAIN sao aceitos.', 'marreira-mcp-builders' ), array( 'status' => 400 ) );
		}

		$forbidden = array(
			'INSERT', 'UPDATE', 'DELETE', 'REPLACE', 'TRUNCATE', 'DROP',
			'ALTER', 'CREATE', 'RENAME', 'GRANT', 'REVOKE', 'LOAD',
			'HANDLER', 'CALL', 'LOCK', 'UNLOCK', 'OPTIMIZE', 'ANALYZE',
			'INTO\s+OUTFILE', 'INTO\s+DUMPFILE',
		);
		foreach ( $forbidden as $word ) {
			if ( preg_match( '/\b' . $word . '\b/i', $trimmed ) ) {
				return new \WP_Error( 'mmcb_forbidden_keyword', __( 'Palavra-chave nao permitida: ', 'marreira-mcp-builders' ) . $word, array( 'status' => 400 ) );
			}
		}

		$blocked_fns = array( 'BENCHMARK', 'SLEEP', 'GET_LOCK', 'RELEASE_LOCK', 'LOAD_FILE' );
		foreach ( $blocked_fns as $fn ) {
			if ( preg_match( '/\b' . $fn . '\s*\(/i', $trimmed ) ) {
				return new \WP_Error( 'mmcb_forbidden_function', __( 'Funcao bloqueada: ', 'marreira-mcp-builders' ) . $fn, array( 'status' => 400 ) );
			}
		}

		// O que sai daqui pra execucao e o SQL ORIGINAL (so sem o ; final); o
		// probe fica disponivel para checagens que nao podem cair em literal.
		return array(
			'sql'   => rtrim( $sql, " \t\n\r;" ),
			'probe' => $trimmed,
		);
	}

	/**
	 * Infere relacoes por convencao de nomes.
	 *
	 * @param string $table   Tabela.
	 * @param array  $columns Colunas.
	 * @return array
	 */
	private static function infer_relations( string $table, array $columns ): array {
		$known = array(
			'post_id'    => array( 'table' => 'posts', 'column' => 'ID' ),
			'user_id'    => array( 'table' => 'users', 'column' => 'ID' ),
			'term_id'    => array( 'table' => 'terms', 'column' => 'term_id' ),
			'comment_id' => array( 'table' => 'comments', 'column' => 'comment_ID' ),
			'parent'     => array( 'table' => '__self__', 'column' => '__pk__' ),
		);
		global $wpdb;
		$prefix = $wpdb->prefix;
		$out    = array();
		foreach ( $columns as $col ) {
			$name = strtolower( (string) ( $col['Field'] ?? $col['field'] ?? '' ) );
			if ( '' === $name ) {
				continue;
			}
			foreach ( $known as $suffix => $rel ) {
				if ( $name === $suffix || str_ends_with( $name, '_' . $suffix ) ) {
					$ref_table = '__self__' === $rel['table'] ? $table : $prefix . $rel['table'];
					$out[]     = array(
						'column'        => $col['Field'] ?? $name,
						'references'    => $ref_table,
						'ref_column'    => $rel['column'],
						'inferred_from' => 'naming-convention',
					);
				}
			}
		}
		return $out;
	}

	/**
	 * Redacta colunas/valores sensiveis nas linhas retornadas.
	 *
	 * @param string $table Tabela (ou vazio quando SELECT com alias).
	 * @param array  $rows  Linhas.
	 * @return array
	 */
	private static function redact_rows( string $table, array $rows ): array {
		global $wpdb;
		$is_options = ( $table === $wpdb->options );
		$is_meta    = in_array( $table, array( $wpdb->postmeta, $wpdb->usermeta, $wpdb->commentmeta, $wpdb->termmeta ?? '' ), true );

		foreach ( $rows as &$row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			foreach ( $row as $col => $val ) {
				if ( in_array( $col, self::REDACTED_COLUMNS, true ) ) {
					$row[ $col ] = '***';
				}
			}

			if ( ( $is_options || isset( $row['option_name'], $row['option_value'] ) ) && isset( $row['option_name'] ) ) {
				foreach ( self::REDACTED_OPTION_PATTERNS as $p ) {
					if ( false !== stripos( (string) $row['option_name'], $p ) ) {
						$row['option_value'] = '***';
						break;
					}
				}
			}

			if ( ( $is_meta || isset( $row['meta_key'], $row['meta_value'] ) ) && isset( $row['meta_key'] ) ) {
				foreach ( self::REDACTED_META_PATTERNS as $p ) {
					if ( false !== stripos( (string) $row['meta_key'], $p ) ) {
						$row['meta_value'] = '***';
						break;
					}
				}
			}

			foreach ( $row as $col => $val ) {
				if ( is_string( $val ) && strlen( $val ) > 8192 ) {
					$row[ $col ] = substr( $val, 0, 8192 ) . '…[truncated]';
				}
			}
		}
		return $rows;
	}
}
