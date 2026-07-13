<?php
/**
 * Element_Tree: validacao e manipulacao da arvore plana de elementos Bricks.
 *
 * @package Marreira\MCP_Builders
 */

namespace Marreira\MCP_Builders\Builders\Bricks;

use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Opera sobre a arvore plana do Bricks (cada elemento tem id, name, parent,
 * children, settings, label). Garante integridade parent<->children, ids
 * unicos de 6 caracteres e normaliza valores.
 */
class Element_Tree {

	/**
	 * Gera um id de elemento de 6 caracteres (alfanumerico minusculo).
	 *
	 * Usa o helper nativo do Bricks quando disponivel para maxima compatibilidade.
	 *
	 * @param array $taken Ids ja em uso (para evitar colisao no fallback).
	 * @return string
	 */
	public static function generate_id( array $taken = array() ) {
		if ( class_exists( '\Bricks\Helpers' ) && method_exists( '\Bricks\Helpers', 'generate_random_id' ) ) {
			// O helper do Bricks ja garante unicidade global.
			return \Bricks\Helpers::generate_random_id( false );
		}

		// Fallback: 6 chars [a-z0-9].
		$chars = 'abcdefghijklmnopqrstuvwxyz0123456789';
		do {
			$id = '';
			for ( $i = 0; $i < 6; $i++ ) {
				$id .= $chars[ random_int( 0, strlen( $chars ) - 1 ) ];
			}
		} while ( in_array( $id, $taken, true ) );

		return $id;
	}

	/**
	 * Coleta todos os ids presentes numa arvore.
	 *
	 * @param array $elements Arvore plana.
	 * @return string[]
	 */
	public static function collect_ids( array $elements ) {
		$ids = array();
		foreach ( $elements as $el ) {
			if ( isset( $el['id'] ) ) {
				$ids[] = (string) $el['id'];
			}
		}
		return $ids;
	}

	/**
	 * Valida a integridade da arvore plana.
	 *
	 * @param array $elements Arvore plana de elementos.
	 * @return true|WP_Error
	 */
	public static function validate( array $elements ) {
		$by_id = array();

		foreach ( $elements as $index => $el ) {
			if ( ! is_array( $el ) ) {
				return self::err( sprintf( 'Elemento no indice %d nao e um objeto.', $index ) );
			}
			if ( empty( $el['id'] ) || ! is_string( $el['id'] ) ) {
				return self::err( sprintf( 'Elemento no indice %d sem "id" valido.', $index ) );
			}
			if ( empty( $el['name'] ) || ! is_string( $el['name'] ) ) {
				return self::err( sprintf( 'Elemento "%s" sem "name" valido.', $el['id'] ) );
			}
			if ( isset( $by_id[ $el['id'] ] ) ) {
				return self::err( sprintf( 'Id duplicado: "%s".', $el['id'] ) );
			}
			$by_id[ $el['id'] ] = $el;
		}

		// Verifica parent/children reciprocos.
		foreach ( $elements as $el ) {
			$id       = (string) $el['id'];
			$parent   = isset( $el['parent'] ) ? $el['parent'] : 0;
			$children = isset( $el['children'] ) && is_array( $el['children'] ) ? $el['children'] : array();

			// Parent precisa existir (ou ser 0/"0" para raiz).
			if ( 0 !== $parent && '0' !== (string) $parent ) {
				if ( ! isset( $by_id[ (string) $parent ] ) ) {
					return self::err( sprintf( 'Elemento "%s" referencia parent inexistente "%s".', $id, $parent ) );
				}
				// O parent precisa listar este elemento em children.
				$parent_children = isset( $by_id[ (string) $parent ]['children'] ) ? $by_id[ (string) $parent ]['children'] : array();
				if ( ! in_array( $id, array_map( 'strval', (array) $parent_children ), true ) ) {
					return self::err( sprintf( 'Inconsistencia: parent "%s" nao lista o filho "%s".', $parent, $id ) );
				}
			}

			// Cada child precisa existir e apontar de volta.
			foreach ( $children as $child_id ) {
				$child_id = (string) $child_id;
				if ( ! isset( $by_id[ $child_id ] ) ) {
					return self::err( sprintf( 'Elemento "%s" referencia child inexistente "%s".', $id, $child_id ) );
				}
				if ( (string) $by_id[ $child_id ]['parent'] !== $id ) {
					return self::err( sprintf( 'Inconsistencia: child "%s" nao aponta para o parent "%s".', $child_id, $id ) );
				}
			}
		}

		return true;
	}

	/**
	 * Normaliza uma arvore vinda da IA: garante campos obrigatorios e tipos.
	 *
	 * Nao altera ids existentes (preserva round-trip); apenas completa lacunas.
	 *
	 * @param array $elements Arvore.
	 * @return array
	 */
	public static function normalize( array $elements ) {
		$out = array();
		foreach ( $elements as $el ) {
			if ( ! is_array( $el ) ) {
				continue;
			}
			$el['parent']   = isset( $el['parent'] ) ? $el['parent'] : 0;
			$el['children'] = isset( $el['children'] ) && is_array( $el['children'] ) ? array_values( array_map( 'strval', $el['children'] ) ) : array();
			$el['settings'] = isset( $el['settings'] ) && is_array( $el['settings'] ) ? $el['settings'] : array();
			$out[]          = $el;
		}
		return $out;
	}

	/**
	 * Regenera todos os ids de uma arvore mantendo a topologia (para colar
	 * seccoes/clipboard sem colidir com ids existentes na pagina).
	 *
	 * @param array $elements Arvore plana.
	 * @param array $taken    Ids ja em uso na pagina destino.
	 * @return array Arvore com ids novos.
	 */
	public static function regenerate_ids( array $elements, array $taken = array() ) {
		$map = array();
		foreach ( $elements as $el ) {
			if ( isset( $el['id'] ) ) {
				$new           = self::generate_id( $taken );
				$taken[]       = $new;
				$map[ (string) $el['id'] ] = $new;
			}
		}

		$out = array();
		foreach ( $elements as $el ) {
			$old_id     = (string) $el['id'];
			$el['id']   = $map[ $old_id ];
			$old_parent = isset( $el['parent'] ) ? (string) $el['parent'] : '0';
			$el['parent'] = isset( $map[ $old_parent ] ) ? $map[ $old_parent ] : ( '0' === $old_parent || 0 === $el['parent'] ? 0 : $el['parent'] );

			if ( isset( $el['children'] ) && is_array( $el['children'] ) ) {
				$el['children'] = array_values(
					array_map(
						static function ( $cid ) use ( $map ) {
							$cid = (string) $cid;
							return isset( $map[ $cid ] ) ? $map[ $cid ] : $cid;
						},
						$el['children']
					)
				);
			}
			$out[] = $el;
		}

		return $out;
	}

	/**
	 * Insere um elemento (e sua subarvore opcional) sob um parent, atualizando
	 * a lista de children do parent. Round-trip-safe: nao toca em outros nodes.
	 *
	 * @param array       $elements   Arvore atual.
	 * @param array       $new_nodes  Node(s) a inserir (ja com ids unicos).
	 * @param string|int  $parent_id  Id do parent (0 para raiz).
	 * @param int|null    $position   Posicao entre os children do parent (null = fim).
	 * @return array|WP_Error Nova arvore.
	 */
	public static function insert( array $elements, array $new_nodes, $parent_id, $position = null ) {
		$root_id = '';
		foreach ( $new_nodes as $node ) {
			if ( (string) ( isset( $node['parent'] ) ? $node['parent'] : 0 ) === (string) $parent_id || empty( $node['parent'] ) ) {
				$root_id = (string) $node['id'];
				break;
			}
		}
		if ( '' === $root_id && ! empty( $new_nodes ) ) {
			$root_id = (string) $new_nodes[0]['id'];
		}

		// Acrescenta os novos nodes.
		$elements = array_merge( $elements, array_values( $new_nodes ) );

		if ( 0 === $parent_id || '0' === (string) $parent_id ) {
			return $elements;
		}

		// Atualiza children do parent.
		$found = false;
		foreach ( $elements as &$el ) {
			if ( (string) $el['id'] === (string) $parent_id ) {
				$children = isset( $el['children'] ) && is_array( $el['children'] ) ? $el['children'] : array();
				if ( null === $position || $position >= count( $children ) ) {
					$children[] = $root_id;
				} else {
					array_splice( $children, max( 0, (int) $position ), 0, $root_id );
				}
				$el['children'] = array_values( $children );
				$found          = true;
				break;
			}
		}
		unset( $el );

		if ( ! $found ) {
			return self::err( sprintf( 'Parent "%s" nao encontrado para insercao.', $parent_id ) );
		}

		return $elements;
	}

	/**
	 * Remove um elemento e toda a sua subarvore, limpando a referencia no parent.
	 *
	 * @param array      $elements   Arvore.
	 * @param string     $element_id Id a remover.
	 * @return array|WP_Error Nova arvore.
	 */
	public static function remove( array $elements, $element_id ) {
		$by_id = array();
		foreach ( $elements as $el ) {
			$by_id[ (string) $el['id'] ] = $el;
		}
		if ( ! isset( $by_id[ (string) $element_id ] ) ) {
			return self::err( sprintf( 'Elemento "%s" nao encontrado.', $element_id ) );
		}

		// Coleta a subarvore (descendentes).
		$to_remove = array();
		$stack     = array( (string) $element_id );
		while ( $stack ) {
			$cur               = array_pop( $stack );
			$to_remove[ $cur ] = true;
			$children          = isset( $by_id[ $cur ]['children'] ) ? (array) $by_id[ $cur ]['children'] : array();
			foreach ( $children as $c ) {
				$stack[] = (string) $c;
			}
		}

		$parent_id = (string) $by_id[ (string) $element_id ]['parent'];

		$out = array();
		foreach ( $elements as $el ) {
			$id = (string) $el['id'];
			if ( isset( $to_remove[ $id ] ) ) {
				continue;
			}
			// Limpa a referencia no parent do elemento removido.
			if ( $id === $parent_id && isset( $el['children'] ) && is_array( $el['children'] ) ) {
				$el['children'] = array_values(
					array_filter(
						$el['children'],
						static function ( $cid ) use ( $element_id ) {
							return (string) $cid !== (string) $element_id;
						}
					)
				);
			}
			$out[] = $el;
		}

		return $out;
	}

	/**
	 * Atualiza (merge) as settings de um elemento especifico.
	 *
	 * @param array  $elements      Arvore.
	 * @param string $element_id    Id do elemento.
	 * @param array  $new_settings  Settings a mesclar.
	 * @param bool   $replace       Se true, substitui; se false, faz merge raso por chave.
	 * @return array|WP_Error Nova arvore.
	 */
	public static function update_settings( array $elements, $element_id, array $new_settings, $replace = false ) {
		$found = false;
		foreach ( $elements as &$el ) {
			if ( (string) $el['id'] === (string) $element_id ) {
				$current        = isset( $el['settings'] ) && is_array( $el['settings'] ) ? $el['settings'] : array();
				$el['settings'] = $replace ? $new_settings : array_merge( $current, $new_settings );
				$found          = true;
				break;
			}
		}
		unset( $el );

		if ( ! $found ) {
			return self::err( sprintf( 'Elemento "%s" nao encontrado.', $element_id ) );
		}
		return $elements;
	}

	/**
	 * Helper de erro.
	 *
	 * @param string $message Mensagem.
	 * @return WP_Error
	 */
	private static function err( $message ) {
		return new WP_Error( 'mmb_tree_invalid', $message, array( 'status' => 422 ) );
	}
}
