<?php
/**
 * Classe de administração - Versão Simplificada
 * 
 * @package PressaoPlugin
 */

if (!defined('ABSPATH')) {
    exit;
}

class PressaoPlugin_Admin {
    
    public function __construct() {
        add_action('admin_menu', [$this, 'add_admin_menu']);
        add_action('admin_init', [$this, 'register_settings']);
    }
    
    public function add_admin_menu() {
        add_options_page(
            __('Pressão Plugin', 'pressao-plugin'),
            __('Pressão Plugin', 'pressao-plugin'),
            'manage_options',
            'pressao-settings',
            [$this, 'render_settings_page']
        );
    }
    
    public function register_settings() {
        // Configurações principais
        register_setting('pressao_settings_group', 'pressao_keycloak_url');
        register_setting('pressao_settings_group', 'pressao_realm');
        register_setting('pressao_settings_group', 'pressao_client_id');
        register_setting('pressao_settings_group', 'pressao_client_secret');
        register_setting('pressao_settings_group', 'pressao_api_url');
        register_setting('pressao_settings_group', 'pressao_campaign_id');
        register_setting('pressao_settings_group', 'pressao_widget_title');
        register_setting('pressao_settings_group', 'pressao_session_duration');
        register_setting('pressao_settings_group', 'pressao_candidatos', [
            'sanitize_callback' => [$this, 'sanitize_candidatos'],
            'default' => [],
        ]);
        
        // Seção: Autenticação
        add_settings_section(
            'pressao_auth_section',
            __('Configurações de Autenticação', 'pressao-plugin'),
            null,
            'pressao-settings'
        );
        
        add_settings_field(
            'pressao_keycloak_url',
            __('URL do Keycloak', 'pressao-plugin'),
            [$this, 'render_text_field'],
            'pressao-settings',
            'pressao_auth_section',
            ['field' => 'pressao_keycloak_url', 'type' => 'url']
        );
        
        add_settings_field(
            'pressao_realm',
            __('Realm', 'pressao-plugin'),
            [$this, 'render_text_field'],
            'pressao-settings',
            'pressao_auth_section',
            [
                'field' => 'pressao_realm',
                'description' => __('Ex: pressao, master, etc.', 'pressao-plugin')
            ]
        );

        add_settings_field(
            'pressao_client_id',
            __('Client ID', 'pressao-plugin'),
            [$this, 'render_text_field'],
            'pressao-settings',
            'pressao_auth_section',
            ['field' => 'pressao_client_id']
        );
        
        add_settings_field(
            'pressao_client_secret',
            __('Client Secret', 'pressao-plugin'),
            [$this, 'render_password_field'],
            'pressao-settings',
            'pressao_auth_section',
            ['field' => 'pressao_client_secret']
        );
        
        add_settings_field(
            'pressao_api_url',
            __('URL da API', 'pressao-plugin'),
            [$this, 'render_text_field'],
            'pressao-settings',
            'pressao_auth_section',
            ['field' => 'pressao_api_url', 'type' => 'url']
        );
        
        // Seção: Widget
        add_settings_section(
            'pressao_widget_section',
            __('Configurações do Widget', 'pressao-plugin'),
            null,
            'pressao-settings'
        );
        
        add_settings_field(
            'pressao_campaign_id',
            __('ID da Campanha', 'pressao-plugin'),
            [$this, 'render_text_field'],
            'pressao-settings',
            'pressao_widget_section',
            ['field' => 'pressao_campaign_id']
        );
        
        add_settings_field(
            'pressao_widget_title',
            __('Título do Widget', 'pressao-plugin'),
            [$this, 'render_text_field'],
            'pressao-settings',
            'pressao_widget_section',
            ['field' => 'pressao_widget_title']
        );

        // Seção: Ativista
        add_settings_section(
            'pressao_ativista_section',
            __('Configurações do Ativista', 'pressao-plugin'),
            null,
            'pressao-settings'
        );

        add_settings_field(
            'pressao_ativista_confirm_interval',
            __('Intervalo para confirmar identidade (minutos)', 'pressao-plugin'),
            [$this, 'render_number_field'],
            'pressao-settings',
            'pressao_ativista_section',
            [
                'field' => 'pressao_ativista_confirm_interval',
                'default' => 10,
                'min' => 1,
                'max' => 60
            ]
        );
        
        add_settings_field(
            'pressao_ativista_form_title',
            __('Título do formulário de identificação', 'pressao-plugin'),
            [$this, 'render_text_field'],
            'pressao-settings',
            'pressao_ativista_section',
            ['field' => 'pressao_ativista_form_title']
        );

        add_settings_field(
            'pressao_session_duration',
            __('Duração da sessão do ativista', 'pressao-plugin'),
            [$this, 'render_session_duration_field'],
            'pressao-settings',
            'pressao_ativista_section'
        );

        // Seção: Candidatos
        add_settings_section(
            'pressao_candidatos_section',
            __('Configurações de Candidatos', 'pressao-plugin'),
            null,
            'pressao-settings'
        );

        add_settings_field(
            'pressao_candidatos',
            __('Candidatos', 'pressao-plugin'),
            [$this, 'render_candidatos_field'],
            'pressao-settings',
            'pressao_candidatos_section'
        );
    }
    
    public function render_text_field($args) {
        $field = $args['field'];
        $type = isset($args['type']) ? $args['type'] : 'text';
        $value = get_option($field, '');
        ?>
        <input type="<?php echo esc_attr($type); ?>" 
               name="<?php echo esc_attr($field); ?>" 
               value="<?php echo esc_attr($value); ?>" 
               class="regular-text" />
        <?php
    }
    
    public function render_password_field($args) {
        $field = $args['field'];
        $value = get_option($field, '');
        ?>
        <input type="password" 
               name="<?php echo esc_attr($field); ?>" 
               value="<?php echo esc_attr($value); ?>" 
               class="regular-text" />
        <p class="description">
            <?php esc_html_e('O Client Secret fica guardado no servidor.', 'pressao-plugin'); ?>
        </p>
        <?php
    }
    
    public function render_settings_page() {
        if (!current_user_can('manage_options')) {
            wp_die(__('Sem permissão.', 'pressao-plugin'));
        }
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Pressão Plugin - Configurações', 'pressao-plugin'); ?></h1>
            
            <form method="post" action="options.php">
                <?php
                settings_fields('pressao_settings_group');
                do_settings_sections('pressao-settings');
                submit_button();
                ?>
            </form>
            
            <div class="pressao-usage">
                <h2><?php esc_html_e('Como usar', 'pressao-plugin'); ?></h2>
                <p><?php esc_html_e('Shortcodes disponíveis:', 'pressao-plugin'); ?></p>
                <ul>
                    <li><code>[pressao_widget]</code> - <?php esc_html_e('Widget principal', 'pressao-plugin'); ?></li>
                    <li><code>[pressao_form]</code> - <?php esc_html_e('Apenas formulário', 'pressao-plugin'); ?></li>
                    <li><code>[pressao_list]</code> - <?php esc_html_e('Apenas lista', 'pressao-plugin'); ?></li>
                    <li><code>[pressao_alvos]</code> - <?php esc_html_e('Lista de alvos com ações', 'pressao-plugin'); ?></li>
                    <li><code>[pressao_candidatos]</code> - <?php esc_html_e('Bloco de candidatos configurado no admin', 'pressao-plugin'); ?></li>
                </ul>
                
                <p><?php esc_html_e('Exemplos:', 'pressao-plugin'); ?></p>
                <code>[pressao_widget title="Meu Widget"]</code>
                <br>
                <code>[pressao_form button_text="Enviar"]</code>
                <br>
                <code>[pressao_list limit="5"]</code>
                <br>
                <code>[pressao_alvos campaign="123" show_ativista_form="yes"]</code>
                <br>
                <code>[pressao_candidatos title="Conheça os candidatos"]</code>
                
                <div class="pressao-lgpd-info" style="margin-top: 20px; padding: 15px; background: #f0f8ff; border-radius: 6px; border-left: 4px solid #0073aa;">
                    <h3 style="margin-top: 0;"><?php esc_html_e('Sobre a LGPD', 'pressao-plugin'); ?></h3>
                    <p style="margin-bottom: 0;">
                        <?php esc_html_e('O plugin armazena apenas o nome do ativista no navegador para identificação. Email e telefone são opcionais e só são enviados ao servidor quando o ativista realiza uma ação. A confirmação de identidade exibe apenas o nome, respeitando a Lei Geral de Proteção de Dados.', 'pressao-plugin'); ?>
                    </p>
                </div>
            </div>
        </div>
        <?php
    }

    public function render_number_field($args) {
        $field = $args['field'];
        $value = get_option($field, $args['default'] ?? 10);
        $min = $args['min'] ?? 1;
        $max = $args['max'] ?? 60;
        ?>
        <input type="number" 
               name="<?php echo esc_attr($field); ?>" 
               value="<?php echo esc_attr($value); ?>" 
               min="<?php echo esc_attr($min); ?>" 
               max="<?php echo esc_attr($max); ?>" 
               class="small-text" />
        <p class="description"><?php esc_html_e('Tempo em minutos para perguntar novamente se é o mesmo ativista.', 'pressao-plugin'); ?></p>
        <?php
    }
    public function render_session_duration_field() {
        $value = get_option('pressao_session_duration', '86400');
        $options = [
            '1800' => __('30 minutos', 'pressao-plugin'),
            '3600' => __('1 hora', 'pressao-plugin'),
            '7200' => __('2 horas', 'pressao-plugin'),
            '21600' => __('6 horas', 'pressao-plugin'),
            '43200' => __('12 horas', 'pressao-plugin'),
            '86400' => __('24 horas', 'pressao-plugin'),
            '604800' => __('7 dias', 'pressao-plugin'),
            '2592000' => __('30 dias', 'pressao-plugin'),
            '0' => __('Sessão do navegador (sem expiração)', 'pressao-plugin'),
        ];
        ?>
        <select name="pressao_session_duration">
            <?php foreach ($options as $val => $label) : ?>
                <option value="<?php echo esc_attr($val); ?>" <?php selected($value, $val); ?>>
                    <?php echo esc_html($label); ?>
                </option>
            <?php endforeach; ?>
        </select>
        <p class="description"><?php esc_html_e('Tempo que a sessão do ativista permanece ativa no navegador.', 'pressao-plugin'); ?></p>
        <?php
    }

    public function render_candidatos_field() {
        $candidatos = get_option('pressao_candidatos', []);
        if (!is_array($candidatos) || empty($candidatos)) {
            $candidatos = [[]];
        }
        ?>
        <div class="pressao-candidatos-admin" data-next-index="<?php echo esc_attr(count($candidatos)); ?>">
            <div class="pressao-candidatos-list">
                <?php foreach ($candidatos as $index => $candidato) : ?>
                    <?php $this->render_candidato_admin_item((int) $index, $candidato); ?>
                <?php endforeach; ?>
            </div>
            <button type="button" class="button pressao-add-candidato">
                <?php esc_html_e('Adicionar candidato', 'pressao-plugin'); ?>
            </button>
            <p class="description">
                <?php esc_html_e('As imagens usam a Biblioteca de Mídia do WordPress. Se o WordPress enviar mídias para S3 no futuro, o plugin continuará usando o mesmo attachment ID.', 'pressao-plugin'); ?>
            </p>
        </div>
        <?php
    }

    private function render_candidato_admin_item($index, $candidato) {
        $candidato = is_array($candidato) ? $candidato : [];
        $imagem_id = absint($candidato['imagem_id'] ?? 0);
        $imagem_url = $imagem_id ? wp_get_attachment_image_url($imagem_id, 'thumbnail') : '';
        ?>
        <div class="pressao-candidato-admin-item" data-index="<?php echo esc_attr($index); ?>">
            <p>
                <label>
                    <?php esc_html_e('Nome', 'pressao-plugin'); ?><br>
                    <input type="text"
                           name="pressao_candidatos[<?php echo esc_attr($index); ?>][nome]"
                           value="<?php echo esc_attr($candidato['nome'] ?? ''); ?>"
                           class="regular-text" />
                </label>
            </p>
            <p>
                <label>
                    <?php esc_html_e('Cargo', 'pressao-plugin'); ?><br>
                    <input type="text"
                           name="pressao_candidatos[<?php echo esc_attr($index); ?>][cargo]"
                           value="<?php echo esc_attr($candidato['cargo'] ?? ''); ?>"
                           class="regular-text" />
                </label>
            </p>
            <p>
                <label>
                    <?php esc_html_e('Partido/organização', 'pressao-plugin'); ?><br>
                    <input type="text"
                           name="pressao_candidatos[<?php echo esc_attr($index); ?>][partido]"
                           value="<?php echo esc_attr($candidato['partido'] ?? ''); ?>"
                           class="regular-text" />
                </label>
            </p>
            <p>
                <label>
                    <?php esc_html_e('Link', 'pressao-plugin'); ?><br>
                    <input type="url"
                           name="pressao_candidatos[<?php echo esc_attr($index); ?>][link_url]"
                           value="<?php echo esc_url($candidato['link_url'] ?? ''); ?>"
                           class="regular-text" />
                </label>
            </p>
            <p>
                <label>
                    <?php esc_html_e('Descrição', 'pressao-plugin'); ?><br>
                    <textarea name="pressao_candidatos[<?php echo esc_attr($index); ?>][descricao]"
                              rows="3"
                              class="large-text"><?php echo esc_textarea($candidato['descricao'] ?? ''); ?></textarea>
                </label>
            </p>
            <div class="pressao-candidato-image-field">
                <input type="hidden"
                       class="pressao-candidato-image-id"
                       name="pressao_candidatos[<?php echo esc_attr($index); ?>][imagem_id]"
                       value="<?php echo esc_attr($imagem_id); ?>" />
                <div class="pressao-candidato-image-preview">
                    <?php if ($imagem_url) : ?>
                        <img src="<?php echo esc_url($imagem_url); ?>" alt="" style="max-width: 96px; height: auto;" />
                    <?php endif; ?>
                </div>
                <button type="button" class="button pressao-select-candidato-image">
                    <?php esc_html_e('Selecionar imagem', 'pressao-plugin'); ?>
                </button>
                <button type="button" class="button pressao-remove-candidato-image">
                    <?php esc_html_e('Remover imagem', 'pressao-plugin'); ?>
                </button>
            </div>
            <p>
                <button type="button" class="button link-delete pressao-remove-candidato">
                    <?php esc_html_e('Remover candidato', 'pressao-plugin'); ?>
                </button>
            </p>
            <hr>
        </div>
        <?php
    }

    public function sanitize_candidatos($value) {
        if (!is_array($value)) {
            return [];
        }

        $sanitized = [];
        foreach ($value as $candidato) {
            if (!is_array($candidato)) {
                continue;
            }

            $nome = sanitize_text_field($candidato['nome'] ?? '');
            $cargo = sanitize_text_field($candidato['cargo'] ?? '');
            $partido = sanitize_text_field($candidato['partido'] ?? '');
            $descricao = sanitize_textarea_field($candidato['descricao'] ?? '');
            $link_url = esc_url_raw($candidato['link_url'] ?? '');
            $imagem_id = absint($candidato['imagem_id'] ?? 0);

            if ($nome === '' && $cargo === '' && $partido === '' && $descricao === '' && !$imagem_id) {
                continue;
            }

            $sanitized[] = [
                'nome' => $nome,
                'cargo' => $cargo,
                'partido' => $partido,
                'descricao' => $descricao,
                'link_url' => $link_url,
                'imagem_id' => $imagem_id,
            ];
        }

        return $sanitized;
    }
}

new PressaoPlugin_Admin();