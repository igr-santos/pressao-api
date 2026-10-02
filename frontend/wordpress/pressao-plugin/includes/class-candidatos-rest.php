<?php
/**
 * REST API para candidatos apoiadores (upsert por @, imagem opcional).
 *
 * @package PressaoPlugin
 */

if (!defined('ABSPATH')) {
    exit;
}

class PressaoPlugin_Candidatos_Rest {

    const API_NAMESPACE = 'pressao/v1';
    const ROUTE = '/candidatos-apoiadores';

    public function __construct() {
        add_action('rest_api_init', [$this, 'register_routes']);
    }

    public function register_routes() {
        register_rest_route(self::API_NAMESPACE, self::ROUTE, [
            [
                'methods' => WP_REST_Server::READABLE,
                'callback' => [$this, 'list_items'],
                'permission_callback' => [$this, 'can_manage'],
            ],
            [
                'methods' => WP_REST_Server::EDITABLE,
                'callback' => [$this, 'upsert_item'],
                'permission_callback' => [$this, 'can_manage'],
            ],
        ]);

        // Delimitador do WP REST é @ — escapar \@ no padrão. Handle na URL pode vir
        // com ou sem @ (ex.: fulana ou %40fulana → @fulana após decode).
        register_rest_route(self::API_NAMESPACE, self::ROUTE . '/(?P<handle>[A-Za-z0-9._\@\-]+)', [
            [
                'methods' => WP_REST_Server::READABLE,
                'callback' => [$this, 'get_item'],
                'permission_callback' => [$this, 'can_manage'],
            ],
            [
                'methods' => WP_REST_Server::DELETABLE,
                'callback' => [$this, 'delete_item'],
                'permission_callback' => [$this, 'can_manage'],
            ],
        ]);
    }

    /**
     * @param WP_REST_Request $request
     * @return bool
     */
    public function can_manage($request) {
        return current_user_can('manage_options');
    }

    /**
     * GET /candidatos-apoiadores
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response
     */
    public function list_items($request) {
        $list = array_values(PressaoPlugin_Candidatos_Import::index_by_handle(
            PressaoPlugin_Candidatos_Import::get_apoiadores()
        ));

        return new WP_REST_Response([
            'total' => count($list),
            'items' => $list,
        ], 200);
    }

    /**
     * GET /candidatos-apoiadores/{handle}
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response|WP_Error
     */
    public function get_item($request) {
        $handle = $this->handle_from_request($request);
        if ($handle === '') {
            return new WP_Error(
                'pressao_invalid_handle',
                __('Instagram inválido ou ausente.', 'pressao-plugin'),
                ['status' => 400]
            );
        }

        $map = PressaoPlugin_Candidatos_Import::index_by_handle(
            PressaoPlugin_Candidatos_Import::get_apoiadores()
        );

        if (!isset($map[$handle])) {
            return new WP_Error(
                'pressao_not_found',
                __('Candidato não encontrado.', 'pressao-plugin'),
                ['status' => 404]
            );
        }

        return new WP_REST_Response($map[$handle], 200);
    }

    /**
     * PUT/POST /candidatos-apoiadores — upsert de um registro.
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response|WP_Error
     */
    public function upsert_item($request) {
        $params = $request->get_json_params();
        if (!is_array($params)) {
            $params = $request->get_params();
        }
        if (!is_array($params)) {
            $params = [];
        }

        $instagram_raw = '';
        if (isset($params['instagram'])) {
            $instagram_raw = $params['instagram'];
        } elseif (isset($params['link_url'])) {
            $instagram_raw = $params['link_url'];
        }

        $handle = PressaoPlugin_Candidatos_Import::normalize_handle($instagram_raw);
        if ($handle === '') {
            return new WP_Error(
                'pressao_invalid_handle',
                __('Campo instagram (ou link_url) é obrigatório e deve ser um handle válido.', 'pressao-plugin'),
                ['status' => 400]
            );
        }

        $map = PressaoPlugin_Candidatos_Import::index_by_handle(
            PressaoPlugin_Candidatos_Import::get_apoiadores()
        );

        $is_new = !isset($map[$handle]);
        $existing = $is_new ? [] : $map[$handle];

        $nome = array_key_exists('nome', $params)
            ? sanitize_text_field($params['nome'])
            : ($existing['nome'] ?? '');
        $cargo = array_key_exists('cargo', $params)
            ? sanitize_text_field($params['cargo'])
            : ($existing['cargo'] ?? '');
        $partido = array_key_exists('partido', $params)
            ? sanitize_text_field($params['partido'])
            : ($existing['partido'] ?? '');
        $descricao = array_key_exists('descricao', $params)
            ? sanitize_textarea_field($params['descricao'])
            : ($existing['descricao'] ?? '');

        $imagem_id = absint($existing['imagem_id'] ?? 0);
        $warning = null;

        if (array_key_exists('imagem_id', $params) && $params['imagem_id'] !== null && $params['imagem_id'] !== '') {
            $candidate_id = absint($params['imagem_id']);
            if ($candidate_id > 0 && wp_attachment_is_image($candidate_id)) {
                $imagem_id = $candidate_id;
            } elseif ($candidate_id === 0) {
                $imagem_id = 0;
            } else {
                $warning = __('imagem_id inválido ou não é uma imagem; mantida imagem anterior.', 'pressao-plugin');
            }
        } elseif (!empty($params['imagem_url'])) {
            $sideload = PressaoPlugin_Candidatos_Import::sideload_image(
                $params['imagem_url'],
                $nome !== '' ? $nome : $handle
            );
            if (is_wp_error($sideload)) {
                $warning = sprintf(
                    /* translators: %s: error message */
                    __('Falha ao baixar imagem: %s', 'pressao-plugin'),
                    $sideload->get_error_message()
                );
            } else {
                $imagem_id = (int) $sideload;
            }
        }

        $record = [
            'nome' => $nome,
            'cargo' => $cargo,
            'partido' => $partido,
            'descricao' => $descricao,
            'link_url' => $handle,
            'imagem_id' => $imagem_id,
        ];

        $map[$handle] = $record;
        update_option(PressaoPlugin_Candidatos_Import::OPTION, array_values($map), false);
        if ($imagem_id !== absint($existing['imagem_id'] ?? 0)) {
            PressaoPlugin_Apoiadores_Imagens_Fila::descartar($handle);
        }

        $response = [
            'action' => $is_new ? 'created' : 'updated',
            'item' => $record,
        ];
        if ($warning !== null) {
            $response['warning'] = $warning;
        }

        return new WP_REST_Response($response, $is_new ? 201 : 200);
    }

    /**
     * DELETE /candidatos-apoiadores/{handle}
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response|WP_Error
     */
    public function delete_item($request) {
        $handle = $this->handle_from_request($request);
        if ($handle === '') {
            return new WP_Error(
                'pressao_invalid_handle',
                __('Instagram inválido ou ausente.', 'pressao-plugin'),
                ['status' => 400]
            );
        }

        $map = PressaoPlugin_Candidatos_Import::index_by_handle(
            PressaoPlugin_Candidatos_Import::get_apoiadores()
        );

        if (!isset($map[$handle])) {
            return new WP_Error(
                'pressao_not_found',
                __('Candidato não encontrado.', 'pressao-plugin'),
                ['status' => 404]
            );
        }

        $removed = $map[$handle];
        unset($map[$handle]);
        update_option(PressaoPlugin_Candidatos_Import::OPTION, array_values($map), false);

        return new WP_REST_Response([
            'action' => 'deleted',
            'item' => $removed,
        ], 200);
    }

    /**
     * @param WP_REST_Request $request
     * @return string handle normalizado ou ''
     */
    private function handle_from_request($request) {
        $raw = $request->get_param('handle');
        if ($raw === null || $raw === '') {
            return '';
        }
        $raw = rawurldecode((string) $raw);
        return PressaoPlugin_Candidatos_Import::normalize_handle($raw);
    }
}

new PressaoPlugin_Candidatos_Rest();
