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
            'pressao_candidatos' => []
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
        
        $has_shortcode = has_shortcode($post->post_content, 'pressao_widget') ||
                         has_shortcode($post->post_content, 'pressao_form') ||
                         has_shortcode($post->post_content, 'pressao_list') ||
                         has_shortcode($post->post_content, 'pressao_alvos') ||
                         has_shortcode($post->post_content, 'pressao_contador') ||
                         has_shortcode($post->post_content, 'pressao_progresso') ||
                         has_shortcode($post->post_content, 'pressao_candidatos');
        
        if ($has_shortcode) {
            wp_enqueue_style(
                'pressao-plugin',
                PRESSAO_PLUGIN_URL . 'assets/css/style.css',
                [],
                PRESSAO_PLUGIN_VERSION
            );
            
            wp_enqueue_script(
                'pressao-plugin',
                PRESSAO_PLUGIN_URL . 'assets/js/widget.js',
                [],
                PRESSAO_PLUGIN_VERSION,
                true
            );
            
            wp_localize_script('pressao-plugin', 'pressaoData', [
                'apiUrl' => get_option('pressao_api_url', ''),
                'campaignId' => get_option('pressao_campaign_id', ''),
                'nonce' => wp_create_nonce('pressao_acao_nonce'),
                'ajaxUrl' => admin_url('admin-ajax.php'),
                'localStorageKey' => 'pressao_acoes_realizadas',
                'cookieUserIdKey' => 'pressao_usuario_id',
                'cookieActionsKey' => 'pressao_acoes_realizadas',
                // Ativista Config
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
    }
    
    public function enqueue_admin_assets($hook) {
        if (strpos($hook, 'pressao-settings') === false) {
            return;
        }

        wp_enqueue_media();

        $admin_css = PRESSAO_PLUGIN_DIR . 'assets/css/admin.css';
        if (file_exists($admin_css)) {
            wp_enqueue_style(
                'pressao-admin',
                PRESSAO_PLUGIN_URL . 'assets/css/admin.css',
                [],
                PRESSAO_PLUGIN_VERSION
            );
        }

        wp_enqueue_script(
            'pressao-admin',
            PRESSAO_PLUGIN_URL . 'assets/js/admin.js',
            ['jquery'],
            PRESSAO_PLUGIN_VERSION,
            true
        );

        wp_localize_script('pressao-admin', 'pressaoAdminData', [
            'selectCandidateImage' => __('Selecionar imagem do candidato', 'pressao-plugin'),
            'useThisImage' => __('Usar esta imagem', 'pressao-plugin'),
        ]);
    }
}

// Inicializa o plugin
PressaoPlugin::get_instance();