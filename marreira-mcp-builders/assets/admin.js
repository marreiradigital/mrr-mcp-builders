/**
 * MarreiraMCP Builders — painel admin 100% AJAX (SPA).
 * Sem dependências externas; usa jQuery do WP apenas para FormData do post().
 */
( function () {
	'use strict';

	var API   = ( window.MMCB && MMCB.ajaxurl ) || '';
	var NONCE = ( window.MMCB && MMCB.nonce )   || '';
	var root  = document.getElementById( 'mmcb-app' );
	if ( ! root ) { return; }

	// ---- tema claro/escuro ----
	// Sem preferência salva, a media query prefers-color-scheme do CSS decide.
	// Roda síncrono, antes de qualquer render, para não piscar o tema errado.
	var THEME_KEY = 'mmcb-admin-theme';

	function initTheme() {
		try {
			var stored = localStorage.getItem( THEME_KEY );
			if ( stored === 'dark' || stored === 'light' ) {
				root.setAttribute( 'data-mmcb-theme', stored );
			}
		} catch ( e ) { /* localStorage indisponível: segue o sistema */ }
	}

	function currentTheme() {
		var forced = root.getAttribute( 'data-mmcb-theme' );
		if ( forced === 'dark' || forced === 'light' ) { return forced; }
		return ( window.matchMedia && window.matchMedia( '(prefers-color-scheme: dark)' ).matches ) ? 'dark' : 'light';
	}

	function toggleTheme() {
		var next = currentTheme() === 'dark' ? 'light' : 'dark';
		root.setAttribute( 'data-mmcb-theme', next );
		try { localStorage.setItem( THEME_KEY, next ); } catch ( e ) { /* sem persistência */ }
	}

	function themeToggleBtn() {
		return '<button class="mmcb-theme-toggle" type="button" data-action="toggle-theme" ' +
			'aria-label="Alternar tema claro/escuro" title="Alternar tema claro/escuro">◐</button>';
	}

	initTheme();

	// ---- estado global ----
	var state = {
		status:       null,
		tab:          'painel',
		flashToken:   null,  // plaintext exibido uma vez após geração
		wizard: {
			step:         'terms', // terms | builder | tier | branch | token-* | oauth-*
			branch:       '',      // 'token' | 'oauth'
			builder:      '',
			tier:         '',
			token:        null,    // plaintext após geração no wizard
			termsChecked: false,
			guideMode:    false,   // true = aberto pelo botão "Rever guia"
		},
		logs: {
			page:     1,
			per_page: 50,
			data:     null,
		},
	};

	// ---- utilitários ----
	function esc( s ) {
		return String( s == null ? '' : s ).replace( /[&<>"']/g, function ( c ) {
			return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[ c ];
		} );
	}

	function post( action, extra ) {
		var fd = new FormData();
		fd.append( 'action', action );
		fd.append( '_ajax_nonce', NONCE );
		if ( extra ) {
			Object.keys( extra ).forEach( function ( k ) {
				var v = extra[ k ];
				if ( Array.isArray( v ) ) {
					v.forEach( function ( item ) { fd.append( k + '[]', item ); } );
				} else {
					if ( typeof v === 'boolean' ) { v = v ? '1' : '0'; }
					fd.append( k, v == null ? '' : v );
				}
			} );
		}
		return fetch( API, { method: 'POST', credentials: 'same-origin', body: fd } )
			.then( function ( r ) { return r.json(); } );
	}

	// Toast
	var toastEl;
	function toast( msg, type ) {
		if ( ! toastEl ) {
			toastEl = document.createElement( 'div' );
			toastEl.className = 'mmcb-toast';
			document.body.appendChild( toastEl );
		}
		toastEl.textContent = msg;
		toastEl.className = 'mmcb-toast ' + ( type || '' );
		void toastEl.offsetWidth;
		toastEl.classList.add( 'show' );
		clearTimeout( toastEl._t );
		toastEl._t = setTimeout( function () { toastEl.classList.remove( 'show' ); }, 2400 );
	}

	// Copiar para clipboard
	function copyText( text, btn ) {
		var done = function () {
			if ( btn ) {
				var o = btn.textContent; btn.textContent = 'Copiado!';
				setTimeout( function () { btn.textContent = o; }, 1500 );
			}
			toast( 'Copiado para a área de transferência.', 'ok' );
		};
		if ( navigator.clipboard && navigator.clipboard.writeText ) {
			navigator.clipboard.writeText( text ).then( done, function () { toast( 'Falha ao copiar.', 'err' ); } );
		} else {
			var ta = document.createElement( 'textarea' );
			ta.value = text;
			document.body.appendChild( ta );
			ta.select();
			try { document.execCommand( 'copy' ); done(); } catch ( e ) { toast( 'Falha ao copiar.', 'err' ); }
			document.body.removeChild( ta );
		}
	}

	// Formatar data ISO para leitura
	function fmtDate( s ) {
		if ( ! s ) { return '—'; }
		try {
			var d = new Date( s.replace( ' ', 'T' ) );
			return d.toLocaleDateString( 'pt-BR' ) + ' ' + d.toLocaleTimeString( 'pt-BR', { hour: '2-digit', minute: '2-digit' } );
		} catch ( e ) { return s || '—'; }
	}

	// =========================================================================
	// WIZARD GUIADO (ramificado) + TERMO DE RESPONSABILIDADE
	// =========================================================================

	var KNOWN_ABILITIES = [ '*', 'builder', 'read', 'content', 'plugins', 'themes', 'core', 'files', 'snippets', 'options', 'db', 'db_query', 'exec', 'cli' ];

	// O wizard está visível quando: o termo ainda não foi aceito, o onboarding
	// não terminou, ou o usuário abriu o "Rever guia".
	function wizardActive() {
		var s = state.status;
		if ( ! s ) { return false; }
		var termsOk = s.terms && s.terms.accepted;
		return ! termsOk || ! s.onboarding_done || state.wizard.guideMode;
	}

	// ---- Termo de Responsabilidade (texto jurídico) ----
	function termsHtml() {
		return '' +
			'<h4>1. Natureza do software e isenção de garantia</h4>' +
			'<p>O MarreiraMCP Builders é um software livre distribuído sob a Licença Pública Geral GNU, versão 2.0 ou posterior (GPL-2.0-or-later). Nos termos das cláusulas 15 e 16 da GPL, este programa é fornecido <strong>sem qualquer garantia</strong>, expressa ou implícita, incluindo, sem limitação, as garantias implícitas de comercialização e de adequação a uma finalidade específica. O risco integral quanto à qualidade e ao desempenho do programa é seu. Em nenhuma hipótese os autores ou detentores dos direitos autorais serão responsáveis por danos diretos, indiretos, incidentais, especiais ou consequentes decorrentes do uso ou da impossibilidade de uso deste software.</p>' +
			'<h4>2. O que este plugin é — e o que ele não é</h4>' +
			'<p>Este plugin é um <strong>servidor MCP (Model Context Protocol)</strong>: uma ponte estruturada que recebe comandos emitidos por um modelo de inteligência artificial externo e os executa neste WordPress, dentro dos limites que você configurar. O MarreiraMCP Builders <strong>não é um agente de IA</strong>: ele não toma decisões autônomas, não escolhe o que fazer no seu site e não controla o modelo conectado. Todo comando executado é determinado exclusivamente pelo modelo de IA que você escolheu conectar e pelas instruções que você (ou alguém autorizado por você) forneceu a ele.</p>' +
			'<h4>3. Responsabilidade integral do usuário</h4>' +
			'<p>A escolha do modelo de inteligência artificial, das permissões (abilities) habilitadas em cada token, dos poderes ativados nas configurações e das instruções dadas ao modelo é <strong>inteira e exclusivamente sua</strong>. Um modelo de IA com as permissões correspondentes pode <strong>criar, modificar e apagar conteúdo, páginas, configurações e dados deste site</strong>. Os autores deste plugin não controlam, não supervisionam e não se responsabilizam pelas ações do modelo de IA conectado, sejam quais forem sua natureza e extensão.</p>' +
			'<h4>4. Poderes perigosos — trava dupla</h4>' +
			'<p>O CLI geral de WordPress (execução de PHP, consultas diretas ao banco de dados, escrita de arquivos) vem <strong>desligado de fábrica</strong> e exige ativação explícita no painel <strong>e</strong> a ability correspondente no token. Ao ativar qualquer um desses recursos, você autoriza conscientemente que o modelo de IA execute operações potencialmente destrutivas e irreversíveis no servidor.</p>' +
			'<h4>5. Recomendações de segurança</h4>' +
			'<p>Antes de conectar qualquer agente de IA: (a) <strong>faça backup completo do site</strong> (banco de dados e arquivos), de preferência automatizado e guardado fora do servidor; (b) habilite apenas as abilities estritamente necessárias; (c) revise e revogue tokens que não estiverem em uso; (d) acompanhe o audit log no painel; (e) não ative execução de PHP, queries diretas ou escrita de arquivos sem plena compreensão das implicações.</p>' +
			'<h4>6. Aceitação</h4>' +
			'<p>Ao marcar a caixa e clicar em “Aceitar e continuar”, você declara ter lido, compreendido e concordado com este termo e com as condições da licença GPL que rege o software.</p>';
	}

	// ---- Bloco de instruções prontas para colar na IA ----
	function aiInstructions( token ) {
		var ep  = ( state.status && state.status.endpoints ) || {};
		var tok = token || 'SEU_TOKEN';
		return 'Você vai operar este site WordPress pelo servidor MCP "MarreiraMCP Builders".\n\n' +
			'1) Leia a documentação do servidor (skill) — ela lista todos os endpoints já com o domínio real:\n' +
			'   GET ' + ( ep.skill || '' ) + '\n\n' +
			'2) Conecte-se ao servidor MCP:\n' +
			'   URL: ' + ( ep.mcp || '' ) + '\n' +
			'   Header: Authorization: Bearer ' + tok + '\n\n' +
			'3) Configuração para Claude Code / Cursor / VS Code (mcpServers):\n' +
			'{\n' +
			'  "mcpServers": {\n' +
			'    "wordpress": {\n' +
			'      "type": "http",\n' +
			'      "url": "' + ( ep.mcp || '' ) + '",\n' +
			'      "headers": { "Authorization": "Bearer ' + tok + '" }\n' +
			'    }\n' +
			'  }\n' +
			'}';
	}

	// ---- Sequência de passos (muda conforme o branch escolhido) ----
	function wizardSequence() {
		var w   = state.wizard;
		var seq = [];
		if ( ! w.guideMode ) {
			seq.push( { id: 'terms', label: 'Termo' } );
			seq.push( { id: 'builder', label: 'Builder' } );
			seq.push( { id: 'tier', label: 'IA' } );
		}
		seq.push( { id: 'branch', label: 'Conexão' } );
		if ( w.branch === 'oauth' ) {
			seq.push( { id: 'oauth-url', label: 'URL' } );
			seq.push( { id: 'oauth-consent', label: 'Autorizar' } );
			seq.push( { id: 'oauth-approve', label: 'Aprovar' } );
			seq.push( { id: 'oauth-done', label: 'Pronto' } );
		} else if ( w.branch === 'token' ) {
			seq.push( { id: 'token-gen', label: 'Token' } );
			seq.push( { id: 'token-copy', label: 'Instruções' } );
			seq.push( { id: 'token-done', label: 'Pronto' } );
		} else {
			seq.push( { id: 'wz-end', label: 'Pronto' } );
		}
		return seq;
	}

	var WZ_TITLES = {
		'terms':         [ 'Termo de Responsabilidade', 'Leia e aceite para configurar o plugin.' ],
		'builder':       [ 'Escolha o builder', 'Selecione o page builder instalado neste site.' ],
		'tier':          [ 'Qual IA vai consumir o MCP?', 'Isso define como o servidor envia o contexto para a IA.' ],
		'branch':        [ 'Como você vai conectar a IA?', 'Escolha um caminho agora — dá para usar os dois depois.' ],
		'token-gen':     [ 'Gere o token de acesso', 'O token autentica o agente de IA. Você pode gerar mais depois.' ],
		'token-copy':    [ 'Copie e cole na IA', 'Tudo pronto para colar no seu cliente de IA.' ],
		'token-done':    [ 'Confira e conclua', 'Revise o checklist e rode o autoteste.' ],
		'oauth-url':     [ 'Adicione o conector no app de IA', 'Claude.ai e ChatGPT se conectam por OAuth — automático.' ],
		'oauth-consent': [ 'O que vai acontecer agora', 'O app abre uma tela de autorização aqui no seu site.' ],
		'oauth-approve': [ 'Aprove o cliente', 'Cada app que se registra precisa da sua aprovação.' ],
		'oauth-done':    [ 'Confira e conclua', 'Revise o checklist e rode o autoteste.' ],
	};

	function renderWizard() {
		var s = state.status;
		var w = state.wizard;

		// Instalação existente sem aceite: só a tela do termo, sem stepper.
		var termsOnly = s.terms && ! s.terms.accepted && s.onboarding_done && ! w.guideMode;
		if ( termsOnly ) { w.step = 'terms'; }

		var seq    = wizardSequence();
		var curIdx = -1;
		seq.forEach( function ( st, i ) { if ( st.id === w.step ) { curIdx = i; } } );

		var stepper = '';
		if ( ! termsOnly ) {
			stepper = '<div class="mmcb-wizard-steps">' + seq.map( function ( st, i ) {
				var cls = i === curIdx ? 'active' : ( i < curIdx ? 'done' : '' );
				return '<div class="mmcb-wizard-step ' + cls + '">' +
					'<div class="mmcb-wizard-step-dot">' + ( i < curIdx ? '✓' : ( i + 1 ) ) + '</div>' +
					'<div class="mmcb-wizard-step-label">' + esc( st.label ) + '</div>' +
				'</div>';
			} ).join( '' ) + '</div>';
		}

		var t     = WZ_TITLES[ w.step ] || [ '', '' ];
		var inner = wizardStepHtml( w.step );

		var headerSub = termsOnly
			? 'O plugin foi atualizado e agora exige o aceite do termo abaixo para usar o painel.'
			: ( w.guideMode
				? 'Guia de conexão — siga os passos para ligar sua IA ao site.'
				: 'Passo ' + ( curIdx + 1 ) + ' de ' + seq.length + ' — configuração guiada.' );

		var topRow = '<div style="display:flex;justify-content:flex-end;gap:8px;margin-bottom:8px">' +
			( w.guideMode ? '<button class="mmcb-btn mmcb-btn-sm" data-wz-close="1">× Fechar guia</button>' : '' ) +
			themeToggleBtn() +
		'</div>';

		root.innerHTML =
			'<div class="mmcb-wizard">' +
				topRow +
				'<div class="mmcb-wizard-header">' +
					'<div class="mmcb-logo" aria-hidden="true" style="margin:0 auto 16px;"><span></span><span></span><span></span><span></span></div>' +
					'<h2>' + ( w.guideMode ? 'Guia de conexão' : 'MarreiraMCP Builders' ) + '</h2>' +
					'<p>' + esc( headerSub ) + '</p>' +
				'</div>' +
				stepper +
				'<div class="mmcb-wizard-card">' +
					'<h3>' + esc( t[ 0 ] ) + '</h3>' +
					'<p class="mmcb-hint">' + esc( t[ 1 ] ) + '</p>' +
					inner +
				'</div>' +
			'</div>';
	}

	function wizardStepHtml( step ) {
		if ( step === 'terms' )         { return wizardTerms(); }
		if ( step === 'builder' )       { return wizardBuilder(); }
		if ( step === 'tier' )          { return wizardTier(); }
		if ( step === 'branch' )        { return wizardBranch(); }
		if ( step === 'token-gen' )     { return wizardTokenGen(); }
		if ( step === 'token-copy' )    { return wizardTokenCopy(); }
		if ( step === 'token-done' )    { return wizardDone( 'token' ); }
		if ( step === 'oauth-url' )     { return wizardOauthUrl(); }
		if ( step === 'oauth-consent' ) { return wizardOauthConsent(); }
		if ( step === 'oauth-approve' ) { return wizardOauthApprove(); }
		if ( step === 'oauth-done' )    { return wizardDone( 'oauth' ); }
		return '';
	}

	// ---- Passo 0: termo ----
	function wizardTerms() {
		var s       = state.status;
		var checked = state.wizard.termsChecked;
		return '<div class="mmcb-terms-box">' + termsHtml() + '</div>' +
			'<label class="mmcb-terms-accept">' +
				'<input type="checkbox" id="mmcb-wz-terms-check"' + ( checked ? ' checked' : '' ) + '> ' +
				'<span>Li e aceito o Termo de Responsabilidade. Entendo que o plugin apenas executa o que o modelo de IA mandar e que a responsabilidade pelo modelo, pelas permissões e pelos poderes ativados é minha.</span>' +
			'</label>' +
			'<p class="mmcb-terms-meta">Versão do termo: ' + esc( ( s.terms && s.terms.required_version ) || '1' ) + '. O aceite fica registrado com usuário, data e IP.</p>' +
			'<div class="mmcb-wizard-actions">' +
				'<button class="mmcb-btn mmcb-btn-primary" id="mmcb-wz-accept" data-wz-accept-terms="1"' + ( checked ? '' : ' disabled' ) + '>Aceitar e continuar</button>' +
			'</div>';
	}

	// ---- Passo: builder ----
	function wizardBuilder() {
		var s         = state.status;
		var available = s.available_builders || [];
		if ( ! state.wizard.builder ) {
			state.wizard.builder = available.length ? available[ 0 ] : ( s.builders && s.builders.length ? s.builders[ 0 ].slug : '' );
		}
		var cards = ( s.builders || [] ).map( function ( b ) {
			var isAvail = available.indexOf( b.slug ) !== -1;
			var sel     = state.wizard.builder === b.slug;
			return '<div class="mmcb-builder-card' + ( sel ? ' selected' : '' ) + '" data-wz-builder="' + esc( b.slug ) + '">' +
				'<div class="mmcb-builder-label">' + esc( b.label ) + '</div>' +
				'<div class="mmcb-builder-status' + ( isAvail ? ' active' : '' ) + '">' +
					( isAvail ? '● Detectado' : '○ Não detectado' ) + ( b.version ? ' ' + esc( b.version ) : '' ) +
				'</div>' +
			'</div>';
		} ).join( '' );

		return '<div class="mmcb-builder-cards">' + cards + '</div>' +
			'<div class="mmcb-wizard-actions">' +
				'<button class="mmcb-btn mmcb-btn-primary" data-wz-go="tier"' + ( state.wizard.builder ? '' : ' disabled' ) + '>Próximo →</button>' +
			'</div>';
	}

	// ---- Passo: tier de IA ----
	function wizardTier() {
		if ( ! state.wizard.tier ) { state.wizard.tier = 'premium'; }
		var tier  = state.wizard.tier;
		var tiers = [
			{
				slug: 'premium',
				title: 'Premium / paga',
				desc: 'Claude Sonnet, GPT-4o, Gemini Pro ou similar. Respostas completas, mapa inteiro de uma vez, contexto máximo.',
			},
			{
				slug: 'economy',
				title: 'Grátis / barata',
				desc: 'Claude Haiku, GPT-4o mini, Gemini Flash. Respostas enxutas, buscar contexto por partes com get_map, batch-first.',
			},
		];

		var cards = tiers.map( function ( t ) {
			var sel = tier === t.slug;
			return '<div class="mmcb-tier-card' + ( sel ? ' selected' : '' ) + '" data-wz-tier="' + esc( t.slug ) + '">' +
				'<h3>' + esc( t.title ) + '</h3>' +
				'<p>' + esc( t.desc ) + '</p>' +
			'</div>';
		} ).join( '' );

		return '<div class="mmcb-tier-cards">' + cards + '</div>' +
			'<div class="mmcb-wizard-actions">' +
				'<button class="mmcb-btn" data-wz-go="builder">← Voltar</button>' +
				'<button class="mmcb-btn mmcb-btn-primary" data-wz-go="branch">Próximo →</button>' +
			'</div>';
	}

	// ---- Passo: escolha do modo de conexão ----
	function wizardBranch() {
		var w     = state.wizard;
		var cards =
			'<div class="mmcb-choice-cards">' +
				'<div class="mmcb-choice-card' + ( w.branch === 'token' ? ' selected' : '' ) + '" data-wz-branch="token">' +
					'<div class="mmcb-choice-icon">🔑</div>' +
					'<h3>Token manual</h3>' +
					'<p>Para Claude Code, Cursor, VS Code, Zed, n8n e afins. Você gera um token e cola a URL do MCP no cliente de IA.</p>' +
				'</div>' +
				'<div class="mmcb-choice-card' + ( w.branch === 'oauth' ? ' selected' : '' ) + '" data-wz-branch="oauth">' +
					'<div class="mmcb-choice-icon">🔗</div>' +
					'<h3>Conector OAuth</h3>' +
					'<p>Para Claude.ai e ChatGPT. A IA abre uma tela de autorização aqui no seu site — sem copiar token.</p>' +
				'</div>' +
			'</div>';
		var nextTarget = w.branch === 'oauth' ? 'oauth-url' : 'token-gen';
		var back       = w.guideMode ? '' : '<button class="mmcb-btn" data-wz-go="tier">← Voltar</button>';
		return cards +
			'<div class="mmcb-wizard-actions">' +
				back +
				'<button class="mmcb-btn mmcb-btn-primary" data-wz-go="' + nextTarget + '"' + ( w.branch ? '' : ' disabled' ) + '>Próximo →</button>' +
			'</div>';
	}

	// ---- Branch token: gerar ----
	function wizardTokenGen() {
		var w          = state.wizard;
		var tokenFlash = '';
		if ( w.token ) {
			tokenFlash =
				'<div class="mmcb-flash">' +
					'<h3>Token gerado — copie agora!</h3>' +
					'<p class="mmcb-flash-warn">⚠ Este token só é exibido uma vez. O WordPress guarda apenas o hash.</p>' +
					'<div class="mmcb-copy-row"><code class="mmcb-code is-token" id="mmcb-wz-tok">' + esc( w.token ) + '</code>' +
					'<button class="mmcb-btn mmcb-btn-sm mmcb-copy" data-copy="#mmcb-wz-tok">Copiar</button></div>' +
				'</div>';
		}

		var abilityBoxes = KNOWN_ABILITIES.map( function ( ab ) {
			var checked = ab === 'builder';
			return '<label class="mmcb-ability-check' + ( checked ? ' checked' : '' ) + '">' +
				'<input type="checkbox" name="wz_ability" value="' + esc( ab ) + '"' + ( checked ? ' checked' : '' ) + '> ' + esc( ab ) +
			'</label>';
		} ).join( '' );

		var skip = ( w.guideMode && ! w.token )
			? '<button class="mmcb-btn" data-wz-go="token-copy">Já tenho um token →</button>'
			: '';

		return tokenFlash +
			'<div class="mmcb-field"><label for="mmcb-wz-tname">Nome do token</label>' +
				'<input type="text" id="mmcb-wz-tname" value="Agente IA" placeholder="Ex.: Claude Code">' +
			'</div>' +
			'<div class="mmcb-field"><label>Permissões (abilities)</label>' +
				'<div class="mmcb-abilities-grid">' + abilityBoxes + '</div>' +
				'<p class="description">Para editar páginas basta “builder”. Só marque poderes extras se a tarefa exigir.</p>' +
			'</div>' +
			'<div class="mmcb-field"><label for="mmcb-wz-exp">Validade (dias, 0 = nunca expira)</label>' +
				'<input type="number" id="mmcb-wz-exp" min="0" value="0">' +
			'</div>' +
			'<div class="mmcb-wizard-actions">' +
				'<button class="mmcb-btn" data-wz-go="branch">← Voltar</button>' +
				'<button class="mmcb-btn' + ( w.token ? '' : ' mmcb-btn-primary' ) + '" id="mmcb-wz-gen">' + ( w.token ? 'Gerar outro token' : 'Gerar token' ) + '</button>' +
				skip +
				'<button class="mmcb-btn' + ( w.token ? ' mmcb-btn-primary' : '' ) + '" data-wz-go="token-copy"' + ( w.token ? '' : ' disabled' ) + '>Próximo →</button>' +
			'</div>';
	}

	// ---- Branch token: copiar skill + endpoint + instruções ----
	function wizardTokenCopy() {
		var ep = ( state.status && state.status.endpoints ) || {};
		var w  = state.wizard;
		return '' +
			'<div class="mmcb-field"><label>1 · URL da skill — a documentação que a IA lê primeiro</label>' +
				'<div class="mmcb-copy-row"><code class="mmcb-code" id="mmcb-wz-skill">' + esc( ep.skill || '' ) + '</code>' +
				'<button class="mmcb-btn mmcb-btn-sm mmcb-copy" data-copy="#mmcb-wz-skill">Copiar</button></div>' +
				'<p class="description">Ela já lista todos os endpoints com o domínio real do site — além dela, a IA só precisa do token.</p>' +
			'</div>' +
			'<div class="mmcb-field"><label>2 · Endpoint MCP</label>' +
				'<div class="mmcb-copy-row"><code class="mmcb-code" id="mmcb-wz-mcp">' + esc( ep.mcp || '' ) + '</code>' +
				'<button class="mmcb-btn mmcb-btn-sm mmcb-copy" data-copy="#mmcb-wz-mcp">Copiar</button></div>' +
			'</div>' +
			'<div class="mmcb-field"><label>3 · Instruções prontas — cole na sua IA</label>' +
				'<pre class="mmcb-instructions-block" id="mmcb-wz-instr">' + esc( aiInstructions( w.token ) ) + '</pre>' +
				'<button class="mmcb-btn mmcb-btn-sm mmcb-copy" data-copy="#mmcb-wz-instr">Copiar instruções completas</button>' +
				( w.token ? '' : '<p class="description">Sem token gerado nesta sessão, as instruções saem com o marcador SEU_TOKEN — troque pelo token real.</p>' ) +
			'</div>' +
			'<div class="mmcb-wizard-actions">' +
				'<button class="mmcb-btn" data-wz-go="token-gen">← Voltar</button>' +
				'<button class="mmcb-btn mmcb-btn-primary" data-wz-go="token-done">Próximo →</button>' +
			'</div>';
	}

	// ---- Branch OAuth: URL do conector ----
	function wizardOauthUrl() {
		var s        = state.status;
		var mcp      = ( s.endpoints && s.endpoints.mcp ) || '';
		var oauthOff = ! ( s.oauth && s.oauth.enabled )
			? '<p class="mmcb-flash-warn">⚠ O conector OAuth está desligado nas configurações. Ligue-o na aba Conectores antes de seguir, senão o app não consegue se registrar.</p>'
			: '';
		return oauthOff +
			'<div class="mmcb-field"><label>URL do servidor MCP — cole no app de IA</label>' +
				'<div class="mmcb-copy-row"><code class="mmcb-code" id="mmcb-wz-omcp">' + esc( mcp ) + '</code>' +
				'<button class="mmcb-btn mmcb-btn-sm mmcb-copy" data-copy="#mmcb-wz-omcp">Copiar</button></div>' +
			'</div>' +
			'<ul class="mmcb-timeline">' +
				'<li><strong>No Claude.ai</strong>Configurações → Conectores → “Adicionar conector personalizado” → cole a URL acima.</li>' +
				'<li><strong>No ChatGPT</strong>Configurações → Conectores / “Apps e conectores” → adicionar servidor MCP → cole a URL acima.</li>' +
			'</ul>' +
			'<div class="mmcb-wizard-actions">' +
				'<button class="mmcb-btn" data-wz-go="branch">← Voltar</button>' +
				'<button class="mmcb-btn mmcb-btn-primary" data-wz-go="oauth-consent">Já colei, próximo →</button>' +
			'</div>';
	}

	// ---- Branch OAuth: o que esperar da autorização ----
	function wizardOauthConsent() {
		return '<ul class="mmcb-timeline">' +
			'<li><strong>O app se registra sozinho</strong>Ao salvar o conector, o Claude.ai/ChatGPT descobre o OAuth deste site e se registra automaticamente.</li>' +
			'<li><strong>Uma tela de autorização abre aqui no seu site</strong>Você precisa estar logado como administrador. A tela mostra o nome do app e os escopos pedidos.</li>' +
			'<li><strong>Você clica em “Autorizar”</strong>Conceda apenas os escopos que quiser — os sensíveis ficam bloqueados pela trava dupla das configurações.</li>' +
			'<li><strong>O app volta a funcionar sozinho</strong>Ele recebe o token e passa a chamar as ferramentas do site. O cliente aparece na aba Conectores.</li>' +
		'</ul>' +
		'<p class="mmcb-hint">Mantenha esta aba aberta durante o processo. Se o app ficar “pendente de aprovação”, o próximo passo resolve.</p>' +
		'<div class="mmcb-wizard-actions">' +
			'<button class="mmcb-btn" data-wz-go="oauth-url">← Voltar</button>' +
			'<button class="mmcb-btn mmcb-btn-primary" data-wz-go="oauth-approve">Entendi, próximo →</button>' +
		'</div>';
	}

	// ---- Branch OAuth: aprovar clientes ----
	function wizardOauthApprove() {
		var clients = state.status._clients;
		var list;
		if ( clients === undefined ) {
			list = '<p class="mmcb-hint">Carregando clientes…</p>';
		} else if ( ! clients.length ) {
			list = '<p class="mmcb-hint">Nenhum cliente apareceu ainda. Termine de adicionar o conector no app de IA e clique em “Atualizar lista”.</p>';
		} else {
			list = '<ul class="mmcb-checklist">' + clients.map( function ( c ) {
				var pend = c.status === 'pending';
				return '<li class="' + ( pend ? 'pending' : '' ) + '">' +
					'<span style="flex:1 1 auto"><strong>' + esc( c.client_name || c.client_id ) + '</strong> — ' +
					( c.status === 'approved' ? 'aprovado' : ( pend ? 'pendente de aprovação' : esc( c.status ) ) ) + '</span>' +
					( pend ? '<button class="mmcb-btn mmcb-btn-sm mmcb-btn-primary" data-wz-approve-client="' + esc( c.id ) + '">Aprovar</button>' : '' ) +
				'</li>';
			} ).join( '' ) + '</ul>';
		}
		return list +
			'<div class="mmcb-actions" style="margin-top:12px"><button class="mmcb-btn mmcb-btn-sm" data-wz-clients-refresh="1">↻ Atualizar lista</button></div>' +
			'<p class="mmcb-hint" style="margin-top:12px">Aprovar o registro não concede acesso sozinho — o acesso só nasce quando você autoriza os escopos na tela de consentimento.</p>' +
			'<div class="mmcb-wizard-actions">' +
				'<button class="mmcb-btn" data-wz-go="oauth-consent">← Voltar</button>' +
				'<button class="mmcb-btn mmcb-btn-primary" data-wz-go="oauth-done">Próximo →</button>' +
			'</div>';
	}

	// ---- Checklist final + autoteste ----
	function wizardDone( branch ) {
		var s            = state.status;
		var w            = state.wizard;
		var items        = [];
		var builderLabel = w.builder || s.active_builder || '—';
		var tierLabel    = w.tier || s.ai_tier || '—';
		items.push( '<li>Builder: <strong>' + esc( builderLabel ) + '</strong></li>' );
		items.push( '<li>Tier de IA: <strong>' + esc( tierLabel ) + '</strong></li>' );
		if ( branch === 'token' ) {
			items.push( w.token ? '<li>Token gerado nesta sessão</li>' : '<li class="pending">Token — gere um na aba Tokens se ainda não tiver</li>' );
			items.push( '<li>URL da skill e endpoint MCP prontos (sempre disponíveis no Painel)</li>' );
		} else {
			items.push( '<li class="pending">Conector adicionado no app de IA (confira lá se conectou)</li>' );
			items.push( '<li>Clientes aprovados aparecem na aba Conectores</li>' );
		}
		var finishLabel = w.guideMode ? 'Fechar guia' : 'Concluir configuração';
		return '<ul class="mmcb-checklist">' + items.join( '' ) + '</ul>' +
			'<div class="mmcb-actions"><button class="mmcb-btn" data-wz-selftest="1">Executar autoteste</button></div>' +
			'<div id="mmcb-wz-selftest"></div>' +
			'<div class="mmcb-wizard-actions">' +
				'<button class="mmcb-btn" data-wz-go="' + ( branch === 'token' ? 'token-copy' : 'oauth-approve' ) + '">← Voltar</button>' +
				'<button class="mmcb-btn mmcb-btn-primary" id="mmcb-wz-finish">' + finishLabel + '</button>' +
			'</div>';
	}

	// Carrega a lista de clients OAuth para o passo de aprovação do wizard.
	function wizardLoadClients() {
		post( 'mmcb_list_oauth_clients' ).then( function ( res ) {
			if ( res && res.success ) {
				state.status._clients = res.data.clients || [];
				if ( state.status.oauth ) { state.status.oauth.pending = res.data.pending || 0; }
				if ( wizardActive() && state.wizard.step === 'oauth-approve' ) { renderWizard(); }
			}
		} );
	}

	function handleWizardClick( ev ) {
		var target = ev.target;
		var w      = state.wizard;

		// Selecionar builder
		var bCard = target.closest( '[data-wz-builder]' );
		if ( bCard ) {
			w.builder = bCard.getAttribute( 'data-wz-builder' );
			renderWizard();
			return;
		}

		// Selecionar tier
		var tCard = target.closest( '[data-wz-tier]' );
		if ( tCard ) {
			w.tier = tCard.getAttribute( 'data-wz-tier' );
			renderWizard();
			return;
		}

		// Selecionar modo de conexão
		var brCard = target.closest( '[data-wz-branch]' );
		if ( brCard ) {
			w.branch = brCard.getAttribute( 'data-wz-branch' );
			renderWizard();
			return;
		}

		// Navegar entre passos
		var goBtn = target.closest( '[data-wz-go]' );
		if ( goBtn ) {
			if ( goBtn.disabled ) { return; }
			w.step = goBtn.getAttribute( 'data-wz-go' );
			renderWizard();
			if ( w.step === 'oauth-approve' && state.status._clients === undefined ) { wizardLoadClients(); }
			return;
		}

		// Copiar
		var copyBtn = target.closest( '[data-copy]' );
		if ( copyBtn ) {
			var el = document.querySelector( copyBtn.getAttribute( 'data-copy' ) );
			if ( el ) { copyText( el.textContent, copyBtn ); }
			return;
		}

		// Fechar guia (modo "Rever guia")
		if ( target.closest( '[data-wz-close]' ) ) {
			w.guideMode = false;
			render();
			return;
		}

		// Aceitar o Termo de Responsabilidade
		var acceptBtn = target.closest( '[data-wz-accept-terms]' );
		if ( acceptBtn ) {
			if ( ! w.termsChecked ) { toast( 'Marque a caixa de aceite primeiro.', 'err' ); return; }
			acceptBtn.disabled = true;
			var version = ( state.status.terms && state.status.terms.required_version ) || '1';
			post( 'mmcb_accept_terms', { terms_version: version } ).then( function ( res ) {
				acceptBtn.disabled = false;
				if ( res && res.success ) {
					state.status = res.data;
					toast( 'Termo aceito. Registro gravado.', 'ok' );
					if ( state.status.onboarding_done ) {
						render(); // instalação existente: direto para o painel
					} else {
						w.step = 'builder';
						renderWizard();
					}
				} else {
					toast( ( res && res.data && res.data.message ) || 'Falha ao registrar o aceite.', 'err' );
				}
			} ).catch( function () { acceptBtn.disabled = false; toast( 'Erro de rede.', 'err' ); } );
			return;
		}

		// Gerar token (branch token)
		if ( target.id === 'mmcb-wz-gen' ) {
			var nameEl = document.getElementById( 'mmcb-wz-tname' );
			var expEl  = document.getElementById( 'mmcb-wz-exp' );
			var abs    = [];
			document.querySelectorAll( 'input[name="wz_ability"]:checked' ).forEach( function ( cb ) { abs.push( cb.value ); } );
			target.disabled = true;
			post( 'mmcb_generate_token', {
				name:         nameEl ? nameEl.value : 'Agente IA',
				abilities:    abs.length ? abs : [ 'builder' ],
				expires_days: expEl ? parseInt( expEl.value, 10 ) || 0 : 0,
			} ).then( function ( res ) {
				target.disabled = false;
				if ( res && res.success ) {
					w.token = res.data.token;
					// Preserva o bloco terms/estado: o payload de status vem completo.
					state.status = res.data.status;
					toast( 'Token gerado. Copie antes de seguir!', 'ok' );
					renderWizard();
				} else {
					toast( 'Falha ao gerar token.', 'err' );
				}
			} ).catch( function () { target.disabled = false; toast( 'Erro de rede.', 'err' ); } );
			return;
		}

		// Aprovar client OAuth (passo de aprovação)
		var apBtn = target.closest( '[data-wz-approve-client]' );
		if ( apBtn ) {
			apBtn.disabled = true;
			post( 'mmcb_approve_oauth_client', { id: apBtn.getAttribute( 'data-wz-approve-client' ) } ).then( function ( res ) {
				if ( res && res.success ) {
					state.status._clients = res.data.clients || [];
					if ( state.status.oauth ) { state.status.oauth.pending = res.data.pending || 0; }
					toast( 'Cliente aprovado.', 'ok' );
					renderWizard();
				} else {
					apBtn.disabled = false;
					toast( 'Falha ao aprovar.', 'err' );
				}
			} ).catch( function () { apBtn.disabled = false; toast( 'Erro de rede.', 'err' ); } );
			return;
		}

		// Atualizar lista de clients
		if ( target.closest( '[data-wz-clients-refresh]' ) ) {
			wizardLoadClients();
			return;
		}

		// Autoteste no wizard
		var stBtn = target.closest( '[data-wz-selftest]' );
		if ( stBtn ) {
			stBtn.disabled = true;
			post( 'mmcb_selftest' ).then( function ( res ) {
				stBtn.disabled = false;
				var box = document.getElementById( 'mmcb-wz-selftest' );
				if ( res && res.success && res.data.ok ) {
					if ( box ) { box.innerHTML = '<code class="mmcb-code is-token" style="margin-top:12px">✔ OK — o servidor respondeu. Sua configuração está funcionando.</code>'; }
					toast( 'Autoteste OK.', 'ok' );
				} else {
					var msg = ( res && res.data && res.data.message ) || 'Falha no autoteste.';
					if ( box ) { box.innerHTML = '<code class="mmcb-code" style="margin-top:12px">✗ ' + esc( msg ) + '</code>'; }
					toast( msg, 'err' );
				}
			} ).catch( function () { stBtn.disabled = false; toast( 'Erro de rede.', 'err' ); } );
			return;
		}

		// Concluir configuração / fechar guia
		if ( target.id === 'mmcb-wz-finish' ) {
			if ( w.guideMode ) {
				w.guideMode = false;
				render();
				return;
			}
			if ( ! w.builder || ! w.tier ) {
				toast( 'Selecione builder e tier antes de concluir.', 'err' );
				return;
			}
			target.disabled = true;
			post( 'mmcb_complete_onboarding', { builder: w.builder, tier: w.tier } )
				.then( function ( res ) {
					target.disabled = false;
					if ( res && res.success ) {
						state.status = res.data;
						state.tab = 'painel';
						render();
						toast( 'Configuração concluída!', 'ok' );
					} else {
						toast( ( res && res.data && res.data.message ) || 'Falha ao concluir.', 'err' );
					}
				} ).catch( function () { target.disabled = false; toast( 'Erro de rede.', 'err' ); } );
			return;
		}
	}

	// =========================================================================
	// APP PRINCIPAL (após onboarding)
	// =========================================================================

	var TABS = [
		[ 'painel',        'Painel'        ],
		[ 'tokens',        'Tokens'        ],
		[ 'conectores',    'Conectores'    ],
		[ 'logs',          'Logs'          ],
		[ 'configuracoes', 'Configurações' ],
		[ 'ferramentas',   'Ferramentas'   ],
	];

	function badge( cls, label ) {
		return '<span class="mmcb-badge ' + cls + '">' + esc( label ) + '</span>';
	}

	function topbar() {
		var s  = state.status;
		var ab = s.active_builder || '—';
		var b  = '';
		b += s.active_builder ? badge( 'is-on', '● Builder: ' + ab ) : badge( 'is-off', '○ Sem builder' );
		b += s.ai_tier ? badge( 'is-info', 'IA: ' + esc( s.ai_tier ) ) : badge( 'is-warn', '▲ Tier não definido' );
		b += s.token_count > 0 ? badge( 'is-on', '● ' + s.token_count + ( s.token_count === 1 ? ' token' : ' tokens' ) ) : badge( 'is-off', '○ Sem tokens' );
		b += badge( 'is-info', 'v' + esc( s.plugin_version ) );
		return '<header class="mmcb-topbar">' +
			'<div class="mmcb-logo" aria-hidden="true"><span></span><span></span><span></span><span></span></div>' +
			'<div><h1>MarreiraMCP Builders</h1><p class="mmcb-sub">Servidor MCP unificado para Bricks Builder &amp; Elementor</p></div>' +
			'<div class="mmcb-badges">' + b +
				'<button class="mmcb-btn mmcb-btn-sm mmcb-guide-btn" data-action="open-guide" title="Reabrir o guia de conexão passo a passo">↺ Rever guia</button>' +
				themeToggleBtn() +
			'</div>' +
			'</header>';
	}

	function tabsNav() {
		return '<nav class="mmcb-tabs" role="tablist">' + TABS.map( function ( t ) {
			return '<button class="mmcb-tab' + ( state.tab === t[ 0 ] ? ' is-active' : '' ) +
				'" data-tab="' + t[ 0 ] + '" role="tab" aria-selected="' + ( state.tab === t[ 0 ] ? 'true' : 'false' ) + '">' +
				esc( t[ 1 ] ) + '</button>';
		} ).join( '' ) + '</nav>';
	}

	function stat( icon, num, label ) {
		return '<div class="mmcb-stat">' +
			'<span class="mmcb-stat-icon">' + icon + '</span>' +
			'<span class="mmcb-stat-num">' + esc( num ) + '</span>' +
			'<span class="mmcb-stat-label">' + esc( label ) + '</span>' +
		'</div>';
	}

	// ---- Tab: Painel ----
	function viewPainel() {
		var s  = state.status;
		var ep = s.endpoints || {};

		// Stats
		var stats = '<div class="mmcb-stats">' +
			stat( '🔧', s.tools_count || 0, 'Ferramentas MCP' ) +
			stat( '🔑', s.token_count || 0, 'Tokens ativos' ) +
			stat( '📦', esc( s.active_builder || 'nenhum' ), 'Builder ativo' ) +
			stat( '🤖', esc( s.ai_tier || 'nenhum' ), 'Tier de IA' ) +
		'</div>';

		// Switch de builder
		var builderSwitch = '<div class="mmcb-inline-switch">' +
			( s.builders || [] ).map( function ( b ) {
				return '<button class="' + ( s.active_builder === b.slug ? 'active' : '' ) +
					'" data-action="switch-builder" data-builder="' + esc( b.slug ) + '">' +
					esc( b.label ) + ( b.is_active ? ' ✓' : '' ) + '</button>';
			} ).join( '' ) +
		'</div>';

		// Switch de tier
		var tierSwitch = '<div class="mmcb-inline-switch">' +
			'<button class="' + ( s.ai_tier === 'premium' ? 'active' : '' ) + '" data-action="set-tier" data-tier="premium">Premium</button>' +
			'<button class="' + ( s.ai_tier === 'economy' ? 'active' : '' ) + '" data-action="set-tier" data-tier="economy">Economy</button>' +
		'</div>';

		// Endpoints — a skill vem primeiro: é o ponto de entrada recomendado.
		function epRow( label, url, id, hint ) {
			return '<div class="mmcb-field"><label>' + esc( label ) + '</label>' +
				'<div class="mmcb-copy-row"><code class="mmcb-code" id="' + id + '">' + esc( url ) + '</code>' +
				'<button class="mmcb-btn mmcb-btn-sm mmcb-copy" data-copy="#' + id + '">Copiar</button></div>' +
				( hint ? '<p class="description">' + esc( hint ) + '</p>' : '' ) +
			'</div>';
		}

		var endpoints =
			epRow( 'Skill (GET, documentação para a IA) — comece por aqui', ep.skill || '', 'mmcb-ep-skill',
				'Passe esta URL para a IA: o documento sai com o domínio real do site e lista todos os endpoints — você não precisa ditar mais nada.' ) +
			epRow( 'Endpoint MCP (POST, JSON-RPC 2.0)', ep.mcp || '', 'mmcb-ep-mcp',
				'A URL que o cliente MCP (Claude Code, Cursor, conector) usa para chamar as ferramentas.' ) +
			epRow( 'Describe (GET, auto-descoberta — exige token)', ep.describe || '', 'mmcb-ep-desc',
				'Builder ativo, tier, abilities do token e catálogo de tools em JSON.' ) +
			epRow( 'CLI (POST — desligado de fábrica)', ep.cli || '', 'mmcb-ep-cli',
				'CLI geral de WordPress. Só funciona com a flag ligada nas configurações e a ability no token.' );

		return '<div class="mmcb-grid cols-2">' +
			'<section class="mmcb-card">' +
				'<h2>Visão geral</h2>' +
				'<p class="mmcb-hint">Resumo do que a IA pode gerenciar neste site.</p>' +
				stats +
			'</section>' +
			'<section class="mmcb-card">' +
				'<h2>Builder ativo</h2>' +
				'<p class="mmcb-hint">Troque o page builder que o servidor MCP vai controlar.</p>' +
				builderSwitch +
				'<h2 style="margin-top:18px">Tier de IA</h2>' +
				'<p class="mmcb-hint">Define como o servidor envia contexto.</p>' +
				tierSwitch +
			'</section>' +
			'<section class="mmcb-card mmcb-span-2">' +
				'<h2>Endpoints</h2>' +
				'<p class="mmcb-hint">A skill é o ponto de entrada: ela documenta o servidor e lista todos os endpoints já com o seu domínio.</p>' +
				endpoints +
			'</section>' +
			'<section class="mmcb-card mmcb-span-2">' +
				'<h2>Instruções para a IA</h2>' +
				'<p class="mmcb-hint">Bloco pronto para colar no seu cliente de IA (Claude Code, Cursor, VS Code…). Troque SEU_TOKEN por um token da aba Tokens.</p>' +
				'<pre class="mmcb-instructions-block" id="mmcb-ai-instr">' + esc( aiInstructions( null ) ) + '</pre>' +
				'<div class="mmcb-actions" style="margin-top:0">' +
					'<button class="mmcb-btn mmcb-btn-sm mmcb-copy" data-copy="#mmcb-ai-instr">Copiar instruções completas</button>' +
				'</div>' +
			'</section>' +
			'<section class="mmcb-card mmcb-span-2">' +
				'<h2>Autoteste</h2>' +
				'<p class="mmcb-hint">Dispara a tool get_capabilities (ou lê as capabilities do driver ativo) para confirmar que o servidor está funcionando.</p>' +
				'<div class="mmcb-actions"><button class="mmcb-btn mmcb-btn-primary" data-action="selftest">Executar autoteste</button></div>' +
				'<div id="mmcb-selftest"></div>' +
			'</section>' +
		'</div>';
	}

	// ---- Tab: Tokens ----
	function buildAbilityBadges( abilitiesJson ) {
		var abs = [];
		try { abs = JSON.parse( abilitiesJson ); } catch ( e ) { abs = []; }
		if ( ! Array.isArray( abs ) ) { abs = []; }
		return '<div class="mmcb-ability-list">' +
			abs.map( function ( a ) {
				return '<span class="mmcb-ability' + ( a === '*' ? ' star' : '' ) + '">' + esc( a ) + '</span>';
			} ).join( '' ) +
		'</div>';
	}

	function tokenTable( tokens ) {
		if ( ! tokens || ! tokens.length ) {
			return '<p class="mmcb-hint" style="margin-top:12px">Nenhum token criado ainda.</p>';
		}
		var rows = tokens.map( function ( t ) {
			var statusCls = t.status === 'active' ? 'active' : 'revoked';
			var statusTxt = t.status === 'active' ? 'ativo' : 'revogado';
			var canRevoke  = t.status === 'active';
			var canActivate = t.status !== 'active';
			return '<tr>' +
				'<td class="mmcb-mono">' + esc( t.prefix ) + '…</td>' +
				'<td>' + esc( t.name ) + '</td>' +
				'<td>' + buildAbilityBadges( t.abilities ) + '</td>' +
				'<td><span class="mmcb-token-status ' + statusCls + '">' + statusTxt + '</span></td>' +
				'<td>' + esc( fmtDate( t.last_used_at ) ) + '</td>' +
				'<td>' + esc( fmtDate( t.expires_at ) ) + '</td>' +
				'<td style="white-space:nowrap">' +
					( canRevoke   ? '<button class="mmcb-btn mmcb-btn-sm mmcb-btn-danger" data-action="revoke-token" data-id="' + esc( t.id ) + '">Revogar</button> ' : '' ) +
					( canActivate ? '<button class="mmcb-btn mmcb-btn-sm" data-action="activate-token" data-id="' + esc( t.id ) + '">Ativar</button> ' : '' ) +
					'<button class="mmcb-btn mmcb-btn-sm mmcb-btn-danger" data-action="delete-token" data-id="' + esc( t.id ) + '">Apagar</button>' +
				'</td>' +
			'</tr>';
		} ).join( '' );

		return '<div class="mmcb-table-wrap"><table class="mmcb-table">' +
			'<thead><tr>' +
				'<th>Prefixo</th><th>Nome</th><th>Abilities</th><th>Status</th><th>Último uso</th><th>Expira</th><th>Ações</th>' +
			'</tr></thead>' +
			'<tbody>' + rows + '</tbody>' +
		'</table></div>';
	}

	function abilityCheckboxes( formPrefix ) {
		return '<div class="mmcb-abilities-grid">' +
			KNOWN_ABILITIES.map( function ( ab ) {
				var id  = formPrefix + '-ab-' + ab.replace( '*', 'all' );
				var def = ab === 'builder';
				return '<label class="mmcb-ability-check' + ( def ? ' checked' : '' ) + '" for="' + id + '">' +
					'<input type="checkbox" id="' + id + '" name="' + formPrefix + '_ability" value="' + esc( ab ) + '"' + ( def ? ' checked' : '' ) + '> ' + esc( ab ) +
				'</label>';
			} ).join( '' ) +
		'</div>';
	}

	function viewTokens( tokens ) {
		var flash = '';
		if ( state.flashToken ) {
			flash = '<div class="mmcb-flash">' +
				'<h3>Token gerado — copie agora!</h3>' +
				'<p class="mmcb-flash-warn">⚠ Exibido apenas uma vez. O WordPress guarda somente o hash.</p>' +
				'<div class="mmcb-copy-row"><code class="mmcb-code is-token" id="mmcb-tok">' + esc( state.flashToken ) + '</code>' +
				'<button class="mmcb-btn mmcb-btn-sm mmcb-copy" data-copy="#mmcb-tok">Copiar</button></div>' +
			'</div>';
			state.flashToken = null;
		}

		var createForm =
			'<div class="mmcb-field"><label for="mmcb-tnew-name">Nome do token</label>' +
				'<input type="text" id="mmcb-tnew-name" placeholder="Ex.: Claude Desktop">' +
			'</div>' +
			'<div class="mmcb-field"><label>Permissões (abilities)</label>' + abilityCheckboxes( 'tnew' ) + '</div>' +
			'<div class="mmcb-field"><label for="mmcb-tnew-exp">Validade em dias (0 = nunca expira)</label>' +
				'<input type="number" id="mmcb-tnew-exp" min="0" value="0">' +
			'</div>' +
			'<div class="mmcb-actions"><button class="mmcb-btn mmcb-btn-primary" data-action="gen-token">Criar token</button></div>';

		return flash +
			'<div class="mmcb-grid cols-2">' +
				'<section class="mmcb-card mmcb-span-2">' +
					'<h2>Tokens ativos</h2>' +
					'<p class="mmcb-hint">Cada token carrega suas próprias abilities. Revogue ou apague tokens comprometidos.</p>' +
					tokenTable( tokens ) +
				'</section>' +
				'<section class="mmcb-card mmcb-span-2">' +
					'<h2>Criar novo token</h2>' +
					'<p class="mmcb-hint">O texto puro só é exibido uma vez logo após a criação.</p>' +
					createForm +
				'</section>' +
			'</div>';
	}

	// ---- Tab: Logs ----
	function viewLogs( logsData ) {
		var items = ( logsData && logsData.items ) || [];
		var total = ( logsData && logsData.total ) || 0;
		var page  = state.logs.page;
		var pp    = state.logs.per_page;
		var pages = Math.ceil( total / pp ) || 1;

		var rows = items.map( function ( l ) {
			var sc      = parseInt( l.status_code, 10 );
			var scCls   = ( sc >= 200 && sc < 300 ) ? 'mmcb-http-ok' : 'mmcb-http-err';
			var success = parseInt( l.success, 10 );
			return '<tr>' +
				'<td>' + esc( fmtDate( l.created_at ) ) + '</td>' +
				'<td><code class="mmcb-mono">' + esc( l.method ) + '</code></td>' +
				'<td class="mmcb-mono" style="font-size:11.5px;max-width:240px;overflow-wrap:anywhere">' + esc( l.route ) + '</td>' +
				'<td>' + esc( l.action ) + '</td>' +
				'<td><span class="' + scCls + '">' + esc( l.status_code ) + '</span></td>' +
				'<td>' + esc( l.ip ) + '</td>' +
				'<td>' + ( success ? '<span class="mmcb-ok">✓</span>' : '<span class="mmcb-bad">✗</span>' ) + '</td>' +
			'</tr>';
		} ).join( '' );

		var table = items.length
			? '<div class="mmcb-table-wrap"><table class="mmcb-table">' +
				'<thead><tr><th>Data/Hora</th><th>Método</th><th>Rota</th><th>Ação</th><th>HTTP</th><th>IP</th><th>OK</th></tr></thead>' +
				'<tbody>' + rows + '</tbody></table></div>'
			: '<p class="mmcb-hint" style="margin-top:12px">Nenhum log registrado.</p>';

		var pagination =
			'<div class="mmcb-pagination">' +
				'<button class="mmcb-btn mmcb-btn-sm" data-action="logs-prev"' + ( page <= 1 ? ' disabled' : '' ) + '>← Anterior</button>' +
				'<span class="mmcb-page-info">Página ' + page + ' de ' + pages + ' (' + total + ' entradas)</span>' +
				'<button class="mmcb-btn mmcb-btn-sm" data-action="logs-next"' + ( page >= pages ? ' disabled' : '' ) + '>Próxima →</button>' +
			'</div>';

		return '<section class="mmcb-card">' +
			'<h2>Audit log</h2>' +
			'<p class="mmcb-hint">Registro de todas as requisições aos endpoints do plugin.</p>' +
			'<div class="mmcb-actions" style="margin-top:0;margin-bottom:14px"><button class="mmcb-btn mmcb-btn-sm" data-action="logs-refresh">↻ Atualizar</button></div>' +
			table + pagination +
		'</section>';
	}

	// ---- Tab: Configurações ----
	function toggle( id, label, sub, checked ) {
		return '<label class="mmcb-toggle">' +
			'<input type="checkbox" data-toggle="' + id + '"' + ( checked ? ' checked' : '' ) + '>' +
			'<span class="mmcb-switch" aria-hidden="true"></span>' +
			'<span><span class="mmcb-toggle-label">' + esc( label ) + '</span>' +
			'<span class="mmcb-toggle-sub">' + esc( sub ) + '</span></span>' +
		'</label>';
	}

	function viewConfiguracoes() {
		var st = state.status.settings;

		var security =
			toggle( 'https_only', 'Exigir HTTPS', 'Recusa chamadas sem TLS.', st.https_only ) +
			toggle( 'block_code', 'Bloquear elementos de código (anti-RCE)', 'Ligado: recusa qualquer elemento de código no builder. Injeção de script continua sempre bloqueada.', st.block_code ) +
			'<div class="mmcb-field"><label>Limite de requisições por token</label>' +
				'<div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;">' +
					'<input type="number" min="0" id="mmcb-rl" value="' + st.rate_limit + '">' +
					'<span class="mmcb-toggle-sub">req por</span>' +
					'<input type="number" min="1" id="mmcb-rw" value="' + st.rate_window + '">' +
					'<span class="mmcb-toggle-sub">segundos</span>' +
					'<button class="mmcb-btn mmcb-btn-sm" data-action="save-rate">Salvar</button>' +
				'</div>' +
				'<p class="description">Use 0 no limite para desativar.</p>' +
			'</div>' +
			'<div class="mmcb-field"><label for="mmcb-ips">IPs permitidos (um por linha, vazio = qualquer)</label>' +
				'<textarea id="mmcb-ips" rows="4">' + esc( st.allowed_ips ) + '</textarea>' +
			'</div>' +
			'<div class="mmcb-field"><label for="mmcb-retention">Retenção de logs (dias)</label>' +
				'<input type="number" min="1" id="mmcb-retention" value="' + st.log_retention_days + '">' +
			'</div>' +
			'<div class="mmcb-actions"><button class="mmcb-btn mmcb-btn-primary" data-action="save-security">Salvar segurança</button></div>';

		var cli =
			'<div class="mmcb-danger-zone">' +
				'<h3>Poderes avançados — deixe desligado se não usa</h3>' +
				'<p>Estas opções habilitam capacidades de execução no servidor. Ative somente se você sabe o que está fazendo e confia nos tokens que usam o endpoint.</p>' +
				toggle( 'enable_general_cli', 'Habilitar CLI geral do WordPress', 'Permite operações administrativas amplas via IA.', st.enable_general_cli ) +
				toggle( 'allow_php_exec', 'Permitir execução de PHP (exec)', '⚠ Alto risco — permite que a IA execute código PHP arbitrário.', st.allow_php_exec ) +
				toggle( 'allow_db_query', 'Permitir queries SQL diretas (db_query)', '⚠ Alto risco — permite SELECT/UPDATE/DELETE arbitrários no banco.', st.allow_db_query ) +
				toggle( 'allow_file_write', 'Permitir escrita de arquivos (file_write)', '⚠ Alto risco — permite que a IA escreva arquivos no servidor.', st.allow_file_write ) +
				'<div class="mmcb-field"><label for="mmcb-dbbl">Blacklist de tabelas DB (uma por linha)</label>' +
					'<textarea id="mmcb-dbbl" rows="4">' + esc( st.db_blacklist ) + '</textarea>' +
					'<p class="description">Tabelas listadas aqui são bloqueadas nas queries diretas.</p>' +
				'</div>' +
				'<div class="mmcb-actions"><button class="mmcb-btn mmcb-btn-primary" data-action="save-cli">Salvar configurações avançadas</button></div>' +
			'</div>';

		return '<div class="mmcb-grid cols-2">' +
			'<section class="mmcb-card mmcb-span-2">' +
				'<h2>Segurança e limites</h2>' +
				'<p class="mmcb-hint">Proteções aplicadas a cada requisição aos endpoints do plugin.</p>' +
				security +
			'</section>' +
			'<section class="mmcb-card mmcb-span-2">' +
				'<h2>CLI avançado</h2>' +
				cli +
			'</section>' +
		'</div>';
	}

	// ---- Tab: Ferramentas ----
	function viewFerramentas() {
		var tools = state.status.tools || [];
		var cards = tools.map( function ( t ) {
			var props = ( t.inputSchema && t.inputSchema.properties ) || {};
			var req   = ( t.inputSchema && t.inputSchema.required ) || [];
			var args  = Object.keys( props ).map( function ( k ) {
				var isReq = req.indexOf( k ) !== -1;
				return '<span class="mmcb-arg' + ( isReq ? ' req' : '' ) + '">' + esc( k ) + ( isReq ? '*' : '' ) + '</span>';
			} ).join( '' );
			return '<div class="mmcb-tool" data-name="' + esc( t.name ) + '">' +
				'<div class="mmcb-tool-name">' + esc( t.name ) + '</div>' +
				'<div class="mmcb-tool-desc">' + esc( t.description ) + '</div>' +
				'<div class="mmcb-tool-args">' + ( args || '<span class="mmcb-arg">sem argumentos</span>' ) + '</div>' +
			'</div>';
		} ).join( '' );

		return '<section class="mmcb-card">' +
			'<h2>Catálogo de ferramentas MCP (' + tools.length + ')</h2>' +
			'<p class="mmcb-hint">Tudo que a IA pode chamar. Campos com <strong>*</strong> são obrigatórios.</p>' +
			'<div class="mmcb-tool-search"><input type="search" id="mmcb-search" placeholder="Filtrar ferramentas…"></div>' +
			'<div class="mmcb-tools" id="mmcb-tool-list">' + cards + '</div>' +
		'</section>';
	}

	// ---- Tab: Conectores (OAuth) ----
	function clientStatusBadge( st ) {
		if ( st === 'approved' ) { return badge( 'is-on', '● aprovado' ); }
		if ( st === 'revoked' )  { return badge( 'is-off', '○ revogado' ); }
		return badge( 'is-warn', '▲ pendente' );
	}

	// Conexão != registro. O status do client diz se ele pode conectar; esta
	// coluna diz se ele CONECTOU (token vivo emitido) e quando falou com o site
	// pela última vez. Antes a aba só mostrava registro e nunca dizia "conectado".
	function connectionCell( c ) {
		if ( c.connected ) {
			return badge( 'is-on', '● conectado' ) +
				'<br><span class="mmcb-hint" style="font-size:11px">último uso: ' + esc( fmtDate( c.last_used_at ) ) + '</span>';
		}
		if ( c.status === 'revoked' ) {
			return badge( 'is-off', '○ acesso revogado' );
		}
		if ( c.ever_issued ) {
			return badge( 'is-warn', '○ desconectado' ) +
				'<br><span class="mmcb-hint" style="font-size:11px">último uso: ' + esc( fmtDate( c.last_used_at ) ) + '</span>';
		}
		return '<span class="mmcb-hint">nunca conectou</span>';
	}

	function clientsTable( clients ) {
		if ( ! clients || ! clients.length ) {
			return '<p class="mmcb-hint" style="margin-top:12px">Nenhum cliente registrado ainda. Ao adicionar o conector no Claude.ai/ChatGPT, ele aparece aqui para aprovação.</p>';
		}
		var rows = clients.map( function ( c ) {
			var uris = ( c.redirect_uris || [] ).map( function ( u ) { return esc( u ); } ).join( '<br>' );
			var actions = '';
			if ( c.status === 'pending' ) {
				actions += '<button class="mmcb-btn mmcb-btn-sm mmcb-btn-primary" data-action="approve-client" data-id="' + c.id + '">Aprovar</button> ';
			}
			if ( c.status !== 'revoked' ) {
				actions += '<button class="mmcb-btn mmcb-btn-sm" data-action="revoke-client" data-id="' + c.id + '">Revogar</button>';
			}
			return '<tr>' +
				'<td>' + esc( c.client_name || '—' ) + '<br><code class="mmcb-mono" style="font-size:11px">' + esc( c.client_id ) + '</code></td>' +
				'<td class="mmcb-mono" style="font-size:11px;max-width:260px;overflow-wrap:anywhere">' + uris + '</td>' +
				'<td>' + clientStatusBadge( c.status ) + '</td>' +
				'<td>' + connectionCell( c ) + '</td>' +
				'<td>' + esc( fmtDate( c.created_at ) ) + '</td>' +
				'<td>' + ( actions || '—' ) + '</td>' +
			'</tr>';
		} ).join( '' );
		return '<div class="mmcb-table-wrap"><table class="mmcb-table">' +
			'<thead><tr><th>Cliente</th><th>Redirect URIs</th><th>Registro</th><th>Conexão</th><th>Criado</th><th>Ações</th></tr></thead>' +
			'<tbody>' + rows + '</tbody></table></div>';
	}

	function viewConectores() {
		var s   = state.status;
		var oa  = s.oauth || {};
		var ep  = oa.endpoints || {};
		var mcp = ( s.endpoints && s.endpoints.mcp ) || '';
		var clients = s._clients;

		var pendingBadge = oa.pending > 0 ? ' ' + badge( 'is-warn', oa.pending + ( oa.pending === 1 ? ' pendente' : ' pendentes' ) ) : '';

		var connectCard =
			'<section class="mmcb-card mmcb-span-2">' +
				'<h2>Conectar Claude.ai / ChatGPT</h2>' +
				'<p class="mmcb-hint">No app de IA, adicione um <strong>conector (servidor MCP remoto)</strong> apontando para a URL abaixo. A autenticação OAuth é automática: o app abre uma tela de consentimento aqui no WordPress, você autoriza, e pronto.</p>' +
				'<div class="mmcb-field"><label>URL do servidor MCP (cole no conector)</label>' +
					'<div class="mmcb-copy-row"><code class="mmcb-code" id="mmcb-oauth-mcp">' + esc( mcp ) + '</code>' +
					'<button class="mmcb-btn mmcb-btn-sm mmcb-copy" data-copy="#mmcb-oauth-mcp">Copiar</button></div>' +
				'</div>' +
				'<details style="margin-top:10px"><summary class="mmcb-hint" style="cursor:pointer">Endpoints OAuth (referência técnica)</summary>' +
					'<ul class="mmcb-hint" style="margin-top:8px;line-height:1.9;overflow-wrap:anywhere">' +
						'<li>Discovery: <code class="mmcb-mono">' + esc( ep.protected_resource || '' ) + '</code></li>' +
						'<li>Auth server: <code class="mmcb-mono">' + esc( ep.authorization_server || '' ) + '</code></li>' +
						'<li>Registro (DCR): <code class="mmcb-mono">' + esc( ep.register || '' ) + '</code></li>' +
						'<li>Autorização: <code class="mmcb-mono">' + esc( ep.authorize || '' ) + '</code></li>' +
						'<li>Token: <code class="mmcb-mono">' + esc( ep.token || '' ) + '</code></li>' +
					'</ul>' +
				'</details>';

		if ( ! oa.enabled ) {
			connectCard += '<p class="mmcb-flash-warn" style="margin-top:12px">⚠ O conector OAuth está desligado. Ligue abaixo para permitir novas conexões.</p>';
		}
		connectCard +=
				'<div style="margin-top:14px">' +
					toggle( 'enable_oauth', 'Habilitar conector OAuth', 'Expõe o discovery e o fluxo OAuth para apps de IA externos. A segurança vem do seu consentimento a cada cliente.', oa.enabled ) +
					toggle( 'oauth_auto_approve', 'Aprovar clientes automaticamente', '⚠ Não recomendado. Ligado: clientes registrados via DCR já ficam aptos sem sua aprovação manual (você ainda autoriza na tela de consentimento).', oa.auto_approve ) +
				'</div>' +
			'</section>';

		var clientsCard =
			'<section class="mmcb-card mmcb-span-2">' +
				'<h2>Clientes conectados' + pendingBadge + '</h2>' +
				'<p class="mmcb-hint">Cada app que se registra aparece aqui. Aprove os que você reconhece; revogue os demais. Aprovar um cliente não concede acesso sozinho — o acesso só nasce quando você autoriza os escopos na tela de consentimento.</p>' +
				'<div class="mmcb-actions" style="margin-top:0;margin-bottom:12px"><button class="mmcb-btn mmcb-btn-sm" data-action="clients-refresh">↻ Atualizar</button></div>' +
				( clients === undefined ? '<p class="mmcb-hint">Carregando…</p>' : clientsTable( clients ) ) +
			'</section>';

		return '<div class="mmcb-grid cols-2">' + connectCard + clientsCard + '</div>';
	}

	function viewFor( tab, logsData ) {
		var s = state.status;
		if ( tab === 'tokens' )        { return viewTokens( s._tokens || [] ); }
		if ( tab === 'conectores' )    { return viewConectores(); }
		if ( tab === 'logs' )          { return viewLogs( logsData || state.logs.data ); }
		if ( tab === 'configuracoes' ) { return viewConfiguracoes(); }
		if ( tab === 'ferramentas' )   { return viewFerramentas(); }
		return viewPainel();
	}

	// =========================================================================
	// RENDER PRINCIPAL
	// =========================================================================

	function render( logsData ) {
		var s = state.status;
		if ( ! s ) { return; }
		if ( wizardActive() ) {
			renderWizard();
			return;
		}
		root.innerHTML = topbar() + tabsNav() +
			'<div class="mmcb-panel-view" id="mmcb-view">' + viewFor( state.tab, logsData ) + '</div>';
	}

	// =========================================================================
	// SETTINGS helpers
	// =========================================================================

	function saveSettings( overrides, okMsg ) {
		var st       = state.status.settings;
		var data     = Object.assign( {}, st, overrides || {} );
		var prevTok  = state.status._tokens;
		var prevCli  = state.status._clients;
		return post( 'mmcb_save_settings', data ).then( function ( res ) {
			if ( res && res.success ) {
				state.status = res.data;
				// Preserva listas carregadas sob demanda (nao vem no status).
				if ( prevTok !== undefined ) { state.status._tokens = prevTok; }
				if ( prevCli !== undefined ) { state.status._clients = prevCli; }
				toast( okMsg || 'Configurações salvas.', 'ok' );
				render();
			} else {
				toast( ( res && res.data && res.data.message ) || 'Falha ao salvar.', 'err' );
			}
		} ).catch( function () { toast( 'Erro de rede.', 'err' ); } );
	}

	// Carrega logs e renderiza
	function loadLogs() {
		post( 'mmcb_logs', { page: state.logs.page, per_page: state.logs.per_page } )
			.then( function ( res ) {
				if ( res && res.success ) {
					state.logs.data = res.data;
					var view = document.getElementById( 'mmcb-view' );
					if ( view ) { view.innerHTML = viewLogs( res.data ); }
				} else {
					toast( 'Falha ao carregar logs.', 'err' );
				}
			} ).catch( function () { toast( 'Erro de rede.', 'err' ); } );
	}

	// Carrega tokens e atualiza estado
	function loadTokens( cb ) {
		post( 'mmcb_list_tokens' ).then( function ( res ) {
			if ( res && res.success ) {
				state.status._tokens = res.data.tokens || [];
				if ( cb ) { cb(); } else { render(); }
			}
		} );
	}

	// Carrega clients OAuth e atualiza estado
	function loadClients( cb ) {
		post( 'mmcb_list_oauth_clients' ).then( function ( res ) {
			if ( res && res.success ) {
				state.status._clients = res.data.clients || [];
				if ( state.status.oauth ) { state.status.oauth.pending = res.data.pending || 0; }
				if ( cb ) { cb(); } else { render(); }
			}
		} );
	}

	// =========================================================================
	// DELEGAÇÃO DE EVENTOS — APP PRINCIPAL
	// =========================================================================

	root.addEventListener( 'click', function ( ev ) {
		// Toggle de tema — funciona em qualquer tela (wizard ou app).
		if ( ev.target.closest( '.mmcb-theme-toggle' ) ) {
			toggleTheme();
			return;
		}

		// Wizard — passa primeiro
		if ( ! state.status ) { return; }
		if ( wizardActive() ) {
			handleWizardClick( ev );
			return;
		}

		// Tab
		var tabBtn = ev.target.closest( '.mmcb-tab' );
		if ( tabBtn ) {
			state.tab = tabBtn.getAttribute( 'data-tab' );
			if ( state.tab === 'tokens' ) {
				loadTokens( function () { render(); } );
			} else if ( state.tab === 'conectores' ) {
				render();
				loadClients();
			} else if ( state.tab === 'logs' ) {
				state.logs.page = 1;
				render();
				loadLogs();
			} else {
				render();
			}
			return;
		}

		// Copiar
		var copyBtn = ev.target.closest( '[data-copy]' );
		if ( copyBtn ) {
			var el = document.querySelector( copyBtn.getAttribute( 'data-copy' ) );
			if ( el ) { copyText( el.textContent, copyBtn ); }
			return;
		}

		// Ações
		var act = ev.target.closest( '[data-action]' );
		if ( ! act ) { return; }
		var action = act.getAttribute( 'data-action' );

		// Reabrir o guia de conexão (sem re-exigir termo nem refazer onboarding)
		if ( action === 'open-guide' ) {
			state.wizard.guideMode = true;
			state.wizard.step      = 'branch';
			state.wizard.branch    = '';
			state.wizard.token     = null;
			renderWizard();
			return;
		}

		// Switch builder
		if ( action === 'switch-builder' ) {
			var builder = act.getAttribute( 'data-builder' );
			act.disabled = true;
			post( 'mmcb_switch_builder', { builder: builder } ).then( function ( res ) {
				act.disabled = false;
				if ( res && res.success ) { state.status = res.data; render(); toast( 'Builder alterado.', 'ok' ); }
				else { toast( 'Falha ao trocar builder.', 'err' ); }
			} ).catch( function () { act.disabled = false; toast( 'Erro de rede.', 'err' ); } );
		}

		// Set tier
		if ( action === 'set-tier' ) {
			var tier = act.getAttribute( 'data-tier' );
			post( 'mmcb_set_tier', { tier: tier } ).then( function ( res ) {
				if ( res && res.success ) { state.status = res.data; render(); toast( 'Tier atualizado.', 'ok' ); }
				else { toast( 'Falha ao definir tier.', 'err' ); }
			} ).catch( function () { toast( 'Erro de rede.', 'err' ); } );
		}

		// Autoteste
		if ( action === 'selftest' ) {
			act.disabled = true;
			post( 'mmcb_selftest' ).then( function ( res ) {
				act.disabled = false;
				var box = document.getElementById( 'mmcb-selftest' );
				if ( res && res.success && res.data.ok ) {
					if ( box ) { box.innerHTML = '<code class="mmcb-code is-token" style="margin-top:12px">✔ OK — ' + esc( JSON.stringify( res.data.data, null, 2 ) ) + '</code>'; }
					toast( 'Autoteste OK.', 'ok' );
				} else {
					var msg = ( res && res.data && res.data.message ) || 'Falha no autoteste.';
					if ( box ) { box.innerHTML = '<code class="mmcb-code" style="margin-top:12px">' + esc( msg ) + '</code>'; }
					toast( msg, 'err' );
				}
			} ).catch( function () { act.disabled = false; toast( 'Erro de rede.', 'err' ); } );
		}

		// Criar token
		if ( action === 'gen-token' ) {
			var nameEl = document.getElementById( 'mmcb-tnew-name' );
			var expEl  = document.getElementById( 'mmcb-tnew-exp' );
			var abs    = [];
			document.querySelectorAll( 'input[name="tnew_ability"]:checked' ).forEach( function ( cb ) { abs.push( cb.value ); } );
			act.disabled = true;
			post( 'mmcb_generate_token', {
				name:         nameEl ? nameEl.value : 'Token',
				abilities:    abs.length ? abs : [ 'builder' ],
				expires_days: expEl ? parseInt( expEl.value, 10 ) || 0 : 0,
			} ).then( function ( res ) {
				act.disabled = false;
				if ( res && res.success ) {
					state.status    = res.data.status;
					state.status._tokens = res.data.tokens || [];
					state.flashToken = res.data.token;
					state.tab = 'tokens';
					render();
					toast( 'Token criado. Copie agora!', 'ok' );
				} else {
					toast( 'Falha ao criar token.', 'err' );
				}
			} ).catch( function () { act.disabled = false; toast( 'Erro de rede.', 'err' ); } );
		}

		// Revogar token
		if ( action === 'revoke-token' ) {
			if ( ! window.confirm( 'Revogar este token? Integrações que o usam deixarão de funcionar.' ) ) { return; }
			var id = act.getAttribute( 'data-id' );
			post( 'mmcb_revoke_token', { id: id } ).then( function ( res ) {
				if ( res && res.success ) {
					state.status._tokens = res.data.tokens || [];
					render();
					toast( 'Token revogado.', 'ok' );
				} else { toast( 'Falha ao revogar.', 'err' ); }
			} );
		}

		// Ativar token
		if ( action === 'activate-token' ) {
			var idA = act.getAttribute( 'data-id' );
			post( 'mmcb_activate_token', { id: idA } ).then( function ( res ) {
				if ( res && res.success ) {
					state.status._tokens = res.data.tokens || [];
					render();
					toast( 'Token ativado.', 'ok' );
				} else { toast( 'Falha ao ativar.', 'err' ); }
			} );
		}

		// Apagar token
		if ( action === 'delete-token' ) {
			if ( ! window.confirm( 'Apagar este token permanentemente?' ) ) { return; }
			var idD = act.getAttribute( 'data-id' );
			post( 'mmcb_delete_token', { id: idD } ).then( function ( res ) {
				if ( res && res.success ) {
					state.status._tokens = res.data.tokens || [];
					render();
					toast( 'Token apagado.', 'ok' );
				} else { toast( 'Falha ao apagar.', 'err' ); }
			} );
		}

		// Aprovar client OAuth
		if ( action === 'approve-client' ) {
			var idAp = act.getAttribute( 'data-id' );
			act.disabled = true;
			post( 'mmcb_approve_oauth_client', { id: idAp } ).then( function ( res ) {
				if ( res && res.success ) {
					state.status._clients = res.data.clients || [];
					if ( state.status.oauth ) { state.status.oauth.pending = res.data.pending || 0; }
					render();
					toast( 'Cliente aprovado.', 'ok' );
				} else { toast( 'Falha ao aprovar.', 'err' ); }
			} ).catch( function () { toast( 'Erro de rede.', 'err' ); } );
		}

		// Revogar client OAuth
		if ( action === 'revoke-client' ) {
			if ( ! window.confirm( 'Revogar este cliente? Ele perde o acesso e precisará ser reautorizado.' ) ) { return; }
			var idRv = act.getAttribute( 'data-id' );
			post( 'mmcb_revoke_oauth_client', { id: idRv } ).then( function ( res ) {
				if ( res && res.success ) {
					state.status._clients = res.data.clients || [];
					if ( state.status.oauth ) { state.status.oauth.pending = res.data.pending || 0; }
					render();
					toast( 'Cliente revogado.', 'ok' );
				} else { toast( 'Falha ao revogar.', 'err' ); }
			} );
		}

		// Atualizar lista de clients
		if ( action === 'clients-refresh' ) {
			loadClients();
		}

		// Rate limit
		if ( action === 'save-rate' ) {
			var rl = document.getElementById( 'mmcb-rl' );
			var rw = document.getElementById( 'mmcb-rw' );
			saveSettings( {
				rate_limit:  Math.max( 0, parseInt( ( rl && rl.value ) || 60, 10 ) || 0 ),
				rate_window: Math.max( 1, parseInt( ( rw && rw.value ) || 60, 10 ) || 1 ),
			}, 'Limite de requisições salvo.' );
		}

		// Salvar segurança
		if ( action === 'save-security' ) {
			var ips  = document.getElementById( 'mmcb-ips' );
			var ret  = document.getElementById( 'mmcb-retention' );
			var rl2  = document.getElementById( 'mmcb-rl' );
			var rw2  = document.getElementById( 'mmcb-rw' );
			saveSettings( {
				allowed_ips:        ips ? ips.value : '',
				log_retention_days: ret ? Math.max( 1, parseInt( ret.value, 10 ) || 90 ) : 90,
				rate_limit:         rl2 ? Math.max( 0, parseInt( rl2.value, 10 ) || 0 ) : 60,
				rate_window:        rw2 ? Math.max( 1, parseInt( rw2.value, 10 ) || 1 ) : 60,
			}, 'Segurança salva.' );
		}

		// Salvar CLI avançado
		if ( action === 'save-cli' ) {
			var dbbl = document.getElementById( 'mmcb-dbbl' );
			var overrides = {
				db_blacklist: dbbl ? dbbl.value : '',
			};
			// os toggles são capturados via change handler, mas enviamos o estado atual
			[ 'enable_general_cli', 'allow_php_exec', 'allow_db_query', 'allow_file_write' ].forEach( function ( k ) {
				var el = document.querySelector( '[data-toggle="' + k + '"]' );
				if ( el ) { overrides[ k ] = el.checked; }
			} );
			saveSettings( overrides, 'Configurações avançadas salvas.' );
		}

		// Logs: paginação
		if ( action === 'logs-prev' && state.logs.page > 1 ) {
			state.logs.page--;
			loadLogs();
		}
		if ( action === 'logs-next' ) {
			state.logs.page++;
			loadLogs();
		}
		if ( action === 'logs-refresh' ) {
			loadLogs();
		}
	} );

	// Toggles (checkboxes)
	root.addEventListener( 'change', function ( ev ) {
		if ( ! state.status ) { return; }

		if ( wizardActive() ) {
			// Checkbox de aceite do termo: habilita o botão sem re-renderizar
			// (re-render perderia a posição de scroll do texto do termo).
			if ( ev.target.id === 'mmcb-wz-terms-check' ) {
				state.wizard.termsChecked = ev.target.checked;
				var acceptBtn = document.getElementById( 'mmcb-wz-accept' );
				if ( acceptBtn ) { acceptBtn.disabled = ! ev.target.checked; }
				return;
			}
			// Classe checked nas abilities do wizard
			var wzAb = ev.target.closest( '.mmcb-ability-check input[type="checkbox"]' );
			if ( wzAb ) {
				var wzLbl = ev.target.closest( '.mmcb-ability-check' );
				if ( wzLbl ) { wzLbl.classList.toggle( 'checked', ev.target.checked ); }
			}
			return;
		}

		// Atualizar classe checked nas ability-check labels
		var abCb = ev.target.closest( '.mmcb-ability-check input[type="checkbox"]' );
		if ( abCb ) {
			var lbl = ev.target.closest( '.mmcb-ability-check' );
			if ( lbl ) { lbl.classList.toggle( 'checked', ev.target.checked ); }
			return;
		}

		var tg = ev.target.closest( '[data-toggle]' );
		if ( ! tg ) { return; }
		var key = tg.getAttribute( 'data-toggle' );
		// Apenas segurança básica auto-salva; CLI avançado requer botão
		var autoSave = [ 'https_only', 'block_code', 'enable_oauth', 'oauth_auto_approve' ];
		if ( autoSave.indexOf( key ) !== -1 ) {
			var ov = {}; ov[ key ] = tg.checked;
			saveSettings( ov, tg.checked ? 'Ativado.' : 'Desativado.' );
		}
	} );

	// Filtro de ferramentas
	root.addEventListener( 'input', function ( ev ) {
		if ( ! state.status || wizardActive() ) { return; }
		if ( ev.target.id === 'mmcb-search' ) {
			var q = ev.target.value.toLowerCase();
			document.querySelectorAll( '#mmcb-tool-list .mmcb-tool' ).forEach( function ( el ) {
				var name = ( el.getAttribute( 'data-name' ) || '' ).toLowerCase();
				var txt  = el.textContent.toLowerCase();
				el.style.display = ( name.indexOf( q ) !== -1 || txt.indexOf( q ) !== -1 ) ? '' : 'none';
			} );
		}
	} );

	// =========================================================================
	// INIT
	// =========================================================================

	post( 'mmcb_status' ).then( function ( res ) {
		if ( res && res.success ) {
			state.status = res.data;
			// Pré-selecionar builder/tier e o passo inicial do wizard
			var termsOk = state.status.terms && state.status.terms.accepted;
			if ( ! state.status.onboarding_done ) {
				var avail = state.status.available_builders || [];
				state.wizard.builder = avail.length ? avail[ 0 ] : ( state.status.builders && state.status.builders.length ? state.status.builders[ 0 ].slug : '' );
				state.wizard.tier    = 'premium';
				state.wizard.step    = termsOk ? 'builder' : 'terms';
			}
			render();
		} else {
			root.innerHTML = '<div class="mmcb-loading">Falha ao carregar o painel.</div>';
		}
	} ).catch( function () {
		root.innerHTML = '<div class="mmcb-loading">Erro de rede ao carregar o painel.</div>';
	} );
} )();
