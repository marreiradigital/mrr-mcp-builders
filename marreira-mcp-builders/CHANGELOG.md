# Changelog

Todas as mudanças relevantes deste projeto são documentadas aqui.

O formato segue [Keep a Changelog](https://keepachangelog.com/pt-BR/1.1.0/)
e o projeto adota [Versionamento Semântico](https://semver.org/lang/pt-BR/).

As mudanças se acumulam em **[Unreleased]** conforme são feitas. A versão só é
fechada quando há um lote fechado para publicar — aí a seção Unreleased vira uma
versão datada e o número é sincronizado em quatro lugares: o header `Version:` e a
constante `MMCB_VERSION` do arquivo principal, o `Stable tag:` do `readme.txt` e a
entrada deste arquivo (resumida na seção `== Changelog ==` do `readme.txt`).

Bumpar a cada mudança fragmentaria o changelog em entradas pobres e encheria as
Releases de versões-ruído. Acumular deixa cada versão contar a história inteira do
lote — que é o que se lê para decidir se atualiza.

---

## [Unreleased]

_Nada ainda._

---

## [1.6.2] - 2026-07-16

### Corrigido

- **Clientes MCP com validação estrita não carregavam nenhuma tool.** As tools sem
  argumentos declaravam `inputSchema.properties` como array vazio do PHP, e o
  `json_encode` não tem como adivinhar que aquele `array()` queria ser objeto: saía
  `[]`. O JSON Schema exige que `properties` seja um **objeto**, então clientes que
  validam de verdade — o **Claude Code**, por exemplo — recusavam o `tools/list`
  **inteiro**. O sintoma enganava: o servidor conectava (`Connected`), mas nenhuma
  das tools ficava disponível (`expected record, received array`).

  Eram **11 tools**, não 6: `list_global_classes`, `list_color_palette`,
  `get_theme_styles`, `list_fonts`, `get_capabilities` e `list_elements` no driver
  Bricks, mais `get_capabilities`, `get_kit_settings`, `list_elements`,
  `list_global_colors` e `list_global_fonts` no Elementor. E o `/describe` sofria do
  mesmo problema, não só o `tools/list`.

  A normalização ficou em `Tool_Registry::definitions()` — o ponto único de saída do
  catálogo, que alimenta `tools/list`, `/describe`, o painel e o WP-CLI. Ficar ali, e
  não nos helpers `schema()` de cada driver, faz valer para qualquer tool, registrada
  pelo helper do driver, inline pelo núcleo ou por um driver futuro. A recursão é
  ciente de schema (desce só em `properties` e `items`), então também cobre objetos
  aninhados e não estraga uma propriedade que por acaso se chame `properties`.

  Reportado por [@HermesMacedo](https://github.com/HermesMacedo) em
  [#1](https://github.com/marreiradigital/mrr-mcp-builders/issues/1), com
  diagnóstico e patch — obrigado.

### Documentação

- `README.md` ganhou uma seção **Problemas comuns**, com o sintoma acima e com a
  dica (também do #1) sobre hosts **LiteSpeed** que aplicam throttle por user-agent:
  o handshake do Claude Code pode levar 429 antes de a requisição chegar ao plugin, e
  registrar o MCP com um `User-Agent` próprio resolve.

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

## [1.6.1] - 2026-07-16

### Corrigido

- **`Author URI` também apontava para o endereço fora do ar.** A 1.6.0 corrigiu só o
  `Plugin URI`; o `Author URI` continuou em `marreiradigital.com.br`, que responde
  erro 520. É o link do nome do autor na lista de plugins do painel — quebrado para
  todo usuário, e o WordPress.org também confere esse link na revisão. Agora aponta
  para o site do plugin, junto com o `Plugin URI` e o `Update URI`.
- O crédito do autor no `README.md` e o link do autor no modal "Ver detalhes"
  apontavam para o mesmo endereço; o do modal passou a sair do próprio manifesto, em
  vez de repetir a URL na mão.

Nenhum link vivo do projeto aponta mais para `marreiradigital.com.br` — quando o
servidor voltar, é só reapontar o que fizer sentido.

---

## [1.6.0] - 2026-07-16

### Adicionado

- **Atualização pelo painel do WordPress.** O plugin não está no WordPress.org, então
  atualizar exigia baixar o `.zip` e reenviar pelo painel — e quem instalou não ficava
  sabendo que saiu versão nova (inclusive correção de segurança, como as da 1.5.0).
  Agora o wp-admin oferece "Atualizar agora" normalmente, servindo o `.zip` das
  Releases do GitHub.

  Usa o mecanismo **oficial** do núcleo para plugins hospedados fora do WordPress.org
  (desde a WP 5.8): o header `Update URI:` declara quem manda nas atualizações e o
  núcleo dispara o filtro `update_plugins_{hostname}`. Não há hack de
  `pre_set_site_transient_update_plugins`. De quebra, o `Update URI` impede que um
  plugin homônimo publicado no WordPress.org sequestre a atualização deste — que é
  exatamente o motivo pelo qual o header existe.

  A checagem lê um manifesto (`update.json`) no próprio site do plugin, gerado pelo
  build a partir do header, e **não** a API do GitHub: a API limita a 60 requisições
  por hora **por IP** sem autenticação, e em hospedagem compartilhada vários sites
  saem pelo mesmo IP — a checagem falharia justo em quem mais precisa. O manifesto é
  servido por CDN, sem limite, e fica 12h em cache no site.

- **Link "Checar atualização"** nas ações do plugin, na lista de plugins. O manifesto
  fica 12h em cache; sem isso não havia como pedir uma checagem imediata depois de sair
  uma versão. Descarta o cache do plugin e o do núcleo, recheca na hora e informa o
  resultado.

- **A coluna "Atualizações automáticas" do WordPress passa a funcionar** para este
  plugin (é a nativa do núcleo, não um controle próprio — um controle próprio não
  gravaria na option `auto_update_plugins` do WordPress). Ela só aparece para plugin
  que o núcleo considere atualizável, e para isso o filtro precisa devolver o payload
  **sempre**, inclusive quando não há versão nova: quem compara as versões e decide
  entre "há atualização" e "sem atualização, mas suportado" é o próprio núcleo.
  Retornar `false` quando está tudo em dia tira o plugin dos dois casos e a tela passa
  a dizer que atualizações automáticas não estão disponíveis.

### Segurança

- O manifesto diz **o que** instalar, mas não **de onde**: o `package` é recusado se
  não vier das Releases deste repositório (prefixo fixo no código). Sem essa trava, um
  comprometimento do site que serve o JSON viraria execução de código arbitrário em
  todo site que tem o plugin instalado.

### Corrigido

- **`Plugin URI` apontava para um endereço fora do ar.** O header apontava para
  `marreiradigital.com.br`, que responde erro 520 (a Cloudflare alcança o servidor de
  origem mas recebe resposta inválida) — ou seja, o link "Visitar site do plugin" que
  todo usuário vê no painel estava quebrado, e o WordPress.org exige esse link
  funcionando na revisão. Agora aponta para o site do plugin, que é a documentação
  pública dele. O `Author URI` segue no domínio próprio.
- Os links de versão do `CHANGELOG.md` apontavam para o mesmo endereço fora do ar;
  agora vão para a tag da release correspondente.

---

## [1.5.1] - 2026-07-16

Segundo lote da auditoria: os achados menores que ficaram de fora da 1.5.0.

### Corrigido

- **Elementor: CPTs eram gravados como documento de página.** `create_page()`
  definia `_elementor_template_type` com `'post' === $post_type ? 'wp-post' :
  'wp-page'` — o inverso do que o Elementor faz. O Elementor
  (`Documents_Manager::get_doc_type_by_id()`) só mapeia `post => wp-post` e
  `page => wp-page`, e para qualquer outro post_type cai no fallback `post`, que
  aponta para a mesma classe do `wp-post`. Ou seja: um CPT (`product`,
  `portfolio`...) era marcado como `wp-page` e o Elementor instanciava o
  documento com a classe errada (Page em vez de Post). Agora só `page` é
  documento de página; todo o resto é documento de post.
- **Bricks: o gerador de ID ignorava os IDs já em uso.** `generate_id()` usava o
  helper nativo do Bricks sem consultar `$taken` (o comentário afirmava que ele
  "garante unicidade global", mas ele apenas sorteia 6 caracteres). Como
  `regenerate_ids()` acumula em `$taken` os IDs já presentes na página e os que
  acabou de gerar, uma colisão passava batido e só estourava depois, no
  `validate()`, como "Id duplicado" — `insert_element`/`duplicate_element`
  falhando com erro opaco. Agora `$taken` vale para os dois caminhos.
- **`safe_theme_path()` validava só o diretório pai imediato.** Com
  `a/b/c.php`, se `a` fosse um symlink para fora do tema e `a/b` ainda não
  existisse, o pai imediato não existia, a validação era pulada e o
  `theme_file_write()` criava `b` dentro do symlink — gravando fora do tema.
  Agora sobe até o primeiro ancestral existente e valida esse.
- **`notifications/initialized` com `id` não respondia.** Retornava `202` sem
  corpo mesmo quando o cliente mandava um `id`. A JSON-RPC 2.0 exige responder
  com o mesmo `id` a qualquer requisição que o traga; um cliente que espera
  correlacionar ficava sem resposta e podia tratar como falha de rede. Sem `id`
  (o caso normal) continua `202`.
- **Rate limit por token tinha corrida.** `get_transient()` + `set_transient()`
  não é atômico: em requisições simultâneas duas threads liam o mesmo valor e as
  duas passavam, deixando o limite efetivo em `max + concorrência - 1`. Com
  object cache persistente (Redis/Memcached) passa a usar `wp_cache_incr()`, que
  é atômico; sem object cache externo, mantém o transient.
- **Horários do painel misturavam fusos.** A tabela grava `created_at` e
  `last_used_at` em hora do site (`current_time`) e `expires_at` em UTC
  (`gmdate`), e o painel renderizava todos como hora local — num site em UTC−3,
  um token OAuth de 1 hora aparecia expirando 4 horas depois. A conversão passou
  a ser feita na camada de exibição. (O armazenamento estava correto: o
  `Rest_Guard` compara em UTC, e o WordPress força o fuso do PHP para UTC.)
- **OAuth: `current_url()` montava a URL a partir do `HTTP_HOST`**, que é escrito
  pelo cliente, para gerar o `redirect_to` do wp-login. Na prática o WordPress já
  barraria um destino forjado no `wp_safe_redirect`, mas não há motivo para
  depender disso — agora host e esquema vêm do `home_url()` e só o caminho vem da
  requisição.
- **Purge de authorization codes preservava linhas mortas por ~1 hora.** O TTL do
  code é de 60s; a carência caiu para 5 minutos.

### Segurança

- **Elementor: árvores sem limite de profundidade.** O `Code_Guard` e o
  `Element_Tree` percorrem a árvore recursivamente e o PHP não tem teto de
  recursão — uma árvore absurdamente aninhada derrubava o processo antes de
  qualquer validação. O driver do Bricks já estava protegido pelo `deep_clean`
  (que também remove bytes nulos e rejeita objetos PHP); o do Elementor não tinha
  equivalente. Agora tem, com teto de 200 níveis — bem acima do de 30 do Bricks,
  porque a árvore do Bricks é plana e a do Elementor é aninhada (cada nível de
  elemento custa dois níveis de array, e containers aninham à vontade), então um
  teto baixo recusaria página legítima.

---

## [1.5.0] - 2026-07-16

Lote de correções vindas de uma auditoria completa do plugin (OAuth, autenticação,
MCP/CLI e drivers de builder).

### Segurança

- **Revogar um conector no painel não cortava o acesso.** `Client_Manager::revoke()`
  apenas marcava o client como `revoked`, mas o `Rest_Guard` nunca consulta a tabela
  de clients e o `rotate_refresh()` também não. Resultado: o access token seguia
  válido e o refresh token continuava rotacionando — e **cada rotação emitia um
  refresh novo de 30 dias**, ou seja, acesso permanente apesar da revogação. Revogar
  o conector agora revoga também todos os tokens OAuth dele
  (`Token_Manager::revoke_by_client()`), e a rotação passa a exigir client aprovado.
- **A trava dupla sumia na rotação do refresh token.** `rotate_refresh()` copiava as
  abilities do token antigo sem reaplicar `Scopes::to_abilities()` com as settings
  atuais. Um conector que ganhou o escopo `exec` enquanto `allow_php_exec` estava
  ligado continuava renovando com `exec` depois de o admin desligar a flag — a trava
  valia só na emissão inicial. Agora toda rotação refiltra pelas settings do momento.
- **`rotate_refresh()` não revalidava o dono.** Um admin despromovido continuava
  gerando pares novos (o `Rest_Guard` barrava no uso, mas o log registrava emissão
  bem-sucedida). Agora exige `manage_options` do dono, como o resto do pipeline.
- **Troca `code` → token não checava o status do client.** Entre o consentimento e a
  troca cabem até 60s (TTL do code) — tempo de sobra para revogar o conector no
  painel e o token ser emitido mesmo assim. O `/token` agora confere o client.
- **Bricks: desligar o anti-RCE liberava injeção de `<script>` no site.**
  `Code_Guard::inspect_page_settings()` (Bricks) saía sem inspecionar nada quando
  `block_code` estava desligado, deixando passar `customScriptsHeader` /
  `customScriptsBodyHeader` / `customScriptsBodyFooter` e `customCss` perigoso —
  que o Bricks injeta no `<head>` e executa no primeiro carregamento. O toggle
  existe para liberar o **elemento `code`**, que entra inerte (sem `signature`, o
  Bricks não executa até um humano assinar no editor); os `customScripts*` não têm
  etapa de assinatura nenhuma. Agora page settings são sempre inspecionadas,
  independente do toggle — igual ao driver do Elementor, que já fazia assim, e
  igual ao que `inspect_elements()` já aplicava aos elementos não-`code`.

- **Headers de proxy eram aceitos sem validar a origem nem o formato.** Com
  `MMCB_TRUST_PROXY` ligada, `client_ip()` aceitava `CF-Connecting-IP` / `X-Real-IP` /
  `X-Forwarded-For` de qualquer origem e **sem checar se o valor era sequer um IP**.
  Quem alcançasse a origem direto (sem passar pelo Cloudflare/nginx) mandava
  `X-Real-IP: <ip da allowlist>` e entrava, ou rotacionava IPs forjados e nunca
  atingia o throttle; `X-Forwarded-Proto: https` furava o HTTPS-only com o token
  trafegando em claro. Agora todo valor passa por `FILTER_VALIDATE_IP`, e
  `MMCB_TRUST_PROXY` aceita uma **lista de IPs de proxy** — os headers só valem
  quando o `REMOTE_ADDR` é um deles. O valor `true` mantém o comportamento antigo
  (com a validação de formato), para não quebrar quem já usa.

- **Ativar um snippet contornava a flag `allow_php_exec`.** Um snippet ativo executa
  PHP em toda requisição do site — é execução de PHP tanto quanto `/cli/exec/php`,
  mas exigia apenas `enable_general_cli` + ability `snippets`. Um token restrito a
  `snippets` (sem `exec`) rodava o que quisesse com `allow_php_exec` desligado, que
  é justamente a flag pela qual o admin diz "não quero execução de PHP". Ativar
  (via create, update ou toggle) agora exige `allow_php_exec`. Criar, ler, editar e
  apagar snippet **inativo** seguem exigindo só a ability `snippets`, e snippets já
  ativos continuam rodando.
- **Throttle não contava falha de token revogado/expirado.** Só as etapas de formato
  inválido e hash não encontrado incrementavam, então dava para sondar tokens
  conhecidos sem nunca gastar o limite de 20 tentativas.

### Adicionado

- **A aba Conectores passa a mostrar conexão de verdade.** A tela se chamava
  "Clientes conectados" e mostrava apenas o status de *registro*
  (pendente/aprovado/revogado) — nunca dizia se o app chegou a conectar. Registro e
  conexão são coisas diferentes: quem sabe da conexão é a tabela de tokens, e nada
  ligava as duas. Agora cada conector mostra **conectado / desconectado / nunca
  conectou / acesso revogado** e a data do último uso, derivados dos tokens OAuth
  emitidos para aquele `client_id`.

### Corrigido

- **`/cli/db/query` executava um SQL diferente do pedido, devolvendo dados errados
  em silêncio.** Para procurar keywords perigosas sem falso positivo (ex.:
  `WHERE t = 'UPDATE ...'`), `validate_select()` monta um "probe" com o conteúdo
  das strings literais esvaziado — e devolvia o **probe** em vez do SQL original.
  `WHERE post_status = 'publish'` virava `WHERE post_status = ''`. Toda query com
  literal (`=`, `LIKE`, `IN`) voltava vazia ou errada, sem erro nenhum: a IA
  concluía que a tabela estava vazia. Agora o probe serve só para a análise e o
  SQL executado é o original.
- **Comentários SQL passam a ser recusados em `/cli/db/query`.** Não é preferência
  de estilo: o MySQL *executa* comentários versionados (`/*!40000 DROP TABLE x */`).
  Como a análise removia os comentários, passar a executar o SQL original abriria um
  bypass de todas as checagens de keyword. Recusar de saída mantém o que é analisado
  e o que é executado equivalentes token a token. (O código anterior não tinha esse
  furo apenas por acidente — ele executava o probe, já sem comentários.)
- **Detecção de `LIMIT` deixou de cair em literal.** `WHERE t = 'limit 5'` fazia o
  `LIMIT` parecer presente e a query voltava sem teto de 1000 linhas. A checagem
  agora usa o probe.
- **`state='0'` era descartado do redirect do OAuth.** `array_filter()` sem callback
  remove valores falsy, e `'0'` é falsy em PHP. A RFC 6749 §4.1.2 exige devolver o
  `state` exatamente como veio: um cliente que usasse `state='0'` recebia o redirect
  sem `state`, concluía que era CSRF e abortava — com o admin tendo autorizado.
- **`uninstall.php` deixava as tabelas OAuth no banco.** Só apagava `mmcb_tokens`,
  `mmcb_logs` e `mmcb_snippets`; `mmcb_oauth_clients` e `mmcb_oauth_codes` (criadas
  na 1.3.x) sobreviviam à desinstalação com client_ids, redirect_uris e IPs de
  registro.
- **Tokens OAuth acumulavam para sempre.** Cada rotação de refresh revoga a linha
  antiga e insere uma nova (~24/dia por conector ativo), mas o cron diário limpava
  só authorization codes e logs. Agora o purge diário também remove tokens OAuth
  mortos (revogados e com o access token expirado há mais de 7 dias — a carência
  mantém a conexão recente visível no painel), e `list_tokens()` ganhou `LIMIT` para
  o payload do painel não crescer sem teto.

---

## [1.4.0] - 2026-07-16

### Corrigido

- **Erro fatal na ativação em PHP anterior a 8.2** (`Cannot use 'true' as class
  name as it is reserved`). O método `CLI\Rest_Controller::require_cli_enabled()`
  declarava o tipo de retorno `true|\WP_Error`. O tipo literal `true` em union
  type só existe a partir do **PHP 8.2**; em versões anteriores `true` é palavra
  reservada e o parser tentava interpretá-la como nome de classe — resolvendo
  para `Marreira\MCP_Builders\CLI\true` e derrubando o site inteiro no load do
  plugin. Ou seja: o plugin declarava `Requires PHP: 8.0`, mas esse trecho na
  prática exigia 8.2.

### Alterado

- **Piso de PHP reduzido de 8.0 para 7.4** (`Requires PHP: 7.4`). O plugin agora
  roda em 7.4, 8.0, 8.1, 8.2, 8.3 e 8.4. O núcleo do WordPress suporta PHP 7.2+,
  e uma parte relevante dos sites em produção ainda está em 7.4 — manter um piso
  em 8.0 excluía esses sites sem que houvesse necessidade técnica real.
- **Union types removidos das 59 assinaturas** de `CLI\Rest_Controller` e
  `CLI\Content` (`\WP_REST_Response|\WP_Error`, `array|\WP_Error`,
  `string|\WP_Error`). Union type em assinatura é PHP 8.0+ e não tem equivalente
  em 7.4. O tipo passou para o docblock (`@return`), que é a convenção do próprio
  núcleo do WordPress. Não há mudança de comportamento: o projeto não usa
  `declare(strict_types=1)`, e nenhuma dessas assinaturas fazia coerção — a única
  perda é a checagem de tipo em runtime, que a documentação agora expressa.

### Adicionado

- **Guard de compatibilidade no CI** (`.github/workflows/php-compat.yml`): roda
  `php -l` em todos os arquivos do plugin nas versões **7.4, 8.0, 8.3 e 8.4** a
  cada push e pull request. Foi exatamente a ausência de uma checagem assim que
  deixou um construto de PHP 8.2 entrar num plugin que se declarava 8.0 e só
  quebrar em produção, no site do usuário final.
- **Script `scripts/lint-php.sh`** para rodar o mesmo lint localmente.

---

## [1.3.1] - 2026-07-14

### Corrigido

- **PKCE verificado antes de consumir o authorization code.** Antes o code era
  marcado como usado e só depois o PKCE era conferido — um `code_verifier`
  errado (bug do cliente ou tentativa de interceptação) queimava o code e
  obrigava novo consentimento. Agora, se o PKCE falhar, o code permanece válido
  para a troca legítima.
- **`redirect_uri` obrigatório no `/token`** (grant `authorization_code`): a
  ausência agora retorna `invalid_request` (correto por RFC 6749) em vez de
  `invalid_grant`.
- **`/marreira-mcp-oauth/authorize`** responde `405 Method Not Allowed` a métodos
  diferentes de GET/POST (antes qualquer método caía no fluxo do GET).
- Removido o check de `Origin` do endpoint MCP, que era efetivamente inócuo (o
  `permission_callback` autentica antes, então o ramo nunca disparava). Como a
  autenticação é por Bearer token — credencial não-ambiente —, ataques de DNS
  rebinding não se aplicam; o token não pode ser obtido por página maliciosa.

### Documentação

- `SKILL.md` e `SKILL.economy.md`: nova referência **completa de endpoints**
  (incluindo os OAuth) e seção **"Conectar como conector (Claude.ai / ChatGPT)"**.

## [1.3.0] - 2026-07-14

### Adicionado

- **Aba "Conectores" no painel** — quarta etapa (gestão e visibilidade do OAuth):
  - Mostra a **URL do servidor MCP** para colar no conector do Claude.ai/ChatGPT
    (com botão copiar) e os endpoints OAuth como referência técnica.
  - **Lista de clientes** registrados via DCR, com contagem de pendentes e botões
    **Aprovar** / **Revogar** por cliente.
  - Toggles **Habilitar conector OAuth** (`enable_oauth`) e **Aprovar clientes
    automaticamente** (`oauth_auto_approve`), com salvamento automático.
- Novos eventos no audit log: `oauth:client_approved` e `oauth:client_revoked`
  (aprovação/revogação pelo admin).
- **Limpeza automática** dos authorization codes expirados acoplada ao cron
  diário existente (`mmcb_daily_purge`).

## [1.2.0] - 2026-07-14

### Adicionado

- **Fluxo OAuth completo (Authorization Code + PKCE)** — terceira etapa, que
  fecha a conexão como conector de IA externo (Claude.ai / ChatGPT):
  - `GET|POST /marreira-mcp-oauth/authorize` — tela de **consentimento** do
    administrador, servida na raiz do site (não é rota REST: usa o cookie de
    sessão do WordPress e o login nativo). Exige admin com `manage_options` e
    protege o POST com nonce. Mostra os escopos pedidos; os **sensíveis** só
    aparecem concedíveis se a trava dupla estiver ligada nas configurações.
  - `POST /marreira-mcp-oauth/token` — troca `authorization_code` por access
    token (com verificação **PKCE S256**) e `refresh_token` por um novo par
    (rotação obrigatória). Emite os tokens via `Token_Manager::generate_oauth()`
    (mesma tabela e pipeline HMAC dos tokens estáticos), com validade curta.
  - Authorization codes **single-use**, TTL de 60s, guardados só como hash HMAC,
    com lock otimista contra corrida na troca.
- **Mapa único escopo OAuth ↔ ability** (`OAuth\Scopes`) com a mesma trava dupla
  do CLI geral: escopos perigosos (`exec`, `db_query`, `files`, `plugins`,
  `themes`, `core`, `snippets`, `cli`, `db`) só entram no token se a flag de
  settings correspondente estiver ligada; default seguro `builder read content`.

### Segurança

- Novas chaves mascaradas no audit log: `code`, `code_verifier`, `access_token`,
  `refresh_token`, `registration_access_token`, `client_secret`.
- `redirect_uri` validado por correspondência exata contra os registrados no
  cliente (anti open-redirect); rotação de refresh token; consentimento humano
  obrigatório antes de emitir qualquer code.

## [1.1.0] - 2026-07-14

### Adicionado

- **Discovery OAuth e Dynamic Client Registration** (segunda etapa da conexão
  como conector de IA externo). Servidos na **raiz do site** (fora do
  `/wp-json/`), interceptados no hook `init` por `OAuth\Router`:
  - `GET /.well-known/oauth-protected-resource` (RFC 9728) — aponta o recurso
    protegido (endpoint MCP) e o Authorization Server.
  - `GET /.well-known/oauth-authorization-server` (RFC 8414) — anuncia
    `authorization_endpoint`, `token_endpoint`, `registration_endpoint`,
    `code_challenge_methods_supported: ["S256"]` e os escopos.
  - `POST /marreira-mcp-oauth/register` (RFC 7591) — Dynamic Client Registration.
    Clients nascem `pending` e só operam após aprovação do admin (salvo
    `oauth_auto_approve`); registro protegido por rate limit por IP (5/hora).
  - Preflight CORS (`OPTIONS`) e headers CORS nos endpoints públicos de discovery.
- **Novas tabelas** `mmcb_oauth_clients` e `mmcb_oauth_codes`, e novas colunas em
  `mmcb_tokens` (`source`, `refresh_hash`, `refresh_expires_at`,
  `oauth_client_id`) para os access tokens emitidos via OAuth. Migração
  automática no boot (`DB_VERSION` `1` → `2`).
- **Novas configurações** `enable_oauth` (ligada — expõe o fluxo; a segurança vem
  do consentimento humano) e `oauth_auto_approve` (desligada).

## [1.0.2] - 2026-07-14

### Adicionado

- **Conformidade de transporte para conectores de IA externos (Claude.ai /
  ChatGPT).** Primeira etapa da conexão como servidor MCP remoto de consumidor:
  - **Negociação de `protocolVersion`** no `initialize`: o servidor agora ecoa a
    versão pedida pelo cliente quando suportada (`2025-11-25`, `2025-06-18` ou
    `2025-03-26`), em vez de responder sempre uma versão fixa. Clientes modernos
    (Claude.ai, ChatGPT) exigem esse eco para completar o handshake.
  - **Header `MCP-Protocol-Version`** ecoado nas respostas 2xx do endpoint MCP.
  - **Header `WWW-Authenticate: Bearer resource_metadata="..."`** nas respostas
    `401` do endpoint MCP — é o gatilho que faz o cliente iniciar o discovery
    OAuth (RFC 9728). Aponta para `/.well-known/oauth-protected-resource` (a ser
    servido na próxima etapa).

### Alterado

- **Check de `Origin` (anti DNS rebinding)** só é aplicado quando não há Bearer
  token válido. Um token autenticado (OAuth ou estático) já prova a identidade,
  então a validação de `Origin` — que serve contra ataques de navegador — passa
  a ser ignorada para conexões server-to-server legítimas que enviam `Origin`
  próprio (ex.: `https://claude.ai`). Sem token, o comportamento anterior é
  mantido.
- **Versão preferida do protocolo MCP** atualizada de `2025-03-26` para
  `2025-06-18` (constante `MMCB_MCP_PROTOCOL_VERSION`), usada como resposta
  quando o cliente pede uma versão desconhecida.

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

[1.5.1]: https://github.com/marreiradigital/mrr-mcp-builders/releases/tag/v1.5.1
[1.5.0]: https://github.com/marreiradigital/mrr-mcp-builders/releases/tag/v1.5.0
[1.4.0]: https://github.com/marreiradigital/mrr-mcp-builders/releases/tag/v1.4.0
[1.3.1]: https://github.com/marreiradigital/mrr-mcp-builders/releases/tag/v1.3.1
[1.0.1]: https://github.com/marreiradigital/mrr-mcp-builders/releases/tag/v1.0.1
[1.0.0]: https://github.com/marreiradigital/mrr-mcp-builders/releases/tag/v1.0.0
