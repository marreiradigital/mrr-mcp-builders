<?php
/**
 * Elementor_Driver: driver do Elementor para o MCP Builders.
 *
 * Implementa Builder_Driver expondo as tools, snapshot de capacidades e
 * mapa compacto do site para o modo economico da IA.
 *
 * @package Marreira\MCP_Builders
 */

namespace Marreira\MCP_Builders\Builders\Elementor;

use Marreira\MCP_Builders\Builders\Builder_Driver;
use Marreira\MCP_Builders\MCP\Tool_Registry;
use Marreira\MCP_Builders\Auth\Rest_Guard;
use Marreira\MCP_Builders\Builders\Elementor\Tools\Page_Tools;
use Marreira\MCP_Builders\Builders\Elementor\Tools\Template_Tools;
use Marreira\MCP_Builders\Builders\Elementor\Tools\Element_Tools;
use Marreira\MCP_Builders\Builders\Elementor\Tools\Style_Tools;
use Marreira\MCP_Builders\Builders\Elementor\Tools\Util_Tools;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Driver do Elementor: registra tools, informa o ambiente e monta o mapa do site.
 *
 * O nucleo (servidor MCP, admin, auth) conversa exclusivamente com esta interface;
 * toda logica especifica do Elementor fica aqui e nas classes que este driver
 * delega.
 */
class Elementor_Driver implements Builder_Driver {

	/**
	 * Slug estavel do builder.
	 *
	 * @return string
	 */
	public function slug() {
		return 'elementor';
	}

	/**
	 * Rotulo legivel do builder.
	 *
	 * @return string
	 */
	public function label() {
		return 'Elementor';
	}

	/**
	 * Indica se o Elementor esta ativo no site.
	 *
	 * Condicao identica a Elementor_Gateway::is_elementor_active(), que e a
	 * mesma usada por Base_Tools::require_elementor().
	 *
	 * @return bool
	 */
	public function is_active() {
		return defined( 'ELEMENTOR_VERSION' ) && class_exists( '\Elementor\Plugin' );
	}

	/**
	 * Versao do Elementor instalada, ou null se nao disponivel.
	 *
	 * @return string|null
	 */
	public function version() {
		return defined( 'ELEMENTOR_VERSION' ) ? ELEMENTOR_VERSION : null;
	}

	/**
	 * Registra as tools do Elementor no registry compartilhado.
	 *
	 * @param Tool_Registry $registry Registro de tools.
	 * @return void
	 */
	public function register_tools( Tool_Registry $registry ) {
		Page_Tools::register( $registry );
		Template_Tools::register( $registry );
		Element_Tools::register( $registry );
		Style_Tools::register( $registry );
		Util_Tools::register( $registry );
	}

	/**
	 * Snapshot do ambiente (para get_capabilities e o painel).
	 *
	 * Retorna o mesmo array que Util_Tools::get_capabilities() constroi,
	 * sem o envelope Tool_Registry::success_result.
	 *
	 * @return array
	 */
	public function capabilities() {
		$settings = Rest_Guard::settings();

		return array(
			'plugin_version'    => MMCB_VERSION,
			'protocol_version'  => MMCB_MCP_PROTOCOL_VERSION,
			'elementor_active'  => Elementor_Gateway::is_elementor_active(),
			'elementor_version' => Elementor_Gateway::elementor_version(),
			'pro_active'        => Elementor_Gateway::is_pro_active(),
			'pro_version'       => defined( 'ELEMENTOR_PRO_VERSION' ) ? ELEMENTOR_PRO_VERSION : '',
			'kit_available'     => Global_Styles::available(),
			'kit_id'            => Global_Styles::available() ? Global_Styles::get_active_kit_id() : 0,
			'block_code'        => ! empty( $settings['block_code'] ),
			'https_only'        => ! empty( $settings['https_only'] ),
			'breakpoints'       => array(
				'mobile' => 'sufixo _mobile',
				'tablet' => 'sufixo _tablet',
				'note'   => 'Controles responsivos usam sufixos (_tablet, _mobile). Breakpoints reais ficam nas settings do Kit.',
			),
			'el_types'          => Element_Tree::EL_TYPES,
		);
	}

	/**
	 * Mapa compacto do site (paginas/templates/estilos) para o modo economico da IA.
	 *
	 * Usa Elementor_Gateway::list_pages(), list_templates() e Global_Styles::list_global_colors()
	 * / list_global_fonts(). Cada item de pagina/template e reduzido a poucos campos escalares.
	 *
	 * @param array $args Filtros opcionais. Suporta 'limit' (int, default 50).
	 * @return array
	 */
	public function map( array $args = array() ) {
		$limit = (int) ( isset( $args['limit'] ) ? $args['limit'] : 50 );

		// Paginas Elementor (list_pages retorna summarize_post(): id, title, post_type, status...).
		$raw_pages = Elementor_Gateway::list_pages( array( 'limit' => $limit ) );
		$pages     = array();
		foreach ( $raw_pages as $p ) {
			$pages[] = array(
				'id'     => isset( $p['id'] ) ? $p['id'] : 0,
				'title'  => isset( $p['title'] ) ? $p['title'] : '',
				'status' => isset( $p['status'] ) ? $p['status'] : '',
				'type'   => isset( $p['post_type'] ) ? $p['post_type'] : '',
			);
		}

		// Templates da biblioteca (list_templates retorna summarize_post() + template_type + conditions).
		$raw_templates = Elementor_Gateway::list_templates();
		$templates     = array();
		foreach ( $raw_templates as $t ) {
			$templates[] = array(
				'id'            => isset( $t['id'] ) ? $t['id'] : 0,
				'title'         => isset( $t['title'] ) ? $t['title'] : '',
				'template_type' => isset( $t['template_type'] ) ? $t['template_type'] : '',
			);
		}

		// Contagem de tokens de design globais.
		$colors_data   = Global_Styles::list_global_colors();
		$fonts_data    = Global_Styles::list_global_fonts();
		$global_colors = count( isset( $colors_data['system_colors'] ) ? $colors_data['system_colors'] : array() )
		               + count( isset( $colors_data['custom_colors'] ) ? $colors_data['custom_colors'] : array() );
		$global_fonts  = count( isset( $fonts_data['system_typography'] ) ? $fonts_data['system_typography'] : array() )
		               + count( isset( $fonts_data['custom_typography'] ) ? $fonts_data['custom_typography'] : array() );

		return array(
			'builder'       => 'elementor',
			'pages'         => $pages,
			'templates'     => $templates,
			'global_colors' => $global_colors,
			'global_fonts'  => $global_fonts,
		);
	}
}
