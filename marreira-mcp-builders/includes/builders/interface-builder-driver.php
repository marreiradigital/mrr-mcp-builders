<?php
/**
 * Contrato que cada driver de builder implementa.
 *
 * @package Marreira\MCP_Builders
 */

namespace Marreira\MCP_Builders\Builders;

use Marreira\MCP_Builders\MCP\Tool_Registry;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Um driver encapsula tudo que e especifico de um page builder (Bricks ou
 * Elementor): registra suas tools no registry compartilhado, informa o
 * ambiente (capabilities) e monta um mapa compacto do site.
 *
 * O nucleo (servidor MCP, CLI, admin, auth) permanece agnostico e conversa
 * so com esta interface, via Builder_Manager.
 */
interface Builder_Driver {

	/**
	 * Slug estavel do builder ('bricks' | 'elementor').
	 *
	 * @return string
	 */
	public function slug();

	/**
	 * Rotulo legivel ('Bricks Builder', 'Elementor').
	 *
	 * @return string
	 */
	public function label();

	/**
	 * Indica se o builder correspondente esta ativo no site.
	 *
	 * @return bool
	 */
	public function is_active();

	/**
	 * Versao do builder instalada, ou null.
	 *
	 * @return string|null
	 */
	public function version();

	/**
	 * Registra as tools MCP deste builder no registry compartilhado.
	 *
	 * @param Tool_Registry $registry Registro de tools.
	 * @return void
	 */
	public function register_tools( Tool_Registry $registry );

	/**
	 * Snapshot do ambiente (para get_capabilities e o painel).
	 *
	 * @return array
	 */
	public function capabilities();

	/**
	 * Mapa compacto do site (paginas/templates/estilos) para o modo economico.
	 *
	 * @param array $args Filtros opcionais.
	 * @return array
	 */
	public function map( array $args = array() );
}
