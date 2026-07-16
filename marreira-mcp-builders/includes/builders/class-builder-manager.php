<?php
/**
 * Seleciona e resolve o driver de builder ativo.
 *
 * @package Marreira\MCP_Builders
 */

namespace Marreira\MCP_Builders\Builders;

use Marreira\MCP_Builders\Activator;
use Marreira\MCP_Builders\Builders\Bricks\Bricks_Driver;
use Marreira\MCP_Builders\Builders\Elementor\Elementor_Driver;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Fabrica e cache dos drivers, alem da leitura do builder selecionado no
 * onboarding (option active_builder). Um driver ativo por vez.
 *
 * Sobre "um builder ativo por vez": a regra e sobre qual driver fica ATIVO —
 * quem registra hooks, tools e responde pelo builder. Ela nao proibe construir o
 * objeto do outro driver para introspeccao: all() e detect_available() precisam
 * perguntar a cada driver se o builder dele esta instalado no site, e isso e
 * pergunta de instancia (is_active()).
 *
 * Isso e seguro porque os drivers NAO tem construtor: instanciar nao registra
 * hook, nao toca no banco e nao cria estado alem do proprio objeto. Quem cria
 * estado e o boot, que carrega apenas active_driver(). Se algum dia um driver
 * ganhar construtor com efeito colateral, esta premissa cai e a deteccao precisa
 * deixar de instanciar (ex.: is_active() virar estatico).
 */
class Builder_Manager {

	/**
	 * Slugs suportados.
	 *
	 * @var string[]
	 */
	const SLUGS = array( 'bricks', 'elementor' );

	/**
	 * Instancias ja resolvidas.
	 *
	 * @var array<string,Builder_Driver|null>
	 */
	private static $instances = array();

	/**
	 * Resolve (e cacheia) a instancia de um driver por slug.
	 *
	 * @param string $slug Slug do builder.
	 * @return Builder_Driver|null
	 */
	public static function driver( $slug ) {
		$slug = (string) $slug;
		if ( array_key_exists( $slug, self::$instances ) ) {
			return self::$instances[ $slug ];
		}

		$driver = null;
		if ( 'bricks' === $slug ) {
			$driver = new Bricks_Driver();
		} elseif ( 'elementor' === $slug ) {
			$driver = new Elementor_Driver();
		}

		self::$instances[ $slug ] = $driver;
		return $driver;
	}

	/**
	 * Todos os drivers conhecidos.
	 *
	 * @return array<string,Builder_Driver>
	 */
	public static function all() {
		$out = array();
		foreach ( self::SLUGS as $slug ) {
			$driver = self::driver( $slug );
			if ( $driver ) {
				$out[ $slug ] = $driver;
			}
		}
		return $out;
	}

	/**
	 * Slug do builder ativo (configurado no onboarding), ou string vazia.
	 *
	 * @return string
	 */
	public static function active_slug() {
		$settings = get_option( Activator::SETTINGS_OPTION, array() );
		$slug     = is_array( $settings ) && ! empty( $settings['active_builder'] ) ? (string) $settings['active_builder'] : '';
		return in_array( $slug, self::SLUGS, true ) ? $slug : '';
	}

	/**
	 * Driver ativo, ou null se o onboarding ainda nao escolheu um.
	 *
	 * @return Builder_Driver|null
	 */
	public static function active_driver() {
		$slug = self::active_slug();
		if ( '' === $slug ) {
			return null;
		}
		return self::driver( $slug );
	}

	/**
	 * Lista de slugs cujos builders estao ativos no site agora.
	 *
	 * @return string[]
	 */
	public static function detect_available() {
		$available = array();
		foreach ( self::SLUGS as $slug ) {
			$driver = self::driver( $slug );
			if ( $driver && $driver->is_active() ) {
				$available[] = $slug;
			}
		}
		return $available;
	}
}
