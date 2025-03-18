<?php
/**
 * Plugin Name: Simple Client Folders
 * Description: Sistema simples para acesso de clientes às suas próprias pastas FTP
 * Version: 1.0
 * Author: DevSpacek
 */

// Prevenir acesso direto
if (!defined('ABSPATH')) {
    exit;
}

class Simple_Client_Folders {
    
    public function __construct() {
        // Registrar shortcode
        add_shortcode('client_folder', array($this, 'render_client_folder'));
        
        // Endpoints AJAX
        add_action('wp_ajax_client_list_files', array($this, 'ajax_list_files'));
        add_action('wp_ajax_client_download_file', array($this, 'ajax_download_file'));
        
        // Configurações de admin
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_init', array($this, 'register_settings'));
        
        // Scripts e estilos
        add_action('wp_enqueue_scripts', array($this, 'enqueue_scripts'));
    }
    
    /**
     * Registrar configurações
     */
    public function register_settings() {
        register_setting('simple_client_folders', 'scf_base_dir');
    }
    
    /**
     * Adicionar menu de administração
     */
    public function add_admin_menu() {
        add_options_page(
            'Client Folder Settings',
            'Client Folders',
            'manage_options',
            'client-folder-settings',
            array($this, 'render_settings_page')
        );
    }
    
    /**
     * Renderizar página de configurações
     */
    public function render_settings_page() {
        if (!current_user_can('manage_options')) {
            wp_die('Access denied');
        }
        
        // Obter diretório base configurado
        $base_dir = get_option('scf_base_dir', '');
        if (empty($base_dir)) {
            $upload_dir = wp_upload_dir();
            $base_dir = $upload_dir['basedir'] . '/ftp_uploads';
        }
        
        ?>
        <div class="wrap">
            <h1>Client Folder Settings</h1>
            
            <form method="post" action="options.php">
                <?php settings_fields('simple_client_folders'); ?>
                
                <table class="form-table">
                    <tr>
                        <th scope="row">Base Directory</th>
                        <td>
                            <input type="text" name="scf_base_dir" value="<?php echo esc_attr($base_dir); ?>" class="regular-text" />
                            <p class="description">Full path to the directory containing client folders</p>
                        </td>
                    </tr>
                </table>
                
                <?php submit_button(); ?>
            </form>
            
            <hr>
            
            <h2>How to Use</h2>
            <p>Add this shortcode to any page: <code>[client_folder]</code></p>
            <p>Each logged-in user will only see their own folder.</p>
        </div>
        <?php
    }
    
    /**
     * Carregar scripts e estilos
     */
    public function enqueue_scripts() {
        wp_enqueue_style(
            'simple-client-folders',
            plugin_dir_url(__FILE__) . 'style.css',
            array(),
            '1.0'
        );
        
        wp_enqueue_script(
            'simple-client-folders',
            plugin_dir_url(__FILE__) . 'script.js',
            array('jquery'),
            '1.0',
            true
        );
        
        wp_localize_script(
            'simple-client-folders',
            'clientFolders',
            array(
                'ajaxUrl' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('client_folder_nonce')
            )
        );
    }
    
    /**
     * Obter pasta do cliente atual
     */
    private function get_client_folder() {
        if (!is_user_logged_in()) {
            return false;
        }
        
        $user = wp_get_current_user();
        $folder_name = sanitize_file_name($user->user_login);
        
        $base_dir = get_option('scf_base_dir', '');
        if (empty($base_dir)) {
            $upload_dir = wp_upload_dir();
            $base_dir = $upload_dir['basedir'] . '/ftp_uploads';
        }
        
        $client_folder = trailingslashit($base_dir) . $folder_name;
        
        if (file_exists($client_folder) && is_dir($client_folder)) {
            return $client_folder;
        }
        
        return false;
    }
    
    /**
     * Verificar se um caminho está dentro da pasta do cliente
     */
    private function is_valid_path($path) {
        $client_folder = $this->get_client_folder();
        
        if (!$client_folder || empty($path)) {
            return false;
        }
        
        $real_client_folder = realpath($client_folder);
        $real_path = realpath($path);
        
        if ($real_path && strpos($real_path, $real_client_folder) === 0) {
            return true;
        }
        
        return false;
    }
    
    /**
     * Renderizar shortcode
     */
    public function render_client_folder($atts) {
        // Parse atributos
        $atts = shortcode_atts(array(
            'title' => 'Meus Arquivos'
        ), $atts, 'client_folder');
        
        // Verificar login
        if (!is_user_logged_in()) {
            return '<div class="client-folder-error">Você precisa estar logado para acessar seus arquivos.</div>';
        }
        
        // Verificar pasta do cliente
        $client_folder = $this->get_client_folder();
        if (!$client_folder) {
            return '<div class="client-folder-error">Você não possui uma pasta de arquivos configurada.</div>';
        }
        
        // Iniciar buffer de saída
        ob_start();
        
        ?>
        <div class="client-folder-container">
            <h2><?php echo esc_html($atts['title']); ?></h2>
            
            <div class="client-folder-navigation">
                <div class="client-folder-path">
                    <span class="path-home" data-path="">Pasta Principal</span>
                    <span class="current-path"></span>
                </div>
            </div>
            
            <div class="client-folder-content">
                <div class="loading">Carregando arquivos...</div>
                
                <table class="files-table" style="display: none;">
                    <thead>
                        <tr>
                            <th>Nome</th>
                            <th>Tamanho</th>
                            <th>Data</th>
                            <th>Ações</th>
                        </tr>
                    </thead>
                    <tbody>
                    </tbody>
                </table>
                
                <div class="empty-folder" style="display: none;">
                    Esta pasta está vazia.
                </div>
            </div>
            
            <div class="client-folder-messages"></div>
        </div>
        <?php
        
        return ob_get_clean();
    }
    
    /**
     * AJAX: Listar arquivos
     */
    public function ajax_list_files() {
        // Verificar nonce
        check_ajax_referer('client_folder_nonce', 'nonce');
        
        // Verificar login
        if (!is_user_logged_in()) {
            wp_send_json_error('Você precisa estar logado');
        }
        
        // Obter cliente e subpasta
        $client_folder = $this->get_client_folder();
        $subfolder = isset($_POST['path']) ? sanitize_text_field($_POST['path']) : '';
        
        if (!$client_folder) {
            wp_send_json_error('Pasta não encontrada');
        }
        
        // Montar caminho
        $path = $client_folder;
        if (!empty($subfolder)) {
            $path = trailingslashit($client_folder) . $subfolder;
        }
        
        // Verificar segurança
        if (!$this->is_valid_path($path)) {
            wp_send_json_error('Acesso inválido');
        }
        
        // Listar arquivos
        $items = array();
        
        if (is_dir($path) && $handle = opendir($path)) {
            while (false !== ($entry = readdir($handle))) {
                if ($entry == '.' || $entry == '..') {
                    continue;
                }
                
                $full_path = trailingslashit($path) . $entry;
                $is_dir = is_dir($full_path);
                
                $relative_path = !empty($subfolder) 
                    ? trailingslashit($subfolder) . $entry 
                    : $entry;
                
                $items[] = array(
                    'name' => $entry,
                    'is_dir' => $is_dir,
                    'path' => $relative_path,
                    'size' => $is_dir ? '-' : size_format(filesize($full_path)),
                    'date' => date('Y-m-d H:i:s', filemtime($full_path))
                );
            }
            closedir($handle);
        }
        
        // Ordenar: pastas primeiro, depois arquivos
        usort($items, function($a, $b) {
            if ($a['is_dir'] && !$b['is_dir']) return -1;
            if (!$a['is_dir'] && $b['is_dir']) return 1;
            return strcasecmp($a['name'], $b['name']);
        });
        
        wp_send_json_success(array(
            'items' => $items,
            'current_path' => $subfolder
        ));
    }
    
    /**
     * AJAX: Download de arquivo
     */
    public function ajax_download_file() {
        // Verificar nonce
        check_ajax_referer('client_folder_nonce', 'nonce', false);
        
        // Verificar login
        if (!is_user_logged_in()) {
            wp_die('Você precisa estar logado');
        }
        
        // Verificar parâmetro de arquivo
        if (empty($_GET['file'])) {
            wp_die('Arquivo não especificado');
        }
        
        // Obter cliente e arquivo
        $client_folder = $this->get_client_folder();
        $file_path = sanitize_text_field($_GET['file']);
        
        if (!$client_folder) {
            wp_die('Pasta não encontrada');
        }
        
        // Caminho completo
        $full_path = trailingslashit($client_folder) . $file_path;
        
        // Verificar segurança
        if (!$this->is_valid_path($full_path) || !is_file($full_path)) {
            wp_die('Acesso inválido');
        }
        
        // Preparar download
        $filename = basename($full_path);
        $filesize = filesize($full_path);
        
        // Limpar buffer
        ob_end_clean();
        
        // Headers para download
        header('Content-Description: File Transfer');
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Transfer-Encoding: binary');
        header('Expires: 0');
        header('Cache-Control: must-revalidate');
        header('Pragma: public');
        header('Content-Length: ' . $filesize);
        
        // Enviar arquivo e terminar
        readfile($full_path);
        exit;
    }
}

// Inicializar plugin
$simple_client_folders = new Simple_Client_Folders();

// Criar arquivos de estilo e script na ativação
register_activation_hook(__FILE__, 'client_folders_activation');

function client_folders_activation() {
    // Criar CSS
    $css = "
.client-folder-container {
    max-width: 100%;
    margin: 20px 0;
    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
}

.client-folder-navigation {
    margin-bottom: 15px;
    padding-bottom: 10px;
    border-bottom: 1px solid #eee;
}

.client-folder-path {
    font-size: 14px;
}

.path-home {
    cursor: pointer;
    color: #2271b1;
}

.files-table {
    width: 100%;
    border-collapse: collapse;
    border: 1px solid #ddd;
}

.files-table th, .files-table td {
    padding: 10px;
    text-align: left;
    border-bottom: 1px solid #eee;
}

.files-table th {
    background: #f8f8f8;
}

.folder-item {
    cursor: pointer;
    color: #2271b1;
}

.folder-icon:before {
    content: '📁';
    margin-right: 5px;
}

.file-icon:before {
    content: '📄';
    margin-right: 5px;
}

.download-btn {
    background: #2271b1;
    color: white;
    border: none;
    border-radius: 3px;
    padding: 5px 10px;
    cursor: pointer;
    font-size: 13px;
}

.loading {
    padding: 20px;
    text-align: center;
    color: #666;
}

.empty-folder {
    padding: 20px;
    text-align: center;
    color: #666;
    font-style: italic;
}

.client-folder-error {
    padding: 10px 15px;
    margin: 10px 0;
    background: #f8d7da;
    color: #721c24;
    border: 1px solid #f5c6cb;
    border-radius: 3px;
}
";
    file_put_contents(plugin_dir_path(__FILE__) . 'style.css', $css);
    
    // Criar JavaScript
    $js = "
jQuery(document).ready(function($) {
    var currentPath = '';
    
    // Carregar arquivos iniciais
    loadFiles(currentPath);
    
    // Clicar em pasta
    $(document).on('click', '.folder-item', function(e) {
        e.preventDefault();
        var path = $(this).data('path');
        loadFiles(path);
    });
    
    // Voltar para pasta principal
    $(document).on('click', '.path-home', function(e) {
        e.preventDefault();
        loadFiles('');
    });
    
    // Carregar arquivos via AJAX
    function loadFiles(path) {
        var container = $('.client-folder-container');
        container.find('.loading').show();
        container.find('.files-table').hide();
        container.find('.empty-folder').hide();
        
        $.ajax({
            url: clientFolders.ajaxUrl,
            type: 'POST',
            data: {
                action: 'client_list_files',
                nonce: clientFolders.nonce,
                path: path
            },
            success: function(response) {
                container.find('.loading').hide();
                
                if (response.success) {
                    var data = response.data;
                    currentPath = data.current_path;
                    updatePath(currentPath);
                    
                    if (data.items.length > 0) {
                        var tbody = container.find('.files-table tbody');
                        tbody.empty();
                        
                        $.each(data.items, function(i, item) {
                            var row = '';
                            
                            if (item.is_dir) {
                                row = '<tr>' +
                                    '<td><span class=\"folder-item\" data-path=\"' + item.path + '\">' +
                                    '<span class=\"folder-icon\"></span>' + item.name + '</span></td>' +
                                    '<td>' + item.size + '</td>' +
                                    '<td>' + item.date + '</td>' +
                                    '<td>-</td>' +
                                    '</tr>';
                            } else {
                                row = '<tr>' +
                                    '<td><span class=\"file-icon\"></span>' + item.name + '</td>' +
                                    '<td>' + item.size + '</td>' +
                                    '<td>' + item.date + '</td>' +
                                    '<td><a href=\"' + clientFolders.ajaxUrl + 
                                    '?action=client_download_file&nonce=' + clientFolders.nonce + 
                                    '&file=' + encodeURIComponent(item.path) + '\" class=\"download-btn\">Baixar</a></td>' +
                                    '</tr>';
                            }
                            
                            tbody.append(row);
                        });
                        
                        container.find('.files-table').show();
                    } else {
                        container.find('.empty-folder').show();
                    }
                } else {
                    showError(response.data || 'Erro ao carregar arquivos');
                }
            },
            error: function() {
                container.find('.loading').hide();
                showError('Erro de conexão');
            }
        });
    }
    
    // Atualizar caminho atual
    function updatePath(path) {
        var pathElement = $('.current-path');
        pathElement.empty();
        
        if (path) {
            var parts = path.split('/');
            pathElement.append(' / ' + parts.join(' / '));
        }
    }
    
    // Mostrar erro
    function showError(message) {
        var messageElement = $('<div class=\"client-folder-error\">' + message + '</div>');
        $('.client-folder-messages').html(messageElement);
    }
});
";
    file_put_contents(plugin_dir_path(__FILE__) . 'script.js', $js);
}