# Pressão Plugin

Widget WordPress para integração com Keycloak e API Pressão.

## 📋 Sobre

O Pressão Plugin é um widget WordPress que integra sua aplicação com Keycloak para autenticação e consome a API Pressão. Ele faz parte do ecossistema Pressão e está localizado neste repositório como um dos frontends disponíveis.

## 📂 Localização no Repositório

Este plugin está na estrutura:

```text
pressao-api/
└── frontend/
    └── wordpress/
        └── pressao-plugin/ # ← Este plugin
```

## 🏗️ Arquitetura em 3 camadas

O JavaScript **não** chama a FastAPI direto do browser. Todo request passa por três camadas:

```text
widget.js  ──►  admin-ajax.php        ──►  PressaoPlugin_API   ──►  FastAPI
(browser)       (class-ajax.php)           (class-api.php)          (/api/v1/...)
                                            Keycloak M2M
```

O visitante do WordPress não precisa de JWT: o PHP autentica com uma **service account** do Keycloak (Client Credentials) e cacheia o token num transient.

**Consequência prática:** toda operação nova exige as três pontas ao mesmo tempo — função no `widget.js`, handler `wp_ajax_*` + `wp_ajax_nopriv_*` e método em `PressaoPlugin_API`. Ver [Regras de manutenção](#-regras-de-manutenção).

## 🚀 Desenvolvimento

### Pré-requisitos

- Docker e Docker Compose
- Node.js (para assets, opcional)
- PHP 7.4+ (a imagem de desenvolvimento é `wordpress:6.4-php8.2-apache`)

### Setup com Docker

O plugin é desenvolvido dentro do ecossistema Pressão. Para iniciar o ambiente completo:

```bash
# Na pasta docker/ do repositório pressao-api
docker compose up -d

# O WordPress estará disponível em:
# http://localhost:8181
```

O `docker-compose.yml` monta esta pasta como volume em `wp-content/plugins/pressao-plugin`, então alterações em PHP/JS/CSS refletem sem rebuild.

A Media Library grava em um volume Docker (`wordpress_data` → `/var/www/html/wp-content/uploads`). Se o upload falhar por permissão no ambiente local:

```bash
# Correção imediata (já aplicada no entrypoint após rebuild da imagem wordpress)
docker compose exec wordpress chown -R www-data:www-data /var/www/html/wp-content/uploads
docker compose exec wordpress chmod -R ug+rwX /var/www/html/wp-content/uploads
```

Para tornar o ajuste automático no próximo start:

```bash
docker compose build wordpress && docker compose up -d wordpress
```

### Estrutura do plugin

```text
pressao-plugin/
├── pressao-plugin.php          # Bootstrap (singleton) + enqueue de assets
├── includes/
│   ├── class-main.php          # Funcionalidades gerais
│   ├── class-admin.php         # Página de configurações
│   ├── class-candidatos-import.php  # CSV apoiadores + remoção
│   ├── class-api.php           # Cliente HTTP: Keycloak + API Pressão
│   ├── class-shortcode.php     # Shortcodes e renderização SSR
│   └── class-ajax.php          # AJAX handlers
├── assets/
│   ├── css/
│   │   ├── style.css           # Tokens, mask-image dos ícones, @font-face
│   │   └── fluxo.css           # UI do shortcode [pressao_fluxo]
│   ├── fonts/                  # Anton + Host_Grotesk (fluxo); NeueHaas*.woff* opcional p/ alvos
│   │   ├── Anton/
│   │   ├── Host_Grotesk/
│   │   └── Funnel_Display/     # presente; não usada no [pressao_fluxo]
│   ├── icons/                  # SVG de canais, compartilhar, copiar, download, seta e raio (via CSS mask-image)
│   ├── vendor/tom-select/      # Autocomplete do fluxo único (+ remoção no admin)
│   ├── examples/               # CSV de exemplo (apoiadores)
│   └── js/
│       ├── admin.js            # Campos repetíveis, CSV/remoção apoiadores, Media Library
│       ├── widget.js           # UI, cookies, ações, compartilhamento e confirmações ([pressao_alvos])
│       └── fluxo.js            # Wizard sequencial isolado ([pressao_fluxo])
└── views/
    └── widget-template.php
```

Ícones usam `mask-image` (cor via CSS): lista com ícone branco; modal Instagram `#b21e99`, Email `#0068b2`, TikTok `#4d4d4d`. Seta: círculo CSS + mask; só a seta fica branca no hover.

### Ativando o plugin

```bash
# Via WP-CLI
docker compose exec wordpress wp plugin activate pressao-plugin

# Ou pelo admin WordPress
# Plugins > Pressão Plugin > Ativar
```

### Configurando

Acesse Configurações > Pressão Plugin e preencha:

| Campo | Option WP | Descrição |
|-------|-----------|-----------|
| URL do Keycloak | `pressao_keycloak_url` | Endereço do servidor Keycloak |
| Realm | `pressao_realm` | Realm do Keycloak |
| Client ID | `pressao_client_id` | ID do client configurado |
| Client Secret | `pressao_client_secret` | Secret do client |
| URL da API | `pressao_api_url` | Endereço da API Pressão |
| ID da Campanha | `pressao_campaign_id` | Campanha padrão dos shortcodes |
| Título do Widget | `pressao_widget_title` | Título exibido em `[pressao_widget]` |
| Duração da sessão | `pressao_session_duration` | TTL dos cookies em segundos (padrão `86400`) |
| Candidatos a pressionar | `pressao_candidatos` | Busca/seleção do `[pressao_fluxo]` |
| Candidatos apoiadores | `pressao_candidatos_apoiadores` | Botão/lista “já apoiam”, `[pressao_candidatos]`, import CSV |
| Limite de marcação (fluxo) | `pressao_fluxo_limite_candidatos` | Máximo de @ por mensagem no `[pressao_fluxo]` (padrão `5`) |
| Contador antes de abrir IG | `pressao_fluxo_countdown_abrir` | Se ligado: toast com countdown antes de abrir; se desligado (padrão): abre no clique. Mobile tenta o app; desktop abre nova aba |
| Ajuda do fluxo | `pressao_fluxo_ajuda` | Título + conteúdo HTML do modal `?` no `[pressao_fluxo]` |
| Compartilhamento | `pressao_compartilhamento` | Textos, links, deep links e imagens do botão de compartilhar |

### Configuração compartilhada via wp-config.php (multisite)

Os 5 campos de autenticação (`pressao_keycloak_url`, `pressao_realm`, `pressao_client_id`, `pressao_client_secret`, `pressao_api_url`) aceitam uma constante PHP com o mesmo nome em maiúsculas, definida no `wp-config.php`, que sobrescreve a option daquele site:

```php
define('PRESSAO_KEYCLOAK_URL', 'https://auth.bonde.org');
define('PRESSAO_REALM', 'bonde');
define('PRESSAO_CLIENT_ID', 'pressao-api');
define('PRESSAO_CLIENT_SECRET', getenv('PRESSAO_KEYCLOAK_CLIENT_SECRET'));
define('PRESSAO_API_URL', 'https://pressao-api.bonde.cloud');
```

Útil numa rede multisite onde todos os sites compartilham o mesmo client Keycloak/API — evita reescrever (e reexpor) o `client_secret` em cada site. Quando uma constante está definida, o campo correspondente aparece desabilitado na tela de admin, com uma nota indicando a constante. `pressao_campaign_id` e os demais campos continuam por site (não têm override por constante).

### Configuração de candidatos

Há **duas bases** no WordPress:

| Base | Option | Uso |
|------|--------|-----|
| A pressionar | `pressao_candidatos` | Busca/seleção (Tom Select) no `[pressao_fluxo]` |
| Apoiadores | `pressao_candidatos_apoiadores` | Botão “já apoiam”, overlay da lista, shortcode `[pressao_candidatos]` |

Campos por candidato (iguais nas duas):

- `nome`
- `cargo`
- `partido`
- `descricao`
- `link_url` — **Instagram (@)** (handle; aceita `@user` ou URL de perfil; sanitizado no save)
- `imagem_id`

As imagens manuais usam a Biblioteca de Mídia do WordPress (`attachment ID` + `wp_get_attachment_image()`).

No `[pressao_fluxo]`, os handles da base **a pressionar** entram na mensagem (`@a, @b …` + template do alvo). O limite de seleção vem de `pressao_fluxo_limite_candidatos`. Contagens do botão/lista usam a base **apoiadores**.

#### Import CSV (apoiadores)

Na página de configurações, abaixo do formulário principal: upload CSV com upsert **incremental** por `@`:

- Colunas: `nome`, `cargo`, `partido`, `descricao`, `instagram` (ou `link_url`), `imagem_url` (opcional)
- Botão **Baixar CSV de exemplo** ao lado de Importar CSV (`assets/examples/candidatos-apoiadores-exemplo.csv`)
- `@` novo → adiciona; `@` existente → atualiza; ausente no CSV → permanece
- `imagem_url` http(s) → download + sideload em `uploads/…/candidatos/`; falha de imagem não aborta o lote

#### Remover apoiadores

Select com autocomplete (nome/`@`), seleção múltipla e botão **Remover da base**. Não apaga attachments da Media Library.

### Ajuda do fluxo (`?`)

Option `pressao_fluxo_ajuda`:

- `titulo` — título do modal/drawer
- `conteudo` — HTML sanitizado (`wp_kses_post`), editado com o editor do WordPress no admin

O botão `?` em todas as telas do `[pressao_fluxo]` abre esse conteúdo. No topo da tela inicial o badge segue o Figma (“Faça sua parte pelo Instagram!”); `alvo.nome` permanece no config JS para uso futuro.

### Configuração de compartilhamento

A seção "Configurações de Compartilhamento" controla o botão exibido **sempre por último** em `[pressao_alvos]`.

Campos principais da option `pressao_compartilhamento`:

- `ativo` — exibe ou não o botão
- `titulo`, `subtitulo`, `tempo` — textos do item na lista
- `overlay_titulo`, `link`, `mensagem` — overlay principal
- `whatsapp_url` (opcional; se vazio, monta `https://wa.me/?text=` com `mensagem`)
- `instagram_url`, `messenger_url` — deep links completos definidos no admin
- `imagens_titulo`, `imagens_subtitulo`, `imagens_instrucao`
- `imagens[]` — repetível com `imagem_id` (Media Library) + `rotulo`

Não cria ação na API. Ao copiar o link ou abrir WhatsApp/Instagram/Messenger, grava a chave sintética `__compartilhar` no cookie `pressao_acoes_realizadas` para o estado “já realizei”. Essa chave **não** entra no `done/total` de `[pressao_progresso]`. Depois de realizado, a linha mostra só o check verde e continua clicável para reabrir o overlay na mesma sessão.

### Debug

Para ativar o debug, no `wp-config.php`:

```bash
define('WP_DEBUG', true);
define('WP_DEBUG_LOG', true);
```

Ver logs:

```bash
docker compose exec wordpress tail -f /var/www/html/wp-content/debug.log
```

Validar sintaxe PHP sem PHP instalado na máquina:

```bash
docker compose exec wordpress php -l wp-content/plugins/pressao-plugin/includes/class-shortcode.php
```

## Uso

Os shortcodes ligados à campanha aceitam `campaign` e caem em `pressao_campaign_id` quando o atributo é omitido. O shortcode `[pressao_candidatos]` é editorial e usa a base **apoiadores** (`pressao_candidatos_apoiadores`).

### `[pressao_alvos]` — lista de alvos com botão de ação

Principal shortcode do plugin: lista os alvos da campanha e permite agir por canal.

**E-mail:** a API agrupa todos os contatos de e-mail da campanha em um único item (`modo=agregado`, nome padrão "Pressionar por E-mail"). Um clique dispara a ação `multi_alvo` para todos os destinatários. O campo `total_membros` indica quantos e-mails serão pressionados. Use `action_label="Pressionar por E-mail"` para o rótulo do botão.

**Instagram e TikTok:** fluxo manual com mensagem sorteada. No cadastro do alvo, `nome` é o
nome do perfil a comentar e `contato` é a **URL da postagem/vídeo**. O overlay copia o texto e
abre esse link; a confirmação segue via `PATCH /api/v1/acoes/{id}/confirmar`.

**Compartilhamento:** item editorial no fim da lista (configurado no admin). Overlay com copiar link,
deep links WhatsApp/Instagram/Messenger e download de imagens. Sem `POST /acoes`.

```text
[pressao_alvos campaign="uuid" show_ativista_form="yes" show_template="yes" cache="0" action_label="Pressionar por E-mail" ordem="instagram,tiktok,email" tempo_instagram="2 min" tempo_tiktok="2 min" tempo_email="1 min"]
```

| Atributo | Padrão | Descrição |
|----------|--------|-----------|
| `campaign` | option | ID da campanha |
| `limit` | `10` | Máximo de alvos exibidos (`0` = todos) |
| `show_contact` | `yes` | Exibe o contato do alvo |
| `show_actions` | `yes` | Exibe os botões de ação |
| `show_ativista_form` | `no` | Coleta nome/email/telefone antes de agir |
| `action_label` | `Agir` | Rótulo do botão de ação |
| `action_done_label` | `Ação realizada ✓` | Rótulo após a ação |
| `canal` | — | Filtra os alvos por canal |
| `ordem` | — | CSV de canais para ordenar a lista (ex.: `instagram,tiktok,email`). Canais omitidos ficam depois, na ordem da API. Compartilhamento permanece sempre por último |
| `tempo_instagram` | `2 min` | Tempo estimado exibido ao lado do título Instagram |
| `tempo_tiktok` | `2 min` | Tempo estimado exibido ao lado do título TikTok |
| `tempo_email` | `1 min` | Tempo estimado exibido ao lado do título Email |
| `template_id` | — | **Fallback**; normalmente o template vem sorteado da API |
| `show_template` | `no` | `yes` exibe a mensagem sorteada no toggle "Ver mensagem" |
| `cache` | `300` | TTL do transient de alvos. `0` desliga o cache e sorteia um template a cada pageview |
| `class` | — | Classe CSS extra |
| `id` | gerado | ID do container |
| `ativista_confirm_interval` | `10` | Minutos até pedir reconfirmação de identidade |
| `ativista_confirm_message` | `Confirmar identidade` | Texto do overlay de confirmação |
| `ativista_confirm_yes` | `Sou eu` | Rótulo de confirmação |
| `ativista_confirm_no` | `Não sou eu` | Rótulo que limpa os dados da sessão |

### `[pressao_contador]` — total de ações confirmadas

Lê `acoes_confirmadas` da campanha (transient de 60s) e anima o número via countUp quando o ativista conclui uma ação na mesma página.

```text
[pressao_contador campaign="uuid" label="ações confirmadas"]
```

| Atributo | Padrão | Descrição |
|----------|--------|-----------|
| `campaign` | option | ID da campanha |
| `label` | `ações confirmadas` | Texto ao lado do número |
| `class` / `id` | — / gerado | Classe CSS extra e ID do container |

### `[pressao_progresso]` — progresso pessoal do ativista

Barra `done / total` de alvos baseada no cookie `pressao_acoes_realizadas`. Conta ações **realizadas**: canais automáticos entram na hora, manuais só após a confirmação.

```text
[pressao_progresso campaign="uuid" label="seu progresso"]
```

| Atributo | Padrão | Descrição |
|----------|--------|-----------|
| `campaign` | option | ID da campanha |
| `label` | `seu progresso` | Texto da barra |
| `class` / `id` | — / gerado | Classe CSS extra e ID do container |

### `[pressao_candidatos]` — bloco de candidatos apoiadores

Renderiza os candidatos da option `pressao_candidatos_apoiadores` (já apoiam a pauta).

```text
[pressao_candidatos title="Conheça os candidatos"]
```

| Atributo | Padrão | Descrição |
|----------|--------|-----------|
| `title` | `Candidatos` | Título do bloco |
| `show_title` | `yes` | Exibe ou oculta o título |
| `class` / `id` | — / gerado | Classe CSS extra e ID do container |

### `[pressao_fluxo]` — fluxo único sequencial (Instagram v1)

Wizard isolado de `[pressao_alvos]`: seleção de candidatos → copiar/abrir Instagram → confirmação humana → formulário de newsletter → compartilhar. **Cria e confirma a ação na API apenas na saída do formulário** (“Quero receber atualizações” com dados, ou “Agora não” sem ativista). Telas pós-Continuar são bloqueantes (sem dismiss por backdrop/Escape); no **mobile** abrem como **drawer tela cheia** (entra da direita, como o overlay de ação — distinto do bottom sheet da lista de candidatos); no desktop a troca continua inline no card. A lista de candidatos fecha no X ou backdrop.

**Abrir Instagram:** no mobile, “Copiar e abrir” tenta o **app** (Android Intent / iOS Universal Link) **sem nova aba**, para o X/voltar do app devolver à tela de confirmação do fluxo; no desktop abre a URL HTTPS em nova aba. A option `pressao_fluxo_countdown_abrir` (toast antes de abrir) permanece opcional.

```text
[pressao_fluxo alvo_id="uuid-do-alvo" canal="instagram"]
```

| Atributo | Padrão | Descrição |
|----------|--------|-----------|
| `alvo_id` | — (**obrigatório**) | UUID do alvo Instagram na API |
| `canal` | `instagram` | Canal do fluxo; v1 só implementa Instagram |
| `campaign` | option | ID da campanha |
| `template_id` | template do alvo | Fallback se a API não devolver template |
| `title` / `subtitle` | copy do layout | Textos da tela inicial |
| `cache` | `300` | TTL do cache de alvos |
| `class` / `id` | — / gerado | Classe CSS extra e ID do container |

Assets: `fluxo.js` + `fluxo.css` + Tom Select (só quando o shortcode está na página). Reusa AJAX `pressao_realizar_acao` / `pressao_confirmar_acao`.

### `[pressao_widget]` — widget principal

```text
[pressao_widget title="Participe" campaign="uuid"]
```

| Atributo | Padrão | Descrição |
|----------|--------|-----------|
| `title` | option | Título do widget |
| `campaign` | option | ID da campanha |
| `show_campaign_name` | `yes` | Busca e exibe o nome da campanha |
| `cache` | `3600` | TTL do transient da campanha |
| `id` | gerado | ID do container |

### `[pressao_form]` e `[pressao_list]`

Containers básicos, ainda sem lógica de renderização completa. Aceitam `campaign`, `id` e — no caso de `pressao_list` — `limit`; `pressao_form` aceita `button_text`.

## ✉️ Templates sorteados

A API mantém templates de mensagem por campanha e sorteia um deles para cada alvo de e-mail, Instagram e TikTok. O fluxo ponta a ponta:

1. `GET /api/v1/alvos/campanha/{id}` devolve, em cada alvo com `tipo_contato=email`, `instagram` ou `tiktok`, o campo `template` (`id`, `canal`, `titulo`, `conteudo`) **sorteado naquele request**.
2. `render_alvos()` grava esse `template.id` em `data-template-id` **no `<li>` de cada alvo**.
3. `realizarAcao()` no `widget.js` lê o `data-template-id` do item (o do container é fallback) e o envia no AJAX.
4. `PressaoPlugin_API::criar_acao*` repassa como `template_id` no `POST /api/v1/acoes/`.
5. Em e-mail, `titulo` vira o assunto e `conteudo` vira o corpo. Em Instagram/TikTok, `conteudo` vira a mensagem para copiar no fluxo manual.

**Cadastro de templates é feito pela API**, não pelo painel WordPress:

```bash
curl -X POST "$API_URL/api/v1/templates/" \
  -H "Authorization: Bearer $TOKEN" -H "Content-Type: application/json" \
  -d '{"campanha_id":"uuid","canal":"email","titulo":"Assunto do e-mail",
       "conteudo":"<p>Prezado(a) {alvo_nome}, sobre {campanha_nome}...</p>","ativo":true}'
```

Placeholders disponíveis no `conteudo`: `{alvo_nome}`, `{campanha_nome}`, `{ativista_nome}` (vazio em ação anônima) e `{acao_id}`.

**Atenção ao cache:** com `cache` maior que zero o template sorteado fica congelado pelo período do transient. Use `cache="0"` no `[pressao_alvos]` quando a variação por pageview importar. Campanha sem template ativo devolve `template: null` e o fluxo segue com a mensagem padrão da API.

## 🍪 Cookies

Todos usam o TTL de `pressao_session_duration` e são limpos de uma vez por `clearPressaoUserData()` (botão "Não sou eu").

| Cookie | Conteúdo |
|--------|----------|
| `pressao_sessao_id` | UUID v4 da sessão do navegador |
| `pressao_ativista_data` | Nome, email e telefone do ativista (JSON) |
| `pressao_ativista_last_confirm` | Timestamp da última confirmação de identidade |
| `pressao_acoes_realizadas` | Mapa `alvoId → {timestamp, acao_id, status, user_id}` — fonte de verdade do progresso e do estado SSR. Inclui a chave sintética `__compartilhar` quando o ativista compartilha (sem `acao_id`) |
| `pressao_usuario_id` | ID anônimo do usuário (legado) |

O payload de `pressao_acoes_realizadas` é mantido enxuto de propósito: estourar ~4KB derruba os cookies de sessão do WordPress e o AJAX começa a responder 403 "Nonce inválido".

Instagram/TikTok disparam `pressao_realizar_acao` **ao abrir o modal**. Se o nonce embutido na página estiver inválido (page cache aquecido por outro usuário, ou cookie de login WP perdido), o erro aparece na hora. O plugin renova o nonce via `pressao_refresh_nonce` antes do POST e tenta de novo uma vez se ainda falhar.

## 🔌 Handlers AJAX

Todos registrados nas variantes logada e `nopriv`:

| Action | Método em `PressaoPlugin_API` | Endpoint da API |
|--------|-------------------------------|-----------------|
| `pressao_refresh_nonce` | — | Nenhum: devolve `wp_create_nonce('pressao_acao_nonce')` da sessão atual (sem exigir nonce prévio) |
| `pressao_get_campanha` | `get_campanha` | `GET /api/v1/campanhas/{id}` |
| `pressao_realizar_acao` | `criar_acao_com_ativista` / `criar_acao_sem_ativista` | `POST /api/v1/acoes/` |
| `pressao_confirmar_acao` | `confirmar_acao` | `PATCH /api/v1/acoes/{id}/confirmar` |
| `pressao_get_acoes_status` | — | Nenhum: lê o estado do cookie `pressao_acoes_realizadas` |

`pressao_realizar_acao` e `pressao_confirmar_acao` invalidam o transient do contador (`invalidar_cache_contador`) quando recebem `campanha_id` no POST.

## 📏 Regras de manutenção

1. **Atualize este README** a cada alteração no plugin: shortcode ou atributo novo, mudança de default, cookie, handler AJAX ou contrato consumido da API.
2. **Nunca** use `echo`, `print_r`, `var_dump`, `dd()` ou `die()` em handler AJAX ou no cliente da API. O `widget.js` faz `response.json()`; qualquer HTML no meio quebra com `SyntaxError: Unexpected token '<'`. Resposta de AJAX é só `wp_send_json_success` / `wp_send_json_error`.
3. **Três camadas por operação:** função JS → `wp_ajax_*` + `wp_ajax_nopriv_*` → método em `PressaoPlugin_API`. Faltar uma ponta gera botão que não faz nada.
4. **Use os nomes reais dos campos da API** (`acao_id`, `status_atual`, `proximo_passo`), conforme `src/pressao_api/schemas/`. Erros da FastAPI vêm em `detail`, não `message`.
5. **Valide `content-type: application/json`** antes de `response.json()` no JS.
6. Preserve `AGUARDANDO_ACAO_HUMANA` no cookie de ações até a confirmação; só então marque `CONCLUIDA`.
7. Shortcode novo precisa entrar na checagem de `has_shortcode` em `pressao-plugin.php`, senão CSS e JS não são carregados na página.
