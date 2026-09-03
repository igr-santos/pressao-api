# 🚀 Pressão API - Orquestrador Multicanal de Ações de Pressão

[![Python 3.11+](https://img.shields.io/badge/python-3.11+-blue.svg)](https://www.python.org/downloads/)
[![FastAPI](https://img.shields.io/badge/FastAPI-0.104+-green.svg)](https://fastapi.tiangolo.com)
[![PostgreSQL](https://img.shields.io/badge/PostgreSQL-15+-blue.svg)](https://www.postgresql.org/)
[![Docker](https://img.shields.io/badge/Docker-24.0+-blue.svg)](https://www.docker.com/)
[![License: GPL v3](https://img.shields.io/badge/License-GPLv3-blue.svg)](https://www.gnu.org/licenses/gpl-3.0)
[![Code style: black](https://img.shields.io/badge/code%20style-black-000000.svg)](https://github.com/psf/black)

API para orquestração de ações de pressão multicanal, suportando canais automáticos (e-mail, telefone) e manuais (WhatsApp, Instagram) com rastreabilidade imutável e métricas de qualidade.

## 📋 Índice

- [Visão Geral](#🎯-visão-geral)
- [Arquitetura](#️🏗️-arquitetura)
- [Funcionalidades](#✨-funcionalidades)
- [Tecnologias](#️🛠️-tecnologias)
- [Pré-requisitos](#📦-pré-requisitos)
- [Instalação Rápida](#🚀-instalação-rápida)
- [Configuração](#️⚙️-configuração)
- [Endpoints da API](#📡-endpoints-da-api)
- [Fluxo de Trabalho](#🔄-fluxo-de-trabalho)
- [E-mail multi-alvo e alvo agregado](#📬-e-mail-multi-alvo-e-alvo-agregado)
- [Contador de Ações Confirmadas](#contador-de-ações-confirmadas)
- [Testes](#🧪-testes)
- [Monitoramento](#📊-monitoramento)
- [Deploy](#🐳-deploy)
- [CI / GitHub Actions](#🔄-ci--github-actions)
- [Contribuição](#🤝-contribuição)
- [Licença](#📝-licença)
- [Recursos Adicionais](#📚-recursos-adicionais)

## 🎯 Visão Geral

O sistema é um orquestrador de ações de pressão que gerencia múltiplos canais de comunicação:

- **Automáticos (API):** E-mail (SendGrid) e Telefone (Twilio) - disparo imediato com resposta via webhook
- **Manuais (Interação Humana):** WhatsApp e Instagram - geração de link/texto com confirmação manual

Cada ação é registrada de forma imutável e rastreável, garantindo auditoria completa e métricas de qualidade baseadas no tempo de resposta.

## 🏗️ Arquitetura

```text
┌─────────────────────────────────────────────────────────────┐
│                      Frontend (React)                       │
└─────────────────────┬───────────────────────────────────────┘
                      │ HTTPS + JWT
                      ▼
┌─────────────────────────────────────────────────────────────────┐
│                    FastAPI (Backend)                            │
│  ┌────────────────┬─────────────────┬───────────────────────┐   │
│  │  Endpoints     │  Services       │    Repositories       │   │
│  │  - /acoes      │  - Orquestrador │  - AcaoRepository     │   │
│  │  - /alvos      │  - Orquestrador │  - AlvoRepository     │   │
│  │  - /campanhas  │  - Orquestrador │  - CampanhaRepository │   │
│  │  - /status     │  - Canais       │  - Campanha           │   │
│  │  - /confirmar  │  - Metricas     │  - ...                │   │
│  └────────────────┴─────────────────┴───────────────────────┘   │
└─────────┬────────────────┬─────────────────┬────────────────────┘
          │                │                 │
          ▼                ▼                 ▼
┌─────────────────┐ ┌──────────────┐ ┌──────────────────┐
│   PostgreSQL    │ │   Keycloak   │ │   Providers      │
│   (Dados)       │ │   (SSO)      │ │   - SendGrid     │
│                 │ │              │ │   - Twilio       │
└─────────────────┘ └──────────────┘ └──────────────────┘
          │                │                 │
          ▼                ▼                 ▼
┌─────────────────────────────────────────────────────────────┐
│                    Monitoramento                            │
│  ┌──────────────┬──────────────┬───────────────────────┐    │
│  │  Prometheus  │   Grafana    │    Structured Logs    │    │
│  └──────────────┴──────────────┴───────────────────────┘    │
└─────────────────────────────────────────────────────────────┘
```

## ✨ Funcionalidades

### Core

- ✅ Registro Imutável: Cada ação é registrada com timestamp e não pode ser alterada
- ✅ Multi-canal: Suporte a e-mail, telefone, WhatsApp e Instagram
- ✅ **Gestão de Campanhas**: Criação e organização de ações por campanha
- ✅ **Gestão de Alvos**: Cadastro de contatos com validação de tipo (email, telefone, whatsapp, instagram)
- ✅ **E-mail multi-alvo**: Contatos de e-mail agrupados em um alvo agregado na listagem; um clique dispara N envios SendGrid e conta **uma** ação na campanha
- ✅ **Validação de Compatibilidade**: Garantia que o canal da ação é compatível com o tipo de contato do alvo
- ✅ Dual Mode: Execução síncrona (API) e assíncrona (manual)
- ✅ Métricas de Qualidade: Classificação automática da qualidade da ação
- ✅ Rastreabilidade Completa: Histórico completo de cada ação
- ✅ **Suporte a Ações Anônimas**: Permite ações sem identificação do ativista
- ✅ **Contador de Ações Confirmadas**: Total por campanha em leitura O(1) (`acoes_confirmadas`), incrementado na confirmação manual e no webhook SendGrid; reconciliação admin disponível

### Segurança

- 🔐 SSO com Keycloak: Autenticação via JWT com Keycloak
- 🔐 RBAC: Controle de acesso baseado em papéis (admin/ativista)
- 🔐 **Service Account Automática**: Toda service account é automaticamente administradora
- 🔐 **Validação de Permissões**: Ativistas só veem suas próprias ações
- 🔐 **Ações Anônimas**: Suporte a ações sem identificação do ativista (via service account)

#### Tipos de Usuário e Permissões

| Tipo | Autenticação | Permissões |
|------|--------------|------------|
| **Usuário Comum** | Keycloak User | Criar ações, ver suas próprias ações |
| **Administrador** | Keycloak User + role admin | Criar/editar/deletar campanhas, todas as ações |
| **Service Account** | Client Credentials | **Automaticamente admin**, pode criar ações anônimas ou com dados |

#### Service Account (M2M)

Service accounts são ideais para:
- Integrações com sistemas externos
- Scripts de automação
- Testes de carga
- Ações anônimas

**Exemplo de uso:**

```bash
# 1. Obter token da Service Account
TOKEN=$(curl -s -X POST http://localhost:8080/realms/pressao/protocol/openid-connect/token \
  -d "client_id=pressao-api" \
  -d "client_secret=SEU_SECRET" \
  -d "grant_type=client_credentials" | jq -r '.access_token')

# 2. Criar ação anônima
curl -X POST http://localhost:8000/api/v1/acoes/ \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "campanha_id": "...",
    "alvo_id": "...",
    "canal": "whatsapp",
    "anonimo": true
  }'

# 3. Criar ação com dados do ativista
curl -X POST http://localhost:8000/api/v1/acoes/ \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "campanha_id": "550e8400-e29b-41d4-a716-446655440000",
    "alvo_id": "550e8400-e29b-41d4-a716-446655440001",
    "canal": "email",
    "ativista": {
      "nome": "João Silva",
      "email": "joao@email.com"
    }
  }'
```

### Monitoramento

- 📊 Métricas Prometheus: Coleta automática de métricas
- 📊 Grafana: Dashboards pré-configurados
- 📊 Logs Estruturados: Logs em JSON para fácil análise
- 📊 Health Checks: Endpoints de saúde da aplicação

## 🛠️ Tecnologias

### Backend

- Python 3.11+ - Linguagem principal
- FastAPI - Framework web assíncrono
- SQLAlchemy 2.0 - ORM assíncrono
- Alembic - Migrações de banco de dados
- Pydantic - Validação de dados
- UV - Gerenciador de pacotes rápido
- SendGrid - Envio de e-mails de pressão

### Infraestrutura

- PostgreSQL 15+ - Banco de dados principal
- Keycloak - SSO e gerenciamento de identidade
- Docker & Docker Compose - Containerização
- Prometheus - Coleta de métricas
- Grafana - Visualização de métricas

### Qualidade

- Pytest - Framework de testes
- Black - Formatação de código
- Ruff - Linting rápido
- Mypy - Type checking estático

## 📦 Pré-requisitos

- Python 3.11 ou superior
- PostgreSQL 15 ou superior
- Docker e Docker Compose (opcional)
- Git
- Make (opcional, para comandos facilitados)

## 🚀 Instalação Rápida

### Com Docker (Recomendado)

```bash
# Clone o repositório
git clone https://github.com/bonde-org/pressao-api.git
cd pressao-api

# Configure as variáveis de ambiente
cp .env.example .env

# Suba todos os serviços
make docker-up

# Acesse a API
# Swagger: http://localhost:8000/api/docs
# Health: http://localhost:8000/api/health
# Keycloak Admin: http://localhost:8080 (admin/admin123)
# Grafana: http://localhost:3000 (admin/admin)
# Prometheus: http://localhost:9090
```

### Sem Docker (Desenvolvimento)

```bash
# Clone o repositório
git clone https://github.com/bonde-org/pressao-api.git
cd pressao-api

# Configure as variáveis de ambiente
cp .env.example .env

# Setup inicial (cria ambiente virtual e instala dependências)
make setup

# Ative o ambiente virtual (necessário apenas uma vez por sessão)
source .venv/bin/activate

# Rode as migrações do banco de dados
make migrate

# Inicie o servidor em modo desenvolvimento
make dev
```

### Comandos Úteis para Desenvolvimento

```bash
# Ver todos os comandos disponíveis
make help

# Instalar/atualizar dependências (se já tiver venv)
make install

# Executar testes
make test

# Executar testes com cobertura
make test-cov

# Rodar linters (ruff + mypy)
make lint

# Formatar código automaticamente
make format

# Limpar arquivos temporários
make clean

# Limpar tudo (incluindo venv)
make clean-all

# Ver logs dos containers Docker
make docker-logs

# Parar containers Docker
make docker-down
```

## ⚙️ Configuração

### Variáveis de Ambiente

| Variável | Descrição | Exemplo |
|----|----|----|
| `APP_ENV` | Ambiente da aplicação | `development` / `production` |
| `SECRET_KEY` | Chave secreta para JWT | `sua-chave-secreta-aqui` |
| `DATABASE_URL` | URL de conexão PostgreSQL | `postgresql://user:pass@localhost:5432/pressao` |
| `KEYCLOAK_URL` | 	URL do Keycloak | `http://localhost:8080` |
| `KEYCLOAK_REALM` | Realm do Keycloak | `pressao` |
| `KEYCLOAK_CLIENT_ID` | Client ID do Keycloak | `pressao-api` |
| `KEYCLOAK_CLIENT_SECRET` | Client Secret do Keycloak | `seu-secret` |
| `SENDGRID_API_KEY` | API Key do SendGrid | `SG.xxxxx` |
| `SENDGRID_SANDBOX_MODE` | Se `true`, não entrega e-mails reais | `true` |
| `SENDGRID_WEBHOOK_VERIFICATION_KEY` | Chave pública ECDSA do Event Webhook | `MFkwEwYH...` |
| `SENDGRID_WEBHOOK_URL` | URL pública do webhook | `https://seu-dominio/api/v1/webhooks/sendgrid` |
| `TWILIO_ACCOUNT_SID` | SID da conta Twilio | `ACxxxxx` |
| `TWILIO_AUTH_TOKEN` | Token de autenticação Twilio | `xxxxx` |
| `LOG_LEVEL` | Nível de log | `INFO` / `DEBUG` |

### Configuração do Keycloak

**Importante:** Service accounts são automaticamente administradoras na API, dispensando configuração de roles específicas.

1. Acesse o Keycloak em http://localhost:8080
2. Faça login com admin/admin123
3. Crie um Realm chamado pressao
4. Crie um Client chamado pressao-api
5. Configure:
    - Client authentication: ON
    - Service accounts roles: ON
    - Standard flow: OFF (para M2M)
6. Copie o Client Secret para o .env

## 📡 Endpoints da API

### Swagger Documentation

- Documentação Interativa: `http://localhost:8000/api/docs`
- Documentação ReDoc: `http://localhost:8000/api/redoc`

### Endpoints Principais

**Criar Nova Ação**

```http
POST /api/v1/acoes/
Authorization: Bearer {token_jwt}
Content-Type: application/json

{
    "campanha_id": "550e8400-e29b-41d4-a716-446655440000",
    "alvo_id": "550e8400-e29b-41d4-a716-446655440001",
    "canal": "whatsapp",
    "template_id": "550e8400-e29b-41d4-a716-446655440002",
    "anonimo": false,  // Opcional: true para ações anônimas
    "ativista": {      // Opcional: dados do ativista (se não for anônimo)
        "nome": "Maria Silva",
        "email": "maria@email.com"
    }
}
```

**Resposta para WhatsApp (Manual)**

```json
{
    "acao_id": "550e8400-e29b-41d4-a716-446655440003",
    "ativista_id": "user-123",
    "campanha_id": "550e8400-e29b-41d4-a716-446655440000",
    "alvo_id": "550e8400-e29b-41d4-a716-446655440001",
    "status_atual": "AGUARDANDO_ACAO_HUMANA",
    "proximo_passo": {
        "tipo": "REDIRECIONAR_LINK",
        "instrucao": "Clique no link para enviar a mensagem no WhatsApp",
        "dados": {
            "link": "https://wa.me/5511999999999?text=Ol%C3%A1",
            "texto": "Olá, esta é uma mensagem de pressão"
        }
    }
}
```

**Obter Detalhes da Ação**

```http
GET /api/v1/acoes/{acao_id}
Authorization: Bearer {token_jwt}
```

**Webhook SendGrid** (sem JWT; autenticação por assinatura ECDSA)

```http
POST /api/v1/webhooks/sendgrid
Content-Type: application/json
X-Twilio-Email-Event-Webhook-Signature: {assinatura}
X-Twilio-Email-Event-Webhook-Timestamp: {timestamp}

[
  {"event": "delivered", "acao_id": "550e8400-e29b-41d4-a716-446655440003"}
]
```

**Obter Status da Ação**

```http
GET /api/v1/acoes/{acao_id}/status
Authorization: Bearer {token_jwt}
```

**Resposta**

```json
{
    "id": "550e8400-e29b-41d4-a716-446655440003",
    "status": "CONCLUIDA",
    "metrica_qualidade": "alta",
    "confirmado_em": "2026-08-04T14:30:00Z"
}
```

**Confirmar Ação Manual**

```http
PATCH /api/v1/acoes/{acao_id}/confirmar
Authorization: Bearer {token_jwt}
```

Resposta `200 OK`:

```json
{
    "acoes_confirmadas": 47833
}
```

O campo `acoes_confirmadas` é o total atualizado da campanha após o incremento.

**Criar Campanha** (Apenas Admin/Service Account)

```http
POST /api/v1/campanhas/
Authorization: Bearer {token_jwt}
Content-Type: application/json

{
    "nome": "Campanha de Pressão",
    "descricao": "Descrição da campanha",
    "dominios_permitidos": ["gmail.com", "yahoo.com"],
    "ativa": true
}
```

**Listar Campanhas**

```http
GET /api/v1/campanhas/
Authorization: Bearer {token_jwt}
```

Cada campanha inclui `acoes_confirmadas` (total de ações com status `CONCLUIDA`).

**Obter Campanha**

```http
GET /api/v1/campanhas/{campanha_id}
Authorization: Bearer {token_jwt}
```

```json
{
    "id": "550e8400-e29b-41d4-a716-446655440000",
    "nome": "Campanha de Pressão",
    "acoes_confirmadas": 47833,
    "ativa": true
}
```

**Reconciliar Contador** (Apenas Admin/Service Account)

Recalcula o total a partir da tabela `acoes` e corrige drift no campo desnormalizado:

```http
POST /api/v1/campanhas/{campanha_id}/reconciliar-contador
Authorization: Bearer {token_jwt}
```

```json
{
    "antes": 47830,
    "depois": 47833,
    "divergencia": 3
}
```

**Criar Alvo**

Cadastro de um contato na campanha. Para **e-mail**, cada registro é um alvo **individual** (`modo=individual`); o sistema sincroniza automaticamente o agregado da campanha (ver [E-mail multi-alvo](#📬-e-mail-multi-alvo-e-alvo-agregado)).

```http
POST /api/v1/alvos/
Authorization: Bearer {token_jwt}
Content-Type: application/json

{
    "nome": "João Silva",
    "contato": "joao@email.com",
    "tipo_contato": "email",
    "campanha_id": "550e8400-e29b-41d4-a716-446655440000",
    "metadados": {"cargo": "Diretor"}
}
```

**Listar Alvos por Campanha**

Retorna os alvos **para exibição** na campanha:

- **E-mail:** um único item **agregado** (`modo=agregado`, nome padrão "Pressionar por E-mail") com `total_membros`; os e-mails individuais **não** aparecem nesta lista.
- **Outros canais** (WhatsApp, Instagram, telefone): um item por alvo (`modo=individual`).

```http
GET /api/v1/alvos/campanha/{campanha_id}
Authorization: Bearer {token_jwt}
```

Exemplo (campanha com 2 e-mails e 1 WhatsApp):

```json
[
  {
    "id": "uuid-agregado",
    "nome": "Pressionar por E-mail",
    "contato": "agregado.{campanha_id}@pressao.local",
    "tipo_contato": "email",
    "modo": "agregado",
    "total_membros": 2,
    "template": { "id": "...", "titulo": "Assunto sorteado", "canal": "email" }
  },
  {
    "id": "uuid-whatsapp",
    "nome": "Perfil Institucional",
    "contato": "11999999999",
    "tipo_contato": "whatsapp",
    "modo": "individual",
    "total_membros": null
  }
]
```

### Health Check

```http
GET /api/health
```

```json
{
    "status": "ok",
    "environment": "development"
}
```

### Métricas Prometheus

```http
GET /api/metrics
```

## 🔄 Fluxo de Trabalho

### Fluxo Automático (E-mail/Telefone)

**E-mail (padrão — `tipo_acao=multi_alvo`):**

```text
Cadastro de e-mails individuais → Listagem expõe 1 alvo agregado →
POST /acoes no agregado → N disparos SendGrid → CONCLUIDA se ≥1 aceito → +1 acoes_confirmadas
→ Webhooks atualizam disparos (métrica de entrega), não a ação pai
```

**E-mail legado (`tipo_acao=simples`, alvo individual via API direta):**

```text
POST /acoes → 1 envio SendGrid → PROCESSANDO → webhook delivered → CONCLUIDA → +1 acoes_confirmadas
```

**Telefone:**

```text
Ativista → POST /api/acoes → Backend registra → Chama provider →
→ Aguarda webhook → Incrementa acoes_confirmadas → Concluído
```

### Fluxo Manual (WhatsApp/Instagram/TikTok)

```text
Ativista → POST /api/acoes → Backend registra → Gera link/texto →
→ Retorna próximo passo → Ativista executa manualmente →
→ PATCH /confirmar → Incrementa acoes_confirmadas → Calcula métrica → Concluído
```

Para **Instagram** e **TikTok**, cadastre o alvo assim:

- `nome`: nome do perfil a ser comentado
- `contato`: URL da postagem (Instagram) ou do vídeo (TikTok)

O `proximo_passo.dados` devolve `texto`, `perfil` (= `nome`) e `url_postagem` (= `contato`).
O plugin copia o texto e abre a URL da postagem/vídeo.
### Contador de Ações Confirmadas

O total por campanha fica em `campanhas.acoes_confirmadas` (coluna desnormalizada) para leitura rápida — sem `COUNT(*)` em cada request.

| Quando incrementa | Quem dispara |
|-------------------|--------------|
| Confirmação manual | `PATCH /acoes/{id}/confirmar` |
| E-mail entregue (ação `simples`) | Webhook SendGrid `delivered` com `acao_id` |
| E-mail multi-alvo aceito pelo SendGrid | `POST /acoes` no alvo agregado (`tipo_acao=multi_alvo`) |

- Só incrementa na **transição** para `CONCLUIDA` (idempotente em webhooks duplicados).
- A tabela `acoes` continua sendo a fonte da verdade; use `POST /campanhas/{id}/reconciliar-contador` se houver suspeita de drift.
- No plugin WordPress: shortcode `[pressao_contador campaign="uuid"]` (independente de `[pressao_alvos]`), com cache transient de 60s e animação countUp ao confirmar ação na mesma página.

### Matriz de Canais

| Canal | Modo | Resposta | Confirmação | Tempo Esperado |
|-------|------|----------|-------------|----------------|
| E-mail (agregado) | API multi-alvo | `FINALIZADO` | SendGrid aceita ≥1 disparo na criação | Imediato (API) |
| E-mail (individual, legado) | API (SendGrid) | `WEBHOOK_AGUARDAR` | Automática (webhook `acao_id`) | ≤ 5s |
| Telefone | API (Twilio) | `WEBHOOK_AGUARDAR` | Automática (webhook) | ≤ 5s |
| WhatsApp | Manual (Link) | `REDIRECIONAR_LINK` | Manual (PATCH /confirmar) | 5s - 60s |
| Instagram | Manual (Texto + URL postagem) | `EXIBIR_TEXTO_E_ABRIR_PERFIL` | Manual (PATCH /confirmar) | 5s - 60s |
| TikTok | Manual (Texto + URL vídeo) | `EXIBIR_TEXTO_E_ABRIR_PERFIL` | Manual (PATCH /confirmar) | 5s - 60s |

## 📬 E-mail multi-alvo e alvo agregado

Modelo usado pelo plugin WordPress e pela listagem pública de alvos. **Não** é “cada e-mail vira um agregado”: é **um agregado por campanha** que representa **todos** os e-mails individuais cadastrados.

### Duas camadas de alvo

| Camada | `modo` | Visível na listagem? | Uso |
|--------|--------|----------------------|-----|
| Contato cadastrado (`POST /alvos/`, `tipo_contato=email`) | `individual` | Não | Destinatário real de cada disparo |
| “Pressionar por E-mail” (criado automaticamente) | `agregado` | Sim (1 item por campanha) | Botão único na UI; alvo da ação `multi_alvo` |

Vínculo: tabela `alvo_membros` (`agregado_id` → `membro_id`).

```text
Campanha
├── Alvo agregado (1)              ← GET /alvos/campanha/{id}
│     └── alvo_membros
├── Alvo email: joao@...           ← POST /alvos/ (individual)
├── Alvo email: maria@...          ← POST /alvos/ (individual)
└── Alvo whatsapp: @perfil         ← listado individualmente
```

### Adicionar um novo e-mail

1. `POST /api/v1/alvos/` com `tipo_contato: "email"` (alvo individual).
2. A API chama `sincronizar_membros` e inclui o contato no agregado.
3. `GET /alvos/campanha/{id}` passa a mostrar `total_membros` maior no item agregado.
4. O próximo “Pressionar por E-mail” envia para **todos** os membros ativos.

**Remover da pressão:** `PUT /alvos/{id}` com `{"ativo": false}` ou `DELETE /alvos/{id}` — a sincronização atualiza os membros do agregado.

### Ação `tipo_acao=multi_alvo`

Disparada ao criar ação com `canal=email` no alvo **agregado**:

| Aspecto | Comportamento |
|---------|---------------|
| Registros | 1 `acao` pai + N `disparos` (1 por membro) |
| SendGrid | N chamadas; `custom_args` incluem `disparo_id` e `acao_id` |
| Conclusão para o ativista | `CONCLUIDA` se **≥1** disparo aceito pelo SendGrid (HTTP 202) |
| Contador `acoes_confirmadas` | +1 na ação pai ao `CONCLUIDA` |
| Webhook | Atualiza **disparo** (`ENTREGUE` / `FALHA`); **não** altera status do pai nem contador |

Resposta inclui `tipo_acao: "multi_alvo"` e `disparos_resumo` (`total`, `enviados`, `entregues`, `falhas`).

### Ação `tipo_acao=simples` (legado)

Ainda suportada se a ação é criada contra um alvo **individual** de e-mail (não exposto na listagem pública). Um envio, um webhook por `acao_id`, confirmação via `delivered` como antes.

## 📧 Ação de Pressão por E-mail (SendGrid)

O canal `email` dispara mensagens via SendGrid ao criar a ação (`POST /api/v1/acoes/` com `"canal": "email"`).

**Fluxo padrão (alvo agregado):** ver [E-mail multi-alvo](#📬-e-mail-multi-alvo-e-alvo-agregado). Um clique → N e-mails (um `To` por membro).

**Fluxo legado (alvo individual):** um `To` = `alvo.contato`; aguarda webhook `delivered` na ação.

**Papéis no e-mail (por disparo)**

| Campo SMTP | Quem | Origem |
|------------|------|--------|
| Remetente (`From` / `Reply-To`) | Ativista | `ativista.email` e `ativista.nome` (ou claims do JWT) |
| Destinatário (`To`) | Membro individual | `alvo.contato` e `alvo.nome` de cada membro do agregado |

Ação anônima **não** dispara e-mail: o canal exige e-mail do ativista como remetente.

O orquestrador usa `_estrategia_email_multi_alvo` (agregado) ou `_estrategia_email` (individual). Grava `message_id` nos disparos ou em `proximo_passo_dados` da ação.

> O SendGrid só entrega se o domínio do e-mail do ativista estiver autorizado na conta (Single Sender ou Domain Authentication). Sem isso o envio real pode ser rejeitado mesmo com From correto.

### Configuração

1. Crie uma API Key em [SendGrid → Settings → API Keys](https://app.sendgrid.com/settings/api_keys) com permissão de envio.
2. Autentique o domínio (ou remetentes) que os ativistas usarão.
3. Preencha o `.env` (veja `.env.example`):

```bash
SENDGRID_API_KEY=SG.xxxxx
SENDGRID_SANDBOX_MODE=true
SENDGRID_WEBHOOK_VERIFICATION_KEY=
SENDGRID_WEBHOOK_URL=https://seu-dominio/api/v1/webhooks/sendgrid
```

**Sandbox**

| `SENDGRID_SANDBOX_MODE` | API Key | Comportamento |
|-------------------------|---------|---------------|
| `true` | placeholder (`mock-key`, `test-key`) | Dry-run local: **não** chama a API; retorna `sandbox-{uuid}` |
| `true` | chave real (`SG....`) | Chama a API com `sandbox_mode` do SendGrid (não entrega) |
| `false` | chave real | Envio real |

Em desenvolvimento e no Docker o padrão é `SENDGRID_SANDBOX_MODE=true`.

### Como usar (multi-alvo — padrão)

Use o `alvo_id` do item **agregado** retornado por `GET /alvos/campanha/{campanha_id}`:

```bash
TOKEN=$(curl -s -X POST http://localhost:8080/realms/pressao/protocol/openid-connect/token \
  -d "client_id=pressao-api" \
  -d "client_secret=SEU_SECRET" \
  -d "grant_type=client_credentials" | jq -r '.access_token')

# Listar e obter id do agregado
curl -s http://localhost:8000/api/v1/alvos/campanha/{campanha_id} \
  -H "Authorization: Bearer $TOKEN" | jq '.[0].id'

curl -X POST http://localhost:8000/api/v1/acoes/ \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "campanha_id": "550e8400-e29b-41d4-a716-446655440000",
    "alvo_id": "UUID-DO-ALVO-AGREGADO",
    "canal": "email",
    "anonimo": false,
    "ativista": {"nome": "Maria Silva", "email": "maria@email.com"}
  }'
```

Resposta esperada: `tipo_acao: multi_alvo`, `status_atual: CONCLUIDA`, `proximo_passo.tipo: FINALIZADO`, `disparos_resumo` com totais.

### Como usar (ação simples — legado)

Uso direto do serviço (testes ou scripts):

```python
from pressao_api.services.email_service import email_service

resultado = email_service.enviar_pressao(
    destinatario="alvo@orgao.gov.br",
    remetente_email="maria@email.com",
    remetente_nome="Maria Silva",
    assunto="Pressão: Campanha X",
    conteudo_html="<p>Olá {nome}</p>",
    acao_id="550e8400-e29b-41d4-a716-446655440003",
    campanha_id="550e8400-e29b-41d4-a716-446655440000",
    dados_dinamicos={"nome": "Deputado"},
)
# resultado.message_id, resultado.sandbox, resultado.status
```

O HTML padrão é montado por `EmailService.montar_template_pressao` com nome do alvo, campanha e assinatura do ativista.

### Webhook

Endpoint: `POST /api/v1/webhooks/sendgrid` (público; **não** usa JWT).

1. No SendGrid: **Settings → Mail Settings → Event Webhook**.
2. URL: `https://seu-dominio/api/v1/webhooks/sendgrid`.
3. Eventos: pelo menos `delivered`, `bounce`, `dropped`, `spamreport`, `blocked` (opcional: `open`, `click`).
4. Ative **Signed Event Webhook** e copie a chave pública para `SENDGRID_WEBHOOK_VERIFICATION_KEY`.
5. Em production a chave é **obrigatória**. Em development, webhook sem chave é aceito (com warning no log).

O SendGrid devolve `disparo_id` (ações multi-alvo) ou `acao_id` (ação simples) via `custom_args` gravados no envio.

| Evento | Efeito (ação `simples`) | Efeito (disparo multi-alvo) |
|--------|-------------------------|-----------------------------|
| `delivered` | `CONCLUIDA` se `PROCESSANDO` | Disparo → `ENTREGUE` |
| `bounce`, `dropped`, `blocked`, `spamreport` | Ação → `FALHA` | Disparo → `FALHA` |
| `processed`, `open`, `click`, … | log / `ultimo_evento` na ação | log / `ultimo_evento` no disparo |

Eventos com só `acao_id` em ações `multi_alvo` são **ignorados** (use `disparo_id`).

**Teste local com ngrok**

```bash
ngrok http 8000
# No SendGrid, use: https://xxxx.ngrok.io/api/v1/webhooks/sendgrid

# Simular delivered em disparo multi-alvo (development):
curl -X POST http://localhost:8000/api/v1/webhooks/sendgrid \
  -H "Content-Type: application/json" \
  -d '[{"event":"delivered","disparo_id":"UUID-DO-DISPARO"}]'

# Simular delivered em ação simples:
curl -X POST http://localhost:8000/api/v1/webhooks/sendgrid \
  -H "Content-Type: application/json" \
  -d '[{"event":"delivered","acao_id":"UUID-DA-ACAO"}]'
```

### Testes

```bash
# Unidade: envio, sandbox, validação, orquestrador e webhook
uv run python -m pytest tests/unit/test_email_service.py tests/unit/test_webhook_sendgrid.py tests/unit/test_acoes.py -v

# Integração: POST /api/v1/webhooks/sendgrid
uv run python -m pytest tests/integration/test_api_webhook_sendgrid.py -v
```

A API do SendGrid é mockada nos testes; com `SENDGRID_API_KEY=test-key` o sandbox faz dry-run e **não** envia e-mail.

### Métricas de Qualidade

| Tempo de Resposta | Classificação | Significado |
|----|----|----|
| < 5s | **suspeita** | Provavelmente automatizado |
| 5s - 60s | **alta** | Resposta rápida e humana |
| 60s - 120s | **media** | Resposta dentro do esperado |
| > 120s | **baixa** | Resposta lenta |

## 🧪 Testes

### Executar Testes

```bash
# Todos os testes
make test

# Testes com cobertura
make test-cov

# Testes específicos
pytest tests/unit/test_acoes.py -v

# SendGrid (envio, sandbox, webhook)
pytest tests/unit/test_email_service.py tests/unit/test_webhook_sendgrid.py tests/integration/test_api_webhook_sendgrid.py -v
```

### Cobertura de Testes

- ✅ Testes unitários de serviços
- ✅ Testes de integração com API
- ✅ Testes de repositórios
- ✅ Testes de segurança
- ✅ Testes de validação

### Testes de Carga (k6)

Simulam o fluxo do plugin WordPress contra a API (listar alvos → criar ação → confirmar). O plugin em si não é alvo de carga — o gargalo está na API.

**Pré-requisitos:** [k6](https://grafana.com/docs/k6/latest/set-up/install-k6/) (`brew install k6`), `curl`, `jq`, stack local no ar (`make docker-up`).

```bash
# Secret do client M2M no Keycloak (obrigatório)
export KEYCLOAK_CLIENT_SECRET=seu-secret

# 1. Seed: cria campanha, alvos e templates (grava IDs em tests/k6/.env.load-test)
make load-test-seed

# 2. Smoke (~5 VUs, 1,5 min) — validação rápida
make load-test SCENARIO=smoke

# 3. Baseline MVP (~200 VUs, 5 min)
make load-test SCENARIO=load

# Teste maior / stress / spike
make load-test SCENARIO=stress VUS=500 DURATION=10m
make load-test SCENARIO=spike

# Relatório Markdown parcial (colar em docs/04-performance-e-escalabilidade.md)
make load-test-report
```

| Cenário | Objetivo | Default |
|---------|----------|---------|
| `smoke` | Validar setup e auth | 5 VUs, ~1,5 min |
| `load` | Baseline MVP | 200 VUs, 5 min |
| `stress` | Encontrar limite | 500 VUs, 10 min |
| `spike` | Pico súbito | 10→300 VUs |

**Homologação:**

```bash
export BASE_URL=https://sua-api-homolog.example.com
export KEYCLOAK_URL=https://seu-keycloak.example.com
export KEYCLOAK_CLIENT_SECRET=...
make load-test SCENARIO=load
```

**Troubleshooting — 100% de falha / 401:** o k6 obtém um JWT fresco no `setup`. Não reutilize `TOKEN` antigo (o JWT do Keycloak local expira em ~5 min). Se `tests/k6/.env.load-test` tiver uma linha `TOKEN=...`, remova-a e rode de novo com `KEYCLOAK_CLIENT_SECRET` exportado. Detalhes em [`tests/k6/README.md`](tests/k6/README.md) e no documento [`docs/04-performance-e-escalabilidade.md`](../docs/04-performance-e-escalabilidade.md).

## 📊 Monitoramento

### Logs Estruturados

Todos os logs são emitidos em formato JSON para fácil integração com ferramentas de observabilidade:

```json
{
    "timestamp": "2026-08-04T14:30:00Z",
    "level": "info",
    "logger": "app.services.orquestrador",
    "message": "Ação executada com sucesso",
    "acao_id": "550e8400-e29b-41d4-a716-446655440003",
    "canal": "whatsapp",
    "status": "AGUARDANDO_ACAO_HUMANA"
}
```

### Métricas Coletadas

- ✅ Tempo de resposta por endpoint
- ✅ Taxa de sucesso/falha por canal
- ✅ Distribuição de qualidade das ações
- ✅ Tempo médio de confirmação manual
- ✅ Uso de recursos (CPU, memória)

### Endpoints de Monitoramento

- **Health Check:** `/api/health`
- **Métricas:** `/api/metrics` (Prometheus)
- **Logs:** Saída JSON para stdout

## 🐳 Deploy

### Construir Imagem Docker

```bash
make docker-build
```

### Subir em Produção

```bash
# Configure variáveis de produção
export APP_ENV=production
export DATABASE_URL=postgresql://user:pass@prod-db:5432/pressao

# Suba os serviços
docker-compose -f docker/docker-compose.yml up -d
```

### Migrações em Produção

```bash
# Dentro do container
docker exec -it pressao-api-1 alembic upgrade head
```

### Variáveis de Produção Necessárias

- `APP_ENV=production`
- `DEBUG=false`
- `ALLOWED_ORIGINS` = Lista de domínios permitidos
- Certificados SSL/HTTPS configurados
- Variáveis de banco de dados de produção

### Kubernetes (Helm)

Chart em [`helm/`](helm/) (`Chart.yaml`, `values.yaml`, `templates/`). PostgreSQL via CloudNativePG e ServiceMonitor (kube-prometheus-stack) são **opcionais e desligados por padrão**. Guia completo: [CHART.md](helm/CHART.md).

```bash
# Banco externo (padrão)
helm upgrade --install pressao-api ./helm \
  --set secrets.DATABASE_URL='postgresql://user:pass@host:5432/pressao'

# Banco gerenciado pelo CNPG
helm upgrade --install pressao-api ./helm -f helm/values-dev.yaml

# Métricas no Prometheus Operator (path /api/metrics)
helm upgrade --install pressao-api ./helm --set serviceMonitor.enabled=true
```

GitOps: exemplo Argo CD em [`argocd/application.yaml`](argocd/application.yaml) (produção com ServiceMonitor e banco externo).

## 🔄 CI / GitHub Actions

Pipeline em [`.github/workflows/`](.github/workflows/). O orquestrador é [`ci.yml`](.github/workflows/ci.yml): **lint → test → docker-build** (este último só em push para `main` / `develop` / tags `v*`).

```text
push / PR
   │
   ├─ lint.yml   → Ruff + Ruff format check + Mypy
   ├─ test.yml   → pytest + cobertura (Codecov)
   │
   └─ docker-build.yml  (apenas push em main/develop/tags)
         ├─ build + push da imagem no Docker Hub
         │     tags: <branch>, <sha8>, latest
         └─ em main/develop: atualiza helm/values.yaml (image.tag = sha8),
            commit "chore: update image tag … [skip ci]" e push
            → Argo CD detecta a mudança e sincroniza a nova imagem
```

`ci.yml` ignora mudanças só em `helm/**` e `**.md` (`paths-ignore`), e o commit do Helm usa `[skip ci]`, para não entrar em loop com o bump automático da tag.

### Secrets do repositório

Configure em **Settings → Secrets and variables → Actions**:

| Secret | Obrigatório | Usado em | Descrição |
|--------|-------------|----------|-----------|
| `CODECOV_TOKEN` | sim (testes) | `test.yml` | Upload de cobertura no Codecov (`fail_ci_if_error: false`) |
| `DOCKER_HUB_USERNAME` | sim (build) | `docker-build.yml` | Usuário Docker Hub |
| `DOCKER_HUB_TOKEN` | sim (build) | `docker-build.yml` | Access Token Docker Hub (não use a senha da conta) |
| `DOCKER_HUB_REPO` | não | `docker-build.yml` | Repositório da imagem; default `igrsantos/pressao-api` |
| `GIT_TOKEN` | sim (GitOps) | `docker-build.yml` | PAT com permissão de **conteúdo (write)** no repositório, para o bot commitar `helm/values.yaml` |

Sem `GIT_TOKEN` com escopo de escrita, o build/push da imagem pode funcionar, mas o passo que atualiza o Helm falha e o Argo CD não vê tag nova.

### Fluxo Helm → Argo CD

1. Merge/push em `develop` ou `main` (ou tag `v*`) dispara o CI.
2. Após lint e testes, a imagem é publicada (`:<branch>`, `:<sha8>`, `:latest`).
3. Em `main`/`develop`, o workflow faz `sed` em [`helm/values.yaml`](helm/values.yaml) (`image.tag: <sha8>`), commit e push na mesma branch.
4. O Application do Argo CD (ex.: [`argocd/application.yaml`](argocd/application.yaml)) aponta para o chart neste repo; ao detectar o commit, sincroniza o Deployment com a nova tag.

Para validar localmente o que o CI fará na imagem:

```bash
make docker-build
```

Testes de carga (k6) **não** rodam no CI — são manuais (`make load-test`). Ver [Testes de Carga](#testes-de-carga-k6).

## 🤝 Contribuição

### Setup de Desenvolvimento

```bash
# Clone o repo
git clone https://github.com/bonde-org/pressao-api.git
cd pressao-api

# Instale dependências de desenvolvimento
make install

# Configure pre-commit hooks
pre-commit install

# Execute os testes
make test
```

### Padrões de Código

- ✅ Seguir PEP 8
- ✅ Usar Black para formatação (`make format`)
- ✅ Type hints com mypy (`make lint`)
- ✅ Documentação de funções e classes
- ✅ Testes para novas funcionalidades
- ✅ Seguir princípios SOLID

### Fluxo de Contribuição

1. Fork o projeto
2. Crie uma branch para sua feature (git checkout -b feature/nova-funcionalidade)
3. Faça commit das alterações (git commit -m 'Adiciona nova funcionalidade')
4. Push para a branch (git push origin feature/nova-funcionalidade)
5. Abra um Pull Request

## 📝 Licença

Este projeto está licenciado sob a Licença MIT - veja o arquivo [LICENSE](./LICENSE) para detalhes.

## 📚 Recursos Adicionais

- [Documentação FastAPI](https://fastapi.tiangolo.com)
- [Documentação Keycloak](https://www.keycloak.org/documentation)
- [Documentação SQLAlchemy](https://docs.sqlalchemy.org/en/20/)
- [Documentação Docker](https://docs.docker.com)

----

Feito com ❤️ para ativistas e causas sociais