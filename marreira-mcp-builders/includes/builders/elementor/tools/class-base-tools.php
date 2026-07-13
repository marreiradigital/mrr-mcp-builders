<?php
/**
 * Base_Tools: utilitarios compartilhados pelas tools.
 *
 * @package Marreira\MCP_Builders
 */

namespace Marreira\MCP_Builders\Builders\Elementor\Tools;

use Marreira\MCP_Builders\MCP\Tool_Registry;
use Marreira\MCP_Builders\Builders\Elementor\Code_Guard;
use Marreira\MCP_Builders\Builders\Elementor\Elementor_Gateway;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Base com helpers de capability e conversao de erros.
 */
abstract class Base_Tools {

	/**
	 * Verifica uma capability. Retorna null se ok, ou um error_result.
	 *
	 * @param string $cap Capability requerida.
	 * @return array|null
	 */
	protected static function require_cap( $cap ) {
		if ( ! current_user_can( $cap ) ) {
			return Tool_Registry::error_result(
				sprintf(
					/* translators: %s: capability */
					__( 'Permissao insuficiente (requer "%s"). Verifique o usuario de servico configurado.', 'marreira-mcp-builders' ),
					$cap
				)
			);
		}
		return null;
	}

	/**
	 * Converte um WP_Error em error_result da tool.
	 *
	 * @param WP_Error $error Erro.
	 * @return array
	 */
	protected static function from_error( WP_Error $error ) {
		return Tool_Registry::error_result( $error->get_error_message() );
	}

	/**
	 * Garante que o Elementor esta ativo; senao retorna error_result.
	 *
	 * @return array|null
	 */
	protected static function require_elementor() {
		if ( ! Elementor_Gateway::is_elementor_active() ) {
			return Tool_Registry::error_result(
				__( 'O plugin Elementor nao esta ativo neste site.', 'marreira-mcp-builders' )
			);
		}
		return null;
	}

	/**
	 * Retorna um aviso de codigo se a arvore tiver widget que executa codigo.
	 *
	 * @param array $tree Arvore aninhada de elementos.
	 * @return string String vazia ou o aviso prefixado por espaco.
	 */
	protected static function code_warning( $tree ) {
		return Code_Guard::contains_code( $tree ) ? ' ' . Code_Guard::run_warning() : '';
	}

	/**
	 * Esquema JSON base do tipo object.
	 *
	 * @param array $properties Propriedades.
	 * @param array $required   Campos obrigatorios.
	 * @return array
	 */
	protected static function schema( array $properties, array $required = array() ) {
		$schema = array(
			'type'       => 'object',
			'properties' => $properties,
		);
		if ( ! empty( $required ) ) {
			$schema['required'] = $required;
		}
		return $schema;
	}
}
