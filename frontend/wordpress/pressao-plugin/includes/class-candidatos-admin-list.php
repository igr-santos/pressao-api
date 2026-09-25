<?php
/**
 * Listagem admin de candidatos: tabela, expand, busca, paginação e CRUD AJAX.
 *
 * @package PressaoPlugin
 */

if (!defined('ABSPATH')) {
    exit;
}

class PressaoPlugin_Candidatos_Admin_List {

    const PER_PAGE = 20;
    const NONCE_ACTION = 'pressao_candidatos_admin_list';

    const OPTION_PRESSIONAR = 'pressao_candidatos';
    const OPTION_APOIADORES = 'pressao_candidatos_apoiadores';

    public function __construct() {
        add_action('wp_ajax_pressao_candidato_save', [$this, 'ajax_save']);
        add_action('wp_ajax_pressao_candidato_delete', [$this, 'ajax_delete']);
        add_action('wp_ajax_pressao_candidato_add', [$this, 'ajax_add']);
    }

    /**
     * @return string[]
     */
    public static function allowed_options() {
        return [self::OPTION_PRESSIONAR, self::OPTION_APOIADORES];
    }

    /**
     * @param string $option_name
     * @return bool
     */
    public static function is_allowed_option($option_name) {
        return in_array($option_name, self::allowed_options(), true);
    }

    /**
     * @param string $option_name
     * @return array<int, array>
     */
    public static function get_items($option_name) {
        if (!self::is_allowed_option($option_name)) {
            return [];
        }
        $items = get_option($option_name, []);
        return is_array($items) ? $items : [];
    }

    /**
     * Filtra preservando índices originais da option.
     *
     * @param array<int, array> $items
     * @param string            $search
     * @return array<int, array>
     */
    public static function filter_items(array $items, $search) {
        $search = trim((string) $search);
        if ($search === '') {
            return $items;
        }

        $needle = function_exists('mb_strtolower')
            ? mb_strtolower($search, 'UTF-8')
            : strtolower($search);

        $filtered = [];
        foreach ($items as $index => $item) {
            if (!is_array($item)) {
                continue;
            }
            $haystack = implode(' ', [
                (string) ($item['nome'] ?? ''),
                (string) ($item['cargo'] ?? ''),
                (string) ($item['partido'] ?? ''),
                (string) ($item['link_url'] ?? ''),
            ]);
            $haystack = function_exists('mb_strtolower')
                ? mb_strtolower($haystack, 'UTF-8')
                : strtolower($haystack);

            if ($needle !== '' && strpos($haystack, $needle) === false) {
                continue;
            }
            $filtered[(int) $index] = $item;
        }

        return $filtered;
    }

    /**
     * @param array<int, array> $items
     * @param int               $page
     * @param int               $per_page
     * @return array{items: array<int, array>, total: int, pages: int, page: int}
     */
    public static function paginate(array $items, $page, $per_page = self::PER_PAGE) {
        $per_page = max(1, (int) $per_page);
        $total = count($items);
        $pages = max(1, (int) ceil($total / $per_page));
        $page = max(1, min((int) $page, $pages));
        $offset = ($page - 1) * $per_page;
        $slice = array_slice($items, $offset, $per_page, true);

        return [
            'items' => $slice,
            'total' => $total,
            'pages' => $pages,
            'page' => $page,
        ];
    }

    /**
     * @param mixed $value
     * @return string
     */
    public static function sanitize_instagram_handle($value) {
        $value = trim((string) $value);
        if ($value === '') {
            return '';
        }

        if (preg_match('#instagram\.com/([^/?#]+)#i', $value, $matches)) {
            $value = $matches[1];
        }

        $value = ltrim($value, '@');
        $value = preg_replace('/[^A-Za-z0-9._]/', '', $value);
        if ($value === '') {
            return '';
        }

        return '@' . $value;
    }

    /**
     * Sanitiza um item (permite vazio — rascunho na listagem admin).
     *
     * @param mixed $candidato
     * @return array{nome: string, cargo: string, partido: string, descricao: string, link_url: string, imagem_id: int}
     */
    public static function sanitize_item($candidato) {
        if (!is_array($candidato)) {
            $candidato = [];
        }

        return [
            'nome' => sanitize_text_field($candidato['nome'] ?? ''),
            'cargo' => sanitize_text_field($candidato['cargo'] ?? ''),
            'partido' => sanitize_text_field($candidato['partido'] ?? ''),
            'descricao' => sanitize_textarea_field($candidato['descricao'] ?? ''),
            'link_url' => self::sanitize_instagram_handle($candidato['link_url'] ?? ''),
            'imagem_id' => absint($candidato['imagem_id'] ?? 0),
        ];
    }

    /**
     * @param mixed $value
     * @return array<int, array>
     */
    public static function sanitize_list($value) {
        if (!is_array($value)) {
            return [];
        }

        $sanitized = [];
        foreach ($value as $candidato) {
            $item = self::sanitize_item($candidato);
            if (
                $item['nome'] === ''
                && $item['cargo'] === ''
                && $item['partido'] === ''
                && $item['descricao'] === ''
                && $item['link_url'] === ''
                && !$item['imagem_id']
            ) {
                continue;
            }
            $sanitized[] = $item;
        }

        return $sanitized;
    }

    /**
     * @param string $option_name
     * @return string candidatos|apoiadores
     */
    public static function tab_for_option($option_name) {
        return $option_name === self::OPTION_APOIADORES ? 'apoiadores' : 'candidatos';
    }

    private function assert_can_manage() {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Sem permissão.', 'pressao-plugin')], 403);
        }
        check_ajax_referer(self::NONCE_ACTION, 'nonce');
    }

    /**
     * @return string
     */
    private function require_option_from_request() {
        $option = isset($_POST['option']) ? sanitize_key(wp_unslash($_POST['option'])) : '';
        if (!self::is_allowed_option($option)) {
            wp_send_json_error(['message' => __('Option inválida.', 'pressao-plugin')], 400);
        }
        return $option;
    }

    public function ajax_save() {
        $this->assert_can_manage();
        $option = $this->require_option_from_request();
        $index = isset($_POST['index']) ? (int) $_POST['index'] : -1;

        $items = array_values(self::get_items($option));
        if ($index < 0 || !array_key_exists($index, $items)) {
            wp_send_json_error(['message' => __('Item não encontrado.', 'pressao-plugin')], 404);
        }

        $raw = isset($_POST['candidato']) && is_array($_POST['candidato'])
            ? wp_unslash($_POST['candidato'])
            : [];
        $item = self::sanitize_item($raw);
        $items[$index] = $item;
        update_option($option, array_values($items), false);

        $thumb = '';
        if (!empty($item['imagem_id'])) {
            $thumb = (string) wp_get_attachment_image_url((int) $item['imagem_id'], 'thumbnail');
        }

        wp_send_json_success([
            'index' => $index,
            'item' => $item,
            'thumb' => $thumb,
            'message' => __('Candidato salvo.', 'pressao-plugin'),
        ]);
    }

    public function ajax_delete() {
        $this->assert_can_manage();
        $option = $this->require_option_from_request();
        $index = isset($_POST['index']) ? (int) $_POST['index'] : -1;

        $items = array_values(self::get_items($option));
        if ($index < 0 || !array_key_exists($index, $items)) {
            wp_send_json_error(['message' => __('Item não encontrado.', 'pressao-plugin')], 404);
        }

        unset($items[$index]);
        $items = array_values($items);
        update_option($option, $items, false);

        wp_send_json_success([
            'message' => __('Candidato removido.', 'pressao-plugin'),
            'total' => count($items),
        ]);
    }

    public function ajax_add() {
        $this->assert_can_manage();
        $option = $this->require_option_from_request();

        $items = array_values(self::get_items($option));
        $items[] = self::sanitize_item([]);
        update_option($option, $items, false);

        $index = count($items) - 1;
        $page = (int) ceil(($index + 1) / self::PER_PAGE);
        $tab = self::tab_for_option($option);

        $url = add_query_arg(
            [
                'page' => 'pressao-settings',
                'tab' => $tab,
                'cpage' => $page,
                'cs' => '',
                'expand' => $index,
            ],
            admin_url('options-general.php')
        );

        wp_send_json_success([
            'index' => $index,
            'page' => $page,
            'redirect' => $url,
            'message' => __('Candidato adicionado.', 'pressao-plugin'),
        ]);
    }

    /**
     * Renderiza a listagem (busca + tabela + paginação).
     *
     * @param string $option_name
     */
    public static function render($option_name) {
        if (!self::is_allowed_option($option_name)) {
            return;
        }

        $tab = self::tab_for_option($option_name);
        $search = isset($_GET['cs']) ? sanitize_text_field(wp_unslash($_GET['cs'])) : '';
        $page = isset($_GET['cpage']) ? max(1, absint($_GET['cpage'])) : 1;
        $expand = isset($_GET['expand']) ? (int) $_GET['expand'] : -1;

        $all = self::get_items($option_name);
        // Normaliza índices contíguos na leitura da UI.
        $all = array_values($all);
        $filtered = self::filter_items($all, $search);
        $paged = self::paginate($filtered, $page, self::PER_PAGE);

        $base_url = admin_url('options-general.php');
        $nonce = wp_create_nonce(self::NONCE_ACTION);
        ?>
        <div class="pressao-admin-list"
             data-option="<?php echo esc_attr($option_name); ?>"
             data-nonce="<?php echo esc_attr($nonce); ?>"
             data-tab="<?php echo esc_attr($tab); ?>">

            <div class="pressao-admin-list-toolbar">
                <form method="get" class="pressao-admin-list-search" action="<?php echo esc_url($base_url); ?>">
                    <input type="hidden" name="page" value="pressao-settings" />
                    <input type="hidden" name="tab" value="<?php echo esc_attr($tab); ?>" />
                    <label class="screen-reader-text" for="pressao-admin-list-cs-<?php echo esc_attr($option_name); ?>">
                        <?php esc_html_e('Buscar candidatos', 'pressao-plugin'); ?>
                    </label>
                    <input type="search"
                           id="pressao-admin-list-cs-<?php echo esc_attr($option_name); ?>"
                           name="cs"
                           value="<?php echo esc_attr($search); ?>"
                           placeholder="<?php esc_attr_e('Buscar por nome, cargo, partido ou @', 'pressao-plugin'); ?>"
                           class="regular-text" />
                    <?php submit_button(__('Buscar', 'pressao-plugin'), 'secondary', '', false); ?>
                    <?php if ($search !== '') : ?>
                        <a class="button"
                           href="<?php echo esc_url(add_query_arg(['page' => 'pressao-settings', 'tab' => $tab], $base_url)); ?>">
                            <?php esc_html_e('Limpar', 'pressao-plugin'); ?>
                        </a>
                    <?php endif; ?>
                </form>
                <p class="pressao-admin-list-count description">
                    <?php
                    printf(
                        /* translators: 1: visible count, 2: total in option */
                        esc_html__('%1$d exibido(s) de %2$d', 'pressao-plugin'),
                        (int) count($paged['items']),
                        (int) count($all)
                    );
                    if ($search !== '') {
                        printf(
                            ' · ' . esc_html__('%d resultado(s) na busca', 'pressao-plugin'),
                            (int) $paged['total']
                        );
                    }
                    ?>
                </p>
            </div>

            <table class="wp-list-table widefat striped pressao-admin-list-table">
                <thead>
                    <tr>
                        <th scope="col" class="column-thumb"><?php esc_html_e('Foto', 'pressao-plugin'); ?></th>
                        <th scope="col"><?php esc_html_e('Nome', 'pressao-plugin'); ?></th>
                        <th scope="col"><?php esc_html_e('Cargo', 'pressao-plugin'); ?></th>
                        <th scope="col"><?php esc_html_e('Partido', 'pressao-plugin'); ?></th>
                        <th scope="col"><?php esc_html_e('Instagram', 'pressao-plugin'); ?></th>
                        <th scope="col" class="column-actions"><?php esc_html_e('Ações', 'pressao-plugin'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($paged['items'])) : ?>
                        <tr class="pressao-admin-list-empty">
                            <td colspan="6">
                                <?php
                                echo $search !== ''
                                    ? esc_html__('Nenhum candidato encontrado para esta busca.', 'pressao-plugin')
                                    : esc_html__('Nenhum candidato cadastrado. Use “Adicionar candidato”.', 'pressao-plugin');
                                ?>
                            </td>
                        </tr>
                    <?php else : ?>
                        <?php foreach ($paged['items'] as $index => $candidato) : ?>
                            <?php
                            $candidato = is_array($candidato) ? $candidato : [];
                            $imagem_id = absint($candidato['imagem_id'] ?? 0);
                            $thumb = $imagem_id ? wp_get_attachment_image_url($imagem_id, 'thumbnail') : '';
                            $is_expanded = ((int) $index === $expand);
                            ?>
                            <tr class="pressao-admin-list-row<?php echo $is_expanded ? ' is-expanded' : ''; ?>"
                                data-index="<?php echo esc_attr((string) $index); ?>">
                                <td class="column-thumb">
                                    <span class="pressao-admin-list-thumb">
                                        <?php if ($thumb) : ?>
                                            <img src="<?php echo esc_url($thumb); ?>" alt="" />
                                        <?php else : ?>
                                            <span class="pressao-admin-list-thumb-empty" aria-hidden="true">—</span>
                                        <?php endif; ?>
                                    </span>
                                </td>
                                <td class="column-nome" data-field="nome"><?php echo esc_html($candidato['nome'] ?? ''); ?></td>
                                <td class="column-cargo" data-field="cargo"><?php echo esc_html($candidato['cargo'] ?? ''); ?></td>
                                <td class="column-partido" data-field="partido"><?php echo esc_html($candidato['partido'] ?? ''); ?></td>
                                <td class="column-instagram" data-field="link_url"><?php echo esc_html($candidato['link_url'] ?? ''); ?></td>
                                <td class="column-actions">
                                    <button type="button" class="button-link pressao-admin-list-toggle">
                                        <?php esc_html_e('Editar', 'pressao-plugin'); ?>
                                    </button>
                                    |
                                    <button type="button" class="button-link link-delete pressao-admin-list-delete">
                                        <?php esc_html_e('Remover', 'pressao-plugin'); ?>
                                    </button>
                                </td>
                            </tr>
                            <tr class="pressao-admin-list-editor<?php echo $is_expanded ? ' is-open' : ''; ?>"
                                data-index="<?php echo esc_attr((string) $index); ?>"
                                <?php echo $is_expanded ? '' : 'hidden'; ?>>
                                <td colspan="6">
                                    <?php self::render_editor_fields($candidato, (int) $index); ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>

            <?php if ($paged['pages'] > 1) : ?>
                <div class="pressao-admin-list-pagination tablenav">
                    <div class="tablenav-pages">
                        <?php
                        $pagination_base = remove_query_arg(['cpage', 'expand']);
                        echo wp_kses_post(
                            paginate_links([
                                'base' => esc_url_raw(add_query_arg('cpage', '%#%', $pagination_base)),
                                'format' => '',
                                'current' => $paged['page'],
                                'total' => $paged['pages'],
                                'prev_text' => '&laquo;',
                                'next_text' => '&raquo;',
                            ])
                        );
                        ?>
                    </div>
                </div>
            <?php endif; ?>

            <p class="pressao-admin-list-footer">
                <button type="button" class="button button-primary pressao-admin-list-add">
                    <?php esc_html_e('Adicionar candidato', 'pressao-plugin'); ?>
                </button>
                <span class="description">
                    <?php esc_html_e('As imagens usam a Biblioteca de Mídia do WordPress. Alterações de cada item são salvas com “Salvar item” (não pelo botão Salvar da aba).', 'pressao-plugin'); ?>
                </span>
            </p>
        </div>
        <?php
    }

    /**
     * @param array $candidato
     * @param int   $index
     */
    private static function render_editor_fields(array $candidato, $index) {
        $imagem_id = absint($candidato['imagem_id'] ?? 0);
        $imagem_url = $imagem_id ? wp_get_attachment_image_url($imagem_id, 'thumbnail') : '';
        ?>
        <div class="pressao-admin-list-editor-fields">
            <div class="pressao-admin-list-editor-grid">
                <p>
                    <label>
                        <?php esc_html_e('Nome', 'pressao-plugin'); ?><br>
                        <input type="text"
                               class="regular-text"
                               data-field="nome"
                               value="<?php echo esc_attr($candidato['nome'] ?? ''); ?>" />
                    </label>
                </p>
                <p>
                    <label>
                        <?php esc_html_e('Cargo', 'pressao-plugin'); ?><br>
                        <input type="text"
                               class="regular-text"
                               data-field="cargo"
                               value="<?php echo esc_attr($candidato['cargo'] ?? ''); ?>" />
                    </label>
                </p>
                <p>
                    <label>
                        <?php esc_html_e('Partido/organização', 'pressao-plugin'); ?><br>
                        <input type="text"
                               class="regular-text"
                               data-field="partido"
                               value="<?php echo esc_attr($candidato['partido'] ?? ''); ?>" />
                    </label>
                </p>
                <p>
                    <label>
                        <?php esc_html_e('Instagram (@)', 'pressao-plugin'); ?><br>
                        <input type="text"
                               class="regular-text"
                               data-field="link_url"
                               value="<?php echo esc_attr($candidato['link_url'] ?? ''); ?>"
                               placeholder="@candidato" />
                    </label>
                </p>
            </div>
            <p>
                <label>
                    <?php esc_html_e('Descrição', 'pressao-plugin'); ?><br>
                    <textarea rows="3"
                              class="large-text"
                              data-field="descricao"><?php echo esc_textarea($candidato['descricao'] ?? ''); ?></textarea>
                </label>
            </p>
            <div class="pressao-candidato-image-field">
                <input type="hidden"
                       class="pressao-candidato-image-id"
                       data-field="imagem_id"
                       value="<?php echo esc_attr((string) $imagem_id); ?>" />
                <div class="pressao-candidato-image-preview">
                    <?php if ($imagem_url) : ?>
                        <img src="<?php echo esc_url($imagem_url); ?>" alt="" />
                    <?php endif; ?>
                </div>
                <button type="button" class="button pressao-select-candidato-image">
                    <?php esc_html_e('Selecionar imagem', 'pressao-plugin'); ?>
                </button>
                <button type="button" class="button pressao-remove-candidato-image">
                    <?php esc_html_e('Remover imagem', 'pressao-plugin'); ?>
                </button>
            </div>
            <p class="pressao-admin-list-editor-actions">
                <button type="button" class="button button-primary pressao-admin-list-save">
                    <?php esc_html_e('Salvar item', 'pressao-plugin'); ?>
                </button>
                <button type="button" class="button pressao-admin-list-cancel">
                    <?php esc_html_e('Fechar', 'pressao-plugin'); ?>
                </button>
                <span class="pressao-admin-list-status" aria-live="polite"></span>
            </p>
        </div>
        <?php
    }
}

new PressaoPlugin_Candidatos_Admin_List();
