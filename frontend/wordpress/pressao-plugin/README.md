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

### Estrutura do plugin

```text
pressao-plugin/
├── pressao-plugin.php          # Bootstrap (singleton) + enqueue de assets
├── includes/
│   ├── class-main.php          # Funcionalidades gerais
│   ├── class-admin.php         # Página de configurações
│   ├── class-api.php           # Cliente HTTP: Keycloak + API Pressão
│   ├── class-shortcode.php     # Shortcodes e renderização SSR
│   └── class-ajax.php          # AJAX handlers
├── assets/
│   ├── css/
│   │   └── style.css
│   └── js/
│       ├── admin.js            # Campo repetível de candidatos + Media Library
│       └── widget.js           # UI, cookies, ações e confirmações
└── views/
    └── widget-template.php
```

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
| Candidatos | `pressao_candidatos` | Lista de candidatos exibida em `[pressao_candidatos]` |

### Configuração de candidatos

O painel possui uma seção "Configurações de Candidatos" para cadastrar os dados renderizados pelo shortcode `[pressao_candidatos]`.

Campos por candidato:

- `nome`
- `cargo`
- `partido`
- `descricao`
- `link_url`
- `imagem_id`

As imagens são selecionadas pela Biblioteca de Mídia do WordPress. O plugin armazena o `attachment ID` e renderiza com `wp_get_attachment_image()`, sem implementar upload próprio. Assim, se o WordPress passar a enviar mídias para S3 via offload/plugin de storage, o comportamento continua transparente para o Pressão Plugin.

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

Os shortcodes ligados à campanha aceitam `campaign` e caem em `pressao_campaign_id` quando o atributo é omitido. O shortcode `[pressao_candidatos]` é editorial e usa os dados cadastrados no admin do plugin.

### `[pressao_alvos]` — lista de alvos com botão de ação

Principal shortcode do plugin: lista os alvos da campanha e permite agir por canal.

**E-mail:** a API agrupa todos os contatos de e-mail da campanha em um único item (`modo=agregado`, nome padrão "Pressionar por E-mail"). Um clique dispara a ação `multi_alvo` para todos os destinatários. O campo `total_membros` indica quantos e-mails serão pressionados. Use `action_label="Pressionar por E-mail"` para o rótulo do botão.

**Instagram e TikTok:** fluxo manual com mensagem sorteada. No cadastro do alvo, `nome` é o
nome do perfil a comentar e `contato` é a **URL da postagem/vídeo**. O overlay copia o texto e
abre esse link; a confirmação segue via `PATCH /api/v1/acoes/{id}/confirmar`.

```text
[pressao_alvos campaign="uuid" show_ativista_form="yes" show_template="yes" cache="0" action_label="Pressionar por E-mail"]
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

### `[pressao_candidatos]` — bloco de candidatos

Renderiza os candidatos cadastrados no painel do plugin.

```text
[pressao_candidatos title="Conheça os candidatos"]
```

| Atributo | Padrão | Descrição |
|----------|--------|-----------|
| `title` | `Candidatos` | Título do bloco |
| `show_title` | `yes` | Exibe ou oculta o título |
| `class` / `id` | — / gerado | Classe CSS extra e ID do container |

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
| `pressao_acoes_realizadas` | Mapa `alvoId → {timestamp, acao_id, status, user_id}` — fonte de verdade do progresso e do estado SSR |
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
