<?php
/**
 * Classe para gerenciar shortcodes - Com integração da campanha
 * 
 * @package PressaoPlugin
 */

if (!defined('ABSPATH')) {
    exit;
}

class PressaoPlugin_Shortcode {
    
    private $api;
    
    public function __construct() {
        $this->api = new PressaoPlugin_API();
        add_shortcode('pressao_widget', [$this, 'render_widget']);
        add_shortcode('pressao_form', [$this, 'render_form']);
        add_shortcode('pressao_list', [$this, 'render_list']);
        add_shortcode('pressao_alvos', [$this, 'render_alvos']);
        add_shortcode('pressao_contador', [$this, 'render_contador']);
        add_shortcode('pressao_progresso', [$this, 'render_progresso']);
        add_shortcode('pressao_candidatos', [$this, 'render_candidatos']);
    }
    
    /**
     * Renderiza o widget principal com nome da campanha
     */
    public function render_widget($atts) {
        $atts = shortcode_atts([
            'title' => get_option('pressao_widget_title', 'Pressão Widget'),
            'campaign' => get_option('pressao_campaign_id', ''),
            'id' => 'pressao-widget-' . uniqid(),
            'show_campaign_name' => 'yes',
            'cache' => '3600'
        ], $atts, 'pressao_widget');
        
        $title = sanitize_text_field($atts['title']);
        $campaign_id = sanitize_text_field($atts['campaign']);
        $widget_id = sanitize_text_field($atts['id']);
        $show_campaign_name = sanitize_text_field($atts['show_campaign_name']);
        $cache_time = intval($atts['cache']);
        
        $campaign_name = '';
        $campaign_data = null;
        
        if (!empty($campaign_id) && $show_campaign_name === 'yes') {
            $result = $this->api->get_campanha_cached($campaign_id, $cache_time);
            
            if (!is_wp_error($result) && $result['success'] && !empty($result['data'])) {
                $campaign_data = $result['data'];
                $campaign_name = isset($campaign_data['nome']) ? $campaign_data['nome'] : '';
            }
        }
        
        ob_start();
        ?>
        <div id="<?php echo esc_attr($widget_id); ?>" 
             class="pressao-widget-container"
             data-campaign="<?php echo esc_attr($campaign_id); ?>"
             data-widget-id="<?php echo esc_attr($widget_id); ?>"
             data-campaign-data='<?php echo json_encode($campaign_data); ?>'>
            
            <div class="pressao-widget-header">
                <span class="pressao-widget-name">
                    <?php echo esc_html($title); ?>
                </span>
                
                <?php if (!empty($campaign_name)) : ?>
                    <span class="pressao-widget-campaign">
                        <span class="pressao-campaign-separator">|</span>
                        <span class="pressao-campaign-name">
                            <?php echo esc_html($campaign_name); ?>
                        </span>
                    </span>
                <?php endif; ?>
            </div>
            
            <div class="pressao-widget-content" style="display: none;">
            </div>
        </div>
        <?php
        
        return ob_get_clean();
    }
    
    /**
     * Renderiza apenas o formulário
     */
    public function render_form($atts) {
        $atts = shortcode_atts([
            'campaign' => get_option('pressao_campaign_id', ''),
            'button_text' => __('Enviar', 'pressao-plugin'),
            'id' => 'pressao-form-' . uniqid()
        ], $atts, 'pressao_form');
        
        $campaign = sanitize_text_field($atts['campaign']);
        $button_text = sanitize_text_field($atts['button_text']);
        $form_id = sanitize_text_field($atts['id']);
        
        ob_start();
        ?>
        <div id="<?php echo esc_attr($form_id); ?>" 
             class="pressao-form-container"
             data-campaign="<?php echo esc_attr($campaign); ?>">
            
            <div class="pressao-form-name">
                <?php esc_html_e('Formulário Pressão', 'pressao-plugin'); ?>
            </div>
            
            <div class="pressao-form-content" style="display: none;">
            </div>
        </div>
        <?php
        return ob_get_clean();
    }
    
    /**
     * Renderiza apenas a lista
     */
    public function render_list($atts) {
        $atts = shortcode_atts([
            'campaign' => get_option('pressao_campaign_id', ''),
            'limit' => 10,
            'id' => 'pressao-list-' . uniqid()
        ], $atts, 'pressao_list');
        
        $campaign = sanitize_text_field($atts['campaign']);
        $limit = intval($atts['limit']);
        $list_id = sanitize_text_field($atts['id']);
        
        ob_start();
        ?>
        <div id="<?php echo esc_attr($list_id); ?>" 
             class="pressao-list-container"
             data-campaign="<?php echo esc_attr($campaign); ?>"
             data-limit="<?php echo esc_attr($limit); ?>">
            
            <div class="pressao-list-name">
                <?php esc_html_e('Lista Pressão', 'pressao-plugin'); ?>
            </div>
            
            <div class="pressao-list-content" style="display: none;">
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Renderiza a lista de alvos com botões de ação
     */
    public function render_alvos($atts) {
        $atts = shortcode_atts([
            'campaign' => get_option('pressao_campaign_id', ''),
            'limit' => 10,
            'show_contact' => 'yes',
            'show_actions' => 'yes',
            'show_ativista_form' => 'no',
            'action_label' => __('Agir', 'pressao-plugin'),
            'action_done_label' => __('Ação realizada ✓', 'pressao-plugin'),
            'canal' => '', // Filtro (opcional)
            'template_id' => '',
            'show_template' => 'no',
            'cache' => 300,
            'class' => '',
            'id' => 'pressao-alvos-' . uniqid(),
            'ativista_confirm_interval' => 10,
            'ativista_confirm_message' => __('Confirmar identidade', 'pressao-plugin'),
            'ativista_confirm_yes' => __('Sou eu', 'pressao-plugin'),
            'ativista_confirm_no' => __('Não sou eu', 'pressao-plugin')
        ], $atts, 'pressao_alvos');
        
        $campanha_id = sanitize_text_field($atts['campaign']);
        $limit = intval($atts['limit']);
        $show_contact = sanitize_text_field($atts['show_contact']);
        $show_actions = sanitize_text_field($atts['show_actions']);
        $show_ativista_form = sanitize_text_field($atts['show_ativista_form']);
        $action_label = sanitize_text_field($atts['action_label']);
        $action_done_label = sanitize_text_field($atts['action_done_label']);
        $canal_filter = sanitize_text_field($atts['canal']); // Filtro
        $template_id = sanitize_text_field($atts['template_id']);
        $show_template = sanitize_text_field($atts['show_template']);
        // cache=0 sorteia um template novo a cada pageview; > 0 congela o sorteio pelo período
        $cache_time = intval($atts['cache']);
        $class = sanitize_text_field($atts['class']);
        $alvos_id = sanitize_text_field($atts['id']);
        
        if (empty($campanha_id)) {
            return '<p class="pressao-error">' . esc_html__('ID da campanha não informado', 'pressao-plugin') . '</p>';
        }
        
        // Busca alvos com filtro de canal (se fornecido)
        $params = [];
        if (!empty($canal_filter)) {
            $params['canal'] = $canal_filter;
        }
        
        $api = new PressaoPlugin_API();
        $result = $api->get_alvos_cached($campanha_id, $params, $cache_time);
        
        if (is_wp_error($result)) {
            return sprintf(
                '<p class="pressao-error">%s</p>',
                esc_html($result->get_error_message())
            );
        }
        
        if (!$result['success'] || empty($result['data'])) {
            return '<p class="pressao-empty">' . esc_html__('Nenhum alvo encontrado para esta campanha.', 'pressao-plugin') . '</p>';
        }
        
        $alvos = $result['data'];
        
        if ($limit > 0) {
            $alvos = array_slice($alvos, 0, $limit);
        }
        
        $nonce = wp_create_nonce('pressao_acao_nonce');
        
        ob_start();
        ?>
        <div id="<?php echo esc_attr($alvos_id); ?>" 
            class="pressao-alvos <?php echo esc_attr($class); ?>"
            data-campaign="<?php echo esc_attr($campanha_id); ?>"
            data-nonce="<?php echo esc_attr($nonce); ?>"
            data-template-id="<?php echo esc_attr($template_id); ?>"
            data-confirm-interval="<?php echo intval($atts['ativista_confirm_interval']); ?>"
            data-confirm-message="<?php echo esc_attr($atts['ativista_confirm_message']); ?>"
            data-confirm-yes="<?php echo esc_attr($atts['ativista_confirm_yes']); ?>"
            data-confirm-no="<?php echo esc_attr($atts['ativista_confirm_no']); ?>">
            
            <div class="pressao-alvos-header">
                <h3><?php esc_html_e('Alvos da Campanha', 'pressao-plugin'); ?></h3>
                <span class="pressao-alvos-count"><?php echo count($alvos); ?></span>
            </div>
            
            <ul class="pressao-alvos-list">
                <?php foreach ($alvos as $alvo) : 
                    $alvo_id = $alvo['id'];
                    $action_state = $this->get_alvo_action_state($alvo_id);
                    $action_realizada = $this->is_acao_realizada($action_state);
                    // Pega o canal do alvo (prioriza o que vem da API)
                    $canal_alvo = isset($alvo['tipo_contato']) ? $alvo['tipo_contato'] : $canal_filter;
                    // Template sorteado pela API para este alvo; o att do shortcode é fallback
                    $alvo_template = isset($alvo['template']) && is_array($alvo['template'])
                        ? $alvo['template']
                        : null;
                    $alvo_template_id = $alvo_template && !empty($alvo_template['id'])
                        ? $alvo_template['id']
                        : $template_id;
                    $canal_labels = $this->get_canal_list_labels($canal_alvo);
                    $usa_overlay = in_array($canal_alvo, ['email', 'instagram', 'tiktok'], true);
                    $list_title = $canal_labels
                        ? $canal_labels['title']
                        : (isset($alvo['nome']) ? $alvo['nome'] : '');
                    $list_subtitle = '';
                    if ($canal_labels) {
                        $list_subtitle = $canal_labels['subtitle'];
                    } elseif ($show_contact === 'yes') {
                        if (!empty($alvo['modo']) && $alvo['modo'] === 'agregado' && !empty($alvo['total_membros'])) {
                            $list_subtitle = sprintf(
                                _n('%d destinatário', '%d destinatários', (int) $alvo['total_membros'], 'pressao-plugin'),
                                (int) $alvo['total_membros']
                            );
                        } elseif (!empty($alvo['contato'])) {
                            $list_subtitle = $alvo['contato'];
                        }
                    }
                    $template_titulo = $alvo_template && !empty($alvo_template['titulo'])
                        ? $alvo_template['titulo']
                        : '';
                    $template_conteudo = $alvo_template && !empty($alvo_template['conteudo'])
                        ? wp_strip_all_tags($alvo_template['conteudo'])
                        : '';
                ?>
                    <li class="pressao-alvo-item <?php echo $action_realizada ? 'action-done' : ''; ?>" 
                        data-alvo-id="<?php echo esc_attr($alvo_id); ?>"
                        data-alvo-nome="<?php echo esc_attr($alvo['nome'] ?? ''); ?>"
                        data-contato="<?php echo esc_attr($alvo['contato'] ?? ''); ?>"
                        data-canal="<?php echo esc_attr($canal_alvo); ?>"
                        data-template-id="<?php echo esc_attr($alvo_template_id); ?>"
                        data-template-titulo="<?php echo esc_attr($template_titulo); ?>"
                        data-template-conteudo="<?php echo esc_attr($template_conteudo); ?>"
                        data-total-membros="<?php echo esc_attr($alvo['total_membros'] ?? ''); ?>">
                        
                        <div class="pressao-alvo-info">
                            <?php if (!empty($canal_alvo)) : ?>
                                <span class="pressao-alvo-canal">
                                    <span class="pressao-alvo-canal-badge" data-canal="<?php echo esc_attr($canal_alvo); ?>"></span>
                                </span>
                            <?php endif; ?>

                            <div class="pressao-alvo-detalhes">
                                <strong class="pressao-alvo-nome"><?php echo esc_html($list_title); ?></strong>
                                <?php if ($list_subtitle !== '') : ?>
                                    <span class="pressao-alvo-contato"><?php echo esc_html($list_subtitle); ?></span>
                                <?php endif; ?>
                            </div>
                            
                            <?php if ($show_actions === 'yes') : ?>
                                <div class="pressao-alvo-actions">
                                    <?php if ($action_realizada) : ?>
                                        <span class="pressao-action-done">
                                            <?php echo esc_html($action_done_label); ?>
                                            <span class="pressao-action-time">
                                                <?php echo esc_html($this->format_action_time($action_state)); ?>
                                            </span>
                                        </span>
                                    <?php elseif ($usa_overlay) : ?>
                                        <button type="button"
                                                class="pressao-action-button"
                                                data-alvo-id="<?php echo esc_attr($alvo_id); ?>"
                                                data-campaign="<?php echo esc_attr($campanha_id); ?>"
                                                data-canal="<?php echo esc_attr($canal_alvo); ?>">
                                            <?php echo esc_html($action_label); ?>
                                        </button>
                                    <?php elseif ($show_ativista_form === 'yes') : ?>
                                        <button type="button" 
                                                class="pressao-action-toggle"
                                                data-alvo-id="<?php echo esc_attr($alvo_id); ?>"
                                                data-canal="<?php echo esc_attr($canal_alvo); ?>">
                                            <?php echo esc_html($action_label); ?>
                                        </button>
                                        
                                        <div class="pressao-ativista-form" style="display: none;">
                                            <div class="pressao-form-group">
                                                <input type="text" 
                                                       class="pressao-ativista-nome" 
                                                       placeholder="<?php esc_attr_e('Seu nome', 'pressao-plugin'); ?>"
                                                       required />
                                            </div>
                                            <div class="pressao-form-group">
                                                <input type="email" 
                                                       class="pressao-ativista-email" 
                                                       placeholder="<?php esc_attr_e('Seu email', 'pressao-plugin'); ?>" />
                                            </div>
                                            <div class="pressao-form-group">
                                                <input type="tel" 
                                                       class="pressao-ativista-telefone" 
                                                       placeholder="<?php esc_attr_e('Seu telefone', 'pressao-plugin'); ?>" />
                                            </div>
                                            <button type="button" 
                                                    class="pressao-action-submit"
                                                    data-alvo-id="<?php echo esc_attr($alvo_id); ?>"
                                                    data-campaign="<?php echo esc_attr($campanha_id); ?>"
                                                    data-canal="<?php echo esc_attr($canal_alvo); ?>">
                                                <?php esc_html_e('Confirmar', 'pressao-plugin'); ?>
                                            </button>
                                        </div>
                                    <?php else : ?>
                                        <button type="button" 
                                                class="pressao-action-button"
                                                data-alvo-id="<?php echo esc_attr($alvo_id); ?>"
                                                data-campaign="<?php echo esc_attr($campanha_id); ?>"
                                                data-canal="<?php echo esc_attr($canal_alvo); ?>">
                                            <?php echo esc_html($action_label); ?>
                                        </button>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>
                        </div>

                        <?php if (!empty($alvo['metadados'])) : ?>
                            <div class="pressao-alvo-metadados">
                                <small><?php esc_html_e('Metadados:', 'pressao-plugin'); ?></small>
                                <pre><?php echo esc_html(json_encode($alvo['metadados'], JSON_PRETTY_PRINT)); ?></pre>
                            </div>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ul>
            
            <?php if ($result['cached']) : ?>
                <div class="pressao-cache-info">
                    <small><?php esc_html_e('Dados em cache', 'pressao-plugin'); ?></small>
                </div>
            <?php endif; ?>
        </div>
        <?php
        
        return ob_get_clean();
    }

    /**
     * Verifica se o usuário já fez ação para um alvo
     */
    private function get_alvo_action_state($alvo_id) {
        $actions = $this->get_acoes_from_cookie();
        return isset($actions[$alvo_id]) ? $actions[$alvo_id] : false;
    }

    /**
     * Textos fixos da lista por canal (índice visual, não o nome do alvo).
     */
    private function get_canal_list_labels($canal) {
        $labels = [
            'tiktok' => [
                'title' => __('TikTok', 'pressao-plugin'),
                'subtitle' => __('Marque em um video estrategico', 'pressao-plugin'),
            ],
            'instagram' => [
                'title' => __('Instagram', 'pressao-plugin'),
                'subtitle' => __('Faça barulho nas redes sociais', 'pressao-plugin'),
            ],
            'email' => [
                'title' => __('Email', 'pressao-plugin'),
                'subtitle' => __('Envie diretamente para os alvos', 'pressao-plugin'),
            ],
        ];
        return isset($labels[$canal]) ? $labels[$canal] : null;
    }

    /**
     * Ação realizada: automática já conta; canal manual só após confirmação.
     * Pendentes (AGUARDANDO_ACAO_HUMANA) ainda não entram no progresso.
     */
    private function is_acao_realizada($action_state) {
        if (!is_array($action_state)) {
            return false;
        }
        $status = $action_state['status'] ?? 'CONCLUIDA';
        return $status !== 'AGUARDANDO_ACAO_HUMANA';
    }

    /**
     * Formata o tempo da ação.
     * Aceita timestamp int ou o objeto completo da ação no cookie.
     */
    private function format_action_time($timestamp) {
        if (is_array($timestamp)) {
            $timestamp = isset($timestamp['timestamp']) ? $timestamp['timestamp'] : 0;
        }
        $timestamp = intval($timestamp);
        if ($timestamp <= 0) {
            return __('agora', 'pressao-plugin');
        }

        $diff = time() - $timestamp;
        
        if ($diff < 60) {
            return __('agora', 'pressao-plugin');
        } elseif ($diff < 3600) {
            $minutes = floor($diff / 60);
            return sprintf(_n('%d minuto', '%d minutos', $minutes, 'pressao-plugin'), $minutes);
        } elseif ($diff < 86400) {
            $hours = floor($diff / 3600);
            return sprintf(_n('%d hora', '%d horas', $hours, 'pressao-plugin'), $hours);
        } else {
            $days = floor($diff / 86400);
            return sprintf(_n('%d dia', '%d dias', $days, 'pressao-plugin'), $days);
        }
    }

    /**
     * Renderiza o contador de ações confirmadas da campanha.
     */
    public function render_contador($atts) {
        $atts = shortcode_atts([
            'campaign' => get_option('pressao_campaign_id', ''),
            'label' => __('ações confirmadas', 'pressao-plugin'),
            'class' => '',
            'id' => 'pressao-contador-' . uniqid(),
        ], $atts, 'pressao_contador');

        $campanha_id = sanitize_text_field($atts['campaign']);
        if (empty($campanha_id)) {
            return '<p class="pressao-error">' . esc_html__('ID da campanha não informado', 'pressao-plugin') . '</p>';
        }

        $result = $this->api->get_acoes_confirmadas_count($campanha_id, 60);
        if (is_wp_error($result)) {
            return '<p class="pressao-error">' . esc_html($result->get_error_message()) . '</p>';
        }
        $count = $result['count'];
        $formatted = number_format($count, 0, ',', '.');

        ob_start();
        ?>
        <div id="<?php echo esc_attr($atts['id']); ?>"
             class="pressao-acoes-counter <?php echo esc_attr($atts['class']); ?>"
             data-campaign="<?php echo esc_attr($campanha_id); ?>">
            <span class="pressao-acoes-count" data-count="<?php echo esc_attr($count); ?>">
                <?php echo esc_html($formatted); ?>
            </span>
            <span class="pressao-acoes-label"><?php echo esc_html($atts['label']); ?></span>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Renderiza a barra de progresso pessoal do ativista (baseada no cookie).
     */
    public function render_progresso($atts) {
        $atts = shortcode_atts([
            'campaign' => get_option('pressao_campaign_id', ''),
            'label' => __('Pressione para impactar', 'pressao-plugin'),
            'class' => '',
            'id' => 'pressao-progresso-' . uniqid(),
        ], $atts, 'pressao_progresso');

        $campanha_id = sanitize_text_field($atts['campaign']);
        if (empty($campanha_id)) {
            return '<p class="pressao-error">' . esc_html__('ID da campanha não informado', 'pressao-plugin') . '</p>';
        }

        $result = $this->api->get_alvos_cached($campanha_id, [], 300);
        if (is_wp_error($result)) {
            return '<p class="pressao-error">' . esc_html($result->get_error_message()) . '</p>';
        }

        $alvos = (!empty($result['success']) && !empty($result['data'])) ? $result['data'] : [];
        if (!is_array($alvos)) {
            $alvos = [];
        }

        $alvo_ids = [];
        foreach ($alvos as $alvo) {
            if (!empty($alvo['id'])) {
                $alvo_ids[] = $alvo['id'];
            }
        }

        $total = count($alvo_ids);
        $actions = $this->get_acoes_from_cookie();
        $done = 0;
        foreach ($alvo_ids as $alvo_id) {
            if ($this->is_acao_realizada($actions[$alvo_id] ?? null)) {
                $done++;
            }
        }

        $pct = $total > 0 ? min(100, (int) round(($done / $total) * 100)) : 0;
        $alvo_ids_attr = implode(',', $alvo_ids);

        ob_start();
        ?>
        <div id="<?php echo esc_attr($atts['id']); ?>"
             class="pressao-progresso <?php echo esc_attr($atts['class']); ?>"
             data-campaign="<?php echo esc_attr($campanha_id); ?>"
             data-alvo-ids="<?php echo esc_attr($alvo_ids_attr); ?>"
             data-total="<?php echo esc_attr($total); ?>"
             data-done="<?php echo esc_attr($done); ?>">
            <span class="pressao-progresso-label"><?php echo esc_html($atts['label']); ?></span>
            <span class="pressao-progresso-count">
                <span class="pressao-progresso-raio" aria-hidden="true"></span>
                <span class="pressao-progresso-text"><?php echo esc_html($done . ' de ' . $total); ?></span>
            </span>
            <div class="pressao-progresso-track" role="progressbar"
                 aria-valuemin="0"
                 aria-valuemax="<?php echo esc_attr($total); ?>"
                 aria-valuenow="<?php echo esc_attr($done); ?>">
                <div class="pressao-progresso-bar" style="width: <?php echo esc_attr($pct); ?>%;"></div>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Renderiza o bloco de candidatos configurado no admin do plugin.
     */
    public function render_candidatos($atts) {
        $atts = shortcode_atts([
            'title' => __('Candidatos', 'pressao-plugin'),
            'show_title' => 'yes',
            'class' => '',
            'id' => 'pressao-candidatos-' . uniqid(),
        ], $atts, 'pressao_candidatos');

        $candidatos = get_option('pressao_candidatos', []);
        if (!is_array($candidatos) || empty($candidatos)) {
            return '<p class="pressao-empty">' . esc_html__('Nenhum candidato configurado.', 'pressao-plugin') . '</p>';
        }

        ob_start();
        ?>
        <section id="<?php echo esc_attr($atts['id']); ?>"
                 class="pressao-candidatos <?php echo esc_attr($atts['class']); ?>">
            <?php if ($atts['show_title'] === 'yes') : ?>
                <h3 class="pressao-candidatos-title"><?php echo esc_html($atts['title']); ?></h3>
            <?php endif; ?>

            <div class="pressao-candidatos-grid">
                <?php foreach ($candidatos as $candidato) : ?>
                    <?php
                    $nome = $candidato['nome'] ?? '';
                    $cargo = $candidato['cargo'] ?? '';
                    $partido = $candidato['partido'] ?? '';
                    $descricao = $candidato['descricao'] ?? '';
                    $link_url = $candidato['link_url'] ?? '';
                    $imagem_id = absint($candidato['imagem_id'] ?? 0);
                    ?>
                    <article class="pressao-candidato-card">
                        <?php if ($imagem_id) : ?>
                            <div class="pressao-candidato-image">
                                <?php
                                echo wp_get_attachment_image(
                                    $imagem_id,
                                    'medium',
                                    false,
                                    ['class' => 'pressao-candidato-img']
                                );
                                ?>
                            </div>
                        <?php endif; ?>

                        <div class="pressao-candidato-content">
                            <?php if ($nome) : ?>
                                <h4 class="pressao-candidato-nome"><?php echo esc_html($nome); ?></h4>
                            <?php endif; ?>

                            <?php if ($cargo || $partido) : ?>
                                <p class="pressao-candidato-meta">
                                    <?php echo esc_html(trim($cargo . ($cargo && $partido ? ' - ' : '') . $partido)); ?>
                                </p>
                            <?php endif; ?>

                            <?php if ($descricao) : ?>
                                <p class="pressao-candidato-descricao"><?php echo esc_html($descricao); ?></p>
                            <?php endif; ?>

                            <?php if ($link_url) : ?>
                                <a class="pressao-candidato-link" href="<?php echo esc_url($link_url); ?>">
                                    <?php esc_html_e('Saiba mais', 'pressao-plugin'); ?>
                                </a>
                            <?php endif; ?>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        </section>
        <?php
        return ob_get_clean();
    }

    /**
     * Lê o mapa de ações realizadas do cookie do navegador.
     */
    private function get_acoes_from_cookie() {
        if (!isset($_COOKIE['pressao_acoes_realizadas'])) {
            return [];
        }
        $actions = json_decode(stripslashes($_COOKIE['pressao_acoes_realizadas']), true);
        return is_array($actions) ? $actions : [];
    }
}

// Inicializa o shortcode
new PressaoPlugin_Shortcode();