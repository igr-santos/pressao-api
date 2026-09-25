/**
 * Pressão Plugin — fluxo único sequencial (Instagram v1)
 * Isolado de widget.js / [pressao_alvos].
 */
(function () {
    'use strict';

    var SESSAO_COOKIE = 'pressao_sessao_id';
    var COOKIE_ACTIONS = 'pressao_acoes_realizadas';
    var TOAST_MS = 2800;
    var REDIRECT_COUNTDOWN_S = 3;

    function data() {
        return window.pressaoFluxoData || {};
    }

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

    function parseConfig(root) {
        var raw = root.getAttribute('data-pressao-fluxo') || '{}';
        try {
            return JSON.parse(raw);
        } catch (e) {
            console.error('pressao_fluxo: config inválida', e);
            return null;
        }
    }

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

    function saveActionCookie(alvoId, entry) {
        var actions = {};
        try {
            var raw = getCookie(COOKIE_ACTIONS);
            if (raw) {
                actions = JSON.parse(raw) || {};
            }
        } catch (e) {
            actions = {};
        }
        actions[alvoId] = entry;
        setCookie(COOKIE_ACTIONS, JSON.stringify(actions), sessionDuration());
    }

    function initFluxo(root) {
        var config = parseConfig(root);
        if (!config) {
            return;
        }

        var state = {
            selectedIds: [],
            submitting: false
        };

        var selectEl = root.querySelector('[data-fluxo-select]');
        var listaOverlay = root.querySelector('[data-fluxo-lista]');
        var helpOverlay = root.querySelector('[data-fluxo-help]');
        var seqOverlay = root.querySelector('[data-fluxo-seq]');
        var toastEl = root.querySelector('[data-fluxo-toast]');
        var toastTitle = root.querySelector('[data-fluxo-toast-title]');
        var toastText = root.querySelector('[data-fluxo-toast-text]');
        var formError = root.querySelector('[data-fluxo-form-error]');
        var tom = null;
        var candidatosById = {};
        var seqCloseTimer = null;
        var bodyScrollLocked = false;

        // No desktop, .pressao-fluxo-seq-overlay é um item flex inline
        // dentro de .pressao-fluxo-card (flex:1, ocupa o espaço ao lado do
        // painel esquerdo) — só vira overlay de tela cheia (position:fixed)
        // no breakpoint mobile (ver @media max-width:767px no CSS). Por
        // isso, ao contrário de lista/help/toast (que são sempre fixed, em
        // qualquer largura, e por isso portados sem condição lá embaixo),
        // este só pode ser movido pro <body> quando realmente vai renderizar
        // como drawer mobile — mover sempre quebraria o layout desktop.
        // Guardamos um comentário-âncora no lugar original pra devolver o
        // elemento certinho ali quando a tela crescer.
        var seqOverlayAnchor = null;
        if (seqOverlay && seqOverlay.parentNode) {
            seqOverlayAnchor = document.createComment('pressao-fluxo-seq-overlay-anchor');
            seqOverlay.parentNode.insertBefore(seqOverlayAnchor, seqOverlay);
        }

        function syncSeqOverlayPortal() {
            if (!seqOverlay || !seqOverlayAnchor) {
                return;
            }
            if (isMobileDrawer()) {
                if (seqOverlay.parentElement !== document.body) {
                    document.body.appendChild(seqOverlay);
                }
            } else if (seqOverlay.parentElement === document.body) {
                seqOverlayAnchor.parentNode.insertBefore(seqOverlay, seqOverlayAnchor.nextSibling);
            }
        }

        if (listaOverlay) {
            listaOverlay.hidden = true;
            listaOverlay.classList.remove('is-open', 'is-closing');
        }
        if (helpOverlay) {
            helpOverlay.hidden = true;
            helpOverlay.classList.remove('is-open', 'is-closing');
        }
        if (seqOverlay) {
            seqOverlay.hidden = true;
            seqOverlay.classList.remove('is-open', 'is-closing');
        }
        if (toastEl) {
            toastEl.hidden = true;
            toastEl.classList.remove('is-visible');
        }

        // Buscas dinâmicas, refeitas a cada interação (screens do passo 2+,
        // botão "copiar", chips, mensagem, share, form...), todas dentro de
        // seqOverlay, que é portado pra fora de `root` mais abaixo (ver
        // comentário perto do fim desta função). queryAll()/queryOne() somam
        // root + seqOverlay pra continuar encontrando esses elementos depois
        // do portal.
        function queryAll(selector) {
            var results = Array.prototype.slice.call(root.querySelectorAll(selector));
            if (seqOverlay) {
                results = results.concat(Array.prototype.slice.call(seqOverlay.querySelectorAll(selector)));
            }
            return results;
        }

        function queryOne(selector) {
            return root.querySelector(selector) || (seqOverlay ? seqOverlay.querySelector(selector) : null);
        }

        (config.candidatos || []).forEach(function (c) {
            candidatosById[c.id] = c;
        });

        function isMobileDrawer() {
            return window.matchMedia('(max-width: 767px)').matches;
        }

        function lockBodyScroll() {
            if (bodyScrollLocked) {
                return;
            }
            bodyScrollLocked = true;
            document.documentElement.style.overflow = 'hidden';
            document.body.style.overflow = 'hidden';
        }

        function unlockBodyScroll() {
            if (!bodyScrollLocked) {
                return;
            }
            bodyScrollLocked = false;
            document.documentElement.style.overflow = '';
            document.body.style.overflow = '';
        }

        function openSeq() {
            if (!seqOverlay) {
                return;
            }
            if (seqCloseTimer) {
                clearTimeout(seqCloseTimer);
                seqCloseTimer = null;
            }
            syncSeqOverlayPortal();
            seqOverlay.hidden = false;
            seqOverlay.classList.remove('is-closing');
            root.classList.add('is-seq-open');
            if (isMobileDrawer()) {
                lockBodyScroll();
                requestAnimationFrame(function () {
                    seqOverlay.classList.add('is-open');
                });
            } else {
                seqOverlay.classList.add('is-open');
            }
        }

        function closeSeq(immediate) {
            if (!seqOverlay) {
                root.classList.remove('is-seq-open');
                unlockBodyScroll();
                return;
            }
            if (seqOverlay.hidden && !root.classList.contains('is-seq-open')) {
                return;
            }
            if (seqCloseTimer) {
                clearTimeout(seqCloseTimer);
                seqCloseTimer = null;
            }

            var finish = function () {
                seqOverlay.hidden = true;
                seqOverlay.classList.remove('is-open', 'is-closing');
                root.classList.remove('is-seq-open');
                unlockBodyScroll();
                seqCloseTimer = null;
            };

            if (immediate || !isMobileDrawer() || !seqOverlay.classList.contains('is-open')) {
                finish();
                return;
            }

            seqOverlay.classList.add('is-closing');
            seqOverlay.classList.remove('is-open');
            seqCloseTimer = setTimeout(finish, 280);
        }

        function showScreen(name) {
            var mobile = isMobileDrawer();
            var isInicio = name === 'inicio';

            if (isInicio) {
                closeSeq();
            } else {
                openSeq();
            }

            queryAll('.pressao-fluxo-screen').forEach(function (screen) {
                var screenName = screen.getAttribute('data-screen');
                var active = screenName === name;

                if (!isInicio && screenName === 'inicio') {
                    // Mantém a tela inicial: atrás do drawer (mobile) ou left-panel (desktop).
                    screen.hidden = false;
                    screen.classList.add('is-active');
                    return;
                }

                screen.hidden = !active;
                screen.classList.toggle('is-active', active);
            });
            root.dataset.fluxoStep = name;
        }

        function openLista() {
            if (!listaOverlay) {
                return;
            }
            listaOverlay.hidden = false;
            listaOverlay.classList.remove('is-closing');
            requestAnimationFrame(function () {
                listaOverlay.classList.add('is-open');
            });
        }

        function closeLista() {
            if (!listaOverlay || listaOverlay.hidden) {
                return;
            }
            listaOverlay.classList.add('is-closing');
            listaOverlay.classList.remove('is-open');
            setTimeout(function () {
                listaOverlay.hidden = true;
                listaOverlay.classList.remove('is-closing');
            }, 200);
        }

        function showToast(title, text) {
            return new Promise(function (resolve) {
                if (!toastEl) {
                    resolve();
                    return;
                }
                toastTitle.textContent = title || '';
                toastText.textContent = text || '';
                toastEl.hidden = false;
                toastEl.classList.add('is-visible');
                setTimeout(function () {
                    toastEl.classList.remove('is-visible');
                    toastEl.hidden = true;
                    resolve();
                }, TOAST_MS);
            });
        }

        function hideToast() {
            if (!toastEl) {
                return;
            }
            toastEl.classList.remove('is-visible');
            toastEl.hidden = true;
        }

        /**
         * Exibe toast e atualiza o texto a cada segundo até zerar.
         * Não esconde o toast ao resolver — o caller controla o dismiss.
         */
        function showToastCountdown(title, buildTextFn, seconds, onTick) {
            return new Promise(function (resolve) {
                if (!toastEl) {
                    resolve();
                    return;
                }
                var remaining = seconds;
                toastTitle.textContent = title || '';
                toastText.textContent = typeof buildTextFn === 'function' ? buildTextFn(remaining) : '';
                toastEl.hidden = false;
                toastEl.classList.add('is-visible');
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
                    toastText.textContent = typeof buildTextFn === 'function' ? buildTextFn(remaining) : '';
                    if (typeof onTick === 'function') {
                        onTick(remaining);
                    }
                }, 1000);
            });
        }

        function selectedCandidatos() {
            return state.selectedIds
                .map(function (id) {
                    return candidatosById[id];
                })
                .filter(Boolean);
        }

        function buildMessage() {
            var handles = selectedCandidatos()
                .map(function (c) {
                    return c.instagram;
                })
                .filter(Boolean);
            var prefix = handles.join(', ');
            var body = (config.template_conteudo || '').trim();
            if (prefix && body) {
                return prefix + ' ' + body;
            }
            return prefix || body;
        }

        function renderChips() {
            var wrap = queryOne('[data-fluxo-chips]');
            if (!wrap) {
                return;
            }
            var list = selectedCandidatos();
            var visible = list.slice(0, 3);
            var extra = list.length - visible.length;
            var moreLabel = '';
            if (extra > 0) {
                moreLabel = window.matchMedia('(min-width: 768px)').matches
                    ? 'Mostrar +' + extra
                    : '+' + extra;
            }
            wrap.innerHTML = visible
                .map(function (c) {
                    var img = c.imagem
                        ? '<span class="pressao-fluxo-chip-avatar" style="background-image:url(\'' + escapeAttr(c.imagem) + '\')"></span>'
                        : '<span class="pressao-fluxo-chip-avatar is-empty"></span>';
                    return '<span class="pressao-fluxo-chip">' + img + '<span>' + escapeHtml(c.instagram) + '</span></span>';
                })
                .join('') + (extra > 0 ? '<span class="pressao-fluxo-chip-more">' + moreLabel + '</span>' : '');
        }

        function renderMessage() {
            var el = queryOne('[data-fluxo-message]');
            if (el) {
                el.textContent = buildMessage();
            }
        }

        function renderShare() {
            var mount = queryOne('[data-fluxo-share]');
            if (!mount) {
                return;
            }
            var share = config.share || {};
            // Layout do fluxo sempre exibe os 3 canais; link opcional (admin).
            var social = [
                { canal: 'whatsapp', url: share.whatsapp_url || '', label: 'WhatsApp' },
                { canal: 'instagram', url: share.instagram_url || '', label: 'Instagram' },
                { canal: 'messenger', url: share.messenger_url || '', label: 'Messenger' }
            ];
            var socialHtml = social
                .map(function (btn) {
                    var hasUrl = !!btn.url;
                    var tagOpen = hasUrl
                        ? '<a class="pressao-fluxo-share-social" data-canal="' +
                          escapeAttr(btn.canal) +
                          '" href="' +
                          escapeAttr(btn.url) +
                          '" target="_blank" rel="noopener noreferrer">'
                        : '<span class="pressao-fluxo-share-social is-disabled" data-canal="' +
                          escapeAttr(btn.canal) +
                          '" aria-disabled="true" title="Configure o link em Compartilhamento">';
                    var tagClose = hasUrl ? '</a>' : '</span>';
                    return (
                        tagOpen +
                        '<span class="pressao-fluxo-share-social-icon" data-canal="' +
                        escapeAttr(btn.canal) +
                        '" aria-hidden="true"></span>' +
                        '<span class="pressao-fluxo-share-social-label">' +
                        escapeHtml(btn.label) +
                        '</span>' +
                        tagClose
                    );
                })
                .join('');

            var imagens = Array.isArray(share.imagens) ? share.imagens : [];
            var firstThumb = imagens.length ? imagens[0].thumb || imagens[0].url || '' : '';
            var imagesCard = imagens.length
                ? '<button type="button" class="pressao-fluxo-images-card" data-fluxo-images-open>' +
                  '<span class="pressao-fluxo-images-thumb"' +
                  (firstThumb ? ' style="background-image:url(\'' + escapeAttr(firstThumb) + '\')"' : '') +
                  '></span>' +
                  '<span class="pressao-fluxo-images-copy"><strong>' +
                  escapeHtml(share.imagens_titulo || 'Imagens para postar') +
                  '</strong><span>' +
                  escapeHtml(share.imagens_subtitulo || 'baixe imagens prontas para postar nas redes') +
                  '</span></span>' +
                  '<span class="pressao-fluxo-images-arrow" aria-hidden="true"></span></button>'
                : '';

            var linkDisplay = share.link || '';
            var imagesItems = imagens
                .map(function (img, index) {
                    return (
                        '<div class="pressao-fluxo-image-item" data-index="' +
                        index +
                        '">' +
                        '<div class="pressao-fluxo-image-thumb-wrap">' +
                        '<span class="pressao-fluxo-image-thumb" style="background-image:url(\'' +
                        escapeAttr(img.thumb || img.url) +
                        '\')"></span>' +
                        '<button type="button" class="pressao-fluxo-image-download" data-index="' +
                        index +
                        '">BAIXAR</button>' +
                        '</div>' +
                        '<span class="pressao-fluxo-image-rotulo">' +
                        escapeHtml(img.rotulo || '') +
                        '</span></div>'
                    );
                })
                .join('');

            var downloadAllBtn = imagens.length
                ? '<button type="button" class="pressao-fluxo-btn pressao-fluxo-btn-primary" data-fluxo-download-all>' +
                  'Baixar todas as imagens' +
                  '<span class="pressao-fluxo-btn-download" aria-hidden="true"></span></button>'
                : '';

            mount.innerHTML =
                '<div class="pressao-fluxo-share-main" data-fluxo-share-main>' +
                '<h2 class="pressao-fluxo-title">' +
                escapeHtml('Convide mais pessoas') +
                '</h2>' +
                '<p class="pressao-fluxo-subtitle">' +
                escapeHtml(
                    'Quanto mais gente participar, maior a pressão pela Tarifa Zero. Você pode compartilhar com amigos ou marcar mais parlamentares.'
                ) +
                '</p>' +
                '<button type="button" class="pressao-fluxo-link-row" data-fluxo-copy-link aria-label="Copiar link">' +
                '<span class="pressao-fluxo-link-text">' +
                escapeHtml(linkDisplay) +
                '</span>' +
                '<span class="pressao-fluxo-copy-link" data-fluxo-copy-btn>' +
                '<span class="pressao-fluxo-copy-icon" aria-hidden="true"></span>' +
                '<span class="pressao-fluxo-copy-label">Copiar link</span></span></button>' +
                (socialHtml ? '<div class="pressao-fluxo-share-social-row">' + socialHtml + '</div>' : '') +
                imagesCard +
                '<button type="button" class="pressao-fluxo-btn pressao-fluxo-btn-secondary" data-fluxo-reset>' +
                'Pressionar outros candidatos</button>' +
                '</div>' +
                '<div class="pressao-fluxo-images-screen" data-fluxo-images-screen hidden>' +
                '<header class="pressao-fluxo-images-header">' +
                '<button type="button" class="pressao-fluxo-back" data-fluxo-images-back aria-label="Voltar"></button>' +
                '<h3 class="pressao-fluxo-nav-title">' +
                escapeHtml(share.imagens_titulo || 'Imagens para postar') +
                '</h3></header>' +
                '<p class="pressao-fluxo-subtitle">' +
                escapeHtml(
                    share.imagens_instrucao ||
                        'Utilize nossas imagens nas suas redes para que outras pessoas conheçam a campanha:'
                ) +
                '</p>' +
                '<div class="pressao-fluxo-images-grid">' +
                imagesItems +
                '</div>' +
                downloadAllBtn +
                '</div>';

            function setCopiedState(isCopied) {
                var btn = mount.querySelector('[data-fluxo-copy-btn]');
                var label = mount.querySelector('.pressao-fluxo-copy-label');
                var icon = mount.querySelector('.pressao-fluxo-copy-icon');
                if (!btn || !label) {
                    return;
                }
                if (isCopied) {
                    btn.classList.add('is-copied');
                    label.textContent = 'Copiado!';
                    if (icon) {
                        icon.classList.add('is-check');
                    }
                } else {
                    btn.classList.remove('is-copied');
                    label.textContent = 'Copiar link';
                    if (icon) {
                        icon.classList.remove('is-check');
                    }
                }
            }

            function copyLink() {
                var link = share.link || '';
                if (!link) {
                    return;
                }
                var done = function () {
                    setCopiedState(true);
                    setTimeout(function () {
                        setCopiedState(false);
                    }, 1800);
                };
                if (navigator.clipboard && navigator.clipboard.writeText) {
                    navigator.clipboard.writeText(link).then(done).catch(done);
                } else {
                    done();
                }
            }

            var copyRow = mount.querySelector('[data-fluxo-copy-link]');
            if (copyRow) {
                copyRow.addEventListener('click', function (e) {
                    e.preventDefault();
                    copyLink();
                });
            }

            var openImages = mount.querySelector('[data-fluxo-images-open]');
            var imagesScr = mount.querySelector('[data-fluxo-images-screen]');
            var mainScr = mount.querySelector('[data-fluxo-share-main]');
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
            var backImages = mount.querySelector('[data-fluxo-images-back]');
            if (backImages && imagesScr && mainScr) {
                backImages.addEventListener('click', function () {
                    imagesScr.hidden = true;
                    imagesScr.classList.remove('is-entering');
                    mainScr.hidden = false;
                });
            }

            var resetBtn = mount.querySelector('[data-fluxo-reset]');
            if (resetBtn) {
                resetBtn.addEventListener('click', resetFluxo);
            }

            function handleFluxoImageAction(index) {
                if (isNaN(index) || !imagens[index]) {
                    return;
                }
                if (window.PressaoShareImages && typeof window.PressaoShareImages.downloadOrShareOne === 'function') {
                    window.PressaoShareImages.downloadOrShareOne(imagens[index], index);
                }
            }

            mount.querySelectorAll('.pressao-fluxo-image-item').forEach(function (item) {
                item.addEventListener('click', function (e) {
                    e.preventDefault();
                    var index = parseInt(item.getAttribute('data-index'), 10);
                    handleFluxoImageAction(index);
                });
            });

            var downloadAll = mount.querySelector('[data-fluxo-download-all]');
            if (downloadAll) {
                downloadAll.addEventListener('click', function () {
                    if (window.PressaoShareImages && typeof window.PressaoShareImages.downloadOrShareAll === 'function') {
                        window.PressaoShareImages.downloadOrShareAll(imagens);
                    }
                });
            }
        }

        function syncSelectedFromTom() {
            state.selectedIds = tom ? tom.getValue() : [];
        }

        function goToAcao() {
            syncSelectedFromTom();
            if (!state.selectedIds.length) {
                alert('Selecione ao menos um candidato para continuar.');
                return;
            }
            renderChips();
            renderMessage();
            showScreen('acao');
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
         * Mobile: tenta abrir o app Instagram sem nova aba do browser, para o
         * X/voltar do app devolver à página do fluxo (já em confirmação).
         * Android usa Intent; iOS dispara Universal Link via <a> sem target.
         * Desktop: window.open em nova aba.
         */
        function openInstagramApp(webUrl) {
            if (!webUrl) {
                return;
            }

            if (!isMobileBrowser()) {
                window.open(webUrl, '_blank', 'noopener,noreferrer');
                return;
            }

            if (isAndroidBrowser()) {
                var path = webUrl.replace(/^https?:\/\//i, '');
                var intentUrl =
                    'intent://' +
                    path +
                    '#Intent;scheme=https;package=com.instagram.android;S.browser_fallback_url=' +
                    encodeURIComponent(webUrl) +
                    ';end';
                window.location.href = intentUrl;
                return;
            }

            // iOS e demais mobile: Universal Links costumam abrir o app e
            // deixar o Safari/Chrome na página; sem target=_blank.
            var anchor = document.createElement('a');
            anchor.href = webUrl;
            anchor.rel = 'noopener noreferrer';
            document.body.appendChild(anchor);
            anchor.click();
            document.body.removeChild(anchor);
        }

        function copiarEAbrir() {
            var texto = buildMessage();
            var url = config.contato_url || '';
            var useCountdown = !!config.countdown_abrir;
            var copiarBtns = queryAll('[data-fluxo-copiar]');

            var setCopiarDisabled = function (disabled) {
                copiarBtns.forEach(function (btn) {
                    btn.disabled = disabled;
                });
            };

            var openUrl = function () {
                openInstagramApp(url);
            };

            var afterCopy = function () {
                if (!useCountdown) {
                    openUrl();
                    showScreen('confirmacao');
                    return;
                }

                setCopiarDisabled(true);
                showToastCountdown(
                    'Mensagem copiada! Abrindo o Instagram…',
                    function (n) {
                        return 'Abrindo em ' + n + '… Agora é só colar nos comentários da publicação.';
                    },
                    REDIRECT_COUNTDOWN_S
                ).then(function () {
                    openUrl();
                    hideToast();
                    showScreen('confirmacao');
                    setCopiarDisabled(false);
                });
            };

            if (texto && navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(texto).then(afterCopy).catch(afterCopy);
            } else {
                afterCopy();
            }
        }

        function setFormError(msg) {
            if (!formError) {
                return;
            }
            if (!msg) {
                formError.hidden = true;
                formError.textContent = '';
                return;
            }
            formError.hidden = false;
            formError.textContent = msg;
        }

        function setFormBusy(busy) {
            state.submitting = busy;
            queryAll('[data-fluxo-receber], [data-fluxo-agora-nao]').forEach(function (btn) {
                btn.disabled = busy;
            });
        }

        function criarEConfirmar(ativista) {
            if (state.submitting) {
                return Promise.resolve();
            }
            setFormBusy(true);
            setFormError('');

            var alvoId = config.alvo_id;
            var campanhaId = config.campanha_id;
            var canal = config.canal || 'instagram';

            function realizar(nonce) {
                return postAjax({
                    action: 'pressao_realizar_acao',
                    alvo_id: alvoId,
                    campanha_id: campanhaId,
                    canal: canal,
                    template_id: config.template_id || '',
                    nonce: nonce,
                    sessao_id: getOrCreateSessaoId(),
                    ativista_nome: (ativista && ativista.nome) || '',
                    ativista_email: (ativista && ativista.email) || '',
                    ativista_telefone: (ativista && ativista.telefone) || ''
                });
            }

            function confirmar(nonce, acaoId) {
                return postAjax({
                    action: 'pressao_confirmar_acao',
                    acao_id: acaoId,
                    alvo_id: alvoId,
                    campanha_id: campanhaId,
                    nonce: nonce
                });
            }

            return refreshNonce(root)
                .then(function (nonce) {
                    return realizar(nonce).then(function (response) {
                        if (response.success) {
                            return response;
                        }
                        var message = (response.data && response.data.message) || 'Erro ao criar ação';
                        if (isNonceError(message)) {
                            return refreshNonce(root).then(function (fresh) {
                                return realizar(fresh);
                            });
                        }
                        return response;
                    });
                })
                .then(function (response) {
                    if (!response.success) {
                        throw new Error((response.data && response.data.message) || 'Erro ao criar ação');
                    }
                    var apiData = (response.data && response.data.data) || {};
                    var acaoId = (response.data && response.data.acao_id) || apiData.acao_id;
                    if (!acaoId) {
                        throw new Error('ID da ação não encontrado.');
                    }
                    return refreshNonce(root).then(function (nonce) {
                        return confirmar(nonce, acaoId).then(function (confResponse) {
                            if (confResponse.success) {
                                return { create: response, confirm: confResponse, acaoId: acaoId };
                            }
                            var message =
                                (confResponse.data && confResponse.data.message) || 'Erro ao confirmar ação';
                            if (isNonceError(message)) {
                                return refreshNonce(root).then(function (fresh) {
                                    return confirmar(fresh, acaoId).then(function (retry) {
                                        if (!retry.success) {
                                            throw new Error(
                                                (retry.data && retry.data.message) || 'Erro ao confirmar ação'
                                            );
                                        }
                                        return { create: response, confirm: retry, acaoId: acaoId };
                                    });
                                });
                            }
                            throw new Error(message);
                        });
                    });
                })
                .then(function (result) {
                    var confData = (result.confirm.data && result.confirm.data) || {};
                    saveActionCookie(alvoId, {
                        timestamp: Math.floor(Date.now() / 1000),
                        acao_id: result.acaoId,
                        status: 'CONCLUIDA'
                    });
                    if (confData.acoes_confirmadas != null) {
                        updateCounter(campanhaId, confData.acoes_confirmadas);
                    } else {
                        updateCounter(campanhaId);
                    }
                    return showToast(
                        'Comentário publicado!',
                        'Sua participação foi contabilizada.'
                    ).then(function () {
                        renderShare();
                        showScreen('share');
                    });
                })
                .catch(function (err) {
                    console.error(err);
                    setFormError(err.message || 'Não foi possível registrar a ação. Tente novamente.');
                })
                .finally(function () {
                    setFormBusy(false);
                });
        }

        function resetFluxo() {
            state.selectedIds = [];
            if (tom) {
                tom.clear(true);
            }
            var form = queryOne('[data-fluxo-form]');
            if (form) {
                form.reset();
            }
            setFormError('');
            showScreen('inicio');
        }

        if (selectEl && typeof TomSelect !== 'undefined') {
            tom = new TomSelect(selectEl, {
                plugins: ['remove_button'],
                maxItems: config.limite_candidatos || 5,
                maxOptions: null,
                searchField: ['text'],
                placeholder: selectEl.getAttribute('placeholder') || 'Digite o nome ou @ do Instagram',
                render: {
                    no_results: function () {
                        return '<div class="no-results">Não encontramos resultados para sua busca</div>';
                    },
                    option: function (data, escape) {
                        var opt = selectEl.querySelector('option[value="' + data.value + '"]');
                        var img = opt ? opt.getAttribute('data-imagem') : '';
                        var handle = opt ? opt.getAttribute('data-instagram') : '';
                        var avatar = img
                            ? '<span class="pressao-fluxo-ts-avatar" style="background-image:url(\'' +
                              escape(img) +
                              '\')"></span>'
                            : '<span class="pressao-fluxo-ts-avatar is-empty"></span>';
                        return (
                            '<div class="pressao-fluxo-ts-option">' +
                            avatar +
                            '<span>' +
                            escape(data.text) +
                            (handle ? '' : '') +
                            '</span></div>'
                        );
                    },
                    item: function (data, escape) {
                        var opt = selectEl.querySelector('option[value="' + data.value + '"]');
                        var img = opt ? opt.getAttribute('data-imagem') : '';
                        var handle = opt ? opt.getAttribute('data-instagram') : data.text;
                        var avatar = img
                            ? '<span class="pressao-fluxo-ts-avatar" style="background-image:url(\'' +
                              escape(img) +
                              '\')"></span>'
                            : '<span class="pressao-fluxo-ts-avatar is-empty"></span>';
                        return (
                            '<div class="pressao-fluxo-ts-item">' +
                            avatar +
                            '<span>' +
                            escape(handle) +
                            '</span></div>'
                        );
                    }
                },
                onItemAdd: function () {
                    syncSelectedFromTom();
                    this.setTextboxValue('');
                    this.refreshOptions(false);
                },
                onItemRemove: function () {
                    syncSelectedFromTom();
                },
                onChange: function () {
                    syncSelectedFromTom();
                    this.setTextboxValue('');
                }
            });
        }

        function openHelp() {
            if (!helpOverlay) {
                return;
            }
            helpOverlay.hidden = false;
            helpOverlay.classList.remove('is-closing');
            requestAnimationFrame(function () {
                helpOverlay.classList.add('is-open');
            });
        }

        function closeHelp() {
            if (!helpOverlay || helpOverlay.hidden) {
                return;
            }
            helpOverlay.classList.add('is-closing');
            helpOverlay.classList.remove('is-open');
            setTimeout(function () {
                helpOverlay.hidden = true;
                helpOverlay.classList.remove('is-closing');
            }, 200);
        }

        root.querySelectorAll('[data-fluxo-open-lista]').forEach(function (btn) {
            btn.addEventListener('click', openLista);
        });
        root.querySelectorAll('[data-fluxo-lista-close]').forEach(function (el) {
            el.addEventListener('click', closeLista);
        });
        root.querySelectorAll('[data-fluxo-open-help]').forEach(function (btn) {
            btn.addEventListener('click', openHelp);
        });
        root.querySelectorAll('[data-fluxo-help-close]').forEach(function (el) {
            el.addEventListener('click', closeHelp);
        });
        root.querySelectorAll('[data-fluxo-continuar]').forEach(function (btn) {
            btn.addEventListener('click', goToAcao);
        });
        root.querySelectorAll('[data-fluxo-back-inicio]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                showScreen('inicio');
            });
        });
        root.querySelectorAll('[data-fluxo-copiar]').forEach(function (btn) {
            btn.addEventListener('click', copiarEAbrir);
        });
        root.querySelectorAll('[data-fluxo-to-acao], [data-fluxo-tentar-novamente]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                showScreen('acao');
            });
        });
        root.querySelectorAll('[data-fluxo-sim-publiquei]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                showScreen('form');
            });
        });

        var form = root.querySelector('[data-fluxo-form]');
        if (form) {
            bindPhoneMask(form.querySelector('input[name="telefone"]'));
            form.addEventListener('submit', function (e) {
                e.preventDefault();
                var nome = (form.nome && form.nome.value || '').trim();
                var email = (form.email && form.email.value || '').trim();
                var telefone = digitsOnly(form.telefone ? form.telefone.value : '');
                if (!nome || !email) {
                    setFormError('Preencha nome e email para receber atualizações.');
                    return;
                }
                criarEConfirmar({ nome: nome, email: email, telefone: telefone });
            });
        }

        root.querySelectorAll('[data-fluxo-agora-nao]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                criarEConfirmar(null);
            });
        });

        // Modal bloqueante pós-Continuar: sem Escape fechando o card.
        // Lista de candidatos continua fechável por backdrop/X.
        document.addEventListener('keydown', function (e) {
            if (e.key !== 'Escape') {
                return;
            }
            if (helpOverlay && !helpOverlay.hidden) {
                closeHelp();
                return;
            }
            if (listaOverlay && !listaOverlay.hidden) {
                closeLista();
            }
        });

        // Troca mobile↔desktop com a sequência aberta: ajusta scroll e início.
        var mobileMq = window.matchMedia('(max-width: 767px)');
        var onViewportChange = function () {
            if (!root.classList.contains('is-seq-open') || root.dataset.fluxoStep === 'inicio') {
                return;
            }
            var inicio = root.querySelector('.pressao-fluxo-screen[data-screen="inicio"]');
            syncSeqOverlayPortal();
            if (mobileMq.matches) {
                lockBodyScroll();
                if (inicio) {
                    inicio.hidden = false;
                    inicio.classList.add('is-active');
                }
                if (seqOverlay && !seqOverlay.classList.contains('is-open')) {
                    seqOverlay.classList.add('is-open');
                }
            } else {
                unlockBodyScroll();
                if (inicio) {
                    inicio.hidden = false;
                    inicio.classList.add('is-active');
                }
            }
        };
        if (typeof mobileMq.addEventListener === 'function') {
            mobileMq.addEventListener('change', onViewportChange);
        } else if (typeof mobileMq.addListener === 'function') {
            mobileMq.addListener(onViewportChange);
        }

        // O tema envolve o conteúdo da página em `#content`/.miolo-site com
        // `position: relative; z-index: 2;`, o que cria um novo contexto de
        // empilhamento — nenhum z-index daqui de dentro (nem 100050) compete
        // com o que fica FORA dessa seção. O header do site é irmão dessa
        // seção, fixo, com z-index:99, e sempre vence essa comparação
        // externa, cobrindo os overlays de tela cheia do widget. Corrigir
        // aumentando o z-index aqui não resolveria — o único jeito é escapar
        // desse contexto preso, movendo os overlays pra filhos diretos do
        // <body>. Só lista/help/toast entram aqui, incondicionalmente,
        // porque são sempre position:fixed (qualquer largura de tela) — o
        // seqOverlay é tratado à parte, via syncSeqOverlayPortal(), porque no
        // desktop ele é um item flex inline dentro do card, não um overlay.
        // Feito só aqui, no fim da inicialização — de propósito, depois de
        // TODOS os binds de evento acima (que fazem root.querySelectorAll
        // pra achar os botões/campos de dentro desses overlays); mover antes
        // faria essas buscas não encontrarem mais nada, já que os elementos
        // já teriam saído de dentro de `root`.
        [listaOverlay, helpOverlay, toastEl].forEach(function (el) {
            if (el && el.parentElement !== document.body) {
                document.body.appendChild(el);
            }
        });
    }

    function boot() {
        document.querySelectorAll('.pressao-fluxo[data-pressao-fluxo]').forEach(initFluxo);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }
})();
