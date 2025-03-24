<?php
/**
 * Plugin Name: FTP para WooCommerce - Ultra Reliable Auto
 * Description: Converte arquivos FTP em produtos WooCommerce com automação ultra confiável
 * Version: 2.1
 * Author: DevSpacek
 * Requires at least: 5.6
 * Requires PHP: 7.2
 * Text Domain: ftp-woo-auto
 */

if (!defined('ABSPATH')) {
    exit; // Saída direta se acessado diretamente
}

// Definir constantes do plugin
define('FTP_WOO_AUTO_VERSION', '2.1');
define('FTP_WOO_AUTO_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('FTP_WOO_AUTO_PLUGIN_URL', plugin_dir_url(__FILE__));
define('FTP_WOO_AUTO_INCLUDES_DIR', FTP_WOO_AUTO_PLUGIN_DIR . 'includes/');

// Hooks de ativação e desativação global
register_activation_hook(__FILE__, 'ftp_woo_auto_activation');
register_deactivation_hook(__FILE__, 'ftp_woo_auto_deactivation');

// Adiar a inicialização principal do plugin
add_action('plugins_loaded', 'ftp_woo_auto_init', 20);

/**
 * Função de inicialização adiada do plugin
 */
function ftp_woo_auto_init() {
    // Verificar se o PHP FTP está disponível
    if (!function_exists('ftp_connect')) {
        add_action('admin_notices', function() {
            echo '<div class="notice notice-error"><p>';
            echo 'FTP para WooCommerce requer que a extensão FTP do PHP esteja ativada.';
            echo '</p></div>';
        });
        return;
    }
    
    // Verificar WooCommerce
    if (!class_exists('WooCommerce')) {
        add_action('admin_notices', function() {
            echo '<div class="notice notice-error"><p>';
            echo 'FTP para WooCommerce requer que o plugin WooCommerce esteja instalado e ativado.';
            echo '</p></div>';
        });
        return;
    }

    // Carregar arquivos principais
    require_once FTP_WOO_AUTO_INCLUDES_DIR . 'admin.php';
    require_once FTP_WOO_AUTO_INCLUDES_DIR . 'ftp-handler.php';
    require_once FTP_WOO_AUTO_INCLUDES_DIR . 'product-creator.php';
    require_once FTP_WOO_AUTO_INCLUDES_DIR . 'cron.php';

    // Inicializar a instância principal do plugin
    $GLOBALS['ftp_woo_auto'] = FTP_Woo_Auto::instance();
}

/**
 * Função de ativação segura que não depende do objeto global
 */
function ftp_woo_auto_activation() {
    // Configurar opções padrão
    if (empty(get_option('ftp_server_host', ''))) {
        update_option('ftp_server_port', '21');
        update_option('ftp_server_path', '/');
        update_option('ftp_passive_mode', 'yes');
        update_option('ftp_timeout', '90');
        update_option('ftp_default_price', '10');
        update_option('ftp_product_status', 'publish');
        update_option('ftp_auto_enabled', 'yes');
        update_option('ftp_auto_frequency', 'every5minutes');
        update_option('ftp_force_minutes', '5');
    }
    
    // Gerar chave de segurança para API se não existir
    if (empty(get_option('ftp_security_key', ''))) {
        update_option('ftp_security_key', md5(time() . uniqid('', true)));
    }
    
    // Registrar log de ativação
    $log_entry = '[' . date('Y-m-d H:i:s') . '] Plugin ativado: ' . FTP_WOO_AUTO_VERSION . "\n";
    $recent_log = get_option('ftp_recent_log', '');
    update_option('ftp_recent_log', $log_entry . $recent_log);
    
    // Criar diretórios necessários
    $upload_dir = wp_upload_dir();
    
    // Diretório para downloads de produtos
    $products_dir = $upload_dir['basedir'] . '/woocommerce_uploads';
    if (!file_exists($products_dir)) {
        wp_mkdir_p($products_dir);
    }
    
    // Diretório para arquivos temporários
    $temp_dir = $upload_dir['basedir'] . '/ftp-woo-temp';
    if (!file_exists($temp_dir)) {
        wp_mkdir_p($temp_dir);
    }
    
    // Diretório de logs
    $log_dir = $upload_dir['basedir'] . '/ftp-woo-logs';
    if (!file_exists($log_dir)) {
        wp_mkdir_p($log_dir);
        // Proteger o diretório com .htaccess
        $htaccess = "Order deny,allow\nDeny from all";
        @file_put_contents($log_dir . '/.htaccess', $htaccess);
    }
    
    // Configurar cron - carregamento seguro da classe
    require_once FTP_WOO_AUTO_INCLUDES_DIR . 'cron.php';
    $cron = new FTP_Woo_Cron();
    $cron->schedule();
    
    // Adicionar evento de limpeza se não existir
    if (!wp_next_scheduled('ftp_woo_cleanup_temp_files')) {
        wp_schedule_event(time(), 'daily', 'ftp_woo_cleanup_temp_files');
    }
    
    // Forçar a atualização dos rewrite rules
    flush_rewrite_rules();
}

/**
 * Função de desativação global
 */
function ftp_woo_auto_deactivation() {
    // Limpar cronogramas
    wp_clear_scheduled_hook('ftp_auto_scan_hook');
    wp_clear_scheduled_hook('ftp_woo_cleanup_temp_files');
    
    // Registrar log de desativação
    $log_entry = '[' . date('Y-m-d H:i:s') . '] Plugin desativado' . "\n";
    $recent_log = get_option('ftp_recent_log', '');
    update_option('ftp_recent_log', $log_entry . $recent_log);
}

/**
 * Classe principal do plugin
 */
class FTP_Woo_Auto {
    
    /** @var FTP_Woo_Admin Gerenciamento da interface administrativa */
    public $admin;
    
    /** @var FTP_Woo_FTP_Handler Gerenciamento de conexões FTP */
    public $ftp;
    
    /** @var FTP_Woo_Product_Creator Criador de produtos WooCommerce */
    public $product;
    
    /** @var FTP_Woo_Cron Gerenciamento de agendamento */
    public $cron;
    
    /** @var FTP_Woo_Auto Instância única da classe principal */
    private static $instance = null;
    
    /**
     * Retorna a instância única do plugin
     *
     * @return FTP_Woo_Auto
     */
    public static function instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * Construtor
     */
    private function __construct() {
        // Inicializar componentes
        $this->init_components();
        
        // Endpoints diretos
        add_action('init', array($this, 'register_endpoints'));
        
        // Limpar arquivos temporários periodicamente
        add_action('ftp_woo_cleanup_temp_files', array($this, 'cleanup_temp_files'));
    }
    
    /**
     * Inicializar componentes do plugin
     */
    private function init_components() {
        $this->admin = new FTP_Woo_Admin();
        $this->ftp = new FTP_Woo_FTP_Handler();
        $this->product = new FTP_Woo_Product_Creator();
        $this->cron = new FTP_Woo_Cron();
        
        // Adicionar AJAX handlers
        add_action('wp_ajax_ftp_woo_process_now', array($this, 'ajax_process_now'));
        add_action('wp_ajax_ftp_woo_fix_directory', array($this, 'fix_woo_directory')); // Nova linha
        add_action('wp_ajax_nopriv_ftp_woo_api_endpoint', array($this, 'api_endpoint'));
    }
    
    /**
     * Registrar endpoints personalizados
     */
    public function register_endpoints() {
        add_rewrite_rule(
            'ftp-woo-process/?([^/]*)/?',
            'index.php?ftp_woo_endpoint=process&key=$matches[1]',
            'top'
        );
        
        add_rewrite_tag('%ftp_woo_endpoint%', '([^&]+)');
        add_rewrite_tag('%key%', '([^&]+)');
        
        add_action('template_redirect', array($this, 'handle_endpoints'));
    }
    
    /**
     * Manipular endpoints personalizados
     */
    public function handle_endpoints() {
        global $wp_query;
        
        if (isset($wp_query->query_vars['ftp_woo_endpoint']) && 
            $wp_query->query_vars['ftp_woo_endpoint'] === 'process') {
            
            $key = isset($wp_query->query_vars['key']) ? $wp_query->query_vars['key'] : '';
            $stored_key = get_option('ftp_security_key', '');
            
            if ($key !== $stored_key) {
                status_header(403);
                die('Acesso negado');
            }
            
            $this->process_files();
            die('Processamento concluído');
        }
    }
    
    /**
     * Processar arquivos FTP (ponto de entrada principal)
     * 
     * @param bool $is_auto Se é automático ou manual
     * @return int Número de produtos criados
     */
    public function process_files($is_auto = true) {
        // Verificar se já existe um processo em andamento
        $lock_file = $this->get_lock_file_path();
        
        if (file_exists($lock_file)) {
            $lock_time = filemtime($lock_file);
            if (time() - $lock_time < 300) { // 5 minutos
                $this->log('Processo bloqueado: outro processo já está em andamento');
                return 0;
            }
            // Lock antigo, podemos remover
            @unlink($lock_file);
        }
        
        // Criar o arquivo de lock
        file_put_contents($lock_file, date('Y-m-d H:i:s'));
        
        try {
            $this->log('Iniciando processamento ' . ($is_auto ? 'automático' : 'manual'));
            
            // Conectar ao FTP
            if (!$this->ftp->connect()) {
                $this->log('Falha ao conectar ao servidor FTP');
                @unlink($lock_file);
                return 0;
            }
            
            // Processar arquivos
            $results = $this->ftp->process_directories($is_auto);
            
            // Fechar conexão FTP
            $this->ftp->disconnect();
            
            // Atualizar horário da última execução
            if ($is_auto) {
                update_option('ftp_last_auto_time', time());
            } else {
                update_option('ftp_last_process_time', time());
            }
            
            $this->log('Processamento concluído: ' . $results . ' produtos criados');
            
            // Remover arquivo de lock
            @unlink($lock_file);
            
            return $results;
            
        } catch (Exception $e) {
            $this->log('ERRO: ' . $e->getMessage());
            
            // Remover arquivo de lock em caso de erro
            @unlink($lock_file);
            
            return 0;
        }
    }
    
    /**
     * AJAX handler para processamento manual
     */
    public function ajax_process_now() {
        // Verificar nonce e permissões
        check_ajax_referer('ftp_woo_nonce', 'security');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Permissão negada');
            return;
        }
        
        $result = $this->process_files(false);
        wp_send_json_success(array('count' => $result));
    }
    
    /**
      * AJAX handler para corrigir diretório
      */
    public function fix_woo_directory() {
        // Verificar nonce e permissões
        check_ajax_referer('ftp_woo_nonce', 'security');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Permissão negada');
            return;
        }
        
        $upload_dir = wp_upload_dir();
        $target_dir = $upload_dir['basedir'] . '/woocommerce_uploads';
        
        if (!file_exists($target_dir)) {
            // Criar diretório
            $created = wp_mkdir_p($target_dir);
            if (!$created) {
                $this->log("ERRO: Não foi possível criar o diretório: {$target_dir}");
                wp_send_json_error('Falha ao criar diretório');
                return;
            }
        }
        
        // Ajustar permissões
        if (!is_writable($target_dir)) {
            $chmod_result = @chmod($target_dir, 0755);
            if (!$chmod_result) {
                $this->log("ERRO: Não foi possível ajustar permissões para: {$target_dir}");
                wp_send_json_error('Falha ao ajustar permissões');
                return;
            }
        }
        
        // Criar arquivo de teste para verificar
        $test_file = $target_dir . '/test-' . time() . '.txt';
        $test_content = 'Este é um teste de gravação. ' . date('Y-m-d H:i:s');
        
        if (file_put_contents($test_file, $test_content) === false) {
            $this->log("ERRO: Não foi possível gravar arquivo de teste em: {$target_dir}");
            wp_send_json_error('Falha ao gravar arquivo de teste');
            return;
        }
        
        // Remover arquivo de teste
        @unlink($test_file);
        
        $this->log("Diretório {$target_dir} criado e configurado com sucesso");
        wp_send_json_success();
    }

    /**
     * Endpoint API para processamento via cron externo
     */
    public function api_endpoint() {
        $key = isset($_GET['key']) ? sanitize_text_field($_GET['key']) : '';
        $stored_key = get_option('ftp_security_key', '');
        
        if ($key !== $stored_key) {
            wp_send_json_error('Chave inválida');
            return;
        }
        
        $result = $this->process_files(true);
        wp_send_json_success(array('count' => $result));
    }
    
    /**
     * Adicionar entrada ao log
     * 
     * @param string $message Mensagem para o log
     */
    public function log($message) {
        $upload_dir = wp_upload_dir();
        $log_dir = $upload_dir['basedir'] . '/ftp-woo-logs';
        $log_file = $log_dir . '/ftp-woo-' . date('Y-m-d') . '.log';
        
        $log_entry = '[' . date('Y-m-d H:i:s') . '] ' . $message . "\n";
        
        // Adicionar ao registro recente para exibição na interface
        $recent_log = get_option('ftp_recent_log', '');
        update_option('ftp_recent_log', $log_entry . $recent_log);
        
        // Salvar em arquivo se o diretório existir
        if (file_exists($log_dir)) {
            file_put_contents($log_file, $log_entry, FILE_APPEND);
        }
    }
    
    /**
     * Obter caminho para o arquivo de lock
     * 
     * @return string Caminho para o arquivo de lock
     */
    private function get_lock_file_path() {
        $upload_dir = wp_upload_dir();
        return $upload_dir['basedir'] . '/ftp_woo_process.lock';
    }
    
    /**
     * Limpar arquivos temporários
     */
    public function cleanup_temp_files() {
        $upload_dir = wp_upload_dir();
        $temp_dir = $upload_dir['basedir'] . '/ftp-woo-temp';
        
        if (!is_dir($temp_dir)) {
            return;
        }
        
        // Remover arquivos com mais de 24 horas
        $files = glob($temp_dir . '/*');
        if (!is_array($files)) {
            return;
        }
        
        $now = time();
        $count = 0;
        
        foreach ($files as $file) {
            if (is_file($file) && $now - filemtime($file) > 86400) {
                if (@unlink($file)) {
                    $count++;
                }
            }
        }
        
        if ($count > 0) {
            $this->log("Limpeza de arquivos temporários: {$count} arquivos removidos");
        }
    }
    
    /**
     * Realizar verificações periódicas de integridade do sistema
     */
    public function system_health_check() {
        // Verificar diretórios
        $upload_dir = wp_upload_dir();
        $dirs_to_check = array(
            'woocommerce_uploads' => $upload_dir['basedir'] . '/woocommerce_uploads',
            'ftp-woo-temp' => $upload_dir['basedir'] . '/ftp-woo-temp',
            'ftp-woo-logs' => $upload_dir['basedir'] . '/ftp-woo-logs'
        );
        
        $issues = 0;
        
        foreach ($dirs_to_check as $name => $dir) {
            if (!file_exists($dir)) {
                $this->log("SISTEMA: Diretório {$name} não existe. Tentando criar...");
                if (wp_mkdir_p($dir)) {
                    $this->log("SISTEMA: Diretório {$name} criado com sucesso");
                } else {
                    $this->log("SISTEMA: Falha ao criar diretório {$name}");
                    $issues++;
                }
            } elseif (!is_writable($dir)) {
                $this->log("SISTEMA: Diretório {$name} não tem permissão de escrita");
                $issues++;
            }
        }
        
        // Verificar agendamentos
        $timestamp = wp_next_scheduled('ftp_auto_scan_hook');
        if (!$timestamp && get_option('ftp_auto_enabled', 'yes') === 'yes') {
            $this->log("SISTEMA: Agendamento ausente. Tentando reagendar...");
            $this->cron->schedule();
        }
        
        // Verificar arquivo de lock
        $lock_file = $this->get_lock_file_path();
        if (file_exists($lock_file)) {
            $lock_time = filemtime($lock_file);
            if (time() - $lock_time > 3600) { // 1 hora
                $this->log("SISTEMA: Arquivo de lock antigo detectado. Removendo...");
                @unlink($lock_file);
            }
        }
        
        if ($issues > 0) {
            $this->log("SISTEMA: Verificação concluída com {$issues} problemas encontrados");
        } else {
            $this->log("SISTEMA: Verificação concluída. Nenhum problema encontrado");
        }
        
        return ($issues === 0);
    }
    
    /**
     * Verificar se os requisitos do sistema são atendidos
     * 
     * @return bool True se todos os requisitos são atendidos
     */
    public function check_system_requirements() {
        $requirements = array(
            'php_version' => version_compare(PHP_VERSION, '7.2', '>='),
            'ftp_ext' => function_exists('ftp_connect'),
            'woocommerce' => class_exists('WooCommerce'),
            'memory_limit' => $this->check_memory_limit(),
            'upload_dir' => $this->check_upload_directory()
        );
        
        $all_met = !in_array(false, $requirements);
        
        if (!$all_met) {
            $messages = array(
                'php_version' => 'PHP 7.2 ou superior é necessário',
                'ftp_ext' => 'Extensão FTP do PHP é necessária',
                'woocommerce' => 'WooCommerce deve estar ativo',
                'memory_limit' => 'Limite de memória PHP deve ser pelo menos 64MB',
                'upload_dir' => 'Diretório de uploads deve ter permissão de escrita'
            );
            
            foreach ($requirements as $key => $met) {
                if (!$met) {
                    $this->log("REQUISITO: " . $messages[$key] . " - NÃO ATENDIDO");
                }
            }
        }
        
        return $all_met;
    }
    
    /**
     * Verificar limite de memória
     * 
     * @return bool True se o limite for adequado
     */
    private function check_memory_limit() {
        $memory_limit = ini_get('memory_limit');
        
        // Converter para bytes
        $unit = strtoupper(substr($memory_limit, -1));
        $value = (int)$memory_limit;
        
        switch ($unit) {
            case 'G':
                $value *= 1024;
                // Continua para o próximo caso
            case 'M':
                $value *= 1024;
                // Continua para o próximo caso
            case 'K':
                $value *= 1024;
        }
        
        return $value >= 64 * 1024 * 1024; // 64MB
    }
    
    /**
     * Verificar diretório de uploads
     * 
     * @return bool True se o diretório for gravável
     */
    private function check_upload_directory() {
        $upload_dir = wp_upload_dir();
        return is_writable($upload_dir['basedir']);
    }
}