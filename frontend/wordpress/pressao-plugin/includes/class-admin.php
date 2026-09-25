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
        add_filter('wp_redirect', [$this, 'preserve_settings_tab_on_redirect']);
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

    /**
     * Mantém ?tab= após salvar options.php (option group pressao_settings_{tab}).
     *
     * @param string $location
     * @return string
     */
    public function preserve_settings_tab_on_redirect($location) {
        if (!is_string($location) || $location === '') {
            return $location;
        }
        if (empty($_POST['option_page']) || strpos((string) $_POST['option_page'], 'pressao_settings_') !== 0) {
            return $location;
        }
        $tab = sanitize_key(substr((string) $_POST['option_page'], strlen('pressao_settings_')));
        $tabs = $this->get_settings_tabs();
        if (!isset($tabs[$tab])) {
            return $location;
        }
        return add_query_arg('tab', $tab, $location);
    }

    /**
     * Abas da página de configurações (slug => rótulo).
     * Futuro: Alvos e Templates como abas de 1º nível (CRUD da API), não filhas de Geral.
     *
     * @return array<string, string>
     */
    public function get_settings_tabs() {
        return [
            'conexao' => __('Conexão', 'pressao-plugin'),
            'geral' => __('Geral', 'pressao-plugin'),
            'candidatos' => __('Candidatos', 'pressao-plugin'),
            'apoiadores' => __('Apoiadores', 'pressao-plugin'),
            'compartilhamento' => __('Compartilhamento', 'pressao-plugin'),
            'documentacao' => __('Documentação', 'pressao-plugin'),
        ];
    }

    /**
     * @return string
     */
    public function get_current_tab() {
        $tabs = $this->get_settings_tabs();
        $tab = isset($_GET['tab']) ? sanitize_key(wp_unslash($_GET['tab'])) : 'conexao';
        return isset($tabs[$tab]) ? $tab : 'conexao';
    }

    /**
     * Página Settings API da aba (exceto documentação, sem form).
     *
     * @param string $tab
     * @return string
     */
    private function get_settings_page_for_tab($tab) {
        return 'pressao-settings-' . $tab;
    }

    /**
     * Option group da aba (isolado para o options.php não zerar outras abas).
     *
     * @param string $tab
     * @return string
     */
    private function get_option_group_for_tab($tab) {
        return 'pressao_settings_' . $tab;
    }

    public function register_settings() {
        // Conexão
        $page_conexao = $this->get_settings_page_for_tab('conexao');
        $group_conexao = $this->get_option_group_for_tab('conexao');
        register_setting($group_conexao, 'pressao_keycloak_url');
        register_setting($group_conexao, 'pressao_realm');
        register_setting($group_conexao, 'pressao_client_id');
        register_setting($group_conexao, 'pressao_client_secret');
        register_setting($group_conexao, 'pressao_api_url');

        add_settings_section(
            'pressao_auth_section',
            __('Conexão com a API', 'pressao-plugin'),
            null,
            $page_conexao
        );

        add_settings_field(
            'pressao_keycloak_url',
            __('URL do Keycloak', 'pressao-plugin'),
            [$this, 'render_text_field'],
            $page_conexao,
            'pressao_auth_section',
            ['field' => 'pressao_keycloak_url', 'type' => 'url']
        );

        add_settings_field(
            'pressao_realm',
            __('Realm', 'pressao-plugin'),
            [$this, 'render_text_field'],
            $page_conexao,
            'pressao_auth_section',
            [
                'field' => 'pressao_realm',
                'description' => __('Ex: pressao, master, etc.', 'pressao-plugin'),
            ]
        );

        add_settings_field(
            'pressao_client_id',
            __('Client ID', 'pressao-plugin'),
            [$this, 'render_text_field'],
            $page_conexao,
            'pressao_auth_section',
            ['field' => 'pressao_client_id']
        );

        add_settings_field(
            'pressao_client_secret',
            __('Client Secret', 'pressao-plugin'),
            [$this, 'render_password_field'],
            $page_conexao,
            'pressao_auth_section',
            ['field' => 'pressao_client_secret']
        );

        add_settings_field(
            'pressao_api_url',
            __('URL da API', 'pressao-plugin'),
            [$this, 'render_text_field'],
            $page_conexao,
            'pressao_auth_section',
            ['field' => 'pressao_api_url', 'type' => 'url']
        );

        // Geral (widget + ativista)
        $page_geral = $this->get_settings_page_for_tab('geral');
        $group_geral = $this->get_option_group_for_tab('geral');
        register_setting($group_geral, 'pressao_campaign_id');
        register_setting($group_geral, 'pressao_widget_title');
        register_setting($group_geral, 'pressao_ativista_confirm_interval', [
            'type' => 'integer',
            'sanitize_callback' => [$this, 'sanitize_ativista_confirm_interval'],
            'default' => 10,
        ]);
        register_setting($group_geral, 'pressao_ativista_form_title');
        register_setting($group_geral, 'pressao_session_duration');

        add_settings_section(
            'pressao_widget_section',
            __('Widget e campanha do site', 'pressao-plugin'),
            [$this, 'render_geral_widget_section'],
            $page_geral
        );

        add_settings_field(
            'pressao_campaign_id',
            __('ID da Campanha', 'pressao-plugin'),
            [$this, 'render_text_field'],
            $page_geral,
            'pressao_widget_section',
            ['field' => 'pressao_campaign_id']
        );

        add_settings_field(
            'pressao_widget_title',
            __('Título do Widget', 'pressao-plugin'),
            [$this, 'render_text_field'],
            $page_geral,
            'pressao_widget_section',
            ['field' => 'pressao_widget_title']
        );

        add_settings_section(
            'pressao_ativista_section',
            __('Sessão e identificação do ativista', 'pressao-plugin'),
            null,
            $page_geral
        );

        add_settings_field(
            'pressao_ativista_confirm_interval',
            __('Intervalo para confirmar identidade (minutos)', 'pressao-plugin'),
            [$this, 'render_number_field'],
            $page_geral,
            'pressao_ativista_section',
            [
                'field' => 'pressao_ativista_confirm_interval',
                'default' => 10,
                'min' => 1,
                'max' => 60,
            ]
        );

        add_settings_field(
            'pressao_ativista_form_title',
            __('Título do formulário de identificação', 'pressao-plugin'),
            [$this, 'render_text_field'],
            $page_geral,
            'pressao_ativista_section',
            ['field' => 'pressao_ativista_form_title']
        );

        add_settings_field(
            'pressao_session_duration',
            __('Duração da sessão do ativista', 'pressao-plugin'),
            [$this, 'render_session_duration_field'],
            $page_geral,
            'pressao_ativista_section'
        );

        // Candidatos a pressionar: lista via AJAX; form só opções do fluxo
        $page_candidatos = $this->get_settings_page_for_tab('candidatos');
        $group_candidatos = $this->get_option_group_for_tab('candidatos');
        register_setting($group_candidatos, 'pressao_fluxo_limite_candidatos', [
            'sanitize_callback' => [$this, 'sanitize_fluxo_limite_candidatos'],
            'default' => 5,
        ]);
        register_setting($group_candidatos, 'pressao_fluxo_countdown_abrir', [
            'type' => 'boolean',
            'sanitize_callback' => [$this, 'sanitize_fluxo_countdown_abrir'],
            'default' => 0,
        ]);
        register_setting($group_candidatos, 'pressao_fluxo_ajuda', [
            'sanitize_callback' => [$this, 'sanitize_fluxo_ajuda'],
            'default' => [
                'titulo' => '',
                'conteudo' => '',
            ],
        ]);

        add_settings_section(
            'pressao_candidatos_fluxo_section',
            __('Opções do fluxo', 'pressao-plugin'),
            [$this, 'render_candidatos_pressao_section'],
            $page_candidatos
        );

        add_settings_field(
            'pressao_fluxo_limite_candidatos',
            __('Limite de candidatos por marcação (fluxo)', 'pressao-plugin'),
            [$this, 'render_fluxo_limite_field'],
            $page_candidatos,
            'pressao_candidatos_fluxo_section'
        );

        add_settings_field(
            'pressao_fluxo_countdown_abrir',
            __('Contador antes de abrir Instagram', 'pressao-plugin'),
            [$this, 'render_fluxo_countdown_abrir_field'],
            $page_candidatos,
            'pressao_candidatos_fluxo_section'
        );

        add_settings_field(
            'pressao_fluxo_ajuda',
            __('Ajuda do fluxo (?)', 'pressao-plugin'),
            [$this, 'render_fluxo_ajuda_field'],
            $page_candidatos,
            'pressao_candidatos_fluxo_section'
        );

        // Apoiadores: listagem AJAX (sem options.php); tools CSV abaixo

        // Compartilhamento
        $page_share = $this->get_settings_page_for_tab('compartilhamento');
        $group_share = $this->get_option_group_for_tab('compartilhamento');
        register_setting($group_share, 'pressao_compartilhamento', [
            'sanitize_callback' => [$this, 'sanitize_compartilhamento'],
            'default' => [],
        ]);

        add_settings_section(
            'pressao_compartilhamento_section',
            __('Compartilhamento', 'pressao-plugin'),
            null,
            $page_share
        );

        add_settings_field(
            'pressao_compartilhamento',
            __('Configuração', 'pressao-plugin'),
            [$this, 'render_compartilhamento_field'],
            $page_share,
            'pressao_compartilhamento_section'
        );
    }
    
    public function render_text_field($args) {
        $field = $args['field'];
        $type = isset($args['type']) ? $args['type'] : 'text';
        $constant = strtoupper($field);
        $overridden = defined($constant);
        $value = $overridden ? constant($constant) : get_option($field, '');
        ?>
        <input type="<?php echo esc_attr($type); ?>"
               name="<?php echo esc_attr($field); ?>"
               value="<?php echo esc_attr($value); ?>"
               class="regular-text"
               <?php disabled($overridden); ?> />
        <?php if ($overridden) : ?>
            <p class="description">
                <?php printf(esc_html__('Definido via wp-config.php (constante %s), não editável aqui.', 'pressao-plugin'), esc_html($constant)); ?>
            </p>
        <?php endif; ?>
        <?php
    }

    public function render_password_field($args) {
        $field = $args['field'];
        $constant = strtoupper($field);
        $overridden = defined($constant);
        $value = $overridden ? constant($constant) : get_option($field, '');
        ?>
        <input type="password"
               name="<?php echo esc_attr($field); ?>"
               value="<?php echo esc_attr($value); ?>"
               class="regular-text"
               <?php disabled($overridden); ?> />
        <p class="description">
            <?php if ($overridden) : ?>
                <?php printf(esc_html__('Definido via wp-config.php (constante %s), não editável aqui.', 'pressao-plugin'), esc_html($constant)); ?>
            <?php else : ?>
                <?php esc_html_e('O Client Secret fica guardado no servidor.', 'pressao-plugin'); ?>
            <?php endif; ?>
        </p>
        <?php
    }
    
    public function render_settings_page() {
        if (!current_user_can('manage_options')) {
            wp_die(__('Sem permissão.', 'pressao-plugin'));
        }

        $tabs = $this->get_settings_tabs();
        $current_tab = $this->get_current_tab();
        $base_url = admin_url('options-general.php?page=pressao-settings');
        ?>
        <div class="wrap pressao-settings-wrap">
            <h1><?php esc_html_e('Pressão Plugin - Configurações', 'pressao-plugin'); ?></h1>

            <nav class="nav-tab-wrapper pressao-settings-tabs" aria-label="<?php esc_attr_e('Seções de configuração', 'pressao-plugin'); ?>">
                <?php foreach ($tabs as $slug => $label) : ?>
                    <a href="<?php echo esc_url(add_query_arg('tab', $slug, $base_url)); ?>"
                       class="nav-tab <?php echo $current_tab === $slug ? 'nav-tab-active' : ''; ?>">
                        <?php echo esc_html($label); ?>
                    </a>
                <?php endforeach; ?>
            </nav>

            <div class="pressao-settings-tab-panel" data-tab="<?php echo esc_attr($current_tab); ?>">
                <?php if ($current_tab === 'documentacao') : ?>
                    <?php $this->render_documentacao_tab(); ?>
                <?php elseif ($current_tab === 'apoiadores') : ?>
                    <h2><?php esc_html_e('Candidatos apoiadores', 'pressao-plugin'); ?></h2>
                    <p><?php esc_html_e('Base dos que já apoiam a pauta: botão/lista do [pressao_fluxo] e shortcode [pressao_candidatos]. Use as ferramentas abaixo para importar CSV ou remover da base.', 'pressao-plugin'); ?></p>
                    <?php PressaoPlugin_Candidatos_Admin_List::render(PressaoPlugin_Candidatos_Admin_List::OPTION_APOIADORES); ?>
                    <?php $this->render_apoiadores_tools(); ?>
                <?php elseif ($current_tab === 'candidatos') : ?>
                    <h2><?php esc_html_e('Candidatos a pressionar', 'pressao-plugin'); ?></h2>
                    <p><?php esc_html_e('Base usada na busca/seleção do [pressao_fluxo] (candidatos a pressionar).', 'pressao-plugin'); ?></p>
                    <?php PressaoPlugin_Candidatos_Admin_List::render(PressaoPlugin_Candidatos_Admin_List::OPTION_PRESSIONAR); ?>
                    <form method="post" action="options.php" class="pressao-settings-fluxo-form">
                        <?php
                        settings_fields($this->get_option_group_for_tab('candidatos'));
                        do_settings_sections($this->get_settings_page_for_tab('candidatos'));
                        submit_button();
                        ?>
                    </form>
                <?php else : ?>
                    <form method="post" action="options.php">
                        <?php
                        $group = $this->get_option_group_for_tab($current_tab);
                        $page = $this->get_settings_page_for_tab($current_tab);
                        settings_fields($group);
                        do_settings_sections($page);
                        submit_button();
                        ?>
                    </form>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }

    private function render_documentacao_tab() {
        $shortcodes = [
            [
                'tag' => '[pressao_alvos]',
                'title' => __('Lista de alvos com ações', 'pressao-plugin'),
                'body' => __('Shortcode principal: lista os canais da campanha (Instagram, TikTok, e-mail etc.) e permite ao ativista agir. Se o compartilhamento estiver ativo na aba Compartilhamento, o botão aparece por último na lista.', 'pressao-plugin'),
                'example' => '[pressao_alvos campaign="uuid" show_ativista_form="yes" ordem="instagram,tiktok,email" tempo_email="1 min"]',
            ],
            [
                'tag' => '[pressao_fluxo]',
                'title' => __('Fluxo sequencial (Instagram)', 'pressao-plugin'),
                'body' => __('Wizard isolado por alvo/canal: marcar candidatos a pressionar, copiar/abrir Instagram, confirmar e opcionalmente deixar contato. Usa as bases das abas Candidatos e Apoiadores. Requer alvo_id e canal.', 'pressao-plugin'),
                'example' => '[pressao_fluxo alvo_id="uuid-do-alvo" canal="instagram"]',
            ],
            [
                'tag' => '[pressao_candidatos]',
                'title' => __('Bloco de candidatos apoiadores', 'pressao-plugin'),
                'body' => __('Exibe a base editorial de quem já apoia a pauta (option pressao_candidatos_apoiadores), configurada na aba Apoiadores.', 'pressao-plugin'),
                'example' => '[pressao_candidatos title="Conheça os candidatos"]',
            ],
            [
                'tag' => '[pressao_contador]',
                'title' => __('Contador de ações confirmadas', 'pressao-plugin'),
                'body' => __('Mostra o total de ações confirmadas da campanha (atualiza com cache curto) e pode animar quando o ativista conclui uma ação na mesma página.', 'pressao-plugin'),
                'example' => '[pressao_contador campaign="uuid" label="ações confirmadas"]',
            ],
            [
                'tag' => '[pressao_progresso]',
                'title' => __('Progresso pessoal do ativista', 'pressao-plugin'),
                'body' => __('Barra done/total com base nas ações realizadas neste navegador (cookie). Zera quando a sessão do ativista é limpa (“Não sou eu” ou expiração).', 'pressao-plugin'),
                'example' => '[pressao_progresso]',
            ],
            [
                'tag' => '[pressao_widget]',
                'title' => __('Widget completo (legado)', 'pressao-plugin'),
                'body' => __('Combina formulário e lista num único bloco. Prefira [pressao_alvos] para campanhas novas com canais e compartilhamento.', 'pressao-plugin'),
                'example' => '[pressao_widget title="Meu Widget"]',
            ],
            [
                'tag' => '[pressao_form]',
                'title' => __('Somente formulário (legado)', 'pressao-plugin'),
                'body' => __('Renderiza só o formulário de identificação/ação do widget antigo.', 'pressao-plugin'),
                'example' => '[pressao_form button_text="Enviar"]',
            ],
            [
                'tag' => '[pressao_list]',
                'title' => __('Somente lista (legado)', 'pressao-plugin'),
                'body' => __('Renderiza só a lista do widget antigo, sem o formulário.', 'pressao-plugin'),
                'example' => '[pressao_list limit="5"]',
            ],
        ];
        ?>
        <div class="pressao-docs">
            <header class="pressao-docs-intro">
                <h2><?php esc_html_e('Como usar', 'pressao-plugin'); ?></h2>
                <p>
                    <?php esc_html_e('O Pressão Plugin conecta este site à API de pressão multicanal. Configure a conexão (Keycloak + API) e o ID da campanha nas abas Conexão e Geral; depois insira os shortcodes nas páginas ou posts.', 'pressao-plugin'); ?>
                </p>
                <p class="description">
                    <?php esc_html_e('Atributos como campaign usam o ID da campanha da aba Geral quando omitidos. Detalhes completos de atributos estão no README do plugin.', 'pressao-plugin'); ?>
                </p>
            </header>

            <div class="pressao-docs-grid">
                <?php foreach ($shortcodes as $item) : ?>
                    <article class="pressao-docs-card">
                        <h3 class="pressao-docs-card-title">
                            <code><?php echo esc_html($item['tag']); ?></code>
                            <span><?php echo esc_html($item['title']); ?></span>
                        </h3>
                        <p class="pressao-docs-card-body"><?php echo esc_html($item['body']); ?></p>
                        <pre class="pressao-docs-example"><code><?php echo esc_html($item['example']); ?></code></pre>
                    </article>
                <?php endforeach; ?>
            </div>

            <aside class="pressao-lgpd-info" aria-labelledby="pressao-docs-lgpd-title">
                <h3 id="pressao-docs-lgpd-title"><?php esc_html_e('Sobre a LGPD', 'pressao-plugin'); ?></h3>
                <p>
                    <?php esc_html_e('O plugin armazena apenas o nome do ativista no navegador para identificação. Email e telefone são opcionais e só são enviados ao servidor quando o ativista realiza uma ação. A confirmação de identidade exibe apenas o nome, respeitando a Lei Geral de Proteção de Dados.', 'pressao-plugin'); ?>
                </p>
            </aside>
        </div>
        <?php
    }

    public function render_geral_widget_section() {
        echo '<p>' . esc_html__(
            'Vínculo deste site à campanha na API e textos do widget. Alvos e templates da campanha continuam sendo geridos pela API (abas futuras no admin).',
            'pressao-plugin'
        ) . '</p>';
    }

    public function sanitize_ativista_confirm_interval($value) {
        $n = absint($value);
        if ($n < 1) {
            $n = 1;
        }
        if ($n > 60) {
            $n = 60;
        }
        return $n;
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

    public function render_candidatos_pressao_section() {
        echo '<p>' . esc_html__(
            'Preferências do [pressao_fluxo] (lista de candidatos fica acima e salva item a item).',
            'pressao-plugin'
        ) . '</p>';
    }

    private function render_apoiadores_tools() {
        $apoiadores = get_option('pressao_candidatos_apoiadores', []);
        if (!is_array($apoiadores)) {
            $apoiadores = [];
        }
        ?>
        <div class="pressao-apoiadores-tools">
            <h2><?php esc_html_e('Ferramentas — candidatos apoiadores', 'pressao-plugin'); ?></h2>

            <div class="pressao-admin-card">
                <h3><?php esc_html_e('Importar CSV (incremental)', 'pressao-plugin'); ?></h3>
                <p class="description">
                    <?php esc_html_e('Colunas: nome, cargo, partido, descricao, instagram (ou link_url), imagem_url (opcional). Novos @ são adicionados; @ existentes são atualizados; quem não está no CSV permanece. Imagens públicas são baixadas para uploads/candidatos/.', 'pressao-plugin'); ?>
                </p>
                <form method="post"
                      action="<?php echo esc_url(admin_url('admin-post.php')); ?>"
                      enctype="multipart/form-data">
                    <input type="hidden" name="action" value="<?php echo esc_attr(PressaoPlugin_Candidatos_Import::ACTION_IMPORT); ?>" />
                    <?php wp_nonce_field(PressaoPlugin_Candidatos_Import::ACTION_IMPORT); ?>
                    <p>
                        <input type="file" name="pressao_apoiadores_csv" accept=".csv,text/csv" required />
                    </p>
                    <p class="pressao-admin-actions">
                        <?php submit_button(__('Importar CSV', 'pressao-plugin'), 'secondary', 'submit', false); ?>
                        <a class="button"
                           href="<?php echo esc_url(PRESSAO_PLUGIN_URL . 'assets/examples/candidatos-apoiadores-exemplo.csv'); ?>"
                           download="candidatos-apoiadores-exemplo.csv">
                            <?php esc_html_e('Baixar CSV de exemplo', 'pressao-plugin'); ?>
                        </a>
                    </p>
                </form>
            </div>

            <div class="pressao-admin-card">
                <h3><?php esc_html_e('Remover da base', 'pressao-plugin'); ?></h3>
                <p class="description">
                    <?php esc_html_e('Busque por nome ou @, selecione um ou mais e remova. Não apaga arquivos da Media Library.', 'pressao-plugin'); ?>
                </p>
                <form method="post"
                      action="<?php echo esc_url(admin_url('admin-post.php')); ?>"
                      class="pressao-apoiadores-remove-form">
                    <input type="hidden" name="action" value="<?php echo esc_attr(PressaoPlugin_Candidatos_Import::ACTION_REMOVE); ?>" />
                    <?php wp_nonce_field(PressaoPlugin_Candidatos_Import::ACTION_REMOVE); ?>
                    <p>
                        <select id="pressao-apoiadores-remove-select"
                                name="pressao_apoiadores_remove[]"
                                multiple
                                placeholder="<?php esc_attr_e('Digite nome ou @', 'pressao-plugin'); ?>"
                                class="pressao-apoiadores-remove-select">
                            <?php foreach ($apoiadores as $candidato) : ?>
                                <?php
                                if (!is_array($candidato)) {
                                    continue;
                                }
                                $handle = PressaoPlugin_Candidatos_Import::normalize_handle($candidato['link_url'] ?? '');
                                if ($handle === '') {
                                    continue;
                                }
                                $nome = trim((string) ($candidato['nome'] ?? ''));
                                $label = $nome !== '' ? $nome . ' ' . $handle : $handle;
                                ?>
                                <option value="<?php echo esc_attr($handle); ?>">
                                    <?php echo esc_html($label); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </p>
                    <?php
                    submit_button(
                        __('Remover da base', 'pressao-plugin'),
                        'delete',
                        'submit',
                        false,
                        ['id' => 'pressao-apoiadores-remove-btn']
                    );
                    ?>
                </form>
            </div>
        </div>
        <?php
    }

    public function sanitize_candidatos($value) {
        return PressaoPlugin_Candidatos_Admin_List::sanitize_list($value);
    }

    /**
     * Normaliza @handle do Instagram (aceita URL de perfil ou @user).
     */
    public function sanitize_instagram_handle($value) {
        return PressaoPlugin_Candidatos_Admin_List::sanitize_instagram_handle($value);
    }

    public function sanitize_fluxo_limite_candidatos($value) {
        $n = absint($value);
        if ($n < 1) {
            $n = 1;
        }
        if ($n > 20) {
            $n = 20;
        }
        return $n;
    }

    public function render_fluxo_limite_field() {
        $value = (int) get_option('pressao_fluxo_limite_candidatos', 5);
        ?>
        <input type="number"
               name="pressao_fluxo_limite_candidatos"
               value="<?php echo esc_attr($value); ?>"
               min="1"
               max="20"
               class="small-text" />
        <p class="description">
            <?php esc_html_e('Máximo de candidatos que o ativista pode marcar na mensagem do [pressao_fluxo].', 'pressao-plugin'); ?>
        </p>
        <?php
    }

    public function sanitize_fluxo_countdown_abrir($value) {
        return !empty($value) ? 1 : 0;
    }

    public function render_fluxo_countdown_abrir_field() {
        $value = (int) get_option('pressao_fluxo_countdown_abrir', 0);
        ?>
        <input type="hidden" name="pressao_fluxo_countdown_abrir" value="0" />
        <label>
            <input type="checkbox"
                   name="pressao_fluxo_countdown_abrir"
                   value="1"
                   <?php checked($value, 1); ?> />
            <?php esc_html_e('Aguardar contador e mostrar aviso antes de abrir o Instagram', 'pressao-plugin'); ?>
        </label>
        <p class="description">
            <?php esc_html_e('Desligado (padrão): abre no clique, sem toast. Ligado: toast com countdown e só então abre. No mobile a abertura tenta o app Instagram (para o voltar nativo retornar ao fluxo); no desktop abre em nova aba.', 'pressao-plugin'); ?>
        </p>
        <?php
    }

    public function sanitize_fluxo_ajuda($value) {
        if (!is_array($value)) {
            return [
                'titulo' => '',
                'conteudo' => '',
            ];
        }

        return [
            'titulo' => sanitize_text_field($value['titulo'] ?? ''),
            'conteudo' => wp_kses_post($value['conteudo'] ?? ''),
        ];
    }

    public function render_fluxo_ajuda_field() {
        $ajuda = get_option('pressao_fluxo_ajuda', []);
        if (!is_array($ajuda)) {
            $ajuda = [];
        }
        $titulo = $ajuda['titulo'] ?? '';
        $conteudo = $ajuda['conteudo'] ?? '';
        ?>
        <p>
            <label>
                <?php esc_html_e('Título', 'pressao-plugin'); ?><br>
                <input type="text"
                       name="pressao_fluxo_ajuda[titulo]"
                       value="<?php echo esc_attr($titulo); ?>"
                       class="regular-text"
                       placeholder="<?php esc_attr_e('Como funciona?', 'pressao-plugin'); ?>" />
            </label>
        </p>
        <p>
            <label for="pressao_fluxo_ajuda_conteudo"><?php esc_html_e('Conteúdo (HTML permitido)', 'pressao-plugin'); ?></label>
        </p>
        <?php
        wp_editor(
            $conteudo,
            'pressao_fluxo_ajuda_conteudo',
            [
                'textarea_name' => 'pressao_fluxo_ajuda[conteudo]',
                'textarea_rows' => 10,
                'media_buttons' => false,
                'teeny' => true,
                'quicktags' => true,
            ]
        );
        ?>
        <p class="description">
            <?php esc_html_e('Exibido no modal/drawer ao clicar no ? do [pressao_fluxo].', 'pressao-plugin'); ?>
        </p>
        <?php
    }

    public function render_compartilhamento_field() {
        $config = get_option('pressao_compartilhamento', []);
        if (!is_array($config)) {
            $config = [];
        }
        $imagens = isset($config['imagens']) && is_array($config['imagens']) ? $config['imagens'] : [];
        if (empty($imagens)) {
            $imagens = [[]];
        }
        $ativo = !empty($config['ativo']);
        ?>
        <div class="pressao-compartilhamento-admin">
            <p>
                <label>
                    <input type="checkbox"
                           name="pressao_compartilhamento[ativo]"
                           value="1"
                           <?php checked($ativo); ?> />
                    <?php esc_html_e('Exibir botão de compartilhamento no final da lista de alvos', 'pressao-plugin'); ?>
                </label>
            </p>

            <h4><?php esc_html_e('Botão na lista', 'pressao-plugin'); ?></h4>
            <p>
                <label>
                    <?php esc_html_e('Título', 'pressao-plugin'); ?><br>
                    <input type="text"
                           name="pressao_compartilhamento[titulo]"
                           value="<?php echo esc_attr($config['titulo'] ?? ''); ?>"
                           class="regular-text"
                           placeholder="<?php esc_attr_e('Compartilhar ação', 'pressao-plugin'); ?>" />
                </label>
            </p>
            <p>
                <label>
                    <?php esc_html_e('Subtítulo', 'pressao-plugin'); ?><br>
                    <input type="text"
                           name="pressao_compartilhamento[subtitulo]"
                           value="<?php echo esc_attr($config['subtitulo'] ?? ''); ?>"
                           class="regular-text"
                           placeholder="<?php esc_attr_e('Ajude a chegar em mais gente', 'pressao-plugin'); ?>" />
                </label>
            </p>
            <p>
                <label>
                    <?php esc_html_e('Tempo estimado', 'pressao-plugin'); ?><br>
                    <input type="text"
                           name="pressao_compartilhamento[tempo]"
                           value="<?php echo esc_attr($config['tempo'] ?? ''); ?>"
                           class="small-text"
                           placeholder="3 min" />
                </label>
            </p>

            <h4><?php esc_html_e('Overlay', 'pressao-plugin'); ?></h4>
            <p>
                <label>
                    <?php esc_html_e('Título do overlay', 'pressao-plugin'); ?><br>
                    <input type="text"
                           name="pressao_compartilhamento[overlay_titulo]"
                           value="<?php echo esc_attr($config['overlay_titulo'] ?? ''); ?>"
                           class="regular-text"
                           placeholder="<?php esc_attr_e('Compartilhe e aumente o seu impacto', 'pressao-plugin'); ?>" />
                </label>
            </p>
            <p>
                <label>
                    <?php esc_html_e('Link para compartilhar', 'pressao-plugin'); ?><br>
                    <input type="url"
                           name="pressao_compartilhamento[link]"
                           value="<?php echo esc_url($config['link'] ?? ''); ?>"
                           class="regular-text" />
                </label>
            </p>
            <p>
                <label>
                    <?php esc_html_e('Mensagem pré-definida', 'pressao-plugin'); ?><br>
                    <textarea name="pressao_compartilhamento[mensagem]"
                              rows="3"
                              class="large-text"><?php echo esc_textarea($config['mensagem'] ?? ''); ?></textarea>
                </label>
                <span class="description">
                    <?php esc_html_e('Usada no WhatsApp (wa.me/?text=) quando a URL customizada estiver vazia.', 'pressao-plugin'); ?>
                </span>
            </p>
            <p>
                <label>
                    <?php esc_html_e('URL WhatsApp (opcional)', 'pressao-plugin'); ?><br>
                    <input type="url"
                           name="pressao_compartilhamento[whatsapp_url]"
                           value="<?php echo esc_url($config['whatsapp_url'] ?? ''); ?>"
                           class="regular-text" />
                </label>
            </p>
            <p>
                <label>
                    <?php esc_html_e('URL Instagram', 'pressao-plugin'); ?><br>
                    <input type="url"
                           name="pressao_compartilhamento[instagram_url]"
                           value="<?php echo esc_url($config['instagram_url'] ?? ''); ?>"
                           class="regular-text" />
                </label>
            </p>
            <p>
                <label>
                    <?php esc_html_e('URL Messenger', 'pressao-plugin'); ?><br>
                    <input type="url"
                           name="pressao_compartilhamento[messenger_url]"
                           value="<?php echo esc_url($config['messenger_url'] ?? ''); ?>"
                           class="regular-text" />
                </label>
            </p>

            <h4><?php esc_html_e('Imagens para postar', 'pressao-plugin'); ?></h4>
            <p>
                <label>
                    <?php esc_html_e('Título do card', 'pressao-plugin'); ?><br>
                    <input type="text"
                           name="pressao_compartilhamento[imagens_titulo]"
                           value="<?php echo esc_attr($config['imagens_titulo'] ?? ''); ?>"
                           class="regular-text"
                           placeholder="<?php esc_attr_e('Imagens para postar', 'pressao-plugin'); ?>" />
                </label>
            </p>
            <p>
                <label>
                    <?php esc_html_e('Subtítulo do card', 'pressao-plugin'); ?><br>
                    <input type="text"
                           name="pressao_compartilhamento[imagens_subtitulo]"
                           value="<?php echo esc_attr($config['imagens_subtitulo'] ?? ''); ?>"
                           class="regular-text"
                           placeholder="<?php esc_attr_e('baixe imagens prontas para postar nas redes', 'pressao-plugin'); ?>" />
                </label>
            </p>
            <p>
                <label>
                    <?php esc_html_e('Instrução da tela de download', 'pressao-plugin'); ?><br>
                    <textarea name="pressao_compartilhamento[imagens_instrucao]"
                              rows="2"
                              class="large-text"><?php echo esc_textarea($config['imagens_instrucao'] ?? ''); ?></textarea>
                </label>
            </p>

            <div class="pressao-share-imagens-admin" data-next-index="<?php echo esc_attr(count($imagens)); ?>">
                <div class="pressao-share-imagens-list">
                    <?php foreach ($imagens as $index => $imagem) : ?>
                        <?php $this->render_share_imagem_admin_item((int) $index, $imagem); ?>
                    <?php endforeach; ?>
                </div>
                <button type="button" class="button pressao-add-share-imagem">
                    <?php esc_html_e('Adicionar imagem', 'pressao-plugin'); ?>
                </button>
                <p class="description">
                    <?php esc_html_e('As imagens usam a Biblioteca de Mídia do WordPress (attachment ID).', 'pressao-plugin'); ?>
                </p>
            </div>
        </div>
        <?php
    }

    private function render_share_imagem_admin_item($index, $imagem) {
        $imagem = is_array($imagem) ? $imagem : [];
        $imagem_id = absint($imagem['imagem_id'] ?? 0);
        $imagem_url = $imagem_id ? wp_get_attachment_image_url($imagem_id, 'thumbnail') : '';
        ?>
        <div class="pressao-share-imagem-admin-item" data-index="<?php echo esc_attr($index); ?>">
            <p>
                <label>
                    <?php esc_html_e('Rótulo', 'pressao-plugin'); ?><br>
                    <input type="text"
                           name="pressao_compartilhamento[imagens][<?php echo esc_attr($index); ?>][rotulo]"
                           value="<?php echo esc_attr($imagem['rotulo'] ?? ''); ?>"
                           class="regular-text"
                           placeholder="<?php esc_attr_e('Story/Status', 'pressao-plugin'); ?>" />
                </label>
            </p>
            <div class="pressao-share-imagem-field">
                <input type="hidden"
                       class="pressao-share-imagem-id"
                       name="pressao_compartilhamento[imagens][<?php echo esc_attr($index); ?>][imagem_id]"
                       value="<?php echo esc_attr($imagem_id); ?>" />
                <div class="pressao-share-imagem-preview">
                    <?php if ($imagem_url) : ?>
                        <img src="<?php echo esc_url($imagem_url); ?>" alt="" />
                    <?php endif; ?>
                </div>
                <button type="button" class="button pressao-select-share-imagem">
                    <?php esc_html_e('Selecionar imagem', 'pressao-plugin'); ?>
                </button>
                <button type="button" class="button pressao-remove-share-imagem-file">
                    <?php esc_html_e('Remover imagem', 'pressao-plugin'); ?>
                </button>
            </div>
            <p>
                <button type="button" class="button link-delete pressao-remove-share-imagem">
                    <?php esc_html_e('Remover item', 'pressao-plugin'); ?>
                </button>
            </p>
            <hr>
        </div>
        <?php
    }

    public function sanitize_compartilhamento($value) {
        if (!is_array($value)) {
            return [];
        }

        $imagens = [];
        if (isset($value['imagens']) && is_array($value['imagens'])) {
            foreach ($value['imagens'] as $imagem) {
                if (!is_array($imagem)) {
                    continue;
                }
                $rotulo = sanitize_text_field($imagem['rotulo'] ?? '');
                $imagem_id = absint($imagem['imagem_id'] ?? 0);
                if ($rotulo === '' && !$imagem_id) {
                    continue;
                }
                $imagens[] = [
                    'rotulo' => $rotulo,
                    'imagem_id' => $imagem_id,
                ];
            }
        }

        return [
            'ativo' => !empty($value['ativo']) ? 1 : 0,
            'titulo' => sanitize_text_field($value['titulo'] ?? ''),
            'subtitulo' => sanitize_text_field($value['subtitulo'] ?? ''),
            'tempo' => sanitize_text_field($value['tempo'] ?? ''),
            'overlay_titulo' => sanitize_text_field($value['overlay_titulo'] ?? ''),
            'link' => esc_url_raw($value['link'] ?? ''),
            'mensagem' => sanitize_textarea_field($value['mensagem'] ?? ''),
            'whatsapp_url' => esc_url_raw($value['whatsapp_url'] ?? ''),
            'instagram_url' => esc_url_raw($value['instagram_url'] ?? ''),
            'messenger_url' => esc_url_raw($value['messenger_url'] ?? ''),
            'imagens_titulo' => sanitize_text_field($value['imagens_titulo'] ?? ''),
            'imagens_subtitulo' => sanitize_text_field($value['imagens_subtitulo'] ?? ''),
            'imagens_instrucao' => sanitize_textarea_field($value['imagens_instrucao'] ?? ''),
            'imagens' => $imagens,
        ];
    }
}

new PressaoPlugin_Admin();