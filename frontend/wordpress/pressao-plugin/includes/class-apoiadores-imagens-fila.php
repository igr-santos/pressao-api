<?php
/**
 * Fila de imagens do import CSV de apoiadores: processada em lotes via AJAX,
 * depois que os candidatos já foram salvos.
 *
 * @package PressaoPlugin
 */

if (!defined('ABSPATH')) {
    exit;
}

class PressaoPlugin_Apoiadores_Imagens_Fila {

    const OPTION = 'pressao_apoiadores_imagens_fila';
    const LOCK = 'pressao_apoiadores_imagens_lock';
    const LOCK_TTL = 120;
    const NONCE_ACTION = 'pressao_apoiadores_imagens';
    const MAX_TENTATIVAS = 2;
    const ORCAMENTO_SEGUNDOS = 10.0;

    public function __construct() {
        add_action('wp_ajax_pressao_apoiadores_imagens_processar', [$this, 'ajax_processar']);
        add_action('wp_ajax_pressao_apoiadores_imagens_status', [$this, 'ajax_status']);
        add_action('wp_ajax_pressao_apoiadores_imagens_retentar', [$this, 'ajax_retentar']);
    }

    /**
     * Lê a fila direto do banco (outra requisição pode ter alterado).
     *
     * @return array{total: int, concluidas: int, jobs: array<string, array>, atualizado_em: int}
     */
    private static function ler() {
        wp_cache_delete(self::OPTION, 'options');
        $fila = get_option(self::OPTION, []);
        if (!is_array($fila)) {
            $fila = [];
        }
        return [
            'total' => (int) ($fila['total'] ?? 0),
            'concluidas' => (int) ($fila['concluidas'] ?? 0),
            'jobs' => isset($fila['jobs']) && is_array($fila['jobs']) ? $fila['jobs'] : [],
            'atualizado_em' => (int) ($fila['atualizado_em'] ?? 0),
        ];
    }

    private static function gravar(array $fila) {
        if (empty($fila['jobs'])) {
            delete_option(self::OPTION);
            return;
        }
        $fila['total'] = $fila['concluidas'] + count($fila['jobs']);
        $fila['atualizado_em'] = time();
        update_option(self::OPTION, $fila, false);
    }

    /**
     * @param array<string, array{url: string, titulo: string}> $jobs Indexado por @handle.
     */
    public static function enfileirar(array $jobs) {
        if (empty($jobs)) {
            return;
        }
        $fila = self::ler();
        $tem_pendente = false;
        foreach ($fila['jobs'] as $job) {
            if (($job['status'] ?? '') === 'pendente') {
                $tem_pendente = true;
                break;
            }
        }
        if (!$tem_pendente) {
            $fila['concluidas'] = 0;
        }

        foreach ($jobs as $handle => $job) {
            unset($fila['jobs'][$handle]);
            $fila['jobs'][$handle] = [
                'url' => (string) $job['url'],
                'titulo' => (string) ($job['titulo'] ?? $handle),
                'status' => 'pendente',
                'tentativas' => 0,
                'erro' => '',
            ];
        }
        self::gravar($fila);
    }

    /**
     * Remove o job de um @ (valor de imagem definido manualmente/REST vence a fila).
     *
     * @param string $handle
     */
    public static function descartar($handle) {
        $fila = self::ler();
        if (!isset($fila['jobs'][$handle])) {
            return;
        }
        unset($fila['jobs'][$handle]);
        self::gravar($fila);
    }

    /**
     * @return array{total: int, concluidas: int, pendentes: int, erros: array<int, array{handle: string, titulo: string, erro: string}>}
     */
    public static function status() {
        $fila = self::ler();
        $pendentes = 0;
        $erros = [];
        foreach ($fila['jobs'] as $handle => $job) {
            if (($job['status'] ?? '') === 'erro') {
                $erros[] = [
                    'handle' => (string) $handle,
                    'titulo' => (string) ($job['titulo'] ?? ''),
                    'erro' => (string) ($job['erro'] ?? ''),
                ];
            } else {
                $pendentes++;
            }
        }
        return [
            'total' => $fila['total'],
            'concluidas' => $fila['concluidas'],
            'pendentes' => $pendentes,
            'erros' => $erros,
        ];
    }

    public static function retentar_erros() {
        $fila = self::ler();
        foreach ($fila['jobs'] as $handle => $job) {
            if (($job['status'] ?? '') === 'erro') {
                $fila['jobs'][$handle]['status'] = 'pendente';
                $fila['jobs'][$handle]['tentativas'] = 0;
                $fila['jobs'][$handle]['erro'] = '';
            }
        }
        self::gravar($fila);
    }

    /**
     * Lock atômico por site (mesmo padrão do WP_Upgrader::create_lock).
     */
    private static function adquirir_lock() {
        global $wpdb;
        $inserido = $wpdb->query($wpdb->prepare(
            "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')",
            self::LOCK,
            (string) time()
        ));
        if ($inserido) {
            return true;
        }
        $desde = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s",
            self::LOCK
        ));
        if ($desde && $desde > time() - self::LOCK_TTL) {
            return false;
        }
        $wpdb->query($wpdb->prepare(
            "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s",
            (string) time(),
            self::LOCK,
            (string) $desde
        ));
        return (bool) $wpdb->rows_affected;
    }

    private static function liberar_lock() {
        delete_option(self::LOCK);
    }

    /**
     * Processa imagens pendentes até estourar o orçamento de tempo (mínimo 1).
     *
     * @param float $orcamento Segundos.
     * @return array status() + ocupado (bool) + processadas (int)
     */
    public static function processar_lote($orcamento = self::ORCAMENTO_SEGUNDOS) {
        if (!self::adquirir_lock()) {
            return array_merge(self::status(), ['ocupado' => true, 'processadas' => 0]);
        }

        $inicio = microtime(true);
        $processadas = 0;
        $ultima_duracao = 0.0;

        try {
            // Só começa outra imagem se, pela duração da última, ela ainda cabe no orçamento.
            while ($processadas === 0 || (microtime(true) - $inicio) + $ultima_duracao < $orcamento) {
                $fila = self::ler();
                $handle = null;
                foreach ($fila['jobs'] as $h => $job) {
                    if (($job['status'] ?? '') === 'pendente') {
                        $handle = (string) $h;
                        break;
                    }
                }
                if ($handle === null) {
                    break;
                }

                $job = $fila['jobs'][$handle];
                $inicio_imagem = microtime(true);
                $resultado = PressaoPlugin_Candidatos_Import::sideload_image($job['url'], $job['titulo'] ?: $handle);
                $ultima_duracao = microtime(true) - $inicio_imagem;
                $processadas++;

                // Relê: um reimport ou edição pode ter mudado a fila durante o download.
                $fila = self::ler();
                $atual = $fila['jobs'][$handle] ?? null;
                if (!$atual || ($atual['url'] ?? '') !== $job['url']) {
                    continue;
                }

                if (is_wp_error($resultado)) {
                    $tentativas = (int) ($atual['tentativas'] ?? 0) + 1;
                    unset($fila['jobs'][$handle]);
                    $fila['jobs'][$handle] = array_merge($atual, [
                        'tentativas' => $tentativas,
                        'status' => $tentativas >= self::MAX_TENTATIVAS ? 'erro' : 'pendente',
                        'erro' => $resultado->get_error_message(),
                    ]);
                } else {
                    self::aplicar_imagem($handle, (int) $resultado);
                    unset($fila['jobs'][$handle]);
                    $fila['concluidas']++;
                }
                self::gravar($fila);
            }
        } finally {
            self::liberar_lock();
        }

        return array_merge(self::status(), ['ocupado' => false, 'processadas' => $processadas]);
    }

    /**
     * Grava só o imagem_id do @ na base (se o @ ainda existir).
     */
    private static function aplicar_imagem($handle, $attachment_id) {
        wp_cache_delete(PressaoPlugin_Candidatos_Import::OPTION, 'options');
        $lista = PressaoPlugin_Candidatos_Import::get_apoiadores();
        $alterou = false;
        foreach ($lista as $i => $item) {
            if (!is_array($item)) {
                continue;
            }
            if (PressaoPlugin_Candidatos_Import::normalize_handle($item['link_url'] ?? '') === $handle) {
                $lista[$i]['imagem_id'] = $attachment_id;
                $alterou = true;
            }
        }
        if ($alterou) {
            update_option(PressaoPlugin_Candidatos_Import::OPTION, array_values($lista), false);
        }
    }

    private function assert_can_manage() {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Sem permissão.', 'pressao-plugin')], 403);
        }
        check_ajax_referer(self::NONCE_ACTION, 'nonce');
    }

    public function ajax_processar() {
        $this->assert_can_manage();
        wp_send_json_success(self::processar_lote());
    }

    public function ajax_status() {
        $this->assert_can_manage();
        wp_send_json_success(self::status());
    }

    public function ajax_retentar() {
        $this->assert_can_manage();
        self::retentar_erros();
        wp_send_json_success(self::status());
    }

    /**
     * Card de progresso na aba Apoiadores (só quando há fila).
     */
    public static function render_admin_card() {
        $status = self::status();
        if ($status['total'] === 0) {
            return;
        }
        ?>
        <div class="pressao-admin-card pressao-imagens-fila"
             data-nonce="<?php echo esc_attr(wp_create_nonce(self::NONCE_ACTION)); ?>"
             data-status="<?php echo esc_attr(wp_json_encode($status)); ?>">
            <h3><?php esc_html_e('Imagens do import', 'pressao-plugin'); ?></h3>
            <p class="description">
                <?php esc_html_e('Os candidatos já foram salvos. As fotos são baixadas aos poucos e aparecem na lista conforme o progresso avança. Se você sair desta página, o processamento continua quando a aba Apoiadores for aberta de novo.', 'pressao-plugin'); ?>
            </p>
            <progress class="pressao-imagens-fila-barra"
                      max="<?php echo esc_attr((string) max(1, $status['total'])); ?>"
                      value="<?php echo esc_attr((string) $status['concluidas']); ?>"></progress>
            <p class="pressao-imagens-fila-contagem" aria-live="polite"></p>
            <p class="pressao-imagens-fila-estado" aria-live="polite"></p>
            <div class="pressao-imagens-fila-erros" hidden>
                <p><strong><?php esc_html_e('Imagens com erro', 'pressao-plugin'); ?></strong></p>
                <ul></ul>
                <p class="pressao-admin-actions">
                    <button type="button" class="button pressao-imagens-fila-retentar">
                        <?php esc_html_e('Tentar novamente', 'pressao-plugin'); ?>
                    </button>
                </p>
            </div>
            <p class="pressao-admin-actions">
                <button type="button" class="button pressao-imagens-fila-continuar" hidden>
                    <?php esc_html_e('Continuar', 'pressao-plugin'); ?>
                </button>
            </p>
        </div>
        <?php
    }
}

new PressaoPlugin_Apoiadores_Imagens_Fila();
