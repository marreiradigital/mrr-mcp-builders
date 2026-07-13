# MarreiraMCP Builders — guia enxuto (modo econômico)

MCP para editar **Bricks** ou **Elementor** (um builder ativo por vez). Versão
enxuta para poupar contexto. Um site = um builder; veja os nomes exatos em
`tools/list`.

## Conexão
- POST `https://SEU-SITE/wp-json/marreira-mcp/v1/mcp` — `Authorization: Bearer <token>`, TLS.
- Handshake: `initialize` → `tools/list` → `tools/call`. Resultado em `result.content[0].text`.

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
