# MarreiraMCP Builders — guia da IA

Servidor **MCP (Model Context Protocol)** unificado para criar e editar
páginas e templates do **Bricks Builder** ou do **Elementor** via IA, com um
**CLI** completo de WordPress e segurança por token com escopos.

Um site usa **um builder ativo por vez** (escolhido no painel). As tools do
builder ativo aparecem em `tools/list` — sempre confirme lá os nomes exatos
antes de chamar.

---

## 1. Conexão

- **Endpoint MCP:** `https://SEU-SITE/wp-json/marreira-mcp/v1/mcp`
- **Método:** `POST` (JSON-RPC 2.0, um JSON de resposta por POST).
- **Auth:** header `Authorization: Bearer <token>`. TLS obrigatório.
- **Rota oculta** do índice público de `/wp-json/`.
- **Documentação (esta skill):** `GET /wp-json/marreira-mcp/v1/skill` (pública).
- **Auto-descoberta:** `GET /wp-json/marreira-mcp/v1/describe` (com token) —
  retorna builder ativo, tier de IA, abilities do seu token e o catálogo de tools.

O token carrega **escopos (abilities)**. Para as tools do builder você precisa
da ability `builder`. Rotas de CLI geral exigem abilities específicas
(`plugins`, `themes`, `core`, `files`, `content`, `db`, `db_query`, `exec`, ...).

---

## 2. Handshake

1. `initialize` → devolve `protocolVersion`, `serverInfo` (nome, versão, builder
   ativo) e `instructions`.
2. `tools/list` → catálogo de tools do builder ativo + tools de núcleo.
3. `tools/call` → executa uma tool. O resultado vem em
   `result.content[0].text` (string, geralmente JSON) e `result.isError`.

---

## 3. REGRA DE OURO — agrupe mudanças em UMA requisição (`run_batch`)

Sempre que for aplicar **mais de uma mudança**, **não** dispare vários POSTs em
sequência. Muitas requisições remotas seguidas podem ser interpretadas por
alguns hosts/WAFs como **ataque/invasão** e derrubar a conexão. Em vez disso,
**junte tudo em um único `run_batch`**:

```json
{
  "name": "run_batch",
  "arguments": {
    "commands": [
      { "tool": "create_bricks_page", "arguments": { "title": "Home", "elements": [ ... ] } },
      { "tool": "regenerate_css", "arguments": {} }
    ],
    "stop_on_error": false,
    "dry_run": false
  }
}
```

- `commands`: lista ordenada de `{ tool, arguments }`, aplicada numa só requisição.
- `stop_on_error` (padrão `false`): interrompe no primeiro erro.
- `dry_run` (padrão `false`): valida sem aplicar (usa `validate_tree` quando há árvore).
- Retorna o **status por comando** (`results[]`) + resumo (`ok`/`failed`).

Use várias requisições **apenas** para leituras exploratórias pontuais. Toda
sequência de escrita deve ser um `run_batch`.

---

## 4. Fluxo recomendado

1. `get_capabilities` → confirma o builder ativo, versões e flags de segurança.
2. `get_map` → **mapa compacto** do site (páginas, templates, resumo de estilos).
   Use para se orientar e buscar só o que precisar (essencial no modo econômico).
3. Leia o design system do site (paleta/cores/fontes/classes globais) com as
   tools de estilo do builder ativo — **antes** de gerar layout.
4. `validate_tree` → cheque a árvore antes de gravar.
5. Aplique tudo com **`run_batch`** (criar/atualizar página + ajustes + CSS).
6. Ao editar páginas existentes: **leia primeiro** (`get_page`) e **preserve**
   elementos funcionais (formulários, shortcodes, query loops) — enriqueça em
   volta, não recrie.

---

## 5. Modo de IA (premium x econômico)

O painel configura um `ai_tier` que muda como você deve consumir contexto:

- **premium**: pode puxar o mapa inteiro e árvores completas conforme precisar.
- **economy**: **poupe contexto** — comece por `get_map`, liste com limites
  pequenos, busque árvores só das páginas que vai mexer, e sempre agrupe
  escritas em `run_batch`. Esta skill é servida numa variante enxuta neste modo.

`describe` informa o tier atual.

---

## 6. Boas práticas de design

- Não fixe `font-size`/`font-weight`/`line-height` em títulos/textos quando o
  builder tem estilos de tema/Kit — use tags semânticas e os tokens globais.
- Dê um `label` legível a cada elemento.
- Escrita é **round-trip-safe**: o servidor lê o estado atual, altera só o
  necessário e regrava preservando IDs e campos desconhecidos. Nunca dependa de
  sobrescrever a árvore cega.
- Elementos de código podem ser recusados pelo guard anti-RCE (configurável).
  Evite `{echo:` / `{do_action:` / `<script>` mesmo em texto.

---

## 7. CLI geral de WordPress (opcional, desligado de fábrica)

Além do builder, o plugin expõe um CLI de WordPress em
`/wp-json/marreira-mcp/v1/cli/...` (plugins, temas, core, arquivos do tema,
snippets, conteúdo, leitura de banco, exec). **Vem desligado**: o dono precisa
ativar `enable_general_cli` no painel, e os poderes perigosos (`exec/php`,
`db/query`, escrita de arquivo) têm trava dupla (flag + ability do token). Cada
rota exige a ability correspondente. Consulte `GET /cli/describe`.

---

## 8. Segurança (o que esperar)

- Token só como hash (HMAC-SHA256); escopos por token; expiração; throttle por
  IP; IP allowlist; o dono do token precisa continuar admin.
- Toda requisição é auditada (com mascaramento de dados sensíveis).
- O CLI não desativa/remove o próprio plugin nem apaga o tema ativo.
