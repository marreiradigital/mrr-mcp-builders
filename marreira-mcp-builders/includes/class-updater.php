<?php
/**
 * Atualizacao automatica pelo painel do WordPress, fora do WordPress.org.
 *
 * @package Marreira\MCP_Builders
 */

namespace Marreira\MCP_Builders;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Oferece atualizacao do plugin direto no wp-admin, servindo o zip das Releases
 * do GitHub.
 *
 * Usa o mecanismo OFICIAL do WordPress para plugins hospedados fora do
 * WordPress.org (nucleo, desde a 5.8): o header `Update URI:` do plugin declara
 * quem manda nas atualizacoes, e o nucleo dispara o filtro
 * `update_plugins_{hostname}` para aquele host (ver wp-includes/update.php).
 * Nao ha hack de `pre_set_site_transient_update_plugins` aqui.
 *
 * Dois efeitos do `Update URI`, ambos desejados:
 *  - o nucleo passa a NAO procurar atualizacao deste plugin no WordPress.org;
 *  - portanto um plugin homonimo publicado la nao consegue sequestrar a
 *    atualizacao deste (e exatamente para isso que o header foi criado).
 *
 * A checagem le um JSON no proprio site do plugin em vez da API do GitHub, que
 * limita a 60 requisicoes/hora POR IP sem autenticacao — em hospedagem
 * compartilhada varios sites saem pelo mesmo IP e a checagem falharia. O JSON e
 * gerado pelo scripts/build.ps1 a partir do header do plugin (mesma fonte unica
 * de versao do resto) e servido pelo GitHub Pages, via CDN e sem limite.
 *
 * Se este plugin um dia entrar no WordPress.org: apontar o `Update URI` para a
 * pagina dele la (ou remover o header) e remover esta classe do boot — o
 * WordPress.org nao permite plugin hospedado la se atualizando por fora.
 */
class Updater {

	/**
	 * Precisa ser IDENTICO ao `Update URI:` do header do plugin.
	 *
	 * O nome do filtro do nucleo traz o host do Update URI, entao os dois tem que
	 * concordar: se divergirem, o filtro nunca dispara e o plugin para de oferecer
	 * atualizacao — sem erro nenhum, so silencio. Ler o header em tempo de
	 * execucao custaria carregar wp-admin/includes/plugin.php em toda requisicao
	 * do site, entao os dois valores sao fixos e um job de CI trava a igualdade.
	 *
	 * @var string
	 */
	const UPDATE_URI = 'https://marreiradigital.github.io/mrr-mcp-builders/';

	/**
	 * Onde fica o manifesto de atualizacao (gerado pelo build).
	 *
	 * @var string
	 */
	const MANIFEST_URL = 'https://marreiradigital.github.io/mrr-mcp-builders/update.json';

	/**
	 * De onde o zip PODE vir. O manifesto diz o que instalar, entao ele nao pode
	 * ser a unica palavra sobre DE ONDE baixar: se o site que serve o JSON fosse
	 * comprometido, um `package` apontando pra qualquer lugar viraria execucao de
	 * codigo arbitrario no site do usuario. Prefixo fixo = o manifesto so escolhe
	 * a versao, nunca a origem.
	 *
	 * @var string
	 */
	const PACKAGE_PREFIX = 'https://github.com/marreiradigital/mrr-mcp-builders/releases/';

	/**
	 * Transient do manifesto (12h, mesma ordem de grandeza da checagem do nucleo).
	 *
	 * @var string
	 */
	const CACHE_KEY = 'mmcb_update_manifest';

	/**
	 * Registra os hooks.
	 *
	 * @return void
	 */
	public static function init() {
		$host = wp_parse_url( self::UPDATE_URI, PHP_URL_HOST );
		if ( ! is_string( $host ) || '' === $host ) {
			return;
		}

		add_filter( 'update_plugins_' . $host, array( __CLASS__, 'check' ), 10, 3 );
		add_action( 'upgrader_process_complete', array( __CLASS__, 'flush_cache' ), 10, 0 );

		// Checagem manual na lista de plugins: o manifesto fica 12h em cache, entao
		// sem isto nao ha como pedir "olha de novo agora" depois de sair uma versao.
		add_filter( 'plugin_action_links_' . MMCB_PLUGIN_BASENAME, array( __CLASS__, 'action_link' ) );
		add_action( 'admin_post_mmcb_check_update', array( __CLASS__, 'handle_manual_check' ) );
		add_action( 'admin_notices', array( __CLASS__, 'manual_check_notice' ) );

		// "Ver detalhes da versao" do aviso de atualizacao abre um modal que chama
		// a plugins_api — que so conhece o WordPress.org. Sem responder aqui, o
		// link do nosso proprio aviso de update abriria "plugin nao encontrado".
		add_filter( 'plugins_api', array( __CLASS__, 'plugin_info' ), 10, 3 );
	}

	/**
	 * Responde ao modal "Ver detalhes da versao" para este plugin.
	 *
	 * @param false|object|array $result Resultado acumulado.
	 * @param string             $action Acao pedida a plugins_api.
	 * @param object             $args   Argumentos (slug, etc).
	 * @return false|object|array
	 */
	public static function plugin_info( $result, $action, $args ) {
		$slug = dirname( MMCB_PLUGIN_BASENAME );

		if ( 'plugin_information' !== $action || ! isset( $args->slug ) || $slug !== $args->slug ) {
			return $result;
		}

		$manifest = self::manifest();
		if ( ! $manifest ) {
			return $result;
		}

		return (object) array(
			'name'          => 'MarreiraMCP Builders',
			'slug'          => $slug,
			'version'       => $manifest['version'],
			'author'        => '<a href="' . esc_url( $manifest['url'] ) . '">Paulo Marreira</a>',
			'homepage'      => $manifest['url'],
			'requires'      => $manifest['requires'],
			'requires_php'  => $manifest['requires_php'],
			'tested'        => $manifest['tested'],
			'download_link' => $manifest['package'],
			'trust_source'  => false,
			'sections'      => array(
				'description' => sprintf(
					/* translators: 1: URL do site do plugin, 2: URL das releases */
					__( '<p>Servidor MCP unificado para Bricks Builder e Elementor.</p><p>Este plugin é distribuído fora do WordPress.org: as atualizações vêm das <a href="%2$s" target="_blank" rel="noopener">Releases do GitHub</a>.</p><p><a href="%1$s" target="_blank" rel="noopener">Documentação completa</a></p>', 'marreira-mcp-builders' ),
					esc_url( $manifest['url'] ),
					'https://github.com/marreiradigital/mrr-mcp-builders/releases'
				),
				'changelog'   => sprintf(
					/* translators: 1: versao, 2: URL da release, 3: URL do changelog */
					__( '<p>O que mudou na versão %1$s está nas <a href="%2$s" target="_blank" rel="noopener">notas da release</a>.</p><p><a href="%3$s" target="_blank" rel="noopener">Changelog completo de todas as versões</a></p>', 'marreira-mcp-builders' ),
					esc_html( $manifest['version'] ),
					'https://github.com/marreiradigital/mrr-mcp-builders/releases/tag/v' . rawurlencode( $manifest['version'] ),
					'https://github.com/marreiradigital/mrr-mcp-builders/blob/main/marreira-mcp-builders/CHANGELOG.md'
				),
			),
		);
	}

	/**
	 * Acrescenta "Checar atualizacao" nas acoes do plugin na lista de plugins.
	 *
	 * @param string[] $links Links de acao.
	 * @return string[]
	 */
	public static function action_link( $links ) {
		if ( ! current_user_can( 'update_plugins' ) ) {
			return $links;
		}

		$url = wp_nonce_url( admin_url( 'admin-post.php?action=mmcb_check_update' ), 'mmcb_check_update' );

		$links[] = sprintf(
			'<a href="%s">%s</a>',
			esc_url( $url ),
			esc_html__( 'Checar atualização', 'marreira-mcp-builders' )
		);

		return $links;
	}

	/**
	 * Descarta o cache e refaz a checagem agora.
	 *
	 * @return void
	 */
	public static function handle_manual_check() {
		if ( ! current_user_can( 'update_plugins' ) ) {
			wp_die( esc_html__( 'Você não tem permissão para checar atualizações.', 'marreira-mcp-builders' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( 'mmcb_check_update' );

		// Ordem importa: limpa o nosso manifesto e o transient do nucleo ANTES de
		// mandar rechecar, senao wp_update_plugins() so releria o cache velho.
		self::flush_cache();
		delete_site_transient( 'update_plugins' );
		wp_update_plugins();

		$manifest = self::manifest();
		$result   = 'error';

		if ( is_array( $manifest ) && ! empty( $manifest['version'] ) ) {
			$result = version_compare( $manifest['version'], MMCB_VERSION, '>' ) ? $manifest['version'] : 'latest';
		}

		wp_safe_redirect( add_query_arg( 'mmcb_checked', rawurlencode( $result ), admin_url( 'plugins.php' ) ) );
		exit;
	}

	/**
	 * Aviso com o resultado da checagem manual.
	 *
	 * @return void
	 */
	public static function manual_check_notice() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- so exibe o resultado; a acao em si e protegida por nonce no admin-post.
		if ( empty( $_GET['mmcb_checked'] ) || ! current_user_can( 'update_plugins' ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$result = sanitize_text_field( wp_unslash( $_GET['mmcb_checked'] ) );

		if ( 'latest' === $result ) {
			$class   = 'notice-success';
			$message = sprintf(
				/* translators: %s: versao atual do plugin */
				__( 'MarreiraMCP Builders está atualizado (versão %s).', 'marreira-mcp-builders' ),
				MMCB_VERSION
			);
		} elseif ( 'error' === $result ) {
			$class   = 'notice-warning';
			$message = __( 'MarreiraMCP Builders: não foi possível checar atualizações agora. Tente novamente em instantes.', 'marreira-mcp-builders' );
		} else {
			$class   = 'notice-info';
			$message = sprintf(
				/* translators: 1: versao nova, 2: versao instalada */
				__( 'MarreiraMCP Builders %1$s disponível (você está na %2$s). Use "Atualizar agora" acima.', 'marreira-mcp-builders' ),
				$result,
				MMCB_VERSION
			);
		}

		printf(
			'<div class="notice %s is-dismissible"><p>%s</p></div>',
			esc_attr( $class ),
			esc_html( $message )
		);
	}

	/**
	 * Responde ao nucleo com a ultima versao publicada.
	 *
	 * IMPORTANTE: devolve o payload SEMPRE que conseguir ler o manifesto —
	 * inclusive quando nao ha versao nova. Nao e desperdicio, e o que faz a coluna
	 * "Atualizacoes automaticas" da lista de plugins existir.
	 *
	 * O nucleo (wp-includes/update.php) faz assim com o retorno deste filtro:
	 *
	 *   if ( ! $update ) { continue; }                       // nao entra em lugar nenhum
	 *   ...
	 *   if ( version_compare( $update->new_version, $plugin_data['Version'], '>' ) ) {
	 *       $updates->response[ $plugin_file ]  = $update;   // ha atualizacao
	 *   } else {
	 *       $updates->no_update[ $plugin_file ] = $update;   // sem atualizacao, mas SUPORTADO
	 *   }
	 *
	 * E a lista de plugins so marca `update-supported` (e so entao oferece o
	 * toggle de auto-update) para plugin que esteja em `response` OU em
	 * `no_update`. Ou seja: retornar false quando esta tudo em dia tiraria o
	 * plugin dos dois e a tela passaria a dizer "atualizacoes automaticas nao
	 * disponiveis para este plugin".
	 *
	 * Quem compara versao e o proprio nucleo, entao nao ha risco de oferecer
	 * downgrade se o site estiver numa versao mais nova que o manifesto.
	 *
	 * @param array|false $update      Resposta acumulada (false = sem atualizacao).
	 * @param array       $plugin_data Header do plugin sendo checado.
	 * @param string      $plugin_file Basename do plugin sendo checado.
	 * @return array|false
	 */
	public static function check( $update, $plugin_data, $plugin_file ) {
		// O filtro e por HOST, nao por plugin: qualquer outro plugin que declare
		// um Update URI no mesmo host cai aqui tambem.
		if ( MMCB_PLUGIN_BASENAME !== $plugin_file ) {
			return $update;
		}

		$manifest = self::manifest();
		if ( ! $manifest ) {
			// Sem manifesto (rede fora, JSON invalido) nao da pra afirmar nada.
			return $update;
		}

		return array(
			'slug'         => dirname( MMCB_PLUGIN_BASENAME ),
			'version'      => $manifest['version'],
			'url'          => $manifest['url'],
			'package'      => $manifest['package'],
			'requires'     => $manifest['requires'],
			'requires_php' => $manifest['requires_php'],
			'tested'       => $manifest['tested'],
		);
	}

	/**
	 * Le o manifesto (com cache) e valida o que veio.
	 *
	 * @return array|null Manifesto validado ou null.
	 */
	private static function manifest() {
		$cached = get_transient( self::CACHE_KEY );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$response = wp_remote_get(
			self::MANIFEST_URL,
			array(
				'timeout' => 10,
				'headers' => array( 'Accept' => 'application/json' ),
			)
		);

		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			// Falha de rede nao pode virar erro na tela do usuario: sem manifesto,
			// o plugin so nao oferece atualizacao. Cache curto pra tentar de novo.
			set_transient( self::CACHE_KEY, array(), HOUR_IN_SECONDS );
			return null;
		}

		$data = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		$data = self::validate( $data );

		set_transient( self::CACHE_KEY, is_array( $data ) ? $data : array(), 12 * HOUR_IN_SECONDS );

		return $data;
	}

	/**
	 * Valida o manifesto. Recusa tudo que nao bate com o esperado.
	 *
	 * @param mixed $data Conteudo decodificado do JSON.
	 * @return array|null
	 */
	private static function validate( $data ) {
		if ( ! is_array( $data ) || empty( $data['version'] ) || empty( $data['package'] ) ) {
			return null;
		}

		$version = (string) $data['version'];
		$package = (string) $data['package'];

		// Versao tem que ser semver, senao version_compare compara lixo.
		if ( ! preg_match( '/^\d+\.\d+\.\d+$/', $version ) ) {
			return null;
		}

		// O zip so pode vir das Releases deste repositorio. Ver PACKAGE_PREFIX.
		if ( 0 !== strpos( $package, self::PACKAGE_PREFIX ) ) {
			return null;
		}

		return array(
			'version'      => $version,
			'package'      => $package,
			'url'          => isset( $data['url'] ) ? esc_url_raw( (string) $data['url'] ) : '',
			'requires'     => isset( $data['requires'] ) ? (string) $data['requires'] : '',
			'requires_php' => isset( $data['requires_php'] ) ? (string) $data['requires_php'] : '',
			'tested'       => isset( $data['tested'] ) ? (string) $data['tested'] : '',
		);
	}

	/**
	 * Limpa o cache do manifesto apos qualquer upgrade.
	 *
	 * Sem isto, atualizar o plugin deixava o manifesto velho no transient e o
	 * painel continuava anunciando a versao nova por ate 12h depois de instalada.
	 *
	 * @return void
	 */
	public static function flush_cache() {
		delete_transient( self::CACHE_KEY );
	}
}
