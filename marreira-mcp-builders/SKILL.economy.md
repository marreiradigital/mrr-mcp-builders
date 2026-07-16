# MarreiraMCP Builders — guia enxuto (modo econômico)

MCP para editar **Bricks** ou **Elementor** (um builder ativo por vez). Versão
enxuta para poupar contexto. Um site = um builder; veja os nomes exatos em
`tools/list`.

## Conexão
> As URLs deste documento já vêm com o domínio real do site (substituídas em
> runtime) — a URL desta skill basta; não é preciso ditar endpoint por endpoint.
- POST `https://SEU-SITE/wp-json/marreira-mcp/v1/mcp` — `Authorization: Bearer <token>`, TLS.
- Handshake: `initialize` → `tools/list` → `tools/call`. Resultado em `result.content[0].text`.
- **Conector Claude.ai / ChatGPT:** aponte o conector para a URL do MCP acima; o
  OAuth (discovery + registro + consentimento no WordPress + token PKCE) é
  automático. O admin aprova o cliente na aba "Conectores" do painel.

## Endpoints
- `POST /wp-json/marreira-mcp/v1/mcp` — JSON-RPC (Bearer: token ou OAuth).
- `GET /wp-json/marreira-mcp/v1/skill` — esta doc (pública).
- `GET /wp-json/marreira-mcp/v1/describe` — builder/tier/abilities/tools (Bearer).
- `/wp-json/marreira-mcp/v1/cli/...` + `/cli/describe` — CLI geral (desligado).
  Com o CLI ligado, as operacoes aparecem como tools MCP `wp_*` em tools/list
  (wp_list_plugins, wp_create_post, wp_get_option/wp_update_option, wp_db_query,
  wp_exec_php...). Config de plugins de terceiros = options (`wp_list_options`
  por trecho do nome, `wp_get_option`, `wp_update_option`; ability `options`).
  Meta privada (_price, _sku, _yoast_*, ACF) via campo `meta` de wp_create_post/
  wp_update_post e `include_private:true` em wp_get_post.
- OAuth (na raiz, uso automático pelo app): `GET /.well-known/oauth-protected-resource`,
  `GET /.well-known/oauth-authorization-server`, `POST /marreira-mcp-oauth/register`,
  `GET|POST /marreira-mcp-oauth/authorize`, `POST /marreira-mcp-oauth/token`.

## Regras (siga à risca)
1. **Comece por `get_map`** (mapa compacto: páginas, templates, resumo de estilos).
   Não puxe o site inteiro. Liste com `limit` pequeno. Busque a árvore só das
   páginas que vai editar (`get_page`).
2. **Toda escrita vai em UM `run_batch`** — nunca vários POSTs seguidos (hosts
   podem tratar como ataque):
   ```json
   { "name":"run_batch", "arguments":{ "commands":[
       {"tool":"<create/update...>","arguments":{...}},
       {"tool":"regenerate_css","arguments":{}}
   ], "stop_on_error":false } }
   ```
   Use `dry_run:true` para validar antes.
   **Interface completa (estrutura + estilos + fontes + cores): tudo em UM
   `run_batch`** — hosts com rate-limit agressivo bloqueiam a 2ª/3ª requisição
   seguida e a página fica pela metade.
3. **Editar existente:** `get_page` primeiro; preserve formulários/shortcodes/loops.
4. Antes de gerar, leia paleta/cores/fontes/classes globais do site (tools de
   estilo do builder ativo). Use tokens globais, não valores fixos.
5. `get_capabilities` confirma builder, versões e flags. `validate_tree` valida a árvore.

## Passo a passo
`get_capabilities` → `get_map` → (ler estilos) → `validate_tree` → **`run_batch`**.

## Observações
- Escrita é round-trip-safe (preserva IDs/campos desconhecidos).
- Guard anti-RCE pode recusar código; evite `{echo:`/`{do_action:`/`<script>`.
- CLI geral de WP existe em `/cli/...` mas vem desligado (veja `/cli/describe`).
- `db/query`: só SELECT/SHOW/DESCRIBE/EXPLAIN, 1 instrução, sem comentários SQL
  (`--`, `#`, `/* */`). Valores por placeholder (`%s` + `args`). `LIMIT 1000` padrão.
- Ativar snippet exige a flag `allow_php_exec` (snippet ativo roda PHP em todo
  request), além da ability `snippets`. CRUD de snippet inativo não exige. Sem a
  flag, ativar retorna `403 mmcb_php_exec_disabled`.
