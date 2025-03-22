<?php
/**
 * Plugin Name: FTP to WooCommerce - Auto
 * Description: Converte arquivos FTP em produtos WooCommerce com monitoramento automático
 * Version: 1.2
 * Author: DevSpacek
 */

if (!defined('ABSPATH')) {
    exit;
}

class ftp_To_Woo_Auto {
    
    private $log = array();
    
    public function __construct() {
        // Verificar WooCommerce
        add_action('admin_init', array($this, 'check_woocommerce'));
        
        // Admin
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_init', array($this, 'register_settings'));
        add_action('admin_notices', array($this, 'admin_notices'));
        
        // Processamento
        add_action('admin_post_scan_ftp_folders', array($this, 'process_manual_scan'));
        
        // Automation - Cron job para automação
        add_action('ftp_auto_scan_hook', array($this, 'auto_scan_folders'));
        
        // Ativar cronograma na ativação do plugin
        register_activation_hook(__FILE__, array($this, 'activate_cron'));
        register_deactivation_hook(__FILE__, array($this, 'deactivate_cron'));
    }
    
    /**
     * Configurar agendamento CRON na ativação
     */
    public function activate_cron() {
        if (!wp_next_scheduled('ftp_auto_scan_hook')) {
            // Agendar para rodar conforme a frequência configurada
            $frequency = get_option('ftp_auto_frequency', 'hourly');
            wp_schedule_event(time(), $frequency, 'ftp_auto_scan_hook');
            update_option('ftp_auto_enabled', 'yes');
        }
    }
    
    /**
     * Remover agendamento CRON na desativação
     */
    public function deactivate_cron() {
        $timestamp = wp_next_scheduled('ftp_auto_scan_hook');
        if ($timestamp) {
            wp_unschedule_event($timestamp, 'ftp_auto_scan_hook');
        }
    }
    
    /**
     * Verificar WooCommerce
     */
    public function check_woocommerce() {
        if (!class_exists('WooCommerce')) {
            add_action('admin_notices', function() {
                echo '<div class="notice notice-error"><p>O plugin <strong>ftp to WooCommerce</strong> requer o WooCommerce ativo.</p></div>';
            });
        }
    }
    
    /**
     * Adicionar menu
     */
    public function add_admin_menu() {
        add_menu_page(
            'ftp para WooCommerce',
            'ftp para Woo',
            'manage_options',
            'ftp-to-woo-auto',
            array($this, 'render_admin_page'),
            'dashicons-upload',
            58
        );
    }
    
    /**
     * Registrar configurações
     */
    public function register_settings() {
        register_setting('ftp_to_woo_settings', 'ftp_base_directory');
        register_setting('ftp_to_woo_settings', 'ftp_default_price');
        register_setting('ftp_to_woo_settings', 'ftp_product_status', array(
            'default' => 'draft'
        ));
        register_setting('ftp_to_woo_settings', 'ftp_debug_mode', array(
            'default' => 'yes'
        ));
        register_setting('ftp_to_woo_settings', 'ftp_auto_enabled', array(
            'default' => 'yes'
        ));
        register_setting('ftp_to_woo_settings', 'ftp_auto_frequency', array(
            'default' => 'hourly'
         ));
        add_filter('cron_schedules', function($schedules) {
            $schedules['every_minute'] = array(
                'interval' => 60,
                'display' => __('A cada minuto')
            );
            return $schedules;
        });
    }
    
    /**
     * Adicionar notices no admin
     */
    public function admin_notices() {
        if (!isset($_GET['page']) || $_GET['page'] !== 'ftp-to-woo-auto') {
            return;
        }
        
        if (isset($_GET['processed'])) {
            $count = intval($_GET['processed']);
            echo '<div class="notice notice-success is-dismissible"><p>' . 
                 sprintf(_n('%s produto criado com sucesso!', '%s produtos criados com sucesso!', $count), number_format_i18n($count)) . 
                 '</p></div>';
        }
        
        if (isset($_GET['auto_processed'])) {
            $count = intval($_GET['auto_processed']);
            echo '<div class="notice notice-success is-dismissible"><p>' . 
                 'O processamento automático foi executado e criou ' . 
                 sprintf(_n('%s produto', '%s produtos', $count), number_format_i18n($count)) . 
                 '</p></div>';
        }
        
        if (isset($_GET['error'])) {
            if ($_GET['error'] === 'directory') {
                echo '<div class="notice notice-error is-dismissible"><p>O diretório base não existe ou não é acessível.</p></div>';
            }
        }
        
        if (isset($_GET['debug_log'])) {
            $log = get_transient('ftp_woo_debug_log');
            if ($log) {
                echo '<div class="notice notice-info is-dismissible"><p>Log de depuração:</p><pre style="background:#f8f8f8;padding:10px;max-height:400px;overflow-y:auto;">' . esc_html($log) . '</pre></div>';
            }
        }
        
        // Aviso sobre status do CRON
        if (get_option('ftp_auto_enabled', 'yes') === 'yes') {
            $next_run = wp_next_scheduled('ftp_auto_scan_hook');
            if ($next_run) {
                $time_diff = $next_run - time();
                if ($time_diff > 0) {
                    echo '<div class="notice notice-info is-dismissible"><p>' . 
                         '<strong>Automação ativa!</strong> Próximo escaneamento automático em: ' . 
                         human_time_diff(time(), $next_run) . ' (' . date('d/m/Y H:i:s', $next_run) . ')' . 
                         '</p></div>';
                }
            }
        }
    }
    
    /**
     * Renderizar página de admin
     */
    public function render_admin_page() {
        // Checar permissões
        if (!current_user_can('manage_options')) {
            wp_die('Acesso negado');
        }
        
        // Obter configurações
        $base_dir = get_option('ftp_base_directory', '');
        $default_price = get_option('ftp_default_price', '10');
        $product_status = get_option('ftp_product_status', 'draft');
        $debug_mode = get_option('ftp_debug_mode', 'yes');
        $auto_enabled = get_option('ftp_auto_enabled', 'yes');
        $auto_frequency = get_option('ftp_auto_frequency', 'hourly');
        
        // Verificar status do WooCommerce
        $woo_active = class_exists('WooCommerce');
        $wc_product_class = class_exists('WC_Product');
        $woo_version = $woo_active ? WC()->version : 'N/A';
        
        ?>
        <div class="wrap">
            <h1>ftp para WooCommerce (Automático)</h1>
            
            <div class="notice notice-info">
                <p><strong>Status do Sistema:</strong></p>
                <ul style="list-style:disc;padding-left:20px;">
                    <li>WooCommerce Ativo: <?php echo $woo_active ? '<span style="color:green;">✓ Sim</span>' : '<span style="color:red;">✗ Não</span>'; ?></li>
                    <li>Versão do WooCommerce: <?php echo esc_html($woo_version); ?></li>
                    <li>Último processamento manual: <?php echo get_option('ftp_last_process_time') ? date('d/m/Y H:i:s', get_option('ftp_last_process_time')) : 'Nunca'; ?></li>
                    <li>Último processamento automático: <?php echo get_option('ftp_last_auto_time') ? date('d/m/Y H:i:s', get_option('ftp_last_auto_time')) : 'Nunca'; ?></li>
                    <li>Automação: <?php echo $auto_enabled === 'yes' ? '<span style="color:green;">✓ Ativa</span>' : '<span style="color:orange;">✗ Desativada</span>'; ?></li>
                    <?php if ($auto_enabled === 'yes'): ?>
                        <?php 
                        $next_run = wp_next_scheduled('ftp_auto_scan_hook');
                        if ($next_run):
                        ?>
                            <li>Próximo escaneamento: <?php echo date('d/m/Y H:i:s', $next_run); ?> (em <?php echo human_time_diff(time(), $next_run); ?>)</li>
                        <?php else: ?>
                            <li>Cronograma: <span style="color:red;">Não agendado</span> - Salve as configurações para reativar</li>
                        <?php endif; ?>
                    <?php endif; ?>
                </ul>
            </div>
            
            <?php if (!$woo_active): ?>
                <div class="notice notice-error">
                    <p>O WooCommerce precisa estar instalado e ativado para que este plugin funcione.</p>
                </div>
            <?php endif; ?>
            
            <form method="post" action="options.php">
                <?php settings_fields('ftp_to_woo_settings'); ?>
                
                <table class="form-table">
                    <tr>
                        <th scope="row">Diretório Base ftp</th>
                        <td>
                            <input type="text" name="ftp_base_directory" value="<?php echo esc_attr($base_dir); ?>" class="regular-text" />
                            <p class="description">Caminho absoluto para o diretório onde estão as pastas dos clientes</p>
                            <?php
                            if (!empty($base_dir)) {
                                if (file_exists($base_dir)) {
                                    echo '<p style="color: green;">✓ Diretório existe</p>';
                                    $is_readable = is_readable($base_dir);
                                    echo '<p>' . ($is_readable ? 
                                        '<span style="color: green;">✓ Diretório pode ser lido</span>' : 
                                        '<span style="color: red;">✗ Diretório não pode ser lido</span>') . '</p>';
                                    
                                    // Listar pastas
                                    if ($is_readable) {
                                        $dirs = glob($base_dir . '/*', GLOB_ONLYDIR);
                                        if ($dirs) {
                                            echo '<p>Encontradas ' . count($dirs) . ' pastas de cliente</p>';
                                        } else {
                                            echo '<p style="color: orange;">Nenhuma pasta de cliente encontrada no diretório.</p>';
                                        }
                                    }
                                } else {
                                    echo '<p style="color: red;">✗ Diretório não encontrado</p>';
                                }
                            }
                            ?>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row">Preço Padrão</th>
                        <td>
                            <input type="text" name="ftp_default_price" value="<?php echo esc_attr($default_price); ?>" class="small-text" />
                            <p class="description">Preço padrão para os produtos criados</p>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row">Status dos Produtos</th>
                        <td>
                            <select name="ftp_product_status">
                                <option value="draft" <?php selected($product_status, 'draft'); ?>>Rascunho</option>
                                <option value="publish" <?php selected($product_status, 'publish'); ?>>Publicado</option>
                                <option value="private" <?php selected($product_status, 'private'); ?>>Privado</option>
                                <option value="pending" <?php selected($product_status, 'pending'); ?>>Pendente</option>
                            </select>
                            <p class="description">Status dos produtos quando criados</p>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row">Automação</th>
                        <td>
                            <select name="ftp_auto_enabled">
                                <option value="yes" <?php selected($auto_enabled, 'yes'); ?>>Ativa</option>
                                <option value="no" <?php selected($auto_enabled, 'no'); ?>>Desativada</option>
                            </select>
                            <p class="description">Ativar verificação automática de novas pastas/arquivos</p>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row">Frequência da Verificação</th>
                        <td>
                            <select name="ftp_auto_frequency">
                                <option value="every_minute" <?php selected($auto_frequency, 'every_minute'); ?>>A cada minuto</option>
                                <option value="hourly" <?php selected($auto_frequency, 'hourly'); ?>>A cada hora</option>
                                <option value="twicedaily" <?php selected($auto_frequency, 'twicedaily'); ?>>Duas vezes por dia</option>
                                <option value="daily" <?php selected($auto_frequency, 'daily'); ?>>Diariamente</option>
                                <option value="weekly" <?php selected($auto_frequency, 'weekly'); ?>>Semanalmente</option>
                            </select>
                            <p class="description">Com que frequência verificar automaticamente arquivos novos</p>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row">Modo de Depuração</th>
                        <td>
                            <select name="ftp_debug_mode">
                                <option value="yes" <?php selected($debug_mode, 'yes'); ?>>Ativado</option>
                                <option value="no" <?php selected($debug_mode, 'no'); ?>>Desativado</option>
                            </select>
                            <p class="description">Exibe informações detalhadas durante o processamento</p>
                        </td>
                    </tr>
                </table>
                
                <?php submit_button('Salvar Configurações'); ?>
            </form>
            
            <hr>
            
            <h2>Processamento Manual</h2>
            <p>Clique no botão abaixo para escanear as pastas ftp e criar produtos para novos arquivos imediatamente:</p>
            
            <form method="post" action="<?php echo admin_url('admin-post.php'); ?>">
                <input type="hidden" name="action" value="scan_ftp_folders">
                <?php wp_nonce_field('scan_ftp_folders_nonce'); ?>
                <?php submit_button('Escanear Pastas Agora', 'primary', 'submit', false); ?>
            </form>
            
            <?php if ($woo_active): ?>
            <div style="margin-top:20px;padding:15px;background:#f8f8f8;border:1px solid #ddd;">
                <h3>Últimos Produtos Processados</h3>
                <?php
                $processed_files = get_option('ftp_processed_files', array());
                
                if (!empty($processed_files)) {
                    $recent_files = array_slice($processed_files, -10, 10, true);
                    echo '<table class="widefat striped" style="margin-top:10px;">';
                    echo '<thead><tr><th>Arquivo</th><th>ID Produto</th><th>Data</th><th>Método</th></tr></thead>';
                    echo '<tbody>';
                    
                    foreach ($recent_files as $hash => $info) {
                        $method = isset($info['auto']) && $info['auto'] ? 'Automático' : 'Manual';
                        
                        echo '<tr>';
                        echo '<td>' . esc_html($info['file']) . '</td>';
                        echo '<td><a href="' . admin_url('post.php?post=' . $info['product_id'] . '&action=edit') . '">' . esc_html($info['product_id']) . '</a></td>';
                        echo '<td>' . date('d/m/Y H:i:s', $info['time']) . '</td>';
                        echo '<td>' . $method . '</td>';
                        echo '</tr>';
                    }
                    
                    echo '</tbody></table>';
                    
                    // Total processado
                    echo '<p style="margin-top:15px;">Total de arquivos processados: <strong>' . count($processed_files) . '</strong></p>';
                } else {
                    echo '<p>Nenhum arquivo processado ainda.</p>';
                }
                ?>
            </div>
            <?php endif; ?>
            
            <?php
            // Exibir log recente se disponível
            $recent_log = get_option('ftp_recent_log', '');
            if (!empty($recent_log)):
            ?>
            <div style="margin-top:20px;">
                <h3>Log de Atividades Recentes</h3>
                <div style="max-height:300px;overflow-y:auto;background:#f5f5f5;padding:10px;border:1px solid #ddd;">
                    <pre style="margin:0;white-space:pre-wrap;"><?php echo esc_html($recent_log); ?></pre>
                </div>
            </div>
            <?php endif; ?>
        </div>
        <?php
        
        // Se as configurações forem alteradas, reagendar cron se necessário
        add_action('update_option_ftp_auto_enabled', array($this, 'maybe_reschedule_cron'), 10, 2);
        add_action('update_option_ftp_auto_frequency', array($this, 'maybe_reschedule_cron'), 10, 2);
    }
    
    /**
     * Reagendar CRON quando configurações mudarem
     */
    public function maybe_reschedule_cron($old_value, $new_value) {
        // Primeiro remover qualquer agendamento existente
        $timestamp = wp_next_scheduled('ftp_auto_scan_hook');
        if ($timestamp) {
            wp_unschedule_event($timestamp, 'ftp_auto_scan_hook');
        }
        
        // Se automação estiver ativa, agendar novamente
        if (get_option('ftp_auto_enabled', 'yes') === 'yes') {
            $frequency = get_option('ftp_auto_frequency', 'hourly');
            wp_schedule_event(time(), $frequency, 'ftp_auto_scan_hook');
        }
    }
    
    /**
     * Processar scan manual
     */
    public function process_manual_scan() {
        // Verificar nonce
        check_admin_referer('scan_ftp_folders_nonce');
        
        // Verificar permissões
        if (!current_user_can('manage_options')) {
            wp_die('Acesso negado');
        }
        
        // Limpar log
        $this->log = array();
        $this->add_log("Iniciando processamento MANUAL: " . date('d/m/Y H:i:s'));
        
        // Verificar WooCommerce
        if (!class_exists('WooCommerce')) {
            $this->add_log("ERRO: WooCommerce não está ativo");
            $this->save_debug_log();
            wp_redirect(add_query_arg(array(
                'page' => 'ftp-to-woo-auto',
                'error' => 'woocommerce',
                'debug_log' => '1'
            ), admin_url('admin.php')));
            exit;
        }
        
        // Obter diretório base
        $base_dir = get_option('ftp_base_directory', '');
        $this->add_log("Diretório base: $base_dir");
        
        if (empty($base_dir) || !file_exists($base_dir) || !is_dir($base_dir)) {
            $this->add_log("ERRO: Diretório base inválido ou inacessível");
            $this->save_debug_log();
            wp_redirect(add_query_arg(array(
                'page' => 'ftp-to-woo-auto',
                'error' => 'directory',
                'debug_log' => '1'
            ), admin_url('admin.php')));
            exit;
        }
        
        // Iniciar processamento
        $result = $this->scan_directories($base_dir);
        
        // Atualizar hora do último processamento
        update_option('ftp_last_process_time', time());
        
        // Adicionar resultado final ao log
        $this->add_log("Processamento concluído. Total: $result produtos criados");
        $this->save_debug_log();
        
        // Redirecionar com resultado
        wp_redirect(add_query_arg(array(
            'page' => 'ftp-to-woo-auto',
            'processed' => $result,
            'debug_log' => '1'
        ), admin_url('admin.php')));
        exit;
    }
    
    /**
     * Método para escaneamento automático via CRON
     */
    public function auto_scan_folders() {
        // Verificar se automação está ativada
        if (get_option('ftp_auto_enabled', 'yes') !== 'yes') {
            return;
        }
        
        // Limpar log
        $this->log = array();
        $this->add_log("Iniciando processamento AUTOMÁTICO: " . date('d/m/Y H:i:s'));
        
        // Verificar WooCommerce
        if (!class_exists('WooCommerce')) {
            $this->add_log("ERRO: WooCommerce não está ativo - escaneamento automático abortado");
            $this->save_activity_log();
            return;
        }
        
        // Obter diretório base
        $base_dir = get_option('ftp_base_directory', '');
        $this->add_log("Diretório base: $base_dir");
        
        if (empty($base_dir) || !file_exists($base_dir) || !is_dir($base_dir)) {
            $this->add_log("ERRO: Diretório base inválido ou inacessível - escaneamento automático abortado");
            $this->save_activity_log();
            return;
        }
        
        // Executar escaneamento
        $result = $this->scan_directories($base_dir, true);
        
        // Atualizar hora do último processamento automático
        update_option('ftp_last_auto_time', time());
        
        // Adicionar resultado ao log
        $this->add_log("Processamento automático concluído. Total: $result produtos criados");
        
        // Salvar log de atividade
        $this->save_activity_log();
        
        return $result;
    }
    
    /**
     * Escanear diretórios
     */
    private function scan_directories($base_dir, $auto_mode = false) {
        $processed = 0;
        
        // Listar pastas de clientes
        $client_folders = glob($base_dir . '/*', GLOB_ONLYDIR);
        $this->add_log("Encontradas " . count($client_folders) . " pastas de cliente");
        
        foreach ($client_folders as $client_folder) {
            $client_name = basename($client_folder);
            $this->add_log("Processando cliente: $client_name");
            
            // Verificar se a pasta do cliente é acessível
            if (!is_readable($client_folder)) {
                $this->add_log("AVISO: Pasta do cliente não pode ser lida: $client_name");
                continue;
            }
            
            $client_processed = $this->process_client_folder($client_folder, $client_name, $auto_mode);
            $processed += $client_processed;
            
            if ($client_processed > 0) {
                $this->add_log("Cliente $client_name: $client_processed arquivos processados");
            } else {
                $this->add_log("Cliente $client_name: nenhum arquivo novo encontrado");
            }
        }
        
        return $processed;
    }
    
    /**
     * Processar pasta do cliente
     */
    private function process_client_folder($folder_path, $client_name, $auto_mode = false) {
        $processed = 0;
        
        // Listar arquivos (incluindo em subpastas)
        $files = $this->get_all_files($folder_path);
        $this->add_log("Encontrados " . count($files) . " arquivos para o cliente $client_name");
        
        // Lista de arquivos já processados
        $processed_files = get_option('ftp_processed_files', array());
        
        foreach ($files as $file_path) {
            // Ignorar diretórios
            if (is_dir($file_path)) {
                continue;
            }
            
            $file_name = basename($file_path);
            
            // Criar hash único para o arquivo baseado no caminho e data de modificação
            $file_hash = md5($file_path . filemtime($file_path));
            
            // Verificar se já processamos este arquivo
            if (isset($processed_files[$file_hash])) {
                continue;
            }
            
            $this->add_log("Criando produto para: $file_name");
            
            // Criar produto para este arquivo
            $product_id = $this->create_product_for_file($file_path, $client_name);
            
            if ($product_id) {
                $this->add_log("SUCESSO: Produto criado com ID: $product_id");
                
                // Marcar arquivo como processado
                $processed_files[$file_hash] = array(
                    'file' => $file_name,
                    'product_id' => $product_id,
                    'time' => time(),
                    'auto' => $auto_mode
                );
                
                $processed++;
            } else {
                $this->add_log("ERRO: Falha ao criar produto para: $file_name");
            }
        }
        
        // Salvar lista atualizada
        update_option('ftp_processed_files', $processed_files);
        
        return $processed;
    }
    
    /**
     * Obter todos os arquivos, incluindo em subpastas
     */
    private function get_all_files($dir) {
        $files = array();
        
        // Diretório raiz
        $dir_files = glob($dir . '/*');
        if (is_array($dir_files)) {
            foreach ($dir_files as $file) {
                if (is_dir($file)) {
                    // É uma subpasta, buscar arquivos recursivamente
                    $sub_files = $this->get_all_files($file);
                    $files = array_merge($files, $sub_files);
                } else {
                    // É um arquivo, adicionar à lista
                    $files[] = $file;
                }
            }
        }
        
        return $files;
    }
    
    /**
     * Criar produto WooCommerce para o arquivo
     */
    private function create_product_for_file($file_path, $client_name) {
        // Informações do arquivo
        $file_name = basename($file_path);
        $file_ext = pathinfo($file_path, PATHINFO_EXTENSION);
        $file_size = size_format(filesize($file_path));
        
        $this->add_log("Processando arquivo: {$file_name} ({$file_size})");
        
        // Gerar título do produto
        $title = $this->generate_product_title($file_name, $client_name);
        
        // Configurações do produto
        $price = get_option('ftp_default_price', '0');
        $status = get_option('ftp_product_status', 'draft');
        
        // Verificar WooCommerce
        if (!class_exists('WC_Product')) {
            $this->add_log("ERRO: Classe WC_Product não existe");
            return false;
        }
        
        try {
            // Criar objeto do produto
            $product = new WC_Product();
            $product->set_name($title);
            
            // Descrição
            $description = "Arquivo: {$file_name}\n";
            $description .= "Tipo: " . strtoupper($file_ext) . "\n";
            $description .= "Tamanho: {$file_size}\n";
            $description .= "Cliente: {$client_name}\n";
            $description .= "Data: " . date('d/m/Y H:i:s');
            
            $product->set_description($description);
            $product->set_short_description("Arquivo {$file_ext} do cliente {$client_name}");
            
            // Configurações básicas
            $product->set_status($status);
            $product->set_catalog_visibility('visible');
            $product->set_price($price);
            $product->set_regular_price($price);
            
            // Configurar como virtual e downloadable
            $product->set_virtual(true);
            $product->set_downloadable(true);
            
            // Preparar download
            $upload_dir = wp_upload_dir();
            $target_dir = $upload_dir['basedir'] . '/woocommerce_uploads/';
            
            if (!file_exists($target_dir)) {
                wp_mkdir_p($target_dir);
            }
            
            $new_file_name = uniqid($client_name . '_') . '_' . $file_name;
            $target_path = $target_dir . $new_file_name;
            
            if (copy($file_path, $target_path)) {
                $download_url = $upload_dir['baseurl'] . '/woocommerce_uploads/' . $new_file_name;
                $download = array(
                    'id' => md5($target_path),
                    'name' => $file_name,
                    'file' => $download_url
                );
            
                $product->set_downloads(array($download));
                $product->set_download_limit(-1); // Sem limite
                $product->set_download_expiry(-1); // Sem expiração
            } else {
                return false;
            }
            
            // Salvar produto
            $product_id = $product->save();
            
            if (!$product_id) {
                return false;
            }
            
            // Adicionar metadados
            update_post_meta($product_id, '_ftp_source', $file_path);
            update_post_meta($product_id, '_ftp_client', $client_name);
            
            // Verificar se o produto foi criado corretamente
            $saved_product = wc_get_product($product_id);
            if (!$saved_product) {
                return false;
            }
            
            return $product_id;
        } catch (Exception $e) {
            $this->add_log("EXCEÇÃO: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Gerar título para o produto
     */
    private function generate_product_title($file_name, $client_name) {
        // Remover extensão
        $title = pathinfo($file_name, PATHINFO_FILENAME);
        
        // Substituir underscores e hífens por espaços
        $title = str_replace(['_', '-'], ' ', $title);
        
        // Capitalizar palavras
        $title = ucwords($title);
        
        return $title . ' - ' . $client_name;
    }
    
    /**
     * Adicionar entrada ao log
     */
    private function add_log($message) {
        $this->log[] = '[' . date('H:i:s') . '] ' . $message;
        
        // Se modo debug estiver ativado, também gravar em arquivo
        if (get_option('ftp_debug_mode', 'yes') === 'yes') {
            error_log('ftp_WOO: ' . $message);
        }
    }
    
    /**
     * Salvar log para exibição
     */
    private function save_debug_log() {
        $log_text = implode("\n", $this->log);
        set_transient('ftp_woo_debug_log', $log_text, HOUR_IN_SECONDS);
    }
    
    /**
     * Salvar log de atividade
     */
    private function save_activity_log() {
        $log_text = implode("\n", $this->log);
        update_option('ftp_recent_log', $log_text);
    }
}

// Inicializar plugin de forma segura
add_action('plugins_loaded', function() {
    if (class_exists('WooCommerce')) {
        new ftp_To_Woo_Auto();
    }
});