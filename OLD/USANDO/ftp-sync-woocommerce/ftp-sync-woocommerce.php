<?php
/**
 * Plugin Name: FTP Sync para WooCommerce
 * Description: Sincroniza arquivos de um servidor FTP para produtos WooCommerce automaticamente
 * Version: 1.0.0
 * Author: DevSpacek
 * Text Domain: ftp-sync-woo
 * Requires at least: 5.6
 * Requires PHP: 7.2
 * WC requires at least: 5.0
 */

if (!defined('ABSPATH')) {
    exit; // Saída se acessado diretamente
}

// Definir constantes
define('FTP_SYNC_VERSION', '1.0.0');
define('FTP_SYNC_PATH', plugin_dir_path(__FILE__));
define('FTP_SYNC_URL', plugin_dir_url(__FILE__));
define('FTP_SYNC_BASENAME', plugin_basename(__FILE__));

/**
 * Classe principal do plugin
 */
class FTP_Sync_WooCommerce {
    
    /**
     * Instância única
     */
    private static $instance = null;
    
    /**
     * Classes do plugin
     */
    public $ftp;
    public $product_creator;
    public $admin;
    public $jetengine;
    public $cron;
    
    /**
     * Obter instância única
     */
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * Construtor
     */
    private function __construct() {
        // Hooks de ativação/desativação
        register_activation_hook(__FILE__, array($this, 'activation_check'));
        register_deactivation_hook(__FILE__, array($this, 'deactivation'));
        
        // Carregar plugin
        add_action('plugins_loaded', array($this, 'load_plugin'), 10);
    }
    
    /**
     * Verificação de ativação
     */
    public function activation_check() {
        // Verificar versão do WordPress
        if (version_compare(get_bloginfo('version'), '5.6', '<')) {
            wp_die('FTP Sync para WooCommerce requer WordPress 5.6 ou superior.');
        }
        
        // Verificar versão do PHP
        if (version_compare(PHP_VERSION, '7.2', '<')) {
            wp_die('FTP Sync para WooCommerce requer PHP 7.2 ou superior.');
        }
        
        // Verificar se WooCommerce está ativado
        if (!class_exists('WooCommerce')) {
            wp_die('FTP Sync para WooCommerce requer o plugin WooCommerce ativado.');
        }
        
        // Verificar extensão FTP
        if (!function_exists('ftp_connect')) {
            wp_die('FTP Sync para WooCommerce requer a extensão FTP do PHP habilitada.');
        }
        
        // Configurações iniciais
        $this->create_default_options();
        
        // Criar diretórios necessários
        $this->create_required_directories();
    }
    
    /**
     * Ações de desativação
     */
    public function deactivation() {
        // Remover tarefas agendadas
        wp_clear_scheduled_hook('ftp_sync_check_event');
        
        // Log de desativação
        $this->log("Plugin desativado");
    }
    
    /**
     * Criar opções padrão
     */
    private function create_default_options() {
        $default_options = array(
            'ftp_host' => '',
            'ftp_port' => '21',
            'ftp_username' => '',
            'ftp_password' => '',
            'ftp_passive' => 'yes',
            'ftp_base_path' => '/',
            'check_interval' => 'hourly',
            'product_status' => 'publish',
            'product_price' => '10',
            'last_check' => 0,
            'debug_mode' => 'no',
        );
        
        foreach ($default_options as $option => $value) {
            if (get_option('ftp_sync_' . $option) === false) {
                update_option('ftp_sync_' . $option, $value);
            }
        }
        
        // Criar chave de segurança para API
        if (get_option('ftp_sync_security_key') === false) {
            update_option('ftp_sync_security_key', md5(uniqid('', true)));
        }
    }
    
    /**
     * Criar diretórios necessários
     */
    private function create_required_directories() {
        $upload_dir = wp_upload_dir();
        
        $directories = array(
            $upload_dir['basedir'] . '/ftp-sync-files',
            $upload_dir['basedir'] . '/ftp-sync-logs',
        );
        
        foreach ($directories as $directory) {
            if (!file_exists($directory)) {
                wp_mkdir_p($directory);
                
                // Adicionar arquivo .htaccess para proteger diretório
                file_put_contents($directory . '/.htaccess', "Order deny,allow\nDeny from all");
                
                // Adicionar index.php vazio para segurança
                file_put_contents($directory . '/index.php', "<?php\n// Silêncio é ouro");
            }
        }
    }
    
    /**
     * Carregar plugin
     */
    public function load_plugin() {
        // Verificar WooCommerce
        if (!class_exists('WooCommerce')) {
            add_action('admin_notices', function() {
                echo '<div class="notice notice-error"><p>';
                echo 'FTP Sync para WooCommerce requer o plugin WooCommerce ativado.';
                echo '</p></div>';
            });
            return;
        }
        
        // Verificar extensão FTP
        if (!function_exists('ftp_connect')) {
            add_action('admin_notices', function() {
                echo '<div class="notice notice-error"><p>';
                echo 'FTP Sync para WooCommerce requer a extensão FTP do PHP habilitada.';
                echo '</p></div>';
            });
            return;
        }
        
        // Carregar arquivos necessários
        $this->include_files();
        
        // Inicializar componentes
        $this->init_components();
        
        // Hooks
        $this->setup_hooks();
    }
    
    /**
     * Incluir arquivos
     */
    private function include_files() {
        require_once FTP_SYNC_PATH . 'includes/class-ftp-connector.php';
        require_once FTP_SYNC_PATH . 'includes/class-product-creator.php';
        require_once FTP_SYNC_PATH . 'includes/class-admin-interface.php';
        require_once FTP_SYNC_PATH . 'includes/class-jetengine-integration.php';
        require_once FTP_SYNC_PATH . 'includes/class-cron-manager.php';
    }
    
    /**
     * Inicializar componentes
     */
    private function init_components() {
        $this->ftp = new FTP_Sync_Connector();
        $this->product_creator = new FTP_Sync_Product_Creator();
        $this->admin = new FTP_Sync_Admin_Interface();
        $this->jetengine = new FTP_Sync_JetEngine_Integration();
        $this->cron = new FTP_Sync_Cron_Manager();
    }
    
    /**
     * Configurar hooks
     */
    private function setup_hooks() {
        // CORREÇÃO IMPORTANTE: Registrar os hooks AJAX da classe admin
        add_action('wp_ajax_ftp_sync_test_connection', array($this->admin, 'test_connection'));
        add_action('wp_ajax_ftp_sync_manual_sync', array($this->admin, 'manual_sync'));
        add_action('wp_ajax_ftp_sync_view_log', array($this->admin, 'view_log'));
        
        // Endpoints
        add_action('init', array($this, 'register_endpoints'));
        add_action('template_redirect', array($this, 'handle_endpoints'));
        
        // Cron
        add_action('ftp_sync_check_event', array($this, 'scheduled_sync'));
    }
    
    /**
     * Registrar endpoints personalizados
     */
    public function register_endpoints() {
        add_rewrite_rule(
            'ftp-sync/process/?([^/]*)/?',
            'index.php?ftp_sync_action=process&key=$matches[1]',
            'top'
        );
        
        add_rewrite_tag('%ftp_sync_action%', '([^&]+)');
        add_rewrite_tag('%key%', '([^&]+)');
    }
    
    /**
     * Manipular endpoints personalizados
     */
    public function handle_endpoints() {
        global $wp_query;
        
        if (isset($wp_query->query_vars['ftp_sync_action']) && 
            $wp_query->query_vars['ftp_sync_action'] === 'process') {
            
            $key = isset($wp_query->query_vars['key']) ? $wp_query->query_vars['key'] : '';
            $stored_key = get_option('ftp_sync_security_key');
            
            if ($key !== $stored_key) {
                status_header(403);
                die('Acesso negado');
            }
            
            $this->scheduled_sync();
            echo json_encode(array('success' => true, 'time' => current_time('mysql')));
            exit;
        }
    }
    
    /**
     * Sincronização agendada
     */
    public function scheduled_sync() {
        $this->log("Iniciando sincronização agendada");
        
        // Verificar se outro processo está em andamento
        $lock_file = $this->get_lock_file();
        if (file_exists($lock_file)) {
            $lock_time = filemtime($lock_file);
            if (time() - $lock_time < 300) { // 5 minutos
                $this->log("Outro processo já está em andamento. Pulando.");
                return;
            }
            // Lock antigo, podemos remover
            @unlink($lock_file);
        }
        
        // Criar arquivo de lock
        file_put_contents($lock_file, date('Y-m-d H:i:s'));
        
        try {
            // Conectar ao FTP
            if (!$this->ftp->connect()) {
                $this->log("Falha ao conectar ao servidor FTP");
                @unlink($lock_file);
                return;
            }
            
            // Obter lista de clientes
            $client_folders = $this->ftp->list_client_folders();
            $this->log("Encontradas " . count($client_folders) . " pastas de clientes");
            
            $total_products = 0;
            
            // Processar cada cliente
            foreach ($client_folders as $client_folder) {
                $client_name = basename($client_folder);
                $this->log("Verificando cliente: {$client_name}");
                
                // Processar arquivos deste cliente
                $client_products = $this->process_client_folder($client_folder, $client_name);
                $total_products += $client_products;
                
                if ($client_products > 0) {
                    $this->log("Criados {$client_products} produtos para {$client_name}");
                }
            }
            
            // Desconectar
            $this->ftp->disconnect();
            
            // Atualizar hora da última verificação
            update_option('ftp_sync_last_check', time());
            
            $this->log("Sincronização concluída. Total: {$total_products} produtos criados");
            
        } catch (Exception $e) {
            $this->log("ERRO: " . $e->getMessage());
        }
        
        // Remover arquivo de lock
        @unlink($lock_file);
    }
    
    /**
     * Processar pasta de cliente
     * 
     * @param string $folder_path Caminho da pasta
     * @param string $client_name Nome do cliente
     * @return int Produtos criados
     */
    private function process_client_folder($folder_path, $client_name) {
        $files = $this->ftp->list_files($folder_path);
        if (empty($files)) {
            return 0;
        }
        
        $this->log("Encontrados " . count($files) . " arquivos em {$client_name}");
        
        // Obter arquivos já processados
        $processed_files = get_option('ftp_sync_processed_files', array());
        
        $products_created = 0;
        
        foreach ($files as $file) {
            // Criar hash único do arquivo
            $file_hash = md5($file['path'] . $file['size'] . $file['time']);
            
            // Verificar se já foi processado
            if (isset($processed_files[$file_hash])) {
                continue;
            }
            
            // Baixar e criar produto
            $product_id = $this->product_creator->create_product($file, $client_name, $this->ftp->connection);
            
            if ($product_id) {
                $products_created++;
                
                // Registrar arquivo como processado
                $processed_files[$file_hash] = array(
                    'file' => $file['name'],
                    'product_id' => $product_id,
                    'time' => time(),
                    'client' => $client_name
                );
            }
        }
        
        // Atualizar lista de arquivos processados
        if ($products_created > 0) {
            update_option('ftp_sync_processed_files', $processed_files);
        }
        
        return $products_created;
    }
    
    /**
     * Obter caminho do arquivo de lock
     */
    private function get_lock_file() {
        $upload_dir = wp_upload_dir();
        return $upload_dir['basedir'] . '/ftp-sync-files/sync.lock';
    }
    
    /**
     * Registrar mensagem no log
     * 
     * @param string $message Mensagem
     */
    public function log($message) {
        if (get_option('ftp_sync_debug_mode') !== 'yes') {
            return;
        }
        
        $upload_dir = wp_upload_dir();
        $log_file = $upload_dir['basedir'] . '/ftp-sync-logs/sync-' . date('Y-m-d') . '.log';
        
        $log_entry = '[' . date('Y-m-d H:i:s') . '] ' . $message . "\n";
        file_put_contents($log_file, $log_entry, FILE_APPEND);
    }
}

// Inicializar plugin
function ftp_sync_woocommerce() {
    return FTP_Sync_WooCommerce::get_instance();
}

// Hook de inicialização global
add_action('plugins_loaded', 'ftp_sync_woocommerce');