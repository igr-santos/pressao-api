<?php
/**
 * Plugin Name: Pressão Plugin
 * Plugin URI: https://github.com/bonde-org/pressao-api/tree/main/frontend/wordpress/pressao-plugin
 * Description: Widget para integração com Keycloak e API Pressão
 * Version: 1.0.0
 * Author: Igor Santos
 * Author URI: https://github.com/igr-santos
 * License: GPL v2 or later
 * Text Domain: pressao-plugin
 */

// Previne acesso direto
if (!defined('ABSPATH')) {
    exit;
}

// Define constantes
define('PRESSAO_PLUGIN_VERSION', '1.0.0');
define('PRESSAO_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('PRESSAO_PLUGIN_URL', plugin_dir_url(__FILE__));
define('PRESSAO_PLUGIN_BASENAME', plugin_basename(__FILE__));

/**
 * Versão pra cache-busting de um asset. Usa a data de modificação do
 * arquivo (sempre muda quando o conteúdo muda, forçando o navegador a
 * buscar a versão nova) em vez da PRESSAO_PLUGIN_VERSION fixa — sem isso,
 * qualquer alteração em CSS/JS fica presa no cache do navegador de quem
 * já visitou o site antes, mesmo o servidor já servindo o conteúdo certo.
 *
 * @param string $relative_path Caminho relativo à raiz do plugin (ex: 'assets/css/fluxo.css').
 * @return string
 */
function pressao_plugin_asset_version($relative_path) {
    $path = PRESSAO_PLUGIN_DIR . $relative_path;
    return is_readable($path) ? (string) filemtime($path) : PRESSAO_PLUGIN_VERSION;
}

// Classe principal
final class PressaoPlugin {
    
    private static $instance = null;
    
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        register_activation_hook(__FILE__, [$this, 'activate']);
        register_deactivation_hook(__FILE__, [$this, 'deactivate']);
        
        $this->load_dependencies();
        
        add_action('init', [$this, 'init']);
        add_action('plugins_loaded', [$this, 'load_textdomain']);
        add_action('wp_enqueue_scripts', [$this, 'enqueue_assets']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_assets']);
    }
    
    private function load_dependencies() {
        require_once PRESSAO_PLUGIN_DIR . 'includes/class-main.php';
        require_once PRESSAO_PLUGIN_DIR . 'includes/class-admin.php';
        require_once PRESSAO_PLUGIN_DIR . 'includes/class-candidatos-admin-list.php';
        require_once PRESSAO_PLUGIN_DIR . 'includes/class-candidatos-import.php';
        require_once PRESSAO_PLUGIN_DIR . 'includes/class-api.php';
        require_once PRESSAO_PLUGIN_DIR . 'includes/class-shortcode.php';
        require_once PRESSAO_PLUGIN_DIR . 'includes/class-ajax.php'; // NOVO
    }
    
    public function activate() {
        if (version_compare(PHP_VERSION, '7.4', '<')) {
            deactivate_plugins(PRESSAO_PLUGIN_BASENAME);
            wp_die('Pressão Plugin requer PHP 7.4 ou superior.');
        }
        
        $this->create_default_options();
        
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('Pressão Plugin ativado com sucesso!');
        }
    }
    
    public function deactivate() {
        delete_transient('pressao_keycloak_token');
        delete_transient('pressao_campanha_*'); // Limpa cache de campanhas
        
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('Pressão Plugin desativado.');
        }
    }
    
    private function create_default_options() {
        $defaults = [
            'pressao_keycloak_url' => '',
            'pressao_client_id' => '',
            'pressao_client_secret' => '',
            'pressao_api_url' => '',
            'pressao_campaign_id' => '',
            'pressao_widget_title' => 'Pressão Widget',
            'pressao_candidatos' => [],
            'pressao_candidatos_apoiadores' => [],
            'pressao_fluxo_limite_candidatos' => 5,
            'pressao_fluxo_countdown_abrir' => 0,
            'pressao_fluxo_ajuda' => [
                'titulo' => '',
                'conteudo' => '',
            ],
            'pressao_compartilhamento' => [],
        ];
        
        foreach ($defaults as $key => $value) {
            if (get_option($key) === false) {
                add_option($key, $value);
            }
        }
    }
    
    public function init() {
        // Shortcodes já registrados na classe
    }
    
    public function load_textdomain() {
        load_plugin_textdomain(
            'pressao-plugin',
            false,
            dirname(PRESSAO_PLUGIN_BASENAME) . '/languages/'
        );
    }
    
    public function enqueue_assets() {
        global $post;
        if (!is_a($post, 'WP_Post')) {
            return;
        }
        
        $content = $post->post_content . ' ' . $this->decode_vc_raw_html_shortcodes($post->post_content);

        $has_shortcode = has_shortcode($content, 'pressao_widget') ||
                         has_shortcode($content, 'pressao_form') ||
                         has_shortcode($content, 'pressao_list') ||
                         has_shortcode($content, 'pressao_alvos') ||
                         has_shortcode($content, 'pressao_contador') ||
                         has_shortcode($content, 'pressao_progresso') ||
                         has_shortcode($content, 'pressao_candidatos') ||
                         has_shortcode($content, 'pressao_fluxo');

        $has_fluxo = has_shortcode($content, 'pressao_fluxo');
        $has_legacy = has_shortcode($content, 'pressao_widget') ||
                      has_shortcode($content, 'pressao_form') ||
                      has_shortcode($content, 'pressao_list') ||
                      has_shortcode($content, 'pressao_alvos') ||
                      has_shortcode($content, 'pressao_contador') ||
                      has_shortcode($content, 'pressao_progresso') ||
                      has_shortcode($content, 'pressao_candidatos');
        
        if ($has_shortcode) {
            $icons_url = PRESSAO_PLUGIN_URL . 'assets/icons/';
            $icon_vars = sprintf(
                ':root{--pressao-icon-instagram:url("%1$sinstagram.svg");--pressao-icon-tiktok:url("%1$stiktok.svg");--pressao-icon-email:url("%1$semail.svg");--pressao-icon-seta:url("%1$sseta.svg");--pressao-icon-seta-direita:url("%1$sseta-direita.svg");--pressao-icon-abrir-externo:url("%1$sabrir-externo.svg");--pressao-icon-check-circulo:url("%1$scheck-circulo.svg");--pressao-icon-raio:url("%1$sraio-barra-progresso.svg");--pressao-icon-compartilhar:url("%1$scompartilhar.svg");--pressao-icon-copiar:url("%1$scopiar.svg");--pressao-icon-download:url("%1$sdownload.svg");--pressao-icon-whatsapp:url("%1$swhatsapp.svg");--pressao-icon-messenger:url("%1$smessenger.svg");--pressao-icon-seta-circulo:url("%1$sseta-com-circulo.svg");}',
                esc_url_raw($icons_url)
            );

            if ($has_legacy || $has_fluxo) {
                wp_enqueue_style(
                    'pressao-plugin',
                    PRESSAO_PLUGIN_URL . 'assets/css/style.css',
                    [],
                    pressao_plugin_asset_version('assets/css/style.css')
                );
                wp_add_inline_style('pressao-plugin', $icon_vars);
            }

            if ($has_legacy) {
                wp_enqueue_script(
                    'pressao-plugin',
                    PRESSAO_PLUGIN_URL . 'assets/js/widget.js',
                    [],
                    pressao_plugin_asset_version('assets/js/widget.js'),
                    true
                );

                wp_localize_script('pressao-plugin', 'pressaoData', [
                    'apiUrl' => get_option('pressao_api_url', ''),
                    'campaignId' => get_option('pressao_campaign_id', ''),
                    'nonce' => wp_create_nonce('pressao_acao_nonce'),
                    'ajaxUrl' => admin_url('admin-ajax.php'),
                    'iconsUrl' => $icons_url,
                    'localStorageKey' => 'pressao_acoes_realizadas',
                    'cookieUserIdKey' => 'pressao_usuario_id',
                    'cookieActionsKey' => 'pressao_acoes_realizadas',
                    'ativistaFormTitle' => get_option('pressao_ativista_form_title', __('Identifique-se', 'pressao-plugin')),
                    'ativistaFormMessage' => get_option('pressao_ativista_form_message', __('Preencha seus dados para continuar:', 'pressao-plugin')),
                    'ativistaNomeLabel' => get_option('pressao_ativista_nome_label', __('Nome', 'pressao-plugin')),
                    'ativistaEmailLabel' => get_option('pressao_ativista_email_label', __('Email', 'pressao-plugin')),
                    'ativistaTelefoneLabel' => get_option('pressao_ativista_telefone_label', __('Telefone', 'pressao-plugin')),
                    'ativistaSaveButton' => get_option('pressao_ativista_save_button', __('Salvar e continuar', 'pressao-plugin')),
                    'confirmInterval' => get_option('pressao_ativista_confirm_interval', 10),
                    'sessionDuration' => get_option('pressao_session_duration', '86400'),
                ]);
            }

            if ($has_fluxo) {
                wp_enqueue_style(
                    'tom-select',
                    PRESSAO_PLUGIN_URL . 'assets/vendor/tom-select/tom-select.default.min.css',
                    [],
                    '2.3.1'
                );
                wp_enqueue_style(
                    'pressao-fluxo',
                    PRESSAO_PLUGIN_URL . 'assets/css/fluxo.css',
                    ['tom-select', 'pressao-plugin'],
                    pressao_plugin_asset_version('assets/css/fluxo.css')
                );
                wp_enqueue_script(
                    'tom-select',
                    PRESSAO_PLUGIN_URL . 'assets/vendor/tom-select/tom-select.complete.min.js',
                    [],
                    '2.3.1',
                    true
                );
                wp_enqueue_script(
                    'pressao-fluxo',
                    PRESSAO_PLUGIN_URL . 'assets/js/fluxo.js',
                    ['tom-select'],
                    pressao_plugin_asset_version('assets/js/fluxo.js'),
                    true
                );
                wp_localize_script('pressao-fluxo', 'pressaoFluxoData', [
                    'ajaxUrl' => admin_url('admin-ajax.php'),
                    'nonce' => wp_create_nonce('pressao_acao_nonce'),
                    'sessionDuration' => get_option('pressao_session_duration', '86400'),
                    'iconsUrl' => $icons_url,
                ]);
            }
        }
    }

    /**
     * Page builders como o WPBakery (js_composer) guardam o conteúdo de
     * elementos "Raw HTML" ([vc_raw_html]) em base64+urlencode no
     * post_content, pra escapar do wpautop. Isso esconde qualquer
     * shortcode nosso colado lá dentro (ex: [pressao_fluxo ...] inserido
     * via elemento Raw HTML) de checagens simples de texto como
     * has_shortcode(), mesmo o WPBakery decodificando e executando esse
     * shortcode normalmente no render — resultado: o widget aparece na
     * página, mas sem o CSS/JS carregado. Decodifica esses blocos aqui só
     * pra fins de detecção (não altera o post_content de verdade).
     *
     * @param string $content
     * @return string
     */
    private function decode_vc_raw_html_shortcodes($content) {
        if (strpos($content, 'vc_raw_html') === false) {
            return '';
        }

        $decoded = '';
        if (preg_match_all('/\[vc_raw_html[^\]]*\](.*?)\[\/vc_raw_html\]/s', $content, $matches)) {
            foreach ($matches[1] as $encoded) {
                $raw = base64_decode($encoded, true);
                if ($raw !== false) {
                    $decoded .= ' ' . urldecode($raw);
                }
            }
        }

        return $decoded;
    }

    public function enqueue_admin_assets($hook) {
        if (strpos($hook, 'pressao-settings') === false) {
            return;
        }

        wp_enqueue_media();

        wp_enqueue_style(
            'tom-select',
            PRESSAO_PLUGIN_URL . 'assets/vendor/tom-select/tom-select.default.min.css',
            [],
            '2.3.1'
        );
        wp_enqueue_script(
            'tom-select',
            PRESSAO_PLUGIN_URL . 'assets/vendor/tom-select/tom-select.complete.min.js',
            [],
            '2.3.1',
            true
        );

        $admin_css = PRESSAO_PLUGIN_DIR . 'assets/css/admin.css';
        if (file_exists($admin_css)) {
            wp_enqueue_style(
                'pressao-admin',
                PRESSAO_PLUGIN_URL . 'assets/css/admin.css',
                ['tom-select'],
                pressao_plugin_asset_version('assets/css/admin.css')
            );
        }

        wp_enqueue_script(
            'pressao-admin',
            PRESSAO_PLUGIN_URL . 'assets/js/admin.js',
            ['jquery', 'tom-select'],
            pressao_plugin_asset_version('assets/js/admin.js'),
            true
        );

        wp_localize_script('pressao-admin', 'pressaoAdminData', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'selectCandidateImage' => __('Selecionar imagem do candidato', 'pressao-plugin'),
            'selectShareImage' => __('Selecionar imagem para postar', 'pressao-plugin'),
            'useThisImage' => __('Usar esta imagem', 'pressao-plugin'),
            'removeConfirm' => __('Remover os candidatos selecionados da base de apoiadores?', 'pressao-plugin'),
            'removeItemConfirm' => __('Remover este candidato da lista?', 'pressao-plugin'),
            'saveError' => __('Não foi possível salvar o candidato.', 'pressao-plugin'),
            'deleteError' => __('Não foi possível remover o candidato.', 'pressao-plugin'),
            'addError' => __('Não foi possível adicionar o candidato.', 'pressao-plugin'),
            'saving' => __('Salvando…', 'pressao-plugin'),
            'saved' => __('Salvo.', 'pressao-plugin'),
        ]);
    }
}

// Inicializa o plugin
PressaoPlugin::get_instance();