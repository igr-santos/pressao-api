(function($) {
    function optionNameFromContainer($container) {
        const name = $container.attr('data-option') || 'pressao_candidatos';
        return name.replace(/[^a-z0-9_]/g, '');
    }

    function renameCandidateFields($item, index, optionName) {
        $item.attr('data-index', index);
        const re = new RegExp(optionName.replace(/[.*+?^${}()|[\]\\]/g, '\\$&') + '\\[\\d+\\]');
        $item.find('[name]').each(function() {
            this.name = this.name.replace(re, optionName + '[' + index + ']');
        });
    }

    function clearCandidate($item) {
        $item.find('input[type="text"], input[type="url"], textarea').val('');
        $item.find('.pressao-candidato-image-id').val('');
        $item.find('.pressao-candidato-image-preview').empty();
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

    $(document).on('click', '.pressao-add-candidato', function(e) {
        e.preventDefault();

        const $container = $(this).closest('.pressao-candidatos-admin');
        const optionName = optionNameFromContainer($container);
        const $list = $container.find('.pressao-candidatos-list');
        const nextIndex = parseInt($container.attr('data-next-index'), 10) || 0;
        const $first = $list.find('.pressao-candidato-admin-item').first();

        if (!$first.length) {
            return;
        }

        const $newItem = $first.clone();
        renameCandidateFields($newItem, nextIndex, optionName);
        clearCandidate($newItem);
        $list.append($newItem);
        $container.attr('data-next-index', nextIndex + 1);
    });

    $(document).on('click', '.pressao-remove-candidato', function(e) {
        e.preventDefault();

        const $list = $(this).closest('.pressao-candidatos-list');
        const $items = $list.find('.pressao-candidato-admin-item');

        if ($items.length <= 1) {
            clearCandidate($items.first());
            return;
        }

        $(this).closest('.pressao-candidato-admin-item').remove();
    });

    $(document).on('click', '.pressao-select-candidato-image', function(e) {
        e.preventDefault();

        const $field = $(this).closest('.pressao-candidato-image-field');
        const labels = window.pressaoAdminData || {};
        const frame = wp.media({
            title: labels.selectCandidateImage || 'Selecionar imagem do candidato',
            button: {
                text: labels.useThisImage || 'Usar esta imagem'
            },
            multiple: false
        });

        frame.on('select', function() {
            const attachment = frame.state().get('selection').first().toJSON();
            const previewUrl = attachment.sizes?.thumbnail?.url || attachment.url;

            $field.find('.pressao-candidato-image-id').val(attachment.id);
            $field.find('.pressao-candidato-image-preview').html(
                '<img src="' + previewUrl + '" alt="" style="max-width: 96px; height: auto;" />'
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
        const labels = window.pressaoAdminData || {};
        const frame = wp.media({
            title: labels.selectShareImage || 'Selecionar imagem para postar',
            button: {
                text: labels.useThisImage || 'Usar esta imagem'
            },
            multiple: false
        });

        frame.on('select', function() {
            const attachment = frame.state().get('selection').first().toJSON();
            const previewUrl = attachment.sizes?.thumbnail?.url || attachment.url;

            $field.find('.pressao-share-imagem-id').val(attachment.id);
            $field.find('.pressao-share-imagem-preview').html(
                '<img src="' + previewUrl + '" alt="" style="max-width: 96px; height: auto;" />'
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
                searchField: ['text'],
                render: {
                    no_results: function () {
                        return '<div class="no-results">Não encontramos resultados para sua busca</div>';
                    }
                }
            });
        }

        $('.pressao-apoiadores-remove-form').on('submit', function(e) {
            const labels = window.pressaoAdminData || {};
            const selected = $removeSelect.val();
            if (!selected || !selected.length) {
                e.preventDefault();
                return;
            }
            const msg = labels.removeConfirm || 'Remover os candidatos selecionados da base de apoiadores?';
            if (!window.confirm(msg)) {
                e.preventDefault();
            }
        });
    });
})(jQuery);
