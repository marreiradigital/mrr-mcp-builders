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

	// ---- estado global ----
	var state = {
		status:       null,
		tab:          'painel',
		flashToken:   null,  // plaintext exibido uma vez após geração
		wizard: {
			step:    1,
			builder: '',
			tier:    '',
			token:   null,   // plaintext após step 3
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
	// WIZARD DE ONBOARDING
	// =========================================================================

	var KNOWN_ABILITIES = [ '*', 'builder', 'read', 'content', 'plugins', 'themes', 'core', 'files', 'snippets', 'db', 'db_query', 'exec', 'cli' ];

	function renderWizard() {
		var w = state.wizard;
		var stepDots = [ 1, 2, 3 ].map( function ( n ) {
			var cls = n === w.step ? 'active' : ( n < w.step ? 'done' : '' );
			return '<div class="mmcb-wizard-step-dot ' + cls + '">' + ( n < w.step ? '✓' : n ) + '</div>';
		} ).join( '' );

		var stepTitles = [ 'Escolha o builder', 'Qual IA vai consumir o MCP?', 'Gere seu primeiro token' ];
		var stepSubs   = [
			'Selecione o page builder instalado neste site.',
			'Isso define como o servidor envia o contexto para a IA.',
			'O token autentica o agente de IA. Você pode gerar mais depois.',
		];

		var inner = '';
		if ( w.step === 1 ) { inner = wizardStep1(); }
		else if ( w.step === 2 ) { inner = wizardStep2(); }
		else { inner = wizardStep3(); }

		root.innerHTML =
			'<div class="mmcb-wizard">' +
				'<div class="mmcb-wizard-header">' +
					'<div class="mmcb-logo" aria-hidden="true" style="margin:0 auto 16px;"><span></span><span></span><span></span><span></span></div>' +
					'<h2>Configuração inicial — MarreiraMCP Builders</h2>' +
					'<p>Passo ' + w.step + ' de 3 — ' + esc( stepSubs[ w.step - 1 ] ) + '</p>' +
				'</div>' +
				'<div class="mmcb-wizard-steps">' + stepDots + '</div>' +
				'<div class="mmcb-wizard-card">' +
					'<h3>' + esc( stepTitles[ w.step - 1 ] ) + '</h3>' +
					'<p class="mmcb-hint">' + esc( stepSubs[ w.step - 1 ] ) + '</p>' +
					inner +
				'</div>' +
			'</div>';
	}

	function wizardStep1() {
		var s = state.status;
		var available = s.available_builders || [];
		// Pré-selecionar: se nenhum selecionado ainda, use o primeiro disponível.
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
				'<button class="mmcb-btn mmcb-btn-primary" data-wz-next="2"' + ( state.wizard.builder ? '' : ' disabled' ) + '>Próximo →</button>' +
			'</div>';
	}

	function wizardStep2() {
		var tier = state.wizard.tier || 'premium';
		if ( ! state.wizard.tier ) { state.wizard.tier = 'premium'; }

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
				'<button class="mmcb-btn" data-wz-back="1">← Voltar</button>' +
				'<button class="mmcb-btn mmcb-btn-primary" data-wz-next="3">Próximo →</button>' +
			'</div>';
	}

	function wizardStep3() {
		var w = state.wizard;
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

		return tokenFlash +
			'<div class="mmcb-field"><label for="mmcb-wz-tname">Nome do token</label>' +
				'<input type="text" id="mmcb-wz-tname" value="Agente IA" placeholder="Ex.: Claude Desktop">' +
			'</div>' +
			'<div class="mmcb-field"><label>Permissões (abilities)</label>' +
				'<div class="mmcb-abilities-grid">' + abilityBoxes + '</div>' +
			'</div>' +
			'<div class="mmcb-field"><label for="mmcb-wz-exp">Validade (dias, 0 = nunca expira)</label>' +
				'<input type="number" id="mmcb-wz-exp" min="0" value="0">' +
			'</div>' +
			'<div class="mmcb-wizard-actions">' +
				'<button class="mmcb-btn" data-wz-back="2">← Voltar</button>' +
				'<button class="mmcb-btn" id="mmcb-wz-gen">Gerar token</button>' +
				'<button class="mmcb-btn mmcb-btn-primary" id="mmcb-wz-finish">Concluir configuração</button>' +
			'</div>';
	}

	function handleWizardClick( ev ) {
		var target = ev.target;

		// Selecionar builder
		var bCard = target.closest( '[data-wz-builder]' );
		if ( bCard ) {
			state.wizard.builder = bCard.getAttribute( 'data-wz-builder' );
			renderWizard();
			return;
		}

		// Selecionar tier
		var tCard = target.closest( '[data-wz-tier]' );
		if ( tCard ) {
			state.wizard.tier = tCard.getAttribute( 'data-wz-tier' );
			renderWizard();
			return;
		}

		// Avançar passo
		var nextBtn = target.closest( '[data-wz-next]' );
		if ( nextBtn ) {
			state.wizard.step = parseInt( nextBtn.getAttribute( 'data-wz-next' ), 10 );
			renderWizard();
			return;
		}

		// Voltar passo
		var backBtn = target.closest( '[data-wz-back]' );
		if ( backBtn ) {
			state.wizard.step = parseInt( backBtn.getAttribute( 'data-wz-back' ), 10 );
			renderWizard();
			return;
		}

		// Copiar
		var copyBtn = target.closest( '[data-copy]' );
		if ( copyBtn ) {
			var el = document.querySelector( copyBtn.getAttribute( 'data-copy' ) );
			if ( el ) { copyText( el.textContent, copyBtn ); }
			return;
		}

		// Gerar token (step 3)
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
					state.wizard.token = res.data.token;
					state.status = res.data.status;
					toast( 'Token gerado. Copie antes de concluir!', 'ok' );
					renderWizard();
				} else {
					toast( 'Falha ao gerar token.', 'err' );
				}
			} ).catch( function () { target.disabled = false; toast( 'Erro de rede.', 'err' ); } );
			return;
		}

		// Concluir onboarding
		if ( target.id === 'mmcb-wz-finish' ) {
			if ( ! state.wizard.builder || ! state.wizard.tier ) {
				toast( 'Selecione builder e tier antes de concluir.', 'err' );
				return;
			}
			target.disabled = true;
			post( 'mmcb_complete_onboarding', { builder: state.wizard.builder, tier: state.wizard.tier } )
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
			'<div class="mmcb-badges">' + b + '</div>' +
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

		// Endpoints
		function epRow( label, url, id ) {
			return '<div class="mmcb-field"><label>' + esc( label ) + '</label>' +
				'<div class="mmcb-copy-row"><code class="mmcb-code" id="' + id + '">' + esc( url ) + '</code>' +
				'<button class="mmcb-btn mmcb-btn-sm mmcb-copy" data-copy="#' + id + '">Copiar</button></div></div>';
		}

		var endpoints =
			epRow( 'Endpoint MCP (POST, JSON-RPC 2.0)', ep.mcp || '', 'mmcb-ep-mcp' ) +
			epRow( 'Skill (GET, Markdown para a IA)', ep.skill || '', 'mmcb-ep-skill' ) +
			epRow( 'Describe (GET, auto-descoberta)', ep.describe || '', 'mmcb-ep-desc' ) +
			epRow( 'CLI (POST)', ep.cli || '', 'mmcb-ep-cli' );

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
				'<p class="mmcb-hint">Use a URL do endpoint MCP na configuração do agente de IA.</p>' +
				endpoints +
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

	function viewFor( tab, logsData ) {
		var s = state.status;
		if ( tab === 'tokens' )        { return viewTokens( s._tokens || [] ); }
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
		if ( ! s || ! s.onboarding_done ) {
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
		var st   = state.status.settings;
		var data = Object.assign( {}, st, overrides || {} );
		return post( 'mmcb_save_settings', data ).then( function ( res ) {
			if ( res && res.success ) {
				state.status = res.data;
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

	// =========================================================================
	// DELEGAÇÃO DE EVENTOS — APP PRINCIPAL
	// =========================================================================

	root.addEventListener( 'click', function ( ev ) {
		// Wizard — passa primeiro
		if ( ! state.status || ! state.status.onboarding_done ) {
			handleWizardClick( ev );
			return;
		}

		// Tab
		var tabBtn = ev.target.closest( '.mmcb-tab' );
		if ( tabBtn ) {
			state.tab = tabBtn.getAttribute( 'data-tab' );
			if ( state.tab === 'tokens' ) {
				loadTokens( function () { render(); } );
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
		if ( ! state.status || ! state.status.onboarding_done ) { return; }

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
		var autoSave = [ 'https_only', 'block_code' ];
		if ( autoSave.indexOf( key ) !== -1 ) {
			var ov = {}; ov[ key ] = tg.checked;
			saveSettings( ov, tg.checked ? 'Ativado.' : 'Desativado.' );
		}
	} );

	// Filtro de ferramentas
	root.addEventListener( 'input', function ( ev ) {
		if ( ! state.status || ! state.status.onboarding_done ) {
			// Atualizar checked na classe da label do wizard
			var wzCb = ev.target.closest( '.mmcb-ability-check' );
			if ( wzCb ) {
				var cb2 = wzCb.querySelector( 'input[type="checkbox"]' );
				if ( cb2 ) { wzCb.classList.toggle( 'checked', cb2.checked ); }
			}
			return;
		}
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
			// Pré-selecionar builder para o wizard
			if ( ! state.status.onboarding_done ) {
				var avail = state.status.available_builders || [];
				state.wizard.builder = avail.length ? avail[ 0 ] : ( state.status.builders && state.status.builders.length ? state.status.builders[ 0 ].slug : '' );
				state.wizard.tier    = 'premium';
			}
			render();
		} else {
			root.innerHTML = '<div class="mmcb-loading">Falha ao carregar o painel.</div>';
		}
	} ).catch( function () {
		root.innerHTML = '<div class="mmcb-loading">Erro de rede ao carregar o painel.</div>';
	} );
} )();
