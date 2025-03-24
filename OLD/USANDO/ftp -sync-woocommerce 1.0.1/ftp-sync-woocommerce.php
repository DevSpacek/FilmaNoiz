<?php
/**
 * Plugin Name: FTP Sync para WooCommerce
 * Description: Sincroniza arquivos de um servidor FTP para produtos WooCommerce
 * Version: 1.0.1
 * Author: DevSpacek
 * Text Domain: ftp-sync-woo
 * Requires at least: 5.6
 * Requires PHP: 7.2
 */

if (!defined('ABSPATH')) {
    exit; // Saída se acessado diretamente
}

// Definições básicas
define('FTP_SYNC_VERSION', '1.0.1');
define('FTP_SYNC_PATH', plugin_dir_path(__FILE__));
define('FTP_SYNC_URL', plugin_dir_url(__FILE__));

// Hooks de ativação/desativação
register_activation_hook(__FILE__, 'ftp_sync_plugin_activation');
register_deactivation_hook(__FILE__, 'ftp_sync_plugin_deactivation');

/**
 * Função de ativação
 */
function ftp_sync_plugin_activation() {
    // Criar diretórios necessários
    if (!file_exists(FTP_SYNC_PATH . 'includes')) {
        wp_mkdir_p(FTP_SYNC_PATH . 'includes');
    }
    
    if (!file_exists(FTP_SYNC_PATH . 'assets/css')) {
        wp_mkdir_p(FTP_SYNC_PATH . 'assets/css');
    }
    
    if (!file_exists(FTP_SYNC_PATH . 'assets/js')) {
        wp_mkdir_p(FTP_SYNC_PATH . 'assets/js');
    }
    
    // Criar os arquivos de includes manualmente
    ftp_sync_create_include_files();
    
    // Configurações padrão
    $defaults = array(
        'ftp_host' => '',
        'ftp_port' => '21',
        'ftp_username' => '',
        'ftp_password' => '',
        'ftp_path' => '/',
        'ftp_passive' => 'yes',
        'check_interval' => 'hourly',
        'product_price' => '10',
        'product_status' => 'publish',
        'debug_mode' => 'yes', // Enable debug by default to help diagnose issues
    );
    
    foreach ($defaults as $key => $value) {
        if (get_option('ftp_sync_' . $key) === false) {
            update_option('ftp_sync_' . $key, $value);
        }
    }
    
    // Gerar chave de segurança para API
    if (get_option('ftp_sync_security_key') === false) {
        update_option('ftp_sync_security_key', md5(uniqid('', true)));
    }
    
    // Configurar cron (adiar para garantir que não falhe na ativação)
    wp_schedule_single_event(time() + 60, 'ftp_sync_setup_schedules');
    
    // Criar log de ativação
    ftp_sync_log("Plugin ativado - Versão: " . FTP_SYNC_VERSION);
    
    // Atualizar regras de reescrita
    flush_rewrite_rules();
}

/**
 * Função de desativação
 */
function ftp_sync_plugin_deactivation() {
    // Remover tarefas agendadas
    wp_clear_scheduled_hook('ftp_sync_check_files');
    wp_clear_scheduled_hook('ftp_sync_setup_schedules');
    
    // Atualizar regras de reescrita
    flush_rewrite_rules();
    
    ftp_sync_log("Plugin desativado");
}

/**
 * Criar arquivos de includes necessários
 */
function ftp_sync_create_include_files() {
    // FTP Connector
    $ftp_connector = FTP_SYNC_PATH . 'includes/ftp-connector.php';
    if (!file_exists($ftp_connector)) {
        $content = '<?php
/**
 * FTP Connector - Gerencia conexões FTP
 */

if (!defined("ABSPATH")) { exit; }

/**
 * Conectar ao servidor FTP
 */
function ftp_sync_connect_ftp() {
    $host = get_option("ftp_sync_ftp_host", "");
    $port = intval(get_option("ftp_sync_ftp_port", 21));
    $username = get_option("ftp_sync_ftp_username", "");
    $password = get_option("ftp_sync_ftp_password", "");
    $passive = get_option("ftp_sync_ftp_passive", "yes") === "yes";
    
    if (empty($host) || empty($username) || empty($password)) {
        ftp_sync_log("ERRO: Configurações FTP incompletas");
        return false;
    }
    
    $conn = @ftp_connect($host, $port, 30);
    if (!$conn) {
        ftp_sync_log("ERRO: Falha ao conectar ao servidor FTP: {$host}:{$port}");
        return false;
    }
    
    $login = @ftp_login($conn, $username, $password);
    if (!$login) {
        ftp_sync_log("ERRO: Falha na autenticação FTP");
        @ftp_close($conn);
        return false;
    }
    
    if ($passive) {
        @ftp_pasv($conn, true);
    }
    
    ftp_sync_log("Conexão FTP estabelecida com sucesso");
    return $conn;
}

/**
 * Listar pastas de clientes
 */
function ftp_sync_list_client_folders($ftp_connection) {
    $base_path = get_option("ftp_sync_ftp_path", "/");
    
    if (!@ftp_chdir($ftp_connection, $base_path)) {
        ftp_sync_log("ERRO: Não foi possível acessar o diretório base: {$base_path}");
        return array();
    }
    
    $items = @ftp_nlist($ftp_connection, ".");
    
    if (!is_array($items)) {
        ftp_sync_log("ERRO: Falha ao listar conteúdo do diretório: {$base_path}");
        return array();
    }
    
    $folders = array();
    $current = @ftp_pwd($ftp_connection);
    
    foreach ($items as $item) {
        if ($item === "." || $item === "..") {
            continue;
        }
        
        if (@ftp_chdir($ftp_connection, $current . "/" . $item)) {
            $folders[] = $current . "/" . $item;
            @ftp_chdir($ftp_connection, $current);
        }
    }
    
    return $folders;
}
';
        file_put_contents($ftp_connector, $content);
    }
    
    // Product Creator
    $product_creator = FTP_SYNC_PATH . 'includes/product-creator.php';
    if (!file_exists($product_creator)) {
        $content = '<?php
/**
 * Product Creator - Cria produtos WooCommerce
 */

if (!defined("ABSPATH")) { exit; }

/**
 * Criar produto WooCommerce para um arquivo
 */
function ftp_sync_create_product_from_file($file_data, $client_name, $ftp_connection) {
    try {
        if (!class_exists("WC_Product")) {
            ftp_sync_log("ERRO: WooCommerce não está disponível");
            return false;
        }
        
        // Download do arquivo
        $upload_dir = wp_upload_dir();
        $target_dir = $upload_dir["basedir"] . "/ftp-sync-files";
        
        if (!file_exists($target_dir)) {
            wp_mkdir_p($target_dir);
        }
        
        $unique_name = uniqid($client_name . "-") . "-" . sanitize_file_name($file_data["name"]);
        $local_path = $target_dir . "/" . $unique_name;
        
        $download = @ftp_get($ftp_connection, $local_path, $file_data["name"], FTP_BINARY);
        
        if (!$download || !file_exists($local_path)) {
            ftp_sync_log("ERRO: Falha ao baixar arquivo: {$file_data["name"]}");
            return false;
        }
        
        // Criar produto
        $product = new WC_Product();
        
        // Título
        $title = pathinfo($file_data["name"], PATHINFO_FILENAME);
        $title = str_replace(["_", "-"], " ", $title);
        $title = ucwords($title) . " - " . $client_name;
        $product->set_name($title);
        
        // Descrição
        $file_ext = pathinfo($file_data["name"], PATHINFO_EXTENSION);
        $file_size = size_format($file_data["size"]);
        $description = "Arquivo: {$file_data["name"]}\n";
        $description .= "Tipo: " . strtoupper($file_ext) . "\n";
        $description .= "Tamanho: {$file_size}\n";
        $description .= "Cliente: {$client_name}\n";
        $description .= "Data de processamento: " . date("Y-m-d H:i:s");
        
        $product->set_description($description);
        $product->set_short_description("Arquivo {$file_ext} do cliente {$client_name}");
        
        // Configurações
        $product->set_status(get_option("ftp_sync_product_status", "publish"));
        $product->set_catalog_visibility("visible");
        $product->set_price(get_option("ftp_sync_product_price", "10"));
        $product->set_regular_price(get_option("ftp_sync_product_price", "10"));
        $product->set_virtual(true);
        $product->set_downloadable(true);
        
        // Configurar download
        $file_url = $upload_dir["baseurl"] . "/ftp-sync-files/" . $unique_name;
        $download_data = array(
            "id" => md5($file_data["path"]),
            "name" => $file_data["name"],
            "file" => $file_url
        );
        
        $product->set_downloads(array($download_data));
        $product->set_download_limit(-1);
        $product->set_download_expiry(-1);
        
        // Metadata
        $product->update_meta_data("_ftp_sync_client", $client_name);
        $product->update_meta_data("_ftp_sync_file_path", $file_data["path"]);
        $product->update_meta_data("_ftp_sync_file_size", $file_data["size"]);
        
        // Salvar
        $product_id = $product->save();
        
        if (!$product_id) {
            ftp_sync_log("ERRO: Falha ao salvar produto");
            return false;
        }
        
        ftp_sync_log("Produto criado com sucesso: #{$product_id} - {$title}");
        return $product_id;
        
    } catch (Exception $e) {
        ftp_sync_log("ERRO: " . $e->getMessage());
        return false;
    }
}
';
        file_put_contents($product_creator, $content);
    }
    
    // CSS padrão
    $css_file = FTP_SYNC_PATH . 'assets/css/admin.css';
    if (!file_exists($css_file)) {
        $content = '/* Estilos para FTP Sync */
.ftp-sync-container {
    margin: 20px 0;
}

.ftp-sync-panel {
    background: #fff;
    border: 1px solid #ccd0d4;
    box-shadow: 0 1px 1px rgba(0,0,0,.04);
    margin-bottom: 20px;
    padding: 15px;
}

.ftp-sync-panel h3 {
    margin-top: 0;
    border-bottom: 1px solid #eee;
    padding-bottom: 10px;
}

#ftp-test-result, 
#ftp-sync-result {
    padding: 10px;
    border-radius: 3px;
    margin-top: 10px;
    display: none;
}

.ftp-sync-success {
    background-color: #dff0d8;
    color: #3c763d;
    border: 1px solid #d6e9c6;
}

.ftp-sync-error {
    background-color: #f2dede;
    color: #a94442;
    border: 1px solid #ebccd1;
}
';
        file_put_contents($css_file, $content);
    }
    
    // JS padrão
    $js_file = FTP_SYNC_PATH . 'assets/js/admin.js';
    if (!file_exists($js_file)) {
        $content = '/* Scripts para FTP Sync */
jQuery(document).ready(function($) {
    // Teste de conexão FTP
    $("#ftp-test-connection").on("click", function() {
        var $button = $(this);
        var $result = $("#ftp-test-result");
        
        $button.prop("disabled", true).text("Testando...");
        $result.hide();
        
        $.ajax({
            url: ajaxurl,
            type: "POST",
            data: {
                action: "ftp_sync_test_connection",
                nonce: ftpSyncData.nonce
            },
            success: function(response) {
                $button.prop("disabled", false).text("Testar Conexão");
                if (response.success) {
                    $result.removeClass("ftp-sync-error")
                           .addClass("ftp-sync-success")
                           .html(response.data)
                           .show();
                } else {
                    $result.removeClass("ftp-sync-success")
                           .addClass("ftp-sync-error")
                           .html(response.data)
                           .show();
                }
            },
            error: function() {
                $button.prop("disabled", false).text("Testar Conexão");
                $result.removeClass("ftp-sync-success")
                       .addClass("ftp-sync-error")
                       .html("Erro de conexão com o servidor")
                       .show();
            }
        });
    });
    
    // Sincronização manual
    $("#ftp-sync-manual").on("click", function() {
        var $button = $(this);
        var $result = $("#ftp-sync-result");
        
        $button.prop("disabled", true).text("Sincronizando...");
        $result.hide();
        
        $.ajax({
            url: ajaxurl,
            type: "POST",
            data: {
                action: "ftp_sync_manual",
                nonce: ftpSyncData.nonce
            },
            success: function(response) {
                $button.prop("disabled", false).text("Sincronizar Agora");
                if (response.success) {
                    $result.removeClass("ftp-sync-error")
                           .addClass("ftp-sync-success")
                           .html(response.data)
                           .show();
                } else {
                    $result.removeClass("ftp-sync-success")
                           .addClass("ftp-sync-error")
                           .html(response.data)
                           .show();
                }
            },
            error: function() {
                $button.prop("disabled", false).text("Sincronizar Agora");
                $result.removeClass("ftp-sync-success")
                       .addClass("ftp-sync-error")
                       .html("Erro de conexão com o servidor")
                       .show();
            }
        });
    });
});
';
        file_put_contents($js_file, $content);
    }
}

/**
 * Registrar menu (separado de outras funções para garantir que sempre apareça)
 */
function ftp_sync_register_menu() {
    add_menu_page(
        'FTP Sync para WooCommerce',
        'FTP Sync',
        'manage_options',
        'ftp-sync-woocommerce',
        'ftp_sync_render_main_page',
        'dashicons-upload',
        55
    );
    
    add_submenu_page(
        'ftp-sync-woocommerce',
        'Configurações',
        'Configurações',
        'manage_options',
        'ftp-sync-woocommerce',
        'ftp_sync_render_main_page'
    );
    
    add_submenu_page(
        'ftp-sync-woocommerce',
        'Status',
        'Status',
        'manage_options',
        'ftp-sync-status',
        'ftp_sync_render_status_page'
    );
    
    add_submenu_page(
        'ftp-sync-woocommerce',
        'Logs',
        'Logs',
        'manage_options',
        'ftp-sync-logs',
        'ftp_sync_render_logs_page'
    );
    
    add_submenu_page(
        'ftp-sync-woocommerce',
        'Diagnóstico',
        'Diagnóstico',
        'manage_options',
        'ftp-sync-diagnostic',
        'ftp_sync_render_diagnostic_page'
    );
}
/**
 * Incluir arquivos (com verificação para evitar erros)
 */
function ftp_sync_load_includes() {
    $includes = array(
        'ftp-connector.php',
        'product-creator.php'
    );
    
    foreach ($includes as $file) {
        $filepath = FTP_SYNC_PATH . 'includes/' . $file;
        if (file_exists($filepath)) {
            require_once $filepath;
        } else {
            // Se o arquivo não existir, recriar os includes
            ftp_sync_create_include_files();
            break; // Sair depois de recriar
        }
    }
}

/**
 * Carregar integração com JetEngine
 */
function ftp_sync_load_jetengine() {
    // Verificar se JetEngine está ativo
    if (defined('JET_ENGINE_VERSION') || class_exists('Jet_Engine')) {
        $jet_integration = FTP_SYNC_PATH . 'includes/jet-integration.php';
        
        if (file_exists($jet_integration)) {
            require_once $jet_integration;
            ftp_sync_log('JetEngine integração carregada');
            return true;
        } else {
            ftp_sync_create_jetengine_integration();
            if (file_exists($jet_integration)) {
                require_once $jet_integration;
                ftp_sync_log('JetEngine integração criada e carregada');
                return true;
            }
        }
    }
    
    ftp_sync_log('JetEngine não detectado ou arquivos de integração ausentes');
    return false;
}

/**
 * Criar arquivo de integração com JetEngine
 */
function ftp_sync_create_jetengine_integration() {
    $file = FTP_SYNC_PATH . 'includes/jet-integration.php';
    // O conteúdo será o mesmo do arquivo jet-integration.php acima
    $content = '<?php
/**
 * Integração com JetEngine
 */

if (!defined("ABSPATH")) {
    exit;
}

/**
 * Verificar se o JetEngine está ativo
 */
function ftp_sync_jetengine_is_active() {
    return defined("JET_ENGINE_VERSION") || class_exists("Jet_Engine");
}

/**
 * Registrar campo FTP para usuários no JetEngine
 */
function ftp_sync_register_jetengine_field() {
    // Verificar se JetEngine está disponível
    if (!ftp_sync_jetengine_is_active() || !function_exists("jet_engine")) {
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
        
        jet_engine()->meta_boxes->add_field_to_box("user", array(
            "type"        => "text",
            "name"        => "ftp_folder_name",
            "title"       => "Pasta FTP do Cliente",
            "description" => "Nome da pasta no servidor FTP para este cliente",
            "is_required" => false,
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
    if (!isset($data["ftp_folder_name"]) || empty($data["ftp_folder_name"])) {
        ftp_sync_log("JETENGINE: Sem nome de pasta FTP definido para usuário #$user_id");
        return;
    }
    
    $folder_name = sanitize_title($data["ftp_folder_name"]);
    
    // Salvar no user meta
    update_user_meta($user_id, "ftp_folder_name", $folder_name);
    ftp_sync_log("JETENGINE: Pasta FTP \'$folder_name\' definida para usuário #$user_id");
    
    // Criar pasta no servidor FTP
    ftp_sync_create_client_folder($folder_name);
}

/**
 * Criar pasta no servidor FTP
 */
function ftp_sync_create_client_folder($folder_name) {
    ftp_sync_log("JETENGINE: Tentando criar pasta \'$folder_name\' no servidor FTP");
    
    // Carregar funções FTP
    require_once FTP_SYNC_PATH . "includes/ftp-connector.php";
    
    // Conectar ao FTP
    $conn = ftp_sync_connect_ftp();
    if (!$conn) {
        ftp_sync_log("JETENGINE ERRO: Não foi possível conectar ao FTP para criar pasta");
        return false;
    }
    
    // Criar pasta
    $base_path = get_option("ftp_sync_ftp_path", "/");
    $folder_path = rtrim($base_path, "/") . "/" . $folder_name;
    
    // Verificar se já existe
    $current_dir = @ftp_pwd($conn);
    $exists = @ftp_chdir($conn, $folder_path);
    
    if ($exists) {
        @ftp_chdir($conn, $current_dir);
        ftp_sync_log("JETENGINE: Pasta \'$folder_path\' já existe no servidor FTP");
        @ftp_close($conn);
        return true;
    }
    
    // Criar nova pasta
    $result = @ftp_mkdir($conn, $folder_path);
    
    if ($result) {
        ftp_sync_log("JETENGINE: Pasta \'$folder_path\' criada com sucesso no servidor FTP");
    } else {
        ftp_sync_log("JETENGINE ERRO: Falha ao criar pasta \'$folder_path\'");
    }
    
    @ftp_close($conn);
    return $result;
}

// Registrar hooks para JetEngine se estiver disponível
if (ftp_sync_jetengine_is_active()) {
    // Registrar campo
    add_action("init", "ftp_sync_register_jetengine_field", 99);
    
    // Hooks para usuários
    add_action("jet-engine/user/after-add", "ftp_sync_handle_jetengine_user", 10, 2);
    add_action("jet-engine/user/after-edit", "ftp_sync_handle_jetengine_user", 10, 2);
    
    ftp_sync_log("JETENGINE: Integração ativada");
} else {
    ftp_sync_log("JETENGINE: Não detectado");
}';

    file_put_contents($file, $content);
}

/**
 * Enfileirar scripts e estilos para o admin
 */
function ftp_sync_admin_scripts($hook) {
    if (strpos($hook, 'ftp-sync') === false) {
        return;
    }
    
    // CSS
    $css_file = FTP_SYNC_PATH . 'assets/css/admin.css';
    if (file_exists($css_file)) {
        wp_enqueue_style(
            'ftp-sync-admin-style', 
            FTP_SYNC_URL . 'assets/css/admin.css',
            array(),
            FTP_SYNC_VERSION
        );
    }
    
    // JS
    $js_file = FTP_SYNC_PATH . 'assets/js/admin.js';
    if (file_exists($js_file)) {
        wp_enqueue_script(
            'ftp-sync-admin-script', 
            FTP_SYNC_URL . 'assets/js/admin.js',
            array('jquery'),
            FTP_SYNC_VERSION,
            true
        );
        
        wp_localize_script('ftp-sync-admin-script', 'ftpSyncData', array(
            'nonce' => wp_create_nonce('ftp_sync_nonce')
        ));
    }
}

/**
 * Registrar configurações
 */
function ftp_sync_register_settings() {
    // Servidor FTP
    register_setting('ftp_sync_settings', 'ftp_sync_ftp_host');
    register_setting('ftp_sync_settings', 'ftp_sync_ftp_port');
    register_setting('ftp_sync_settings', 'ftp_sync_ftp_username');
    register_setting('ftp_sync_settings', 'ftp_sync_ftp_password');
    register_setting('ftp_sync_settings', 'ftp_sync_ftp_path');
    register_setting('ftp_sync_settings', 'ftp_sync_ftp_passive');
    
    // Produtos
    register_setting('ftp_sync_settings', 'ftp_sync_product_price');
    register_setting('ftp_sync_settings', 'ftp_sync_product_status');
    
    // Geral
    register_setting('ftp_sync_settings', 'ftp_sync_check_interval');
    register_setting('ftp_sync_settings', 'ftp_sync_debug_mode');
}

/**
 * Renderizar página principal
 */
function ftp_sync_render_main_page() {
    // Verificar permissões
    if (!current_user_can('manage_options')) {
        wp_die('Acesso negado');
    }
    
    // Obter opções
    $ftp_host = get_option('ftp_sync_ftp_host', '');
    $ftp_port = get_option('ftp_sync_ftp_port', '21');
    $ftp_username = get_option('ftp_sync_ftp_username', '');
    $ftp_password = get_option('ftp_sync_ftp_password', '');
    $ftp_path = get_option('ftp_sync_ftp_path', '/');
    $ftp_passive = get_option('ftp_sync_ftp_passive', 'yes');
    
    $product_price = get_option('ftp_sync_product_price', '10');
    $product_status = get_option('ftp_sync_product_status', 'publish');
    $check_interval = get_option('ftp_sync_check_interval', 'hourly');
    $debug_mode = get_option('ftp_sync_debug_mode', 'no');
    
    ?>
    <div class="wrap">
        <h1>FTP Sync para WooCommerce</h1>
        
        <h2 class="nav-tab-wrapper">
            <a href="?page=ftp-sync-woocommerce" class="nav-tab nav-tab-active">Configurações</a>
            <a href="?page=ftp-sync-status" class="nav-tab">Status</a>
            <a href="?page=ftp-sync-logs" class="nav-tab">Logs</a>
        </h2>
        
        <div class="ftp-sync-container">
            <form method="post" action="options.php">
                <?php settings_fields('ftp_sync_settings'); ?>
                
                <div class="ftp-sync-panel">
                    <h3>Configurações do Servidor FTP</h3>
                    <p>Configure os detalhes do servidor FTP para acessar os arquivos dos clientes.</p>
                    
                    <table class="form-table">
                        <tr>
                            <th scope="row"><label for="ftp_sync_ftp_host">Servidor FTP</label></th>
                            <td>
                                <input type="text" name="ftp_sync_ftp_host" id="ftp_sync_ftp_host" 
                                       value="<?php echo esc_attr($ftp_host); ?>" class="regular-text" />
                                <p class="description">Endereço do servidor FTP (ex: ftp.seusite.com)</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="ftp_sync_ftp_port">Porta</label></th>
                            <td>
                                <input type="text" name="ftp_sync_ftp_port" id="ftp_sync_ftp_port" 
                                       value="<?php echo esc_attr($ftp_port); ?>" class="small-text" />
                                <p class="description">Porta do servidor FTP (geralmente 21)</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="ftp_sync_ftp_username">Usuário</label></th>
                            <td>
                                <input type="text" name="ftp_sync_ftp_username" id="ftp_sync_ftp_username" 
                                       value="<?php echo esc_attr($ftp_username); ?>" class="regular-text" />
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="ftp_sync_ftp_password">Senha</label></th>
                            <td>
                                <input type="password" name="ftp_sync_ftp_password" id="ftp_sync_ftp_password" 
                                       value="<?php echo esc_attr($ftp_password); ?>" class="regular-text" />
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="ftp_sync_ftp_path">Diretório Base</label></th>
                            <td>
                                <input type="text" name="ftp_sync_ftp_path" id="ftp_sync_ftp_path" 
                                       value="<?php echo esc_attr($ftp_path); ?>" class="regular-text" />
                                <p class="description">Diretório no servidor FTP onde estão as pastas dos clientes</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="ftp_sync_ftp_passive">Modo Passivo</label></th>
                            <td>
                                <select name="ftp_sync_ftp_passive" id="ftp_sync_ftp_passive">
                                    <option value="yes" <?php selected($ftp_passive, 'yes'); ?>>Sim</option>
                                    <option value="no" <?php selected($ftp_passive, 'no'); ?>>Não</option>
                                </select>
                                <p class="description">Recomendado para evitar problemas de firewall</p>
                            </td>
                        </tr>
                    </table>
                    
                    <p>
                        <button type="button" id="ftp-test-connection" class="button button-secondary">Testar Conexão</button>
                        <div id="ftp-test-result"></div>
                    </p>
                </div>
                
                <div class="ftp-sync-panel">
                    <h3>Configurações de Produtos</h3>
                    <p>Configure como os arquivos serão convertidos em produtos WooCommerce.</p>
                    
                    <table class="form-table">
                        <tr>
                            <th scope="row"><label for="ftp_sync_product_price">Preço Padrão</label></th>
                            <td>
                                <input type="text" name="ftp_sync_product_price" id="ftp_sync_product_price" 
                                       value="<?php echo esc_attr($product_price); ?>" class="small-text" />
                                <p class="description">Preço padrão para os produtos criados</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="ftp_sync_product_status">Status do Produto</label></th>
                            <td>
                                <select name="ftp_sync_product_status" id="ftp_sync_product_status">
                                    <option value="publish" <?php selected($product_status, 'publish'); ?>>Publicado</option>
                                    <option value="draft" <?php selected($product_status, 'draft'); ?>>Rascunho</option>
                                    <option value="pending" <?php selected($product_status, 'pending'); ?>>Pendente</option>
                                </select>
                                <p class="description">Status dos produtos quando criados</p>
                            </td>
                        </tr>
                    </table>
                </div>
                
                <div class="ftp-sync-panel">
                    <h3>Configurações Gerais</h3>
                    
                    <table class="form-table">
                        <tr>
                            <th scope="row"><label for="ftp_sync_check_interval">Frequência de Verificação</label></th>
                            <td>
                                <select name="ftp_sync_check_interval" id="ftp_sync_check_interval">
                                    <option value="hourly" <?php selected($check_interval, 'hourly'); ?>>A cada hora</option>
                                    <option value="twicedaily" <?php selected($check_interval, 'twicedaily'); ?>>Duas vezes ao dia</option>
                                    <option value="daily" <?php selected($check_interval, 'daily'); ?>>Diariamente</option>
                                </select>
                                <p class="description">Com que frequência verificar novos arquivos</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="ftp_sync_debug_mode">Modo Debug</label></th>
                            <td>
                                <select name="ftp_sync_debug_mode" id="ftp_sync_debug_mode">
                                    <option value="yes" <?php selected($debug_mode, 'yes'); ?>>Ativado</option>
                                    <option value="no" <?php selected($debug_mode, 'no'); ?>>Desativado</option>
                                </select>
                                <p class="description">Registrar informações detalhadas para diagnóstico</p>
                            </td>
                        </tr>
                    </table>
                </div>
                
                <?php submit_button('Salvar Configurações'); ?>
            </form>
            
            <div class="ftp-sync-panel">
                <h3>Sincronização Manual</h3>
                <p>Execute uma sincronização manual para verificar e criar produtos para novos arquivos imediatamente:</p>
                
                <button id="ftp-sync-manual" class="button button-primary">Sincronizar Agora</button>
                <div id="ftp-sync-result"></div>
            </div>
            
            <?php if (file_exists(FTP_SYNC_PATH . 'includes/ftp-connector.php')): ?>
            <div class="ftp-sync-panel" style="background-color:#f7fcff; border-left:4px solid #2271b1;">
                <h3>Arquivos do Plugin</h3>
                <p>Status dos arquivos necessários:</p>
                <ul style="list-style:disc; margin-left:20px;">
                    <li>FTP Connector: <strong><?php echo file_exists(FTP_SYNC_PATH . 'includes/ftp-connector.php') ? 'OK ✓' : 'Faltando ✗'; ?></strong></li>
                    <li>Product Creator: <strong><?php echo file_exists(FTP_SYNC_PATH . 'includes/product-creator.php') ? 'OK ✓' : 'Faltando ✗'; ?></strong></li>
                    <li>CSS: <strong><?php echo file_exists(FTP_SYNC_PATH . 'assets/css/admin.css') ? 'OK ✓' : 'Faltando ✗'; ?></strong></li>
                    <li>JavaScript: <strong><?php echo file_exists(FTP_SYNC_PATH . 'assets/js/admin.js') ? 'OK ✓' : 'Faltando ✗'; ?></strong></li>
                </ul>
                <p>Se algum arquivo estiver faltando, clique aqui para recriar: <button id="ftp-sync-recreate-files" class="button">Recriar Arquivos</button></p>
            </div>
            <?php else: ?>
            <div class="ftp-sync-panel" style="background-color:#fcf8f7; border-left:4px solid #d63638;">
                <h3>Arquivos do Plugin</h3>
                <p><strong>Atenção:</strong> Arquivos necessários estão faltando. Clique abaixo para recriar:</p>
                <button id="ftp-sync-recreate-files" class="button button-primary">Recriar Arquivos Necessários</button>
                <div id="ftp-recreate-result" style="margin-top:10px;"></div>
            </div>
            <?php endif; ?>
        </div>
        
        <script>
        jQuery(document).ready(function($) {
            // Botão para recriar arquivos
            $("#ftp-sync-recreate-files").on("click", function(e) {
                e.preventDefault();
                var $button = $(this);
                var $result = $("#ftp-recreate-result");
                
                $button.prop("disabled", true).text("Recriando...");
                
                $.ajax({
                    url: ajaxurl,
                    type: "POST",
                    data: {
                        action: "ftp_sync_recreate_files",
                        nonce: "<?php echo wp_create_nonce('ftp_sync_nonce'); ?>"
                    },
                    success: function(response) {
                        $button.prop("disabled", false).text("Recriar Arquivos");
                        
                        if (response.success) {
                            alert("Arquivos recriados com sucesso! A página será recarregada.");
                            location.reload();
                        } else {
                            $result.html('<div style="color:red;padding:10px;">Erro: ' + response.data + '</div>');
                        }
                    },
                    error: function() {
                        $button.prop("disabled", false).text("Recriar Arquivos");
                        $result.html('<div style="color:red;padding:10px;">Erro de conexão com o servidor</div>');
                    }
                });
            });
        });
        </script>
    </div>
    <?php
}

/**
 * Renderizar página de status
 */
function ftp_sync_render_status_page() {
    // Verificar permissões
    if (!current_user_can('manage_options')) {
        wp_die('Acesso negado');
    }
    
    // Verificar status do sistema
    $has_woocommerce = class_exists('WooCommerce');
    $has_ftp = function_exists('ftp_connect');
    $has_jetengine = defined('JET_ENGINE_VERSION');
    
    $last_check = get_option('ftp_sync_last_check', 0);
    $next_check = wp_next_scheduled('ftp_sync_check_files');
    $processed_files = get_option('ftp_sync_processed_files', array());
    $total_files = count($processed_files);
    
    ?>
    <div class="wrap">
        <h1>FTP Sync - Status</h1>
        
        <h2 class="nav-tab-wrapper">
            <a href="?page=ftp-sync-woocommerce" class="nav-tab">Configurações</a>
            <a href="?page=ftp-sync-status" class="nav-tab nav-tab-active">Status</a>
            <a href="?page=ftp-sync-logs" class="nav-tab">Logs</a>
        </h2>
        
        <div class="ftp-sync-container">
            <div class="ftp-sync-panel">
                <h3>Informações do Sistema</h3>
                <table class="widefat striped">
                    <tbody>
                        <tr>
                            <th style="width:200px">WooCommerce</th>
                            <td>
                                <?php if ($has_woocommerce): ?>
                                    <span style="color:green;font-weight:bold;">✓ Ativado</span>
                                    (Versão <?php echo WC()->version; ?>)
                                <?php else: ?>
                                    <span style="color:red;font-weight:bold;">✗ Desativado</span>
                                    <br><em>O plugin requer o WooCommerce ativo.</em>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <tr>
                            <th>Extensão FTP do PHP</th>
                            <td>
                                <?php if ($has_ftp): ?>
                                    <span style="color:green;font-weight:bold;">✓ Disponível</span>
                                <?php else: ?>
                                    <span style="color:red;font-weight:bold;">✗ Indisponível</span>
                                    <br><em>O plugin requer a extensão FTP do PHP.</em>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <tr>
                            <th>JetEngine</th>
                            <td>
                                <?php if ($has_jetengine): ?>
                                    <span style="color:green;font-weight:bold;">✓ Ativado</span>
                                    (Versão <?php echo JET_ENGINE_VERSION; ?>)
                                <?php else: ?>
                                    <span style="color:orange;font-weight:bold;">⚠ Não detectado</span>
                                    <br><em>Recomendado para integração com cadastro de clientes.</em>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <tr>
                            <th>PHP</th>
                            <td><?php echo PHP_VERSION; ?></td>
                        </tr>
                        <tr>
                            <th>WordPress</th>
                            <td><?php echo get_bloginfo('version'); ?></td>
                        </tr>
                    </tbody>
                </table>
            </div>
            
            <div class="ftp-sync-panel">
                <h3>Status da Sincronização</h3>
                <table class="widefat striped">
                    <tbody>
                        <tr>
                            <th style="width:200px">Arquivos processados</th>
                            <td><?php echo $total_files; ?></td>
                        </tr>
                        <tr>
                            <th>Última sincronização</th>
                            <td>
                                <?php 
                                if ($last_check > 0) {
                                    echo date('d/m/Y H:i:s', $last_check);
                                } else {
                                    echo '<em>Nunca executado</em>';
                                }
                                ?>
                            </td>
                        </tr>
                        <tr>
                            <th>Próxima sincronização agendada</th>
                            <td>
                                <?php 
                                if ($next_check) {
                                    echo date('d/m/Y H:i:s', $next_check);
                                } else {
                                    echo '<em>Não agendado</em>';
                                }
                                ?>
                            </td>
                        </tr>
                        <tr>
                            <th>Diretórios necessários</th>
                            <td>
                                <?php
                                $upload_dir = wp_upload_dir();
                                $sync_dir = $upload_dir['basedir'] . '/ftp-sync-files';
                                $log_dir = $upload_dir['basedir'] . '/ftp-sync-logs';
                                
                                echo 'Diretório de arquivos: ';
                                if (file_exists($sync_dir) && is_writable($sync_dir)) {
                                    echo '<span style="color:green">OK</span>';
                                } else {
                                    echo '<span style="color:red">NÃO CRIADO OU SEM PERMISSÃO</span>';
                                }
                                
                                echo '<br>Diretório de logs: ';
                                if (file_exists($log_dir) && is_writable($log_dir)) {
                                    echo '<span style="color:green">OK</span>';
                                } else {
                                    echo '<span style="color:red">NÃO CRIADO OU SEM PERMISSÃO</span>';
                                }
                                ?>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            
            <?php if (!empty($processed_files)): ?>
            <div class="ftp-sync-panel">
                <h3>Últimos Arquivos Processados</h3>
                <table class="widefat">
                    <thead>
                        <tr>
                            <th>Arquivo</th>
                            <th>Cliente</th>
                            <th>Data</th>
                            <th>Produto</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        // Mostrar os últimos 10 arquivos
                        $recent_files = array_slice($processed_files, -10, 10, true);
                        foreach ($recent_files as $file_data): 
                        ?>
                        <tr>
                            <td><?php echo esc_html($file_data['file']); ?></td>
                            <td><?php echo esc_html($file_data['client']); ?></td>
                            <td><?php echo date('d/m/Y H:i:s', $file_data['time']); ?></td>
                            <td>
                                <a href="<?php echo admin_url('post.php?post=' . $file_data['product_id'] . '&action=edit'); ?>">#<?php echo $file_data['product_id']; ?></a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
            
            <div class="ftp-sync-panel">
                <h3>URL Para Cron Externo</h3>
                <p>Para sincronizações mais confiáveis, configure um cron externo real para acessar esta URL:</p>
                
                <?php 
                $security_key = get_option('ftp_sync_security_key');
                $cron_url = home_url('/ftp-sync/process/' . $security_key); 
                ?>
                
                <input type="text" readonly class="large-text code" value="<?php echo esc_url($cron_url); ?>" onclick="this.select()">
                
                <p class="description">
                    Exemplo de comando para crontab: <code>*/15 * * * * wget -q -O /dev/null "<?php echo esc_url($cron_url); ?>"</code>
                </p>
            </div>
        </div>
    </div>
    <?php
}

/**
 * Renderizar página de logs
 */
function ftp_sync_render_logs_page() {
    // Verificar permissões
    if (!current_user_can('manage_options')) {
        wp_die('Acesso negado');
    }
    
    // Obter logs disponíveis
    $upload_dir = wp_upload_dir();
    $log_dir = $upload_dir['basedir'] . '/ftp-sync-logs';
    $log_files = array();
    
    if (is_dir($log_dir)) {
        $log_files = glob($log_dir . '/*.log');
    }
    
    // Ordenar por data (mais recentes primeiro)
    rsort($log_files);
    
    // Determinar qual log mostrar
    $current_log = isset($_GET['log']) && in_array($_GET['log'], $log_files) ? $_GET['log'] : (isset($log_files[0]) ? $log_files[0] : '');
    
    ?>
    <div class="wrap">
        <h1>FTP Sync - Logs</h1>
        
        <h2 class="nav-tab-wrapper">
            <a href="?page=ftp-sync-woocommerce" class="nav-tab">Configurações</a>
            <a href="?page=ftp-sync-status" class="nav-tab">Status</a>
            <a href="?page=ftp-sync-logs" class="nav-tab nav-tab-active">Logs</a>
        </h2>
        
        <div class="ftp-sync-container">
            <?php if (empty($log_files)): ?>
                <div class="ftp-sync-panel">
                    <p>Nenhum arquivo de log encontrado.</p>
                </div>
            <?php else: ?>
                <div class="ftp-sync-panel">
                    <h3>Selecionar Log</h3>
                    
                    <form method="get">
                        <input type="hidden" name="page" value="ftp-sync-logs">
                        <select name="log">
                            <?php foreach ($log_files as $log_file): ?>
                                <?php $log_name = basename($log_file); ?>
                                <option value="<?php echo $log_file; ?>" <?php selected($log_file, $current_log); ?>>
                                    <?php echo $log_name; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        
                        <input type="submit" class="button" value="Ver">
                    </form>
                </div>
                
                <div class="ftp-sync-panel">
                    <h3>Conteúdo do Log</h3>
                    
                    <?php if (!empty($current_log) && file_exists($current_log)): ?>
                        <div style="background:#f8f8f8; overflow:auto; max-height:500px; padding:10px; font-family:monospace;">
                            <?php echo nl2br(esc_html(file_get_contents($current_log))); ?>
                        </div>
                    <?php else: ?>
                        <p>Selecione um arquivo de log para visualizar.</p>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
    <?php
}

/**
 * AJAX para recriar arquivos
 */
function ftp_sync_ajax_recreate_files() {
    // Verificar nonce
    check_ajax_referer('ftp_sync_nonce', 'nonce');
    
    // Verificar permissões
    if (!current_user_can('manage_options')) {
        wp_send_json_error('Acesso negado');
        return;
    }
    
    // Recriar arquivos
    try {
        ftp_sync_create_include_files();
        wp_send_json_success('Arquivos recriados com sucesso');
    } catch (Exception $e) {
        wp_send_json_error($e->getMessage());
    }
}

/**
 * AJAX para testar conexão FTP
 */
function ftp_sync_ajax_test_connection() {
    // Verificar nonce
    check_ajax_referer('ftp_sync_nonce', 'nonce');
    
    // Verificar permissões
    if (!current_user_can('manage_options')) {
        wp_send_json_error('Acesso negado');
    }
    
    // Carregar arquivo FTP connector
    $ftp_connector = FTP_SYNC_PATH . 'includes/ftp-connector.php';
    if (file_exists($ftp_connector)) {
        require_once $ftp_connector;
    } else {
        wp_send_json_error('Arquivo FTP Connector não encontrado. Use o botão "Recriar Arquivos".');
        return;
    }
    
    // Obter configurações
    $host = get_option('ftp_sync_ftp_host', '');
    $port = intval(get_option('ftp_sync_ftp_port', 21));
    $username = get_option('ftp_sync_ftp_username', '');
    $password = get_option('ftp_sync_ftp_password', '');
    $path = get_option('ftp_sync_ftp_path', '/');
    $passive = get_option('ftp_sync_ftp_passive', 'yes') === 'yes';
    
    // Validar configurações
    if (empty($host) || empty($username) || empty($password)) {
        wp_send_json_error('Configurações FTP incompletas. Preencha todos os campos obrigatórios.');
    }
    
    // Tentar conectar
    $conn = @ftp_connect($host, $port, 30);
    if (!$conn) {
        wp_send_json_error("Falha ao conectar ao servidor FTP: {$host}:{$port}");
    }
    
    // Tentar login
    $login = @ftp_login($conn, $username, $password);
    if (!$login) {
        @ftp_close($conn);
        wp_send_json_error('Falha na autenticação. Verifique usuário e senha.');
    }
    
    // Configurar modo passivo
    if ($passive) {
        @ftp_pasv($conn, true);
    }
    
    // Tentar acessar diretório
    if (!@ftp_chdir($conn, $path)) {
        @ftp_close($conn);
        wp_send_json_error("Falha ao acessar o diretório: {$path}");
    }
    
    // Listar conteúdo
    $items = @ftp_nlist($conn, ".");
    $files_count = 0;
    $dirs_count = 0;
    
    if (is_array($items)) {
        foreach ($items as $item) {
            if ($item == '.' || $item == '..') continue;
            
            // Verificar se é um diretório
            if (@ftp_size($conn, $item) === -1) {
                $dirs_count++;
            } else {
                $files_count++;
            }
        }
    }
    
    @ftp_close($conn);
    
    wp_send_json_success(sprintf(
        "<strong>Conexão FTP bem sucedida!</strong><br>Diretório: %s<br>Pastas encontradas: %d<br>Arquivos encontrados: %d",
        $path, $dirs_count, $files_count
    ));
}

/**
 * AJAX para sincronização manual
 */
function ftp_sync_ajax_manual_sync() {
    // Verificar nonce
    check_ajax_referer('ftp_sync_nonce', 'nonce');
    
    // Verificar permissões
    if (!current_user_can('manage_options')) {
        wp_send_json_error('Acesso negado');
    }
    
    // Verificar arquivos necessários
    $ftp_connector = FTP_SYNC_PATH . 'includes/ftp-connector.php';
    $product_creator = FTP_SYNC_PATH . 'includes/product-creator.php';
    
    if (!file_exists($ftp_connector) || !file_exists($product_creator)) {
        wp_send_json_error('Arquivos necessários não encontrados. Use o botão "Recriar Arquivos".');
        return;
    }
    
    // Incluir arquivos necessários
    require_once $ftp_connector;
    require_once $product_creator;
    
    // Executar sincronização
    $result = ftp_sync_process_files(false);
    
    if ($result === false) {
        wp_send_json_error('Falha na sincronização. Verifique os logs para mais detalhes.');
        return;
    }
    
    wp_send_json_success(sprintf(
        'Sincronização concluída com sucesso. %d novos produtos criados.',
        $result
    ));
}

/**
 * Função para processar arquivos FTP
 * 
 * @param bool $is_scheduled Se é uma execução agendada
 * @return int|bool Número de produtos criados ou false em caso de erro
 */
function ftp_sync_process_files($is_scheduled = true) {
    // Verificar se WooCommerce está ativo
    if (!class_exists('WooCommerce')) {
        ftp_sync_log('ERRO: WooCommerce não está ativo');
        return false;
    }
    
    // Verificar arquivo de lock
    $upload_dir = wp_upload_dir();
    $lock_file = $upload_dir['basedir'] . '/ftp-sync-files/process.lock';
    
    if (file_exists($lock_file)) {
        $lock_time = filemtime($lock_file);
        // Se o lock tem menos de 5 minutos
        if (time() - $lock_time < 300) {
            ftp_sync_log('Operação ignorada: processo já em andamento');
            return false;
        }
        
        // Lock antigo, remover
        @unlink($lock_file);
    }
    
    // Criar diretório se não existir
    if (!file_exists(dirname($lock_file))) {
        wp_mkdir_p(dirname($lock_file));
    }
    
    // Criar arquivo de lock
    file_put_contents($lock_file, date('Y-m-d H:i:s'));
    
    ftp_sync_log('=== INÍCIO DO PROCESSAMENTO (' . ($is_scheduled ? 'agendado' : 'manual') . ') ===');
    
    try {
        // Carregar funções necessárias
        require_once FTP_SYNC_PATH . 'includes/ftp-connector.php';
        
        // Conectar ao FTP
        $ftp_connection = ftp_sync_connect_ftp();
        
        if (!$ftp_connection) {
            ftp_sync_log('ERRO: Falha na conexão FTP');
            @unlink($lock_file);
            return false;
        }
        
        // Obter lista de diretórios de clientes
        $base_path = get_option('ftp_sync_ftp_path', '/');
        ftp_sync_log("Listando diretórios em '$base_path'");
        
        // Tentar mudar para o diretório base
        if (!@ftp_chdir($ftp_connection, $base_path)) {
            ftp_sync_log("ERRO: Não foi possível acessar o diretório base: $base_path");
            @ftp_close($ftp_connection);
            @unlink($lock_file);
            return false;
        }
        
        // Listar pastas
        $items = @ftp_nlist($ftp_connection, ".");
        $clients = array();
        
        if (!is_array($items)) {
            ftp_sync_log("ERRO: Falha ao listar conteúdo do diretório base");
            @ftp_close($ftp_connection);
            @unlink($lock_file);
            return false;
        }
        
        $current_dir = @ftp_pwd($ftp_connection);
        
        // Identificar diretórios
        foreach ($items as $item) {
            // Ignorar entradas especiais
            if ($item === "." || $item === "..") {
                continue;
            }
            
            // Verificar se é diretório
            if (@ftp_chdir($ftp_connection, $current_dir . '/' . $item)) {
                $clients[] = array(
                    'path' => $current_dir . '/' . $item,
                    'name' => $item
                );
                @ftp_chdir($ftp_connection, $current_dir);
            }
        }
        
        $count_clients = count($clients);
        ftp_sync_log("Encontrados $count_clients diretórios de clientes");
        
        if (empty($clients)) {
            ftp_sync_log("AVISO: Nenhum diretório de cliente encontrado em '$base_path'");
            @ftp_close($ftp_connection);
            @unlink($lock_file);
            return 0;
        }
        
        $total_products = 0;
        $processed_files = get_option('ftp_sync_processed_files', array());
        
        // Processar cada pasta de cliente
        foreach ($clients as $client) {
            $client_path = $client['path'];
            $client_name = $client['name'];
            
            ftp_sync_log("Processando cliente: $client_name (pasta: $client_path)");
            
            // Mudar para o diretório do cliente
            if (!@ftp_chdir($ftp_connection, $client_path)) {
                ftp_sync_log("ERRO: Não foi possível acessar a pasta do cliente: $client_path");
                continue;
            }
            
            // Listar arquivos
            $items = @ftp_nlist($ftp_connection, ".");
            
            if (!is_array($items)) {
                ftp_sync_log("ERRO: Falha ao listar arquivos na pasta do cliente");
                continue;
            }
            
            $files = array();
            
            foreach ($items as $item) {
                // Ignorar entradas especiais
                if ($item === "." || $item === "..") {
                    continue;
                }
                
                // Verificar se é um arquivo
                $size = @ftp_size($ftp_connection, $item);
                
                if ($size > 0) {
                    $files[] = array(
                        'name' => $item,
                        'path' => $client_path . '/' . $item,
                        'size' => $size,
                        'time' => @ftp_mdtm($ftp_connection, $item) ?: time()
                    );
                }
            }
            
            $count_files = count($files);
            ftp_sync_log("$count_files arquivos encontrados para o cliente $client_name");
            
            $client_products = 0;
            
            // Processar cada arquivo
            foreach ($files as $file) {
                // Gerar hash único do arquivo
                $file_hash = md5($file['path'] . '_' . $file['size'] . '_' . $file['time']);
                
                // Verificar se já foi processado
                if (isset($processed_files[$file_hash])) {
                    ftp_sync_log("Arquivo já processado anteriormente: " . $file['name']);
                    continue;
                }
                
                ftp_sync_log("Processando arquivo: " . $file['name'] . " (" . size_format($file['size']) . ")");
                
                // Preparar diretório de destino
                $target_dir = $upload_dir['basedir'] . '/ftp-sync-files';
                if (!file_exists($target_dir)) {
                    wp_mkdir_p($target_dir);
                }
                
                // Nome único para o arquivo local
                $unique_name = uniqid('file_') . '_' . sanitize_file_name($file['name']);
                $local_path = $target_dir . '/' . $unique_name;
                
                // Download do arquivo
                ftp_sync_log("Baixando arquivo para: $local_path");
                $download_success = @ftp_get($ftp_connection, $local_path, $file['name'], FTP_BINARY);
                
                if (!$download_success) {
                    ftp_sync_log("ERRO: Falha ao baixar arquivo");
                    continue;
                }
                
                // Verificar se o arquivo foi baixado corretamente
                if (!file_exists($local_path)) {
                    ftp_sync_log("ERRO: Arquivo baixado não existe");
                    continue;
                }
                
                if (filesize($local_path) <= 0) {
                    ftp_sync_log("ERRO: Arquivo baixado está vazio");
                    @unlink($local_path);
                    continue;
                }
                
                // Criar produto
                try {
                    // Gerar dados do produto
                    $product = new WC_Product();
                    
                    // Título do produto
                    $title_base = pathinfo($file['name'], PATHINFO_FILENAME);
                    $title_base = str_replace(['_', '-'], ' ', $title_base);
                    $title = ucwords($title_base) . ' - ' . $client_name;
                    
                    $product->set_name($title);
                    
                    // Descrição
                    $file_ext = strtoupper(pathinfo($file['name'], PATHINFO_EXTENSION));
                    $file_size = size_format($file['size']);
                    
                    $description = "Arquivo: {$file['name']}\n";
                    $description .= "Tipo: $file_ext\n";
                    $description .= "Tamanho: $file_size\n";
                    $description .= "Cliente: $client_name\n";
                    $description .= "Data de processamento: " . date('d/m/Y H:i:s');
                    
                    $product->set_description($description);
                    $product->set_short_description("Arquivo $file_ext do cliente $client_name");
                    
                    // Configurar produto como digital
                    $product->set_virtual(true);
                    $product->set_downloadable(true);
                    
                    // Preço e status
                    $price = get_option('ftp_sync_product_price', '10');
                    $status = get_option('ftp_sync_product_status', 'publish');
                    
                    $product->set_regular_price($price);
                    $product->set_price($price);
                    $product->set_status($status);
                    
                    // Configurar download
                    $file_url = $upload_dir['baseurl'] . '/ftp-sync-files/' . $unique_name;
                    
                    $download = array(
                        'id' => md5($file['path']),
                        'name' => $file['name'],
                        'file' => $file_url
                    );
                    
                    $product->set_downloads(array($download));
                    $product->set_download_limit(-1); // Sem limite
                    $product->set_download_expiry(-1); // Sem expiração
                    
                    // Metadados
                    $product->update_meta_data('_ftp_sync_client', $client_name);
                    $product->update_meta_data('_ftp_sync_file_path', $file['path']);
                    $product->update_meta_data('_ftp_sync_file_size', $file['size']);
                    $product->update_meta_data('_ftp_sync_processed_time', time());
                    
                    // Salvar produto
                    ftp_sync_log("Salvando produto: $title");
                    $product_id = $product->save();
                    
                    if (!$product_id) {
                        ftp_sync_log("ERRO: Falha ao salvar produto no banco de dados");
                        continue;
                    }
                    
                    // Registrar arquivo como processado
                    $processed_files[$file_hash] = array(
                        'file' => $file['name'],
                        'product_id' => $product_id,
                        'time' => time(),
                        'client' => $client_name
                    );
                    
                    $client_products++;
                    $total_products++;
                    
                    ftp_sync_log("Produto criado com sucesso (ID: $product_id)");
                    
                } catch (Exception $e) {
                    ftp_sync_log("ERRO ao criar produto: " . $e->getMessage());
                    continue;
                }
            }
            
            ftp_sync_log("$client_products produtos criados para o cliente $client_name");
        }
        
        // Atualizar lista de arquivos processados
        update_option('ftp_sync_processed_files', $processed_files);
        
        // Atualizar última verificação
        update_option('ftp_sync_last_check', time());
        
        // Fechar conexão FTP
        @ftp_close($ftp_connection);
        
        ftp_sync_log("=== FIM DO PROCESSAMENTO: $total_products produtos criados ===");
        
        // Remover arquivo de lock
        @unlink($lock_file);
        
        return $total_products;
        
    } catch (Exception $e) {
        ftp_sync_log("ERRO CRÍTICO: " . $e->getMessage());
        @unlink($lock_file);
        return false;
    }
}

/**
 * Registrar mensagem no log
 * 
 * @param string $message Mensagem a ser registrada
 */
function ftp_sync_log($message) {
    // Debug está sempre ativado durante a migração do plugin
    $debug_mode = get_option('ftp_sync_debug_mode', 'yes');
    
    if ($debug_mode !== 'yes') {
        return;
    }
    
    $upload_dir = wp_upload_dir();
    $log_dir = $upload_dir['basedir'] . '/ftp-sync-logs';
    
    // Criar diretório se não existir
    if (!file_exists($log_dir)) {
        wp_mkdir_p($log_dir);
    }
    
    $log_file = $log_dir . '/sync-' . date('Y-m-d') . '.log';
    $log_entry = '[' . date('Y-m-d H:i:s') . '] ' . $message . "\n";
    
    file_put_contents($log_file, $log_entry, FILE_APPEND);
}

// INÍCIO DOS HOOKS - GARANTIR QUE SEJAM REGISTRADOS SEMPRE

// Registrar menu (com prioridade alta para garantir que sempre apareça)
add_action('admin_menu', 'ftp_sync_register_menu', 99);

// Carregar arquivos necessários
add_action('plugins_loaded', 'ftp_sync_load_includes');

// Admin scripts e estilos
add_action('admin_enqueue_scripts', 'ftp_sync_admin_scripts');

// Registrar configurações
add_action('admin_init', 'ftp_sync_register_settings');

// AJAX handlers
add_action('wp_ajax_ftp_sync_test_connection', 'ftp_sync_ajax_test_connection');
add_action('wp_ajax_ftp_sync_manual', 'ftp_sync_ajax_manual_sync');
add_action('wp_ajax_ftp_sync_recreate_files', 'ftp_sync_ajax_recreate_files');

// Endpoint para cron externo
add_action('init', function() {
    add_rewrite_rule(
        'ftp-sync/process/([^/]+)/?$',
        'index.php?ftp_sync_action=process&security_key=$matches[1]',
        'top'
    );
    
    add_rewrite_tag('%ftp_sync_action%', '([^&]+)');
    add_rewrite_tag('%security_key%', '([^&]+)');
});

// Processar endpoint de cron externo
add_action('template_redirect', function() {
    global $wp_query;
    
    if (isset($wp_query->query_vars['ftp_sync_action']) && 
        $wp_query->query_vars['ftp_sync_action'] === 'process') {
        
        // Verificar chave de segurança
        $key = isset($wp_query->query_vars['security_key']) ? $wp_query->query_vars['security_key'] : '';
        $stored_key = get_option('ftp_sync_security_key');
        
        if ($key !== $stored_key) {
            status_header(403);
            echo json_encode(array('error' => 'Acesso negado'));
            exit;
        }
        
        // Executar processamento
        $result = ftp_sync_process_files(true);
        
        // Retornar resultado
        header('Content-Type: application/json');
        echo json_encode(array(
            'success' => ($result !== false),
            'products_created' => ($result !== false) ? $result : 0,
            'time' => date('Y-m-d H:i:s'),
            'message' => 'Processamento concluído via cron externo'
        ));
        exit;
    }
});

// Configurar agendamento inicial
add_action('ftp_sync_setup_schedules', function() {
    $interval = get_option('ftp_sync_check_interval', 'hourly');
    
    
    // Remover agendamento existente
    $timestamp = wp_next_scheduled('ftp_sync_check_files');
    if ($timestamp) {
        wp_unschedule_event($timestamp, 'ftp_sync_check_files');
    }
    
    // Criar novo agendamento
    wp_schedule_event(time(), $interval, 'ftp_sync_check_files');
    
    ftp_sync_log('Agendamento configurado: ' . $interval);
});

// Handler para verificação agendada
add_action('ftp_sync_check_files', function() {
    ftp_sync_process_files(true);
});

// Atualizar agendamento quando configurações são alteradas
add_action('update_option_ftp_sync_check_interval', function($old_value, $new_value) {
    if ($old_value !== $new_value) {
        // Remover agendamento existente
        $timestamp = wp_next_scheduled('ftp_sync_check_files');
        if ($timestamp) {
            wp_unschedule_event($timestamp, 'ftp_sync_check_files');
        }
        
        // Criar novo agendamento
        wp_schedule_event(time(), $new_value, 'ftp_sync_check_files');
        
        ftp_sync_log('Agendamento atualizado: ' . $new_value);
    }
}, 10, 2);

// Carregar integração com JetEngine
add_action('plugins_loaded', 'ftp_sync_load_jetengine', 20);

/**
 * Renderizar página de diagnóstico
 */
function ftp_sync_render_diagnostic_page() {
    if (!current_user_can('manage_options')) {
        wp_die('Acesso negado');
    }
    
    ?>
    <div class="wrap">
        <h1>FTP Sync - Diagnóstico</h1>
        
        <h2 class="nav-tab-wrapper">
            <a href="?page=ftp-sync-woocommerce" class="nav-tab">Configurações</a>
            <a href="?page=ftp-sync-status" class="nav-tab">Status</a>
            <a href="?page=ftp-sync-logs" class="nav-tab">Logs</a>
            <a href="?page=ftp-sync-diagnostic" class="nav-tab nav-tab-active">Diagnóstico</a>
        </h2>
        
        <div class="ftp-sync-container">
            <div class="ftp-sync-panel">
                <h3>Diagnóstico de Problemas</h3>
                
                <button id="ftp-sync-run-diagnostic" class="button button-primary">Executar Diagnóstico</button>
                <div id="ftp-diagnostic-result" style="margin-top:20px;"></div>
            </div>
        </div>
        
        <script>
        jQuery(document).ready(function($) {
            $('#ftp-sync-run-diagnostic').on('click', function(e) {
                e.preventDefault();
                var $button = $(this);
                var $result = $('#ftp-diagnostic-result');
                
                $button.prop('disabled', true).text('Executando diagnóstico...');
                $result.html('<div style="padding:10px;background:#f7f7f7;border-left:4px solid #999;">Aguarde, executando testes...</div>');
                
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'ftp_sync_run_diagnostic',
                        nonce: '<?php echo wp_create_nonce('ftp_sync_nonce'); ?>'
                    },
                    success: function(response) {
                        $button.prop('disabled', false).text('Executar Diagnóstico');
                        if (response.success) {
                            $result.html(response.data);
                        } else {
                            $result.html('<div style="padding:10px;background:#f2dede;color:#a94442;border-left:4px solid #dc3232;">Erro: ' + response.data + '</div>');
                        }
                    },
                    error: function() {
                        $button.prop('disabled', false).text('Executar Diagnóstico');
                        $result.html('<div style="padding:10px;background:#f2dede;color:#a94442;border-left:4px solid #dc3232;">Erro de conexão com o servidor</div>');
                    }
                });
            });
        });
        </script>
    </div>
    <?php
}

/**
 * AJAX para executar diagnóstico
 */
function ftp_sync_ajax_run_diagnostic() {
    // Verificar nonce
    check_ajax_referer('ftp_sync_nonce', 'nonce');
    
    // Verificar permissões
    if (!current_user_can('manage_options')) {
        wp_send_json_error('Acesso negado');
    }
    
    $output = '<div style="background:#fff;padding:15px;border:1px solid #ddd;">';
    
    // Verificar WooCommerce
    $output .= '<h3>1. Verificação do WooCommerce</h3>';
    if (class_exists('WooCommerce')) {
        $output .= '<p style="color:green;font-weight:bold;">✓ WooCommerce está ativo (versão ' . WC()->version . ')</p>';
    } else {
        $output .= '<p style="color:red;font-weight:bold;">✗ WooCommerce não está ativo! Este plugin requer WooCommerce.</p>';
    }
    
    // Verificar extensão FTP
    $output .= '<h3>2. Verificação da Extensão FTP</h3>';
    if (function_exists('ftp_connect')) {
        $output .= '<p style="color:green;font-weight:bold;">✓ Extensão FTP do PHP está disponível</p>';
    } else {
        $output .= '<p style="color:red;font-weight:bold;">✗ Extensão FTP do PHP não está disponível! Contate seu provedor de hospedagem.</p>';
    }
    
    // Verificar JetEngine
    $output .= '<h3>3. Verificação do JetEngine</h3>';
    if (defined('JET_ENGINE_VERSION') || class_exists('Jet_Engine')) {
        $output .= '<p style="color:green;font-weight:bold;">✓ JetEngine está ativo';
        if (defined('JET_ENGINE_VERSION')) {
            $output .= ' (versão ' . JET_ENGINE_VERSION . ')';
        }
        $output .= '</p>';
        
        // Verificar integração
        $jet_integration = FTP_SYNC_PATH . 'includes/jet-integration.php';
        if (file_exists($jet_integration)) {
            $output .= '<p style="color:green;">✓ Arquivo de integração existe</p>';
        } else {
            $output .= '<p style="color:orange;">⚠ Arquivo de integração não existe</p>';
        }
        
        // Verificar campo no JetEngine
        if (isset(jet_engine()->meta_boxes)) {
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
            
            if ($has_field) {
                $output .= '<p style="color:green;">✓ Campo ftp_folder_name está registrado no JetEngine</p>';
            } else {
                $output .= '<p style="color:orange;">⚠ Campo ftp_folder_name não está registrado no JetEngine</p>';
            }
        } else {
            $output .= '<p style="color:orange;">⚠ Componente meta_boxes do JetEngine não disponível</p>';
        }
        
    } else {
        $output .= '<p style="color:orange;font-weight:bold;">⚠ JetEngine não está ativo. A integração com cadastro de clientes não funcionará.</p>';
    }
    
    // Verificar configurações FTP
    $output .= '<h3>4. Verificação das Configurações FTP</h3>';
    $ftp_host = get_option('ftp_sync_ftp_host', '');
    $ftp_username = get_option('ftp_sync_ftp_username', '');
    $ftp_password = get_option('ftp_sync_ftp_password', '');
    $ftp_path = get_option('ftp_sync_ftp_path', '/');
    
    if (empty($ftp_host) || empty($ftp_username) || empty($ftp_password)) {
        $output .= '<p style="color:red;font-weight:bold;">✗ Configurações FTP incompletas! Preencha todos os campos obrigatórios.</p>';
    } else {
        $output .= '<p style="color:green;font-weight:bold;">✓ Configurações FTP preenchidas</p>';
        
        // Tentar conectar
        $output .= '<p>Tentando conectar ao servidor FTP...</p>';
        
        $conn = @ftp_connect($ftp_host, intval(get_option('ftp_sync_ftp_port', 21)), 10);
        if (!$conn) {
            $output .= '<p style="color:red;">✗ Falha ao conectar ao servidor FTP: ' . $ftp_host . '</p>';
        } else {
            $output .= '<p style="color:green;">✓ Conexão com o servidor estabelecida</p>';
            
            // Tentar login
            $login = @ftp_login($conn, $ftp_username, $ftp_password);
            if (!$login) {
                $output .= '<p style="color:red;">✗ Falha na autenticação. Verifique usuário e senha.</p>';
            } else {
                $output .= '<p style="color:green;">✓ Autenticação bem-sucedida</p>';
                
                // Configurar modo passivo
                if (get_option('ftp_sync_ftp_passive', 'yes') === 'yes') {
                    @ftp_pasv($conn, true);
                    $output .= '<p>Modo passivo ativado</p>';
                }
                
                // Tentar acessar diretório
                if (!@ftp_chdir($conn, $ftp_path)) {
                    $output .= '<p style="color:red;">✗ Não foi possível acessar o diretório: ' . $ftp_path . '</p>';
                } else {
                    $output .= '<p style="color:green;">✓ Diretório ' . $ftp_path . ' acessado com sucesso</p>';
                    
                    // Listar conteúdo
                    $items = @ftp_nlist($conn, ".");
                    if (!is_array($items)) {
                        $output .= '<p style="color:red;">✗ Falha ao listar conteúdo do diretório</p>';
                    } else {
                        $count = count($items);
                        $output .= '<p style="color:green;">✓ Diretório listado com sucesso (' . $count . ' itens)</p>';
                        
                        $dirs_count = 0;
                        $current = @ftp_pwd($conn);
                        
                        $output .= '<ul>';
                        foreach ($items as $item) {
                            if ($item === '.' || $item === '..') {
                                continue;
                            }
                            
                            if (@ftp_chdir($conn, $current . '/' . $item)) {
                                $dirs_count++;
                                $output .= '<li style="color:blue;">[DIR] ' . $item . '</li>';
                                @ftp_chdir($conn, $current);
                            } else {
                                $size = @ftp_size($conn, $item);
                                if ($size > 0) {
                                    $output .= '<li>[ARQUIVO] ' . $item . ' (' . size_format($size) . ')</li>';
                                }
                            }
                        }
                        $output .= '</ul>';
                        
                        $output .= '<p>Diretórios encontrados: ' . $dirs_count . '</p>';
                        
                        if ($dirs_count === 0) {
                            $output .= '<p style="color:orange;font-weight:bold;">⚠ Nenhum diretório de cliente encontrado! Verifique o caminho FTP.</p>';
                        }
                    }
                }
            }
            
            @ftp_close($conn);
        }
    }
    
    // Verificar diretórios do WordPress
    $output .= '<h3>5. Verificação dos Diretórios WordPress</h3>';
    $upload_dir = wp_upload_dir();
    $target_dir = $upload_dir['basedir'] . '/ftp-sync-files';
    $log_dir = $upload_dir['basedir'] . '/ftp-sync-logs';
    
    if (!file_exists($target_dir)) {
        $output .= '<p style="color:orange;">⚠ Diretório de arquivos não existe: ' . $target_dir . '</p>';
        $created = wp_mkdir_p($target_dir);
        if ($created) {
            $output .= '<p style="color:green;">✓ Diretório criado com sucesso</p>';
        } else {
            $output .= '<p style="color:red;">✗ Não foi possível criar o diretório</p>';
        }
    } else {
        $output .= '<p style="color:green;">✓ Diretório de arquivos existe</p>';
        if (is_writable($target_dir)) {
            $output .= '<p style="color:green;">✓ Diretório tem permissões de escrita</p>';
        } else {
            $output .= '<p style="color:red;">✗ Diretório não tem permissões de escrita</p>';
        }
    }
    
    if (!file_exists($log_dir)) {
        $output .= '<p style="color:orange;">⚠ Diretório de logs não existe: ' . $log_dir . '</p>';
        $created = wp_mkdir_p($log_dir);
        if ($created) {
            $output .= '<p style="color:green;">✓ Diretório criado com sucesso</p>';
        } else {
            $output .= '<p style="color:red;">✗ Não foi possível criar o diretório</p>';
        }
    } else {
        $output .= '<p style="color:green;">✓ Diretório de logs existe</p>';
        if (is_writable($log_dir)) {
            $output .= '<p style="color:green;">✓ Diretório tem permissões de escrita</p>';
        } else {
            $output .= '<p style="color:red;">✗ Diretório não tem permissões de escrita</p>';
        }
    }
    
    // Verificar arquivos do plugin
    $output .= '<h3>6. Verificação dos Arquivos do Plugin</h3>';
    $files = array(
        'Principal' => FTP_SYNC_PATH . 'ftp-sync-woocommerce.php',
        'FTP Connector' => FTP_SYNC_PATH . 'includes/ftp-connector.php',
        'Product Creator' => FTP_SYNC_PATH . 'includes/product-creator.php',
        'JetEngine Integration' => FTP_SYNC_PATH . 'includes/jet-integration.php',
        'CSS' => FTP_SYNC_PATH . 'assets/css/admin.css',
        'JavaScript' => FTP_SYNC_PATH . 'assets/js/admin.js'
    );
    
    $output .= '<ul>';
    foreach ($files as $name => $file) {
        if (file_exists($file)) {
            $output .= '<li style="color:green;">✓ ' . $name . ': Presente</li>';
        } else {
            $output .= '<li style="color:red;">✗ ' . $name . ': Faltando</li>';
        }
    }
    $output .= '</ul>';
    
    $output .= '</div>';
    
    wp_send_json_success($output);
}

// Registrar AJAX handler para diagnóstico
add_action('wp_ajax_ftp_sync_run_diagnostic', 'ftp_sync_ajax_run_diagnostic');