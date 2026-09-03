<?php
/**
 * Classe de integração com API e Keycloak
 * 
 * @package PressaoPlugin
 */

if (!defined('ABSPATH')) {
    exit;
}

class PressaoPlugin_API {
    
    private $keycloak_url;
    private $realm;
    private $client_id;
    private $client_secret;
    private $api_url;
    private $token_cache_key = 'pressao_keycloak_token';
    
    public function __construct() {
        $this->keycloak_url = get_option('pressao_keycloak_url', '');
        $this->realm = get_option('pressao_realm', 'master');
        $this->client_id = get_option('pressao_client_id', '');
        $this->client_secret = get_option('pressao_client_secret', '');
        $this->api_url = get_option('pressao_api_url', '');
    }
    
    /**
     * Obtém token do Keycloak com cache
     */
    public function get_token() {
        $cached_token = get_transient($this->token_cache_key);
        if ($cached_token) {
            return $cached_token;
        }
        
        $token_data = $this->fetch_new_token();

        if ($token_data && isset($token_data['access_token'])) {
            $expires_in = isset($token_data['expires_in']) ? intval($token_data['expires_in']) : 300;
            $expires_in = $expires_in - 30; // Margem de segurança
            
            set_transient($this->token_cache_key, $token_data['access_token'], $expires_in);
            return $token_data['access_token'];
        }
        
        return false;
    }
    
    /**
     * Busca novo token no Keycloak
     */
    private function fetch_new_token() {
        if (empty($this->keycloak_url) || empty($this->client_id) || empty($this->client_secret)) {
            error_log('Pressão Plugin: Configurações do Keycloak incompletas');
            return false;
        }
        
        $url = trailingslashit($this->keycloak_url) . 'realms/' . trailingslashit($this->realm) . 'protocol/openid-connect/token';
        
        $response = wp_remote_post($url, [
            'body' => [
                'client_id' => $this->client_id,
                'client_secret' => $this->client_secret,
                'grant_type' => 'client_credentials'
            ],
            'timeout' => 10,
            'sslverify' => apply_filters('pressao_ssl_verify', true)
        ]);
        
        if (is_wp_error($response)) {
            error_log('Pressão Plugin - Erro ao obter token Keycloak: ' . $response->get_error_message());
            return false;
        }
        
        $body = wp_remote_retrieve_body($response);
        $status = wp_remote_retrieve_response_code($response);
        
        if ($status !== 200) {
            error_log('Pressão Plugin - Keycloak retornou status ' . $status . ': ' . $body);
            return false;
        }
        
        $data = json_decode($body, true);
        
        if (!isset($data['access_token'])) {
            error_log('Pressão Plugin - Resposta inválida do Keycloak: ' . $body);
            return false;
        }
        
        return $data;
    }
    
    /**
     * Faz requisição para a API
     */
    public function api_request($endpoint, $method = 'GET', $data = null, $retry = true) {
        $token = $this->get_token();

        if (!$token) {
            return new WP_Error(
                'auth_error',
                __('Não foi possível obter token de autenticação', 'pressao-plugin')
            );
        }
        
        if (empty($this->api_url)) {
            return new WP_Error(
                'config_error',
                __('URL da API não configurada', 'pressao-plugin')
            );
        }
        
        $url = trailingslashit($this->api_url) . ltrim($endpoint, '/');
        $args = [
            'method' => $method,
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ],
            'timeout' => 30,
        ];
        
        if ($data && in_array($method, ['POST', 'PUT', 'PATCH'])) {
            $args['body'] = json_encode($data);
        }
        
        $response = wp_remote_request($url, $args);

        if (is_wp_error($response)) {
            return $response;
        }
        
        $status = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        
        // Se token expirou, tenta novamente
        if ($status === 401 && $retry) {
            delete_transient($this->token_cache_key);
            return $this->api_request($endpoint, $method, $data, false);
        }
        
        $decoded_body = json_decode($body, true);

        if ($status >= 400) {
            $error_message = $decoded_body['detail']
                ?? $decoded_body['message']
                ?? sprintf(__('Erro %d na requisição', 'pressao-plugin'), $status);

            if (is_array($error_message)) {
                $error_message = wp_json_encode($error_message);
            }
            
            return new WP_Error(
                'api_error_' . $status,
                $error_message,
                ['status' => $status, 'body' => $decoded_body]
            );
        }
        
        return $decoded_body;
    }
    
    /**
     * Obtém dados de uma campanha específica
     */
    public function get_campanha($campanha_id) {
        if (empty($campanha_id)) {
            return new WP_Error(
                'invalid_campaign',
                __('ID da campanha não informado', 'pressao-plugin')
            );
        }
        
        $endpoint = sprintf('/api/v1/campanhas/%s', $campanha_id);
        $response = $this->api_request($endpoint, 'GET');
        
        if (is_wp_error($response)) {
            return $response;
        }
        
        // A resposta já é o objeto da campanha
        return $response;
    }
    
    /**
     * Obtém campanha do cache ou da API
     */
    public function get_campanha_cached($campanha_id, $cache_time = 3600) {
        $cache_key = 'pressao_campanha_' . md5($campanha_id);
        $cached = get_transient($cache_key);
        
        if ($cached !== false) {
            return [
                'success' => true,
                'data' => $cached,
                'cached' => true
            ];
        }
        
        $result = $this->get_campanha($campanha_id);
        
        if (is_wp_error($result)) {
            return $result;
        }
        
        // Cache apenas se tiver dados
        if (!empty($result)) {
            set_transient($cache_key, $result, $cache_time);
        }
        
        return [
            'success' => true,
            'data' => $result,
            'cached' => false
        ];
    }

    /**
     * Obtém a lista de alvos de uma campanha específica
     * 
     * @param string $campanha_id ID da campanha
     * @param array $params Parâmetros adicionais (filtros, paginação)
     * @return array|WP_Error Lista de alvos ou erro
     */
    public function get_alvos($campanha_id, $params = []) {
        if (empty($campanha_id)) {
            return new WP_Error(
                'invalid_campaign',
                __('ID da campanha não informado', 'pressao-plugin')
            );
        }
        
        $endpoint = sprintf('/api/v1/alvos/campanha/%s', $campanha_id);
        
        // Adiciona parâmetros de consulta se existirem
        if (!empty($params)) {
            $endpoint .= '?' . http_build_query($params);
        }
        
        $response = $this->api_request($endpoint, 'GET');
        
        if (is_wp_error($response)) {
            return $response;
        }
        
        // A resposta já é um array de alvos
        return $response;
    }
    
    /**
     * Obtém alvos do cache ou da API
     * 
     * @param string $campanha_id ID da campanha
     * @param array $params Parâmetros adicionais
     * @param int $cache_time Tempo de cache em segundos. Zero ou negativo ignora o cache,
     *                        garantindo um novo sorteio de template por requisição.
     * @return array|WP_Error Lista de alvos ou erro
     */
    public function get_alvos_cached($campanha_id, $params = [], $cache_time = 300) {
        $cache_time = intval($cache_time);

        // Cria uma chave de cache baseada nos parâmetros
        $cache_key = 'pressao_alvos_' . md5($campanha_id . serialize($params));

        if ($cache_time > 0) {
            $cached = get_transient($cache_key);

            if ($cached !== false) {
                return [
                    'success' => true,
                    'data' => $cached,
                    'cached' => true,
                    'count' => count($cached)
                ];
            }
        }
        
        $result = $this->get_alvos($campanha_id, $params);
        
        if (is_wp_error($result)) {
            return $result;
        }
        
        // Cache apenas se tiver dados
        if ($cache_time > 0 && !empty($result)) {
            set_transient($cache_key, $result, $cache_time);
        }
        
        return [
            'success' => true,
            'data' => $result,
            'cached' => false,
            'count' => is_array($result) ? count($result) : 0
        ];
    }

    /**
     * Cria uma nova ação para um alvo
     * 
     * @param array $dados Dados da ação
     * @return array|WP_Error Resultado da criação ou erro
     */
    public function criar_acao($dados) {
        // Valida dados obrigatórios
        if (empty($dados['campanha_id'])) {
            return new WP_Error(
                'invalid_data',
                __('campanha_id é obrigatório', 'pressao-plugin')
            );
        }
        
        if (empty($dados['alvo_id'])) {
            return new WP_Error(
                'invalid_data',
                __('alvo_id é obrigatório', 'pressao-plugin')
            );
        }
        
        if (empty($dados['canal'])) {
            return new WP_Error(
                'invalid_data',
                __('canal é obrigatório', 'pressao-plugin')
            );
        }
        
        // Valida canal permitido
        $canais_permitidos = ['email', 'telefone', 'instagram', 'whatsapp', 'tiktok'];
        if (!in_array($dados['canal'], $canais_permitidos)) {
            return new WP_Error(
                'invalid_data',
                sprintf(
                    __('canal deve ser um dos seguintes: %s', 'pressao-plugin'),
                    implode(', ', $canais_permitidos)
                )
            );
        }
        
        // Valida dados do ativista quando fornecidos
        if (!empty($dados['ativista']) && is_array($dados['ativista'])) {
            if (empty($dados['ativista']['nome'])) {
                return new WP_Error(
                    'invalid_data',
                    __('nome do ativista é obrigatório', 'pressao-plugin')
                );
            }
            
            if (empty($dados['ativista']['email']) && empty($dados['ativista']['telefone'])) {
                return new WP_Error(
                    'invalid_data',
                    __('email ou telefone do ativista é obrigatório', 'pressao-plugin')
                );
            }
        }
        
        // Prepara dados para a API
        $payload = [
            'campanha_id' => $dados['campanha_id'],
            'alvo_id' => $dados['alvo_id'],
            'canal' => $dados['canal'],
            'anonimo' => isset($dados['anonimo']) ? (bool) $dados['anonimo'] : false
        ];
        
        // Adiciona template_id se fornecido
        if (!empty($dados['template_id'])) {
            $payload['template_id'] = $dados['template_id'];
        }
        
        if (!empty($dados['sessao_id'])) {
            $payload['sessao_id'] = $dados['sessao_id'];
        }
        
        // Adiciona dados do ativista se não for anônimo
        if (!empty($dados['ativista']) && is_array($dados['ativista']) && empty($dados['anonimo'])) {
            $payload['ativista'] = [
                'nome' => sanitize_text_field($dados['ativista']['nome'])
            ];
            
            if (!empty($dados['ativista']['email'])) {
                $payload['ativista']['email'] = sanitize_email($dados['ativista']['email']);
            }
            
            if (!empty($dados['ativista']['telefone'])) {
                $payload['ativista']['telefone'] = sanitize_text_field($dados['ativista']['telefone']);
            }
        }
        
        // Log para debug
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('Pressão Plugin - Criando ação: ' . json_encode($payload));
        }
        
        // Faz a requisição
        $response = $this->api_request('/api/v1/acoes/', 'POST', $payload);
        if (is_wp_error($response)) {
            return $response;
        }
        
        // Retorna a resposta da API
        return [
            'success' => true,
            'data' => $response,
            'message' => __('Ação criada com sucesso!', 'pressao-plugin')
        ];
    }

    /**
     * Cria uma ação anônima (sem dados do ativista)
     * 
     * @param string $campanha_id ID da campanha
     * @param string $alvo_id ID do alvo
     * @param string $canal Canal da ação (email, whatsapp, etc)
     * @param string|null $template_id ID do template (opcional)
     * @return array|WP_Error Resultado da criação
     */
    public function criar_acao_anonima($campanha_id, $alvo_id, $canal = 'email', $template_id = null, $sessao_id = null) {
        $dados = [
            'campanha_id' => $campanha_id,
            'alvo_id' => $alvo_id,
            'canal' => $canal,
            'anonimo' => true
        ];
        
        if ($template_id) {
            $dados['template_id'] = $template_id;
        }
        
        if ($sessao_id) {
            $dados['sessao_id'] = $sessao_id;
        }
        
        return $this->criar_acao($dados);
    }
    
    /**
     * Cria uma ação sem dados do ativista (não é anônima — sessão ainda não identificada)
     */
    public function criar_acao_sem_ativista($campanha_id, $alvo_id, $canal = 'email', $template_id = null, $sessao_id = null) {
        $dados = [
            'campanha_id' => $campanha_id,
            'alvo_id' => $alvo_id,
            'canal' => $canal,
            'anonimo' => false
        ];
        
        if ($template_id) {
            $dados['template_id'] = $template_id;
        }
        
        if ($sessao_id) {
            $dados['sessao_id'] = $sessao_id;
        }
        
        return $this->criar_acao($dados);
    }

    /**
     * Cria uma ação com dados do ativista
     * 
     * @param string $campanha_id ID da campanha
     * @param string $alvo_id ID do alvo
     * @param string $canal Canal da ação
     * @param array $ativista Dados do ativista (nome, email, telefone)
     * @param string|null $template_id ID do template (opcional)
     * @return array|WP_Error Resultado da criação
     */
    public function criar_acao_com_ativista($campanha_id, $alvo_id, $canal, $ativista, $template_id = null, $sessao_id = null) {
        $dados = [
            'campanha_id' => $campanha_id,
            'alvo_id' => $alvo_id,
            'canal' => $canal,
            'anonimo' => false,
            'ativista' => [
                'nome' => $ativista['nome'] ?? '',
                'email' => $ativista['email'] ?? '',
                'telefone' => $ativista['telefone'] ?? ''
            ]
        ];
        
        if ($template_id) {
            $dados['template_id'] = $template_id;
        }
        
        if ($sessao_id) {
            $dados['sessao_id'] = $sessao_id;
        }
        
        return $this->criar_acao($dados);
    }

    /**
     * Obtém contagem de ações confirmadas da campanha (cache curto).
     *
     * @param string $campanha_id ID da campanha
     * @param int $cache_time TTL do transient em segundos
     * @return array|WP_Error
     */
    public function get_acoes_confirmadas_count($campanha_id, $cache_time = 60) {
        $cache_key = 'pressao_acoes_count_' . md5($campanha_id);
        $cached = get_transient($cache_key);
        if ($cached !== false) {
            return ['success' => true, 'count' => (int) $cached, 'cached' => true];
        }
        $campanha = $this->get_campanha($campanha_id);
        if (is_wp_error($campanha)) {
            return $campanha;
        }
        $count = isset($campanha['acoes_confirmadas']) ? (int) $campanha['acoes_confirmadas'] : 0;
        set_transient($cache_key, $count, $cache_time);
        return ['success' => true, 'count' => $count, 'cached' => false];
    }

    /**
     * Invalida o cache do contador de ações confirmadas.
     *
     * @param string $campanha_id ID da campanha
     */
    public function invalidar_cache_contador($campanha_id) {
        delete_transient('pressao_acoes_count_' . md5($campanha_id));
    }

    /**
     * Confirma uma ação manual aguardando ação humana.
     *
     * @param string $acao_id ID da ação
     * @return array|WP_Error Resultado da confirmação ou erro
     */
    public function confirmar_acao($acao_id) {
        if (empty($acao_id)) {
            return new WP_Error(
                'invalid_data',
                __('acao_id é obrigatório', 'pressao-plugin')
            );
        }

        $endpoint = sprintf('/api/v1/acoes/%s/confirmar', sanitize_text_field($acao_id));
        $response = $this->api_request($endpoint, 'PATCH');

        if (is_wp_error($response)) {
            return $response;
        }

        return [
            'success' => true,
            'message' => __('Ação confirmada com sucesso!', 'pressao-plugin'),
            'acoes_confirmadas' => isset($response['acoes_confirmadas'])
                ? (int) $response['acoes_confirmadas']
                : null,
        ];
    }
}