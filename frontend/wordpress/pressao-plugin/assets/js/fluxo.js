/**
 * Pressão Plugin — fluxo único sequencial (Instagram v1)
 * Isolado de widget.js / [pressao_alvos].
 */
(function () {
    'use strict';

    var Core = window.PressaoCore;
    var TOAST_MS = 2800;
    var REDIRECT_COUNTDOWN_S = 3;

    var escapeHtml = Core.escapeHtml;
    var escapeAttr = Core.escapeAttr;
    var digitsOnly = Core.digitsOnly;
    var bindPhoneMask = Core.bindPhoneMask;
    var updateCounter = Core.updateCounter;
    var saveActionCookie = Core.saveActionCookie;

    function parseConfig(root) {
        var raw = root.getAttribute('data-pressao-fluxo') || '{}';
        try {
            return JSON.parse(raw);
        } catch (e) {
            console.error('pressao_fluxo: config inválida', e);
            return null;
        }
    }


    function initFluxo(root) {
        var config = parseConfig(root);
        if (!config) {
            return;
        }

        var state = {
            selectedIds: [],
            submitting: false,
            modo: 'busca'
        };

        var selectEl = root.querySelector('[data-fluxo-select]');
        var selectEstadoEl = root.querySelector('[data-fluxo-select-estado]');
        var estadoEl = root.querySelector('[data-fluxo-estado]');
        var cargosEl = root.querySelector('[data-fluxo-cargos]');
        var cargosListEl = root.querySelector('[data-fluxo-cargos-list]');
        var listaOverlay = root.querySelector('[data-fluxo-lista]');
        var helpOverlay = root.querySelector('[data-fluxo-help]');
        var seqOverlay = root.querySelector('[data-fluxo-seq]');
        var toastEl = root.querySelector('[data-fluxo-toast]');
        var toastTitle = root.querySelector('[data-fluxo-toast-title]');
        var toastText = root.querySelector('[data-fluxo-toast-text]');
        var formError = root.querySelector('[data-fluxo-form-error]');
        var tom = null;
        var tomEstado = null;
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
            if (!toastEl) {
                return Promise.resolve();
            }
            toastTitle.textContent = title || '';
            toastEl.hidden = false;
            toastEl.classList.add('is-visible');
            return Core.countdown(seconds, function (remaining) {
                toastText.textContent = typeof buildTextFn === 'function' ? buildTextFn(remaining) : '';
                if (typeof onTick === 'function') {
                    onTick(remaining);
                }
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
            return Core.montarMensagemComHandles(handles, config.template_conteudo);
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
            Core.renderShare(queryOne('[data-fluxo-share]'), config.share || {}, {
                prefix: 'pressao-fluxo',
                dataPrefix: 'fluxo',
                // Layout do fluxo sempre exibe os 3 canais; link opcional (admin).
                redes: ['whatsapp', 'instagram', 'messenger'],
                titulo: 'Convide mais pessoas',
                subtitulo: 'Quanto mais gente participar, maior a pressão pela Tarifa Zero. Você pode compartilhar com amigos ou marcar mais parlamentares.',
                resetLabel: 'Pressionar outros candidatos',
                onReset: resetFluxo
            });
        }

        function syncSelectedFromTom() {
            var ativo = state.modo === 'estado' ? tomEstado : tom;
            state.selectedIds = ativo ? ativo.getValue() : [];
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
                Core.openAppUrl(url, 'instagram');
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

            Core.copyText(texto).then(afterCopy);
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

            return Core.criarEConfirmarAcao({
                root: root,
                alvoId: alvoId,
                campanhaId: campanhaId,
                canal: canal,
                templateId: config.template_id || '',
                ativista: ativista
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
            clearModo('busca');
            clearModo('estado');
            openModo('busca');
            var form = queryOne('[data-fluxo-form]');
            if (form) {
                form.reset();
            }
            setFormError('');
            showScreen('inicio');
        }

        function candidatoAvatar(c, escape) {
            return c.imagem
                ? '<span class="pressao-fluxo-ts-avatar" style="background-image:url(\'' + escape(c.imagem) + '\')"></span>'
                : '';
        }

        function renderCandidatoOption(data, escape) {
            var c = candidatosById[data.value] || {};
            var nome = c.nome || c.instagram || data.text;
            var meta = [c.nome ? c.instagram : '', c.cargo, c.partido].filter(Boolean).join(' · ');
            return (
                '<div class="pressao-fluxo-ts-option' +
                (c.imagem ? '' : ' is-text-only') +
                '">' +
                candidatoAvatar(c, escape) +
                '<span class="pressao-fluxo-ts-option-text">' +
                '<span class="pressao-fluxo-ts-option-nome">' + escape(nome) + '</span>' +
                (meta ? '<span class="pressao-fluxo-ts-option-meta">' + escape(meta) + '</span>' : '') +
                '</span></div>'
            );
        }

        function renderCandidatoItem(data, escape) {
            var c = candidatosById[data.value] || {};
            return (
                '<div class="pressao-fluxo-ts-item' +
                (c.imagem ? '' : ' is-text-only') +
                '">' +
                candidatoAvatar(c, escape) +
                '<span>' + escape(c.instagram || data.text) + '</span></div>'
            );
        }

        function createCandidatoSelect(el, modo) {
            return new TomSelect(el, {
                plugins: ['remove_button'],
                maxItems: config.limite_candidatos || 5,
                maxOptions: null,
                searchField: ['text'],
                placeholder: el.getAttribute('placeholder') || 'Digite o nome ou @ do Instagram',
                render: {
                    no_results: function () {
                        return '<div class="no-results">Não encontramos resultados para sua busca</div>';
                    },
                    option: renderCandidatoOption,
                    item: renderCandidatoItem
                },
                onItemAdd: function () {
                    onModoInput(modo);
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

        function filtroDoEstado(uf) {
            var filtros = config.filtros || [];
            for (var i = 0; i < filtros.length; i++) {
                if (filtros[i].uf === uf) {
                    return filtros[i];
                }
            }
            return null;
        }

        function cargosMarcados() {
            if (!cargosListEl) {
                return [];
            }
            return Array.prototype.slice
                .call(cargosListEl.querySelectorAll('input[type="checkbox"]:checked'))
                .map(function (input) {
                    return input.value;
                });
        }

        function renderCargos(uf) {
            if (!cargosEl || !cargosListEl) {
                return;
            }
            cargosListEl.innerHTML = '';
            var filtro = filtroDoEstado(uf);
            var cargos = filtro && filtro.cargos ? filtro.cargos : [];
            cargos.forEach(function (cargo) {
                var label = document.createElement('label');
                label.className = 'pressao-fluxo-cargo';
                var input = document.createElement('input');
                input.type = 'checkbox';
                input.value = cargo.chave;
                input.addEventListener('change', function () {
                    onModoInput('estado');
                    aplicarFiltroEstado();
                });
                var text = document.createElement('span');
                text.textContent = cargo.label;
                label.appendChild(input);
                label.appendChild(text);
                cargosListEl.appendChild(label);
            });
            cargosEl.hidden = cargos.length === 0;
        }

        function aplicarFiltroEstado() {
            if (!tomEstado || !estadoEl) {
                return;
            }
            var uf = estadoEl.value;
            var cargos = cargosMarcados();
            var permitidos = {};
            var opcoes = [];
            (config.candidatos || []).forEach(function (c) {
                if (!uf || c.estado !== uf) {
                    return;
                }
                if (cargos.length && cargos.indexOf(c.cargo_chave) === -1) {
                    return;
                }
                permitidos[c.id] = true;
                opcoes.push({ value: c.id, text: (c.nome ? c.nome + ' ' : '') + c.instagram });
            });
            tomEstado.getValue().forEach(function (id) {
                if (!permitidos[id]) {
                    tomEstado.removeItem(id, true);
                }
            });
            tomEstado.clearOptions();
            tomEstado.addOptions(opcoes);
            tomEstado.refreshOptions(false);
            syncSelectedFromTom();
        }

        function resetFiltroEstado() {
            if (cargosListEl) {
                cargosListEl.innerHTML = '';
            }
            if (cargosEl) {
                cargosEl.hidden = true;
            }
            if (tomEstado) {
                tomEstado.clear(true);
                tomEstado.clearOptions();
                tomEstado.disable();
            }
        }

        function clearModo(modo) {
            if (modo === 'busca') {
                if (tom) {
                    tom.clear(true);
                }
            } else {
                if (estadoEl) {
                    estadoEl.value = '';
                }
                resetFiltroEstado();
            }
            syncSelectedFromTom();
        }

        // Preencher uma opção limpa o que foi preenchido na outra.
        function onModoInput(modo) {
            clearModo(modo === 'busca' ? 'estado' : 'busca');
        }

        function openModo(modo) {
            state.modo = modo;
            root.querySelectorAll('[data-fluxo-modo]').forEach(function (section) {
                var open = section.getAttribute('data-fluxo-modo') === modo;
                section.classList.toggle('is-open', open);
                var toggle = section.querySelector('[data-fluxo-modo-toggle]');
                if (toggle) {
                    toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
                }
                var body = section.querySelector('.pressao-fluxo-modo-body');
                if (body) {
                    body.hidden = !open;
                }
            });
            [tom, tomEstado].forEach(function (t) {
                if (t) {
                    t.close();
                }
            });
            syncSelectedFromTom();
        }

        if (typeof TomSelect !== 'undefined') {
            if (selectEl) {
                tom = createCandidatoSelect(selectEl, 'busca');
            }
            if (selectEstadoEl) {
                tomEstado = createCandidatoSelect(selectEstadoEl, 'estado');
                tomEstado.disable();
            }
        }

        root.querySelectorAll('[data-fluxo-modo-toggle]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                openModo(btn.getAttribute('data-fluxo-modo-toggle'));
            });
        });

        if (estadoEl) {
            estadoEl.addEventListener('change', function () {
                onModoInput('estado');
                var uf = estadoEl.value;
                if (!uf) {
                    resetFiltroEstado();
                    syncSelectedFromTom();
                    return;
                }
                renderCargos(uf);
                if (tomEstado) {
                    tomEstado.enable();
                }
                aplicarFiltroEstado();
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
