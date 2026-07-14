=== MarreiraMCP Builders ===
Contributors: paulomarreira
Tags: mcp, ai, bricks builder, elementor, page builder, rest api
Requires at least: 6.4
Tested up to: 6.7
Requires PHP: 8.0
Stable tag: 1.0.2
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

== Changelog ==

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
