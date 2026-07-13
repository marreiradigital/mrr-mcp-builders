<?php
/**
 * Element_Tools: edicao fina de elementos na arvore Bricks.
 *
 * @package Marreira\MCP_Builders
 */

namespace Marreira\MCP_Builders\Builders\Bricks\Tools;

use Marreira\MCP_Builders\MCP\Tool_Registry;
use Marreira\MCP_Builders\Builders\Bricks\Bricks_Gateway;
use Marreira\MCP_Builders\Builders\Bricks\Css_Regenerator;
use Marreira\MCP_Builders\Builders\Bricks\Element_Tree;
use Marreira\MCP_Builders\Builders\Bricks\Code_Guard;
use Marreira\MCP_Builders\Builders\Bricks\Security\Sanitizer;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Operacoes granulares: inserir, atualizar settings, mover, excluir e duplicar
 * elementos. Todas sao read-modify-write e validam a integridade da arvore.
 */
class Element_Tools extends Base_Tools {

	/**
	 * Registra as tools de elemento.
	 *
	 * @param Tool_Registry $registry Registro.
	 * @return void
	 */
	public static function register( Tool_Registry $registry ) {

		$registry->register(
			'insert_element',
			__( 'Insere um elemento (ou subarvore) sob um parent da pagina. Gera novos IDs.', 'marreira-mcp-builders' ),
			self::schema(
				array(
					'post_id'   => array( 'type' => 'integer', 'description' => 'ID do post.' ),
					'area'      => array( 'type' => 'string', 'description' => 'content, header ou footer. Padrao: content.' ),
					'parent_id' => array( 'type' => 'string', 'description' => 'ID do elemento pai, ou "0" para raiz. Padrao: 0.' ),
					'position'  => array( 'type' => 'integer', 'description' => 'Posicao entre os filhos do pai (opcional).' ),
					'element'   => array( 'type' => 'object', 'description' => 'Elemento unico { name, settings, label }.' ),
					'elements'  => array( 'type' => 'array', 'description' => 'Subarvore (formato clipboard ou array de nodes). Use no lugar de "element".' ),
				),
				array( 'post_id' )
			),
			array( __CLASS__, 'insert_element' )
		);

		$registry->register(
			'update_element_settings',
			__( 'Atualiza (merge) as settings de um elemento especifico.', 'marreira-mcp-builders' ),
			self::schema(
				array(
					'post_id'    => array( 'type' => 'integer', 'description' => 'ID do post.' ),
					'area'       => array( 'type' => 'string', 'description' => 'content, header ou footer. Padrao: content.' ),
					'element_id' => array( 'type' => 'string', 'description' => 'ID do elemento.' ),
					'settings'   => array( 'type' => 'object', 'description' => 'Settings a aplicar (cores como objeto; chaves responsivas com sufixo :tablet_portrait, etc.).' ),
					'replace'    => array( 'type' => 'boolean', 'description' => 'Se true, substitui todas as settings. Padrao: false (merge).' ),
				),
				array( 'post_id', 'element_id', 'settings' )
			),
			array( __CLASS__, 'update_element_settings' )
		);

		$registry->register(
			'move_element',
			__( 'Move um elemento para outro parent e/ou posicao.', 'marreira-mcp-builders' ),
			self::schema(
				array(
					'post_id'    => array( 'type' => 'integer', 'description' => 'ID do post.' ),
					'area'       => array( 'type' => 'string', 'description' => 'content, header ou footer. Padrao: content.' ),
					'element_id' => array( 'type' => 'string', 'description' => 'ID do elemento a mover.' ),
					'new_parent' => array( 'type' => 'string', 'description' => 'ID do novo parent, ou "0" para raiz.' ),
					'position'   => array( 'type' => 'integer', 'description' => 'Posicao no novo parent (opcional).' ),
				),
				array( 'post_id', 'element_id', 'new_parent' )
			),
			array( __CLASS__, 'move_element' )
		);

		$registry->register(
			'delete_element',
			__( 'Remove um elemento e toda a sua subarvore.', 'marreira-mcp-builders' ),
			self::schema(
				array(
					'post_id'    => array( 'type' => 'integer', 'description' => 'ID do post.' ),
					'area'       => array( 'type' => 'string', 'description' => 'content, header ou footer. Padrao: content.' ),
					'element_id' => array( 'type' => 'string', 'description' => 'ID do elemento.' ),
				),
				array( 'post_id', 'element_id' )
			),
			array( __CLASS__, 'delete_element' )
		);

		$registry->register(
			'duplicate_element',
			__( 'Duplica um elemento (e sua subarvore) como irmao, com novos IDs.', 'marreira-mcp-builders' ),
			self::schema(
				array(
					'post_id'    => array( 'type' => 'integer', 'description' => 'ID do post.' ),
					'area'       => array( 'type' => 'string', 'description' => 'content, header ou footer. Padrao: content.' ),
					'element_id' => array( 'type' => 'string', 'description' => 'ID do elemento a duplicar.' ),
				),
				array( 'post_id', 'element_id' )
			),
			array( __CLASS__, 'duplicate_element' )
		);
	}

	/**
	 * Carrega a arvore e verifica permissao de edicao do post.
	 *
	 * @param array  $args Argumentos.
	 * @param string $area Area resolvida (saida por referencia).
	 * @return array|\WP_Error Arvore atual ou erro/permission result.
	 */
	private static function load( array $args, &$area ) {
		$cap = self::require_cap( 'edit_pages' );
		if ( $cap ) {
			return new \WP_Error( 'mmb_cap', $cap['content'][0]['text'] );
		}
		$post_id = isset( $args['post_id'] ) ? (int) $args['post_id'] : 0;
		if ( ! get_post( $post_id ) || ! current_user_can( 'edit_post', $post_id ) ) {
			return new \WP_Error( 'mmb_perm', __( 'Post inexistente ou sem permissao de edicao.', 'marreira-mcp-builders' ) );
		}
		$area = isset( $args['area'] ) ? sanitize_key( $args['area'] ) : 'content';
		return Bricks_Gateway::get_elements( $post_id, $area );
	}

	/**
	 * Persiste a arvore apos validar integridade e disparar regeneracao de CSS.
	 *
	 * @param int    $post_id  Post.
	 * @param array  $elements Arvore.
	 * @param string $area     Area.
	 * @return array|\WP_Error
	 */
	private static function persist( $post_id, array $elements, $area ) {
		$valid = Element_Tree::validate( $elements );
		if ( is_wp_error( $valid ) ) {
			return $valid;
		}
		$saved = Bricks_Gateway::save_elements( $post_id, $elements, $area );
		if ( is_wp_error( $saved ) ) {
			return $saved;
		}
		Css_Regenerator::regenerate( $post_id );
		return $elements;
	}

	/**
	 * Handler: insert_element.
	 *
	 * @param array $args Argumentos.
	 * @return array
	 */
	public static function insert_element( array $args ) {
		$area     = 'content';
		$elements = self::load( $args, $area );
		if ( is_wp_error( $elements ) ) {
			return Tool_Registry::error_result( $elements->get_error_message() );
		}

		$post_id   = (int) $args['post_id'];
		$parent_id = isset( $args['parent_id'] ) ? (string) $args['parent_id'] : '0';
		$position  = isset( $args['position'] ) ? (int) $args['position'] : null;

		// Monta a subarvore a inserir.
		if ( isset( $args['elements'] ) ) {
			$subtree = Sanitizer::extract_content( $args['elements'] );
			$subtree = Sanitizer::deep_clean( is_array( $subtree ) ? $subtree : array() );
			if ( is_wp_error( $subtree ) ) {
				return self::from_error( $subtree );
			}
			$subtree = Element_Tree::normalize( $subtree );
		} elseif ( isset( $args['element'] ) && is_array( $args['element'] ) ) {
			$node = Sanitizer::deep_clean( $args['element'] );
			if ( is_wp_error( $node ) ) {
				return self::from_error( $node );
			}
			$subtree = array(
				array(
					'id'       => '__new__',
					'name'     => isset( $node['name'] ) ? sanitize_key( $node['name'] ) : 'block',
					'parent'   => 0,
					'children' => array(),
					'settings' => isset( $node['settings'] ) && is_array( $node['settings'] ) ? $node['settings'] : array(),
					'label'    => isset( $node['label'] ) ? sanitize_text_field( $node['label'] ) : '',
				),
			);
		} else {
			return Tool_Registry::error_result( __( 'Informe "element" (unico) ou "elements" (subarvore).', 'marreira-mcp-builders' ) );
		}

		// Anti-RCE na subarvore.
		$guard = Code_Guard::inspect_elements( $subtree );
		if ( is_wp_error( $guard ) ) {
			return self::from_error( $guard );
		}

		// IDs novos, sem colidir com os da pagina.
		$taken   = Element_Tree::collect_ids( $elements );
		$subtree = Element_Tree::regenerate_ids( $subtree, $taken );

		// Liga os roots da subarvore ao parent informado.
		$root_ids = self::root_ids( $subtree );
		foreach ( $subtree as &$node ) {
			if ( in_array( (string) $node['id'], $root_ids, true ) ) {
				$node['parent'] = ( '0' === $parent_id ) ? 0 : $parent_id;
			}
		}
		unset( $node );

		// Merge na arvore + atualiza children do parent.
		$elements = array_merge( $elements, $subtree );
		if ( '0' !== $parent_id && 0 !== (int) $parent_id ) {
			$elements = self::attach_to_parent( $elements, $parent_id, $root_ids, $position );
			if ( is_wp_error( $elements ) ) {
				return self::from_error( $elements );
			}
		}

		$result = self::persist( $post_id, $elements, $area );
		if ( is_wp_error( $result ) ) {
			return self::from_error( $result );
		}

		return Tool_Registry::success_result(
			array( 'inserted_root_ids' => $root_ids, 'elements' => $result ),
			__( 'Elemento inserido.', 'marreira-mcp-builders' ) . self::code_warning( $subtree )
		);
	}

	/**
	 * Handler: update_element_settings.
	 *
	 * @param array $args Argumentos.
	 * @return array
	 */
	public static function update_element_settings( array $args ) {
		$area     = 'content';
		$elements = self::load( $args, $area );
		if ( is_wp_error( $elements ) ) {
			return Tool_Registry::error_result( $elements->get_error_message() );
		}

		$settings = Sanitizer::prepare_settings( isset( $args['settings'] ) ? $args['settings'] : array() );
		if ( is_wp_error( $settings ) ) {
			return self::from_error( $settings );
		}

		$updated = Element_Tree::update_settings(
			$elements,
			(string) $args['element_id'],
			$settings,
			! empty( $args['replace'] )
		);
		if ( is_wp_error( $updated ) ) {
			return self::from_error( $updated );
		}

		$result = self::persist( (int) $args['post_id'], $updated, $area );
		if ( is_wp_error( $result ) ) {
			return self::from_error( $result );
		}

		return Tool_Registry::success_result(
			array( 'element_id' => (string) $args['element_id'] ),
			__( 'Settings do elemento atualizadas.', 'marreira-mcp-builders' )
		);
	}

	/**
	 * Handler: move_element.
	 *
	 * @param array $args Argumentos.
	 * @return array
	 */
	public static function move_element( array $args ) {
		$area     = 'content';
		$elements = self::load( $args, $area );
		if ( is_wp_error( $elements ) ) {
			return Tool_Registry::error_result( $elements->get_error_message() );
		}

		$element_id = (string) $args['element_id'];
		$new_parent = (string) $args['new_parent'];
		$position   = isset( $args['position'] ) ? (int) $args['position'] : null;

		$by_id = array();
		foreach ( $elements as $i => $el ) {
			$by_id[ (string) $el['id'] ] = $i;
		}
		if ( ! isset( $by_id[ $element_id ] ) ) {
			return Tool_Registry::error_result( __( 'Elemento nao encontrado.', 'marreira-mcp-builders' ) );
		}
		if ( '0' !== $new_parent && ! isset( $by_id[ $new_parent ] ) ) {
			return Tool_Registry::error_result( __( 'Novo parent nao encontrado.', 'marreira-mcp-builders' ) );
		}
		if ( $new_parent === $element_id ) {
			return Tool_Registry::error_result( __( 'Um elemento nao pode ser seu proprio parent.', 'marreira-mcp-builders' ) );
		}

		$old_parent = (string) $elements[ $by_id[ $element_id ] ]['parent'];

		// Remove do parent antigo.
		if ( '0' !== $old_parent && isset( $by_id[ $old_parent ] ) ) {
			$idx                          = $by_id[ $old_parent ];
			$elements[ $idx ]['children'] = array_values(
				array_filter(
					(array) $elements[ $idx ]['children'],
					static function ( $c ) use ( $element_id ) {
						return (string) $c !== $element_id;
					}
				)
			);
		}

		// Atualiza o parent do elemento.
		$elements[ $by_id[ $element_id ] ]['parent'] = ( '0' === $new_parent ) ? 0 : $new_parent;

		// Adiciona ao novo parent.
		if ( '0' !== $new_parent ) {
			$idx      = $by_id[ $new_parent ];
			$children = (array) $elements[ $idx ]['children'];
			if ( null === $position || $position >= count( $children ) ) {
				$children[] = $element_id;
			} else {
				array_splice( $children, max( 0, $position ), 0, $element_id );
			}
			$elements[ $idx ]['children'] = array_values( $children );
		}

		$result = self::persist( (int) $args['post_id'], $elements, $area );
		if ( is_wp_error( $result ) ) {
			return self::from_error( $result );
		}

		return Tool_Registry::success_result(
			array( 'moved' => $element_id, 'new_parent' => $new_parent ),
			__( 'Elemento movido.', 'marreira-mcp-builders' )
		);
	}

	/**
	 * Handler: delete_element.
	 *
	 * @param array $args Argumentos.
	 * @return array
	 */
	public static function delete_element( array $args ) {
		$area     = 'content';
		$elements = self::load( $args, $area );
		if ( is_wp_error( $elements ) ) {
			return Tool_Registry::error_result( $elements->get_error_message() );
		}

		$updated = Element_Tree::remove( $elements, (string) $args['element_id'] );
		if ( is_wp_error( $updated ) ) {
			return self::from_error( $updated );
		}

		$result = self::persist( (int) $args['post_id'], $updated, $area );
		if ( is_wp_error( $result ) ) {
			return self::from_error( $result );
		}

		return Tool_Registry::success_result(
			array( 'deleted' => (string) $args['element_id'] ),
			__( 'Elemento removido.', 'marreira-mcp-builders' )
		);
	}

	/**
	 * Handler: duplicate_element.
	 *
	 * @param array $args Argumentos.
	 * @return array
	 */
	public static function duplicate_element( array $args ) {
		$area     = 'content';
		$elements = self::load( $args, $area );
		if ( is_wp_error( $elements ) ) {
			return Tool_Registry::error_result( $elements->get_error_message() );
		}

		$element_id = (string) $args['element_id'];

		$by_id = array();
		foreach ( $elements as $el ) {
			$by_id[ (string) $el['id'] ] = $el;
		}
		if ( ! isset( $by_id[ $element_id ] ) ) {
			return Tool_Registry::error_result( __( 'Elemento nao encontrado.', 'marreira-mcp-builders' ) );
		}

		// Coleta a subarvore do elemento.
		$subtree_ids = array();
		$stack       = array( $element_id );
		while ( $stack ) {
			$cur                 = array_pop( $stack );
			$subtree_ids[ $cur ] = true;
			foreach ( (array) $by_id[ $cur ]['children'] as $c ) {
				$stack[] = (string) $c;
			}
		}
		$subtree = array();
		foreach ( $elements as $el ) {
			if ( isset( $subtree_ids[ (string) $el['id'] ] ) ) {
				$subtree[] = $el;
			}
		}

		// Novos ids e religar como irmao (mesmo parent).
		$taken   = Element_Tree::collect_ids( $elements );
		$subtree = Element_Tree::regenerate_ids( $subtree, $taken );

		$parent_id = (string) $by_id[ $element_id ]['parent'];
		$root_ids  = self::root_ids( $subtree );
		foreach ( $subtree as &$node ) {
			if ( in_array( (string) $node['id'], $root_ids, true ) ) {
				$node['parent'] = ( '0' === $parent_id ) ? 0 : $parent_id;
			}
		}
		unset( $node );

		$elements = array_merge( $elements, $subtree );
		if ( '0' !== $parent_id ) {
			$elements = self::attach_to_parent( $elements, $parent_id, $root_ids, null );
			if ( is_wp_error( $elements ) ) {
				return self::from_error( $elements );
			}
		}

		$result = self::persist( (int) $args['post_id'], $elements, $area );
		if ( is_wp_error( $result ) ) {
			return self::from_error( $result );
		}

		return Tool_Registry::success_result(
			array( 'duplicated_root_ids' => $root_ids ),
			__( 'Elemento duplicado.', 'marreira-mcp-builders' )
		);
	}

	/**
	 * Retorna os ids dos nodes raiz de uma subarvore (parent fora da subarvore).
	 *
	 * @param array $subtree Subarvore.
	 * @return string[]
	 */
	private static function root_ids( array $subtree ) {
		$ids = array();
		foreach ( $subtree as $el ) {
			$ids[ (string) $el['id'] ] = true;
		}
		$roots = array();
		foreach ( $subtree as $el ) {
			$parent = isset( $el['parent'] ) ? (string) $el['parent'] : '0';
			if ( '0' === $parent || 0 === $el['parent'] || ! isset( $ids[ $parent ] ) ) {
				$roots[] = (string) $el['id'];
			}
		}
		return $roots;
	}

	/**
	 * Liga uma lista de roots ao array de children de um parent.
	 *
	 * @param array       $elements  Arvore.
	 * @param string      $parent_id Parent.
	 * @param string[]    $root_ids  Roots a ligar.
	 * @param int|null    $position  Posicao.
	 * @return array|\WP_Error
	 */
	private static function attach_to_parent( array $elements, $parent_id, array $root_ids, $position ) {
		$found = false;
		foreach ( $elements as &$el ) {
			if ( (string) $el['id'] === (string) $parent_id ) {
				$children = isset( $el['children'] ) && is_array( $el['children'] ) ? $el['children'] : array();
				if ( null === $position || $position >= count( $children ) ) {
					$children = array_merge( $children, $root_ids );
				} else {
					array_splice( $children, max( 0, (int) $position ), 0, $root_ids );
				}
				$el['children'] = array_values( $children );
				$found          = true;
				break;
			}
		}
		unset( $el );
		if ( ! $found ) {
			return new \WP_Error( 'mmb_parent_missing', __( 'Parent nao encontrado para anexar.', 'marreira-mcp-builders' ) );
		}
		return $elements;
	}
}
