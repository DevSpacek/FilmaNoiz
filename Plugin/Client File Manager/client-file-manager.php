<?php
/**
 * Plugin Name: Client File Manager
 * Description: Sistema de gerenciamento de arquivos com acesso restrito por cliente
 * Version: 1.0
 * Author: DevSpacek
 */

// Prevenir acesso direto
if (!defined('ABSPATH')) {
    exit;
}

class Client_File_Manager {
    
    private $plugin_path;
    private $plugin_url;
    
    public function __construct() {
        $this->plugin_path = plugin_dir_path(__FILE__);
        $this->plugin_url = plugin_dir_url(__FILE__);
        
        // Registrar shortcodes
        add_shortcode('client_files', array($this, 'client_files_shortcode'));
        add_shortcode('client_uploader', array($this, 'client_uploader_shortcode'));
        
        // Adicionar scripts e estilos
        add_action('wp_enqueue_scripts', array($this, 'enqueue_assets'));
        
        // Registrar endpoints AJAX
        add_action('wp_ajax_client_list_files', array($this, 'ajax_list_files'));
        add_action('wp_ajax_client_upload_file', array($this, 'ajax_upload_file'));
        add_action('wp_ajax_client_delete_file', array($this, 'ajax_delete_file'));
        add_action('wp_ajax_client_create_folder', array($this, 'ajax_create_folder'));
        
        // Adicionar página de configurações
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_init', array($this, 'register_settings'));
    }
    
    /**
     * Registrar configurações
     */
    public function register_settings() {
        register_setting('client_file_manager', 'cfm_base_directory');
        register_setting('client_file_manager', 'cfm_allowed_filetypes');
        register_setting('client_file_manager', 'cfm_max_filesize');
        register_setting('client_file_manager', 'cfm_user_meta_key', array('default' => 'user_folder_path'));
    }
    
    /**
     * Adicionar menu de administração
     */
    public function add_admin_menu() {
        add_options_page(
            'Client File Manager',
            'Client File Manager',
            'manage_options',
            'client-file-manager',
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
        $base_directory = get_option('cfm_base_directory', '');
        if (empty($base_directory)) {
            // Tentar usar o mesmo diretório do JetEngine User Folders se estiver instalado
            $juf_dir = get_option('juf_base_directory', '');
            if (!empty($juf_dir)) {
                $base_directory = $juf_dir;
            } else {
                $uploads_dir = wp_upload_dir();
                $base_directory = $uploads_dir['basedir'] . '/user_folders';
            }
        }
        
        $allowed_filetypes = get_option('cfm_allowed_filetypes', 'jpg,jpeg,png,gif,pdf,doc,docx,xls,xlsx,zip,csv');
        $max_filesize = get_option('cfm_max_filesize', 5);
        $user_meta_key = get_option('cfm_user_meta_key', 'user_folder_path');
        
        ?>
        <div class="wrap">
            <h1>Client File Manager</h1>
            
            <form method="post" action="options.php">
                <?php settings_fields('client_file_manager'); ?>
                
                <table class="form-table">
                    <tr>
                        <th scope="row">Diretório Base</th>
                        <td>
                            <input type="text" name="cfm_base_directory" value="<?php echo esc_attr($base_directory); ?>" class="regular-text" />
                            <p class="description">Caminho absoluto para o diretório base das pastas de usuários</p>
                            <?php 
                            if (!empty($base_directory) && !file_exists($base_directory)) {
                                echo '<p style="color: orange;">⚠️ Este diretório não existe. Certifique-se de que coincida com o diretório configurado para as pastas de usuário.</p>';
                            }
                            ?>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row">Tipos de Arquivo Permitidos</th>
                        <td>
                            <input type="text" name="cfm_allowed_filetypes" value="<?php echo esc_attr($allowed_filetypes); ?>" class="regular-text" />
                            <p class="description">Lista de extensões de arquivo permitidas, separadas por vírgula</p>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row">Tamanho Máximo (MB)</th>
                        <td>
                            <input type="number" name="cfm_max_filesize" value="<?php echo esc_attr($max_filesize); ?>" class="small-text" min="1" max="100" />
                            <p class="description">Tamanho máximo de arquivo em megabytes</p>
                            <p class="description">Nota: O PHP pode ter limites menores configurados em php.ini (upload_max_filesize, post_max_size)</p>
                            <?php 
                            $php_limit = min(
                                $this->return_bytes(ini_get('upload_max_filesize')), 
                                $this->return_bytes(ini_get('post_max_size'))
                            ) / (1024 * 1024);
                            
                            echo '<p>Limite atual do PHP: ' . round($php_limit, 2) . ' MB</p>';
                            if ($max_filesize > $php_limit) {
                                echo '<p style="color: orange;">⚠️ O limite configurado é maior que o permitido pelo PHP. O limite efetivo será ' . round($php_limit, 2) . ' MB.</p>';
                            }
                            ?>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row">Meta Key do Usuário</th>
                        <td>
                            <input type="text" name="cfm_user_meta_key" value="<?php echo esc_attr($user_meta_key); ?>" class="regular-text" />
                            <p class="description">Chave de meta que armazena o nome da pasta do usuário (user_folder_path por padrão do JetEngine User Folders)</p>
                        </td>
                    </tr>
                </table>
                
                <?php submit_button('Salvar Configurações'); ?>
            </form>
            
            <hr>
            
            <h2>Como Usar</h2>
            
            <p>Use os shortcodes abaixo para mostrar o gerenciador de arquivos para clientes:</p>
            
            <table class="widefat" style="max-width: 500px">
                <tr>
                    <th>Funcionalidade</th>
                    <th>Shortcode</th>
                </tr>
                <tr>
                    <td>Gerenciador de arquivos completo</td>
                    <td><code>[client_files]</code></td>
                </tr>
                <tr>
                    <td>Apenas upload de arquivos</td>
                    <td><code>[client_uploader]</code></td>
                </tr>
                <tr>
                    <td>Listar arquivos específicos</td>
                    <td><code>[client_files folder="imports"]</code></td>
                </tr>
            </table>
            
            <p><strong>Dica:</strong> Coloque estes shortcodes em uma página protegida, acessível apenas para usuários logados.</p>
        </div>
        <?php
    }
    
    /**
     * Converter strings como "2M" em bytes
     */
    private function return_bytes($size_str) {
        switch (substr($size_str, -1)) {
            case 'M': case 'm': return (int)$size_str * 1048576;
            case 'K': case 'k': return (int)$size_str * 1024;
            case 'G': case 'g': return (int)$size_str * 1073741824;
            default: return $size_str;
        }
    }
    
    /**
     * Adicionar scripts e estilos
     */
    public function enqueue_assets() {
        wp_enqueue_style(
            'client-file-manager',
            $this->plugin_url . 'assets/css/client-file-manager.css',
            array(),
            '1.0'
        );
        
        wp_enqueue_script(
            'client-file-manager',
            $this->plugin_url . 'assets/js/client-file-manager.js',
            array('jquery'),
            '1.0',
            true
        );
        
        wp_localize_script('client-file-manager', 'cfmSettings', array(
            'ajaxurl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('client_file_manager'),
            'i18n' => array(
                'confirmDelete' => 'Tem certeza que deseja excluir este arquivo?',
                'uploadSuccess' => 'Arquivo enviado com sucesso!',
                'uploadError' => 'Erro ao enviar arquivo:',
                'createFolderPrompt' => 'Nome da nova pasta:',
                'loading' => 'Carregando...'
            )
        ));
    }
    
    /**
     * Shortcode para exibir gerenciador de arquivos do cliente
     */
    public function client_files_shortcode($atts) {
        // Se o usuário não está logado, mostrar mensagem de login
        if (!is_user_logged_in()) {
            return '<div class="cfm-error">Você precisa estar logado para acessar seus arquivos.</div>';
        }
        
        $atts = shortcode_atts(array(
            'folder' => '', // Pasta específica para mostrar
            'allow_upload' => 'true', // Permitir upload
            'allow_delete' => 'true', // Permitir exclusão
            'allow_create_folder' => 'true' // Permitir criar pastas
        ), $atts, 'client_files');
        
        // Verificar se o cliente tem uma pasta configurada
        $user_id = get_current_user_id();
        $user_folder = $this->get_user_folder_path($user_id);
        
        if (!$user_folder) {
            return '<div class="cfm-error">Você não possui uma pasta de arquivos configurada. Entre em contato com o administrador.</div>';
        }
        
        // Iniciar buffer de saída
        ob_start();
        
        ?>
        <div class="client-file-manager" 
             data-folder="<?php echo esc_attr($atts['folder']); ?>"
             data-allow-upload="<?php echo esc_attr($atts['allow_upload']); ?>"
             data-allow-delete="<?php echo esc_attr($atts['allow_delete']); ?>"
             data-allow-create-folder="<?php echo esc_attr($atts['allow_create_folder']); ?>">
            
            <div class="cfm-header">
                <div class="cfm-breadcrumbs">
                    <span class="cfm-breadcrumb-home" data-path="">Início</span>
                    <span class="cfm-current-path"></span>
                </div>
                
                <div class="cfm-actions">
                    <?php if ($atts['allow_upload'] === 'true'): ?>
                    <button class="cfm-upload-btn">Enviar Arquivo</button>
                    <?php endif; ?>
                    
                    <?php if ($atts['allow_create_folder'] === 'true'): ?>
                    <button class="cfm-create-folder-btn">Nova Pasta</button>
                    <?php endif; ?>
                </div>
            </div>
            
            <div class="cfm-upload-zone" style="display: none;">
                <form class="cfm-upload-form">
                    <input type="file" name="cfm_file" class="cfm-file-input">
                    <button type="submit" class="cfm-submit-upload">Enviar</button>
                    <button type="button" class="cfm-cancel-upload">Cancelar</button>
                </form>
                <div class="cfm-upload-progress">
                    <div class="cfm-progress-bar"></div>
                </div>
            </div>
            
            <div class="cfm-file-list">
                <div class="cfm-loader">Carregando seus arquivos...</div>
                <table class="cfm-files-table">
                    <thead>
                        <tr>
                            <th>Nome</th>
                            <th>Tamanho</th>
                            <th>Data</th>
                            <th>Ações</th>
                        </tr>
                    </thead>
                    <tbody>
                        <!-- Os arquivos serão carregados via AJAX -->
                    </tbody>
                </table>
            </div>
            
            <div class="cfm-messages"></div>
        </div>
        <?php
        
        return ob_get_clean();
    }
    
    /**
     * Shortcode para exibir apenas o uploader
     */
    public function client_uploader_shortcode($atts) {
        // Se o usuário não está logado, mostrar mensagem de login
        if (!is_user_logged_in()) {
            return '<div class="cfm-error">Você precisa estar logado para enviar arquivos.</div>';
        }
        
        $atts = shortcode_atts(array(
            'folder' => '', // Subpasta específica para upload
            'allowed_types' => '', // Sobrescrever tipos permitidos
            'redirect' => '' // URL para redirecionar após upload
        ), $atts, 'client_uploader');
        
        // Verificar se o cliente tem uma pasta configurada
        $user_id = get_current_user_id();
        $user_folder = $this->get_user_folder_path($user_id);
        
        if (!$user_folder) {
            return '<div class="cfm-error">Você não possui uma pasta de arquivos configurada. Entre em contato com o administrador.</div>';
        }
        
        // Obter configurações
        $allowed_filetypes = $atts['allowed_types'] ?: get_option('cfm_allowed_filetypes', 'jpg,jpeg,png,gif,pdf,doc,docx');
        $allowed_types_array = explode(',', $allowed_filetypes);
        $accept_attr = '.' . implode(',.', $allowed_types_array);
        
        // Iniciar buffer de saída
        ob_start();
        
        ?>
        <div class="client-uploader" data-folder="<?php echo esc_attr($atts['folder']); ?>" data-redirect="<?php echo esc_attr($atts['redirect']); ?>">
            <form class="cfm-simple-upload-form">
                <div class="cfm-upload-area">
                    <label for="cfm_simple_file">Selecione um arquivo para enviar:</label>
                    <input type="file" id="cfm_simple_file" name="cfm_file" accept="<?php echo esc_attr($accept_attr); ?>">
                    <p class="cfm-file-types">Tipos permitidos: <?php echo esc_html($allowed_filetypes); ?></p>
                </div>
                
                <div class="cfm-upload-actions">
                    <button type="submit" class="cfm-submit-btn">Enviar arquivo</button>
                </div>
                
                <div class="cfm-upload-progress" style="display: none;">
                    <div class="cfm-progress-outer">
                        <div class="cfm-progress-inner"></div>
                    </div>
                    <div class="cfm-progress-text">0%</div>
                </div>
                
                <div class="cfm-upload-message"></div>
            </form>
        </div>
        <?php
        
        return ob_get_clean();
    }
    
    /**
     * Obter caminho da pasta do usuário
     */
    public function get_user_folder_path($user_id) {
        // Obter o nome da pasta do usuário das user meta
        $meta_key = get_option('cfm_user_meta_key', 'user_folder_path');
        $folder_name = get_user_meta($user_id, $meta_key, true);
        
        if (empty($folder_name)) {
            // Fallback: usar nome de usuário
            $user = get_userdata($user_id);
            if ($user) {
                $folder_name = sanitize_file_name($user->user_login);
            } else {
                return false;
            }
        }
        
        // Obter diretório base
        $base_directory = get_option('cfm_base_directory', '');
        
        if (empty($base_directory) || !file_exists($base_directory)) {
            return false;
        }
        
        $user_folder = trailingslashit($base_directory) . $folder_name;
        
        return file_exists($user_folder) ? $user_folder : false;
    }
    
    /**
     * Verificar se o caminho está dentro da pasta do usuário
     */
    private function verify_user_path($path, $user_id) {
        $user_folder = $this->get_user_folder_path($user_id);
        
        if (!$user_folder) {
            return false;
        }
        
        // Normalizar caminhos
        $real_user_folder = realpath($user_folder);
        $real_path = realpath($path);
        
        // Verificar se o caminho existe e está dentro da pasta do usuário
        if ($real_path && strpos($real_path, $real_user_folder) === 0) {
            return true;
        }
        
        return false;
    }
    
    /**
     * AJAX: Listar arquivos
     */
    public function ajax_list_files() {
        // Verificar nonce
        if (!check_ajax_referer('client_file_manager', 'nonce', false)) {
            wp_send_json_error(array('message' => 'Erro de segurança.'));
        }
        
        // Verificar login
        if (!is_user_logged_in()) {
            wp_send_json_error(array('message' => 'Usuário não está logado.'));
        }
        
        $user_id = get_current_user_id();
        $user_folder = $this->get_user_folder_path($user_id);
        
        if (!$user_folder) {
            wp_send_json_error(array('message' => 'Pasta do usuário não encontrada.'));
        }
        
        // Obter subpasta solicitada
        $subfolder = isset($_POST['folder']) ? sanitize_text_field($_POST['folder']) : '';
        
        // Montar caminho completo
        $path = $user_folder;
        if (!empty($subfolder)) {
            $path = trailingslashit($path) . $subfolder;
        }
        
        // Verificar se o caminho está dentro da pasta do usuário
        if (!$this->verify_user_path($path, $user_id)) {
            wp_send_json_error(array('message' => 'Acesso negado a este diretório.'));
        }
        
        // Listar arquivos e pastas
        $files = array();
        $directories = array();
        
        if (is_dir($path) && $handle = opendir($path)) {
            while (false !== ($entry = readdir($handle))) {
                if ($entry != "." && $entry != "..") {
                    $full_path = trailingslashit($path) . $entry;
                    $relative_path = !empty($subfolder) ? trailingslashit($subfolder) . $entry : $entry;
                    
                    if (is_dir($full_path)) {
                        $directories[] = array(
                            'name' => $entry,
                            'path' => $relative_path,
                            'type' => 'dir',
                            'size' => '-',
                            'modified' => date('Y-m-d H:i:s', filemtime($full_path))
                        );
                    } else {
                        $files[] = array(
                            'name' => $entry,
                            'path' => $relative_path,
                            'type' => 'file',
                            'size' => size_format(filesize($full_path)),
                            'modified' => date('Y-m-d H:i:s', filemtime($full_path)),
                            'url' => $this->get_file_url($user_id, $relative_path)
                        );
                    }
                }
            }
            closedir($handle);
        }
        
        // Ordenar diretórios e arquivos (diretórios primeiro)
        usort($directories, function($a, $b) {
            return strcmp($a['name'], $b['name']);
        });
        
        usort($files, function($a, $b) {
            return strcmp($a['name'], $b['name']);
        });
        
        $result = array_merge($directories, $files);
        
        wp_send_json_success(array(
            'files' => $result,
            'current_folder' => $subfolder
        ));
    }
    
    /**
     * AJAX: Upload de arquivos
     */
    public function ajax_upload_file() {
        // Verificar nonce
        if (!check_ajax_referer('client_file_manager', 'nonce', false)) {
            wp_send_json_error(array('message' => 'Erro de segurança.'));
        }
        
        // Verificar login
        if (!is_user_logged_in()) {
            wp_send_json_error(array('message' => 'Usuário não está logado.'));
        }
        
        // Verificar arquivo
        if (empty($_FILES['cfm_file'])) {
            wp_send_json_error(array('message' => 'Nenhum arquivo enviado.'));
        }
        
        $user_id = get_current_user_id();
        $user_folder = $this->get_user_folder_path($user_id);
        
        if (!$user_folder) {
            wp_send_json_error(array('message' => 'Pasta do usuário não encontrada.'));
        }
        
        // Obter subpasta para upload
        $subfolder = isset($_POST['folder']) ? sanitize_text_field($_POST['folder']) : '';
        
        // Montar caminho completo
        $upload_path = $user_folder;
        if (!empty($subfolder)) {
            $upload_path = trailingslashit($upload_path) . $subfolder;
        }
        
        // Verificar se o caminho está dentro da pasta do usuário
        if (!$this->verify_user_path($upload_path, $user_id)) {
            wp_send_json_error(array('message' => 'Acesso negado a este diretório.'));
        }
        
        // Verificar se o diretório de upload existe
        if (!file_exists($upload_path)) {
            wp_mkdir_p($upload_path);
        }
        
        // Verificar tipos de arquivo permitidos
        $file = $_FILES['cfm_file'];
        $filename = sanitize_file_name($file['name']);
        $allowed_filetypes = get_option('cfm_allowed_filetypes', 'jpg,jpeg,png,gif,pdf,doc,docx');
        $allowed_types_array = explode(',', $allowed_filetypes);
        
        $file_extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        if (!in_array($file_extension, $allowed_types_array)) {
            wp_send_json_error(array('message' => 'Tipo de arquivo não permitido. Tipos permitidos: ' . $allowed_filetypes));
        }
        
        // Verificar tamanho máximo
        $max_size_mb = get_option('cfm_max_filesize', 5);
        $max_size_bytes = $max_size_mb * 1024 * 1024;
        
        if ($file['size'] > $max_size_bytes) {
            wp_send_json_error(array('message' => 'Arquivo muito grande. Tamanho máximo: ' . $max_size_mb . 'MB'));
        }
        
        // Criar caminho para o arquivo
        $upload_file_path = trailingslashit($upload_path) . $filename;
        
        // Se o arquivo já existe, adicionar um número ao nome
        if (file_exists($upload_file_path)) {
            $name = pathinfo($filename, PATHINFO_FILENAME);
            $ext = pathinfo($filename, PATHINFO_EXTENSION);
            $counter = 1;
            
            while (file_exists(trailingslashit($upload_path) . $name . '_' . $counter . '.' . $ext)) {
                $counter++;
            }
            
            $filename = $name . '_' . $counter . '.' . $ext;
            $upload_file_path = trailingslashit($upload_path) . $filename;
        }
        
        // Mover arquivo
        $result = move_uploaded_file($file['tmp_name'], $upload_file_path);
        
        if (!$result) {
            wp_send_json_error(array('message' => 'Erro ao fazer upload do arquivo.'));
        }
        
        // Gerar URL relativa do arquivo
        $relative_path = !empty($subfolder) ? trailingslashit($subfolder) . $filename : $filename;
        
        wp_send_json_success(array(
            'message' => 'Arquivo enviado com sucesso.',
            'filename' => $filename,
            'path' => $relative_path,
            'url' => $this->get_file_url($user_id, $relative_path),
            'size' => size_format(filesize($upload_file_path)),
            'modified' => date('Y-m-d H:i:s')
        ));
    }
    
    /**
     * AJAX: Excluir arquivo
     */
    public function ajax_delete_file() {
        // Verificar nonce
        if (!check_ajax_referer('client_file_manager', 'nonce', false)) {
            wp_send_json_error(array('message' => 'Erro de segurança.'));
        }
        
        // Verificar login
        if (!is_user_logged_in()) {
            wp_send_json_error(array('message' => 'Usuário não está logado.'));
        }
        
        if (empty($_POST['file'])) {
            wp_send_json_error(array('message' => 'Nenhum arquivo especificado.'));
        }
        
        $file_path = sanitize_text_field($_POST['file']);
        $user_id = get_current_user_id();
        $user_folder = $this->get_user_folder_path($user_id);
        
        if (!$user_folder) {
            wp_send_json_error(array('message' => 'Pasta do usuário não encontrada.'));
        }
        
        // Montar caminho completo
        $full_path = trailingslashit($user_folder) . $file_path;
        
        // Verificar se o caminho está dentro da pasta do usuário
        if (!$this->verify_user_path($full_path, $user_id)) {
            wp_send_json_error(array('message' => 'Acesso negado a este arquivo.'));
        }
        
        // Verificar se é um arquivo
        if (!is_file($full_path)) {
            wp_send_json_error(array('message' => 'O caminho especificado não é um arquivo.'));
        }
        
        // Excluir arquivo
        $result = unlink($full_path);
        
        if (!$result) {
            wp_send_json_error(array('message' => 'Erro ao excluir o arquivo.'));
        }
        
        wp_send_json_success(array('message' => 'Arquivo excluído com sucesso.'));
    }
    