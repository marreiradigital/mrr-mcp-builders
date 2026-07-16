<div align="center">

# MarreiraMCP Builders

**Servidor MCP unificado para Bricks Builder e Elementor — IA cria e edita páginas nativamente, de forma segura e reversível.**

[![Versão](https://img.shields.io/badge/versão-1.5.1-3a8bfd.svg)](marreira-mcp-builders/CHANGELOG.md)
[![WordPress](https://img.shields.io/badge/WordPress-6.4%2B-21759b.svg)](https://wordpress.org/)
[![PHP](https://img.shields.io/badge/PHP-7.4%2B-777bb4.svg)](https://www.php.net/)
[![Licença](https://img.shields.io/badge/licença-GPL--2.0%2B-green.svg)](#licença)
[![MCP](https://img.shields.io/badge/protocolo-MCP%20JSON--RPC%202.0-7b5cff.svg)](https://modelcontextprotocol.io/)

**[⬇️ Baixar a última versão (.zip)](https://github.com/marreiradigital/mrr-mcp-builders/releases/latest)** · [Changelog](marreira-mcp-builders/CHANGELOG.md) · [Site](https://marreiradigital.github.io/mrr-mcp-builders/)

</div>

---

## O que é

**MarreiraMCP Builders** é um plugin WordPress que liga um agente de IA (Claude,
Cursor e outros clientes MCP) diretamente ao **Bricks Builder** ou ao **Elementor**.
Em vez de arrastar elementos manualmente, a IA monta e ajusta as páginas — escrevendo
no **formato nativo** de cada builder, o mesmo que o editor visual usa.

O servidor MCP é escrito inteiramente em **PHP** (sem dependência de Node.js) e exposto
por uma rota REST **oculta** e protegida por token. Um site usa **um builder ativo por
vez** (escolhido no onboarding); a troca é feita no painel a qualquer momento.

> **Substitui** dois plugins anteriores agora congelados:
> **MarreiraMCP Bricks** (0.5.2) e **MarreiraMCP Elementor** (0.1.1).
> Esta é a base oficial unificada — veja a seção [Linhagem](#linhagem) ao final.

---

## Destaques

| | |
|---|---|
| 🔀 **Um plugin, dois builders** | Bricks ou Elementor — um ativo por vez, trocável no painel sem reinstalar. |
| 🤖 **IA nativa no builder** | A IA cria seções, containers, headings, botões — no formato real, não em HTML "colado". |
| 🔁 **Round-trip garantido** | Páginas geradas pela IA abrem no editor visual; páginas do editor podem ser refinadas pela IA. |
| 📦 **`run_batch`** | Agrupa N mudanças em **uma única requisição** — evita bloqueios por host/WAF. |
| 🔑 **Multi-token com escopos** | Abilities granulares por token; expiração e IP allowlist individuais. |
| 🛡️ **Seguro por padrão** | HMAC-SHA256, throttle por IP, revalidação do admin, audit log, Code_Guard anti-RCE. |
| 🖥️ **CLI de WordPress** | Plugins, temas, core, arquivos, snippets, banco, exec — desligado de fábrica, trava dupla. |
| ⚡ **Modo IA configurável** | `premium` (completo) ou `economy` (SKILL enxuta, limites menores). |

---

## Requisitos

- WordPress **6.4+**
- PHP **7.4+** (testado em 7.4, 8.0, 8.1, 8.3 e 8.4 a cada push)
- **Bricks Builder** ou **Elementor** ativo (um dos dois)
- **HTTPS** no site (obrigatório)
- Um **cliente MCP** para consumir o servidor (ex.: Claude Desktop, Cursor)

---

## Instalação rápida

1. Baixe o `.zip` da **[última release](https://github.com/marreiradigital/mrr-mcp-builders/releases/latest)**
   e instale em **Plugins → Adicionar novo → Enviar plugin** (ou copie a pasta
   `marreira-mcp-builders/` para `wp-content/plugins/`).
2. Ative em **Plugins**.
3. Acesse **MarreiraMCP Builders** no menu admin.
4. Siga o **wizard de onboarding**:
   - Escolha o **builder ativo** (Bricks ou Elementor — deve estar instalado e ativo).
   - Escolha o **tier de IA** (`premium` ou `economy`).
   - Gere o **primeiro token** e copie-o (aparece uma única vez).
5. Configure seu cliente MCP:

```http
POST https://SEU-SITE/wp-json/marreira-mcp/v1/mcp
Authorization: Bearer <seu-token>
Content-Type: application/json
```

---

## Endpoints

Todas as rotas usam o namespace `marreira-mcp/v1` e são **ocultas** do índice público
de `/wp-json/` (`show_in_index => false` + filtros de índice).

| Rota | Método | Auth | Descrição |
|---|---|---|---|
| `/mcp` | POST | Bearer token | Servidor MCP — JSON-RPC 2.0 (`initialize` / `tools/list` / `tools/call`) |
| `/skill` | GET | pública | Serve o `SKILL.md` (variante enxuta no modo `economy`) |
| `/describe` | GET | Bearer token | Builder ativo, tier, abilities do token e catálogo de tools |
| `/cli/*` | GET/POST | Bearer token + ability | CLI geral de WordPress (desligado de fábrica) |

---

## Arquitetura

```
┌──────────────┐   JSON-RPC 2.0 / HTTPS     ┌───────────────────────────────────────┐
│  Cliente IA  │ ─── Authorization: Bearer ▶ │  WordPress + MarreiraMCP Builders     │
│ (MCP client) │                             │  (rota REST oculta /mcp)              │
└──────────────┘ ◀── resultado JSON ──────── └──────────────────┬────────────────────┘
                                                                 │
                       ┌─────────────────────────────────────────┴──────────────────────┐
                       │  Núcleo MCP (agnóstico de builder)                              │
                       │  Tool_Registry · Auth · Audit · CLI · run_batch · get_map       │
                       └─────────────────────────────────────────┬──────────────────────┘
                                                                 │ Builder_Manager carrega o driver ativo
                                      ┌──────────────────────────┴──────────────────────────┐
                                      │                                                     │
                             ┌────────▼────────────┐                     ┌──────────────────▼──────┐
                             │    Bricks Driver     │                     │    Elementor Driver     │
                             │  includes/builders/  │                     │  includes/builders/     │
                             │  bricks/             │                     │  elementor/             │
                             └─────────────────────┘                     └─────────────────────────┘
```

O núcleo MCP é completamente agnóstico de builder. Lógica específica de cada builder
fica no driver, em `includes/builders/<slug>/`. Os drivers implementam a interface
`Builder_Driver` e fornecem suas tools ao `Tool_Registry` compartilhado.

---

## Modo de IA (tier)

Configurado no onboarding e alterável depois em Configurações:

- **`premium`** — respostas e mapa completos; a IA pode puxar árvores inteiras quando
  precisar. Indicado quando o contexto de tokens não é uma preocupação.
- **`economy`** — o endpoint `/skill` serve uma variante enxuta da documentação; limites
  menores por resposta; a IA é orientada a usar `get_map`, listas paginadas e `run_batch`
  para poupar contexto. Indicado em sites grandes ou quando o custo de tokens importa.

O endpoint `GET /describe` informa o tier atual junto com as abilities do token.

---

## `run_batch` — uma requisição, N mudanças

Muitos POSTs remotos em sequência rápida podem ser interpretados por hosts e WAFs como
padrão de invasão e derrubar a conexão. O `run_batch` resolve isso agrupando tudo em
**um único request**:

```json
{
  "jsonrpc": "2.0",
  "id": 1,
  "method": "tools/call",
  "params": {
    "name": "run_batch",
    "arguments": {
      "commands": [
        {
          "tool": "create_bricks_page",
          "arguments": { "title": "Home", "elements": [] }
        },
        {
          "tool": "regenerate_css",
          "arguments": {}
        }
      ],
      "stop_on_error": true,
      "dry_run": false
    }
  }
}
```

- **`commands`** — lista ordenada de `{ tool, arguments }`, executada na sequência.
- **`stop_on_error`** (padrão `false`) — interrompe no primeiro erro e não aplica os
  comandos seguintes.
- **`dry_run`** (padrão `false`) — valida sem gravar (usa `validate_tree` quando há
  árvore de elementos).
- Resposta: `results[]` com status individual por comando + resumo `ok`/`failed`.

> A SKILL embutida (`/skill`) orienta o agente a usar `run_batch` para toda sequência
> de escrita e a reservar POSTs avulsos apenas para leituras exploratórias pontuais.

---

## CLI geral de WordPress

O plugin inclui um CLI de WordPress acessível via REST, em
`/wp-json/marreira-mcp/v1/cli/*`.

**Vem desligado de fábrica** (`enable_general_cli=0`). Para usar, ative em
Configurações e garanta que o token tem as abilities correspondentes.

| Área | Ability exigida | Operações |
|---|---|---|
| Plugins | `plugins` | listar, instalar, ativar, desativar, atualizar, apagar |
| Temas | `themes` | listar, instalar, ativar, apagar |
| Core WP | `core` | ver versão, atualizar |
| Arquivos do tema ativo | `files` | ler, escrever (com guarda de path traversal) |
| Snippets PHP | `snippets` | CRUD + lint (`php -l`) |
| Conteúdo | `content` | posts, termos, comentários, mídia, usuários |
| Banco (leitura) | `db` | listar tabelas, schema, amostra |
| Banco (query) | `db_query` | SELECT com allowlist — **trava dupla** |
| Exec / PHP | `exec` | executar PHP — **trava dupla**, `allow_php_exec=1` obrigatório |

> **Trava dupla**: poderes perigosos (`db_query`, `exec`, `allow_file_write`) exigem
> a flag de settings ligada **e** a ability correspondente no token. Um sem o outro
> recusa a requisição.

**Self-protection** — o CLI nunca:
- desativa ou remove o próprio plugin;
- apaga o tema ativo;
- expõe gestão de tokens pela rede.

---

## WP-CLI local

Para gerenciar o plugin via linha de comando no servidor:

```bash
# Informações e ferramentas
wp mmcb describe           # builder ativo, tier, abilities, catálogo de tools
wp mmcb tools              # lista todas as tools do builder ativo
wp mmcb builder            # mostra / altera o builder ativo

# Executar tools
wp mmcb call <tool> [args] # executa uma tool diretamente
wp mmcb batch <arquivo>    # executa um run_batch a partir de um arquivo JSON

# Gestão de tokens
wp mmcb token create --abilities=builder,read [--expires=30d] [--label="Claude Desktop"]
wp mmcb token list
wp mmcb token revoke <id>
wp mmcb token delete <id>
```

---

## Segurança

| Mecanismo | Detalhe |
|---|---|
| Hash do token | HMAC-SHA256 — texto puro nunca armazenado nem logado |
| Abilities por token | Escopos granulares; rotas verificam a ability correspondente |
| Expiração | Configurável por token (ex.: `30d`, `90d`, sem expiração) |
| Throttle por IP | 20 tentativas falhas → bloqueio de 5 min |
| IP allowlist | Lista de CIDRs permitidos por token |
| Revalidação do admin | O usuário vinculado ao token precisa continuar admin a cada requisição |
| `MMCB_TRUST_PROXY` | Constante para habilitar leitura de IP via `X-Forwarded-For` em proxies reversos |
| Audit log | Toda requisição registrada; PII mascarado; retenção configurável em dias |
| Code_Guard (anti-RCE) | Recusa elementos/configs que executam código arbitrário |
| Self-protection | CLI não desfaz o próprio plugin nem apaga o tema ativo |
| `hash_equals` | Comparação timing-safe de tokens (sem timing attack) |

---

## Compatibilidade round-trip

Toda escrita no builder segue o padrão **read-modify-write**: o servidor lê o estado
atual, altera apenas o necessário e regrava preservando IDs e campos desconhecidos.
Nunca sobrescreve uma árvore às cegas. Isso garante que:

- Páginas criadas pela IA abrem e editam normalmente no editor visual.
- Páginas criadas no editor podem ser refinadas pela IA sem corrupção.
- Elementos funcionais (formulários, shortcodes, query loops) são preservados ao
  editar páginas existentes.

---

## Linhagem

Este plugin unifica e substitui dois plugins anteriores:

| Plugin | Última versão | Status |
|---|---|---|
| MarreiraMCP Bricks | 0.5.2 | **Congelado** — sem mais atualizações |
| MarreiraMCP Elementor | 0.1.1 | **Congelado** — sem mais atualizações |
| **MarreiraMCP Builders** | **1.5.1** | **Ativo — sucessor oficial** |

Se você usava um dos dois plugins anteriores, desative-o e instale este no lugar. A
compatibilidade round-trip herdada de ambos é preservada neste plugin.

---

## Licença

Licenciado sob **GPL-2.0-or-later**. Use, copie, modifique e distribua livremente —
inclusive em projetos comerciais — mantendo os créditos ao autor original
(**Paulo Marreira / Marreira Digital**).

---

<div align="center">

Feito por **[Paulo Marreira](https://marreiradigital.com.br)** · MarreiraDigital

</div>
