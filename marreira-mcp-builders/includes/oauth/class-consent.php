<?php
/**
 * Endpoint /authorize: consentimento do admin + emissao do authorization code.
 *
 * @package Marreira\MCP_Builders
 */

namespace Marreira\MCP_Builders\OAuth;

use Marreira\MCP_Builders\Activator;
use Marreira\MCP_Builders\Security\Audit_Log;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Tela de autorizacao OAuth. Servida na raiz do site (via OAuth\Router no hook
 * init), NAO como rota REST — assim usa o cookie de sessao do WordPress e o
 * fluxo de login nativo sem esbarrar em rest_cookie_check_errors.
 *
 * Fluxo:
 *   - valida response_type/PKCE/client/redirect_uri;
 *   - exige admin logado com manage_options (senao redireciona pro wp-login);
 *   - GET: renderiza a tela de consentimento (com nonce);
 *   - POST: confere nonce e, se autorizado, gera o code single-use e redireciona
 *     para o redirect_uri do cliente (?code=...&state=...).
 */
class Consent {

	/**
	 * Trata a requisicao ao /authorize e encerra (sempre com exit).
	 *
	 * @return void
	 */
	public static function handle() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- OAuth params vem do Authorization Server metadata; o consentimento em si e protegido por nonce no POST.
		$response_type  = isset( $_REQUEST['response_type'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['response_type'] ) ) : '';
		$client_id      = isset( $_REQUEST['client_id'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['client_id'] ) ) : '';
		$redirect_uri   = isset( $_REQUEST['redirect_uri'] ) ? esc_url_raw( wp_unslash( $_REQUEST['redirect_uri'] ) ) : '';
		$scope          = isset( $_REQUEST['scope'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['scope'] ) ) : '';
		$state          = isset( $_REQUEST['state'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['state'] ) ) : '';
		$code_challenge = isset( $_REQUEST['code_challenge'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['code_challenge'] ) ) : '';
		$cc_method      = isset( $_REQUEST['code_challenge_method'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['code_challenge_method'] ) ) : '';
		$method         = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : 'GET';
		$is_post        = ( 'POST' === $method );
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		// 0) Metodo: /authorize so aceita GET (form) e POST (decisao).
		if ( 'GET' !== $method && 'POST' !== $method ) {
			status_header( 405 );
			header( 'Allow: GET, POST' );
			header( 'Content-Type: application/json; charset=utf-8' );
			echo wp_json_encode( array( 'error' => 'method_not_allowed' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			exit;
		}

		// 1) Validacao dos parametros do protocolo (antes de qualquer redirect).
		if ( 'code' !== $response_type ) {
			self::error_page( __( 'Parâmetro response_type inválido (esperado "code").', 'marreira-mcp-builders' ) );
		}
		if ( 'S256' !== $cc_method || strlen( $code_challenge ) < 43 || strlen( $code_challenge ) > 128 ) {
			self::error_page( __( 'PKCE obrigatório: code_challenge_method deve ser S256.', 'marreira-mcp-builders' ) );
		}

		$client = Client_Manager::find_by_client_id( $client_id );
		if ( ! $client ) {
			self::error_page( __( 'Cliente OAuth não encontrado.', 'marreira-mcp-builders' ) );
		}
		if ( 'approved' !== $client['status'] ) {
			self::error_page( __( 'Este cliente ainda não foi aprovado pelo administrador. Aprove-o no painel do plugin e tente novamente.', 'marreira-mcp-builders' ) );
		}

		// redirect_uri precisa bater EXATAMENTE com um dos registrados (anti open-redirect).
		$registered = Client_Manager::redirect_uris( $client );
		if ( '' === $redirect_uri || ! in_array( $redirect_uri, $registered, true ) ) {
			self::error_page( __( 'redirect_uri não corresponde a nenhum registrado para este cliente.', 'marreira-mcp-builders' ) );
		}

		// 2) Exige admin logado. Se nao estiver, manda pro login e volta pra ca.
		if ( ! is_user_logged_in() || ! current_user_can( 'manage_options' ) ) {
			$self = self::current_url();
			wp_safe_redirect( wp_login_url( $self ) );
			exit;
		}

		$settings  = wp_parse_args( (array) get_option( Activator::SETTINGS_OPTION, array() ), Activator::default_settings() );
		$requested = Scopes::parse( $scope );
		if ( empty( $requested ) ) {
			$requested = Scopes::SAFE;
		}

		// 3) POST = decisao do admin (protegida por nonce).
		if ( $is_post ) {
			check_admin_referer( 'mmcb_oauth_consent_' . $client_id );

			if ( ! isset( $_POST['mmcb_authorize'] ) ) {
				// Negado.
				Audit_Log::log(
					array(
						'method'           => 'POST',
						'route'            => MMCB_OAUTH_BASE_PATH . '/authorize',
						'action'           => 'oauth:authorize_denied',
						'status_code'      => 302,
						'user_id'          => get_current_user_id(),
						'ip'               => Audit_Log::client_ip(),
						'response_summary' => 'Consentimento negado para ' . $client_id,
						'success'          => false,
					)
				);
				wp_redirect( add_query_arg( self::present( array( 'error' => 'access_denied', 'state' => $state ) ), $redirect_uri ) ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect
				exit;
			}

			// Escopos marcados no formulario, limitados aos pedidos e concediveis.
			$posted = isset( $_POST['scopes'] ) && is_array( $_POST['scopes'] ) ? array_map( 'sanitize_text_field', wp_unslash( $_POST['scopes'] ) ) : array();
			$granted = array();
			foreach ( $requested as $s ) {
				if ( in_array( $s, $posted, true ) && Scopes::is_grantable( $s, $settings ) ) {
					$granted[] = $s;
				}
			}
			if ( empty( $granted ) ) {
				$granted = Scopes::SAFE;
			}

			$code = Code_Manager::generate( $client_id, get_current_user_id(), implode( ' ', $granted ), $redirect_uri, $code_challenge );

			Audit_Log::log(
				array(
					'method'           => 'POST',
					'route'            => MMCB_OAUTH_BASE_PATH . '/authorize',
					'action'           => 'oauth:authorize_granted',
					'status_code'      => 302,
					'user_id'          => get_current_user_id(),
					'ip'               => Audit_Log::client_ip(),
					'response_summary' => 'Consentimento concedido para ' . $client_id . ' (escopos: ' . implode( ',', $granted ) . ')',
					'success'          => true,
				)
			);

			wp_redirect( add_query_arg( self::present( array( 'code' => $code, 'state' => $state ) ), $redirect_uri ) ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect
			exit;
		}

		// 4) GET = renderiza a tela de consentimento.
		self::render_form( $client, $redirect_uri, $scope, $state, $code_challenge, $cc_method, $requested, $settings );
	}

	/**
	 * Remove do array so o que nao foi enviado (string vazia / null), mantendo
	 * valores "falsy" que sao legitimos.
	 *
	 * Existe porque array_filter() sem callback descarta '0', e a RFC 6749 §4.1.2
	 * exige devolver o state EXATAMENTE como veio: um cliente que usasse state='0'
	 * recebia o redirect sem state, achava que era CSRF e abortava a conexao — com
	 * o admin tendo autorizado normalmente.
	 *
	 * @param array $args Pares chave => valor.
	 * @return array
	 */
	private static function present( array $args ) {
		return array_filter(
			$args,
			static function ( $value ) {
				return null !== $value && '' !== $value;
			}
		);
	}

	/**
	 * URL absoluta da requisicao atual (para o retorno pos-login).
	 *
	 * @return string
	 */
	private static function current_url() {
		// Host e esquema saem do home_url(), nao do HTTP_HOST: o header e escrito
		// pelo cliente e pode ser forjado — o valor daqui vira o redirect_to do
		// wp-login. Na pratica o WordPress ja barraria o redirect forjado no
		// wp_safe_redirect, mas nao ha motivo pra depender disso: so o CAMINHO
		// precisa vir da requisicao, e ele nao decide destino de host nenhum.
		$path = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$path = '/' . ltrim( (string) $path, '/' );

		return esc_url_raw( home_url( $path ) );
	}

	/**
	 * Renderiza a tela de consentimento e encerra.
	 *
	 * @param array  $client         Linha do client.
	 * @param string $redirect_uri   Redirect validado.
	 * @param string $scope          Escopo solicitado (cru).
	 * @param string $state          State do cliente.
	 * @param string $code_challenge Desafio PKCE.
	 * @param string $cc_method      Metodo PKCE.
	 * @param array  $requested      Escopos pedidos (reconhecidos).
	 * @param array  $settings       Settings do plugin.
	 * @return void
	 */
	private static function render_form( $client, $redirect_uri, $scope, $state, $code_challenge, $cc_method, array $requested, array $settings ) {
		$labels      = Scopes::labels();
		$client_name = '' !== $client['client_name'] ? $client['client_name'] : $client['client_id'];
		$action_url  = esc_url( home_url( MMCB_OAUTH_BASE_PATH . '/authorize' ) );

		status_header( 200 );
		header( 'Content-Type: text/html; charset=utf-8' );
		header( 'Cache-Control: no-store' );
		header( 'X-Robots-Tag: noindex' );

		?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
	<meta charset="utf-8">
	<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
	<title><?php esc_html_e( 'Autorizar acesso — MarreiraMCP', 'marreira-mcp-builders' ); ?></title>
	<style>
		/* Design system do plugin: claro (creme/terracota) padrão, escuro pelo sistema. */
		:root{
			--bg:#faf9f5;--surface:#ffffff;--surface-2:#f5f3ee;--ink:#1a1614;--muted:#6b6460;
			--border:#e0dbd4;--accent:#c96442;--accent-2:#b55a39;--on-accent:#ffffff;
			--danger:#b83030;--danger-border:rgba(184,48,48,.4);--danger-soft:rgba(184,48,48,.06);
			--uri:#8c5a2b;--serif:Georgia,"Times New Roman",ui-serif,serif;
		}
		@media (prefers-color-scheme: dark){
			:root{
				--bg:#141210;--surface:#1c1917;--surface-2:#232019;--ink:#f0ede8;--muted:#9a928a;
				--border:#2e2a26;--accent:#d47050;--accent-2:#e07d5a;--on-accent:#14100e;
				--danger:#d4534a;--danger-border:rgba(212,83,74,.45);--danger-soft:rgba(212,83,74,.1);
				--uri:#c9a35f;
			}
		}
		*{box-sizing:border-box}
		body{margin:0;background:var(--bg);color:var(--ink);font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif;line-height:1.5;padding:16px;display:flex;min-height:100vh;align-items:center;justify-content:center}
		.card{background:var(--surface);border:1px solid var(--border);border-radius:16px;max-width:520px;width:100%;padding:26px;box-shadow:0 2px 6px rgba(26,22,20,.06),0 12px 28px -16px rgba(26,22,20,.2)}
		h1{font-size:1.3rem;margin:0 0 4px;font-family:var(--serif);font-weight:700;letter-spacing:-.2px}
		.sub{color:var(--muted);font-size:.9rem;margin:0 0 18px}
		.client{background:var(--surface-2);border:1px solid var(--border);border-radius:10px;padding:12px 14px;margin-bottom:18px;word-break:break-word;overflow-wrap:anywhere}
		.client strong{color:var(--ink)}
		.client .uri{color:var(--uri);font-size:.82rem;font-family:ui-monospace,Consolas,monospace}
		.scopes{list-style:none;margin:0 0 18px;padding:0;display:flex;flex-direction:column;gap:8px}
		.scopes li{display:flex;gap:10px;align-items:flex-start;background:var(--surface-2);border:1px solid var(--border);border-radius:10px;padding:10px 12px}
		.scopes li.danger{border-color:var(--danger-border);background:var(--danger-soft)}
		.scopes input{margin-top:3px;width:18px;height:18px;flex:0 0 auto;accent-color:var(--accent)}
		.scopes .txt{font-size:.9rem}
		.scopes .tag{display:inline-block;font-size:.7rem;color:var(--danger);border:1px solid var(--danger-border);border-radius:6px;padding:1px 6px;margin-left:6px;font-weight:700}
		.scopes .off{color:var(--muted);font-size:.75rem;margin-left:6px}
		.actions{display:flex;gap:10px;flex-wrap:wrap}
		button{flex:1 1 auto;min-height:44px;border:1px solid var(--border);border-radius:10px;font-size:.95rem;font-weight:600;cursor:pointer;padding:10px 16px}
		button:focus-visible{outline:2px solid var(--accent);outline-offset:2px}
		.approve{background:var(--accent);border-color:var(--accent);color:var(--on-accent);font-weight:700}
		.approve:hover{background:var(--accent-2);border-color:var(--accent-2)}
		.deny{background:var(--surface-2);color:var(--ink)}
		.foot{color:var(--muted);font-size:.75rem;margin-top:16px;text-align:center}
	</style>
</head>
<body>
	<div class="card">
		<h1><?php esc_html_e( 'Autorizar conexão MCP', 'marreira-mcp-builders' ); ?></h1>
		<p class="sub"><?php esc_html_e( 'Um aplicativo de IA quer se conectar ao seu site como servidor MCP.', 'marreira-mcp-builders' ); ?></p>

		<div class="client">
			<strong><?php echo esc_html( $client_name ); ?></strong><br>
			<span class="uri"><?php echo esc_html( $redirect_uri ); ?></span>
		</div>

		<form method="post" action="<?php echo $action_url; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- ja escapado com esc_url acima. ?>">
			<?php wp_nonce_field( 'mmcb_oauth_consent_' . $client['client_id'] ); ?>
			<input type="hidden" name="response_type" value="code">
			<input type="hidden" name="client_id" value="<?php echo esc_attr( $client['client_id'] ); ?>">
			<input type="hidden" name="redirect_uri" value="<?php echo esc_attr( $redirect_uri ); ?>">
			<input type="hidden" name="scope" value="<?php echo esc_attr( $scope ); ?>">
			<input type="hidden" name="state" value="<?php echo esc_attr( $state ); ?>">
			<input type="hidden" name="code_challenge" value="<?php echo esc_attr( $code_challenge ); ?>">
			<input type="hidden" name="code_challenge_method" value="<?php echo esc_attr( $cc_method ); ?>">

			<p class="sub"><?php esc_html_e( 'Permissões solicitadas:', 'marreira-mcp-builders' ); ?></p>
			<ul class="scopes">
				<?php foreach ( $requested as $s ) : ?>
					<?php
					$danger    = Scopes::is_dangerous( $s );
					$grantable = Scopes::is_grantable( $s, $settings );
					$label     = isset( $labels[ $s ] ) ? $labels[ $s ] : $s;
					?>
					<li class="<?php echo $danger ? 'danger' : ''; ?>">
						<input type="checkbox" name="scopes[]" value="<?php echo esc_attr( $s ); ?>"
							<?php echo ( ! $danger ) ? 'checked' : ''; ?>
							<?php echo ( ! $grantable ) ? 'disabled' : ''; ?>>
						<span class="txt">
							<?php echo esc_html( $label ); ?>
							<?php if ( $danger ) : ?><span class="tag"><?php esc_html_e( 'sensível', 'marreira-mcp-builders' ); ?></span><?php endif; ?>
							<?php if ( ! $grantable ) : ?><span class="off"><?php esc_html_e( '(bloqueado nas configurações)', 'marreira-mcp-builders' ); ?></span><?php endif; ?>
						</span>
					</li>
				<?php endforeach; ?>
			</ul>

			<div class="actions">
				<button type="submit" name="mmcb_authorize" value="1" class="approve"><?php esc_html_e( 'Autorizar', 'marreira-mcp-builders' ); ?></button>
				<button type="submit" name="mmcb_deny" value="1" class="deny"><?php esc_html_e( 'Negar', 'marreira-mcp-builders' ); ?></button>
			</div>
		</form>

		<p class="foot"><?php echo esc_html( sprintf( /* translators: %s: nome do usuario admin */ __( 'Conectado como %s (administrador).', 'marreira-mcp-builders' ), wp_get_current_user()->display_name ) ); ?></p>
	</div>
</body>
</html>
		<?php
		exit;
	}

	/**
	 * Pagina de erro simples (sem redirect) e encerra.
	 *
	 * @param string $message Mensagem.
	 * @return void
	 */
	private static function error_page( $message ) {
		status_header( 400 );
		header( 'Content-Type: text/html; charset=utf-8' );
		header( 'Cache-Control: no-store' );
		header( 'X-Robots-Tag: noindex' );
		echo '<!DOCTYPE html><html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>';
		esc_html_e( 'Erro de autorização', 'marreira-mcp-builders' );
		echo '</title><style>:root{--bg:#faf9f5;--ink:#1a1614;--muted:#6b6460}@media (prefers-color-scheme: dark){:root{--bg:#141210;--ink:#f0ede8;--muted:#9a928a}}</style></head>';
		echo '<body style="font-family:sans-serif;background:var(--bg);color:var(--ink);padding:24px;max-width:520px;margin:0 auto">';
		echo '<h1 style="font-size:1.2rem;font-family:Georgia,ui-serif,serif">' . esc_html__( 'Não foi possível autorizar', 'marreira-mcp-builders' ) . '</h1>';
		echo '<p style="color:var(--muted)">' . esc_html( $message ) . '</p>';
		echo '</body></html>';
		exit;
	}
}
