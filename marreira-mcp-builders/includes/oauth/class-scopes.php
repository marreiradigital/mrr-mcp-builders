<?php
/**
 * Mapa unico escopo OAuth <-> ability MMCB, com a trava dupla dos perigosos.
 *
 * @package Marreira\MCP_Builders
 */

namespace Marreira\MCP_Builders\OAuth;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Fonte unica de verdade para os escopos concedidos a um conector OAuth.
 *
 * Um escopo OAuth corresponde 1:1 a uma ability de token (ver Token_Manager).
 * Os escopos perigosos so podem ser concedidos se a flag de settings
 * correspondente estiver ligada — a MESMA trava dupla do CLI geral. Anunciar/
 * pedir um escopo nunca basta: a emissao do token filtra pelo que as settings
 * permitem, e a tela de consentimento so mostra os perigosos ja destravados.
 */
class Scopes {

	/**
	 * Escopos seguros, sempre concediveis (default quando nada e pedido).
	 *
	 * @var string[]
	 */
	const SAFE = array( 'builder', 'read', 'content' );

	/**
	 * Escopo perigoso => flag de settings que precisa estar ligada para concede-lo.
	 *
	 * @return array<string,string>
	 */
	public static function gated() {
		return array(
			'exec'     => 'allow_php_exec',
			'db_query' => 'allow_db_query',
			'files'    => 'allow_file_write',
			'db'       => 'enable_general_cli',
			'options'  => 'enable_general_cli',
			'plugins'  => 'enable_general_cli',
			'themes'   => 'enable_general_cli',
			'core'     => 'enable_general_cli',
			'cli'      => 'enable_general_cli',
			'snippets' => 'enable_general_cli',
		);
	}

	/**
	 * Rotulos legiveis (pt-BR) por escopo, para a tela de consentimento.
	 *
	 * @return array<string,string>
	 */
	public static function labels() {
		return array(
			'builder'  => 'Criar e editar páginas/templates do builder ativo',
			'read'     => 'Leituras gerais (site, usuários, logs, esquema do banco)',
			'content'  => 'Gerenciar conteúdo (posts, termos, comentários, mídia)',
			'plugins'  => 'Gerenciar plugins',
			'themes'   => 'Gerenciar temas',
			'core'     => 'Operações do núcleo do WordPress',
			'files'    => 'Escrever arquivos do tema',
			'snippets' => 'Gerenciar snippets PHP',
			'options'  => 'Ler e escrever configurações (wp_options) de plugins e do site',
			'db'       => 'Explorar o banco de dados',
			'db_query' => 'Executar consultas SQL',
			'exec'     => 'Executar código PHP/WP-CLI',
			'cli'      => 'Meta-operações de CLI/batch',
		);
	}

	/**
	 * Todos os escopos reconhecidos (sem o coringa '*').
	 *
	 * @return string[]
	 */
	public static function known() {
		return array_keys( self::labels() );
	}

	/**
	 * Indica se um escopo e perigoso (exige flag de settings).
	 *
	 * @param string $scope Escopo.
	 * @return bool
	 */
	public static function is_dangerous( $scope ) {
		return array_key_exists( $scope, self::gated() );
	}

	/**
	 * Indica se um escopo pode ser concedido dadas as settings atuais.
	 *
	 * @param string $scope    Escopo.
	 * @param array  $settings Settings do plugin.
	 * @return bool
	 */
	public static function is_grantable( $scope, array $settings ) {
		if ( ! in_array( $scope, self::known(), true ) ) {
			return false;
		}
		$gated = self::gated();
		if ( ! isset( $gated[ $scope ] ) ) {
			return true; // Seguro.
		}
		return ! empty( $settings[ $gated[ $scope ] ] );
	}

	/**
	 * Converte uma string de escopos (separada por espaco) na lista de escopos
	 * reconhecidos que ela pede (ignora desconhecidos e o coringa '*').
	 *
	 * @param string $scope_string Valor do parametro scope.
	 * @return string[]
	 */
	public static function parse( $scope_string ) {
		$parts = preg_split( '/\s+/', trim( (string) $scope_string ) );
		$parts = is_array( $parts ) ? $parts : array();
		$known = self::known();
		$out   = array();
		foreach ( $parts as $p ) {
			if ( '' !== $p && in_array( $p, $known, true ) && ! in_array( $p, $out, true ) ) {
				$out[] = $p;
			}
		}
		return $out;
	}

	/**
	 * Filtra uma lista de escopos concedidos para a lista de abilities efetiva,
	 * aplicando a trava dupla. Nunca concede '*'. Se sobrar vazio, cai no default
	 * seguro (SAFE).
	 *
	 * @param string[] $granted  Escopos que o admin aprovou.
	 * @param array    $settings Settings do plugin.
	 * @return string[] Abilities efetivas.
	 */
	public static function to_abilities( array $granted, array $settings ) {
		$out = array();
		foreach ( $granted as $scope ) {
			if ( self::is_grantable( $scope, $settings ) && ! in_array( $scope, $out, true ) ) {
				$out[] = $scope;
			}
		}
		if ( empty( $out ) ) {
			$out = self::SAFE;
		}
		return $out;
	}
}
