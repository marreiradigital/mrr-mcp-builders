# MarreiraMCP Builders — regras do projeto

Plugin WordPress que expõe um **servidor MCP (Model Context Protocol)** unificado
para criar e editar páginas/templates do **Bricks Builder** ou do **Elementor** via
IA, com CLI geral de WordPress, multi-token com escopos, batch de uma requisição e
audit log. Destino: diretório oficial **WordPress.org**.

Código do plugin em [`marreira-mcp-builders/`](marreira-mcp-builders/).

> **Linhagem:** este plugin substitui e congela os dois plugins anteriores —
> `mcp-bricks` (MarreiraMCP Bricks, última versão 0.5.2) e `mcp-elementor`
> (MarreiraMCP Elementor, última versão 0.1.1). Não há mais desenvolvimento
> naqueles repositórios. Todo trabalho novo vai aqui.

---

## 1. Versionamento (OBRIGATÓRIO)

**A cada modificação lógica** do código do plugin, incremente a versão e registre no
changelog — não espere o usuário pedir.

- **patch** (`1.0.x`) — correções e ajustes pequenos.
- **minor** (`1.x.0`) — novas features/tools sem quebra de compatibilidade.
- **major** (`x.0.0`) — quebras de compatibilidade ou mudança de protocolo.

A versão precisa ficar **sincronizada nos quatro lugares** no mesmo commit:

1. Header `Version:` em
   `marreira-mcp-builders/marreira-mcp-builders.php`.
2. Constante `MMCB_VERSION` logo abaixo no mesmo arquivo.
3. `Stable tag:` em `marreira-mcp-builders/readme.txt`.
4. Nova entrada em `marreira-mcp-builders/CHANGELOG.md` **e** na seção
   `== Changelog ==` do `readme.txt`.

A entrada do changelog descreve o **porquê** da mudança (não só o "o quê"),
no formato Keep a Changelog (Adicionado / Alterado / Corrigido / Removido).

---

## 2. Prefixos, namespace e organização

- **Options, hooks e constantes:** prefixo `mmcb_` / `MMCB_`.
- **Namespace PHP:** `Marreira\MCP_Builders`.
- **Autoloader:** PSR-ish mapeando sub-namespace para `includes/`. Exemplo:
  `Marreira\MCP_Builders\Auth\Token_Manager` → `includes/auth/class-token-manager.php`.

Estrutura relevante:

```
marreira-mcp-builders/
├── marreira-mcp-builders.php   ← header + MMCB_VERSION + bootstrap
├── readme.txt                  ← WordPress.org (Stable tag sincronizado)
├── CHANGELOG.md                ← Keep a Changelog
├── SKILL.md                    ← documentação para a IA (servida em /skill)
└── includes/
    ├── auth/                   ← Token_Manager, Auth, throttle, allowlist
    ├── mcp/                    ← MCP_Server, Tool_Registry
    ├── cli/                    ← CLI geral de WordPress
    ├── admin/                  ← SPA do painel, onboarding
    ├── builders/
    │   ├── interface-builder-driver.php
    │   ├── class-builder-manager.php
    │   ├── bricks/             ← driver Bricks (Bricks_Driver + helpers)
    │   └── elementor/          ← driver Elementor (Elementor_Driver + helpers)
    └── ...
```

---

## 3. Arquitetura — regras invioláveis

### 3a. Núcleo agnóstico de builder

O núcleo MCP (`MCP_Server`, `Tool_Registry`, `Auth`, `Audit`, `CLI`) **não pode
depender** de classes específicas do Bricks ou do Elementor. Toda interação com o
builder passa pela interface `Builder_Driver` e pelo driver ativo resolvido pelo
`Builder_Manager`.

- Se precisar checar qual builder está ativo, use `Builder_Manager::active_driver()`
  (ou `active_slug()`) — nunca verifique diretamente `class_exists('Bricks\...')` fora
  do driver correspondente.
- Tools do driver são registradas **via `Tool_Registry`** — não registrá-las
  diretamente no `MCP_Server`.

### 3b. Drivers isolados

Lógica específica de cada builder fica **exclusivamente** em
`includes/builders/<slug>/`. Nunca vazar lógica do Bricks para `includes/builders/elementor/`
ou vice-versa. Nunca referenciar classes de um builder no driver do outro.

Ao adicionar uma feature que afeta os dois builders, avalie:
- Se é lógica de núcleo (ex.: `run_batch`, `get_map`), implemente no núcleo.
- Se é lógica específica do builder (ex.: formato de elemento, regeneração de CSS),
  implemente em cada driver separadamente.

### 3c. Round-trip read-modify-write (inquebrável)

Toda escrita no builder é **read-modify-write**: ler o estado atual, alterar só o
necessário e regravar preservando IDs e campos desconhecidos. Nunca sobrescrever
uma árvore às cegas.

- Páginas criadas pela IA têm que abrir no editor visual e vice-versa.
- Centralize o acesso ao formato nativo do builder no driver — não duplicar
  leitura/escrita de postmeta/options em outros lugares.
- Ao editar uma árvore existente, sempre carregar o estado atual antes de modificar.

### 3d. Tool_Registry como fonte única

O catálogo de tools é **uma fonte única** (`Tool_Registry`) reutilizada pelo servidor
MCP, pelo painel admin e pelo WP-CLI. Nunca duplicar a lista de tools. Ao adicionar
uma tool nova:

1. Registrá-la no `Tool_Registry` do driver (se for específica do builder) ou no
   núcleo (se for agnóstica).
2. Atualizar o `SKILL.md` no mesmo commit.
3. Verificar se o painel e o WP-CLI a exibem corretamente via a fonte única.

### 3e. Um builder ativo por vez

O plugin carrega **um único driver por vez**. Nunca instanciar os dois drivers
simultaneamente. A troca de builder salva a nova escolha nas settings e o próximo
boot carrega o driver correto — não há estado paralelo nem fallback silencioso.

---

## 4. Segurança não-negociável

- Rotas REST **fora** do índice público (`show_in_index => false` + filtros de
  `rest_index`/`rest_namespace_index`). Nunca remover esses filtros.
- Escrita **sempre** com token válido + ability correspondente verificada. Nunca
  `permission_callback => __return_true` em rotas de escrita.
- Token armazenado apenas como hash **HMAC-SHA256** (texto puro nunca gravado, nunca
  logado). Usar `hash_equals` em toda comparação de token.
- **Code_Guard** (anti-RCE) recusa execução de código arbitrário. Não criar caminhos
  que contornem isso. O guard é aplicado antes de qualquer escrita no builder.
- **CLI geral desligado de fábrica** (`enable_general_cli=0`). Poderes perigosos
  (`exec/php`, `db_query`, escrita de arquivo) exigem **trava dupla** — flag de
  settings ligada **e** ability no token, ambas verificadas na rota, nunca só uma.
- **Self-protection no CLI**: antes de qualquer operação destrutiva via CLI, verificar
  se o alvo é o próprio plugin ou o tema ativo e recusar. Nunca expor gestão de
  tokens pela rede (endpoint de criação/revogação de tokens é admin-only, não via
  CLI REST).
- Revalidação do admin dono a cada requisição — `user_can($user_id, 'manage_options')`
  antes de executar qualquer tool. Se o usuário perder o papel de admin, o token perde
  acesso imediatamente.
- Audit log: toda requisição registrada; mascarar PII (emails, tokens parciais) antes
  de gravar; nunca logar o token em texto puro.
- **`MMCB_TRUST_PROXY`**: constante que habilita `X-Forwarded-For` para throttle e
  allowlist. Desativada por padrão. Ao ler o IP, usar sempre a abstração interna —
  nunca `$_SERVER['REMOTE_ADDR']` diretamente quando a constante está ativa.

---

## 5. Padrões de código (WordPress.org)

- Sanitizar **toda** entrada (`sanitize_text_field`, `absint`, `wp_kses`, etc.);
  escapar **toda** saída no admin (`esc_html`, `esc_attr`, `esc_url`).
- Texto visível ao usuário na interface em **pt-BR com acentuação correta**
  (usuário, configuração, ação, atualização, autenticação...). Nunca "usuario",
  "configuracao", "acao".
- Sem `eval`, sem execução de código arbitrário, sem assets minificados sem fonte
  original versionada no repo. Licença GPL em todos os arquivos novos.
- Validar antes de commitar: `php -l` em todos os arquivos tocados. Rodar Plugin
  Check quando possível antes de um pull request.
- Não usar autoload em options sensíveis (tokens, chaves) — sempre `false` no
  argumento de autoload do `add_option`/`update_option`.

---

## 6. Commits incrementais

Um commit por preocupação lógica. Header em **pt-BR sem acentos** (encoding seguro
em terminais Windows/PowerShell). Corpo em pt-BR com acentos, explicando o **porquê**
da mudança. Conventional commits: `tipo(escopo): descricao`.

O bump de versão + changelog entra **no mesmo commit** da mudança que o motivou.
Stage explícito por arquivo — nunca `git add -A` sem revisar o `git status` antes.

Exemplos de header correto (sem acento):
```
feat(batch): run_batch com stop_on_error e dry_run
fix(auth): throttle por IP usando MMCB_TRUST_PROXY
refactor(driver): extrai logica de round-trip para Builder_Driver
feat(cli): rota de snippets PHP com lint php -l
fix(security): self-protection no CLI contra desativacao do proprio plugin
```

Co-Authored-By sempre no rodapé do commit:
```
Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>
```

---

## 7. SKILL.md

- O `SKILL.md` é a documentação que o agente de IA lê para entender o que o plugin
  permite e como usá-lo corretamente.
- **Toda mudança de comportamento observável pela IA** deve refletir no `SKILL.md`
  no mesmo commit: nova tool, novo endpoint, novo parâmetro, mudança de default,
  nova restrição.
- O servidor serve a versão embutida em `GET /wp-json/marreira-mcp/v1/skill`. No
  modo `economy`, serve uma variante enxuta. Se a variante enxuta tiver conteúdo
  diferente (seções omitidas, limites diferentes), manter as duas partes em sincronia
  ao editar o arquivo.
- Ao adicionar um novo poder do CLI geral, documentar a ability exigida e o aviso de
  trava dupla na seção correspondente do `SKILL.md`.

---

## 8. Plugins congelados (não tocar)

Os repositórios `mcp-bricks` e `mcp-elementor` (e os plugins que contêm) estão
**congelados**. Não adicionar features, não corrigir bugs não-críticos, não bumpar
versão, não abrir PRs.

Se surgir uma vulnerabilidade de segurança crítica que afete os dois, avaliar com
o Paulo antes de agir — o caminho preferencial é orientar os usuários a migrar para
o MarreiraMCP Builders, que contém todas as correções de segurança da nova base.
