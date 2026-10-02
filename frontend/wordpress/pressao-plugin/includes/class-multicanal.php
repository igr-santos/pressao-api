<?php
/**
 * Shortcode [pressao_multicanal]: widget padrão com Instagram, TikTok, e-mail e compartilhamento.
 *
 * @package PressaoPlugin
 */

if (!defined('ABSPATH')) {
    exit;
}

class PressaoPlugin_Multicanal {

    const CANAIS = ['instagram', 'tiktok', 'email'];
    const REDES = ['whatsapp', 'x', 'instagram', 'messenger'];

    /** @var PressaoPlugin_API */
    private $api;

    public function __construct() {
        $this->api = new PressaoPlugin_API();
        add_shortcode('pressao_multicanal', [$this, 'render']);
    }

    /**
     * Rótulos fixos de cada canal (título e descrição do card).
     */
    public static function canal_labels($canal) {
        $labels = [
            'instagram' => [
                'titulo' => __('Instagram', 'pressao-plugin'),
                'descricao' => __('Faça barulho nas redes sociais', 'pressao-plugin'),
            ],
            'tiktok' => [
                'titulo' => __('TikTok', 'pressao-plugin'),
                'descricao' => __('Marque em um vídeo estratégico', 'pressao-plugin'),
            ],
            'email' => [
                'titulo' => __('Email', 'pressao-plugin'),
                'descricao' => __('Envie diretamente para os alvos', 'pressao-plugin'),
            ],
        ];
        return $labels[$canal] ?? ['titulo' => ucfirst($canal), 'descricao' => ''];
    }

    /**
     * Alvo de um canal: o id informado (se for daquele canal) ou o primeiro do canal.
     * No e-mail, sem id informado, prefere o alvo agregado.
     *
     * @param array  $alvos   Lista da API.
     * @param string $canal
     * @param string $alvo_id
     * @return array|null
     */
    public static function encontrar_alvo(array $alvos, $canal, $alvo_id = '') {
        $do_canal = array_values(array_filter($alvos, function ($alvo) use ($canal) {
            return is_array($alvo) && ($alvo['tipo_contato'] ?? '') === $canal && ($alvo['ativo'] ?? true);
        }));

        if ($alvo_id !== '') {
            foreach ($do_canal as $alvo) {
                if ((string) ($alvo['id'] ?? '') === (string) $alvo_id) {
                    return $alvo;
                }
            }
            return null;
        }

        if ($canal === 'email') {
            foreach ($do_canal as $alvo) {
                if (($alvo['modo'] ?? '') === 'agregado') {
                    return $alvo;
                }
            }
        }

        return $do_canal[0] ?? null;
    }

    /**
     * Lista CSV → canais/redes válidos, sem repetição, na ordem informada.
     */
    public static function parse_lista($csv, array $permitidos) {
        $itens = array_map('trim', explode(',', strtolower((string) $csv)));
        return array_values(array_unique(array_filter($itens, function ($item) use ($permitidos) {
            return in_array($item, $permitidos, true);
        })));
    }

    private function parse_atts($atts) {
        $atts = shortcode_atts([
            'campaign' => get_option('pressao_campaign_id', ''),
            'canais' => 'instagram,tiktok,email',
            'alvo_instagram' => '',
            'alvo_tiktok' => '',
            'alvo_email' => '',
            'cache' => 0,
            'alvos' => __('candidatos', 'pressao-plugin'),
            'selo' => __('Faça sua cobrança aos candidatos', 'pressao-plugin'),
            'title' => __('Pressione os {alvos} pela {campanha}', 'pressao-plugin'),
            'subtitle' => __('Marque quem ainda não se comprometeu e ajude a fortalecer o movimento.', 'pressao-plugin'),
            'progresso' => '1',
            'countdown' => 3,
            'tempo_instagram' => '2 min',
            'tempo_tiktok' => '2 min',
            'tempo_email' => '1 min',
            'ajuda_titulo' => '',
            'ajuda' => '',
            'redes' => 'whatsapp,x,instagram',
            'class' => '',
            'id' => 'pressao-mc-' . uniqid(),
        ], $atts, 'pressao_multicanal');

        $progresso = strtolower(trim((string) $atts['progresso']));

        return [
            'campanha_id' => sanitize_text_field($atts['campaign']),
            'canais' => self::parse_lista($atts['canais'], self::CANAIS),
            'alvo_ids' => [
                'instagram' => sanitize_text_field($atts['alvo_instagram']),
                'tiktok' => sanitize_text_field($atts['alvo_tiktok']),
                'email' => sanitize_text_field($atts['alvo_email']),
            ],
            'cache' => intval($atts['cache']),
            'alvos' => sanitize_text_field($atts['alvos']),
            'selo' => sanitize_text_field($atts['selo']),
            'title' => sanitize_text_field($atts['title']),
            'subtitle' => sanitize_textarea_field($atts['subtitle']),
            'progresso' => !in_array($progresso, ['0', 'no', 'nao', 'não', 'false', 'off'], true),
            'countdown' => max(0, intval($atts['countdown'])),
            'tempos' => [
                'instagram' => sanitize_text_field($atts['tempo_instagram']),
                'tiktok' => sanitize_text_field($atts['tempo_tiktok']),
                'email' => sanitize_text_field($atts['tempo_email']),
            ],
            'ajuda_titulo' => sanitize_text_field($atts['ajuda_titulo']),
            'ajuda' => wp_kses_post($atts['ajuda']),
            'redes' => self::parse_lista($atts['redes'], self::REDES),
            'class' => sanitize_text_field($atts['class']),
            'id' => sanitize_html_class($atts['id']),
        ];
    }

    /**
     * Substitui {campanha} e {alvos} em textos configuráveis.
     */
    private function interpolar($texto, $campanha_nome, $alvos) {
        return strtr((string) $texto, [
            '{campanha}' => $campanha_nome !== '' ? $campanha_nome : __('campanha', 'pressao-plugin'),
            '{alvos}' => $alvos,
        ]);
    }

    /**
     * Config de um card a partir do alvo da API.
     */
    private function canal_config($canal, array $alvo, $tempo) {
        $labels = self::canal_labels($canal);
        $template = isset($alvo['template']) && is_array($alvo['template']) ? $alvo['template'] : [];

        $config = [
            'canal' => $canal,
            'titulo' => $labels['titulo'],
            'descricao' => $labels['descricao'],
            'tempo' => $tempo,
            'alvo_id' => (string) ($alvo['id'] ?? ''),
            'template_id' => (string) ($template['id'] ?? ''),
            'mensagem' => isset($template['conteudo']) ? PressaoPlugin_Render_Helpers::texto_de_html($template['conteudo']) : '',
            'contato_url' => (string) ($alvo['contato'] ?? ''),
        ];

        if ($canal === 'email') {
            $config['assunto'] = isset($template['titulo']) ? (string) $template['titulo'] : '';
            $config['membros'] = [];
            if (!empty($alvo['membros']) && is_array($alvo['membros'])) {
                foreach ($alvo['membros'] as $membro) {
                    $nome = is_array($membro) ? trim((string) ($membro['nome'] ?? '')) : '';
                    if ($nome !== '') {
                        $config['membros'][] = $nome;
                    }
                }
            }
            $total = isset($alvo['total_membros']) ? (int) $alvo['total_membros'] : 0;
            $config['total_membros'] = max($total, count($config['membros']), 1);
        }

        return $config;
    }

    public function render($atts) {
        $o = $this->parse_atts($atts);

        if ($o['campanha_id'] === '') {
            return '<p class="pressao-error">' . esc_html__('ID da campanha não informado', 'pressao-plugin') . '</p>';
        }

        $result = $this->api->get_alvos_cached($o['campanha_id'], [], $o['cache']);
        if (is_wp_error($result)) {
            return '<p class="pressao-error">' . esc_html($result->get_error_message()) . '</p>';
        }
        $alvos = (!empty($result['success']) && is_array($result['data'])) ? $result['data'] : [];

        $canais = [];
        foreach ($o['canais'] as $canal) {
            $alvo = self::encontrar_alvo($alvos, $canal, $o['alvo_ids'][$canal]);
            if ($alvo) {
                $canais[] = $this->canal_config($canal, $alvo, $o['tempos'][$canal]);
            }
        }

        if (!$canais) {
            return '<p class="pressao-empty">' . esc_html__('Nenhum canal disponível para esta campanha.', 'pressao-plugin') . '</p>';
        }

        $campanha = $this->api->get_campanha_cached($o['campanha_id']);
        $campanha_nome = (!is_wp_error($campanha) && !empty($campanha['data']['nome']))
            ? (string) $campanha['data']['nome']
            : '';

        $count_result = $this->api->get_acoes_confirmadas_count($o['campanha_id'], 60);
        $acoes_count = is_wp_error($count_result) ? 0 : (int) $count_result['count'];

        $candidatos = PressaoPlugin_Render_Helpers::normalize_candidatos(get_option('pressao_candidatos', []), 'c');
        $avatares = PressaoPlugin_Render_Helpers::avatares_destaque($candidatos, 5);

        $share = PressaoPlugin_Render_Helpers::compartilhamento_config(false);
        if (!$share) {
            $share = PressaoPlugin_Render_Helpers::compartilhamento_padrao();
        }

        $ajuda_option = PressaoPlugin_Render_Helpers::ajuda_config();
        $ajuda_titulo = $o['ajuda_titulo'] !== '' ? $o['ajuda_titulo'] : __('Como funciona?', 'pressao-plugin');
        $ajuda_conteudo = $o['ajuda'];
        if ($ajuda_conteudo === '') {
            $ajuda_conteudo = $ajuda_option['conteudo'] !== ''
                ? $ajuda_option['conteudo']
                : $this->interpolar(
                    __('Pressionar é fazer sua voz chegar a quem pode tomar decisões. Nesta campanha, significa marcar e cobrar candidatos a deputado federal e senador para que assumam publicamente o compromisso com a {campanha}. Quanto mais gente cobrar, mais força tem o pedido para colocar o transporte gratuito e de qualidade na agenda política.', 'pressao-plugin'),
                    $campanha_nome,
                    $o['alvos']
                );
        }

        $nonce = wp_create_nonce('pressao_acao_nonce');
        $config = [
            'campanha_id' => $o['campanha_id'],
            'campanha_nome' => $campanha_nome,
            'alvos_label' => $o['alvos'],
            'canais' => $canais,
            'candidatos' => $candidatos,
            'acoes_confirmadas' => $acoes_count,
            'progresso' => $o['progresso'],
            'countdown' => $o['countdown'],
            'redes' => $o['redes'],
            'share' => $share,
        ];

        $estados = [];
        foreach ($canais as $canal) {
            $estados[$canal['canal']] = $this->estado_canal($canal);
        }

        return $this->render_html($o, $config, $estados, [
            'nonce' => $nonce,
            'campanha_nome' => $campanha_nome,
            'avatares' => $avatares,
            'total_candidatos' => count($candidatos),
            'acoes_count' => $acoes_count,
            'ajuda_titulo' => $ajuda_titulo,
            'ajuda_conteudo' => $ajuda_conteudo,
        ]);
    }

    /**
     * Estado SSR do card: 'done' (ação realizada), 'skipped' ("Não uso") ou ''.
     */
    private function estado_canal(array $canal) {
        $acao = PressaoPlugin_Render_Helpers::acao_state($canal['alvo_id']);
        if (PressaoPlugin_Render_Helpers::is_acao_realizada($acao)) {
            return 'done';
        }
        if (PressaoPlugin_Render_Helpers::acao_state('__naouso_' . $canal['canal'])) {
            return 'skipped';
        }
        return '';
    }

    private function render_html(array $o, array $config, array $estados, array $view) {
        $total_canais = 0;
        $feitos = 0;
        foreach ($estados as $estado) {
            if ($estado !== 'skipped') {
                $total_canais++;
            }
            if ($estado === 'done') {
                $feitos++;
            }
        }
        $algum_feito = $feitos > 0;
        $title = $this->interpolar($o['title'], $view['campanha_nome'], $o['alvos']);
        $campaign_attr = esc_attr($o['campanha_id']);
        $count_html = '<span class="pressao-acoes-counter" data-campaign="' . $campaign_attr . '">'
            . '<span class="pressao-acoes-count" data-count="' . esc_attr($view['acoes_count']) . '">'
            . esc_html(number_format_i18n($view['acoes_count']))
            . '</span></span>';
        $alvos_texto = sprintf(
            /* translators: 1: total, 2: palavra para os alvos (ex.: candidatos) */
            __('+%1$d %2$s', 'pressao-plugin'),
            $view['total_candidatos'],
            $o['alvos']
        );

        ob_start();
        ?>
        <div id="<?php echo esc_attr($o['id']); ?>"
             class="pressao-mc <?php echo esc_attr($o['class']); ?>"
             data-campaign="<?php echo $campaign_attr; ?>"
             data-nonce="<?php echo esc_attr($view['nonce']); ?>"
             data-pressao-mc="<?php echo esc_attr(wp_json_encode($config)); ?>">
            <div class="pressao-mc-card">
                <section class="pressao-mc-hero">
                    <div class="pressao-mc-hero-top">
                        <?php if ($o['selo'] !== '') : ?>
                            <span class="pressao-mc-selo">
                                <span class="pressao-mc-ico pressao-mc-ico--localizacao" aria-hidden="true"></span>
                                <?php echo esc_html($o['selo']); ?>
                            </span>
                        <?php endif; ?>
                        <button type="button" class="pressao-mc-help-btn" data-mc-open-modal="ajuda" aria-label="<?php echo esc_attr($view['ajuda_titulo']); ?>">
                            <span class="pressao-mc-ico pressao-mc-ico--interrogacao" aria-hidden="true"></span>
                        </button>
                    </div>
                    <h2 class="pressao-mc-hero-title"><?php echo esc_html($title); ?></h2>
                    <?php if ($o['subtitle'] !== '') : ?>
                        <p class="pressao-mc-hero-subtitle"><?php echo esc_html($o['subtitle']); ?></p>
                    <?php endif; ?>
                    <div class="pressao-mc-stats">
                        <?php if ($view['total_candidatos'] > 0) : ?>
                            <button type="button" class="pressao-mc-alvos" data-mc-open-modal="lista">
                                <span class="pressao-mc-avatars" aria-hidden="true">
                                    <?php echo PressaoPlugin_Render_Helpers::render_avatares($view['avatares'], 'pressao-mc-avatar'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                                </span>
                                <span class="pressao-mc-alvos-text">
                                    <strong>
                                        <span class="pressao-mc-mobile-only"><?php esc_html_e('Ver', 'pressao-plugin'); ?> </span><?php echo esc_html($alvos_texto); ?>
                                        <span class="pressao-mc-ico pressao-mc-ico--seta-diagonal" aria-hidden="true"></span>
                                    </strong>
                                    <span class="pressao-mc-mobile-only"><?php esc_html_e('que serão pressionados', 'pressao-plugin'); ?></span>
                                    <span class="pressao-mc-desktop-only"><?php esc_html_e('serão pressionados', 'pressao-plugin'); ?></span>
                                </span>
                            </button>
                        <?php endif; ?>
                        <div class="pressao-mc-stat-count pressao-mc-desktop-only">
                            <span class="pressao-mc-raio pressao-mc-raio--lg" aria-hidden="true"></span>
                            <strong><?php echo $count_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?> <?php esc_html_e('pressões', 'pressao-plugin'); ?></strong>
                            <span><?php esc_html_e('até o momento', 'pressao-plugin'); ?></span>
                        </div>
                    </div>
                </section>

                <section class="pressao-mc-panel" data-mc-panel>
                    <div class="pressao-mc-home" data-mc-home>
                        <p class="pressao-mc-panel-title pressao-mc-desktop-only" data-mc-home-title
                           data-titulo-inicio="<?php esc_attr_e('Escolha por onde começar:', 'pressao-plugin'); ?>"
                           data-titulo-continuar="<?php esc_attr_e('Escolha como agir:', 'pressao-plugin'); ?>">
                            <?php echo $algum_feito ? esc_html__('Escolha como agir:', 'pressao-plugin') : esc_html__('Escolha por onde começar:', 'pressao-plugin'); ?>
                        </p>
                        <ul class="pressao-mc-cards" data-mc-cards>
                            <?php foreach ($config['canais'] as $canal) : ?>
                                <li><?php echo $this->render_card($canal, $estados[$canal['canal']]); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></li>
                            <?php endforeach; ?>
                        </ul>
                        <p class="pressao-mc-count pressao-mc-mobile-only">
                            <span class="pressao-mc-raio" aria-hidden="true"></span>
                            <strong><?php echo $count_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?> <?php esc_html_e('pressões até o momento', 'pressao-plugin'); ?></strong>
                        </p>
                        <button type="button" class="pressao-mc-share-btn" data-mc-open-share>
                            <?php esc_html_e('Compartilhar campanha', 'pressao-plugin'); ?>
                            <span class="pressao-mc-ico pressao-mc-ico--enviar" aria-hidden="true"></span>
                        </button>
                    </div>

                    <div class="pressao-mc-screen" data-mc-screen hidden aria-live="polite"></div>

                    <?php if ($o['progresso']) : ?>
                        <?php echo $this->render_progresso($feitos, $total_canais); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                    <?php endif; ?>
                </section>
            </div>

            <div class="pressao-mc-modal" data-mc-modal hidden>
                <div class="pressao-mc-modal-backdrop" data-mc-close-modal></div>
                <div class="pressao-mc-modal-dialog" role="dialog" aria-modal="true" tabindex="-1" data-mc-modal-dialog></div>
            </div>

            <template data-mc-template="lista">
                <?php echo $this->render_lista($config['candidatos'], $view['campanha_nome'], $o['alvos']); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
            </template>
            <template data-mc-template="ajuda">
                <?php echo $this->render_modal_header($view['ajuda_titulo']); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                <div class="pressao-mc-modal-body pressao-mc-ajuda"><?php echo wp_kses_post(wpautop($view['ajuda_conteudo'])); ?></div>
            </template>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Card de canal da home. $estado: 'done' | 'skipped' | ''.
     */
    public function render_card(array $canal, $estado = '') {
        $classes = 'pressao-mc-canal';
        if ($estado === 'done') {
            $classes .= ' is-done';
        } elseif ($estado === 'skipped') {
            $classes .= ' is-skipped';
        }

        ob_start();
        ?>
        <button type="button" class="<?php echo esc_attr($classes); ?>" data-mc-canal="<?php echo esc_attr($canal['canal']); ?>">
            <span class="pressao-mc-canal-icon" aria-hidden="true">
                <span class="pressao-mc-ico pressao-mc-ico--<?php echo esc_attr($canal['canal']); ?>"></span>
            </span>
            <span class="pressao-mc-canal-copy">
                <span class="pressao-mc-canal-head">
                    <strong class="pressao-mc-canal-title"><?php echo esc_html($canal['titulo']); ?></strong>
                    <?php if ($canal['tempo'] !== '') : ?>
                        <span class="pressao-mc-canal-tempo"><?php echo esc_html($canal['tempo']); ?></span>
                    <?php endif; ?>
                </span>
                <span class="pressao-mc-canal-desc"><?php echo esc_html($canal['descricao']); ?></span>
            </span>
            <span class="pressao-mc-canal-status pressao-ui-sr-only" data-mc-canal-status>
                <?php
                if ($estado === 'done') {
                    esc_html_e('Feito', 'pressao-plugin');
                } elseif ($estado === 'skipped') {
                    esc_html_e('Pulado', 'pressao-plugin');
                }
                ?>
            </span>
            <span class="pressao-mc-canal-arrow" aria-hidden="true"></span>
        </button>
        <?php
        return ob_get_clean();
    }

    public function render_progresso($feitos, $total) {
        $pct = $total > 0 ? min(100, round(($feitos / $total) * 100)) : 0;
        ob_start();
        ?>
        <div class="pressao-mc-progress" data-mc-progress>
            <div class="pressao-mc-progress-head">
                <span class="pressao-mc-progress-label"><?php esc_html_e('Etapas que você já fez', 'pressao-plugin'); ?></span>
                <span class="pressao-mc-progress-value">
                    <span class="pressao-mc-raio" aria-hidden="true"></span>
                    <span data-mc-progress-text><?php echo esc_html(sprintf(__('%1$d de %2$d', 'pressao-plugin'), $feitos, $total)); ?></span>
                </span>
            </div>
            <div class="pressao-mc-progress-track" role="progressbar" aria-valuemin="0" aria-valuemax="<?php echo esc_attr($total); ?>" aria-valuenow="<?php echo esc_attr($feitos); ?>" data-mc-progress-bar>
                <span class="pressao-mc-progress-fill" style="width: <?php echo esc_attr($pct); ?>%" data-mc-progress-fill></span>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    public function render_modal_header($titulo) {
        return '<header class="pressao-mc-modal-header">'
            . '<h3 class="pressao-mc-modal-title">' . esc_html($titulo) . '</h3>'
            . '<button type="button" class="pressao-mc-icon-btn" data-mc-close-modal aria-label="' . esc_attr__('Fechar', 'pressao-plugin') . '">'
            . '<span class="pressao-mc-ico pressao-mc-ico--fechar" aria-hidden="true"></span></button>'
            . '</header>';
    }

    private function render_lista(array $candidatos, $campanha_nome, $alvos) {
        $titulo = sprintf(
            /* translators: %s: palavra para os alvos (ex.: candidatos) */
            __('%s que serão pressionados', 'pressao-plugin'),
            ucfirst($alvos)
        );
        $texto = sprintf(
            /* translators: 1: total, 2: palavra para os alvos, 3: nome da campanha */
            __('%1$d %2$s serão pressionados pela %3$s em cada rede social', 'pressao-plugin'),
            count($candidatos),
            $alvos,
            $campanha_nome !== '' ? $campanha_nome : __('campanha', 'pressao-plugin')
        );

        ob_start();
        echo $this->render_modal_header($titulo); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        ?>
        <div class="pressao-mc-modal-body">
            <p class="pressao-mc-modal-text"><?php echo esc_html($texto); ?></p>
            <ul class="pressao-mc-lista">
                <?php foreach ($candidatos as $c) : ?>
                    <?php $meta = array_filter([$c['instagram'], $c['cargo'], $c['partido']]); ?>
                    <li class="pressao-mc-lista-item">
                        <?php echo PressaoPlugin_Render_Helpers::render_avatares([$c], 'pressao-mc-avatar pressao-mc-avatar--lista'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                        <span class="pressao-mc-lista-copy">
                            <strong><?php echo esc_html($c['nome'] !== '' ? $c['nome'] : $c['instagram']); ?></strong>
                            <span><?php echo esc_html(implode(' · ', $meta)); ?></span>
                        </span>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>
        <?php
        return ob_get_clean();
    }
}

new PressaoPlugin_Multicanal();
