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
        add_shortcode('pressao_fluxo', [$this, 'render_fluxo']);
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
            'ativista_confirm_no' => __('Não sou eu', 'pressao-plugin'),
            'ordem' => '',
            'tempo_tiktok' => '2 min',
            'tempo_instagram' => '2 min',
            'tempo_email' => '1 min',
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
        $ordem = sanitize_text_field($atts['ordem']);
        $tempos_canal = [
            'tiktok' => sanitize_text_field($atts['tempo_tiktok']),
            'instagram' => sanitize_text_field($atts['tempo_instagram']),
            'email' => sanitize_text_field($atts['tempo_email']),
        ];
        
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

        $alvos = $this->ordenar_alvos_por_canal($alvos, $ordem);
        $share_config = $this->get_compartilhamento_config_for_render();
        $share_done_state = $this->get_alvo_action_state('__compartilhar');
        $share_realizado = $this->is_acao_realizada($share_done_state);
        
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
                    $canal_labels = $this->get_canal_list_labels($canal_alvo, $tempos_canal);
                    $usa_overlay = in_array($canal_alvo, ['email', 'instagram', 'tiktok'], true);
                    $list_title = $canal_labels
                        ? $canal_labels['title']
                        : (isset($alvo['nome']) ? $alvo['nome'] : '');
                    $list_tempo = $canal_labels && !empty($canal_labels['tempo'])
                        ? $canal_labels['tempo']
                        : '';
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
                                <strong class="pressao-alvo-nome">
                                    <?php echo esc_html($list_title); ?>
                                    <?php if ($list_tempo !== '') : ?>
                                        <span class="pressao-canal-tempo">· <?php echo esc_html($list_tempo); ?></span>
                                    <?php endif; ?>
                                </strong>
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

                <?php if ($share_config) : ?>
                    <li class="pressao-alvo-item pressao-compartilhar-item <?php echo $share_realizado ? 'action-done' : ''; ?>"
                        data-alvo-id="__compartilhar"
                        data-canal="compartilhar"
                        data-share-config="<?php echo esc_attr(wp_json_encode($share_config)); ?>">
                        <div class="pressao-alvo-info pressao-compartilhar-info">
                            <span class="pressao-alvo-canal">
                                <span class="pressao-alvo-canal-badge" data-canal="compartilhar"></span>
                            </span>
                            <div class="pressao-alvo-detalhes">
                                <strong class="pressao-alvo-nome">
                                    <?php echo esc_html($share_config['titulo']); ?>
                                    <?php if (!empty($share_config['tempo'])) : ?>
                                        <span class="pressao-canal-tempo">· <?php echo esc_html($share_config['tempo']); ?></span>
                                    <?php endif; ?>
                                </strong>
                                <?php if (!empty($share_config['subtitulo'])) : ?>
                                    <span class="pressao-alvo-contato"><?php echo esc_html($share_config['subtitulo']); ?></span>
                                <?php endif; ?>
                            </div>
                            <?php if ($show_actions === 'yes') : ?>
                                <div class="pressao-alvo-actions">
                                    <?php if ($share_realizado) : ?>
                                        <span class="pressao-action-done">
                                            <?php echo esc_html($action_done_label); ?>
                                            <span class="pressao-action-time">
                                                <?php echo esc_html($this->format_action_time($share_done_state)); ?>
                                            </span>
                                        </span>
                                    <?php else : ?>
                                        <button type="button"
                                                class="pressao-action-button pressao-compartilhar-button"
                                                data-alvo-id="__compartilhar"
                                                data-canal="compartilhar"
                                                aria-label="<?php echo esc_attr($share_config['titulo']); ?>">
                                        </button>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </li>
                <?php endif; ?>
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
     *
     * @param string $canal
     * @param array  $tempos Mapa canal => texto de tempo (ex.: "2 min"), vindos do shortcode.
     */
    private function get_canal_list_labels($canal, $tempos = []) {
        $labels = [
            'tiktok' => [
                'title' => __('TikTok', 'pressao-plugin'),
                'subtitle' => __('Marque em um video estrategico', 'pressao-plugin'),
                'tempo' => '2 min',
            ],
            'instagram' => [
                'title' => __('Instagram', 'pressao-plugin'),
                'subtitle' => __('Faça barulho nas redes sociais', 'pressao-plugin'),
                'tempo' => '2 min',
            ],
            'email' => [
                'title' => __('Email', 'pressao-plugin'),
                'subtitle' => __('Envie diretamente para os alvos', 'pressao-plugin'),
                'tempo' => '1 min',
            ],
        ];
        if (!isset($labels[$canal])) {
            return null;
        }
        if (isset($tempos[$canal]) && $tempos[$canal] !== '') {
            $labels[$canal]['tempo'] = $tempos[$canal];
        }
        return $labels[$canal];
    }

    /**
     * Ordena alvos pela lista CSV de canais do atributo ordem (estável).
     * Canais omitidos ficam depois, na ordem original da API.
     */
    private function ordenar_alvos_por_canal($alvos, $ordem) {
        if (!is_array($alvos) || empty($ordem)) {
            return $alvos;
        }

        $canais = array_values(array_filter(array_map(function ($canal) {
            return strtolower(trim($canal));
        }, explode(',', $ordem))));

        if (empty($canais)) {
            return $alvos;
        }

        $prioridade = array_flip($canais);
        $indexed = [];
        foreach ($alvos as $i => $alvo) {
            $indexed[] = [
                'alvo' => $alvo,
                'index' => $i,
                'canal' => isset($alvo['tipo_contato']) ? strtolower((string) $alvo['tipo_contato']) : '',
            ];
        }

        usort($indexed, function ($a, $b) use ($prioridade) {
            $pa = array_key_exists($a['canal'], $prioridade) ? $prioridade[$a['canal']] : PHP_INT_MAX;
            $pb = array_key_exists($b['canal'], $prioridade) ? $prioridade[$b['canal']] : PHP_INT_MAX;
            if ($pa === $pb) {
                return $a['index'] <=> $b['index'];
            }
            return $pa <=> $pb;
        });

        return array_map(function ($row) {
            return $row['alvo'];
        }, $indexed);
    }

    /**
     * Config pública de compartilhamento para SSR/JS, ou null se inativo/incompleto.
     */
    private function get_compartilhamento_config_for_render($require_ativo = true) {
        $config = get_option('pressao_compartilhamento', []);
        if (!is_array($config)) {
            return null;
        }
        if ($require_ativo && empty($config['ativo'])) {
            return null;
        }

        $link = isset($config['link']) ? trim((string) $config['link']) : '';
        $mensagem = isset($config['mensagem']) ? trim((string) $config['mensagem']) : '';
        if ($require_ativo && $link === '' && $mensagem === '') {
            return null;
        }

        $whatsapp_url = isset($config['whatsapp_url']) ? trim((string) $config['whatsapp_url']) : '';
        if ($whatsapp_url === '' && $mensagem !== '') {
            $whatsapp_url = 'https://wa.me/?text=' . rawurlencode($mensagem);
        }

        $imagens = [];
        if (!empty($config['imagens']) && is_array($config['imagens'])) {
            foreach ($config['imagens'] as $imagem) {
                if (!is_array($imagem)) {
                    continue;
                }
                $imagem_id = absint($imagem['imagem_id'] ?? 0);
                if (!$imagem_id) {
                    continue;
                }
                $url = wp_get_attachment_url($imagem_id);
                $thumb = wp_get_attachment_image_url($imagem_id, 'medium');
                if (!$url) {
                    continue;
                }
                $imagens[] = [
                    'rotulo' => sanitize_text_field($imagem['rotulo'] ?? ''),
                    'url' => $url,
                    'thumb' => $thumb ? $thumb : $url,
                    'filename' => basename(parse_url($url, PHP_URL_PATH) ?: ('imagem-' . $imagem_id)),
                ];
            }
        }

        return [
            'titulo' => !empty($config['titulo'])
                ? $config['titulo']
                : __('Compartilhar ação', 'pressao-plugin'),
            'subtitulo' => $config['subtitulo'] ?? '',
            'tempo' => $config['tempo'] ?? '',
            'overlay_titulo' => !empty($config['overlay_titulo'])
                ? $config['overlay_titulo']
                : __('Compartilhe e aumente o seu impacto', 'pressao-plugin'),
            'link' => $link,
            'mensagem' => $mensagem,
            'whatsapp_url' => $whatsapp_url,
            'instagram_url' => $config['instagram_url'] ?? '',
            'messenger_url' => $config['messenger_url'] ?? '',
            'imagens_titulo' => !empty($config['imagens_titulo'])
                ? $config['imagens_titulo']
                : __('Imagens para postar', 'pressao-plugin'),
            'imagens_subtitulo' => !empty($config['imagens_subtitulo'])
                ? $config['imagens_subtitulo']
                : __('baixe imagens prontas para postar nas redes', 'pressao-plugin'),
            'imagens_instrucao' => !empty($config['imagens_instrucao'])
                ? $config['imagens_instrucao']
                : __('Utilize nossas imagens nas suas redes para que outras pessoas conheçam a campanha:', 'pressao-plugin'),
            'imagens' => $imagens,
        ];
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

        $candidatos = get_option('pressao_candidatos_apoiadores', []);
        if (!is_array($candidatos) || empty($candidatos)) {
            return '<p class="pressao-empty">' . esc_html__('Nenhum candidato apoiador configurado.', 'pressao-plugin') . '</p>';
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
                                <?php
                                $instagram_path = ltrim($link_url, '@');
                                $instagram_href = (strpos($link_url, 'http') === 0)
                                    ? $link_url
                                    : 'https://www.instagram.com/' . $instagram_path . '/';
                                ?>
                                <a class="pressao-candidato-link" href="<?php echo esc_url($instagram_href); ?>" target="_blank" rel="noopener noreferrer">
                                    <?php echo esc_html($link_url); ?>
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

    /**
     * Fluxo único sequencial por alvo/canal (v1: Instagram).
     */
    public function render_fluxo($atts) {
        $atts = shortcode_atts([
            'alvo_id' => '',
            'canal' => 'instagram',
            'campaign' => get_option('pressao_campaign_id', ''),
            'template_id' => '',
            'title' => __('Seu candidato já se comprometeu com a Tarifa Zero?', 'pressao-plugin'),
            'subtitle' => __("As eleições estão chegando. É hora de perguntar aos candidatos à Câmara e Senado: vocês estão do lado de quem precisa se deslocar todos os dias?\n\nEscolha até 5 candidatos e peça que ele declare publicamente seu compromisso.", 'pressao-plugin'),
            'class' => '',
            'id' => 'pressao-fluxo-' . uniqid(),
            // O widget de fluxo monta a mensagem a partir de um template
            // sorteado pela API (services/templates.py, random.choice). Cache
            // > 0 aqui prende o mesmo sorteio por até `cache` segundos pra
            // TODOS os visitantes (mesma mensagem pra todo mundo dentro da
            // janela), não só pra quem recarrega a página. 0 = sem cache,
            // sorteio novo a cada visita — desligue via atributo cache="300"
            // (ou outro valor) se algum dia precisar aliviar carga na API.
            'cache' => 0,
        ], $atts, 'pressao_fluxo');

        $alvo_id = sanitize_text_field($atts['alvo_id']);
        $canal = strtolower(sanitize_text_field($atts['canal']));
        $campanha_id = sanitize_text_field($atts['campaign']);
        $template_id_att = sanitize_text_field($atts['template_id']);
        $cache_time = intval($atts['cache']);

        if ($alvo_id === '') {
            return '<p class="pressao-error">' . esc_html__('Informe o alvo_id no shortcode [pressao_fluxo].', 'pressao-plugin') . '</p>';
        }

        if ($canal !== 'instagram') {
            return '<p class="pressao-error">' . esc_html__('Neste lançamento o [pressao_fluxo] está disponível apenas para o canal Instagram.', 'pressao-plugin') . '</p>';
        }

        if ($campanha_id === '') {
            return '<p class="pressao-error">' . esc_html__('ID da campanha não informado', 'pressao-plugin') . '</p>';
        }

        $result = $this->api->get_alvos_cached($campanha_id, ['canal' => $canal], $cache_time);
        if (is_wp_error($result)) {
            return '<p class="pressao-error">' . esc_html($result->get_error_message()) . '</p>';
        }

        $alvos = (!empty($result['success']) && !empty($result['data'])) ? $result['data'] : [];
        if (!is_array($alvos)) {
            $alvos = [];
        }

        $alvo = null;
        foreach ($alvos as $item) {
            if (!empty($item['id']) && (string) $item['id'] === (string) $alvo_id) {
                $alvo = $item;
                break;
            }
        }

        if (!$alvo) {
            $result_all = $this->api->get_alvos_cached($campanha_id, [], $cache_time);
            if (!is_wp_error($result_all) && !empty($result_all['success']) && !empty($result_all['data'])) {
                foreach ($result_all['data'] as $item) {
                    if (!empty($item['id']) && (string) $item['id'] === (string) $alvo_id) {
                        $alvo = $item;
                        break;
                    }
                }
            }
        }

        if (!$alvo) {
            return '<p class="pressao-error">' . esc_html__('Alvo não encontrado para esta campanha.', 'pressao-plugin') . '</p>';
        }

        $alvo_template = isset($alvo['template']) && is_array($alvo['template']) ? $alvo['template'] : null;
        $template_id = $alvo_template && !empty($alvo_template['id'])
            ? $alvo_template['id']
            : $template_id_att;
        $template_conteudo = $alvo_template && !empty($alvo_template['conteudo'])
            ? wp_strip_all_tags($alvo_template['conteudo'])
            : '';

        $count_result = $this->api->get_acoes_confirmadas_count($campanha_id, 60);
        $acoes_count = is_wp_error($count_result) ? 0 : (int) $count_result['count'];

        $limite = (int) get_option('pressao_fluxo_limite_candidatos', 5);
        if ($limite < 1) {
            $limite = 5;
        }

        $ajuda = get_option('pressao_fluxo_ajuda', []);
        if (!is_array($ajuda)) {
            $ajuda = [];
        }
        $ajuda_titulo = !empty($ajuda['titulo'])
            ? $ajuda['titulo']
            : __('Ajuda', 'pressao-plugin');
        $ajuda_conteudo = isset($ajuda['conteudo']) ? (string) $ajuda['conteudo'] : '';
        $alvo_nome = isset($alvo['nome']) ? (string) $alvo['nome'] : '';

        $candidatos_raw = get_option('pressao_candidatos', []);
        $candidatos = $this->normalize_candidatos_for_fluxo($candidatos_raw, 'c');

        $apoiadores_raw = get_option('pressao_candidatos_apoiadores', []);
        $apoiadores = $this->normalize_candidatos_for_fluxo($apoiadores_raw, 'a');

        $filtros_index = PressaoPlugin_Candidatos_Filtros::get_index();
        $filtros_estados = isset($filtros_index['estados']) && is_array($filtros_index['estados'])
            ? $filtros_index['estados']
            : [];

        $share_config = $this->get_compartilhamento_config_for_render(false);
        if (!$share_config) {
            $share_config = [
                'overlay_titulo' => __('Convide mais pessoas', 'pressao-plugin'),
                'link' => '',
                'mensagem' => '',
                'whatsapp_url' => '',
                'instagram_url' => '',
                'messenger_url' => '',
                'imagens_titulo' => __('Imagens para postar', 'pressao-plugin'),
                'imagens_subtitulo' => __('baixe imagens prontas para postar nas redes', 'pressao-plugin'),
                'imagens_instrucao' => '',
                'imagens' => [],
            ];
        }

        $config = [
            'alvo_id' => $alvo_id,
            'campanha_id' => $campanha_id,
            'canal' => $canal,
            'template_id' => $template_id,
            'template_conteudo' => $template_conteudo,
            'contato_url' => $alvo['contato'] ?? '',
            'limite_candidatos' => $limite,
            'countdown_abrir' => (bool) get_option('pressao_fluxo_countdown_abrir', 0),
            'candidatos' => $candidatos,
            'apoiadores' => $apoiadores,
            'filtros' => $filtros_estados,
            'acoes_confirmadas' => $acoes_count,
            'alvo_nome' => $alvo_nome,
            'share' => $share_config,
            'nonce' => wp_create_nonce('pressao_acao_nonce'),
        ];

        $total_candidatos = count($apoiadores);
        $max_avatars = 5;
        $com_imagem = [];
        $sem_imagem = [];
        foreach ($apoiadores as $candidato) {
            if (!empty($candidato['imagem'])) {
                $com_imagem[] = $candidato;
            } else {
                $sem_imagem[] = $candidato;
            }
        }
        $avatares = array_slice(array_merge($com_imagem, $sem_imagem), 0, $max_avatars);

        ob_start();
        ?>
        <div id="<?php echo esc_attr($atts['id']); ?>"
             class="pressao-fluxo <?php echo esc_attr($atts['class']); ?>"
             data-campaign="<?php echo esc_attr($campanha_id); ?>"
             data-nonce="<?php echo esc_attr($config['nonce']); ?>"
             data-pressao-fluxo="<?php echo esc_attr(wp_json_encode($config)); ?>">

            <div class="pressao-fluxo-card" data-fluxo-root>
                <div class="pressao-fluxo-screen is-active" data-screen="inicio">
                    <div class="pressao-fluxo-left">
                        <header class="pressao-fluxo-topbar">
                            <span class="pressao-fluxo-channel-badge">
                                <span class="pressao-fluxo-channel-badge-icon" aria-hidden="true"></span>
                                <?php esc_html_e('Faça sua parte no Instagram!', 'pressao-plugin'); ?>
                            </span>
                            <button type="button" class="pressao-fluxo-help" data-fluxo-open-help aria-label="<?php esc_attr_e('Ajuda', 'pressao-plugin'); ?>">?</button>
                        </header>
                        <h2 class="pressao-fluxo-title"><?php echo esc_html($atts['title']); ?></h2>
                        <p class="pressao-fluxo-subtitle"><?php echo esc_html($atts['subtitle']); ?></p>

                        <div class="pressao-fluxo-proof">
                            <button type="button" class="pressao-fluxo-candidatos-btn" data-fluxo-open-lista>
                                <span class="pressao-fluxo-avatars" aria-hidden="true">
                                    <?php foreach ($avatares as $avatar) : ?>
                                        <span class="pressao-fluxo-avatar"<?php echo !empty($avatar['imagem']) ? ' style="background-image:url(\'' . esc_url($avatar['imagem']) . '\')"' : ''; ?>></span>
                                    <?php endforeach; ?>
                                </span>
                                <span class="pressao-fluxo-candidatos-copy">
                                    <span class="pressao-fluxo-candidatos-count">
                                        <?php
                                        echo esc_html(sprintf(
                                            /* translators: %d: total candidate count */
                                            _n('%d candidato', '%d candidatos', $total_candidatos, 'pressao-plugin'),
                                            $total_candidatos
                                        ));
                                        ?>
                                        <span class="pressao-fluxo-candidatos-arrow" aria-hidden="true"></span>
                                    </span>
                                    <span class="pressao-fluxo-candidatos-caption"><?php esc_html_e('já apoiam essa pauta', 'pressao-plugin'); ?></span>
                                </span>
                            </button>

                            <div class="pressao-fluxo-counter pressao-fluxo-counter--desktop pressao-acoes-counter" data-campaign="<?php echo esc_attr($campanha_id); ?>">
                                <span class="pressao-fluxo-counter-raio" aria-hidden="true"></span>
                                <span class="pressao-fluxo-counter-value">
                                    <span class="pressao-acoes-count" data-count="<?php echo esc_attr($acoes_count); ?>">
                                        <?php echo esc_html(number_format($acoes_count, 0, ',', '.')); ?>
                                    </span>
                                    <span class="pressao-fluxo-counter-label"><?php esc_html_e('pressões', 'pressao-plugin'); ?></span>
                                </span>
                                <span class="pressao-fluxo-counter-caption"><?php esc_html_e('até o momento', 'pressao-plugin'); ?></span>
                            </div>
                        </div>
                    </div>

                    <div class="pressao-fluxo-right">
                        <?php
                        $limit_hint = sprintf(
                            /* translators: %d: max candidates */
                            __('Você pode selecionar até %d candidatos por vez', 'pressao-plugin'),
                            $limite
                        );
                        $com_filtro_estado = !empty($filtros_estados);
                        $modo_id = $atts['id'] . '-modo';
                        ?>
                        <div class="pressao-fluxo-search-block pressao-fluxo-modos" data-fluxo-modos>
                            <section class="pressao-fluxo-modo is-open" data-fluxo-modo="busca">
                                <?php if ($com_filtro_estado) : ?>
                                    <button type="button"
                                            class="pressao-fluxo-modo-toggle"
                                            id="<?php echo esc_attr($modo_id); ?>-busca-toggle"
                                            aria-expanded="true"
                                            aria-controls="<?php echo esc_attr($modo_id); ?>-busca"
                                            data-fluxo-modo-toggle="busca">
                                        <span><?php esc_html_e('Busque ou selecione candidatos', 'pressao-plugin'); ?></span>
                                        <span class="pressao-fluxo-modo-chevron" aria-hidden="true"></span>
                                    </button>
                                <?php else : ?>
                                    <label class="pressao-fluxo-field-label" for="<?php echo esc_attr($atts['id']); ?>-select">
                                        <?php esc_html_e('Busque ou selecione candidatos', 'pressao-plugin'); ?>
                                    </label>
                                <?php endif; ?>
                                <div class="pressao-fluxo-modo-body" id="<?php echo esc_attr($modo_id); ?>-busca">
                                    <select id="<?php echo esc_attr($atts['id']); ?>-select"
                                            class="pressao-fluxo-select"
                                            multiple
                                            data-fluxo-select
                                            <?php if ($com_filtro_estado) : ?>aria-labelledby="<?php echo esc_attr($modo_id); ?>-busca-toggle"<?php endif; ?>
                                            placeholder="<?php esc_attr_e('Nome do candidato ou @ do Instagram', 'pressao-plugin'); ?>">
                                        <?php foreach ($candidatos as $candidato) : ?>
                                            <?php if (empty($candidato['instagram'])) { continue; } ?>
                                            <option value="<?php echo esc_attr($candidato['id']); ?>">
                                                <?php echo esc_html(trim(($candidato['nome'] ? $candidato['nome'] . ' ' : '') . $candidato['instagram'])); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <p class="pressao-fluxo-limit-hint">
                                        <span class="pressao-fluxo-limit-hint-icon" aria-hidden="true"></span>
                                        <?php echo esc_html($limit_hint); ?>
                                    </p>
                                </div>
                            </section>

                            <?php if ($com_filtro_estado) : ?>
                                <p class="pressao-fluxo-modos-ou"><?php esc_html_e('ou então', 'pressao-plugin'); ?></p>

                                <section class="pressao-fluxo-modo" data-fluxo-modo="estado">
                                    <button type="button"
                                            class="pressao-fluxo-modo-toggle"
                                            id="<?php echo esc_attr($modo_id); ?>-estado-toggle"
                                            aria-expanded="false"
                                            aria-controls="<?php echo esc_attr($modo_id); ?>-estado"
                                            data-fluxo-modo-toggle="estado">
                                        <span><?php esc_html_e('Filtre por estado', 'pressao-plugin'); ?></span>
                                        <span class="pressao-fluxo-modo-chevron" aria-hidden="true"></span>
                                    </button>
                                    <div class="pressao-fluxo-modo-body" id="<?php echo esc_attr($modo_id); ?>-estado" hidden>
                                        <fieldset class="pressao-fluxo-cargos" data-fluxo-cargos hidden>
                                            <legend class="screen-reader-text"><?php esc_html_e('Cargo', 'pressao-plugin'); ?></legend>
                                            <span class="pressao-fluxo-cargos-list" data-fluxo-cargos-list></span>
                                            <span class="pressao-fluxo-cargos-opcional"><?php esc_html_e('(opcional)', 'pressao-plugin'); ?></span>
                                        </fieldset>
                                        <label class="screen-reader-text" for="<?php echo esc_attr($atts['id']); ?>-estado">
                                            <?php esc_html_e('Estado', 'pressao-plugin'); ?>
                                        </label>
                                        <select id="<?php echo esc_attr($atts['id']); ?>-estado"
                                                class="pressao-fluxo-estado-select"
                                                data-fluxo-estado>
                                            <option value=""><?php esc_html_e('Selecione um estado', 'pressao-plugin'); ?></option>
                                            <?php foreach ($filtros_estados as $estado) : ?>
                                                <option value="<?php echo esc_attr($estado['uf']); ?>"><?php echo esc_html($estado['nome']); ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                        <select id="<?php echo esc_attr($atts['id']); ?>-select-estado"
                                                class="pressao-fluxo-select"
                                                multiple
                                                disabled
                                                data-fluxo-select-estado
                                                aria-label="<?php esc_attr_e('Candidatos do estado', 'pressao-plugin'); ?>"
                                                placeholder="<?php esc_attr_e('Nome do candidato ou @ do Instagram', 'pressao-plugin'); ?>">
                                        </select>
                                        <p class="pressao-fluxo-limit-hint">
                                            <span class="pressao-fluxo-limit-hint-icon" aria-hidden="true"></span>
                                            <?php echo esc_html($limit_hint); ?>
                                        </p>
                                    </div>
                                </section>
                            <?php endif; ?>
                        </div>

                        <button type="button" class="pressao-fluxo-btn pressao-fluxo-btn-primary" data-fluxo-continuar>
                            <?php esc_html_e('Continuar', 'pressao-plugin'); ?>
                            <span class="pressao-fluxo-btn-arrow" aria-hidden="true"></span>
                        </button>

                        <div class="pressao-fluxo-counter pressao-fluxo-counter--mobile pressao-acoes-counter" data-campaign="<?php echo esc_attr($campanha_id); ?>">
                            <span class="pressao-fluxo-counter-raio" aria-hidden="true"></span>
                            <span class="pressao-fluxo-counter-value">
                                <span class="pressao-acoes-count" data-count="<?php echo esc_attr($acoes_count); ?>">
                                    <?php echo esc_html(number_format($acoes_count, 0, ',', '.')); ?>
                                </span>
                                <span class="pressao-fluxo-counter-label"><?php esc_html_e('pressões até o momento', 'pressao-plugin'); ?></span>
                            </span>
                        </div>
                    </div>
                </div>

                <div class="pressao-fluxo-seq-overlay" data-fluxo-seq hidden>
                    <div class="pressao-fluxo-seq-backdrop" aria-hidden="true"></div>
                    <div class="pressao-fluxo-seq-panel" role="dialog" aria-modal="true">
                        <div class="pressao-fluxo-screen" data-screen="acao" hidden>
                            <header class="pressao-fluxo-nav">
                                <button type="button" class="pressao-fluxo-back" data-fluxo-back-inicio aria-label="<?php esc_attr_e('Voltar', 'pressao-plugin'); ?>"></button>
                                <h3 class="pressao-fluxo-nav-title"><?php esc_html_e('Publique seu comentário', 'pressao-plugin'); ?></h3>
                                <button type="button" class="pressao-fluxo-help pressao-fluxo-help--acao" data-fluxo-open-help aria-label="<?php esc_attr_e('Ajuda', 'pressao-plugin'); ?>">?</button>
                            </header>
                            <div class="pressao-fluxo-acao-body">
                                <p class="pressao-fluxo-section-label"><?php esc_html_e('Candidatos selecionados', 'pressao-plugin'); ?></p>
                                <div class="pressao-fluxo-chips" data-fluxo-chips></div>
                                <hr class="pressao-fluxo-divider" />
                                <p class="pressao-fluxo-section-label"><?php esc_html_e('Copie o texto', 'pressao-plugin'); ?></p>
                                <p class="pressao-fluxo-hint"><?php esc_html_e('É só colar nos comentários da publicação da campanha no Instagram.', 'pressao-plugin'); ?></p>
                                <div class="pressao-fluxo-message" data-fluxo-message></div>
                                <p class="pressao-fluxo-footnote"><?php esc_html_e('O comentário será publicado com seu perfil do Instagram.', 'pressao-plugin'); ?></p>
                            </div>
                            <button type="button" class="pressao-fluxo-btn pressao-fluxo-btn-primary" data-fluxo-copiar>
                                <?php esc_html_e('Copiar e abrir no Instagram', 'pressao-plugin'); ?>
                                <span class="pressao-fluxo-btn-icon" aria-hidden="true"></span>
                            </button>
                        </div>

                        <div class="pressao-fluxo-screen" data-screen="confirmacao" hidden>
                            <header class="pressao-fluxo-nav">
                                <button type="button" class="pressao-fluxo-back" data-fluxo-to-acao aria-label="<?php esc_attr_e('Voltar', 'pressao-plugin'); ?>"></button>
                                <h3 class="pressao-fluxo-nav-title"><?php esc_html_e('Publique seu comentário', 'pressao-plugin'); ?></h3>
                                <button type="button" class="pressao-fluxo-help" data-fluxo-open-help aria-label="<?php esc_attr_e('Ajuda', 'pressao-plugin'); ?>">?</button>
                            </header>
                            <div class="pressao-fluxo-confirm-body">
                                <h2 class="pressao-fluxo-title"><?php esc_html_e('Conseguiu publicar o comentário?', 'pressao-plugin'); ?></h2>
                                <p class="pressao-fluxo-subtitle"><?php esc_html_e('Ao confirmar seu comentário, contabilizamos a sua participação no movimento e acompanhamos o engajamento da pauta.', 'pressao-plugin'); ?></p>
                            </div>
                            <div class="pressao-fluxo-footer-actions">
                                <button type="button" class="pressao-fluxo-btn pressao-fluxo-btn-primary" data-fluxo-sim-publiquei>
                                    <span class="pressao-fluxo-check" aria-hidden="true"></span>
                                    <?php esc_html_e('Sim, já publiquei!', 'pressao-plugin'); ?>
                                </button>
                                <button type="button" class="pressao-fluxo-btn pressao-fluxo-btn-secondary" data-fluxo-tentar-novamente>
                                    <?php esc_html_e('Não, tentar novamente', 'pressao-plugin'); ?>
                                </button>
                            </div>
                        </div>

                        <div class="pressao-fluxo-screen" data-screen="form" hidden>
                            <header class="pressao-fluxo-topbar pressao-fluxo-topbar-form">
                                <h2 class="pressao-fluxo-title pressao-fluxo-title-sm"><?php esc_html_e('Quer acompanhar os próximos passos?', 'pressao-plugin'); ?></h2>
                                <button type="button" class="pressao-fluxo-help" data-fluxo-open-help aria-label="<?php esc_attr_e('Ajuda', 'pressao-plugin'); ?>">?</button>
                            </header>
                            <p class="pressao-fluxo-subtitle"><?php esc_html_e('Receba atualizações sobre a campanha e novas formas de pressionar pela Tarifa Zero.', 'pressao-plugin'); ?></p>
                            <form class="pressao-fluxo-ativista-form" data-fluxo-form novalidate>
                                <label class="pressao-fluxo-field-label">
                                    <?php esc_html_e('Nome', 'pressao-plugin'); ?> <span class="pressao-fluxo-required">*</span>
                                    <input type="text" name="nome" required placeholder="<?php esc_attr_e('Seu nome', 'pressao-plugin'); ?>" />
                                </label>
                                <label class="pressao-fluxo-field-label">
                                    <?php esc_html_e('Email', 'pressao-plugin'); ?> <span class="pressao-fluxo-required">*</span>
                                    <input type="email" name="email" required placeholder="<?php esc_attr_e('seu@email.com', 'pressao-plugin'); ?>" />
                                </label>
                                <label class="pressao-fluxo-field-label">
                                    <?php esc_html_e('Whatsapp (opcional)', 'pressao-plugin'); ?>
                                    <input type="tel" name="telefone" placeholder="(00) 00000-0000" inputmode="numeric" autocomplete="tel" data-fluxo-whatsapp />
                                </label>
                                <p class="pressao-fluxo-form-error" data-fluxo-form-error hidden></p>
                                <div class="pressao-fluxo-footer-actions">
                                    <button type="submit" class="pressao-fluxo-btn pressao-fluxo-btn-primary" data-fluxo-receber>
                                        <span class="pressao-fluxo-check" aria-hidden="true"></span>
                                        <?php esc_html_e('Quero receber atualizações', 'pressao-plugin'); ?>
                                    </button>
                                    <button type="button" class="pressao-fluxo-btn pressao-fluxo-btn-secondary" data-fluxo-agora-nao>
                                        <?php esc_html_e('Agora não', 'pressao-plugin'); ?>
                                    </button>
                                </div>
                            </form>
                        </div>

                        <div class="pressao-fluxo-screen" data-screen="share" hidden>
                            <div class="pressao-fluxo-share" data-fluxo-share></div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="pressao-fluxo-lista-overlay" data-fluxo-lista hidden>
                <div class="pressao-fluxo-lista-backdrop" data-fluxo-lista-close></div>
                <div class="pressao-fluxo-lista-panel" role="dialog" aria-modal="true" aria-labelledby="<?php echo esc_attr($atts['id']); ?>-lista-title">
                    <header class="pressao-fluxo-lista-header">
                        <div>
                            <h3 id="<?php echo esc_attr($atts['id']); ?>-lista-title"><?php esc_html_e('Candidatos que já apoiam', 'pressao-plugin'); ?></h3>
                            <p>
                                <?php
                                echo esc_html(sprintf(
                                    /* translators: %d: candidate count */
                                    __('%d candidatos já assumiram o compromisso com a Tarifa Zero', 'pressao-plugin'),
                                    $total_candidatos
                                ));
                                ?>
                            </p>
                        </div>
                        <button type="button" class="pressao-fluxo-lista-close" data-fluxo-lista-close aria-label="<?php esc_attr_e('Fechar', 'pressao-plugin'); ?>">×</button>
                    </header>
                    <ul class="pressao-fluxo-lista-items">
                        <?php foreach ($apoiadores as $candidato) : ?>
                            <li class="pressao-fluxo-lista-item">
                                <span class="pressao-fluxo-lista-avatar"<?php echo $candidato['imagem'] ? ' style="background-image:url(\'' . esc_url($candidato['imagem']) . '\')"' : ''; ?>></span>
                                <span class="pressao-fluxo-lista-meta">
                                    <strong><?php echo esc_html($candidato['nome'] ?: $candidato['instagram']); ?></strong>
                                    <span>
                                        <?php
                                        $parts = array_filter([
                                            $candidato['instagram'],
                                            $candidato['cargo'],
                                            $candidato['partido'],
                                        ]);
                                        echo esc_html(implode(' · ', $parts));
                                        ?>
                                    </span>
                                </span>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            </div>

            <div class="pressao-fluxo-help-overlay" data-fluxo-help hidden>
                <div class="pressao-fluxo-help-backdrop" data-fluxo-help-close></div>
                <div class="pressao-fluxo-help-panel" role="dialog" aria-modal="true" aria-labelledby="<?php echo esc_attr($atts['id']); ?>-help-title">
                    <header class="pressao-fluxo-help-header">
                        <h3 id="<?php echo esc_attr($atts['id']); ?>-help-title"><?php echo esc_html($ajuda_titulo); ?></h3>
                        <button type="button" class="pressao-fluxo-lista-close" data-fluxo-help-close aria-label="<?php esc_attr_e('Fechar', 'pressao-plugin'); ?>">×</button>
                    </header>
                    <div class="pressao-fluxo-help-body">
                        <?php
                        if ($ajuda_conteudo !== '') {
                            echo wp_kses_post($ajuda_conteudo);
                        } else {
                            echo '<p>' . esc_html__('Conteúdo de ajuda ainda não configurado.', 'pressao-plugin') . '</p>';
                        }
                        ?>
                    </div>
                </div>
            </div>

            <div class="pressao-fluxo-toast" data-fluxo-toast hidden>
                <div class="pressao-fluxo-toast-card">
                    <strong data-fluxo-toast-title></strong>
                    <p data-fluxo-toast-text></p>
                </div>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Normaliza option de candidatos para o config JS do [pressao_fluxo].
     *
     * @param mixed  $raw
     * @param string $id_prefix
     * @return array<int, array<string, string>>
     */
    private function normalize_candidatos_for_fluxo($raw, $id_prefix = 'c') {
        $out = [];
        if (!is_array($raw)) {
            return $out;
        }

        foreach ($raw as $index => $candidato) {
            if (!is_array($candidato)) {
                continue;
            }
            $handle = isset($candidato['link_url']) ? trim((string) $candidato['link_url']) : '';
            if ($handle !== '' && strpos($handle, '@') !== 0) {
                $handle = '@' . ltrim($handle, '@');
            }
            if ($handle === '') {
                continue;
            }
            $imagem_id = absint($candidato['imagem_id'] ?? 0);
            $imagem_url = $imagem_id ? wp_get_attachment_image_url($imagem_id, 'thumbnail') : '';
            $out[] = [
                'id' => $id_prefix . $index,
                'nome' => $candidato['nome'] ?? '',
                'cargo' => $candidato['cargo'] ?? '',
                'cargo_chave' => PressaoPlugin_Candidatos_Filtros::normalize_cargo($candidato['cargo'] ?? ''),
                'partido' => $candidato['partido'] ?? '',
                'estado' => PressaoPlugin_Candidatos_Filtros::sanitize_uf($candidato['estado'] ?? ''),
                'instagram' => $handle,
                'imagem' => $imagem_url ? $imagem_url : '',
            ];
        }

        return $out;
    }
}

// Inicializa o shortcode
new PressaoPlugin_Shortcode();
