<?php
/**
 * Leitura e escrita de wp_options — onde os plugins de terceiros guardam a
 * configuracao (woocommerce_*, yoast, wpforms, etc.).
 *
 * Ate aqui o CLI so alcancava options via SELECT (db_query, somente leitura) ou
 * via exec/php. Esta camada expoe um CRUD estruturado, gateado pela ability
 * `options` + master switch (enable_general_cli).
 *
 * Seguranca:
 *  - Leitura redige options com nome sensivel (chaves/salts/segredos).
 *  - Escrita/remocao tem self-protection nas options do proprio plugin, para a
 *    IA nao desligar a propria seguranca (settings, aceite do termo, versao).
 *
 * @package Marreira\MCP_Builders
 */

namespace Marreira\MCP_Builders\CLI;

use Marreira\MCP_Builders\Activator;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * CRUD de wp_options com redacao e self-protection.
 */
class Options_Manager {

	/**
	 * Padroes de option_name cujo VALOR e redigido na leitura (segredos).
	 *
	 * @var string[]
	 */
	private const REDACTED_PATTERNS = array(
		'auth_key', 'auth_salt', 'logged_in_key', 'logged_in_salt',
		'nonce_key', 'nonce_salt', 'secure_auth_key', 'secure_auth_salt',
		'secret', 'password', 'private_key', 'api_key', 'apikey', 'access_token',
	);

	/**
	 * Options do proprio plugin que a IA nao pode escrever nem remover: mexer
	 * nelas desligaria a propria seguranca (settings/travas, aceite do termo).
	 *
	 * @return string[]
	 */
	private static function self_protected() {
		return array(
			Activator::SETTINGS_OPTION,
			Activator::TERMS_OPTION,
			Activator::VERSION_OPTION,
		);
	}

	/**
	 * Valida e normaliza um nome de option.
	 *
	 * @param string $name Nome cru.
	 * @return string|WP_Error
	 */
	private static function sanitize_name( $name ) {
		$name = trim( (string) $name );
		if ( '' === $name || strlen( $name ) > 191 || preg_match( '/[\x00-\x1F]/', $name ) ) {
			return new WP_Error( 'mmcb_bad_option', 'Nome de option invalido.', array( 'status' => 400 ) );
		}
		return $name;
	}

	/**
	 * Indica se o valor de uma option deve ser redigido (nome sensivel).
	 *
	 * @param string $name Nome da option.
	 * @return bool
	 */
	private static function is_sensitive( $name ) {
		foreach ( self::REDACTED_PATTERNS as $p ) {
			if ( false !== stripos( $name, $p ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Le uma option (valor redigido se sensivel).
	 *
	 * @param string $name Nome.
	 * @return array|WP_Error { name, exists, autoload, value }
	 */
	public static function get( $name ) {
		$name = self::sanitize_name( $name );
		if ( is_wp_error( $name ) ) {
			return $name;
		}

		$sentinel = '__mmcb_absent__';
		$value    = get_option( $name, $sentinel );
		$exists   = ( $sentinel !== $value );

		return array(
			'name'     => $name,
			'exists'   => $exists,
			'autoload' => self::autoload_of( $name ),
			'value'    => ( $exists && self::is_sensitive( $name ) ) ? '[REDACTED]' : ( $exists ? $value : null ),
		);
	}

	/**
	 * Le varias options de uma vez.
	 *
	 * @param array $names Lista de nomes.
	 * @return array { options: [...] }
	 */
	public static function get_many( array $names ) {
		$out = array();
		foreach ( $names as $name ) {
			$res = self::get( $name );
			if ( ! is_wp_error( $res ) ) {
				$out[] = $res;
			}
		}
		return array( 'options' => $out );
	}

	/**
	 * Busca options por trecho do nome (LIKE), para descobrir chaves de plugins.
	 *
	 * @param string $search Trecho do nome (vazio = todas — use com limit).
	 * @param int    $limit  Maximo de resultados (1..500).
	 * @return array { options: [ { name, autoload, value } ], total }
	 */
	public static function search( $search, $limit = 100 ) {
		global $wpdb;

		$search = (string) $search;
		$limit  = max( 1, min( 500, (int) $limit ) );

		if ( '' !== $search ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$names = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s ORDER BY option_name ASC LIMIT %d",
					'%' . $wpdb->esc_like( $search ) . '%',
					$limit
				)
			);
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$names = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT option_name FROM {$wpdb->options} ORDER BY option_name ASC LIMIT %d",
					$limit
				)
			);
		}

		$out = array();
		foreach ( (array) $names as $name ) {
			$out[] = self::get( $name );
		}
		return array( 'options' => $out, 'total' => count( $out ) );
	}

	/**
	 * Escreve (cria ou atualiza) uma option. Self-protection nas do plugin.
	 *
	 * @param string $name     Nome.
	 * @param mixed  $value    Valor (string, numero, array...).
	 * @param mixed  $autoload true/false/null (null = decisao padrao do WP).
	 * @return array|WP_Error { updated, name }
	 */
	public static function update( $name, $value, $autoload = null ) {
		$name = self::sanitize_name( $name );
		if ( is_wp_error( $name ) ) {
			return $name;
		}
		if ( in_array( $name, self::self_protected(), true ) ) {
			return new WP_Error( 'mmcb_self_protection', 'Esta option pertence ao proprio MarreiraMCP e nao pode ser alterada pelo CLI.', array( 'status' => 403 ) );
		}

		if ( null === $autoload ) {
			$result = update_option( $name, $value );
		} else {
			$result = update_option( $name, $value, (bool) $autoload ? 'yes' : 'no' );
		}

		return array(
			'name'     => $name,
			'updated'  => (bool) $result,
			'autoload' => self::autoload_of( $name ),
		);
	}

	/**
	 * Remove uma option. Self-protection nas do plugin.
	 *
	 * @param string $name Nome.
	 * @return array|WP_Error { deleted, name }
	 */
	public static function delete( $name ) {
		$name = self::sanitize_name( $name );
		if ( is_wp_error( $name ) ) {
			return $name;
		}
		if ( in_array( $name, self::self_protected(), true ) ) {
			return new WP_Error( 'mmcb_self_protection', 'Esta option pertence ao proprio MarreiraMCP e nao pode ser removida pelo CLI.', array( 'status' => 403 ) );
		}

		$result = delete_option( $name );
		return array( 'name' => $name, 'deleted' => (bool) $result );
	}

	/**
	 * Descobre o autoload atual de uma option (yes/no/null).
	 *
	 * @param string $name Nome.
	 * @return string|null
	 */
	private static function autoload_of( $name ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$autoload = $wpdb->get_var(
			$wpdb->prepare( "SELECT autoload FROM {$wpdb->options} WHERE option_name = %s LIMIT 1", $name )
		);
		return ( null === $autoload ) ? null : (string) $autoload;
	}
}
