(function($) {
    function labels() {
        return window.pressaoAdminData || {};
    }

    function ajaxUrl() {
        return labels().ajaxUrl || (window.ajaxurl || '');
    }

    function closeAllEditors($list) {
        $list.find('.pressao-admin-list-row').removeClass('is-expanded');
        $list.find('.pressao-admin-list-editor').removeClass('is-open').attr('hidden', true);
    }

    function openEditor($row) {
        const $list = $row.closest('.pressao-admin-list');
        const index = $row.attr('data-index');
        closeAllEditors($list);
        $row.addClass('is-expanded');
        $list.find('.pressao-admin-list-editor[data-index="' + index + '"]')
            .addClass('is-open')
            .removeAttr('hidden');
    }

    function collectEditorData($editor) {
        const $fields = $editor.find('.pressao-admin-list-editor-fields');
        return {
            nome: $fields.find('[data-field="nome"]').val() || '',
            cargo: $fields.find('[data-field="cargo"]').val() || '',
            partido: $fields.find('[data-field="partido"]').val() || '',
            link_url: $fields.find('[data-field="link_url"]').val() || '',
            descricao: $fields.find('[data-field="descricao"]').val() || '',
            imagem_id: $fields.find('[data-field="imagem_id"]').val() || '0'
        };
    }

    function updateSummaryRow($row, item, thumb) {
        $row.find('[data-field="nome"]').text(item.nome || '');
        $row.find('[data-field="cargo"]').text(item.cargo || '');
        $row.find('[data-field="partido"]').text(item.partido || '');
        $row.find('[data-field="link_url"]').text(item.link_url || '');

        const $thumb = $row.find('.pressao-admin-list-thumb');
        if (thumb) {
            $thumb.html('<img src="' + thumb + '" alt="" />');
        } else {
            $thumb.html('<span class="pressao-admin-list-thumb-empty" aria-hidden="true">—</span>');
        }
    }

    function setStatus($editor, text, isError) {
        const $status = $editor.find('.pressao-admin-list-status');
        $status.text(text || '');
        $status.toggleClass('is-error', !!isError);
    }

    function renameShareImageFields($item, index) {
        $item.attr('data-index', index);
        $item.find('[name]').each(function() {
            this.name = this.name.replace(
                /pressao_compartilhamento\[imagens\]\[\d+\]/,
                'pressao_compartilhamento[imagens][' + index + ']'
            );
        });
    }

    function clearShareImage($item) {
        $item.find('input[type="text"]').val('');
        $item.find('.pressao-share-imagem-id').val('');
        $item.find('.pressao-share-imagem-preview').empty();
    }

    $(document).on('click', '.pressao-admin-list-row .pressao-admin-list-toggle, .pressao-admin-list-row td:not(.column-actions)', function(e) {
        if ($(e.target).closest('.pressao-admin-list-delete, a, button').length && !$(e.target).closest('.pressao-admin-list-toggle').length) {
            return;
        }
        e.preventDefault();
        const $row = $(this).closest('.pressao-admin-list-row');
        if ($row.hasClass('is-expanded')) {
            closeAllEditors($row.closest('.pressao-admin-list'));
            return;
        }
        openEditor($row);
    });

    $(document).on('click', '.pressao-admin-list-cancel', function(e) {
        e.preventDefault();
        closeAllEditors($(this).closest('.pressao-admin-list'));
    });

    $(document).on('click', '.pressao-admin-list-save', function(e) {
        e.preventDefault();
        const $btn = $(this);
        const $list = $btn.closest('.pressao-admin-list');
        const $editor = $btn.closest('.pressao-admin-list-editor');
        const index = $editor.attr('data-index');
        const data = collectEditorData($editor);
        const L = labels();

        setStatus($editor, L.saving || 'Salvando…', false);
        $btn.prop('disabled', true);

        $.post(ajaxUrl(), {
            action: 'pressao_candidato_save',
            nonce: $list.attr('data-nonce'),
            option: $list.attr('data-option'),
            index: index,
            candidato: data
        })
            .done(function(resp) {
                if (!resp || !resp.success) {
                    setStatus($editor, (resp && resp.data && resp.data.message) || L.saveError || 'Erro', true);
                    return;
                }
                const item = resp.data.item || data;
                const $row = $list.find('.pressao-admin-list-row[data-index="' + index + '"]');
                updateSummaryRow($row, item, resp.data.thumb || '');
                setStatus($editor, resp.data.message || L.saved || 'Salvo.', false);
            })
            .fail(function() {
                setStatus($editor, L.saveError || 'Erro', true);
            })
            .always(function() {
                $btn.prop('disabled', false);
            });
    });

    $(document).on('click', '.pressao-admin-list-delete', function(e) {
        e.preventDefault();
        e.stopPropagation();
        const L = labels();
        const msg = L.removeItemConfirm || 'Remover este candidato da lista?';
        if (!window.confirm(msg)) {
            return;
        }

        const $btn = $(this);
        const $list = $btn.closest('.pressao-admin-list');
        const $row = $btn.closest('.pressao-admin-list-row');
        const index = $row.attr('data-index');

        $btn.prop('disabled', true);

        $.post(ajaxUrl(), {
            action: 'pressao_candidato_delete',
            nonce: $list.attr('data-nonce'),
            option: $list.attr('data-option'),
            index: index
        })
            .done(function(resp) {
                if (!resp || !resp.success) {
                    window.alert((resp && resp.data && resp.data.message) || L.deleteError || 'Erro');
                    return;
                }
                window.location.reload();
            })
            .fail(function() {
                window.alert(L.deleteError || 'Erro');
            })
            .always(function() {
                $btn.prop('disabled', false);
            });
    });

    $(document).on('click', '.pressao-admin-list-add', function(e) {
        e.preventDefault();
        const $btn = $(this);
        const $list = $btn.closest('.pressao-admin-list');
        const L = labels();

        $btn.prop('disabled', true);

        $.post(ajaxUrl(), {
            action: 'pressao_candidato_add',
            nonce: $list.attr('data-nonce'),
            option: $list.attr('data-option')
        })
            .done(function(resp) {
                if (!resp || !resp.success || !resp.data || !resp.data.redirect) {
                    window.alert((resp && resp.data && resp.data.message) || L.addError || 'Erro');
                    return;
                }
                window.location.href = resp.data.redirect;
            })
            .fail(function() {
                window.alert(L.addError || 'Erro');
            })
            .always(function() {
                $btn.prop('disabled', false);
            });
    });

    $(document).on('click', '.pressao-select-candidato-image', function(e) {
        e.preventDefault();

        const $field = $(this).closest('.pressao-candidato-image-field');
        const L = labels();
        const frame = wp.media({
            title: L.selectCandidateImage || 'Selecionar imagem do candidato',
            button: {
                text: L.useThisImage || 'Usar esta imagem'
            },
            multiple: false
        });

        frame.on('select', function() {
            const attachment = frame.state().get('selection').first().toJSON();
            const previewUrl = (attachment.sizes && attachment.sizes.thumbnail && attachment.sizes.thumbnail.url)
                || attachment.url;

            $field.find('.pressao-candidato-image-id').val(attachment.id);
            $field.find('.pressao-candidato-image-preview').html(
                '<img src="' + previewUrl + '" alt="" />'
            );
        });

        frame.open();
    });

    $(document).on('click', '.pressao-remove-candidato-image', function(e) {
        e.preventDefault();

        const $field = $(this).closest('.pressao-candidato-image-field');
        $field.find('.pressao-candidato-image-id').val('');
        $field.find('.pressao-candidato-image-preview').empty();
    });

    $(document).on('click', '.pressao-add-share-imagem', function(e) {
        e.preventDefault();

        const $container = $(this).closest('.pressao-share-imagens-admin');
        const $list = $container.find('.pressao-share-imagens-list');
        const nextIndex = parseInt($container.attr('data-next-index'), 10) || 0;
        const $first = $list.find('.pressao-share-imagem-admin-item').first();

        if (!$first.length) {
            return;
        }

        const $newItem = $first.clone();
        renameShareImageFields($newItem, nextIndex);
        clearShareImage($newItem);
        $list.append($newItem);
        $container.attr('data-next-index', nextIndex + 1);
    });

    $(document).on('click', '.pressao-remove-share-imagem', function(e) {
        e.preventDefault();

        const $list = $(this).closest('.pressao-share-imagens-list');
        const $items = $list.find('.pressao-share-imagem-admin-item');

        if ($items.length <= 1) {
            clearShareImage($items.first());
            return;
        }

        $(this).closest('.pressao-share-imagem-admin-item').remove();
    });

    $(document).on('click', '.pressao-select-share-imagem', function(e) {
        e.preventDefault();

        const $field = $(this).closest('.pressao-share-imagem-field');
        const L = labels();
        const frame = wp.media({
            title: L.selectShareImage || 'Selecionar imagem para postar',
            button: {
                text: L.useThisImage || 'Usar esta imagem'
            },
            multiple: false
        });

        frame.on('select', function() {
            const attachment = frame.state().get('selection').first().toJSON();
            const previewUrl = (attachment.sizes && attachment.sizes.thumbnail && attachment.sizes.thumbnail.url)
                || attachment.url;

            $field.find('.pressao-share-imagem-id').val(attachment.id);
            $field.find('.pressao-share-imagem-preview').html(
                '<img src="' + previewUrl + '" alt="" />'
            );
        });

        frame.open();
    });

    $(document).on('click', '.pressao-remove-share-imagem-file', function(e) {
        e.preventDefault();

        const $field = $(this).closest('.pressao-share-imagem-field');
        $field.find('.pressao-share-imagem-id').val('');
        $field.find('.pressao-share-imagem-preview').empty();
    });

    $(function() {
        const $removeSelect = $('#pressao-apoiadores-remove-select');
        if ($removeSelect.length && typeof TomSelect !== 'undefined') {
            new TomSelect('#pressao-apoiadores-remove-select', {
                plugins: ['remove_button'],
                maxItems: null,
                maxOptions: null,
                placeholder: $removeSelect.attr('placeholder') || 'Digite nome ou @',
                searchField: ['text']
            });
        }

        $('.pressao-apoiadores-remove-form').on('submit', function(e) {
            const L = labels();
            const selected = $removeSelect.val();
            if (!selected || !selected.length) {
                e.preventDefault();
                return;
            }
            const msg = L.removeConfirm || 'Remover os candidatos selecionados da base de apoiadores?';
            if (!window.confirm(msg)) {
                e.preventDefault();
            }
        });
    });
})(jQuery);
