<?php
/**
 * Classe para gerenciar a API REST
 */
class RPI_API {
    /**
     * Inicializar a API
     */
    public function init() {
        add_action('rest_api_init', array($this, 'register_endpoints'));
    }
    
    /**
     * Registrar os endpoints da API
     */
    public function register_endpoints() {
        register_rest_route('raspberry/v1', '/upload', array(
            'methods' => 'POST',
            'callback' => array($this, 'process_upload'),
            'permission_callback' => array($this, 'verify_api_request')
        ));
        
        register_rest_route('raspberry/v1', '/test', array(
            'methods' => 'GET',
            'callback' => array($this, 'test_connection'),
            'permission_callback' => array($this, 'verify_api_request')
        ));
    }
    
    /**
     * Verificar se a solicitação tem permissão
     */
    public function verify_api_request() {
        $auth_header = isset($_SERVER['HTTP_X_API_KEY']) ? $_SERVER['HTTP_X_API_KEY'] : '';
        $api_key = get_option('rpi_uploader_api_key');
        
        // Verifica se a chave API corresponde
        if ($auth_header === $api_key) {
            return true;
        }
        
        // Alternativa: também permite acesso para administradores logados
        return current_user_can('upload_files');
    }
    
    /**
     * Endpoint de teste
     */
    public function test_connection() {
        return array(
            'success' => true,
            'message' => 'API de upload do Raspberry Pi está funcionando!',
            'time' => current_time('mysql')
        );
    }
    
    /**
     * Processar o upload de arquivo
     */
    public function process_upload($request) {
        if (!function_exists('wp_handle_upload')) {
            require_once(ABSPATH . 'wp-admin/includes/file.php');
        }
        
        // Verificar se existe arquivo na requisição
        if (empty($_FILES['file'])) {
            return new WP_Error('no_file', 'Nenhum arquivo foi enviado', array('status' => 400));
        }
        
        $file = $_FILES['file'];
        
        // Configurações de upload
        $upload_overrides = array(
            'test_form' => false,
            'test_type' => get_option('rpi_uploader_validate_mime', true),
        );
        
        // Realizar o upload
        $movefile = wp_handle_upload($file, $upload_overrides);
        
        if ($movefile && !isset($movefile['error'])) {
            // Registrar log
            $this->log_upload('success', $file['name'], $movefile['url']);
            
            // Adicionar à biblioteca de mídia se configurado
            if (get_option('rpi_uploader_add_to_media', true)) {
                return $this->add_to_media_library($movefile, $file);
            }
            
            return array(
                'success' => true,
                'file_url' => $movefile['url'],
                'file_path' => $movefile['file'],
                'message' => 'Upload realizado com sucesso'
            );
        } else {
            $this->log_upload('error', $file['name'], $movefile['error']);
            
            return new WP_Error(
                'upload_error', 
                $movefile['error'], 
                array('status' => 500)
            );
        }
    }
    
    /**
     * Adicionar arquivo à biblioteca de mídia
     */
    private function add_to_media_library($movefile, $file) {
        // Dados do anexo
        $attachment = array(
            'guid'           => $movefile['url'],
            'post_mime_type' => $movefile['type'],
            'post_title'     => preg_replace('/\.[^.]+$/', '', basename($file['name'])),
            'post_content'   => '',
            'post_status'    => 'inherit'
        );
        
        // Inserir na biblioteca de mídia
        $attach_id = wp_insert_attachment($attachment, $movefile['file']);
        
        // Gerar metadados para o anexo
        require_once(ABSPATH . 'wp-admin/includes/image.php');
        $attach_data = wp_generate_attachment_metadata($attach_id, $movefile['file']);
        wp_update_attachment_metadata($attach_id, $attach_data);
        
        return array(
            'success' => true,
            'file_url' => $movefile['url'],
            'attachment_id' => $attach_id,
            'message' => 'Upload realizado e adicionado à biblioteca de mídia'
        );
    }
    
    /**
     * Registrar log de upload
     */
    private function log_upload($status, $filename, $message) {
        if (!get_option('rpi_uploader_enable_logs', true)) {
            return;
        }
        
        $logs = get_option('rpi_uploader_logs', array());
        
        // Limitar a 100 logs
        if (count($logs) >= 100) {
            array_shift($logs);
        }
        
        $logs[] = array(
            'time' => current_time('mysql'),
            'status' => $status,
            'filename' => $filename,
            'message' => $message,
            'ip' => $_SERVER['REMOTE_ADDR']
        );
        
        update_option('rpi_uploader_logs', $logs);
    }
}