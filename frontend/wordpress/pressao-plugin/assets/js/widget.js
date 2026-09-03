/**
 * Pressão Plugin - JavaScript
 * Com carregamento dinâmico da campanha via AJAX
 */

// Constantes
const ACTIONS_STORAGE_KEY = pressaoData?.localStorageKey || 'pressao_acoes_realizadas';
const COOKIE_USER_ID = pressaoData?.cookieUserIdKey || 'pressao_usuario_id';
const COOKIE_ACTIONS = pressaoData?.cookieActionsKey || 'pressao_acoes_realizadas';
const SESSAO_COOKIE = 'pressao_sessao_id';
const ATIVISTA_COOKIE = 'pressao_ativista_data';
const ATIVISTA_CONFIRM_COOKIE = 'pressao_ativista_last_confirm';

// ============================================
// COOKIE HELPERS
// ============================================

function setCookie(name, value, seconds) {
    let expires = '';
    if (seconds && seconds > 0) {
        const date = new Date();
        date.setTime(date.getTime() + (seconds * 1000));
        expires = '; expires=' + date.toUTCString();
    }
    document.cookie = name + '=' + encodeURIComponent(value) + expires + '; path=/; SameSite=Lax';
}

function getCookie(name) {
    const nameEQ = name + '=';
    const ca = document.cookie.split(';');
    for (let i = 0; i < ca.length; i++) {
        let c = ca[i].trim();
        if (c.indexOf(nameEQ) === 0) {
            return decodeURIComponent(c.substring(nameEQ.length));
        }
    }
    return null;
}

function getSessionDuration() {
    return parseInt(pressaoData?.sessionDuration) || 86400;
}

// ============================================
// CONTADOR DE AÇÕES CONFIRMADAS
// ============================================

function formatCountDisplay(n) {
    return n.toLocaleString('pt-BR');
}

function animateCountUp(el, from, to, durationMs) {
    durationMs = durationMs || 800;
    if (from === to) return;
    const start = performance.now();
    function easeOut(t) { return 1 - Math.pow(1 - t, 3); }
    function frame(now) {
        const progress = Math.min((now - start) / durationMs, 1);
        const current = Math.round(from + (to - from) * easeOut(progress));
        el.textContent = formatCountDisplay(current);
        el.dataset.count = String(current);
        if (progress < 1) requestAnimationFrame(frame);
    }
    requestAnimationFrame(frame);
}

function incrementActionCounterByCampaign(campaignId, newValue) {
    if (!campaignId) return;
    document.querySelectorAll('.pressao-acoes-counter[data-campaign="' + campaignId + '"]').forEach(function(counter) {
        const el = counter.querySelector('.pressao-acoes-count');
        if (!el) return;
        const from = parseInt(el.dataset.count || el.textContent.replace(/\D/g, ''), 10) || 0;
        const to = (typeof newValue === 'number') ? newValue : from + 1;
        animateCountUp(el, from, to);
    });
}

// ============================================
// SESSÃO
// ============================================

function getOrCreateSessaoId() {
    let sessaoId = getCookie(SESSAO_COOKIE);
    if (!sessaoId) {
        sessaoId = crypto.randomUUID();
        setCookie(SESSAO_COOKIE, sessaoId, getSessionDuration());
    }
    return sessaoId;
}

// ============================================
// CANAIS E REQUISITOS DE DADOS
// ============================================

const CANAIS_QUE_REQUEREM_ATIVISTA = {
    email: ['email'],
    telefone: ['telefone'],
    whatsapp: ['telefone'],
    instagram: [],
    tiktok: []
};

const CANAIS_COM_OVERLAY = ['email', 'instagram', 'tiktok'];

const CANAL_DISPLAY_NAMES = {
    email: 'Email',
    instagram: 'Instagram',
    tiktok: 'TikTok',
    whatsapp: 'WhatsApp',
    telefone: 'Telefone'
};

function canalRequerAtivista(canal) {
    const campos = CANAIS_QUE_REQUEREM_ATIVISTA[canal];
    return campos && campos.length > 0;
}

function canalUsaOverlay(canal) {
    return CANAIS_COM_OVERLAY.indexOf(canal) !== -1;
}

function canalDisplayName(canal) {
    return CANAL_DISPLAY_NAMES[canal] || canal;
}

// ============================================
// FUNÇÕES DO ATIVISTA
// ============================================

function getAtivistaData() {
    try {
        const data = getCookie(ATIVISTA_COOKIE);
        return data ? JSON.parse(data) : null;
    } catch (e) {
        return null;
    }
}

function saveAtivistaData(ativista) {
    try {
        setCookie(ATIVISTA_COOKIE, JSON.stringify(ativista), getSessionDuration());
        setCookie(ATIVISTA_CONFIRM_COOKIE, String(Date.now()), getSessionDuration());
        return true;
    } catch (e) {
        return false;
    }
}

function deleteCookie(name) {
    document.cookie = name + '=; expires=Thu, 01 Jan 1970 00:00:00 UTC; path=/;';
}

function clearPressaoUserData() {
    deleteCookie(ATIVISTA_COOKIE);
    deleteCookie(ATIVISTA_CONFIRM_COOKIE);
    deleteCookie(SESSAO_COOKIE);
    deleteCookie(COOKIE_USER_ID);
    deleteCookie(COOKIE_ACTIONS);
    try {
        localStorage.removeItem(ACTIONS_STORAGE_KEY);
    } catch (e) { /* ignore */ }
    resetAllProgressoBars();
}

function precisaConfirmarAtivista(intervaloMinutos) {
    const ultimaConfirmacao = getCookie(ATIVISTA_CONFIRM_COOKIE);
    if (!ultimaConfirmacao) {
        return true;
    }
    const agora = Date.now();
    const tempoDecorrido = (agora - parseInt(ultimaConfirmacao)) / (1000 * 60);
    return tempoDecorrido >= intervaloMinutos;
}

// ============================================
// FUNÇÕES DE AÇÕES (COOKIE)
// ============================================

function getAcoesFromStorage() {
    try {
        const cookieData = getCookie(COOKIE_ACTIONS);
        if (cookieData) {
            const parsed = JSON.parse(cookieData);
            if (parsed && typeof parsed === 'object') {
                const slim = slimAcoesPayload(parsed);
                // Regrava se o cookie antigo ainda carregava campos pesados (ex.: ativista)
                if (JSON.stringify(slim) !== JSON.stringify(parsed)) {
                    saveActionsToStorage(slim);
                }
                return slim;
            }
        }
    } catch (e) {
        console.warn('Cookie de ações inválido:', e);
    }

    // Migração one-shot: localStorage legado → cookie (payload enxuto)
    try {
        const legacy = localStorage.getItem(ACTIONS_STORAGE_KEY);
        if (legacy) {
            const parsed = JSON.parse(legacy);
            if (parsed && typeof parsed === 'object') {
                const slim = slimAcoesPayload(parsed);
                saveActionsToStorage(slim);
                localStorage.removeItem(ACTIONS_STORAGE_KEY);
                return slim;
            }
        }
    } catch (e) {
        console.warn('Falha na migração localStorage → cookie:', e);
    }

    return {};
}

/**
 * Mantém só campos necessários no cookie (evita estourar limite ~4KB
 * e derrubar cookies de sessão do WordPress — causa comum de "Nonce inválido").
 */
function slimAcoesPayload(acoes) {
    const slim = {};
    Object.keys(acoes || {}).forEach(function(alvoId) {
        const a = acoes[alvoId];
        if (!a || typeof a !== 'object') {
            return;
        }
        slim[alvoId] = {
            timestamp: a.timestamp || Math.floor(Date.now() / 1000),
            acao_id: a.acao_id || null,
            status: a.status || 'CONCLUIDA',
            user_id: a.user_id || null
        };
    });
    return slim;
}

function saveActionsToStorage(acoes) {
    try {
        const payload = slimAcoesPayload(acoes || {});
        setCookie(COOKIE_ACTIONS, JSON.stringify(payload), getSessionDuration());
        try {
            localStorage.removeItem(ACTIONS_STORAGE_KEY);
        } catch (e) { /* ignore */ }
    } catch (e) {
        console.warn('Não foi possível salvar ações no cookie:', e);
    }
}

function getActionNonce(container) {
    if (pressaoData && pressaoData.nonce) {
        return pressaoData.nonce;
    }
    if (container && container.dataset && container.dataset.nonce) {
        return container.dataset.nonce;
    }
    return '';
}

function setActionNonce(nonce, container) {
    if (!nonce) {
        return;
    }
    if (typeof pressaoData === 'object' && pressaoData) {
        pressaoData.nonce = nonce;
    }
    if (container && container.dataset) {
        container.dataset.nonce = nonce;
    }
    document.querySelectorAll('.pressao-alvos').forEach(function(el) {
        el.dataset.nonce = nonce;
    });
}

/**
 * Busca nonce fresco da sessão atual (não usa o nonce embutido na página).
 * Mitiga page cache e perda do cookie de autenticação do WordPress.
 */
function refreshActionNonce(container) {
    if (!pressaoData || !pressaoData.ajaxUrl) {
        return Promise.resolve(getActionNonce(container));
    }
    const data = {
        action: 'pressao_refresh_nonce'
    };
    return fetch(pressaoData.ajaxUrl, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: new URLSearchParams(data),
        credentials: 'same-origin'
    })
    .then(function(response) {
        return response.json();
    })
    .then(function(response) {
        const nonce = response?.data?.nonce || response?.data?.data?.nonce;
        if (response.success && nonce) {
            setActionNonce(nonce, container);
            return nonce;
        }
        return getActionNonce(container);
    })
    .catch(function() {
        return getActionNonce(container);
    });
}

function isNonceErrorMessage(message) {
    if (!message) {
        return false;
    }
    return String(message).toLowerCase().indexOf('nonce') !== -1;
}

// ============================================
// PROGRESSO DO ATIVISTA
// ============================================

function isAcaoRealizada(acao) {
    if (!acao || typeof acao !== 'object') {
        return false;
    }
    // Pendente de confirmação humana ainda não conta como realizada
    return acao.status !== 'AGUARDANDO_ACAO_HUMANA';
}

function countDoneForProgresso(el, acoes) {
    const total = parseInt(el.dataset.total, 10) || 0;
    const idsRaw = el.dataset.alvoIds || '';
    const alvoIds = idsRaw ? idsRaw.split(',').map(function(id) { return id.trim(); }).filter(Boolean) : [];
    let done = 0;
    alvoIds.forEach(function(id) {
        if (isAcaoRealizada(acoes[id])) {
            done += 1;
        }
    });
    return { done: done, total: total };
}

function applyProgressoToElement(el, done, total) {
    const pct = total > 0 ? Math.min(100, Math.round((done / total) * 100)) : 0;
    const bar = el.querySelector('.pressao-progresso-bar');
    const text = el.querySelector('.pressao-progresso-text');
    const track = el.querySelector('.pressao-progresso-track');
    if (bar) {
        bar.style.width = pct + '%';
    }
    if (text) {
        text.textContent = done + ' de ' + total;
    }
    if (track) {
        track.setAttribute('aria-valuenow', String(done));
        track.setAttribute('aria-valuemax', String(total));
    }
    el.dataset.done = String(done);
}

function updateProgressoElement(el, acoes) {
    const counts = countDoneForProgresso(el, acoes || getAcoesFromStorage());
    applyProgressoToElement(el, counts.done, counts.total);
}

function updateProgressoByCampaign(campaignId) {
    const acoes = getAcoesFromStorage();
    const selector = campaignId
        ? '.pressao-progresso[data-campaign="' + campaignId + '"]'
        : '.pressao-progresso';
    document.querySelectorAll(selector).forEach(function(el) {
        updateProgressoElement(el, acoes);
    });
}

function resetAllProgressoBars() {
    document.querySelectorAll('.pressao-progresso').forEach(function(el) {
        const total = parseInt(el.dataset.total, 10) || 0;
        applyProgressoToElement(el, 0, total);
    });
}

function initProgressoBars() {
    const acoes = getAcoesFromStorage();
    document.querySelectorAll('.pressao-progresso').forEach(function(el) {
        updateProgressoElement(el, acoes);
    });
}

// ============================================
// INICIALIZAÇÃO
// ============================================

document.addEventListener('DOMContentLoaded', function() {
    console.log('🚀 Pressão Plugin carregado!');
    console.log('📊 pressaoData:', pressaoData);
    
    document.querySelectorAll('.pressao-widget-container').forEach(function(widget) {
        initWidget(widget);
    });
    
    document.querySelectorAll('.pressao-form-container').forEach(function(form) {
        initForm(form);
    });
    
    document.querySelectorAll('.pressao-list-container').forEach(function(list) {
        initList(list);
    });

    document.querySelectorAll('.pressao-alvos').forEach(function(container) {
        initAlvos(container);
    });

    initProgressoBars();
});

// ============================================
// WIDGET
// ============================================

function initWidget(widget) {
    const widgetId = widget.dataset.widgetId;
    const campaignId = widget.dataset.campaign;
    const campaignData = widget.dataset.campaignData;
    const content = widget.querySelector('.pressao-widget-content');
    
    console.log(`Widget inicializado: ${widgetId}`, { campaignId });
    
    if (campaignData && campaignData !== '') {
        try {
            const data = JSON.parse(campaignData);
            if (data && data.nome) {
                updateCampaignName(widget, data.nome);
            }
        } catch (e) {
            console.log('Erro ao processar dados da campanha:', e);
        }
    }
    
    if (campaignId && (!campaignData || campaignData === '')) {
        carregarCampanhaAJAX(widget, campaignId);
    }
    
    if (content) {
        content.dataset.loaded = 'false';
    }
}

function carregarCampanhaAJAX(widget, campaignId) {
    const campaignSpan = widget.querySelector('.pressao-campaign-name');
    const separator = widget.querySelector('.pressao-campaign-separator');
    const loadingSpan = document.createElement('span');
    loadingSpan.className = 'pressao-campaign-loading';
    loadingSpan.textContent = 'carregando...';
    
    if (campaignSpan) {
        campaignSpan.style.display = 'none';
        campaignSpan.parentNode.insertBefore(loadingSpan, campaignSpan);
    }
    
    const data = {
        action: 'pressao_get_campanha',
        campanha_id: campaignId,
        nonce: pressaoData ? pressaoData.nonce : ''
    };
    
    fetch(pressaoData.ajaxUrl, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: new URLSearchParams(data)
    })
    .then(response => response.json())
    .then(response => {
        if (loadingSpan.parentNode) {
            loadingSpan.remove();
        }
        if (response.success && response.data && response.data.data) {
            const campanha = response.data.data;
            if (campanha.nome) {
                updateCampaignName(widget, campanha.nome);
            }
        } else {
            if (separator) separator.style.display = 'none';
            if (campaignSpan) campaignSpan.style.display = 'none';
        }
    })
    .catch(function(err) {
        console.error('Erro ao carregar campanha:', err);
        if (loadingSpan.parentNode) {
            loadingSpan.remove();
        }
        if (separator) separator.style.display = 'none';
        if (campaignSpan) campaignSpan.style.display = 'none';
    });
}

function updateCampaignName(widget, nome) {
    const campaignSpan = widget.querySelector('.pressao-campaign-name');
    const separator = widget.querySelector('.pressao-campaign-separator');
    if (campaignSpan) {
        campaignSpan.textContent = nome;
        campaignSpan.style.display = 'inline';
    }
    if (separator) {
        separator.style.display = 'inline';
    }
}

function initForm(form) {
    const formId = form.id;
    const campaign = form.dataset.campaign;
    const content = form.querySelector('.pressao-form-content');
    console.log(`Formulário inicializado: ${formId}`, { campaign });
    if (content) {
        content.dataset.loaded = 'false';
    }
}

function initList(list) {
    const listId = list.id;
    const campaign = list.dataset.campaign;
    const limit = list.dataset.limit;
    const content = list.querySelector('.pressao-list-content');
    console.log(`Lista inicializada: ${listId}`, { campaign, limit });
    if (content) {
        content.dataset.loaded = 'false';
    }
}

// ============================================
// ALVOS
// ============================================

function initAlvos(container) {
    console.log('🚀 initAlvos: inicializando container...');
    
    const campaignId = container.dataset.campaign;
    const nonce = container.dataset.nonce;
    const confirmInterval = parseInt(container.dataset.confirmInterval) || 
                           parseInt(pressaoData?.confirmInterval) || 10;
    
    console.log('📋 Config:', { campaignId, nonce, confirmInterval });

    snapshotAlvosActionsUI(container);
    checkActionsStatus(container);
    bindAlvoActionListeners(container);
    bindTemplateToggleListeners(container);
}

function snapshotAlvosActionsUI(container) {
    container.querySelectorAll('.pressao-alvo-item').forEach(function(item) {
        const actionsDiv = item.querySelector('.pressao-alvo-actions');
        if (actionsDiv && !actionsDiv.dataset.originalHtml) {
            actionsDiv.dataset.originalHtml = actionsDiv.innerHTML;
        }
    });
}

function resetAlvosActionsUI(container) {
    container.querySelectorAll('.pressao-alvo-item').forEach(function(item) {
        item.classList.remove('action-done');
        const actionsDiv = item.querySelector('.pressao-alvo-actions');
        if (actionsDiv && actionsDiv.dataset.originalHtml) {
            actionsDiv.innerHTML = actionsDiv.dataset.originalHtml;
        }
    });
    bindAlvoActionListeners(container);
}

function bindAlvoActionListeners(container) {
    const confirmInterval = parseInt(container.dataset.confirmInterval) ||
                           parseInt(pressaoData?.confirmInterval) || 10;

    container.querySelectorAll('.pressao-alvo-item').forEach(function(item) {
        if (item.dataset.itemBound === 'true') {
            return;
        }
        item.dataset.itemBound = 'true';
        item.addEventListener('click', function(e) {
            if (item.classList.contains('action-done')) {
                return;
            }
            if (e.target.closest('.pressao-ativista-form') ||
                e.target.closest('.pressao-action-submit') ||
                e.target.closest('.pressao-action-confirm')) {
                return;
            }

            const button = item.querySelector('.pressao-action-button, .pressao-action-toggle');
            if (!button) {
                return;
            }

            e.preventDefault();
            handleAlvoItemActivate(container, item, button, confirmInterval);
        });
    });

    const submits = container.querySelectorAll('.pressao-action-submit');
    submits.forEach(function(submit) {
        if (submit.dataset.bound === 'true') {
            return;
        }
        submit.dataset.bound = 'true';
        submit.addEventListener('click', function(e) {
            e.preventDefault();
            e.stopPropagation();

            const alvoId = this.dataset.alvoId;
            const campaignId = this.dataset.campaign || container.dataset.campaign;
            const canal = this.dataset.canal ||
                this.closest('.pressao-ativista-form')?.dataset.canal ||
                this.closest('.pressao-alvo-item')?.dataset.canal ||
                'email';
            const form = this.closest('.pressao-ativista-form');
            const ativistaExistente = getAtivistaData();

            if (ativistaExistente && !precisaConfirmarAtivista(10)) {
                realizarAcao(alvoId, campaignId, container, this, ativistaExistente, canal);
                return;
            }

            if (!form) {
                return;
            }

            const nome = form.querySelector('.pressao-ativista-nome');
            const email = form.querySelector('.pressao-ativista-email');
            const telefone = form.querySelector('.pressao-ativista-telefone');

            if (!nome || !nome.value.trim()) {
                showNotification(container, 'error', 'Por favor, informe seu nome.');
                return;
            }

            const ativista = {
                nome: nome.value.trim(),
                email: email ? email.value.trim() : '',
                telefone: telefone ? telefone.value.trim() : ''
            };

            if (!saveAtivistaData(ativista) || !getCookie(ATIVISTA_COOKIE)) {
                showNotification(container, 'error', 'Erro ao salvar seus dados. Tente novamente.');
                return;
            }

            form.style.display = 'none';
            realizarAcao(alvoId, campaignId, container, this, ativista, canal);
        });
    });
}

function handleAlvoItemActivate(container, item, button, confirmInterval) {
    const alvoId = button.dataset.alvoId || item.dataset.alvoId;
    const campaignId = button.dataset.campaign || container.dataset.campaign;
    const canal = button.dataset.canal || item.dataset.canal || 'email';

    if (canalUsaOverlay(canal)) {
        abrirOverlayAcao(container, item, canal);
        return;
    }

    if (button.classList.contains('pressao-action-button')) {
        realizarAcao(alvoId, campaignId, container, button, getAtivistaData(), canal);
        return;
    }

    // .pressao-action-toggle (whatsapp/telefone com formulário inline)
    const ativista = getAtivistaData();

    if (!canalRequerAtivista(canal)) {
        realizarAcao(alvoId, campaignId, container, button, null, canal);
        return;
    }

    if (!ativista) {
        const form = item.querySelector('.pressao-ativista-form');
        if (form) {
            form.style.display = 'block';
            form.dataset.canal = canal;
        }
    } else if (precisaConfirmarAtivista(confirmInterval)) {
        mostrarConfirmacaoAtivista(container, ativista, function() {
            const submit = item.querySelector('.pressao-action-submit');
            if (submit) {
                submit.click();
            }
        }, button);
    } else {
        const submit = item.querySelector('.pressao-action-submit');
        if (submit) {
            submit.click();
        } else {
            realizarAcao(alvoId, campaignId, container, button, ativista, canal);
        }
    }
}

function bindTemplateToggleListeners(container) {
    container.querySelectorAll('.pressao-template-toggle').forEach(function(button) {
        if (button.dataset.bound === 'true') {
            return;
        }
        button.dataset.bound = 'true';
        button.addEventListener('click', function(e) {
            e.preventDefault();

            const wrapper = this.closest('.pressao-alvo-template, .pressao-email-template');
            const body = wrapper?.querySelector('.pressao-alvo-template-corpo');
            if (!wrapper || !body) {
                return;
            }

            const isOpen = wrapper.dataset.templateOpen === 'true';
            wrapper.dataset.templateOpen = isOpen ? 'false' : 'true';
            body.hidden = isOpen;
            this.textContent = isOpen ? 'Ver mensagem' : 'Ocultar mensagem';
        });
    });
}

// ============================================
// OVERLAY DE AÇÃO (modal desktop / drawer mobile)
// ============================================

let pressaoOverlayState = null;

function getOrCreateActionOverlay() {
    let overlay = document.getElementById('pressao-action-overlay');
    if (overlay) {
        return overlay;
    }

    overlay = document.createElement('div');
    overlay.id = 'pressao-action-overlay';
    overlay.className = 'pressao-action-overlay';
    overlay.hidden = true;
    overlay.innerHTML = `
        <div class="pressao-action-overlay-backdrop" data-pressao-overlay-close="1"></div>
        <div class="pressao-action-panel" role="dialog" aria-modal="true">
            <div class="pressao-action-panel-header">
                <button type="button" class="pressao-action-back" aria-label="Voltar">←</button>
                <div class="pressao-action-panel-channel"></div>
            </div>
            <div class="pressao-action-panel-body"></div>
        </div>
    `;
    document.body.appendChild(overlay);

    overlay.querySelector('.pressao-action-back').addEventListener('click', function() {
        fecharOverlayAcao();
    });
    overlay.querySelector('[data-pressao-overlay-close]').addEventListener('click', function() {
        if (window.matchMedia('(min-width: 768px)').matches) {
            fecharOverlayAcao();
        }
    });

    return overlay;
}

function fecharOverlayAcao() {
    const overlay = document.getElementById('pressao-action-overlay');
    if (overlay) {
        overlay.hidden = true;
        overlay.querySelector('.pressao-action-panel-body').innerHTML = '';
    }
    pressaoOverlayState = null;
    document.body.style.overflow = '';
}

function abrirOverlayAcao(container, item, canal) {
    if (!item) {
        return;
    }
    if (canal === 'email') {
        abrirOverlayEmail(container, item);
        return;
    }
    if (canal === 'instagram' || canal === 'tiktok') {
        abrirOverlaySocial(container, item, canal);
    }
}

function renderOverlayShell(canal) {
    const overlay = getOrCreateActionOverlay();
    const channelEl = overlay.querySelector('.pressao-action-panel-channel');
    channelEl.innerHTML = `
        <span class="pressao-alvo-canal-badge" data-canal="${escapeAttribute(canal)}"></span>
        <span>${escapeHtml(canalDisplayName(canal))}</span>
    `;
    overlay.hidden = false;
    document.body.style.overflow = 'hidden';
    return overlay;
}

function abrirOverlaySocial(container, item, canal) {
    const overlay = renderOverlayShell(canal);
    const body = overlay.querySelector('.pressao-action-panel-body');
    const alvoId = item.dataset.alvoId;
    const campaignId = container.dataset.campaign;
    const alvoNome = item.dataset.alvoNome || '';
    const canalLabel = canalDisplayName(canal);
    const templateTexto = item.dataset.templateConteudo || '';
    const pendingAcaoId = item.dataset.acaoId || '';
    const pendingUrl = item.dataset.perfilUrl || item.dataset.contato || '';

    body.innerHTML = `
        <h2 class="pressao-action-panel-title">Comente e fortaleça a ${escapeHtml(alvoNome)}</h2>
        <p class="pressao-action-panel-subtitle">Quanto mais gente comenta e barulho fazemos, mais visibilidade a pauta ganha.</p>
        <label class="pressao-action-field-label" for="pressao-social-message">Mensagem</label>
        <textarea id="pressao-social-message" class="pressao-action-message-textarea" readonly></textarea>
        <button type="button" class="pressao-copy-open-btn" disabled>
            Copiar e abrir no ${escapeHtml(canalLabel)}
        </button>
        <div class="pressao-confirm-section">
            <p class="pressao-confirm-section-title">Já comentou?</p>
            <p class="pressao-confirm-section-text">Depois de publicar, volte aqui para seguir para a próxima ação.</p>
            <button type="button" class="pressao-confirm-continue" disabled>Já comentei, continuar!</button>
        </div>
        <p class="pressao-overlay-error" hidden></p>
    `;

    const textarea = body.querySelector('.pressao-action-message-textarea');
    const copyBtn = body.querySelector('.pressao-copy-open-btn');
    const confirmBtn = body.querySelector('.pressao-confirm-continue');
    const errorEl = body.querySelector('.pressao-overlay-error');

    textarea.value = templateTexto;

    pressaoOverlayState = {
        tipo: 'social',
        container: container,
        item: item,
        alvoId: alvoId,
        campaignId: campaignId,
        canal: canal,
        acaoId: pendingAcaoId || null,
        perfilUrl: pendingUrl || '',
        texto: templateTexto
    };

    copyBtn.addEventListener('click', function() {
        const texto = pressaoOverlayState?.texto || textarea.value || '';
        const url = pressaoOverlayState?.perfilUrl || item.dataset.contato || '';
        if (!texto) {
            return;
        }
        navigator.clipboard.writeText(texto)
            .then(function() {
                if (url) {
                    window.open(url, '_blank', 'noopener,noreferrer');
                }
                const original = copyBtn.textContent;
                copyBtn.textContent = 'Mensagem copiada';
                setTimeout(function() {
                    copyBtn.textContent = original;
                }, 2000);
            })
            .catch(function() {
                errorEl.hidden = false;
                errorEl.textContent = 'Não foi possível copiar a mensagem.';
            });
    });

    confirmBtn.addEventListener('click', function() {
        if (!pressaoOverlayState?.acaoId) {
            errorEl.hidden = false;
            errorEl.textContent = 'Aguarde o carregamento da ação.';
            return;
        }
        confirmBtn.disabled = true;
        confirmarAcao(
            pressaoOverlayState.alvoId,
            pressaoOverlayState.acaoId,
            pressaoOverlayState.campaignId,
            pressaoOverlayState.container,
            confirmBtn,
            { fromOverlay: true }
        );
    });

    if (pendingAcaoId) {
        copyBtn.disabled = !templateTexto;
        confirmBtn.disabled = false;
        return;
    }

    // Copia/abre já usa contato (URL da postagem) sem esperar a API
    if (templateTexto && (pendingUrl || item.dataset.contato)) {
        copyBtn.disabled = false;
    }

    iniciarAcaoSocialNoOverlay(container, item, canal, {
        textarea: textarea,
        copyBtn: copyBtn,
        confirmBtn: confirmBtn,
        errorEl: errorEl
    });
}

function iniciarAcaoSocialNoOverlay(container, item, canal, refs) {
    const alvoId = item.dataset.alvoId;
    const campaignId = container.dataset.campaign;

    realizarAcao(alvoId, campaignId, container, null, null, canal, {
        silent: true,
        onSuccess: function(response) {
            const apiData = response.data?.data || {};
            const acaoId = response.data?.acao_id || apiData.acao_id || null;
            const status = response.data?.status || apiData.status_atual || 'CONCLUIDA';
            const proximoPasso = apiData.proximo_passo || {};
            const dados = proximoPasso.dados || {};
            const texto = dados.texto || item.dataset.templateConteudo || '';
            const url = item.dataset.contato || dados.url_postagem || dados.url_perfil || dados.link || '';

            item.dataset.acaoId = acaoId || '';
            item.dataset.perfilUrl = url;

            if (pressaoOverlayState && pressaoOverlayState.alvoId === alvoId) {
                pressaoOverlayState.acaoId = acaoId;
                pressaoOverlayState.perfilUrl = url;
                pressaoOverlayState.texto = texto;
            }

            if (refs.textarea && texto) {
                refs.textarea.value = texto;
            }
            if (refs.copyBtn) {
                refs.copyBtn.disabled = !texto;
            }
            if (refs.confirmBtn) {
                refs.confirmBtn.disabled = !acaoId || status !== 'AGUARDANDO_ACAO_HUMANA';
            }

            if (status !== 'AGUARDANDO_ACAO_HUMANA' && acaoId) {
                // Já concluída (caso raro em canal social)
                const acoes = getAcoesFromStorage();
                marcarAcaoRealizada(item, acoes[alvoId]);
                fecharOverlayAcao();
            }
        },
        onError: function(message) {
            if (refs.errorEl) {
                refs.errorEl.hidden = false;
                refs.errorEl.textContent = message || 'Erro ao iniciar a ação.';
            }
        }
    });
}

function abrirOverlayEmail(container, item) {
    const overlay = renderOverlayShell('email');
    const body = overlay.querySelector('.pressao-action-panel-body');
    const alvoId = item.dataset.alvoId;
    const campaignId = container.dataset.campaign;
    const templateTitulo = item.dataset.templateTitulo || '';
    const templateConteudo = item.dataset.templateConteudo || '';
    const ativista = getAtivistaData() || {};

    const templateBlock = (templateTitulo || templateConteudo)
        ? `
            <div class="pressao-email-template pressao-alvo-template" data-template-open="false">
                <button type="button" class="pressao-template-toggle">Ver mensagem</button>
                <div class="pressao-alvo-template-corpo" hidden>
                    ${templateTitulo ? `<strong>${escapeHtml(templateTitulo)}</strong><br>` : ''}
                    ${escapeHtml(templateConteudo)}
                </div>
            </div>
        `
        : '';

    body.innerHTML = `
        <h2 class="pressao-action-panel-title">Envie diretamente para os alvos</h2>
        <p class="pressao-action-panel-subtitle">Preencha as informações para confirmar o envio.</p>
        ${templateBlock}
        <form class="pressao-overlay-form">
            <div class="pressao-form-group">
                <label for="pressao-overlay-nome">Nome *</label>
                <input type="text" id="pressao-overlay-nome" class="pressao-ativista-nome" required
                       value="${escapeAttribute(ativista.nome || '')}" placeholder="Seu nome" />
            </div>
            <div class="pressao-form-group">
                <label for="pressao-overlay-email">Email *</label>
                <input type="email" id="pressao-overlay-email" class="pressao-ativista-email" required
                       value="${escapeAttribute(ativista.email || '')}" placeholder="Seu email" />
            </div>
            <p class="pressao-overlay-error" hidden></p>
            <button type="submit" class="pressao-overlay-submit">Confirmar</button>
        </form>
    `;

    bindTemplateToggleListeners(body);

    const form = body.querySelector('.pressao-overlay-form');
    const errorEl = body.querySelector('.pressao-overlay-error');
    const submitBtn = body.querySelector('.pressao-overlay-submit');

    pressaoOverlayState = {
        tipo: 'email',
        container: container,
        item: item,
        alvoId: alvoId,
        campaignId: campaignId,
        canal: 'email'
    };

    form.addEventListener('submit', function(e) {
        e.preventDefault();
        const nome = form.querySelector('.pressao-ativista-nome');
        const email = form.querySelector('.pressao-ativista-email');

        if (!nome.value.trim()) {
            errorEl.hidden = false;
            errorEl.textContent = 'Por favor, informe seu nome.';
            return;
        }
        if (!email.value.trim()) {
            errorEl.hidden = false;
            errorEl.textContent = 'Por favor, informe seu email.';
            return;
        }

        const ativistaData = {
            nome: nome.value.trim(),
            email: email.value.trim(),
            telefone: (getAtivistaData() || {}).telefone || ''
        };
        saveAtivistaData(ativistaData);

        submitBtn.disabled = true;
        submitBtn.textContent = 'Enviando...';
        errorEl.hidden = true;

        realizarAcao(alvoId, campaignId, container, submitBtn, ativistaData, 'email', {
            silent: true,
            onSuccess: function() {
                const acoes = getAcoesFromStorage();
                marcarAcaoRealizada(item, acoes[alvoId]);
                fecharOverlayAcao();
                showNotification(container, 'success', 'Ação realizada!');
            },
            onError: function(message) {
                submitBtn.disabled = false;
                submitBtn.textContent = 'Confirmar';
                errorEl.hidden = false;
                errorEl.textContent = message || 'Erro ao realizar ação.';
            }
        });
    });
}

// ============================================
// MODAL DE CONFIRMAÇÃO (APENAS NOME - LGPD)
// ============================================

function mostrarConfirmacaoAtivista(container, ativista, callback, contextElement) {
    const message = container.dataset.confirmMessage ||
                   pressaoData?.ativistaConfirmMessage ||
                   'Confirmar identidade';
    const yesText = container.dataset.confirmYes ||
                   pressaoData?.ativistaConfirmYes ||
                   'Sou eu';
    const noText = container.dataset.confirmNo ||
                  pressaoData?.ativistaConfirmNo ||
                  'Não sou eu';

    const overlay = document.createElement('div');
    overlay.className = 'pressao-modal-overlay pressao-modal-confirm';
    overlay.id = 'pressao-modal-confirm';

    overlay.innerHTML = `
        <div class="pressao-modal pressao-modal-small">
            <div class="pressao-modal-header">
                <h3>${message}</h3>
            </div>
            <div class="pressao-modal-body">
                <div class="pressao-ativista-info">
                    <p><strong>${escapeHtml(ativista.nome)}</strong></p>
                </div>
                <div class="pressao-modal-actions">
                    <button class="pressao-modal-btn pressao-modal-btn-no" id="pressao-confirm-no">
                        ${noText}
                    </button>
                    <button class="pressao-modal-btn pressao-modal-btn-yes" id="pressao-confirm-yes">
                        ${yesText}
                    </button>
                </div>
            </div>
        </div>
    `;

    document.body.appendChild(overlay);

    overlay.addEventListener('click', function(e) {
        if (e.target === this) {
            overlay.remove();
        }
    });

    overlay.querySelector('#pressao-confirm-no').addEventListener('click', function() {
        const alvoId = contextElement?.dataset.alvoId ||
                       contextElement?.closest('.pressao-alvo-item')?.dataset.alvoId;
        clearPressaoUserData();
        resetAlvosActionsUI(container);
        overlay.remove();
        if (alvoId) {
            const item = container.querySelector(`.pressao-alvo-item[data-alvo-id="${alvoId}"]`);
            const form = item?.querySelector('.pressao-ativista-form');
            if (form) {
                form.style.display = 'block';
            }
        }
    });

    overlay.querySelector('#pressao-confirm-yes').addEventListener('click', function() {
        setCookie(ATIVISTA_CONFIRM_COOKIE, String(Date.now()), getSessionDuration());
        overlay.remove();
        if (callback && typeof callback === 'function') {
            callback();
        }
    });
}

// ============================================
// AÇÃO PRINCIPAL
// ============================================

function realizarAcao(alvoId, campaignId, container, button, ativista, canal, options) {
    options = options || {};

    if (!ativista) {
        ativista = getAtivistaData();
    }

    if (!canal) {
        const item = button?.closest?.('.pressao-alvo-item');
        canal = item?.dataset?.canal || 'email';
    }

    const originalText = button ? button.textContent : '';
    const alvoItem = button?.closest?.('.pressao-alvo-item') ||
        container.querySelector(`.pressao-alvo-item[data-alvo-id="${alvoId}"]`);
    const templateId = alvoItem?.dataset?.templateId || container.dataset.templateId || '';

    if (button && !options.silent) {
        button.disabled = true;
        button.textContent = 'Processando...';
    }

    function buildPayload(nonce) {
        return {
            action: 'pressao_realizar_acao',
            alvo_id: alvoId,
            campanha_id: campaignId,
            canal: canal,
            template_id: templateId,
            nonce: nonce,
            sessao_id: getOrCreateSessaoId(),
            ativista_nome: ativista?.nome || '',
            ativista_email: ativista?.email || '',
            ativista_telefone: ativista?.telefone || ''
        };
    }

    function postAcao(nonce) {
        return fetch(pressaoData.ajaxUrl, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            credentials: 'same-origin',
            body: new URLSearchParams(buildPayload(nonce))
        })
        .then(function(response) {
            const contentType = response.headers.get('content-type') || '';
            if (!contentType.includes('application/json')) {
                return response.text().then(function() {
                    throw new Error('Resposta inválida do servidor (esperado JSON).');
                });
            }
            return response.json();
        });
    }

    function fail(message) {
        if (button && !options.silent) {
            button.disabled = false;
            button.textContent = originalText;
        }
        if (typeof options.onError === 'function') {
            options.onError(message);
        } else {
            showNotification(container, 'error', message);
        }
    }

    function handleSuccess(response) {
        const acoes = getAcoesFromStorage();
        const apiData = response.data?.data || {};
        const acaoData = {
            timestamp: response.data?.timestamp || Math.floor(Date.now() / 1000),
            user_id: response.data?.user_id || null,
            acao_id: response.data?.acao_id || apiData.acao_id || null,
            status: response.data?.status || apiData.status_atual || 'CONCLUIDA',
        };
        acoes[alvoId] = acaoData;
        saveActionsToStorage(acoes);
        updateProgressoByCampaign(campaignId);

        if (typeof options.onSuccess === 'function') {
            options.onSuccess(response);
            return;
        }

        if (acaoData.status === 'AGUARDANDO_ACAO_HUMANA') {
            const item = alvoItem;
            if (item) {
                item.dataset.acaoId = acaoData.acao_id || '';
                const dados = (apiData.proximo_passo || {}).dados || {};
                const urlPostagem = item.dataset.contato ||
                    dados.url_postagem || dados.url_perfil || dados.link || '';
                if (urlPostagem) {
                    item.dataset.perfilUrl = urlPostagem;
                }
                if (dados.texto) {
                    item.dataset.templateConteudo = dados.texto;
                }
            }
            if (button && !options.silent) {
                button.disabled = false;
                button.textContent = originalText;
            }
        } else {
            const item = alvoItem;
            if (item) {
                marcarAcaoRealizada(item, acoes[alvoId]);
            }
            showNotification(container, 'success', response.data?.message || 'Ação realizada!');
            incrementActionCounterByCampaign(campaignId, null);

            const form = button?.closest?.('.pressao-alvo-actions')?.querySelector('.pressao-ativista-form');
            if (form) {
                form.style.display = 'none';
            }
        }
    }

    // Renova nonce antes do POST — Instagram/TikTok disparam no open do modal
    // e falham se a página veio de cache ou a sessão WP mudou.
    refreshActionNonce(container)
        .then(function(nonce) {
            return postAcao(nonce).then(function(response) {
                if (response.success) {
                    return response;
                }
                const message = response.data?.message || 'Erro ao realizar ação';
                if (!options._nonceRetried && isNonceErrorMessage(message)) {
                    options._nonceRetried = true;
                    return refreshActionNonce(container).then(function(freshNonce) {
                        return postAcao(freshNonce);
                    });
                }
                return response;
            });
        })
        .then(function(response) {
            if (response.success) {
                handleSuccess(response);
                return;
            }
            fail(response.data?.message || 'Erro ao realizar ação');
        })
        .catch(function(error) {
            console.error('Erro:', error);
            fail('Erro ao realizar ação. Tente novamente.');
        });
}

// ============================================
// CONFIRMAÇÃO DE AÇÃO MANUAL
// ============================================

function confirmarAcao(alvoId, acaoId, campaignId, container, button, options) {
    options = options || {};

    if (!acaoId) {
        showNotification(container, 'error', 'ID da ação não encontrado. Tente agir novamente.');
        return;
    }

    const originalText = button ? button.textContent : '';

    if (button) {
        button.disabled = true;
        button.textContent = 'Confirmando...';
    }

    function buildPayload(nonce) {
        return {
            action: 'pressao_confirmar_acao',
            acao_id: acaoId,
            alvo_id: alvoId,
            campanha_id: campaignId || '',
            nonce: nonce
        };
    }

    function postConfirm(nonce) {
        return fetch(pressaoData.ajaxUrl, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            credentials: 'same-origin',
            body: new URLSearchParams(buildPayload(nonce))
        })
        .then(function(response) {
            const contentType = response.headers.get('content-type') || '';
            if (!contentType.includes('application/json')) {
                return response.text().then(function() {
                    throw new Error('Resposta inválida do servidor (esperado JSON).');
                });
            }
            return response.json();
        });
    }

    function fail(message) {
        if (button) {
            button.disabled = false;
            button.textContent = originalText;
        }
        showNotification(container, 'error', message);
    }

    refreshActionNonce(container)
        .then(function(nonce) {
            return postConfirm(nonce).then(function(response) {
                if (response.success) {
                    return response;
                }
                const message = response.data?.message || 'Erro ao confirmar ação';
                if (!options._nonceRetried && isNonceErrorMessage(message)) {
                    options._nonceRetried = true;
                    return refreshActionNonce(container).then(function(freshNonce) {
                        return postConfirm(freshNonce);
                    });
                }
                return response;
            });
        })
        .then(function(response) {
            if (!response.success) {
                fail(response.data?.message || 'Erro ao confirmar ação');
                return;
            }

            const acoes = getAcoesFromStorage();
            acoes[alvoId] = {
                ...(acoes[alvoId] || {}),
                timestamp: response.data?.timestamp || Math.floor(Date.now() / 1000),
                acao_id: acaoId,
                status: response.data?.status || 'CONCLUIDA'
            };
            saveActionsToStorage(acoes);
            updateProgressoByCampaign(campaignId || container.dataset.campaign);

            const item = options.fromOverlay
                ? pressaoOverlayState?.item || container.querySelector(`.pressao-alvo-item[data-alvo-id="${alvoId}"]`)
                : button?.closest?.('.pressao-alvo-item') ||
                  container.querySelector(`.pressao-alvo-item[data-alvo-id="${alvoId}"]`);

            if (item) {
                delete item.dataset.acaoId;
                marcarAcaoRealizada(item, acoes[alvoId]);
            }

            if (options.fromOverlay) {
                fecharOverlayAcao();
            }

            showNotification(container, 'success', response.data?.message || 'Ação confirmada!');

            const campanhaAtual = campaignId || container.dataset.campaign;
            if (response.data && response.data.acoes_confirmadas != null) {
                incrementActionCounterByCampaign(campanhaAtual, response.data.acoes_confirmadas);
            } else {
                incrementActionCounterByCampaign(campanhaAtual, null);
            }
        })
        .catch(function(error) {
            console.error('Erro ao confirmar ação:', error);
            fail('Erro ao confirmar ação. Tente novamente.');
        });
}

// ============================================
// FUNÇÕES AUXILIARES
// ============================================

function checkActionsStatus(container) {
    const alvoItems = container.querySelectorAll('.pressao-alvo-item');
    const acoes = getAcoesFromStorage();
    alvoItems.forEach(function(item) {
        const alvoId = item.dataset.alvoId;
        const acao = acoes[alvoId];
        if (!acao) {
            return;
        }

        if (acao.status === 'AGUARDANDO_ACAO_HUMANA' && acao.acao_id) {
            item.dataset.acaoId = acao.acao_id;
            return;
        }

        marcarAcaoRealizada(item, acao);
    });
}

function marcarAcaoRealizada(item, actionData) {
    const actionsDiv = item.querySelector('.pressao-alvo-actions');
    if (!actionsDiv) return;
    const timestamp = actionData.timestamp || actionData;
    const timeText = formatActionTime(timestamp);
    const doneLabel = pressaoData?.actionDoneLabel || 'Ação realizada ✓';
    actionsDiv.innerHTML = `
        <span class="pressao-action-done">
            ${doneLabel}
            <span class="pressao-action-time">${timeText}</span>
        </span>
    `;
    item.classList.add('action-done');
    item.classList.remove('action-pending');
}

function formatActionTime(timestamp) {
    const now = Math.floor(Date.now() / 1000);
    const diff = now - timestamp;
    if (diff < 60) return 'agora';
    if (diff < 3600) {
        const minutes = Math.floor(diff / 60);
        return minutes + ' min' + (minutes > 1 ? 's' : '');
    }
    if (diff < 86400) {
        const hours = Math.floor(diff / 3600);
        return hours + ' h' + (hours > 1 ? 's' : '');
    }
    const days = Math.floor(diff / 86400);
    return days + ' d' + (days > 1 ? 's' : '');
}

function showNotification(container, type, message) {
    const oldNotifications = container.querySelectorAll('.pressao-notification');
    oldNotifications.forEach(function(el) { el.remove(); });
    const notification = document.createElement('div');
    notification.className = 'pressao-notification pressao-notification-' + type;
    notification.textContent = message;
    container.prepend(notification);
    setTimeout(function() {
        notification.style.opacity = '0';
        setTimeout(function() { notification.remove(); }, 300);
    }, 4000);
}

function escapeHtml(text) {
    if (!text) return '';
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

function escapeAttribute(text) {
    return escapeHtml(text).replace(/"/g, '&quot;').replace(/'/g, '&#039;');
}