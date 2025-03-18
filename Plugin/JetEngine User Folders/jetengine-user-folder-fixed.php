<?php
/**
 * Plugin Name: JetEngine User Folders (Fixado)
 * Description: Cria automaticamente pastas para usuários registrados via JetEngine (versão com correções de permissão)
 * Version: 1.1
 * Author: DevSpacek
 */

// Prevenir acesso direto
if (!defined('ABSPATH')) {
    exit;
}

class JetEngine_User_Folders_Fixed {
    
    private $log = array();
    
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
        register_setting('jetengine_user_folders', 'juf_logs', array('default' => array()));
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
        $logs = get_option('juf_logs', array());
        
        if (empty($selected_roles) || !is_array($selected_roles)) {
            $selected_roles = array();
        }
        
        // Limpar os logs
        if (isset($_POST['clear_logs']) && check_admin_referer('juf_clear_logs')) {
            update_option('juf_logs', array());
            $logs = array();
        }
        
        // Processar ação de teste
        $test_message = '';
        if (isset($_POST['test_folder_creation']) && check_admin_referer('juf_test_creation')) {
            $user_id = isset($_POST['test_user_id']) ? intval($_POST['test_user_id']) : 0;
            if ($user_id > 0) {
                $result = $this->create_user_folders($user_id, true);
                if ($result) {
                    $test_message = '<div class="notice notice-success"><p>Pastas criadas com sucesso! Veja os logs abaixo para detalhes.</p></div>';
                } else {
                    $test_message = '<div class="notice notice-error"><p>Falha na criação de pastas. Veja os logs abaixo para detalhes.</p></div>';
                }
                
                // Atualizar logs na página
                $logs = get_option('juf_logs', array());
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
                            <p class="description"><strong>Recomendação:</strong> Use um caminho dentro da pasta de uploads do WordPress.</p>
                            
                            <?php 
                            $uploads_dir = wp_upload_dir();
                            echo '<p class="description">Exemplo recomendado: ' . esc_html($uploads_dir['basedir']) . '/user_folders</p>';
                            
                            if (!empty($base_directory)) {
                                if (file_exists($base_directory)) {
                                    if (is_writable($base_directory)) {
                                        echo '<p style="color: green;">✓ Diretório existe e é gravável.</p>';
                                    } else {
                                        echo '<p style="color: red;">✗ Diretório existe mas NÃO É GRAVÁVEL. Ajuste as permissões.</p>';
                                    }
                                } else {
                                    echo '<p style="color: orange;">⚠ Diretório não existe. Será criado automaticamente quando necessário.</p>';
                                    
                                    // Verificar se o diretório pai é gravável
                                    $parent_dir = dirname($base_directory);
                                    if (file_exists($parent_dir)) {
                                        if (is_writable($parent_dir)) {
                                            echo '<p style="color: green;">✓ Diretório pai é gravável, poderemos criar o diretório base.</p>';
                                        } else {
                                            echo '<p style="color: red;">✗ Diretório pai NÃO É GRAVÁVEL. Não será possível criar o diretório base.</p>';
                                        }
                                    } else {
                                        echo '<p style="color: red;">✗ Diretório pai não existe. Verifique o caminho.</p>';
                                    }
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
            
            <hr>
            
            <h2>Logs de Operação</h2>
            
            <form method="post">
                <?php wp_nonce_field('juf_clear_logs'); ?>
                <?php submit_button('Limpar Logs', 'delete', 'clear_logs'); ?>
            </form>
            
            <table class="widefat">
                <thead>
                    <tr>
                        <th>Data/Hora</th>
                        <th>Status</th>
                        <th>Mensagem</th>
                        <th>Detalhes</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($logs)): ?>
                        <tr>
                            <td colspan="4">Nenhum log disponível.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach (array_reverse($logs) as $log): ?>
                            <tr>
                                <td><?php echo esc_html($log['time']); ?></td>
                                <td>
                                    <?php if ($log['status'] === 'success'): ?>
                                        <span style="color:green;">Sucesso</span>
                                    <?php else: ?>
                                        <span style="color:red;">Erro</span>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo esc_html($log['message']); ?></td>
                                <td><?php echo esc_html($log['details']); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php
    }
    
    /**
     * Adicionar mensagem ao log
     */
    private function log($status, $message, $details = '') {
        $log_entry = array(
            'time' => current_time('mysql'),
            'status' => $status,
            'message' => $message,
            'details' => $details
        );
        
        $this->log[] = $log_entry;
        
        // Também salvar no banco de dados
        $logs = get_option('juf_logs', array());
        $logs[] = $log_entry;
        
        // Limitar a 100 logs
        if (count($logs) > 100) {
            array_shift($logs);
        }
        
        update_option('juf_logs', $logs);
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
            $this->log('error', "Usuário #$user_id não encontrado");
            return false;
        }
        
        $selected_roles = get_option('juf_selected_roles', array());
        if (empty($selected_roles) || !is_array($selected_roles)) {
            $this->log('error', "Nenhum papel selecionado nas configurações");
            return false;
        }
        
        // Verificar se usuário tem algum dos papéis selecionados
        foreach ($user->roles as $role) {
            if (in_array($role, $selected_roles)) {
                return true;
            }
        }
        
        $this->log('error', "Usuário #$user_id não tem papel compatível", "Papéis do usuário: " . implode(', ', $user->roles));
        return false;
    }
    
    /**
     * Criar pastas para um usuário
     */
    public function create_user_folders($user_id, $is_test = false) {
        // Limpar log para este processo
        $this->log = array();
        
        // Verificar se o usuário deve ter pastas
        if (!$is_test && !$this->should_create_folders($user_id)) {
            return false;
        }
        
        $user = get_userdata($user_id);
        if (!$user) {
            $this->log('error', "Usuário #$user_id não encontrado");
            return false;
        }
        
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
        
        $this->log('success', "Nome da pasta definido", "Usuário: {$user->user_login}, Nome da pasta: $folder_name");
        
        // Verificar se o diretório base está configurado
        if (empty($base_directory)) {
            $this->log('error', "Diretório base não configurado");
            return false;
        }
        
        // Criar diretório base se não existir
        if (!file_exists($base_directory)) {
            $result = wp_mkdir_p($base_directory);
            if (!$result) {
                $this->log('error', "Não foi possível criar o diretório base", "Caminho: $base_directory");
                
                // Verificar permissão do diretório pai
                $parent_dir = dirname($base_directory);
                if (!is_writable($parent_dir)) {
                    $this->log('error', "Diretório pai não é gravável", "Caminho: $parent_dir");
                }
                
                return false;
            } else {
                $this->log('success', "Diretório base criado", "Caminho: $base_directory");
            }
        } else {
            $this->log('success', "Diretório base já existe", "Caminho: $base_directory");
            
            // Verificar se o diretório base é gravável
            if (!is_writable($base_directory)) {
                $this->log('error', "Diretório base existe mas não é gravável", "Caminho: $base_directory");
                return false;
            }
        }
        
        // Caminho para pasta do usuário
        $user_folder = trailingslashit($base_directory) . $folder_name;
        
        // Criar pasta do usuário
        if (!file_exists($user_folder)) {
            $result = wp_mkdir_p($user_folder);
            if (!$result) {
                $this->log('error', "Não foi possível criar a pasta do usuário", "Caminho: $user_folder");
                return false;
            } else {
                // Definir permissões apropriadas (0755 é recomendado para pastas)
                @chmod($user_folder, 0755);
                $this->log('success', "Pasta do usuário criada", "Caminho: $user_folder");
            }
        } else {
            $this->log('success', "Pasta do usuário já existe", "Caminho: $user_folder");
        }
        
        // Criar subpastas
        $folder_structure = get_option('juf_folder_structure', "imports\nimagens\ndocumentos");
        $subfolders = is_array($folder_structure) ? $folder_structure : explode("\n", $folder_structure);
        
        $created_subfolders = array();
        
        foreach ($subfolders as $subfolder) {
            $subfolder = trim($subfolder);
            if (empty($subfolder)) continue;
            
            $subfolder_path = trailingslashit($user_folder) . sanitize_file_name($subfolder);
            
            if (!file_exists($subfolder_path)) {
                $result = wp_mkdir_p($subfolder_path);
                if ($result) {
                    // Definir permissões apropriadas
                    @chmod($subfolder_path, 0755);
                    $created_subfolders[] = $subfolder;
                    $this->log('success', "Subpasta criada: $subfolder", "Caminho: $subfolder_path");
                } else {
                    $this->log('error', "Não foi possível criar subpasta: $subfolder", "Caminho: $subfolder_path");
                }
            } else {
                $this->log('success', "Subpasta já existe: $subfolder", "Caminho: $subfolder_path");
            }
        }
        
        // Criar um arquivo de teste para verificar permissões
        $test_file_path = trailingslashit($user_folder) . 'test-file-' . time() . '.txt';
        $test_file_content = "Este é um arquivo de teste para verificar permissões.\nCriado para o usuário: {$user->user_login} ({$user_id})\nData: " . current_time('mysql');
        
        $file_result = @file_put_contents($test_file_path, $test_file_content);
        if ($file_result !== false) {
            $this->log('success', "Arquivo de teste criado com sucesso", "Caminho: $test_file_path");
        } else {
            $this->log('error', "Não foi possível criar arquivo de teste")}