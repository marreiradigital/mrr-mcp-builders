# Changelog

Todas as mudanças relevantes deste projeto são documentadas aqui.

O formato segue [Keep a Changelog](https://keepachangelog.com/pt-BR/1.1.0/)
e o projeto adota [Versionamento Semântico](https://semver.org/lang/pt-BR/).

A cada modificação, a versão é incrementada e sincronizada em quatro lugares:
o header `Version:` e a constante `MMCB_VERSION` do arquivo principal, o
`Stable tag:` do `readme.txt` e uma nova entrada neste arquivo (espelhada na
seção `== Changelog ==` do `readme.txt`).

---

> **MarreiraMCP Builders** é a fusão dos plugins anteriores
> **MarreiraMCP Bricks** (última versão: 0.5.2) e
> **MarreiraMCP Elementor** (última versão: 0.1.1) em um único plugin.
> Os dois plugins de origem foram congelados; este é o sucessor oficial.
>
> A unificação foi motivada por três razões práticas: (1) evitar duplicar
> código de núcleo (Auth, Audit, CLI, batch) entre duas bases que divergiriam
> ao longo do tempo; (2) entregar recursos que fazem sentido apenas como
> infraestrutura compartilhada — multi-token com escopos, `run_batch`,
> modo de IA configurável e CLI geral — sem precisar implementá-los duas
> vezes; (3) simplificar a experiência do usuário com um único plugin, um único
> painel e um único token para administrar.

---

## [1.0.1] - 2026-07-13

### Corrigido

- **`/cli/exec/php` e `/cli/snippets` (criar/atualizar) davam erro fatal.**
  O passo de lint chamava `wp_tempnam()` sem incluir
  `wp-admin/includes/file.php`, que não é carregado no contexto de uma
  requisição REST. Resultado: `Uncaught Error: Call to undefined function
  Marreira\MCP_Builders\CLI\wp_tempnam()` (HTTP 500) em toda chamada dessas
  rotas — inviabilizando a execução de PHP e o CRUD de snippets pela API.

### Alterado

- **Lint de sintaxe sem shell nem arquivo temporário.** `Snippets::lint()`
  passa a usar `token_get_all( $code, TOKEN_PARSE )` — o parser nativo do PHP,
  que lança `\ParseError` em erro de sintaxe **sem executar o código**. Isso
  remove a dependência de `wp_tempnam()`, de I/O em disco e do binário `php` de
  CLI via `exec()` (indisponível/instável em PHP-FPM, CloudPanel e hosts que
  desabilitam `exec`). O caminho antigo (`php -l` via `exec`) permanece apenas
  como fallback para PHP < 7.0, agora com o `require_once` correto e um guard
  que não bloqueia quando `exec` está desabilitado.

## [1.0.0] - 2026-07-13

### Adicionado

#### Plugin unificado e arquitetura de drivers

- **Fusão em um único plugin.** Um plugin substitui MarreiraMCP Bricks (0.5.2)
  e MarreiraMCP Elementor (0.1.1). Um builder ativo por vez; a escolha é feita
  no onboarding e pode ser trocada depois no painel.
- **Autodetecção de builder.** No boot, o `Builder_Manager` detecta qual builder
  (Bricks ou Elementor) está instalado e ativo no site e carrega o driver
  correspondente.
- **Interface `Builder_Driver`.** Contrato comum que todo driver implementa,
  garantindo que o núcleo MCP seja completamente agnóstico de builder.
- **Drivers isolados.** Lógica específica de cada builder confinada em
  `includes/builders/bricks/` e `includes/builders/elementor/` — nunca no
  núcleo.
- **`Tool_Registry` compartilhado.** Tools do driver ativo + tools de núcleo
  (`run_batch`, `get_map`) registradas via fonte única, reutilizadas pelo
  servidor MCP e pelo painel. Nenhum catálogo duplicado.

#### Servidor MCP (PHP nativo)

- **`POST /wp-json/marreira-mcp/v1/mcp`** — endpoint JSON-RPC 2.0 com os
  métodos `initialize`, `tools/list` e `tools/call`.
- **`GET /wp-json/marreira-mcp/v1/skill`** — rota pública; serve o `SKILL.md`
  embutido no plugin (variante enxuta no modo `economy`).
- **`GET /wp-json/marreira-mcp/v1/describe`** — com token; retorna builder
  ativo, tier de IA, abilities do token e catálogo completo de tools.
- Todas as rotas **ocultas** do índice público de `/wp-json/`
  (`show_in_index => false` + filtros de `rest_index`/`rest_namespace_index`).
- Namespace REST: `marreira-mcp/v1`.

#### Tools do builder (fornecidas pelo driver ativo)

- **CRUD de páginas e templates**: criar, ler, atualizar, excluir, listar.
- **Edição fina de elementos**: `insert_element`, `update_element`,
  `move_element`, `delete_element`, `duplicate_element`.
- **Estilos globais**: classes globais e paleta de cores (Bricks); Kit de
  cores e fontes (Elementor).
- **Introspecção**: `list_elements` (catálogo de widgets registrados) e
  `get_element_schema` (schema de settings de um widget).
- **Utilitários**: `get_capabilities`, `validate_tree`, `regenerate_css`.

#### Tools de núcleo

- **`run_batch`** — aplica uma lista ordenada de `{ tool, arguments }` em
  uma única requisição HTTP. Parâmetros: `commands` (lista), `stop_on_error`
  (padrão `false`) e `dry_run` (padrão `false`, usa `validate_tree` quando há
  árvore). Retorna `results[]` com status por comando + resumo `ok`/`failed`.
  Motivação: muitos POSTs remotos em sequência rápida podem ser interpretados
  por hosts/WAFs como padrão de invasão — o batch agrupa tudo em um request só.
- **`get_map`** — mapa compacto do site (páginas, templates, estilos globais).
  Essencial no modo `economy` para a IA se orientar sem precisar carregar
  árvores completas.

#### Modo de IA (tier)

- **`premium`** — respostas e mapa completos; a IA pode puxar árvores inteiras
  conforme precisar.
- **`economy`** — SKILL enxuta servida em `/skill`; limites menores por
  resposta; a IA é orientada a usar `get_map`, listas paginadas e `run_batch`
  para reduzir consumo de contexto. Configurado no onboarding e alterável
  depois nas Configurações.

#### Segurança e multi-token

- **Tabela de tokens** (`mmcb_tokens`) — armazena tokens como hash
  **HMAC-SHA256**; texto puro nunca gravado nem logado.
- **Abilities por token** — escopos granulares: `builder`, `read`, `content`,
  `plugins`, `themes`, `core`, `files`, `snippets`, `db`, `db_query`, `exec`,
  `cli`, `*`. Cada rota verifica a ability correspondente.
- **Expiração** configurável por token.
- **Throttle por IP** — bloqueia acesso por 5 minutos após 20 tentativas de
  autenticação falhas.
- **IP allowlist** — lista de CIDRs permitidos por token; rejeita IPs fora da
  lista quando configurada.
- **Revalidação do admin dono** — a cada requisição, confirma que o usuário
  vinculado ao token ainda tem papel de administrador. Se perder o papel, o
  token perde acesso imediatamente.
- **Constante `MMCB_TRUST_PROXY`** — quando definida, habilita leitura do IP
  real via `X-Forwarded-For` para sites atrás de proxy reverso.
- **Audit log** (`mmcb_logs`) — toda requisição registrada com mascaramento de
  PII; retenção configurável em dias.
- **Code_Guard** (anti-RCE) — guard preservado do mcp-bricks; recusa elementos
  e configurações que executam código arbitrário.
- **`hash_equals`** em todas as comparações de token (timing-safe).

#### CLI geral de WordPress (`/cli/*`)

- **Vem desligado de fábrica** (`enable_general_cli=0`, `allow_php_exec=0`,
  `allow_db_query=0`, `allow_file_write=0`).
- Cobre: plugins (listar/instalar/ativar/desativar/atualizar/apagar), temas
  (idem), core WP (ver versão/atualizar), arquivos do tema ativo (ler/escrever,
  com guarda de path traversal), snippets PHP (CRUD + lint `php -l`), conteúdo
  (posts/termos/comentários/mídia), banco (listar tabelas/schema/amostra + SELECT
  com allowlist), usuários, logs de WP e **exec/php**.
- Poderes perigosos (`exec/php`, `db_query`, escrita de arquivo) com
  **trava dupla**: flag de settings ligada **E** ability correspondente no
  token — ambas obrigatórias, nenhuma sozinha é suficiente.
- **Self-protection**: o CLI não desativa/remove o próprio plugin, não apaga o
  tema ativo e não expõe gestão de tokens pela rede. Cada rota verifica sua
  própria ability.

#### WP-CLI local

- `wp mmcb tools` / `wp mmcb call` / `wp mmcb batch` / `wp mmcb describe` /
  `wp mmcb builder`.
- `wp mmcb token create` / `list` / `revoke` / `delete`.

#### Painel administrativo

- **SPA** (Single-Page Application) com menu próprio no admin do WordPress.
- **Wizard de onboarding**: escolha do builder ativo, tier de IA e geração do
  primeiro token.
- **Abas**: Painel (métricas + status do ambiente), Tokens (gestão multi-token),
  Logs (audit log com filtros), Configurações e Ferramentas (catálogo de tools
  + tester de chamadas).

#### Documentação / SKILL

- `SKILL.md` embutido no plugin e servido publicamente em
  `GET /wp-json/marreira-mcp/v1/skill`.
- Variante enxuta automática para o modo `economy`.
- Seção **"Regra de ouro"** orienta a IA a usar `run_batch` para toda
  sequência de escrita.

[1.0.1]: https://marreiradigital.com.br/marreira-mcp-builders
[1.0.0]: https://marreiradigital.com.br/marreira-mcp-builders
