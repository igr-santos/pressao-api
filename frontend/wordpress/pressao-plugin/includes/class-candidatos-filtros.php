<?php
/**
 * Índice de filtros (estado → cargos) da base de candidatos a pressionar.
 *
 * @package PressaoPlugin
 */

if (!defined('ABSPATH')) {
    exit;
}

class PressaoPlugin_Candidatos_Filtros {

    const OPTION_CANDIDATOS = 'pressao_candidatos';
    const OPTION_INDICE = 'pressao_candidatos_filtros';
    const VERSAO = 1;
    const ACTION_REGENERAR = 'pressao_regenerar_filtros';
    const NOTICE_TRANSIENT = 'pressao_filtros_notice';

    const UFS = [
        'AC' => 'Acre',
        'AL' => 'Alagoas',
        'AP' => 'Amapá',
        'AM' => 'Amazonas',
        'BA' => 'Bahia',
        'CE' => 'Ceará',
        'DF' => 'Distrito Federal',
        'ES' => 'Espírito Santo',
        'GO' => 'Goiás',
        'MA' => 'Maranhão',
        'MT' => 'Mato Grosso',
        'MS' => 'Mato Grosso do Sul',
        'MG' => 'Minas Gerais',
        'PA' => 'Pará',
        'PB' => 'Paraíba',
        'PR' => 'Paraná',
        'PE' => 'Pernambuco',
        'PI' => 'Piauí',
        'RJ' => 'Rio de Janeiro',
        'RN' => 'Rio Grande do Norte',
        'RS' => 'Rio Grande do Sul',
        'RO' => 'Rondônia',
        'RR' => 'Roraima',
        'SC' => 'Santa Catarina',
        'SP' => 'São Paulo',
        'SE' => 'Sergipe',
        'TO' => 'Tocantins',
    ];

    public function __construct() {
        add_action('add_option_' . self::OPTION_CANDIDATOS, [__CLASS__, 'regenerate'], 10, 0);
        add_action('update_option_' . self::OPTION_CANDIDATOS, [__CLASS__, 'regenerate'], 10, 0);
        add_action('admin_post_' . self::ACTION_REGENERAR, [$this, 'handle_regenerar']);
        add_action('admin_notices', [$this, 'render_admin_notices']);
    }

    /**
     * @param mixed $value
     * @return string Sigla UF válida ou ''.
     */
    public static function sanitize_uf($value) {
        $uf = strtoupper(trim((string) $value));
        return isset(self::UFS[$uf]) ? $uf : '';
    }

    /**
     * Chave do distinct de cargos: sem acentos, minúsculo, espaços colapsados.
     *
     * @param mixed $cargo
     * @return string
     */
    public static function normalize_cargo($cargo) {
        $cargo = remove_accents((string) $cargo);
        $cargo = function_exists('mb_strtolower') ? mb_strtolower($cargo, 'UTF-8') : strtolower($cargo);
        $cargo = preg_replace('/\s+/u', ' ', $cargo);
        return trim((string) $cargo);
    }

    /**
     * @param array<int, mixed> $items
     * @return array{versao: int, gerado_em: int, total_sem_estado: int, estados: array<int, array>}
     */
    public static function build_index(array $items) {
        $estados = [];
        $sem_estado = 0;

        foreach ($items as $item) {
            if (!is_array($item) || trim((string) ($item['link_url'] ?? '')) === '') {
                continue;
            }
            $uf = self::sanitize_uf($item['estado'] ?? '');
            if ($uf === '') {
                $sem_estado++;
                continue;
            }
            if (!isset($estados[$uf])) {
                $estados[$uf] = [
                    'uf' => $uf,
                    'nome' => self::UFS[$uf],
                    'total' => 0,
                    'cargos' => [],
                ];
            }
            $estados[$uf]['total']++;

            $label = trim(preg_replace('/\s+/u', ' ', (string) ($item['cargo'] ?? '')));
            $chave = self::normalize_cargo($label);
            if ($chave === '') {
                continue;
            }
            if (!isset($estados[$uf]['cargos'][$chave])) {
                $estados[$uf]['cargos'][$chave] = [
                    'chave' => $chave,
                    'label' => $label,
                    'total' => 0,
                ];
            }
            $estados[$uf]['cargos'][$chave]['total']++;
        }

        uasort($estados, function ($a, $b) {
            return strcmp(remove_accents($a['nome']), remove_accents($b['nome']));
        });

        foreach ($estados as &$estado) {
            uasort($estado['cargos'], function ($a, $b) {
                return strcmp($a['chave'], $b['chave']);
            });
            $estado['cargos'] = array_values($estado['cargos']);
        }
        unset($estado);

        return [
            'versao' => self::VERSAO,
            'gerado_em' => time(),
            'total_sem_estado' => $sem_estado,
            'estados' => array_values($estados),
        ];
    }

    /**
     * @return array{versao: int, gerado_em: int, total_sem_estado: int, estados: array<int, array>}
     */
    public static function regenerate() {
        $items = get_option(self::OPTION_CANDIDATOS, []);
        $index = self::build_index(is_array($items) ? $items : []);
        update_option(self::OPTION_INDICE, $index, true);
        return $index;
    }

    /**
     * @return array{versao: int, gerado_em: int, total_sem_estado: int, estados: array<int, array>}
     */
    public static function get_index() {
        $index = get_option(self::OPTION_INDICE, []);
        if (!is_array($index) || (int) ($index['versao'] ?? 0) !== self::VERSAO || !isset($index['estados'])) {
            return self::regenerate();
        }
        return $index;
    }

    /**
     * @param array $index
     * @return string
     */
    public static function summary(array $index) {
        $estados = isset($index['estados']) && is_array($index['estados']) ? $index['estados'] : [];
        $cargos = 0;
        foreach ($estados as $estado) {
            $cargos += count($estado['cargos'] ?? []);
        }
        return sprintf(
            /* translators: 1: states, 2: state/office pairs, 3: candidates without state */
            __('Filtros: %1$d estado(s), %2$d cargo(s) por estado, %3$d candidato(s) sem estado.', 'pressao-plugin'),
            count($estados),
            $cargos,
            (int) ($index['total_sem_estado'] ?? 0)
        );
    }

    public function handle_regenerar() {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Sem permissão.', 'pressao-plugin'));
        }
        check_admin_referer(self::ACTION_REGENERAR);

        $index = self::regenerate();
        set_transient(self::NOTICE_TRANSIENT, self::summary($index), 60);

        wp_safe_redirect(admin_url('options-general.php?page=pressao-settings&tab=candidatos'));
        exit;
    }

    public function render_admin_notices() {
        if (!isset($_GET['page']) || $_GET['page'] !== 'pressao-settings' || !current_user_can('manage_options')) {
            return;
        }
        $message = get_transient(self::NOTICE_TRANSIENT);
        if (!is_string($message) || $message === '') {
            return;
        }
        delete_transient(self::NOTICE_TRANSIENT);
        printf('<div class="notice notice-success is-dismissible"><p>%s</p></div>', esc_html($message));
    }

    /**
     * Bloco do admin com resumo do índice e botão de regenerar.
     */
    public static function render_admin_tools() {
        $index = self::get_index();
        ?>
        <div class="pressao-admin-filtros">
            <h3><?php esc_html_e('Filtros por estado', 'pressao-plugin'); ?></h3>
            <p class="description">
                <?php esc_html_e('Estados e cargos do “Filtre por estado” no [pressao_fluxo] são pré-calculados a partir desta lista e atualizados automaticamente ao salvar um candidato.', 'pressao-plugin'); ?>
                <br>
                <?php echo esc_html(self::summary($index)); ?>
            </p>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="<?php echo esc_attr(self::ACTION_REGENERAR); ?>" />
                <?php wp_nonce_field(self::ACTION_REGENERAR); ?>
                <?php submit_button(__('Regenerar filtros', 'pressao-plugin'), 'secondary', 'submit', false); ?>
            </form>
        </div>
        <?php
    }
}

new PressaoPlugin_Candidatos_Filtros();
