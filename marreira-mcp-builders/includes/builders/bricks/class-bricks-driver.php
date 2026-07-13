<?php
/**
 * Bricks_Driver: implementacao do Builder_Driver para o Bricks Builder.
 *
 * @package Marreira\MCP_Builders
 */

namespace Marreira\MCP_Builders\Builders\Bricks;

use Marreira\MCP_Builders\Builders\Builder_Driver;
use Marreira\MCP_Builders\MCP\Tool_Registry;
use Marreira\MCP_Builders\Builders\Bricks\Tools\Page_Tools;
use Marreira\MCP_Builders\Builders\Bricks\Tools\Template_Tools;
use Marreira\MCP_Builders\Builders\Bricks\Tools\Element_Tools;
use Marreira\MCP_Builders\Builders\Bricks\Tools\Style_Tools;
use Marreira\MCP_Builders\Builders\Bricks\Tools\Util_Tools;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Encapsula tudo que e especifico do Bricks Builder: registra as tools no
 * registry compartilhado, informa o ambiente e monta o mapa compacto do site.
 */
class Bricks_Driver implements Builder_Driver {

	/**
	 * Slug estavel do builder.
	 *
	 * @return string
	 */
	public function slug() {
		return 'bricks';
	}

	/**
	 * Rotulo legivel.
	 *
	 * @return string
	 */
	public function label() {
		return 'Bricks Builder';
	}

	/**
	 * Indica se o Bricks esta ativo no site.
	 *
	 * @return bool
	 */
	public function is_active() {
		return ( class_exists( '\Bricks\Elements' ) || defined( 'BRICKS_VERSION' ) );
	}

	/**
	 * Versao do Bricks instalada, ou null.
	 *
	 * @return string|null
	 */
	public function version() {
		return defined( 'BRICKS_VERSION' ) ? BRICKS_VERSION : null;
	}

	/**
	 * Registra as tools MCP do Bricks no registry compartilhado.
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
	 * Snapshot do ambiente do Bricks.
	 *
	 * Retorna o mesmo array associativo que Util_Tools::get_capabilities() monta
	 * internamente, sem o envelope Tool_Registry::success_result.
	 *
	 * @return array
	 */
	public function capabilities() {
		$breakpoints = get_option( 'bricks_breakpoints', array() );

		return array(
			'plugin_version'      => MMCB_VERSION,
			'mcp_protocol'        => MMCB_MCP_PROTOCOL_VERSION,
			'bricks_active'       => ( class_exists( '\Bricks\Elements' ) || defined( 'BRICKS_VERSION' ) ),
			'bricks_version'      => defined( 'BRICKS_VERSION' ) ? BRICKS_VERSION : null,
			'css_mode'            => Css_Regenerator::loading_method(),
			'css_needs_regen'     => Css_Regenerator::needs_regeneration(),
			'code_blocking'       => Code_Guard::is_blocking(),
			'content_meta_key'    => Bricks_Gateway::META_CONTENT,
			'breakpoints'         => is_array( $breakpoints ) ? $breakpoints : array(),
			'default_breakpoints' => array( 'tablet_portrait', 'mobile_landscape', 'mobile_portrait' ),
		);
	}

	/**
	 * Mapa compacto do site para o modo economico da IA.
	 *
	 * @param array $args Filtros opcionais. Suporta 'limit' (int, default 50).
	 * @return array
	 */
	public function map( array $args = array() ) {
		$limit = isset( $args['limit'] ) ? (int) $args['limit'] : 50;

		// Paginas/posts que usam o Bricks.
		$raw_pages = Bricks_Gateway::list_pages( array( 'limit' => $limit ) );
		$pages     = array();
		foreach ( $raw_pages as $p ) {
			$pages[] = array(
				'id'     => isset( $p['id'] ) ? $p['id'] : 0,
				'title'  => isset( $p['title'] ) ? $p['title'] : '',
				'status' => isset( $p['status'] ) ? $p['status'] : '',
				'type'   => isset( $p['post_type'] ) ? $p['post_type'] : '',
			);
		}

		// Templates Bricks (todos os tipos).
		$raw_templates = Bricks_Gateway::list_templates();
		$templates     = array();
		foreach ( $raw_templates as $t ) {
			$templates[] = array(
				'id'            => isset( $t['id'] ) ? $t['id'] : 0,
				'title'         => isset( $t['title'] ) ? $t['title'] : '',
				'template_type' => isset( $t['type'] ) ? $t['type'] : '',
			);
		}

		// Estilos globais (contagens).
		$global_classes = Global_Styles::get_global_classes();
		$palette        = Global_Styles::get_color_palette();
		$fonts          = Global_Styles::list_fonts();

		return array(
			'builder'        => 'bricks',
			'pages'          => $pages,
			'templates'      => $templates,
			'global_classes' => count( $global_classes ),
			'palette'        => count( $palette ),
			'fonts'          => count( $fonts ),
		);
	}
}
