<?php
/**
 * Helpers de render compartilhados entre [pressao_fluxo] e [pressao_multicanal].
 *
 * @package PressaoPlugin
 */

if (!defined('ABSPATH')) {
    exit;
}

class PressaoPlugin_Render_Helpers {

    const COOKIE_ACOES = 'pressao_acoes_realizadas';

    /**
     * Normaliza uma option de candidatos para o config JS (só itens com @).
     *
     * @param mixed  $raw
     * @param string $id_prefix
     * @return array<int, array<string, string>>
     */
    public static function normalize_candidatos($raw, $id_prefix = 'c') {
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

    /**
     * Primeiros $max candidatos, com foto antes dos sem foto.
     *
     * @param array<int, array<string, string>> $candidatos Saída de normalize_candidatos().
     * @param int $max
     * @return array<int, array<string, string>>
     */
    public static function avatares_destaque(array $candidatos, $max = 5) {
        $com_imagem = [];
        $sem_imagem = [];
        foreach ($candidatos as $candidato) {
            if (!empty($candidato['imagem'])) {
                $com_imagem[] = $candidato;
            } else {
                $sem_imagem[] = $candidato;
            }
        }
        return array_slice(array_merge($com_imagem, $sem_imagem), 0, $max);
    }

    /**
     * Spans de avatar (foto como background-image).
     *
     * @param array<int, array<string, string>> $avatares
     * @param string $class Classe de cada avatar.
     */
    public static function render_avatares(array $avatares, $class) {
        $html = '';
        foreach ($avatares as $avatar) {
            $style = !empty($avatar['imagem'])
                ? ' style="background-image:url(\'' . esc_url($avatar['imagem']) . '\')"'
                : '';
            $html .= '<span class="' . esc_attr($class) . '"' . $style . '></span>';
        }
        return $html;
    }

    /**
     * Config pública de compartilhamento para SSR/JS, ou null se inativo/incompleto.
     *
     * @param bool $require_ativo
     * @return array|null
     */
    public static function compartilhamento_config($require_ativo = true) {
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
            'x_url' => self::x_intent_url($mensagem, $link),
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
     * Fallback quando não há option de compartilhamento.
     */
    public static function compartilhamento_padrao() {
        return [
            'overlay_titulo' => __('Convide mais pessoas', 'pressao-plugin'),
            'link' => '',
            'mensagem' => '',
            'whatsapp_url' => '',
            'instagram_url' => '',
            'messenger_url' => '',
            'x_url' => '',
            'imagens_titulo' => __('Imagens para postar', 'pressao-plugin'),
            'imagens_subtitulo' => __('baixe imagens prontas para postar nas redes', 'pressao-plugin'),
            'imagens_instrucao' => '',
            'imagens' => [],
        ];
    }

    /**
     * Intent de post no X com a mensagem (e o link, se ela ainda não o contiver).
     */
    private static function x_intent_url($mensagem, $link) {
        $texto = $mensagem;
        if ($link !== '' && strpos($texto, $link) === false) {
            $texto = trim($texto . ' ' . $link);
        }
        if ($texto === '') {
            return '';
        }
        return 'https://twitter.com/intent/tweet?text=' . rawurlencode($texto);
    }

    /**
     * Título e conteúdo da ajuda (option pressao_fluxo_ajuda).
     *
     * @return array{titulo: string, conteudo: string}
     */
    public static function ajuda_config() {
        $ajuda = get_option('pressao_fluxo_ajuda', []);
        if (!is_array($ajuda)) {
            $ajuda = [];
        }
        return [
            'titulo' => !empty($ajuda['titulo']) ? (string) $ajuda['titulo'] : __('Ajuda', 'pressao-plugin'),
            'conteudo' => isset($ajuda['conteudo']) ? (string) $ajuda['conteudo'] : '',
        ];
    }

    /**
     * Texto puro de um conteúdo HTML de template, mantendo parágrafos e quebras de linha.
     */
    public static function texto_de_html($html) {
        $texto = preg_replace('#<br\s*/?>#i', "\n", (string) $html);
        $texto = preg_replace('#</(p|div|li|h[1-6])>#i', "\n\n", $texto);
        $texto = html_entity_decode(wp_strip_all_tags($texto), ENT_QUOTES, 'UTF-8');
        $texto = preg_replace("/[ \t]+\n/", "\n", $texto);
        $texto = preg_replace("/\n{3,}/", "\n\n", $texto);
        return trim($texto);
    }

    /**
     * Mapa do cookie pressao_acoes_realizadas.
     */
    public static function acoes_cookie() {
        if (!isset($_COOKIE[self::COOKIE_ACOES])) {
            return [];
        }
        $actions = json_decode(stripslashes($_COOKIE[self::COOKIE_ACOES]), true);
        return is_array($actions) ? $actions : [];
    }

    /**
     * Entrada do cookie para uma chave (alvo_id ou chave sintética), ou false.
     *
     * @param string $key
     * @return array|false
     */
    public static function acao_state($key) {
        $actions = self::acoes_cookie();
        return isset($actions[$key]) ? $actions[$key] : false;
    }

    /**
     * Ação realizada: automática já conta; canal manual só após confirmação.
     * Pendentes (AGUARDANDO_ACAO_HUMANA) ainda não entram no progresso.
     */
    public static function is_acao_realizada($action_state) {
        if (!is_array($action_state)) {
            return false;
        }
        $status = $action_state['status'] ?? 'CONCLUIDA';
        return $status !== 'AGUARDANDO_ACAO_HUMANA';
    }

    /**
     * Campos nome / e-mail / WhatsApp de um formulário de ativista.
     *
     * @param array $args {
     *     @type string $prefix              Prefixo de classe (ex.: 'pressao-fluxo').
     *     @type string $email_placeholder
     *     @type string $telefone_placeholder
     *     @type string $telefone_attr       Atributo extra no input de telefone (ex.: 'data-fluxo-whatsapp').
     *     @type array  $valores             Valores iniciais (nome, email, telefone).
     * }
     */
    public static function render_campos_ativista(array $args) {
        $p = $args['prefix'] ?? 'pressao-fluxo';
        $valores = isset($args['valores']) && is_array($args['valores']) ? $args['valores'] : [];
        $telefone_attr = !empty($args['telefone_attr']) ? ' ' . esc_attr($args['telefone_attr']) : '';
        $value = function ($campo) use ($valores) {
            return isset($valores[$campo]) && $valores[$campo] !== ''
                ? ' value="' . esc_attr($valores[$campo]) . '"'
                : '';
        };

        ob_start();
        ?>
        <label class="<?php echo esc_attr($p); ?>-field-label">
            <?php esc_html_e('Nome', 'pressao-plugin'); ?> <span class="<?php echo esc_attr($p); ?>-required">*</span>
            <input type="text" name="nome" required placeholder="<?php esc_attr_e('Seu nome', 'pressao-plugin'); ?>"<?php echo $value('nome'); ?> />
        </label>
        <label class="<?php echo esc_attr($p); ?>-field-label">
            <?php esc_html_e('Email', 'pressao-plugin'); ?> <span class="<?php echo esc_attr($p); ?>-required">*</span>
            <input type="email" name="email" required placeholder="<?php echo esc_attr($args['email_placeholder'] ?? __('seu@email.com', 'pressao-plugin')); ?>"<?php echo $value('email'); ?> />
        </label>
        <label class="<?php echo esc_attr($p); ?>-field-label">
            <?php esc_html_e('Whatsapp (opcional)', 'pressao-plugin'); ?>
            <input type="tel" name="telefone" placeholder="<?php echo esc_attr($args['telefone_placeholder'] ?? '(00) 00000-0000'); ?>" inputmode="numeric" autocomplete="tel"<?php echo $telefone_attr; ?><?php echo $value('telefone'); ?> />
        </label>
        <?php
        return ob_get_clean();
    }
}
