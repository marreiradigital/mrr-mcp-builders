<?php
/**
 * Base_Tools: utilitarios compartilhados pelas tools.
 *
 * @package Marreira\MCP_Builders
 */

namespace Marreira\MCP_Builders\Builders\Bricks\Tools;

use Marreira\MCP_Builders\MCP\Tool_Registry;
use Marreira\MCP_Builders\Builders\Bricks\Code_Guard;
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
	 * Garante que o Bricks esta ativo; senao retorna error_result.
	 *
	 * @return array|null
	 */
	protected static function require_bricks() {
		if ( ! class_exists( '\Bricks\Elements' ) && ! defined( 'BRICKS_VERSION' ) ) {
			return Tool_Registry::error_result(
				__( 'O tema/plugin Bricks Builder nao esta ativo neste site.', 'marreira-mcp-builders' )
			);
		}
		return null;
	}

	/**
	 * Retorna um aviso de assinatura se a arvore tiver elemento de codigo.
	 *
	 * @param array $tree Arvore de elementos.
	 * @return string String vazia ou o aviso prefixado por espaco.
	 */
	protected static function code_warning( $tree ) {
		return Code_Guard::contains_code( $tree ) ? ' ' . Code_Guard::sign_warning() : '';
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
