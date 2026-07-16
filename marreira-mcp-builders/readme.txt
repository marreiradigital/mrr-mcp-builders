=== MarreiraMCP Builders ===
Contributors: paulomarreira
Tags: mcp, ai, bricks builder, elementor, page builder, rest api
Requires at least: 6.4
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 1.6.2
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Servidor MCP unificado para Bricks Builder e Elementor: IA cria e edita páginas nativamente, com CLI de WordPress, multi-token com escopos e batch em uma requisição.

== Description ==

**MarreiraMCP Builders** expõe um servidor **MCP (Model Context Protocol)** dentro
do WordPress para que um agente de IA (Claude, Cursor e outros clientes MCP) possa
criar, ler, editar e excluir páginas e templates feitos no **Bricks Builder** ou no
**Elementor** — gerando exatamente o mesmo formato de dados que o editor visual usa.

Um site usa **um builder ativo por vez**, escolhido no wizard de onboarding e trocável
depois no painel. O plugin detecta automaticamente qual builder está instalado e ativo.

= Por que um plugin unificado? =

Este plugin é o sucessor oficial de **MarreiraMCP Bricks** (0.5.2) e
**MarreiraMCP Elementor** (0.1.1), ambos congelados. A fusão centraliza a manutenção,
elimina código duplicado e entrega novos recursos — CLI geral, multi-token com escopos,
batch de uma requisição e modo de IA configurável — disponíveis para ambos os builders
de uma só vez.

= Principais recursos =

* Servidor MCP (PHP nativo) sobre uma rota REST **oculta** do índice público de `/wp-json/`.
* **Multi-token com abilities** — crie quantos tokens precisar, cada um com escopos
  próprios (`builder`, `read`, `content`, `plugins`, `themes`, `core`, `files`,
  `snippets`, `db`, `db_query`, `exec`, `cli`, ...) e expiração configurável.
* **`run_batch`** — aplica vários comandos em **uma única requisição** (evita que
  hosts/WAFs bloqueiem sequências de POSTs como padrão de ataque).
* CRUD de páginas e templates, edição fina de elementos (inserir/atualizar/mover/
  excluir/duplicar), estilos globais, introspecção de widgets e regeneração de CSS.
* **Modo de IA**: `premium` (respostas completas) ou `economy` (SKILL enxuta, limites
  menores, otimizado para poupar contexto).
* **CLI geral de WordPress** (desligado de fábrica): plugins, temas, core, arquivos
  do tema, snippets PHP, conteúdo, banco e exec — com trava dupla nos poderes perigosos.
* **WP-CLI local**: `wp mmcb tools|call|batch|describe|builder` e
  `wp mmcb token create|list|revoke|delete`.
* **Audit log** com mascaramento de PII e retenção configurável.
* Compatibilidade **round-trip** garantida: páginas criadas pela IA abrem no editor
  visual, e páginas feitas no editor podem ser refinadas pela IA sem corrupção.
* **Painel SPA** com wizard de onboarding, gestão de múltiplos tokens, logs e catálogo
  de ferramentas com tester de chamadas.

= Segurança =

* Token armazenado apenas como hash **HMAC-SHA256** (texto puro nunca gravado).
* Throttle por IP (20 tentativas falhas bloqueiam por 5 min) e IP allowlist por token.
* Revalidação do admin dono a cada requisição.
* Guard anti-RCE (`Code_Guard`): recusa elementos e configurações que executam código.
* CLI geral desligado de fábrica; poderes perigosos exigem flag de settings **e**
  ability correspondente no token (trava dupla).
* Self-protection: o CLI não desativa o próprio plugin, não apaga o tema ativo e não
  expõe gestão de tokens pela rede.

= Endpoints =

* `POST /wp-json/marreira-mcp/v1/mcp` — servidor MCP (JSON-RPC 2.0), auth Bearer.
* `GET  /wp-json/marreira-mcp/v1/skill` — serve o SKILL.md (pública, sem token).
* `GET  /wp-json/marreira-mcp/v1/describe` — builder ativo, tier, abilities, catálogo.
* `/wp-json/marreira-mcp/v1/cli/*` — CLI geral (desligado de fábrica).

== Installation ==

1. Envie a pasta `marreira-mcp-builders` para `/wp-content/plugins/` (ou instale o
   `.zip` pelo painel do WordPress).
2. Ative o plugin em **Plugins**.
3. Acesse **MarreiraMCP Builders** no menu do painel admin.
4. Siga o **wizard de onboarding**:
   a. Escolha o **builder ativo** (Bricks Builder ou Elementor — deve estar instalado
      e ativo no site).
   b. Escolha o **tier de IA**: `premium` (respostas completas) ou `economy` (otimizado
      para poupar contexto da IA).
   c. Gere o **primeiro token** e copie-o (aparece uma única vez; guarde em lugar seguro).
5. Configure seu cliente MCP com o endpoint e o cabeçalho de autenticação:

   ```
   POST https://SEU-SITE/wp-json/marreira-mcp/v1/mcp
   Authorization: Bearer <seu-token>
   Content-Type: application/json
   ```

6. (Opcional) Para usar o **CLI geral**: ative `Habilitar CLI geral` nas Configurações.
   Libere os poderes perigosos individualmente (`exec/php`, `db_query`, escrita de
   arquivo) e garanta que o token usado tenha as abilities correspondentes. Não ative
   o que não for usar.

== Frequently Asked Questions ==

= Posso usar Bricks Builder e Elementor ao mesmo tempo? =

Não. Um builder ativo por vez. O builder é escolhido no onboarding e pode ser trocado
depois no painel (aba Configurações → Builder ativo). Ao trocar, o driver do builder
anterior é descarregado e o novo é carregado — ambos nunca rodam simultaneamente.

= O CLI geral é perigoso? =

Vem **desligado de fábrica** (`enable_general_cli=0`). Quando ativado, os poderes
perigosos (`exec/php`, `db_query`, escrita de arquivo) exigem **trava dupla**: a flag
de settings precisa estar ligada **e** o token usado precisa ter a ability correspondente.
Sem os dois, a rota é recusada mesmo com o CLI ativo.

O CLI também tem self-protection: não consegue desativar o próprio plugin, apagar o
tema ativo nem expor a gestão de tokens pela rede.

= O endpoint aparece na listagem pública de /wp-json/? =

Não. Todas as rotas são registradas com `show_in_index => false` e o namespace é
removido dos índices via filtros do WordPress, ficando completamente fora da descoberta
automática por ferramentas de scan.

= A IA pode executar código PHP no meu site? =

Não por padrão. O guard `Code_Guard` recusa elementos e configurações que executam
código (elemento Code do Bricks, SVG com código, tags `{echo:}`, `{do_action:}`,
scripts em page settings). O exec/php do CLI geral exige ativação explícita pelo admin
**e** a ability `exec` no token.

= O que é o run_batch e por que devo usá-lo? =

`run_batch` aplica vários comandos do builder em uma única requisição HTTP. Muitos POSTs
remotos em sequência rápida podem ser interpretados por hosts e WAFs como padrão de
invasão e derrubar a conexão. O `run_batch` agrupa tudo em um request só, evitando esse
problema e reduzindo a latência total. A SKILL do plugin orienta o agente de IA a usar
`run_batch` para toda sequência de escrita.

= O que é o modo economy? =

Um tier de IA configurável que afeta como o agente de IA deve consumir contexto. No
modo `economy`, o endpoint `/skill` serve uma variante enxuta da documentação, as
respostas têm limites menores e a IA é orientada a usar `get_map` e listas paginadas
antes de puxar árvores completas. Útil em sites grandes onde o mapa completo seria caro
em tokens de IA.

= Este plugin substitui o MarreiraMCP Bricks e o MarreiraMCP Elementor? =

Sim. Ambos os plugins anteriores foram **congelados** e não receberão mais atualizações.
MarreiraMCP Builders é o sucessor oficial. Se você usava um dos dois, desative-o e
instale este no lugar.

= Preciso de HTTPS? =

Sim. O plugin exige HTTPS por padrão para proteger o token em trânsito.

== Aviso Legal ==

O MarreiraMCP Builders é distribuído sob **GPL 2.0 ou posterior**, **sem garantia de
qualquer espécie** — conforme as cláusulas 15 e 16 da GPL, o programa é fornecido "no
estado em que se encontra", sem garantias implícitas de comercialização ou de adequação
a uma finalidade específica.

**Responsabilidade pelo uso:** este plugin é um servidor MCP estruturado — ele executa o
que o modelo de IA conectado solicitar, dentro dos limites que você configurar. Ele não
é um agente de IA e não toma decisões sozinho. Toda ação (criação, modificação e
exclusão de conteúdo, ativação de plugins, escrita de arquivos, consultas ao banco) é
determinada pelo modelo de IA e pelas abilities (escopos) que você habilitou. A escolha
do modelo, dos escopos e dos poderes ativados é inteiramente sua, e os autores não se
responsabilizam por danos decorrentes das ações do modelo de IA conectado.

**Poderes perigosos vêm desligados de fábrica.** O CLI geral (execução de PHP, consultas
diretas ao banco, escrita de arquivos) exige ativação explícita pelo administrador **e**
a ability correspondente no token (trava dupla). Ao ativar esses recursos, você autoriza
conscientemente operações potencialmente destrutivas.

**Recomendação:** faça backup completo (banco e arquivos) antes de conectar qualquer
agente de IA, habilite apenas as abilities estritamente necessárias e acompanhe o audit
log no painel. O primeiro acesso ao painel exige o aceite do Termo de Responsabilidade,
que fica registrado com usuário, data e versão do termo.

== Changelog ==

= 1.6.2 =
* Correção: clientes MCP com validação estrita (ex.: Claude Code) conectavam mas não
  carregavam nenhuma tool. As 11 tools sem argumentos declaravam `properties` como
  array vazio (`[]`) em vez de objeto (`{}`), e o JSON Schema exige objeto — então o
  catálogo inteiro era recusado. Afetava `tools/list` e `/describe`, nos dois builders.
  Reportado por @HermesMacedo (#1).
* Docs: nova seção "Problemas comuns" no README, incluindo a dica sobre hosts
  LiteSpeed que aplicam throttle por user-agent e podem retornar 429 durante a conexão.

= 1.6.1 =
* Correção: o `Author URI` também apontava para um endereço fora do ar (erro 520) — a
  1.6.0 corrigiu só o `Plugin URI`. É o link do nome do autor na lista de plugins,
  quebrado para todo usuário. Agora aponta para o site do plugin.

= 1.6.0 =
* Novo: **atualização pelo painel do WordPress**. O plugin não está no WordPress.org,
  então atualizar exigia baixar o zip e reenviar — e ninguém ficava sabendo que saiu
  versão nova (inclusive correção de segurança). Agora o wp-admin oferece "Atualizar
  agora", servindo o zip das Releases do GitHub. Usa o mecanismo oficial do núcleo
  (header `Update URI` + filtro `update_plugins_{host}`, desde a WP 5.8).
* Novo: link **"Checar atualização"** na lista de plugins, para uma checagem imediata
  (o manifesto fica 12h em cache).
* Novo: a coluna **"Atualizações automáticas"** do WordPress passa a funcionar para
  este plugin.
* Segurança: o manifesto de atualização só pode apontar o zip para as Releases deste
  repositório — o que instalar é dele, de onde baixar não.
* Correção: `Plugin URI` apontava para um endereço fora do ar (erro 520), deixando
  quebrado o link "Visitar site do plugin" que aparece no painel de todo usuário.

= 1.5.1 =
Segundo lote da auditoria — os achados menores que ficaram de fora da 1.5.0.
* Elementor: páginas criadas em CPT (product, portfolio...) eram marcadas como
  documento de página; o Elementor abria com a classe errada. Só `page` é documento
  de página; o resto é documento de post, como o próprio Elementor faz.
* Bricks: o gerador de ID ignorava os IDs já em uso quando o helper nativo estava
  disponível — uma colisão fazia insert/duplicate falhar com "Id duplicado".
* Segurança: `safe_theme_path()` validava só o diretório pai imediato; um symlink
  mais acima na árvore permitia gravar fora do tema.
* Segurança (Elementor): árvores de elementos não tinham limite de profundidade —
  um payload absurdamente aninhado derrubava o processo. O driver do Bricks já
  estava protegido; agora o do Elementor também.
* MCP: `notifications/initialized` enviado com `id` não recebia resposta, violando
  a JSON-RPC 2.0.
* Rate limit por token deixou de ter corrida em requisições simultâneas (usa
  incremento atômico quando há object cache persistente).
* Painel: horários misturavam fusos — um token OAuth de 1 hora aparecia expirando
  4 horas depois. Corrigido na exibição.
* OAuth: a URL de retorno do login deixou de ser montada a partir do `HTTP_HOST`.
* Limpeza de authorization codes deixou de preservar linhas mortas por ~1 hora.

= 1.5.0 =
Lote de correções vindas de uma auditoria completa do plugin.
* Segurança: revogar um conector no painel não cortava o acesso — o refresh token
  continuava rotacionando indefinidamente (cada rotação renovava por mais 30 dias).
  Revogar o conector agora revoga todos os tokens OAuth dele.
* Segurança: a trava dupla sumia na rotação do refresh — desligar `allow_php_exec`
  não tirava o escopo `exec` de uma conexão já existente. Agora toda rotação
  refiltra pelas settings atuais.
* Segurança: a rotação passa a revalidar que o dono do token ainda é administrador.
* Segurança: a troca de `code` por token agora confere se o client segue aprovado.
* Segurança (Bricks): desligar o bloqueio anti-RCE (para permitir o elemento Code,
  que entra inerte e exige assinatura manual) também desativava, sem querer, a
  checagem das page settings — liberando `customScriptsHeader` e afins, que o
  Bricks injeta no `<head>` e executa sem nenhuma assinatura. Page settings agora
  são sempre inspecionadas, como o driver do Elementor já fazia.
* Correção grave: `/cli/db/query` executava um SQL diferente do pedido. As strings
  literais eram esvaziadas para análise e a versão esvaziada é que era executada —
  `WHERE post_status = 'publish'` virava `= ''`. Toda query com literal voltava
  vazia ou errada, sem erro.
* `/cli/db/query` agora recusa comentários SQL: o MySQL executa comentários
  versionados (`/*!...*/`), o que contornaria as checagens de keyword.
* Detecção de `LIMIT` não cai mais em literal (`WHERE t = 'limit 5'`).
* Segurança: ativar um snippet passa a exigir a flag `allow_php_exec` — snippet ativo
  executa PHP em toda requisição, e antes bastava a ability `snippets`. CRUD de
  snippet inativo não mudou, e snippets já ativos continuam rodando.
* Segurança: o throttle passa a contar falha de token revogado/expirado.
* Novo: a aba Conectores mostra **conexão** (conectado / desconectado / nunca
  conectou / acesso revogado) e a data do último uso — antes só mostrava o status de
  registro e nunca dizia se o app tinha realmente conectado.
* Correção: `state='0'` era descartado do redirect OAuth (`array_filter` remove falsy),
  violando a RFC 6749 e fazendo o conector abortar por suspeita de CSRF.
* Correção: `uninstall.php` não apagava as tabelas `mmcb_oauth_clients` e
  `mmcb_oauth_codes`.
* Correção: tokens OAuth acumulavam sem limpeza (cada renovação insere uma linha). O
  purge diário agora remove os mortos e a listagem do painel ganhou limite.

= 1.4.0 =
* Correção: erro fatal na ativação em PHP anterior a 8.2 ("Cannot use 'true' as
  class name as it is reserved"). O tipo de retorno `true|\WP_Error` só é válido
  a partir do PHP 8.2 — em versões anteriores derrubava o site no load do plugin.
* O plugin agora exige PHP 7.4 (antes 8.0) e roda em 7.4, 8.0, 8.1, 8.2, 8.3 e 8.4.
* Union types removidos das assinaturas (PHP 8.0+, sem equivalente em 7.4); o tipo
  passou para o docblock `@return`, como faz o próprio núcleo do WordPress. Sem
  mudança de comportamento.
* CI: `php -l` em todos os arquivos nas versões 7.4, 8.0, 8.3 e 8.4 a cada push,
  para que uma incompatibilidade de versão não chegue mais em produção.

= 1.3.1 =
* Correção: PKCE verificado antes de consumir o authorization code (verifier
  errado não queima mais o code).
* redirect_uri obrigatório no /token (retorna invalid_request, por RFC 6749).
* /marreira-mcp-oauth/authorize responde 405 a métodos diferentes de GET/POST.
* Removido check de Origin inócuo do endpoint MCP (Bearer token já autentica;
  DNS rebinding não se aplica a credencial não-ambiente).
* Docs: SKILL.md e SKILL.economy.md com referência completa de endpoints e a
  seção de como conectar Claude.ai / ChatGPT.

= 1.3.0 =
* Nova aba "Conectores" no painel: URL do servidor MCP para colar no Claude.ai/
  ChatGPT, lista de clientes registrados com aprovar/revogar e contagem de
  pendentes.
* Toggles enable_oauth e oauth_auto_approve no painel (salvamento automático).
* Novos eventos de auditoria oauth:client_approved e oauth:client_revoked.
* Limpeza automática de authorization codes expirados no cron diário.

= 1.2.0 =
* Fluxo OAuth completo (Authorization Code + PKCE) — fecha a conexão como
  conector de IA externo (Claude.ai / ChatGPT).
* Tela de consentimento do administrador em /marreira-mcp-oauth/authorize
  (cookie de sessão do WordPress + nonce), com escopos e trava dupla nos
  sensíveis.
* /marreira-mcp-oauth/token: troca code->token (PKCE S256) e refresh->token
  (rotação). Tokens emitidos via Token_Manager (mesma tabela/pipeline HMAC).
* Authorization codes single-use, TTL 60s, só como hash, com lock contra corrida.
* Mapa único escopo<->ability (OAuth\Scopes) com a mesma trava dupla do CLI geral.
* Mais chaves mascaradas no audit; redirect_uri por correspondência exata.

= 1.1.0 =
* Discovery OAuth e Dynamic Client Registration para conexão como conector de IA
  externo (Claude.ai / ChatGPT), servidos na raiz do site (fora do /wp-json).
* `/.well-known/oauth-protected-resource` (RFC 9728) e
  `/.well-known/oauth-authorization-server` (RFC 8414).
* `POST /marreira-mcp-oauth/register` (RFC 7591): clients nascem pendentes e só
  operam após aprovação do admin; rate limit por IP.
* Novas tabelas mmcb_oauth_clients e mmcb_oauth_codes e colunas OAuth em
  mmcb_tokens; migração automática (DB_VERSION 1 → 2).
* Novas configurações enable_oauth e oauth_auto_approve.

= 1.0.2 =
* Conformidade de transporte para conectores de IA externos (Claude.ai / ChatGPT):
  primeira etapa da conexão como servidor MCP remoto de consumidor.
* `initialize` passa a negociar `protocolVersion` — ecoa a versão pedida pelo
  cliente quando suportada (`2025-11-25`, `2025-06-18`, `2025-03-26`) em vez de
  responder sempre uma versão fixa.
* Respostas 2xx do endpoint MCP passam a incluir o header `MCP-Protocol-Version`.
* Respostas `401` do endpoint MCP passam a incluir `WWW-Authenticate: Bearer
  resource_metadata="..."` — o gatilho do discovery OAuth (RFC 9728).
* O check de `Origin` (anti DNS rebinding) só é aplicado sem token válido: um
  Bearer autenticado libera conexões server-to-server que enviam `Origin` próprio.

= 1.0.1 =
* Correção: `/cli/exec/php` e `/cli/snippets` (criar/atualizar) retornavam erro
  fatal `Call to undefined function wp_tempnam()` — o lint carregava `wp_tempnam()`
  sem incluir `wp-admin/includes/file.php` no contexto REST.
* O lint de sintaxe passa a usar `token_get_all()` com a flag `TOKEN_PARSE`
  (parser nativo do PHP, sem executar o código), eliminando a dependência de
  arquivo temporário e do binário `php` de CLI via `exec()` — funciona em
  qualquer SAPI, inclusive PHP-FPM. O `php -l` fica apenas como fallback para
  PHP < 7.0, agora com o `require_once` correto.

= 1.0.0 =
* Lançamento inicial — fusão de MarreiraMCP Bricks (0.5.2) e MarreiraMCP Elementor
  (0.1.1) em um único plugin unificado. Ambos os plugins anteriores foram congelados;
  este é o sucessor oficial.
* Arquitetura de drivers: núcleo MCP agnóstico de builder; drivers isolados para Bricks
  e Elementor em `includes/builders/<slug>/`; autodetecção do builder ativo no boot.
* Multi-token com abilities (escopos granulares por token), hash HMAC-SHA256, expiração,
  throttle por IP e IP allowlist.
* `run_batch`: agrupa N comandos em uma única requisição HTTP (evita bloqueios por
  host/WAF), com `stop_on_error` e `dry_run`.
* `get_map`: mapa compacto do site para orientação rápida da IA.
* Modo de IA: `premium` (respostas completas) ou `economy` (SKILL enxuta, limites
  menores).
* CLI geral de WordPress (desligado de fábrica) com trava dupla nos poderes perigosos
  e self-protection.
* WP-CLI local: `wp mmcb`.
* Audit log com mascaramento de PII e retenção configurável.
* Painel SPA com wizard de onboarding, gestão de múltiplos tokens, logs e catálogo.

== Upgrade Notice ==

= 1.3.1 =
Correções no fluxo OAuth (PKCE antes de consumir o code, 405 no authorize) e
documentação completa dos endpoints nas skills. Recomendado.

= 1.3.0 =
Adiciona a aba Conectores para gerenciar as conexões OAuth (aprovar/revogar
clientes) e copiar a URL do servidor MCP para o Claude.ai / ChatGPT.

= 1.2.0 =
Fecha o fluxo OAuth: já é possível conectar Claude.ai / ChatGPT como conector
remoto. Aprove o cliente no painel e autorize na tela de consentimento.

= 1.1.0 =
Adiciona discovery OAuth e registro de clients para conectar Claude.ai / ChatGPT.
Cria novas tabelas automaticamente no primeiro carregamento após a atualização.
O fluxo OAuth completo (consentimento + token) chega na próxima versão.

= 1.0.2 =
Prepara o plugin para conexão como conector de IA externo (Claude.ai / ChatGPT):
negociação de versão do protocolo MCP e desafio OAuth (WWW-Authenticate). Sem
impacto para quem já usa token estático no header.

= 1.0.1 =
Corrige erro fatal em /cli/exec/php e /cli/snippets (wp_tempnam indefinido).
Recomendado para quem usa o CLI geral com exec/php ou snippets.

= 1.0.0 =
Versão inicial do plugin unificado. Substitui MarreiraMCP Bricks e MarreiraMCP
Elementor — desative o plugin anterior antes de ativar este.
