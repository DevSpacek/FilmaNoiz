<?php
/**
 * Plugin Name: SFTP to WooCommerce - Ultra Reliable Auto
 * Description: Converte arquivos SFTP em produtos WooCommerce com automação ultra confiável
 * Version: 2.0
 * Author: DevSpacek
 */

if (!defined('ABSPATH')) {
    exit;
}

class SFTP_To_Woo_Ultra {
    
    private $log = array();
    private $lock_file;
    private $direct_access = false;
    
    public function __construct() {
        // Arquivo de lock para evitar execuções simultâneas
        $upload_dir = wp_upload_dir();
        $this->lock_file = $upload_dir['basedir'] . '/sftp_woo_process.lock';
        
        // Adicionar intervalo de cron personalizado para cada minuto
        add_filter('cron_schedules', array($this, 'add_cron_interval'));
        
        // Admin
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_init', array($this, 'register_settings'));
        add_action('admin_notices', array($this, 'admin_notices'));
        
        // Processamento
        add_action('admin_post_scan_sftp_folders', array($this, 'process_manual_scan'));
        add_action('wp_ajax_trigger_sftp_scan', array($this, 'ajax_trigger_scan'));
        add_action('wp_ajax_nopriv_trigger_sftp_scan', array($this, 'ajax_trigger_scan_nopriv'));
        
        // Métodos múltiplos de automação
        add_action('init', array($this, 'check_direct_access'));
        add_action('init', array($this, 'maybe_schedule_cron'));
        add_action('sftp_auto_scan_hook', array($this, 'auto_scan_folders'));
        add_action('init', array($this, 'maybe_force_scan'));
        
        // Cron events
        register_activation_hook(__FILE__, array($this, 'activate_cron'));
        register_deactivation_hook(__FILE__, array($this, 'deactivate_cron'));
    }
    
    /**
     * Verificar acesso direto (para endpoint de API)
     */
    public function check_direct_access() {
        // Se estamos em uma requisição específica para nosso plugin
        if (isset($_GET['sftp_direct_scan']) && isset($_GET['key'])) {
            $this->direct_access = true;
            $stored_key = get_option('sftp_security_key', '');
            
            // Se a chave estiver vazia, gerar uma nova
            if (empty($stored_key)) {
                $stored_key = md5(time() . rand(1000, 9999));
                update_option('sftp_security_key', $stored_key);
            }
            
            // Verificar chave de segurança
            if ($_GET['key'] === $stored_key) {
                // Executar o processamento
                $this->direct_execution();
                exit;
            } else {
                echo "Chave de acesso inválida";
                exit;
            }
        }
    }
    
    /**
     * Execução direta via URL
     */
    public function direct_execution() {
        // Verificar se já existe um processo rodando
        if ($this->is_process_locked()) {
            echo "LOCKED: Outro processamento já está em andamento.";
            return;
        }
        
        // Executar o processamento
        echo "INICIANDO PROCESSAMENTO DIRETO...\n\n";
        $count = $this->auto_scan_folders(true);
        echo "\nPROCESSAMENTO CONCLUÍDO. PRODUTOS CRIADOS: " . $count;
    }
    
    /**
     * Adicionar intervalo de cron personalizado
     */
    public function add_cron_interval($schedules) {
        $schedules['minutely'] = array(
            'interval' => 60,
            'display'  => 'A cada minuto'
        );
        $schedules['every5minutes'] = array(
            'interval' => 300,
            'display'  => 'A cada 5 minutos'
        );
        return $schedules;
    }
    
    /**
     * Garantir que o cron esteja agendado (chamado em cada carregamento)
     */
    public function maybe_schedule_cron() {
        if (get_option('sftp_auto_enabled', 'yes') === 'yes') {
            $timestamp = wp_next_scheduled('sftp_auto_scan_hook');
            if (!$timestamp) {
                $frequency = get_option('sftp_auto_frequency', 'minutely');
                wp_schedule_event(time(), $frequency, 'sftp_auto_scan_hook');
            }
        }
    }
    
    /**
     * Configurar agendamento CRON na ativação
     */
    public function activate_cron() {
        // Primeiro desativar qualquer agendamento existente
        $this->deactivate_cron();
        
        // Gerar chave de segurança para acesso direto
        if (empty(get_option('sftp_security_key', ''))) {
            update_option('sftp_security_key', md5(time() . rand(1000, 9999)));
        }
        
        // Agendar para rodar a cada minuto por padrão
        wp_schedule_event(time(), 'minutely', 'sftp_auto_scan_hook');
        update_option('sftp_auto_enabled', 'yes');
        update_option('sftp_auto_frequency', 'minutely');
        
        // Registrar ativação no log
        $this->log = array();
        $this->add_log("Plugin ativado: escaneamento configurado para cada minuto");
        $this->save_activity_log();
    }
    
    /**
     * Remover agendamento CRON na desativação
     */
    public function deactivate_cron() {
        $timestamp = wp_next_scheduled('sftp_auto_scan_hook');
        if ($timestamp) {
            wp_unschedule_event($timestamp, 'sftp_auto_scan_hook');
        }
    }
    
    /**
     * Adicionar menu
     */
    public function add_admin_menu() {
        add_menu_page(
            'SFTP para WooCommerce',
            'SFTP para Woo',
            'manage_options',
            'sftp-to-woo-auto',
            array($this, 'render_admin_page'),
            'dashicons-upload',
            58
        );
    }
    
    /**
     * Registrar configurações
     */
    public function register_settings() {
        register_setting('sftp_to_woo_settings', 'sftp_base_directory');
        register_setting('sftp_to_woo_settings', 'sftp_default_price');
        register_setting('sftp_to_woo_settings', 'sftp_product_status', array(
            'default' => 'publish'
        ));
        register_setting('sftp_to_woo_settings', 'sftp_auto_enabled', array(
            'default' => 'yes'
        ));
        register_setting('sftp_to_woo_settings', 'sftp_auto_frequency', array(
            'default' => 'minutely'
        ));
        register_setting('sftp_to_woo_settings', 'sftp_force_minutes', array(
            'default' => '1'
        ));
    }
    
    /**
     * Adicionar notices no admin
     */
    public function admin_notices() {
        if (!isset($_GET['page']) || $_GET['page'] !== 'sftp-to-woo-auto') {
            return;
        }
        
        if (isset($_GET['processed'])) {
            $count = intval($_GET['processed']);
            echo '<div class="notice notice-success is-dismissible"><p>' . 
                 sprintf(_n('%s produto criado com sucesso!', '%s produtos criados com sucesso!', $count), number_format_i18n($count)) . 
                 '</p></div>';
        }
    }
    
    /**
     * AJAX para acionar scan
     */
    public function ajax_trigger_scan() {
        check_ajax_referer('sftp-ajax-nonce', 'security');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Permissão negada');
            return;
        }
        
        $result = $this->auto_scan_folders(true);
        wp_send_json_success(array('count' => $result));
    }
    
    /**
     * AJAX para acionar scan (sem login)
     */
    public function ajax_trigger_scan_nopriv() {
        if (!isset($_GET['key']) || $_GET['key'] !== get_option('sftp_security_key', '')) {
            wp_send_json_error('Chave de acesso inválida');
            return;
        }
        
        $result = $this->auto_scan_folders(true);
        wp_send_json_success(array('count' => $result));
    }

    /**
     * Método alternativo para forçar escaneamento baseado em tráfego
     */
    public function maybe_force_scan() {
        // Apenas executar em requisições normais (não em AJAX, admin, etc)
        if (wp_doing_ajax() || (is_admin() && !$this->direct_access) || (defined('WP_CLI') && WP_CLI) || (defined('DOING_CRON') && DOING_CRON)) {
            return;
        }
        
        // Verificar se automação está ativa
        if (get_option('sftp_auto_enabled', 'yes') !== 'yes') {
            return;
        }
        
        // Obter tempo da última execução
        $last_time = get_option('sftp_last_auto_time', 0);
        $force_minutes = intval(get_option('sftp_force_minutes', 1));
        $force_seconds = $force_minutes * 60;
        
        // Verificar se já passou tempo suficiente desde a última execução
        if (time() - $last_time < $force_seconds) {
            return;
        }
        
        // Verificar se já existe um processo rodando
        if ($this->is_process_locked()) {
            return;
        }
        
        // Executar o processamento
        $this->auto_scan_folders(true);
    }
    
    /**
     * Verificar e criar bloqueio de processo
     */
    private function is_process_locked() {
        if (file_exists($this->lock_file)) {
            $lock_time = filemtime($this->lock_file);
            if (time() - $lock_time < 300) { // 5 minutos
                return true; // Processo está bloqueado
            }
            // Lock muito antigo, remover
            @unlink($this->lock_file);
        }
        
        // Criar um novo arquivo de lock
        @file_put_contents($this->lock_file, date('Y-m-d H:i:s'));
        return false; // Processo não está bloqueado
    }
    
    /**
     * Liberar bloqueio de processo
     */
    private function release_process_lock() {
        if (file_exists($this->lock_file)) {
            @unlink($this->lock_file);
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
        $base_dir = get_option('sftp_base_directory', '');
        $default_price = get_option('sftp_default_price', '10');
        $product_status = get_option('sftp_product_status', 'publish');
        $auto_enabled = get_option('sftp_auto_enabled', 'yes');
        $auto_frequency = get_option('sftp_auto_frequency', 'minutely');
        $force_minutes = get_option('sftp_force_minutes', '1');
        $security_key = get_option('sftp_security_key', '');
        
        // Se a chave estiver vazia, gerar uma nova
        if (empty($security_key)) {
            $security_key = md5(time() . rand(1000, 9999));
            update_option('sftp_security_key', $security_key);
        }
        
        // URL direta para processamento
        $direct_url = add_query_arg(array(
            'sftp_direct_scan' => '1',
            'key' => $security_key
        ), site_url('/'));
        
        // Verificar status do WooCommerce
        $woo_active = class_exists('WooCommerce');
        $next_run = wp_next_scheduled('sftp_auto_scan_hook');
        
        ?>
        <div class="wrap">
            <h1>SFTP para WooCommerce (Ultra Confiável)</h1>
            
            <div class="notice notice-success">
                <p><strong>Status do Sistema:</strong></p>
                <ul style="list-style:disc;padding-left:20px;">
                    <li>WooCommerce: <?php echo $woo_active ? '<span style="color:green;">✓ Ativo</span>' : '<span style="color:red;">✗ Inativo</span>'; ?></li>
                    <li>Data atual do servidor: <?php echo date('Y-m-d H:i:s'); ?></li>
                    <li>Último processamento manual: <?php echo get_option('sftp_last_process_time') ? date('d/m/Y H:i:s', get_option('sftp_last_process_time')) : 'Nunca'; ?></li>
                    <li>Último processamento automático: <?php echo get_option('sftp_last_auto_time') ? date('d/m/Y H:i:s', get_option('sftp_last_auto_time')) : 'Nunca'; ?></li>
                    <li>Próximo agendamento WP-Cron: <?php echo $next_run ? date('d/m/Y H:i:s', $next_run) : 'Não agendado'; ?></li>
                </ul>
            </div>
            
            <h2>Configuração Básica</h2>
            <form method="post" action="options.php">
                <?php settings_fields('sftp_to_woo_settings'); ?>
                
                <table class="form-table">
                    <tr>
                        <th scope="row">Diretório Base SFTP</th>
                        <td>
                            <input type="text" name="sftp_base_directory" value="<?php echo esc_attr($base_dir); ?>" class="regular-text" />
                            <p class="description">Caminho absoluto para o diretório onde estão as pastas dos clientes</p>
                            <?php
                            if (!empty($base_dir)) {
                                if (file_exists($base_dir)) {
                                    echo '<p style="color:green;">✓ Diretório existe</p>';
                                    
                                    if (is_readable($base_dir)) {
                                        echo '<p style="color:green;">✓ Diretório pode ser lido</p>';
                                        
                                        $dirs = glob($base_dir . '/*', GLOB_ONLYDIR);
                                        if (is_array($dirs) && !empty($dirs)) {
                                            echo '<p>Encontradas ' . count($dirs) . ' pastas de cliente</p>';
                                        } else {
                                            echo '<p style="color:orange;">Nenhuma pasta de cliente encontrada</p>';
                                        }
                                    } else {
                                        echo '<p style="color:red;">✗ ERRO: Diretório não pode ser lido! Verifique permissões.</p>';
                                    }
                                } else {
                                    echo '<p style="color:red;">✗ ERRO: Diretório não existe!</p>';
                                }
                            }
                            ?>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row">Preço Padrão</th>
                        <td>
                            <input type="text" name="sftp_default_price" value="<?php echo esc_attr($default_price); ?>" class="small-text" />
                            <p class="description">Preço padrão para os produtos criados</p>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row">Status dos Produtos</th>
                        <td>
                            <select name="sftp_product_status">
                                <option value="publish" <?php selected($product_status, 'publish'); ?>>Publicado</option>
                                <option value="draft" <?php selected($product_status, 'draft'); ?>>Rascunho</option>
                                <option value="private" <?php selected($product_status, 'private'); ?>>Privado</option>
                            </select>
                            <p class="description">Status dos produtos quando criados</p>
                        </td>
                    </tr>
                </table>
                
                <h3>Configuração de Automação</h3>
                <table class="form-table">
                    <tr>
                        <th scope="row">Automação</th>
                        <td>
                            <select name="sftp_auto_enabled">
                                <option value="yes" <?php selected($auto_enabled, 'yes'); ?>>Ativa</option>
                                <option value="no" <?php selected($auto_enabled, 'no'); ?>>Desativada</option>
                            </select>
                            <p class="description">Ativar verificação automática de novas pastas/arquivos</p>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row">Frequência WP-Cron</th>
                        <td>
                            <select name="sftp_auto_frequency">
                                <option value="minutely" <?php selected($auto_frequency, 'minutely'); ?>>A cada minuto (recomendado)</option>
                                <option value="every5minutes" <?php selected($auto_frequency, 'every5minutes'); ?>>A cada 5 minutos</option>
                                <option value="hourly" <?php selected($auto_frequency, 'hourly'); ?>>A cada hora</option>
                            </select>
                            <p class="description">Frequência do agendamento automático</p>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row">Intervalo Mínimo</th>
                        <td>
                            <input type="number" name="sftp_force_minutes" value="<?php echo esc_attr($force_minutes); ?>" min="1" max="60" class="small-text" /> minutos
                            <p class="description">Intervalo mínimo entre verificações automáticas</p>
                        </td>
                    </tr>
                </table>
                
                <?php submit_button('Salvar Configurações'); ?>
            </form>
            
            <hr>
            
            <h2>Processamento Manual</h2>
            <p>Clique no botão abaixo para escanear as pastas SFTP e criar produtos para novos arquivos imediatamente:</p>
            
            <form method="post" action="<?php echo admin_url('admin-post.php'); ?>">
                <input type="hidden" name="action" value="scan_sftp_folders">
                <?php wp_nonce_field('scan_sftp_folders_nonce'); ?>
                <?php submit_button('Escanear Pastas Agora', 'primary', 'submit', false); ?>
            </form>
            
            <hr>
            
            <h2>URL de Processamento Direto</h2>
            <p>Configuração avançada: Use esta URL para acionar o processamento por um cron externo real:</p>
            
            <div style="background:#f5f5f5;padding:10px;border:1px solid #ccc;">
                <code><?php echo esc_url($direct_url); ?></code>
                <p><strong>Instruções:</strong></p>
                <ol>
                    <li>Configure um cron externo real (no servidor) para acessar esta URL a cada minuto</li>
                    <li>Exemplo de comando crontab: <code>* * * * * wget -q -O /dev/null '<?php echo esc_url($direct_url); ?>'</code></li>
                    <li>Esta é a forma mais confiável de garantir processamento automático</li>
                </ol>
                <p><strong>Importante:</strong> Mantenha esta URL privada pois permite processamento sem autenticação</p>
            </div>
            
            <?php if ($woo_active): ?>
            <div style="margin-top:20px;padding:15px;background:#f8f8f8;border:1px solid #ddd;">
                <h3>Últimos Produtos Processados</h3>
                <?php
                $processed_files = get_option('sftp_processed_files', array());
                
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
                    
                    echo '<p style="margin-top:15px;">Total de arquivos processados: <strong>' . count($processed_files) . '</strong></p>';
                } else {
                    echo '<p>Nenhum arquivo processado ainda.</p>';
                }
                ?>
            </div>
            <?php endif; ?>
            
            <?php
            // Exibir log recente
            $recent_log = get_option('sftp_recent_log', '');
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
        add_action('update_option_sftp_auto_enabled', array($this, 'maybe_reschedule_cron'), 10, 2);
        add_action('update_option_sftp_auto_frequency', array($this, 'maybe_reschedule_cron'), 10, 2);
    }
    
    /**
     * Reagendar CRON quando configurações mudarem
     */
    public function maybe_reschedule_cron($old_value, $new_value) {
        // Remover agendamento existente
        $timestamp = wp_next_scheduled('sftp_auto_scan_hook');
        if ($timestamp) {
            wp_unschedule_event($timestamp, 'sftp_auto_scan_hook');
        }
        
        // Se automação estiver ativa, agendar novamente
        if (get_option('sftp_auto_enabled', 'yes') === 'yes') {
            $frequency = get_option('sftp_auto_frequency', 'minutely');
            wp_schedule_event(time(), $frequency, 'sftp_auto_scan_hook');
        }
    }
    
    /**
     * Processar scan manual
     */
    public function process_manual_scan() {
        check_admin_referer('scan_sftp_folders_nonce');
        
        if (!current_user_can('manage_options')) {
            wp_die('Acesso negado');
        }
        
        // Limpar log
        $this->log = array();
        $this->add_log("Iniciando processamento MANUAL: " . date('d/m/Y H:i:s'));
        
        // Verificar WooCommerce
        if (!class_exists('WooCommerce')) {
            $this->add_log("ERRO: WooCommerce não está ativo");
            $this->save_activity_log();
            wp_redirect(add_query_arg(array(
                'page' => 'sftp-to-woo-auto',
                'error' => 'woocommerce'
            ), admin_url('admin.php')));
            exit;
        }
        
        // Obter diretório base
        $base_dir = get_option('sftp_base_directory', '');
        $this->add_log("Diretório base: $base_dir");
        
        if (empty($base_dir) || !file_exists($base_dir) || !is_dir($base_dir)) {
            $this->add_log("ERRO: Diretório base inválido ou inacessível");
            $this->save_activity_log();
            wp_redirect(add_query_arg(array(
                'page' => 'sftp-to-woo-auto',
                'error' => 'directory'
            ), admin_url('admin.php')));
            exit;
        }
        
        // Iniciar processamento
        $result = $this->scan_directories($base_dir);
        
        // Atualizar hora do último processamento
        update_option('sftp_last_process_time', time());
        
        // Adicionar resultado final ao log
        $this->add_log("Processamento manual concluído. Total: $result produtos criados");
        $this->save_activity_log();
        
        // Redirecionar com resultado
        wp_redirect(add_query_arg(array(
            'page' => 'sftp-to-woo-auto',
            'processed' => $result
        ), admin_url('admin.php')));
        exit;
    }
    
    /**
     * Método para escaneamento automático
     */
    public function auto_scan_folders($alternative_method = false) {
        // Verificar se automação está ativada
        if (get_option('sftp_auto_enabled', 'yes') !== 'yes') {
            return 0;
        }
        
        // Verificar se já existe um processo rodando
        if ($this->is_process_locked()) {
            if ($this->direct_access) {
                echo "ERRO: Processo já em execução (bloqueado por outro processo)\n";
            }
            return 0;
        }
        
        // Limpar log
        $this->log = array();
        $execution_type = $alternative_method ? 'ALTERNATIVO' : 'CRON';
        if ($this->direct_access) $execution_type = 'DIRETO';
        
        $this->add_log("Iniciando processamento AUTOMÁTICO ({$execution_type}): " . date('d/m/Y H:i:s'));
        
        // Verificar WooCommerce
        if (!class_exists('WooCommerce')) {
            $this->add_log("ERRO: WooCommerce não está ativo - escaneamento automático abortado");
            $this->save_activity_log();
            $this->release_process_lock();
            return 0;
        }
        
        // Obter diretório base
        $base_dir = get_option('sftp_base_directory', '');
        $this->add_log("Diretório base: $base_dir");
        
        if (empty($base_dir) || !file_exists($base_dir) || !is_dir($base_dir)) {
            $this->add_log("ERRO: Diretório base inválido ou inacessível - escaneamento automático abortado");
            $this->save_activity_log();
            $this->release_process_lock();
            return 0;
        }
        
        // Executar escaneamento
        $result = $this->scan_directories($base_dir, true);
        
         // Atualizar hora do último processamento automático
        update_option('sftp_last_auto_time', time());
        
        // Adicionar resultado ao log
        $this->add_log("Processamento automático concluído. Total: $result produtos criados");
        
        // Salvar log de atividade
        $this->save_activity_log();
        
        // Liberar o lock
        $this->release_process_lock();
        
        return $result;
    }
    
    /**
     * Escanear diretórios
     */
    private function scan_directories($base_dir, $auto_mode = false) {
        $processed = 0;
        
        // Listar pastas de clientes
        $client_folders = glob($base_dir . '/*', GLOB_ONLYDIR);
        
        if (!is_array($client_folders)) {
            $this->add_log("ERRO: Falha ao listar diretórios de clientes. Verifique permissões.");
            return 0;
        }
        
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
        
        if (empty($files)) {
            $this->add_log("Nenhum arquivo encontrado para o cliente $client_name");
            return 0;
        }
        
        $this->add_log("Encontrados " . count($files) . " arquivos para o cliente $client_name");
        
        // Lista de arquivos já processados
        $processed_files = get_option('sftp_processed_files', array());
        
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
            
            $this->add_log("Novo arquivo detectado: $file_name");
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
        
        // Salvar lista atualizada apenas se houve novos produtos
        if ($processed > 0) {
            update_option('sftp_processed_files', $processed_files);
        }
        
        return $processed;
    }
    
    /**
     * Obter todos os arquivos, incluindo em subpastas
     */
    private function get_all_files($dir) {
        $files = array();
        
        // Primeiro verificar se o diretório existe
        if (!file_exists($dir) || !is_dir($dir) || !is_readable($dir)) {
            $this->add_log("AVISO: Diretório não acessível: $dir");
            return $files;
        }
        
        // Diretório raiz
        $dir_files = glob($dir . '/*');
        if (is_array($dir_files)) {
            foreach ($dir_files as $file) {
                if (is_dir($file)) {
                    // É uma subpasta, buscar arquivos recursivamente
                    $sub_files = $this->get_all_files($file);
                    $files = array_merge($files, $sub_files);
                } else {
                    // É um arquivo, verificar se é acessível
                    if (is_readable($file)) {
                        $files[] = $file;
                    } else {
                        $this->add_log("AVISO: Arquivo não pode ser lido: " . basename($file));
                    }
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
        $price = get_option('sftp_default_price', '0');
        $status = get_option('sftp_product_status', 'publish');
        
        // Verificar WooCommerce
        if (!function_exists('wc_get_product') || !class_exists('WC_Product')) {
            $this->add_log("ERRO: Funções ou classes do WooCommerce não existem");
            return false;
        }
        
        try {
            // Garantir que o WC_Data e WC_Product existam
            if (!class_exists('WC_Data') || !class_exists('WC_Product')) {
                $this->add_log("ERRO: Classes WC_Data ou WC_Product não disponíveis");
                return false;
            }
            
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
                $this->add_log("Criando diretório: {$target_dir}");
                wp_mkdir_p($target_dir);
            }
            
            // Verificar permissões do diretório de destino
            if (!is_writable($target_dir)) {
                $this->add_log("ERRO: Diretório de uploads não tem permissão de escrita: {$target_dir}");
                return false;
            }
            
            $new_file_name = uniqid($client_name . '_') . '_' . $file_name;
            $target_path = $target_dir . $new_file_name;
            
            $this->add_log("Copiando arquivo para: {$target_path}");
            if (copy($file_path, $target_path)) {
                $this->add_log("Arquivo copiado com sucesso");
                
                // URL do arquivo
                $download_url = $upload_dir['baseurl'] . '/woocommerce_uploads/' . $new_file_name;
                
                // Configurar download
                $download = array(
                    'id' => md5($target_path),
                    'name' => $file_name,
                    'file' => $download_url
                );
                
                $this->add_log("URL do download: {$download_url}");
                $product->set_downloads(array($download));
                $product->set_download_limit(-1); // Sem limite
                $product->set_download_expiry(-1); // Sem expiração
            } else {
                $this->add_log("ERRO: Falha ao copiar arquivo. Verifique permissões.");
                return false;
            }
            
            // Registrar que estamos tentando salvar o produto
            $this->add_log("Salvando produto no banco de dados...");
            
            // Tentativa de resolução de problema de save()
            global $wpdb;
            if (!$wpdb->ready) {
                $this->add_log("AVISO: Conexão com banco de dados não está pronta");
            }
            
            // Salvar produto
            $product_id = $product->save();
            
            if (!$product_id) {
                $this->add_log("ERRO: Falha ao salvar produto. Retorno vazio.");
                return false;
            }
            
            // Adicionar metadados extras
            update_post_meta($product_id, '_sftp_source', $file_path);
            update_post_meta($product_id, '_sftp_client', $client_name);
            
            // Verificar se o produto foi criado corretamente
            $saved_product = wc_get_product($product_id);
            if (!$saved_product) {
                $this->add_log("ERRO: Produto não encontrado após salvar (ID: {$product_id})");
                return false;
            }
            
            $this->add_log("Produto criado e verificado com sucesso: ID {$product_id}");
            return $product_id;
            
        } catch (Exception $e) {
            $this->add_log("EXCEÇÃO: " . $e->getMessage() . " em " . $e->getFile() . " linha " . $e->getLine());
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
        
        // Também imprimir na resposta se for acesso direto
        if ($this->direct_access) {
            echo $message . "\n";
            flush();
        }
    }
    
    /**
     * Salvar log de atividade
     */
    private function save_activity_log() {
        $log_text = implode("\n", $this->log);
        update_option('sftp_recent_log', $log_text);
    }
}

// Inicializar plugin
add_action('plugins_loaded', function() {
    if (class_exists('WooCommerce')) {
        new SFTP_To_Woo_Ultra();
    }
});