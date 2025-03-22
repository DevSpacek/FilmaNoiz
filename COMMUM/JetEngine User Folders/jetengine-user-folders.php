<?php
/**
 * Plugin Name: JetEngine User Folders
 * Description: Cria automaticamente pastas para usuários registrados via JetEngine com papéis específicos
 * Version: 1.0
 * Author: DevSpacek
 */

// Prevenir acesso direto
if (!defined('ABSPATH')) {
    exit;
}

class JetEngine_User_Folders {
    
    public function __construct() {
        // Hooks para criação de usuários
        add_action('user_register', array($this, 'handle_new_user'), 10, 1);
        
        // Hooks específicos do JetEngine
        add_action('jet-engine/forms/booking/notification/success', array($this, 'handle_jetengine_form'), 10, 2);
        
        // Hooks para atualização de usuário
        add_action('profile_update', array($this, 'handle_user_update'), 10, 2);
        add_action('set_user_role', array($this, 'handle_role_change'), 10, 3);
        
        // Admin settings
        add_action('admin_init', array($this, 'register_settings'));
        add_action('admin_menu', array($this, 'add_admin_menu'));
        
        // Configuração padrão para o diretório base se não existir
        if (!get_option('juf_base_directory')) {
            $uploads_dir = wp_upload_dir();
            update_option('juf_base_directory', $uploads_dir['basedir'] . '/user_folders');
        }
    }
    
    /**
     * Registrar configurações do plugin
     */
    public function register_settings() {
        register_setting('jetengine_user_folders', 'juf_base_directory');
        register_setting('jetengine_user_folders', 'juf_selected_roles');
        register_setting('jetengine_user_folders', 'juf_folder_structure');
        register_setting('jetengine_user_folders', 'juf_folder_naming');
    }
    
    /**
     * Adicionar menu de administração
     */
    public function add_admin_menu() {
        add_options_page(
            'JetEngine User Folders',
            'User Folders',
            'manage_options',
            'jetengine-user-folders',
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
        $base_directory = get_option('juf_base_directory');
        $selected_roles = get_option('juf_selected_roles', array());
        $folder_structure = get_option('juf_folder_structure', "imports\nimagens\ndocumentos");
        $folder_naming = get_option('juf_folder_naming', 'username');
        
        if (empty($selected_roles) || !is_array($selected_roles)) {
            $selected_roles = array();
        }
        
        // Processar ação de teste
        $test_message = '';
        if (isset($_POST['test_folder_creation']) && check_admin_referer('juf_test_creation')) {
            $user_id = isset($_POST['test_user_id']) ? intval($_POST['test_user_id']) : 0;
            if ($user_id > 0) {
                $result = $this->create_user_folders($user_id);
                if ($result) {
                    $test_message = '<div class="notice notice-success"><p>Pastas criadas com sucesso!</p></div>';
                } else {
                    $test_message = '<div class="notice notice-error"><p>Falha na criação de pastas ou usuário não tem papel compatível.</p></div>';
                }
            }
        }
        
        ?>
        <div class="wrap">
            <h1>JetEngine User Folders</h1>
            
            <?php echo $test_message; ?>
            
            <form method="post" action="options.php">
                <?php settings_fields('jetengine_user_folders'); ?>
                
                <table class="form-table">
                    <tr>
                        <th scope="row">Diretório Base</th>
                        <td>
                            <input type="text" name="juf_base_directory" value="<?php echo esc_attr($base_directory); ?>" class="regular-text" />
                            <p class="description">Caminho absoluto para o diretório base onde serão criadas as pastas de usuários.</p>
                            
                            <?php 
                            if (!empty($base_directory)) {
                                if (file_exists($base_directory)) {
                                    echo '<p style="color: green;">✓ Diretório existe e é acessível.</p>';
                                } else {
                                    echo '<p style="color: orange;">⚠ Diretório não existe. Será criado automaticamente quando necessário.</p>';
                                }
                            }
                            ?>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row">Papéis de Usuário</th>
                        <td>
                            <?php
                            $all_roles = wp_roles()->get_names();
                            foreach ($all_roles as $role_id => $role_name) {
                                $checked = in_array($role_id, $selected_roles) ? 'checked' : '';
                                ?>
                                <label>
                                    <input type="checkbox" name="juf_selected_roles[]" value="<?php echo esc_attr($role_id); ?>" <?php echo $checked; ?> />
                                    <?php echo esc_html($role_name); ?>
                                </label><br>
                                <?php
                            }
                            ?>
                            <p class="description">Selecione quais papéis de usuário terão pastas criadas automaticamente.</p>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row">Estrutura de Pastas</th>
                        <td>
                            <textarea name="juf_folder_structure" rows="5" class="large-text"><?php echo esc_textarea($folder_structure); ?></textarea>
                            <p class="description">Lista de subpastas a serem criadas para cada usuário (uma por linha).</p>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row">Nomenclatura de Pastas</th>
                        <td>
                            <select name="juf_folder_naming">
                                <option value="username" <?php selected('username', $folder_naming); ?>>Nome de Usuário</option>
                                <option value="user_id" <?php selected('user_id', $folder_naming); ?>>ID do Usuário</option>
                                <option value="email" <?php selected('email', $folder_naming); ?>>E-mail (antes do @)</option>
                                <option value="display_name" <?php selected('display_name', $folder_naming); ?>>Nome de Exibição</option>
                            </select>
                            <p class="description">Como as pastas de usuário serão nomeadas.</p>
                        </td>
                    </tr>
                </table>
                
                <?php submit_button('Salvar Alterações'); ?>
            </form>
            
            <hr>
            
            <h2>Testar Criação de Pastas</h2>
            <form method="post">
                <?php wp_nonce_field('juf_test_creation'); ?>
                
                <table class="form-table">
                    <tr>
                        <th scope="row">Selecione um Usuário</th>
                        <td>
                            <select name="test_user_id">
                                <?php
                                $users = get_users();
                                foreach ($users as $user) {
                                    echo '<option value="' . esc_attr($user->ID) . '">' . esc_html($user->user_login) . ' (' . esc_html(implode(', ', $user->roles)) . ')</option>';
                                }
                                ?>
                            </select>
                        </td>
                    </tr>
                </table>
                
                <?php submit_button('Criar Pastas para Este Usuário', 'secondary', 'test_folder_creation'); ?>
            </form>
        </div>
        <?php
    }
    
    /**
     * Manipular criação de novo usuário
     */
    public function handle_new_user($user_id) {
        $this->create_user_folders($user_id);
    }
    
    /**
     * Manipular envio de formulário JetEngine
     */
    public function handle_jetengine_form($manager, $notifications) {
        $user_id = $manager->get_response_data('user_id');
        if (!empty($user_id)) {
            $this->create_user_folders($user_id);
        }
    }
    
    /**
     * Manipular atualização de usuário
     */
    public function handle_user_update($user_id, $old_user_data) {
        // Verificar se o usuário tem pastas criadas, se não, criar
        $this->create_user_folders($user_id);
    }
    
    /**
     * Manipular mudança de papel
     */
    public function handle_role_change($user_id, $role, $old_roles) {
        // Verificar se o novo papel deve ter pastas
        $this->create_user_folders($user_id);
    }
    
    /**
     * Verificar se o usuário deve ter pastas criadas
     */
    private function should_create_folders($user_id) {
        $user = get_userdata($user_id);
        if (!$user) {
            return false;
        }
        
        $selected_roles = get_option('juf_selected_roles', array());
        if (empty($selected_roles) || !is_array($selected_roles)) {
            return false;
        }
        
        // Verificar se usuário tem algum dos papéis selecionados
        foreach ($user->roles as $role) {
            if (in_array($role, $selected_roles)) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * Criar pastas para um usuário
     */
    public function create_user_folders($user_id) {
        // Verificar se o usuário deve ter pastas
        if (!$this->should_create_folders($user_id)) {
            return false;
        }
        
        $user = get_userdata($user_id);
        $base_directory = get_option('juf_base_directory');
        $folder_naming = get_option('juf_folder_naming', 'username');
        
        // Determinar nome da pasta principal
        switch ($folder_naming) {
            case 'user_id':
                $folder_name = $user_id;
                break;
            case 'email':
                $email_parts = explode('@', $user->user_email);
                $folder_name = sanitize_file_name($email_parts[0]);
                break;
            case 'display_name':
                $folder_name = sanitize_file_name($user->display_name);
                break;
            case 'username':
            default:
                $folder_name = sanitize_file_name($user->user_login);
        }
        
        // Criar diretório base se não existir
        if (!file_exists($base_directory)) {
            wp_mkdir_p($base_directory);
        }
        
        // Caminho para pasta do usuário
        $user_folder = trailingslashit($base_directory) . $folder_name;
        
        // Criar pasta do usuário
        if (!file_exists($user_folder)) {
            wp_mkdir_p($user_folder);
        }
        
        // Criar subpastas
        $folder_structure = get_option('juf_folder_structure', "imports\nimagens\ndocumentos");
        $subfolders = explode("\n", $folder_structure);
        
        foreach ($subfolders as $subfolder) {
            $subfolder = trim($subfolder);
            if (!empty($subfolder)) {
                $subfolder_path = trailingslashit($user_folder) . sanitize_file_name($subfolder);
                if (!file_exists($subfolder_path)) {
                    wp_mkdir_p($subfolder_path);
                }
            }
        }
        
        // Salvar o caminho da pasta no meta do usuário
        update_user_meta($user_id, 'user_folder_path', $folder_name);
        
        return true;
    }
}

// Inicializar plugin
$jetengine_user_folders = new JetEngine_User_Folders();

// Função auxiliar para outros plugins/temas
function juf_get_user_folder($user_id) {
    $folder_name = get_user_meta($user_id, 'user_folder_path', true);
    if (empty($folder_name)) {
        return false;
    }
    
    $base_directory = get_option('juf_base_directory');
    return trailingslashit($base_directory) . $folder_name;
}