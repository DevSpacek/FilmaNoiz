<?php
/**
 * Plugin Name: SFTP User Folders
 * Description: Cria automaticamente pastas para usuários registrados via SFTP com papéis específicos.
 * Version: 1.0
 * Author: DevSpacek
 */

// Prevenir acesso direto
if (!defined('ABSPATH')) {
    exit;
}

class SFTP_User_Folders {
    
    public function __construct() {
        // Hooks para criação de usuários
        add_action('user_register', array($this, 'handle_new_user'), 10, 1);
        
        // Admin settings
        add_action('admin_init', array($this, 'register_settings'));
        add_action('admin_menu', array($this, 'add_admin_menu'));
        
        // Incluir arquivos necessários
        require_once plugin_dir_path(__FILE__) . 'includes/class-sftp-connection.php';
        require_once plugin_dir_path(__FILE__) . 'includes/class-sftp-folder-manager.php';
        require_once plugin_dir_path(__FILE__) . 'includes/class-admin-settings.php';
        
        // Configuração padrão para o diretório base se não existir
        if (!get_option('sftp_base_directory')) {
            update_option('sftp_base_directory', '/path/to/default/user_folders');
        }
    }
    
    /**
     * Registrar configurações do plugin
     */
    public function register_settings() {
        register_setting('sftp_user_folders', 'sftp_base_directory');
        register_setting('sftp_user_folders', 'sftp_selected_roles');
    }
    
    /**
     * Adicionar menu de administração
     */
    public function add_admin_menu() {
        add_options_page(
            'SFTP User Folders',
            'SFTP User Folders',
            'manage_options',
            'sftp-user-folders',
            array($this, 'render_settings_page')
        );
    }
    
    /**
     * Renderizar página de configurações
     */
    public function render_settings_page() {
        if (!current_user_can('manage_options')) {
            wp_die('Acesso negado');
        }
        
        // Obter configurações atuais
        $base_directory = get_option('sftp_base_directory');
        $selected_roles = get_option('sftp_selected_roles', array());
        
        // Processar ações do formulário
        // (Implementar lógica para processar as configurações do formulário)
        
        include plugin_dir_path(__FILE__) . 'templates/admin-page.php';
    }
    
    /**
     * Manipular criação de novo usuário
     */
    public function handle_new_user($user_id) {
        // Implementar lógica para criar pastas no SFTP
    }
}

// Inicializar plugin
$sftp_user_folders = new SFTP_User_Folders();
?>