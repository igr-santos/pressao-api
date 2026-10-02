/**
 * Pressão Plugin — módulo comum (window.PressaoCore)
 * Funções sem estado de widget, usadas por [pressao_fluxo] e [pressao_multicanal].
 */
(function () {
    'use strict';

    var SESSAO_COOKIE = 'pressao_sessao_id';
    var COOKIE_ACTIONS = 'pressao_acoes_realizadas';
    var ATIVISTA_COOKIE = 'pressao_ativista_data';
    var ATIVISTA_CONFIRM_COOKIE = 'pressao_ativista_last_confirm';

    var ANDROID_PACKAGES = {
        instagram: 'com.instagram.android',
        tiktok: 'com.zhiliaoapp.musically'
    };

    function data() {
        return window.pressaoCoreData || {};
    }

    // ------------------------------------------------------------------
    // Cookies e sessão
    // ------------------------------------------------------------------

    function setCookie(name, value, seconds) {
        var expires = '';
        if (seconds && seconds > 0) {
            var date = new Date();
            date.setTime(date.getTime() + seconds * 1000);
            expires = '; expires=' + date.toUTCString();
        }
        document.cookie = name + '=' + encodeURIComponent(value) + expires + '; path=/; SameSite=Lax';
    }

    function getCookie(name) {
        var nameEQ = name + '=';
        var ca = document.cookie.split(';');
        for (var i = 0; i < ca.length; i++) {
            var c = ca[i].trim();
            if (c.indexOf(nameEQ) === 0) {
                return decodeURIComponent(c.substring(nameEQ.length));
            }
        }
        return null;
    }

    function deleteCookie(name) {
        document.cookie = name + '=; expires=Thu, 01 Jan 1970 00:00:00 UTC; path=/;';
    }

    function sessionDuration() {
        return parseInt(data().sessionDuration, 10) || 86400;
    }

    function getOrCreateSessaoId() {
        var id = getCookie(SESSAO_COOKIE);
        if (!id) {
            id = crypto.randomUUID();
            setCookie(SESSAO_COOKIE, id, sessionDuration());
        }
        return id;
    }

    // ------------------------------------------------------------------
    // Mapa de ações (cookie pressao_acoes_realizadas)
    // ------------------------------------------------------------------

    function readActions() {
        try {
            var raw = getCookie(COOKIE_ACTIONS);
            var parsed = raw ? JSON.parse(raw) : {};
            return parsed && typeof parsed === 'object' ? parsed : {};
        } catch (e) {
            return {};
        }
    }

    function writeActions(actions) {
        setCookie(COOKIE_ACTIONS, JSON.stringify(actions), sessionDuration());
    }

    function saveActionCookie(key, entry) {
        var actions = readActions();
        actions[key] = entry;
        writeActions(actions);
    }

    function removeActionCookie(key) {
        var actions = readActions();
        if (Object.prototype.hasOwnProperty.call(actions, key)) {
            delete actions[key];
            writeActions(actions);
        }
    }

    /** Automática conta na hora; manual só depois de confirmada. */
    function isAcaoRealizada(entry) {
        if (!entry || typeof entry !== 'object') {
            return false;
        }
        return (entry.status || 'CONCLUIDA') !== 'AGUARDANDO_ACAO_HUMANA';
    }

    // ------------------------------------------------------------------
    // Ativista (cookie pressao_ativista_data, mesmo formato do widget.js)
    // ------------------------------------------------------------------

    function getAtivista() {
        try {
            var raw = getCookie(ATIVISTA_COOKIE);
            var parsed = raw ? JSON.parse(raw) : null;
            return parsed && typeof parsed === 'object' ? parsed : null;
        } catch (e) {
            return null;
        }
    }

    function saveAtivista(ativista) {
        try {
            setCookie(ATIVISTA_COOKIE, JSON.stringify({
                nome: ativista.nome || '',
                email: ativista.email || '',
                telefone: ativista.telefone || ''
            }), sessionDuration());
            setCookie(ATIVISTA_CONFIRM_COOKIE, String(Date.now()), sessionDuration());
            return true;
        } catch (e) {
            return false;
        }
    }

    // ------------------------------------------------------------------
    // Texto e formulários
    // ------------------------------------------------------------------

    function escapeHtml(str) {
        return String(str == null ? '' : str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }

    function escapeAttr(str) {
        return escapeHtml(str).replace(/`/g, '&#96;');
    }

    /** "@a, @b corpo" — handles separados por vírgula antes do texto do template. */
    function montarMensagemComHandles(handles, corpo) {
        var prefix = (handles || []).filter(Boolean).join(', ');
        var body = String(corpo || '').trim();
        if (prefix && body) {
            return prefix + ' ' + body;
        }
        return prefix || body;
    }

    function isEmailValido(value) {
        return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(String(value || '').trim());
    }

    function digitsOnly(value) {
        return String(value || '').replace(/\D/g, '');
    }

    /** Máscara BR: (11) 9999-9999 ou (11) 99999-9999 */
    function formatPhoneMask(value) {
        var digits = digitsOnly(value).slice(0, 11);
        if (!digits.length) {
            return '';
        }
        if (digits.length <= 2) {
            return '(' + digits;
        }
        if (digits.length <= 6) {
            return '(' + digits.slice(0, 2) + ') ' + digits.slice(2);
        }
        if (digits.length <= 10) {
            return '(' + digits.slice(0, 2) + ') ' + digits.slice(2, 6) + '-' + digits.slice(6);
        }
        return '(' + digits.slice(0, 2) + ') ' + digits.slice(2, 7) + '-' + digits.slice(7);
    }

    function bindPhoneMask(input) {
        if (!input || input.dataset.maskBound === 'true') {
            return;
        }
        input.dataset.maskBound = 'true';
        input.setAttribute('inputmode', 'numeric');
        input.setAttribute('autocomplete', 'tel');
        input.addEventListener('input', function () {
            var start = input.selectionStart;
            var before = input.value.length;
            input.value = formatPhoneMask(input.value);
            var after = input.value.length;
            if (typeof start === 'number') {
                var next = Math.max(0, start + (after - before));
                try {
                    input.setSelectionRange(next, next);
                } catch (e) { /* ignore */ }
            }
        });
    }

    // ------------------------------------------------------------------
    // Contador de pressões
    // ------------------------------------------------------------------

    function formatCount(n) {
        return Number(n || 0).toLocaleString('pt-BR');
    }

    function animateCountUp(el, from, to, durationMs) {
        durationMs = durationMs || 800;
        if (from === to) {
            el.textContent = formatCount(to);
            el.dataset.count = String(to);
            return;
        }
        var start = performance.now();
        function easeOut(t) {
            return 1 - Math.pow(1 - t, 3);
        }
        function frame(now) {
            var progress = Math.min((now - start) / durationMs, 1);
            var current = Math.round(from + (to - from) * easeOut(progress));
            el.textContent = formatCount(current);
            el.dataset.count = String(current);
            if (progress < 1) {
                requestAnimationFrame(frame);
            }
        }
        requestAnimationFrame(frame);
    }

    function updateCounter(campaignId, newValue) {
        if (!campaignId) {
            return;
        }
        document.querySelectorAll('.pressao-acoes-counter[data-campaign="' + campaignId + '"]').forEach(function (counter) {
            var el = counter.querySelector('.pressao-acoes-count');
            if (!el) {
                return;
            }
            var from = parseInt(el.dataset.count || el.textContent.replace(/\D/g, ''), 10) || 0;
            var to = typeof newValue === 'number' ? newValue : from + 1;
            animateCountUp(el, from, to);
        });
    }

    // ------------------------------------------------------------------
    // AJAX (admin-ajax.php)
    // ------------------------------------------------------------------

    function isNonceError(message) {
        return /nonce/i.test(String(message || ''));
    }

    function refreshNonce(root) {
        return fetch(data().ajaxUrl, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            credentials: 'same-origin',
            body: new URLSearchParams({
                action: 'pressao_refresh_nonce',
                nonce: root.dataset.nonce || data().nonce || ''
            })
        })
            .then(function (r) {
                return r.json();
            })
            .then(function (json) {
                var nonce = json && json.data && json.data.nonce;
                if (nonce) {
                    root.dataset.nonce = nonce;
                    return nonce;
                }
                return root.dataset.nonce || data().nonce || '';
            })
            .catch(function () {
                return root.dataset.nonce || data().nonce || '';
            });
    }

    function postAjax(payload) {
        return fetch(data().ajaxUrl, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            credentials: 'same-origin',
            body: new URLSearchParams(payload)
        }).then(function (response) {
            var contentType = response.headers.get('content-type') || '';
            if (!contentType.includes('application/json')) {
                return response.text().then(function () {
                    throw new Error('Resposta inválida do servidor (esperado JSON).');
                });
            }
            return response.json();
        });
    }

    function responseMessage(response, fallback) {
        return (response && response.data && response.data.message) || fallback;
    }

    /** POST com nonce renovado antes e um retry se o nonce ainda vier inválido. */
    function postWithNonce(root, buildPayload, fallbackMessage) {
        return refreshNonce(root)
            .then(function (nonce) {
                return postAjax(buildPayload(nonce)).then(function (response) {
                    if (response.success || !isNonceError(responseMessage(response, ''))) {
                        return response;
                    }
                    return refreshNonce(root).then(function (fresh) {
                        return postAjax(buildPayload(fresh));
                    });
                });
            })
            .then(function (response) {
                if (!response.success) {
                    throw new Error(responseMessage(response, fallbackMessage));
                }
                return response;
            });
    }

    /**
     * Cria a ação (pressao_realizar_acao).
     * opts: { root, alvoId, campanhaId, canal, templateId, ativista }
     */
    function realizarAcao(opts) {
        var ativista = opts.ativista || null;
        return postWithNonce(opts.root, function (nonce) {
            return {
                action: 'pressao_realizar_acao',
                alvo_id: opts.alvoId,
                campanha_id: opts.campanhaId,
                canal: opts.canal,
                template_id: opts.templateId || '',
                nonce: nonce,
                sessao_id: getOrCreateSessaoId(),
                ativista_nome: (ativista && ativista.nome) || '',
                ativista_email: (ativista && ativista.email) || '',
                ativista_telefone: (ativista && ativista.telefone) || ''
            };
        }, 'Erro ao criar ação');
    }

    /** opts: { root, acaoId, alvoId, campanhaId } */
    function confirmarAcao(opts) {
        return postWithNonce(opts.root, function (nonce) {
            return {
                action: 'pressao_confirmar_acao',
                acao_id: opts.acaoId,
                alvo_id: opts.alvoId,
                campanha_id: opts.campanhaId,
                nonce: nonce
            };
        }, 'Erro ao confirmar ação');
    }

    function acaoIdFrom(response) {
        var apiData = (response.data && response.data.data) || {};
        return (response.data && response.data.acao_id) || apiData.acao_id || null;
    }

    /**
     * Canais manuais: cria e confirma em sequência.
     * Resolve { create, confirm, acaoId }; rejeita com Error.
     */
    function criarEConfirmarAcao(opts) {
        return realizarAcao(opts).then(function (create) {
            var acaoId = acaoIdFrom(create);
            if (!acaoId) {
                throw new Error('ID da ação não encontrado.');
            }
            return confirmarAcao({
                root: opts.root,
                acaoId: acaoId,
                alvoId: opts.alvoId,
                campanhaId: opts.campanhaId
            }).then(function (confirm) {
                return { create: create, confirm: confirm, acaoId: acaoId };
            });
        });
    }

    // ------------------------------------------------------------------
    // Copiar, contagem e abrir app
    // ------------------------------------------------------------------

    /** Resolve sempre (o fluxo segue mesmo se a cópia falhar). */
    function copyText(text) {
        if (text && navigator.clipboard && navigator.clipboard.writeText) {
            return navigator.clipboard.writeText(text).catch(function () {});
        }
        return Promise.resolve();
    }

    /** Chama onTick(n) de `seconds` até 1, um por segundo; resolve ao zerar. */
    function countdown(seconds, onTick) {
        return new Promise(function (resolve) {
            var remaining = seconds;
            if (remaining <= 0) {
                resolve();
                return;
            }
            if (typeof onTick === 'function') {
                onTick(remaining);
            }
            var timer = setInterval(function () {
                remaining -= 1;
                if (remaining <= 0) {
                    clearInterval(timer);
                    resolve();
                    return;
                }
                if (typeof onTick === 'function') {
                    onTick(remaining);
                }
            }, 1000);
        });
    }

    function wait(ms) {
        return new Promise(function (resolve) {
            setTimeout(resolve, ms);
        });
    }

    // Detecção por user agent (não por largura): comportamento de deep link
    // / Intent é do dispositivo, não do layout responsivo.
    function isMobileBrowser() {
        return /Android|iPhone|iPad|iPod|Mobile|IEMobile|BlackBerry/i.test(navigator.userAgent || '');
    }

    function isAndroidBrowser() {
        return /Android/i.test(navigator.userAgent || '');
    }

    /**
     * Mobile: tenta abrir o app sem nova aba do browser, para o X/voltar do
     * app devolver à página do widget. Android usa Intent; iOS dispara
     * Universal Link via <a> sem target. Desktop: window.open em nova aba.
     *
     * @param {string} webUrl
     * @param {string} androidPackage pacote Android ou chave de ANDROID_PACKAGES
     */
    function openAppUrl(webUrl, androidPackage) {
        if (!webUrl) {
            return;
        }

        if (!isMobileBrowser()) {
            window.open(webUrl, '_blank', 'noopener,noreferrer');
            return;
        }

        var pkg = ANDROID_PACKAGES[androidPackage] || androidPackage || '';
        if (isAndroidBrowser() && pkg) {
            var path = webUrl.replace(/^https?:\/\//i, '');
            window.location.href =
                'intent://' +
                path +
                '#Intent;scheme=https;package=' +
                pkg +
                ';S.browser_fallback_url=' +
                encodeURIComponent(webUrl) +
                ';end';
            return;
        }

        var anchor = document.createElement('a');
        anchor.href = webUrl;
        anchor.rel = 'noopener noreferrer';
        document.body.appendChild(anchor);
        anchor.click();
        document.body.removeChild(anchor);
    }

    // ------------------------------------------------------------------
    // Compartilhar (link, redes e imagens para postar)
    // ------------------------------------------------------------------

    var SHARE_REDES = {
        whatsapp: { urlKey: 'whatsapp_url', label: 'WhatsApp' },
        instagram: { urlKey: 'instagram_url', label: 'Instagram' },
        messenger: { urlKey: 'messenger_url', label: 'Messenger' },
        x: { urlKey: 'x_url', label: 'X' }
    };

    /**
     * Renderiza o bloco de compartilhamento em `mount`.
     *
     * opts:
     *  - prefix: prefixo das classes (ex.: 'pressao-fluxo')
     *  - dataPrefix: prefixo dos data-attrs (ex.: 'fluxo' → data-fluxo-copy-link)
     *  - redes: lista de redes (ordem de exibição)
     *  - titulo, subtitulo: textos do topo
     *  - headerHtml: substitui o <h2> do título (ex.: com botão voltar/fechar)
     *  - resetLabel + onReset: botão final opcional
     *  - onShared(canal): ao copiar link ('link') ou abrir uma rede
     */
    function renderShare(mount, share, opts) {
        if (!mount) {
            return;
        }
        share = share || {};
        opts = opts || {};
        var p = opts.prefix || 'pressao-fluxo';
        var d = 'data-' + (opts.dataPrefix || 'fluxo') + '-';
        var redes = opts.redes || ['whatsapp', 'instagram', 'messenger'];
        var onShared = typeof opts.onShared === 'function' ? opts.onShared : function () {};

        var socialHtml = redes
            .filter(function (canal) {
                return !!SHARE_REDES[canal];
            })
            .map(function (canal) {
                var rede = SHARE_REDES[canal];
                var url = share[rede.urlKey] || '';
                var tagOpen = url
                    ? '<a class="' + p + '-share-social" data-canal="' +
                      escapeAttr(canal) +
                      '" href="' +
                      escapeAttr(url) +
                      '" target="_blank" rel="noopener noreferrer">'
                    : '<span class="' + p + '-share-social is-disabled" data-canal="' +
                      escapeAttr(canal) +
                      '" aria-disabled="true" title="Configure o link em Compartilhamento">';
                var tagClose = url ? '</a>' : '</span>';
                return (
                    tagOpen +
                    '<span class="' + p + '-share-social-icon" data-canal="' +
                    escapeAttr(canal) +
                    '" aria-hidden="true"></span>' +
                    '<span class="' + p + '-share-social-label">' +
                    escapeHtml(rede.label) +
                    '</span>' +
                    tagClose
                );
            })
            .join('');

        var imagens = Array.isArray(share.imagens) ? share.imagens : [];
        var firstThumb = imagens.length ? imagens[0].thumb || imagens[0].url || '' : '';
        var imagesCard = imagens.length
            ? '<button type="button" class="' + p + '-images-card" ' + d + 'images-open>' +
              '<span class="' + p + '-images-thumb"' +
              (firstThumb ? ' style="background-image:url(\'' + escapeAttr(firstThumb) + '\')"' : '') +
              '></span>' +
              '<span class="' + p + '-images-copy"><strong>' +
              escapeHtml(share.imagens_titulo || 'Imagens para postar') +
              '</strong><span>' +
              escapeHtml(share.imagens_subtitulo || 'baixe imagens prontas para postar nas redes') +
              '</span></span>' +
              '<span class="' + p + '-images-arrow" aria-hidden="true"></span></button>'
            : '';

        var imagesItems = imagens
            .map(function (img, index) {
                return (
                    '<div class="' + p + '-image-item" data-index="' +
                    index +
                    '">' +
                    '<div class="' + p + '-image-thumb-wrap">' +
                    '<span class="' + p + '-image-thumb" style="background-image:url(\'' +
                    escapeAttr(img.thumb || img.url) +
                    '\')"></span>' +
                    '<button type="button" class="' + p + '-image-download" data-index="' +
                    index +
                    '">BAIXAR</button>' +
                    '</div>' +
                    '<span class="' + p + '-image-rotulo">' +
                    escapeHtml(img.rotulo || '') +
                    '</span></div>'
                );
            })
            .join('');

        var downloadAllBtn = imagens.length
            ? '<button type="button" class="' + p + '-btn ' + p + '-btn-primary" ' + d + 'download-all>' +
              'Baixar todas as imagens' +
              '<span class="' + p + '-btn-download" aria-hidden="true"></span></button>'
            : '';

        var headerHtml = opts.headerHtml ||
            '<h2 class="' + p + '-title">' + escapeHtml(opts.titulo || 'Convide mais pessoas') + '</h2>';

        var resetBtnHtml = opts.resetLabel
            ? '<button type="button" class="' + p + '-btn ' + p + '-btn-secondary" ' + d + 'reset>' +
              escapeHtml(opts.resetLabel) + '</button>'
            : '';

        mount.innerHTML =
            '<div class="' + p + '-share-main" ' + d + 'share-main>' +
            headerHtml +
            '<p class="' + p + '-subtitle">' +
            escapeHtml(opts.subtitulo || '') +
            '</p>' +
            '<button type="button" class="' + p + '-link-row" ' + d + 'copy-link aria-label="Copiar link">' +
            '<span class="' + p + '-link-text">' +
            escapeHtml(share.link || '') +
            '</span>' +
            '<span class="' + p + '-copy-link" ' + d + 'copy-btn>' +
            '<span class="' + p + '-copy-icon" aria-hidden="true"></span>' +
            '<span class="' + p + '-copy-label">Copiar link</span></span></button>' +
            (socialHtml ? '<div class="' + p + '-share-social-row">' + socialHtml + '</div>' : '') +
            imagesCard +
            resetBtnHtml +
            '</div>' +
            '<div class="' + p + '-images-screen" ' + d + 'images-screen hidden>' +
            '<header class="' + p + '-images-header">' +
            '<button type="button" class="' + p + '-back" ' + d + 'images-back aria-label="Voltar"></button>' +
            '<h3 class="' + p + '-nav-title">' +
            escapeHtml(share.imagens_titulo || 'Imagens para postar') +
            '</h3></header>' +
            '<p class="' + p + '-subtitle">' +
            escapeHtml(
                share.imagens_instrucao ||
                    'Utilize nossas imagens nas suas redes para que outras pessoas conheçam a campanha:'
            ) +
            '</p>' +
            '<div class="' + p + '-images-grid">' +
            imagesItems +
            '</div>' +
            downloadAllBtn +
            '</div>';

        function q(name) {
            return mount.querySelector('[' + d + name + ']');
        }

        function setCopiedState(isCopied) {
            var btn = q('copy-btn');
            var label = mount.querySelector('.' + p + '-copy-label');
            var icon = mount.querySelector('.' + p + '-copy-icon');
            if (!btn || !label) {
                return;
            }
            btn.classList.toggle('is-copied', isCopied);
            label.textContent = isCopied ? 'Copiado!' : 'Copiar link';
            if (icon) {
                icon.classList.toggle('is-check', isCopied);
            }
        }

        var copyRow = q('copy-link');
        if (copyRow) {
            copyRow.addEventListener('click', function (e) {
                e.preventDefault();
                var link = share.link || '';
                if (!link) {
                    return;
                }
                copyText(link).then(function () {
                    setCopiedState(true);
                    onShared('link');
                    setTimeout(function () {
                        setCopiedState(false);
                    }, 1800);
                });
            });
        }

        mount.querySelectorAll('a.' + p + '-share-social').forEach(function (a) {
            a.addEventListener('click', function () {
                onShared(a.getAttribute('data-canal'));
            });
        });

        var openImages = q('images-open');
        var imagesScr = q('images-screen');
        var mainScr = q('share-main');
        if (openImages && imagesScr && mainScr) {
            openImages.addEventListener('click', function () {
                mainScr.hidden = true;
                imagesScr.hidden = false;
                imagesScr.classList.add('is-entering');
                if (window.PressaoShareImages && typeof window.PressaoShareImages.prefetch === 'function') {
                    window.PressaoShareImages.prefetch(imagens);
                }
            });
        }
        var backImages = q('images-back');
        if (backImages && imagesScr && mainScr) {
            backImages.addEventListener('click', function () {
                imagesScr.hidden = true;
                imagesScr.classList.remove('is-entering');
                mainScr.hidden = false;
            });
        }

        var resetBtn = q('reset');
        if (resetBtn && typeof opts.onReset === 'function') {
            resetBtn.addEventListener('click', opts.onReset);
        }

        mount.querySelectorAll('.' + p + '-image-item').forEach(function (item) {
            item.addEventListener('click', function (e) {
                e.preventDefault();
                var index = parseInt(item.getAttribute('data-index'), 10);
                if (isNaN(index) || !imagens[index]) {
                    return;
                }
                if (window.PressaoShareImages && typeof window.PressaoShareImages.downloadOrShareOne === 'function') {
                    window.PressaoShareImages.downloadOrShareOne(imagens[index], index);
                }
            });
        });

        var downloadAll = q('download-all');
        if (downloadAll) {
            downloadAll.addEventListener('click', function () {
                if (window.PressaoShareImages && typeof window.PressaoShareImages.downloadOrShareAll === 'function') {
                    window.PressaoShareImages.downloadOrShareAll(imagens);
                }
            });
        }
    }

    window.PressaoCore = {
        data: data,
        setCookie: setCookie,
        getCookie: getCookie,
        deleteCookie: deleteCookie,
        sessionDuration: sessionDuration,
        getOrCreateSessaoId: getOrCreateSessaoId,
        readActions: readActions,
        saveActionCookie: saveActionCookie,
        removeActionCookie: removeActionCookie,
        isAcaoRealizada: isAcaoRealizada,
        getAtivista: getAtivista,
        saveAtivista: saveAtivista,
        escapeHtml: escapeHtml,
        escapeAttr: escapeAttr,
        montarMensagemComHandles: montarMensagemComHandles,
        isEmailValido: isEmailValido,
        digitsOnly: digitsOnly,
        formatPhoneMask: formatPhoneMask,
        bindPhoneMask: bindPhoneMask,
        formatCount: formatCount,
        animateCountUp: animateCountUp,
        updateCounter: updateCounter,
        isNonceError: isNonceError,
        refreshNonce: refreshNonce,
        postAjax: postAjax,
        realizarAcao: realizarAcao,
        confirmarAcao: confirmarAcao,
        criarEConfirmarAcao: criarEConfirmarAcao,
        copyText: copyText,
        countdown: countdown,
        wait: wait,
        isMobileBrowser: isMobileBrowser,
        isAndroidBrowser: isAndroidBrowser,
        openAppUrl: openAppUrl,
        renderShare: renderShare
    };
})();
