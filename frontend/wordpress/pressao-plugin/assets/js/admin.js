(function($) {
    function renameFields($item, index) {
        $item.attr('data-index', index);
        $item.find('[name]').each(function() {
            this.name = this.name.replace(/pressao_candidatos\[\d+\]/, 'pressao_candidatos[' + index + ']');
        });
    }

    function clearCandidate($item) {
        $item.find('input[type="text"], input[type="url"], textarea').val('');
        $item.find('.pressao-candidato-image-id').val('');
        $item.find('.pressao-candidato-image-preview').empty();
    }

    $(document).on('click', '.pressao-add-candidato', function(e) {
        e.preventDefault();

        const $container = $(this).closest('.pressao-candidatos-admin');
        const $list = $container.find('.pressao-candidatos-list');
        const nextIndex = parseInt($container.attr('data-next-index'), 10) || 0;
        const $first = $list.find('.pressao-candidato-admin-item').first();

        if (!$first.length) {
            return;
        }

        const $newItem = $first.clone();
        renameFields($newItem, nextIndex);
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
})(jQuery);
