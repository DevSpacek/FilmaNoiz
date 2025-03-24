<?php
/**
 * Integração com JetEngine
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Verificar se o JetEngine está ativo
 */
function ftp_sync_jetengine_is_active() {
    return defined('JET_ENGINE_VERSION') || class_exists('Jet_Engine');
}

/**
 * Registrar campo FTP para usuários no JetEngine
 */
function ftp_sync_register_jetengine_field() {
    // Verificar se JetEngine está disponível
    if (!ftp_sync_jetengine_is_active() || !function_exists('jet_engine')) {
        ftp_sync_log("JETENGINE: Não disponível para registrar campos");
        return;
    }
    
    // Verificar se o componente meta_boxes existe
    if (!isset(jet_engine()->meta_boxes)) {
        ftp_sync_log("JETENGINE: Componente meta_boxes não disponível");
        return;
    }
    
    try {
        // Registrar campo para usuários
        ftp_sync_log("JETENGINE: Tentando registrar campo ftp_folder_name");
        
        jet_engine()->meta_boxes->add_field_to_box('user', array(
            'type'        => 'text',
            'name'        => 'ftp_folder_name',
            'title'       => 'Pasta FTP do Cliente',
            'description' => 'Nome da pasta no servidor FTP para este cliente',
            'is_required' => false,
        ));
        
        ftp_sync_log("JETENGINE: Campo registrado com sucesso");
    } catch (Exception $e) {
        ftp_sync_log("JETENGINE ERRO: " . $e->getMessage());
    }
}

/**
 * Manipular criação/atualização de usuário pelo JetEngine
 */
function ftp_sync_handle_jetengine_user($user_id, $data) {
    // Verificar se tem dado de pasta FTP
    if (!isset($data['ftp_folder_name']) || empty($data['ftp_folder_name'])) {
        ftp_sync_log("JETENGINE: Sem nome de pasta FTP definido para usuário #$user_id");
        return;
    }
    
    $folder_name = sanitize_title($data['ftp_folder_name']);
    
    // Salvar no user meta
    update_user_meta($user_id, 'ftp_folder_name', $folder_name);
    ftp_sync_log("JETENGINE: Pasta FTP '$folder_name' definida para usuário #$user_id");
    
    // Criar pasta no servidor FTP
    ftp_sync_create_client_folder($folder_name);
}

/**
 * Criar pasta no servidor FTP
 */
function ftp_sync_create_client_folder($folder_name) {
    ftp_sync_log("JETENGINE: Tentando criar pasta '$folder_name' no servidor FTP");
    
    // Carregar funções FTP
    require_once FTP_SYNC_PATH . 'includes/ftp-connector.php';
    
    // Conectar ao FTP
    $conn = ftp_sync_connect_ftp();
    if (!$conn) {
        ftp_sync_log("JETENGINE ERRO: Não foi possível conectar ao FTP para criar pasta");
        return false;
    }
    
    // Criar pasta
    $base_path = get_option('ftp_sync_ftp_path', '/');
    $folder_path = rtrim($base_path, '/') . '/' . $folder_name;
    
    // Verificar se já existe
    $current_dir = @ftp_pwd($conn);
    $exists = @ftp_chdir($conn, $folder_path);
    
    if ($exists) {
        @ftp_chdir($conn, $current_dir);
        ftp_sync_log("JETENGINE: Pasta '$folder_path' já existe no servidor FTP");
        @ftp_close($conn);
        return true;
    }
    
    // Criar nova pasta
    $result = @ftp_mkdir($conn, $folder_path);
    
    if ($result) {
        ftp_sync_log("JETENGINE: Pasta '$folder_path' criada com sucesso no servidor FTP");
    } else {
        ftp_sync_log("JETENGINE ERRO: Falha ao criar pasta '$folder_path'");
    }
    
    @ftp_close($conn);
    return $result;
}

// Registrar hooks para JetEngine se estiver disponível
if (ftp_sync_jetengine_is_active()) {
    // Registrar campo
    add_action('init', 'ftp_sync_register_jetengine_field', 99);
    
    // Hooks para usuários
    add_action('jet-engine/user/after-add', 'ftp_sync_handle_jetengine_user', 10, 2);
    add_action('jet-engine/user/after-edit', 'ftp_sync_handle_jetengine_user', 10, 2);
    
    ftp_sync_log("JETENGINE: Integração ativada");
} else {
    ftp_sync_log("JETENGINE: Não detectado");
}