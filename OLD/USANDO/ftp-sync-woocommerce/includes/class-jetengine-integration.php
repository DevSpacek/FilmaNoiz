<?php
/**
 * Classe para integração com JetEngine
 */

if (!defined('ABSPATH')) {
    exit; // Saída se acessado diretamente
}

class FTP_Sync_JetEngine_Integration {
    
    /**
     * Construtor
     */
    public function __construct() {
        // Verificar se JetEngine está ativo
        if (!$this->is_jetengine_active()) {
            return;
        }
        
        // Hooks para JetEngine
        add_filter('jet-engine/forms/booking/inserted-post-id', array($this, 'handle_form_submission'), 10, 2);
        
        // Adicionar campos personalizados
        add_action('init', array($this, 'register_meta_fields'), 99);
    }
    
    /**
     * Verificar se JetEngine está ativo
     */
    private function is_jetengine_active() {
        return defined('JET_ENGINE_VERSION') || class_exists('Jet_Engine');
    }
    
    /**
     * Registrar campos personalizados
     */
    public function register_meta_fields() {
        if (!function_exists('jet_engine')) {
            return;
        }
        
        // Adicionar campos para usuários (se necessário)
        if (jet_engine()->meta_boxes) {
            // Verificar se já existe o campo
            $user_fields = jet_engine()->meta_boxes->get_registered_fields('user');
            $has_field = false;
            
            if (!empty($user_fields)) {
                foreach ($user_fields as $field) {
                    if (isset($field['name']) && $field['name'] === 'ftp_folder_name') {
                        $has_field = true;
                        break;
                    }
                }
            }
            
            // Adicionar campo se não existir
            if (!$has_field) {
                jet_engine()->meta_boxes->add_field_to_box('user', array(
                    'type'        => 'text',
                    'name'        => 'ftp_folder_name',
                    'title'       => 'Nome da pasta FTP',
                    'description' => 'Nome da pasta do cliente no servidor FTP',
                    'is_required' => false,
                ));
            }
        }
    }
    
    /**
     * Criar pasta FTP para usuário após cadastro via JetEngine
     */
    public function create_ftp_folder_for_user($user_id, $data) {
        // Se não há campo ftp_folder_name, não precisa criar pasta
        if (!isset($data['ftp_folder_name']) || empty($data['ftp_folder_name'])) {
            return;
        }
        
        $folder_name = sanitize_title($data['ftp_folder_name']);
        
        // Salvar campo personalizado para o usuário
        update_user_meta($user_id, 'ftp_folder_name', $folder_name);
        
        // Criar pasta no servidor FTP
        $this->create_ftp_folder($folder_name);
        
        // Registrar log
        $this->log("Pasta FTP criada para usuário #{$user_id}: {$folder_name}");
    }
    
    /**
     * Atualizar pasta FTP para usuário após edição via JetEngine
     */
    public function update_ftp_folder_for_user($user_id, $data) {
        // Se não há campo ftp_folder_name, não precisa atualizar
        if (!isset($data['ftp_folder_name'])) {
            return;
        }
        
        $folder_name = sanitize_title($data['ftp_folder_name']);
        $old_folder = get_user_meta($user_id, 'ftp_folder_name', true);
        
        // Se o nome da pasta não mudou, não precisa fazer nada
        if ($old_folder === $folder_name) {
            return;
        }
        
        // Atualizar meta
        update_user_meta($user_id, 'ftp_folder_name', $folder_name);
        
        // Criar nova pasta (se necessário)
        if (!empty($folder_name)) {
            $this->create_ftp_folder($folder_name);
            $this->log("Pasta FTP atualizada para usuário #{$user_id}: {$old_folder} -> {$folder_name}");
        }
    }
    
    /**
     * Manipular envio de formulário JetEngine
     */
    public function handle_form_submission($inserted_id, $data) {
        // Verificar se o formulário tem um campo ftp_folder_name
        if (!isset($data['ftp_folder_name']) || empty($data['ftp_folder_name'])) {
            return $inserted_id;
        }
        
        $folder_name = sanitize_title($data['ftp_folder_name']);
        
        // Se o post inserido for um usuário
        if (isset($data['_user_id']) && $data['_user_id']) {
            $user_id = $data['_user_id'];
            update_user_meta($user_id, 'ftp_folder_name', $folder_name);
            $this->create_ftp_folder($folder_name);
            $this->log("Pasta FTP criada para usuário #{$user_id} via formulário: {$folder_name}");
        }
        
        return $inserted_id;
    }
    
    /**
     * Criar pasta no servidor FTP
     */
    private function create_ftp_folder($folder_name) {
        if (empty($folder_name)) {
            return false;
        }
        
        // Conectar ao FTP
        $ftp = new FTP_Sync_Connector();
        if (!$ftp->connect()) {
            $this->log("ERRO: Não foi possível conectar ao servidor FTP para criar pasta: {$folder_name}");
            return false;
        }
        
        // Caminho completo da pasta
        $base_path = get_option('ftp_sync_ftp_base_path', '/');
        $folder_path = rtrim($base_path, '/') . '/' . $folder_name;
        
        // Criar pasta
        $result = $ftp->create_directory($folder_path);
        
        // Desconectar
        $ftp->disconnect();
        
        return $result;
    }
    
    /**
     * Registrar log
     */
    private function log($message) {
        if (function_exists('ftp_sync_woocommerce')) {
            ftp_sync_woocommerce()->log("[JetEngine] " . $message);
        }
    }
}