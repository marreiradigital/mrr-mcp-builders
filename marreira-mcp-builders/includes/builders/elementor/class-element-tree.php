<?php
/**
 * Element_Tree: validacao e manipulacao da arvore ANINHADA de elementos Elementor.
 *
 * @package Marreira\MCP_Builders
 */

namespace Marreira\MCP_Builders\Builders\Elementor;

use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Opera sobre a arvore aninhada do Elementor. Cada elemento e um objeto:
 *
 *   {
 *     "id":         "a1b2c3d",                      // 7 chars [0-9a-f], unico no documento
 *     "elType":     "section|column|widget|container",
 *     "widgetType": "heading",                      // apenas quando elType === widget
 *     "settings":   { ... },
 *     "elements":   [ ...filhos... ],
 *     "isInner":    false
 *   }
 *
 * Diferente do Bricks (arvore plana com parent/children por id), aqui a
 * hierarquia e a propria aninhacao de `elements`. Todos os metodos preservam
 * campos desconhecidos (round-trip) e nunca tocam em nodes fora do escopo.
 */
class Element_Tree {

	const EL_TYPES = array( 'section', 'column', 'widget', 'container' );

	/**
	 * Gera um id de elemento de 7 caracteres hexadecimais (padrao Elementor).
	 *
	 * @param array $taken Ids ja em uso (por referencia: o novo id e adicionado).
	 * @return string
	 */
	public static function generate_id( array &$taken = array() ) {
		do {
			// 7 chars [0-9a-f], como os ids gerados pelo editor do Elementor.
			$id = substr( bin2hex( random_bytes( 4 ) ), 0, 7 );
		} while ( in_array( $id, $taken, true ) );

		$taken[] = $id;
		return $id;
	}

	/**
	 * Coleta recursivamente todos os ids presentes numa arvore.
	 *
	 * @param array $elements Arvore aninhada.
	 * @return string[]
	 */
	public static function collect_ids( array $elements ) {
		$ids = array();
		foreach ( $elements as $el ) {
			if ( ! is_array( $el ) ) {
				continue;
			}
			if ( isset( $el['id'] ) ) {
				$ids[] = (string) $el['id'];
			}
			if ( isset( $el['elements'] ) && is_array( $el['elements'] ) ) {
				$ids = array_merge( $ids, self::collect_ids( $el['elements'] ) );
			}
		}
		return $ids;
	}

	/**
	 * Valida a integridade da arvore aninhada.
	 *
	 * @param array      $elements Arvore.
	 * @param array|null $seen     Acumulador de ids vistos (interno).
	 * @return true|WP_Error
	 */
	public static function validate( array $elements, &$seen = null ) {
		if ( null === $seen ) {
			$seen = array();
		}

		foreach ( $elements as $index => $el ) {
			if ( ! is_array( $el ) ) {
				return self::err( sprintf( 'Elemento no indice %d nao e um objeto.', $index ) );
			}
			if ( empty( $el['elType'] ) || ! is_string( $el['elType'] ) ) {
				return self::err( sprintf( 'Elemento no indice %d sem "elType" valido.', $index ) );
			}
			if ( ! in_array( $el['elType'], self::EL_TYPES, true ) ) {
				return self::err( sprintf( 'elType invalido: "%s". Use: %s.', $el['elType'], implode( ', ', self::EL_TYPES ) ) );
			}
			if ( 'widget' === $el['elType'] && empty( $el['widgetType'] ) ) {
				return self::err( sprintf( 'Widget (id "%s") sem "widgetType".', isset( $el['id'] ) ? $el['id'] : '?' ) );
			}
			if ( isset( $el['id'] ) && '' !== (string) $el['id'] ) {
				$id = (string) $el['id'];
				if ( isset( $seen[ $id ] ) ) {
					return self::err( sprintf( 'Id duplicado no documento: "%s".', $id ) );
				}
				$seen[ $id ] = true;
			}

			$children = isset( $el['elements'] ) && is_array( $el['elements'] ) ? $el['elements'] : array();
			if ( $children ) {
				$res = self::validate( $children, $seen );
				if ( is_wp_error( $res ) ) {
					return $res;
				}
			}
		}

		return true;
	}

	/**
	 * Normaliza uma arvore vinda da IA: garante campos obrigatorios e ids unicos.
	 *
	 * Preserva ids ja existentes; gera id apenas quando ausente. Mantem campos
	 * desconhecidos (round-trip). Aplica recursivamente.
	 *
	 * @param array $elements Arvore.
	 * @param array $taken    Ids ja em uso (por referencia).
	 * @return array
	 */
	public static function normalize( array $elements, array &$taken = array() ) {
		$out = array();
		foreach ( $elements as $el ) {
			if ( ! is_array( $el ) ) {
				continue;
			}

			// id: preserva se valido e nao colide; senao gera.
			$id = isset( $el['id'] ) ? (string) $el['id'] : '';
			if ( '' === $id || in_array( $id, $taken, true ) ) {
				$id = self::generate_id( $taken );
			} else {
				$taken[] = $id;
			}
			$el['id'] = $id;

			$el['elType']   = isset( $el['elType'] ) ? (string) $el['elType'] : 'widget';
			$el['settings'] = isset( $el['settings'] ) && is_array( $el['settings'] ) ? $el['settings'] : array();

			if ( 'widget' === $el['elType'] && empty( $el['widgetType'] ) ) {
				$el['widgetType'] = '';
			}

			$children       = isset( $el['elements'] ) && is_array( $el['elements'] ) ? $el['elements'] : array();
			$el['elements'] = self::normalize( $children, $taken );

			$out[] = $el;
		}
		return $out;
	}

	/**
	 * Regenera recursivamente todos os ids de uma arvore (para colar subarvores
	 * sem colidir com ids ja presentes no documento destino).
	 *
	 * @param array $elements Arvore.
	 * @param array $taken    Ids ja em uso no destino (por referencia).
	 * @return array
	 */
	public static function regenerate_ids( array $elements, array &$taken = array() ) {
		$out = array();
		foreach ( $elements as $el ) {
			if ( ! is_array( $el ) ) {
				continue;
			}
			$el['id'] = self::generate_id( $taken );
			if ( isset( $el['elements'] ) && is_array( $el['elements'] ) ) {
				$el['elements'] = self::regenerate_ids( $el['elements'], $taken );
			}
			$out[] = $el;
		}
		return $out;
	}

	/**
	 * Localiza um node por id (busca recursiva). Retorna o node ou null.
	 *
	 * @param array  $elements Arvore.
	 * @param string $id       Id procurado.
	 * @return array|null
	 */
	public static function find( array $elements, $id ) {
		foreach ( $elements as $el ) {
			if ( ! is_array( $el ) ) {
				continue;
			}
			if ( isset( $el['id'] ) && (string) $el['id'] === (string) $id ) {
				return $el;
			}
			if ( isset( $el['elements'] ) && is_array( $el['elements'] ) ) {
				$found = self::find( $el['elements'], $id );
				if ( null !== $found ) {
					return $found;
				}
			}
		}
		return null;
	}

	/**
	 * Insere node(s) sob um parent (por id) ou na raiz.
	 *
	 * @param array       $elements  Arvore atual.
	 * @param array       $new_nodes Lista de nodes a inserir (ja normalizados).
	 * @param string|int  $parent_id Id do parent. Vazio/0/"root" insere na raiz.
	 * @param int|null    $position  Posicao entre os filhos (null = fim).
	 * @return array|WP_Error
	 */
	public static function insert( array $elements, array $new_nodes, $parent_id = '', $position = null ) {
		$parent_id = (string) $parent_id;
		$root      = ( '' === $parent_id || '0' === $parent_id || 'root' === $parent_id );

		if ( $root ) {
			return self::splice( $elements, $new_nodes, $position );
		}

		$found    = false;
		$elements = self::map( $elements, $parent_id, function ( $node ) use ( $new_nodes, $position, &$found ) {
			$children          = isset( $node['elements'] ) && is_array( $node['elements'] ) ? $node['elements'] : array();
			$node['elements']  = self::splice( $children, $new_nodes, $position );
			$found             = true;
			return $node;
		} );

		if ( ! $found ) {
			return self::err( sprintf( 'Parent "%s" nao encontrado para insercao.', $parent_id ) );
		}
		return $elements;
	}

	/**
	 * Remove um node (e sua subarvore) por id.
	 *
	 * @param array  $elements Arvore.
	 * @param string $id       Id a remover.
	 * @return array|WP_Error
	 */
	public static function remove( array $elements, $id ) {
		if ( null === self::find( $elements, $id ) ) {
			return self::err( sprintf( 'Elemento "%s" nao encontrado.', $id ) );
		}
		return self::filter_out( $elements, (string) $id );
	}

	/**
	 * Atualiza (merge ou replace) as settings de um node.
	 *
	 * @param array  $elements     Arvore.
	 * @param string $id           Id do node.
	 * @param array  $new_settings Settings.
	 * @param bool   $replace      true substitui; false faz merge raso por chave.
	 * @return array|WP_Error
	 */
	public static function update_settings( array $elements, $id, array $new_settings, $replace = false ) {
		$found    = false;
		$elements = self::map( $elements, (string) $id, function ( $node ) use ( $new_settings, $replace, &$found ) {
			$current          = isset( $node['settings'] ) && is_array( $node['settings'] ) ? $node['settings'] : array();
			$node['settings'] = $replace ? $new_settings : array_merge( $current, $new_settings );
			$found            = true;
			return $node;
		} );

		if ( ! $found ) {
			return self::err( sprintf( 'Elemento "%s" nao encontrado.', $id ) );
		}
		return $elements;
	}

	/**
	 * Move um node para outro parent/posicao.
	 *
	 * @param array      $elements   Arvore.
	 * @param string     $id         Id a mover.
	 * @param string|int $new_parent Id do novo parent (vazio/0/"root" = raiz).
	 * @param int|null   $position   Posicao no destino.
	 * @return array|WP_Error
	 */
	public static function move( array $elements, $id, $new_parent, $position = null ) {
		$node = self::find( $elements, $id );
		if ( null === $node ) {
			return self::err( sprintf( 'Elemento "%s" nao encontrado.', $id ) );
		}
		// Impede mover para dentro de si mesmo ou de um descendente.
		if ( (string) $new_parent !== '' && ( (string) $new_parent === (string) $id || null !== self::find( array( $node ), $new_parent ) ) ) {
			return self::err( 'Nao e possivel mover um elemento para dentro dele mesmo ou de um descendente.' );
		}

		$detached = self::filter_out( $elements, (string) $id );
		return self::insert( $detached, array( $node ), $new_parent, $position );
	}

	/**
	 * Duplica um node como irmao imediato, com ids novos na subarvore.
	 *
	 * @param array  $elements Arvore.
	 * @param string $id       Id a duplicar.
	 * @return array|WP_Error
	 */
	public static function duplicate( array $elements, $id ) {
		$node = self::find( $elements, $id );
		if ( null === $node ) {
			return self::err( sprintf( 'Elemento "%s" nao encontrado.', $id ) );
		}

		$taken = self::collect_ids( $elements );
		$clone = self::regenerate_ids( array( $node ), $taken );

		// Insere o clone logo apos o original, no mesmo nivel.
		return self::insert_after( $elements, (string) $id, $clone );
	}

	// -----------------------------------------------------------------------
	// Helpers internos.
	// -----------------------------------------------------------------------

	/**
	 * Insere $new_nodes numa lista de filhos na posicao dada (ou no fim).
	 *
	 * @param array    $list      Lista de filhos.
	 * @param array    $new_nodes Nodes a inserir.
	 * @param int|null $position  Posicao.
	 * @return array
	 */
	private static function splice( array $list, array $new_nodes, $position ) {
		$new_nodes = array_values( $new_nodes );
		if ( null === $position || $position >= count( $list ) ) {
			return array_merge( $list, $new_nodes );
		}
		$pos = max( 0, (int) $position );
		array_splice( $list, $pos, 0, $new_nodes );
		return array_values( $list );
	}

	/**
	 * Aplica $cb ao node de id $id (recursivo), retornando a arvore modificada.
	 *
	 * @param array    $elements Arvore.
	 * @param string   $id       Id alvo.
	 * @param callable $cb       Callback que recebe o node e retorna o node novo.
	 * @return array
	 */
	private static function map( array $elements, $id, callable $cb ) {
		$out = array();
		foreach ( $elements as $el ) {
			if ( is_array( $el ) && isset( $el['id'] ) && (string) $el['id'] === $id ) {
				$el = call_user_func( $cb, $el );
			} elseif ( is_array( $el ) && isset( $el['elements'] ) && is_array( $el['elements'] ) ) {
				$el['elements'] = self::map( $el['elements'], $id, $cb );
			}
			$out[] = $el;
		}
		return $out;
	}

	/**
	 * Remove recursivamente o node de id $id de qualquer nivel da arvore.
	 *
	 * @param array  $elements Arvore.
	 * @param string $id       Id.
	 * @return array
	 */
	private static function filter_out( array $elements, $id ) {
		$out = array();
		foreach ( $elements as $el ) {
			if ( is_array( $el ) && isset( $el['id'] ) && (string) $el['id'] === $id ) {
				continue;
			}
			if ( is_array( $el ) && isset( $el['elements'] ) && is_array( $el['elements'] ) ) {
				$el['elements'] = self::filter_out( $el['elements'], $id );
			}
			$out[] = $el;
		}
		return $out;
	}

	/**
	 * Insere $new_nodes imediatamente apos o node de id $id, no mesmo nivel.
	 *
	 * @param array  $elements  Arvore.
	 * @param string $id        Id de referencia.
	 * @param array  $new_nodes Nodes a inserir.
	 * @return array
	 */
	private static function insert_after( array $elements, $id, array $new_nodes ) {
		$out = array();
		foreach ( $elements as $el ) {
			$out[] = $el;
			if ( is_array( $el ) && isset( $el['id'] ) && (string) $el['id'] === $id ) {
				foreach ( array_values( $new_nodes ) as $n ) {
					$out[] = $n;
				}
				continue;
			}
			if ( is_array( $el ) && isset( $el['elements'] ) && is_array( $el['elements'] ) ) {
				$out[ count( $out ) - 1 ]['elements'] = self::insert_after( $el['elements'], $id, $new_nodes );
			}
		}
		return $out;
	}

	/**
	 * Helper de erro.
	 *
	 * @param string $message Mensagem.
	 * @return WP_Error
	 */
	private static function err( $message ) {
		return new WP_Error( 'mme_tree_invalid', $message, array( 'status' => 422 ) );
	}
}
