<?php
/**
 * Import CSV e remoção da base de candidatos apoiadores.
 *
 * @package PressaoPlugin
 */

if (!defined('ABSPATH')) {
    exit;
}

class PressaoPlugin_Candidatos_Import {

    const OPTION = 'pressao_candidatos_apoiadores';
    const ACTION_IMPORT = 'pressao_import_apoiadores_csv';
    const ACTION_REMOVE = 'pressao_remove_apoiadores';

    public function __construct() {
        add_action('admin_post_' . self::ACTION_IMPORT, [$this, 'handle_import']);
        add_action('admin_post_' . self::ACTION_REMOVE, [$this, 'handle_remove']);
        add_action('admin_notices', [$this, 'render_admin_notices']);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function get_apoiadores() {
        $list = get_option(self::OPTION, []);
        return is_array($list) ? $list : [];
    }

    /**
     * Normaliza handle Instagram (aceita URL ou @user).
     */
    public static function normalize_handle($value) {
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
     * Indexa lista por handle normalizado.
     *
     * @param array<int, array<string, mixed>> $list
     * @return array<string, array<string, mixed>>
     */
    public static function index_by_handle(array $list) {
        $map = [];
        foreach ($list as $item) {
            if (!is_array($item)) {
                continue;
            }
            $handle = self::normalize_handle($item['link_url'] ?? '');
            if ($handle === '') {
                continue;
            }
            $item['link_url'] = $handle;
            $map[$handle] = $item;
        }
        return $map;
    }

    public function handle_import() {
        if (!current_user_can('manage_options')) {
            wp_die(__('Sem permissão.', 'pressao-plugin'));
        }

        check_admin_referer(self::ACTION_IMPORT);

        $redirect = admin_url('options-general.php?page=pressao-settings&tab=apoiadores');

        if (empty($_FILES['pressao_apoiadores_csv']['tmp_name'])) {
            $this->set_notice('error', __('Selecione um arquivo CSV para importar.', 'pressao-plugin'));
            wp_safe_redirect($redirect);
            exit;
        }

        $file = $_FILES['pressao_apoiadores_csv'];
        if (!empty($file['error'])) {
            $this->set_notice('error', __('Falha no upload do CSV.', 'pressao-plugin'));
            wp_safe_redirect($redirect);
            exit;
        }

        $path = $file['tmp_name'];
        $handle_file = fopen($path, 'rb');
        if (!$handle_file) {
            $this->set_notice('error', __('Não foi possível ler o CSV.', 'pressao-plugin'));
            wp_safe_redirect($redirect);
            exit;
        }

        $first_line = fgets($handle_file);
        if ($first_line === false) {
            fclose($handle_file);
            $this->set_notice('error', __('CSV vazio.', 'pressao-plugin'));
            wp_safe_redirect($redirect);
            exit;
        }

        if (strncmp($first_line, "\xEF\xBB\xBF", 3) === 0) {
            $first_line = substr($first_line, 3);
        }

        $delimiter = (substr_count($first_line, ';') > substr_count($first_line, ',')) ? ';' : ',';
        $headers = str_getcsv(trim($first_line), $delimiter);
        $headers = array_map([$this, 'normalize_header'], $headers);

        $required_map = [
            'nome' => ['nome', 'name'],
            'cargo' => ['cargo', 'role'],
            'partido' => ['partido', 'partido_organizacao', 'partido/organizacao', 'organizacao'],
            'descricao' => ['descricao', 'descrição', 'description'],
            'instagram' => ['instagram', 'link_url', 'handle', '@'],
            'imagem_url' => ['imagem_url', 'imagem', 'image_url', 'image', 'foto', 'foto_url'],
        ];

        $col = [];
        foreach ($required_map as $key => $aliases) {
            foreach ($aliases as $alias) {
                $idx = array_search($alias, $headers, true);
                if ($idx !== false) {
                    $col[$key] = $idx;
                    break;
                }
            }
        }

        if (!isset($col['instagram'])) {
            fclose($handle_file);
            $this->set_notice(
                'error',
                __('CSV precisa de uma coluna instagram (ou link_url).', 'pressao-plugin')
            );
            wp_safe_redirect($redirect);
            exit;
        }

        $map = self::index_by_handle(self::get_apoiadores());
        $added = 0;
        $updated = 0;
        $images_ok = 0;
        $errors = [];
        $row_num = 1;

        while (($row = fgetcsv($handle_file, 0, $delimiter)) !== false) {
            $row_num++;
            if ($this->row_is_empty($row)) {
                continue;
            }

            $instagram_raw = isset($col['instagram'], $row[$col['instagram']]) ? $row[$col['instagram']] : '';
            $handle = self::normalize_handle($instagram_raw);
            if ($handle === '') {
                $errors[] = sprintf(
                    /* translators: %d: CSV row number */
                    __('Linha %d: Instagram inválido ou ausente.', 'pressao-plugin'),
                    $row_num
                );
                continue;
            }

            $is_new = !isset($map[$handle]);
            $existing = $is_new ? [] : $map[$handle];

            $nome = isset($col['nome'], $row[$col['nome']])
                ? sanitize_text_field($row[$col['nome']])
                : ($existing['nome'] ?? '');
            $cargo = isset($col['cargo'], $row[$col['cargo']])
                ? sanitize_text_field($row[$col['cargo']])
                : ($existing['cargo'] ?? '');
            $partido = isset($col['partido'], $row[$col['partido']])
                ? sanitize_text_field($row[$col['partido']])
                : ($existing['partido'] ?? '');
            $descricao = isset($col['descricao'], $row[$col['descricao']])
                ? sanitize_textarea_field($row[$col['descricao']])
                : ($existing['descricao'] ?? '');

            $imagem_id = absint($existing['imagem_id'] ?? 0);
            $imagem_url = '';
            if (isset($col['imagem_url'], $row[$col['imagem_url']])) {
                $imagem_url = trim((string) $row[$col['imagem_url']]);
            }

            if ($imagem_url !== '') {
                $sideload = $this->sideload_image($imagem_url, $nome !== '' ? $nome : $handle);
                if (is_wp_error($sideload)) {
                    $errors[] = sprintf(
                        /* translators: 1: row number, 2: error message */
                        __('Linha %1$d: imagem — %2$s', 'pressao-plugin'),
                        $row_num,
                        $sideload->get_error_message()
                    );
                } else {
                    $imagem_id = (int) $sideload;
                    $images_ok++;
                }
            }

            $map[$handle] = [
                'nome' => $nome,
                'cargo' => $cargo,
                'partido' => $partido,
                'descricao' => $descricao,
                'link_url' => $handle,
                'imagem_id' => $imagem_id,
            ];

            if ($is_new) {
                $added++;
            } else {
                $updated++;
            }
        }

        fclose($handle_file);

        update_option(self::OPTION, array_values($map), false);

        $summary = sprintf(
            /* translators: 1: added count, 2: updated count, 3: images ok */
            __('Import concluído: %1$d adicionados, %2$d atualizados, %3$d imagens ok.', 'pressao-plugin'),
            $added,
            $updated,
            $images_ok
        );

        if (!empty($errors)) {
            $max = 8;
            $shown = array_slice($errors, 0, $max);
            $summary .= ' ' . implode(' ', $shown);
            if (count($errors) > $max) {
                $summary .= ' ' . sprintf(
                    /* translators: %d: remaining error count */
                    __('(+%d avisos)', 'pressao-plugin'),
                    count($errors) - $max
                );
            }
            $this->set_notice('warning', $summary);
        } else {
            $this->set_notice('success', $summary);
        }

        wp_safe_redirect($redirect);
        exit;
    }

    public function handle_remove() {
        if (!current_user_can('manage_options')) {
            wp_die(__('Sem permissão.', 'pressao-plugin'));
        }

        check_admin_referer(self::ACTION_REMOVE);

        $redirect = admin_url('options-general.php?page=pressao-settings&tab=apoiadores');
        $selected = isset($_POST['pressao_apoiadores_remove']) ? (array) $_POST['pressao_apoiadores_remove'] : [];
        $handles = [];
        foreach ($selected as $raw) {
            $h = self::normalize_handle(wp_unslash($raw));
            if ($h !== '') {
                $handles[$h] = true;
            }
        }

        if (empty($handles)) {
            $this->set_notice('error', __('Selecione ao menos um candidato para remover.', 'pressao-plugin'));
            wp_safe_redirect($redirect);
            exit;
        }

        $map = self::index_by_handle(self::get_apoiadores());
        $removed = 0;
        foreach (array_keys($handles) as $h) {
            if (isset($map[$h])) {
                unset($map[$h]);
                $removed++;
            }
        }

        update_option(self::OPTION, array_values($map), false);

        $this->set_notice(
            'success',
            sprintf(
                /* translators: %d: removed count */
                _n('%d candidato removido da base.', '%d candidatos removidos da base.', $removed, 'pressao-plugin'),
                $removed
            )
        );

        wp_safe_redirect($redirect);
        exit;
    }

    public function render_admin_notices() {
        if (!isset($_GET['page']) || $_GET['page'] !== 'pressao-settings') {
            return;
        }
        if (!current_user_can('manage_options')) {
            return;
        }

        $notice = get_transient('pressao_apoiadores_notice');
        if (!is_array($notice) || empty($notice['message'])) {
            return;
        }
        delete_transient('pressao_apoiadores_notice');

        $type = in_array($notice['type'] ?? '', ['success', 'error', 'warning', 'info'], true)
            ? $notice['type']
            : 'info';
        printf(
            '<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>',
            esc_attr($type),
            esc_html($notice['message'])
        );
    }

    private function set_notice($type, $message) {
        set_transient(
            'pressao_apoiadores_notice',
            [
                'type' => $type,
                'message' => $message,
            ],
            60
        );
    }

    private function normalize_header($header) {
        $header = strtolower(trim((string) $header));
        $header = str_replace([' ', '-'], '_', $header);
        return $header;
    }

    private function row_is_empty(array $row) {
        foreach ($row as $cell) {
            if (trim((string) $cell) !== '') {
                return false;
            }
        }
        return true;
    }

    /**
     * @return int|WP_Error attachment ID
     */
    private function sideload_image($url, $title) {
        $url = esc_url_raw($url);
        if ($url === '' || !preg_match('#^https?://#i', $url)) {
            return new WP_Error('invalid_url', __('URL de imagem inválida.', 'pressao-plugin'));
        }

        if (!function_exists('download_url')) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }
        if (!function_exists('media_handle_sideload')) {
            require_once ABSPATH . 'wp-admin/includes/media.php';
            require_once ABSPATH . 'wp-admin/includes/image.php';
        }

        $tmp = download_url($url, 30);
        if (is_wp_error($tmp)) {
            return $tmp;
        }

        $path = wp_parse_url($url, PHP_URL_PATH);
        $filename = $path ? basename($path) : 'candidato.jpg';
        $filename = sanitize_file_name($filename);
        if ($filename === '' || strpos($filename, '.') === false) {
            $filename = 'candidato.jpg';
        }

        $file_array = [
            'name' => $filename,
            'tmp_name' => $tmp,
        ];

        add_filter('upload_dir', [$this, 'filter_upload_dir_candidatos']);
        $attachment_id = media_handle_sideload($file_array, 0, $title);
        remove_filter('upload_dir', [$this, 'filter_upload_dir_candidatos']);

        if (is_wp_error($attachment_id)) {
            @unlink($tmp);
            return $attachment_id;
        }

        return (int) $attachment_id;
    }

    public function filter_upload_dir_candidatos($uploads) {
        $subdir = '/candidatos';
        $uploads['subdir'] = $subdir . ($uploads['subdir'] ?? '');
        $uploads['path'] = ($uploads['basedir'] ?? '') . $uploads['subdir'];
        $uploads['url'] = ($uploads['baseurl'] ?? '') . $uploads['subdir'];
        return $uploads;
    }
}

new PressaoPlugin_Candidatos_Import();
