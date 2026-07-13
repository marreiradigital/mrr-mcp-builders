<?php
/**
 * Estrategia de consumo de contexto conforme o tier de IA.
 *
 * @package Marreira\MCP_Builders
 */

namespace Marreira\MCP_Builders\MCP;

use Marreira\MCP_Builders\Activator;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Le a option ai_tier (premium | economy) e ajuda os demais modulos a
 * adaptar verbosidade, limites e qual variante de SKILL servir.
 *
 * - premium:  IA paga/capaz. Skill completa, respostas completas, mapa inteiro.
 * - economy:  IA gratis/barata. Skill enxuta, respostas concisas, buscar por
 *             partes (get_map) para poupar contexto.
 */
class Context_Strategy {

	/**
	 * Tier configurado ('premium' | 'economy' | '' se ainda no onboarding).
	 *
	 * @return string
	 */
	public static function tier() {
		$settings = get_option( Activator::SETTINGS_OPTION, array() );
		$tier     = is_array( $settings ) && ! empty( $settings['ai_tier'] ) ? (string) $settings['ai_tier'] : '';
		return in_array( $tier, array( 'premium', 'economy' ), true ) ? $tier : '';
	}

	/**
	 * Indica o modo economico.
	 *
	 * @return bool
	 */
	public static function is_economy() {
		return 'economy' === self::tier();
	}

	/**
	 * Caminho do arquivo de skill a servir conforme o tier.
	 *
	 * @return string
	 */
	public static function skill_file() {
		$economy = MMCB_PLUGIN_DIR . 'SKILL.economy.md';
		if ( self::is_economy() && is_readable( $economy ) ) {
			return $economy;
		}
		return MMCB_PLUGIN_DIR . 'SKILL.md';
	}

	/**
	 * Limite padrao de itens em listagens conforme o tier.
	 *
	 * @param int $premium Limite no modo premium.
	 * @param int $economy Limite no modo economico.
	 * @return int
	 */
	public static function default_limit( $premium = 50, $economy = 15 ) {
		return self::is_economy() ? (int) $economy : (int) $premium;
	}

	/**
	 * Instrucao curta anunciada no handshake MCP (initialize.instructions).
	 *
	 * @return string
	 */
	public static function handshake_instructions() {
		$base = __( 'Leia a skill em /skill antes de agir. Sempre que precisar aplicar varias mudancas, use a tool run_batch para enviar tudo em UMA unica requisicao (muitos POSTs remotos podem ser tratados como intrusao por alguns hosts).', 'marreira-mcp-builders' );
		if ( self::is_economy() ) {
			$base .= ' ' . __( 'Modo economico: consuma contexto por partes (use get_map e liste com limites pequenos) em vez de puxar o site inteiro.', 'marreira-mcp-builders' );
		}
		return $base;
	}
}
