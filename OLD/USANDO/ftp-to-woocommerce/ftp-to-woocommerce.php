<?php
/**
 * Plugin Name: FTP para WooCommerce - Ultra Confiável
 * Description: Converte arquivos FTP em produtos WooCommerce com automação ultra confiável
 * Version: 1.0.1
 * Author: DevSpacek
 */

if (!defined('ABSPATH')) {
    exit;
}

class FTP_To_Woo_Ultra {
    
    private $log = array();
    private $lock_file;
    private $direct_access = false;
    private $ftp_connection = null;
    
    public function __construct() {
        // Arquivo de lock para evitar execuções simultâneas
        $upload_dir = wp_upload_dir();
        $this->lock_file = $upload_dir['basedir'] . '/ftp_woo_process.lock';
        
        // Adicionar intervalo de cron personalizado para cada minuto
        add_filter('cron_schedules', array($this, 'add_cron_interval'));
        
        // Admin
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_init', array($this, 'register_settings'));
        add_action('admin_notices', array($this, 'admin_notices'));
        
        // Processamento
        add_action('admin_post_scan_ftp_folders', array($this, 'process_manual_scan'));
        add_action('wp_ajax_trigger_ftp_scan', array($this, 'ajax_trigger_scan'));
        add_action('wp_ajax_nopriv_trigger_ftp_scan', array($this, 'ajax_trigger_scan_nopriv'));
        add_action('wp_ajax_test_ftp_connection', array($this, 'test_ftp_connection_ajax'));
        
        // Métodos múltiplos de automação
        add_action('init', array($this, 'check_direct_access'));
        add_action('init', array($this, 'maybe_schedule_cron'));
        add_action('ftp_auto_scan_hook', array($this, 'auto_scan_folders'));
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
        if (isset($_GET['ftp_direct_scan']) && isset($_GET['key'])) {
            $this->direct_access = true;
            $stored_key = get_option('ftp_security_key', '');
            
            // Se a chave estiver vazia, gerar uma nova
            if (empty($stored_key)) {
                $stored_key = md5(time() . rand(1000, 9999));
                update_option('ftp_security_key', $stored_key);
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
        if (get_option('ftp_auto_enabled', 'yes') === 'yes') {
            $timestamp = wp_next_scheduled('ftp_auto_scan_hook');
            if (!$timestamp) {
                $frequency = get_option('ftp_auto_frequency', 'minutely');
                wp_schedule_event(time(), $frequency, 'ftp_auto_scan_hook');
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
        if (empty(get_option('ftp_security_key', ''))) {
            update_option('ftp_security_key', md5(time() . rand(1000, 9999)));
        }
        
        // Agendar para rodar a cada minuto por padrão
        wp_schedule_event(time(), 'minutely', 'ftp_auto_scan_hook');
        update_option('ftp_auto_enabled', 'yes');
        update_option('ftp_auto_frequency', 'minutely');
        
        // Registrar ativação no log
        $this->log = array();
        $this->add_log("Plugin ativado: escaneamento configurado para cada minuto");
        $this->save_activity_log();
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
     * Adicionar menu
     */
    public function add_admin_menu() {
        add_menu_page(
            'FTP para WooCommerce',
            'FTP para Woo',
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
        // Configurações FTP
        register_setting('ftp_to_woo_settings', 'ftp_host');
        register_setting('ftp_to_woo_settings', 'ftp_port', array(
            'default' => '21'
        ));
        register_setting('ftp_to_woo_settings', 'ftp_username');
        register_setting('ftp_to_woo_settings', 'ftp_password');
        register_setting('ftp_to_woo_settings', 'ftp_path', array(
            'default' => '/'
        ));
        register_setting('ftp_to_woo_settings', 'ftp_passive', array(
            'default' => 'yes'
        ));
        
        // Configurações de produto
        register_setting('ftp_to_woo_settings', 'ftp_default_price', array(
            'default' => '10'
        ));
        register_setting('ftp_to_woo_settings', 'ftp_product_status', array(
            'default' => 'publish'
        ));
        
        // Configurações de automação
        register_setting('ftp_to_woo_settings', 'ftp_auto_enabled', array(
            'default' => 'yes'
        ));
        register_setting('ftp_to_woo_settings', 'ftp_auto_frequency', array(
            'default' => 'minutely'
        ));
        register_setting('ftp_to_woo_settings', 'ftp_force_minutes', array(
            'default' => '1'
        ));
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
        
        if (isset($_GET['error'])) {
            $error = sanitize_text_field($_GET['error']);
            $message = '';
            
            switch ($error) {
                case 'woocommerce':
                    $message = 'WooCommerce não está ativo. Este plugin requer WooCommerce para funcionar.';
                    break;
                case 'ftp_extension':
                    $message = 'A extensão FTP do PHP não está disponível no servidor. Este plugin requer essa extensão.';
                    break;
                case 'process_locked':
                    $message = 'Outro processo já está em execução. Tente novamente em alguns minutos.';
                    break;
                case 'ftp_credentials':
                    $message = 'As credenciais do servidor FTP não estão configuradas. Preencha todos os campos obrigatórios.';
                    break;
                case 'ftp_connect':
                    $message = 'Falha ao conectar ao servidor FTP. Verifique o endereço e a porta.';
                    break;
                case 'ftp_login':
                    $message = 'Falha no login FTP. Verifique o nome de usuário e senha.';
                    break;
                case 'ftp_path':
                    $message = 'O diretório base no servidor FTP não existe ou não é acessível.';
                    break;
                case 'upload_dir':
                    $message = 'Não foi possível criar ou escrever no diretório de uploads. Verifique as permissões.';
                    break;
                case 'exception':
                    $error_msg = isset($_GET['message']) ? urldecode($_GET['message']) : 'Desconhecido';
                    $message = "Erro durante a execução: {$error_msg}";
                    break;
                default:
                    $message = 'Ocorreu um erro durante o processamento.';
            }
            
            echo '<div class="notice notice-error is-dismissible"><p><strong>FTP para WooCommerce:</strong> ' . $message . '</p></div>';
        }
    }
    
    /**
     * AJAX para acionar scan
     */
    public function ajax_trigger_scan() {
        check_ajax_referer('ftp-ajax-nonce', 'security');
        
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
        if (!isset($_GET['key']) || $_GET['key'] !== get_option('ftp_security_key', '')) {
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
        if (get_option('ftp_auto_enabled', 'yes') !== 'yes') {
            return;
        }
        
        // Obter tempo da última execução
        $last_time = get_option('ftp_last_auto_time', 0);
        $force_minutes = intval(get_option('ftp_force_minutes', 1));
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
        $lock_dir = dirname($this->lock_file);
        if (!file_exists($lock_dir)) {
            wp_mkdir_p($lock_dir);
        }
        
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
        $ftp_host = get_option('ftp_host', '');
        $ftp_port = get_option('ftp_port', '21');
        $ftp_username = get_option('ftp_username', '');
        $ftp_password = get_option('ftp_password', '');
        $ftp_path = get_option('ftp_path', '/');
        $ftp_passive = get_option('ftp_passive', 'yes');
        
        $default_price = get_option('ftp_default_price', '10');
        $product_status = get_option('ftp_product_status', 'publish');
        $auto_enabled = get_option('ftp_auto_enabled', 'yes');
        $auto_frequency = get_option('ftp_auto_frequency', 'minutely');
        $force_minutes = get_option('ftp_force_minutes', '1');
        $security_key = get_option('ftp_security_key', '');
        
        // Se a chave estiver vazia, gerar uma nova
        if (empty($security_key)) {
            $security_key = md5(time() . rand(1000, 9999));
            update_option('ftp_security_key', $security_key);
        }
        
        // URL direta para processamento
        $direct_url = add_query_arg(array(
            'ftp_direct_scan' => '1',
            'key' => $security_key
        ), site_url('/'));
        
        // Verificar status do WooCommerce
        $woo_active = class_exists('WooCommerce');
        $ftp_available = function_exists('ftp_connect');
        $next_run = wp_next_scheduled('ftp_auto_scan_hook');
        
        ?>
        <div class="wrap">
            <h1>FTP para WooCommerce (Ultra Confiável)</h1>
            
            <div class="notice notice-info">
                <p><strong>Status do Sistema:</strong></p>
                <ul style="list-style:disc;padding-left:20px;">
                    <li>WooCommerce: <?php echo $woo_active ? '<span style="color:green;">✓ Ativo</span>' : '<span style="color:red;">✗ Inativo</span>'; ?></li>
                    <li>Extensão FTP do PHP: <?php echo $ftp_available ? '<span style="color:green;">✓ Disponível</span>' : '<span style="color:red;">✗ Não disponível</span>'; ?></li>
                    <li>Data atual do servidor: <?php echo date('Y-m-d H:i:s'); ?></li>
                    <li>Último processamento manual: <?php echo get_option('ftp_last_process_time') ? date('d/m/Y H:i:s', get_option('ftp_last_process_time')) : 'Nunca'; ?></li>
                    <li>Último processamento automático: <?php echo get_option('ftp_last_auto_time') ? date('d/m/Y H:i:s', get_option('ftp_last_auto_time')) : 'Nunca'; ?></li>
                    <li>Próximo agendamento WP-Cron: <?php echo $next_run ? date('d/m/Y H:i:s', $next_run) : 'Não agendado'; ?></li>
                </ul>
            </div>
            
            <h2>Configuração do Servidor FTP</h2>
            <form method="post" action="options.php">
                <?php settings_fields('ftp_to_woo_settings'); ?>
                
                <table class="form-table">
                    <tr>
                        <th scope="row">Servidor FTP</th>
                        <td>
                            <input type="text" name="ftp_host" value="<?php echo esc_attr($ftp_host); ?>" class="regular-text" required />
                            <p class="description">Endereço do servidor FTP (ex: ftp.seudominio.com)</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Porta</th>
                        <td>
                            <input type="number" name="ftp_port" value="<?php echo esc_attr($ftp_port); ?>" class="small-text" required />
                            <p class="description">Porta do servidor FTP (geralmente 21)</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Usuário</th>
                        <td>
                            <input type="text" name="ftp_username" value="<?php echo esc_attr($ftp_username); ?>" class="regular-text" required />
                            <p class="description">Nome de usuário para conexão FTP</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Senha</th>
                        <td>
                            <input type="password" name="ftp_password" value="<?php echo esc_attr($ftp_password); ?>" class="regular-text" required />
                            <p class="description">Senha para conexão FTP</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Diretório Base</th>
                        <td>
                            <input type="text" name="ftp_path" value="<?php echo esc_attr($ftp_path); ?>" class="regular-text" />
                            <p class="description">Caminho base no servidor FTP onde estão os diretórios de clientes (ex: /public_html/clientes)</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Modo Passivo</th>
                        <td>
                            <select name="ftp_passive">
                                <option value="yes" <?php selected($ftp_passive, 'yes'); ?>>Sim</option>
                                <option value="no" <?php selected($ftp_passive, 'no'); ?>>Não</option>
                            </select>
                            <p class="description">Geralmente "Sim" para evitar problemas com firewalls</p>
                        </td>
                    </tr>
                </table>
                
                <button type="button" id="ftp-test-connection" class="button button-secondary">Testar Conexão FTP</button>
                <div id="ftp-test-result" style="padding:10px;margin-top:10px;display:none;"></div>
                
                <h2>Configuração de Produtos</h2>
                
                <table class="form-table">
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
                            <select name="ftp_auto_enabled">
                                <option value="yes" <?php selected($auto_enabled, 'yes'); ?>>Ativa</option>
                                <option value="no" <?php selected($auto_enabled, 'no'); ?>>Desativada</option>
                            </select>
                            <p class="description">Ativar verificação automática de novas pastas/arquivos</p>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row">Frequência WP-Cron</th>
                        <td>
                            <select name="ftp_auto_frequency">
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
                            <input type="number" name="ftp_force_minutes" value="<?php echo esc_attr($force_minutes); ?>" min="1" max="60" class="small-text" /> minutos
                            <p class="description">Intervalo mínimo entre verificações automáticas</p>
                        </td>
                    </tr>
                </table>
                
                <?php submit_button('Salvar Configurações'); ?>
            </form>
            
            <hr>
            
            <h2>Processamento Manual</h2>
            <p>Clique no botão abaixo para escanear as pastas FTP e criar produtos para novos arquivos imediatamente:</p>
            
            <form method="post" action="<?php echo admin_url('admin-post.php'); ?>">
                <input type="hidden" name="action" value="scan_ftp_folders">
                <?php wp_nonce_field('scan_ftp_folders_nonce'); ?>
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
                    
                    echo '<p style="margin-top:15px;">Total de arquivos processados: <strong>' . count($processed_files) . '</strong></p>';
                } else {
                    echo '<p>Nenhum arquivo processado ainda.</p>';
                }
                ?>
            </div>
            <?php endif; ?>
            
            <?php
            // Exibir log recente
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
        
        <script>
        jQuery(document).ready(function($) {
            // Testar conexão FTP
            $('#ftp-test-connection').on('click', function() {
                var $button = $(this);
                var $result = $('#ftp-test-result');
                
                $button.prop('disabled', true).text('Testando...');
                $result.hide();
                
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'test_ftp_connection',
                        security: '<?php echo wp_create_nonce('ftp-ajax-nonce'); ?>',
                        host: $('input[name="ftp_host"]').val(),
                        port: $('input[name="ftp_port"]').val(),
                        username: $('input[name="ftp_username"]').val(),
                        password: $('input[name="ftp_password"]').val(),
                        path: $('input[name="ftp_path"]').val(),
                        passive: $('select[name="ftp_passive"]').val()
                    },
                    success: function(response) {
                        $button.prop('disabled', false).text('Testar Conexão FTP');
                        
                        if (response.success) {
                            $result.html(response.data).css('background-color', '#dff0d8').css('color', '#3c763d').css('border', '1px solid #d6e9c6').show();
                        } else {
                            $result.html(response.data).css('background-color', '#f2dede').css('color', '#a94442').css('border', '1px solid #ebccd1').show();
                        }
                    },
                    error: function() {
                        $button.prop('disabled', false).text('Testar Conexão FTP');
                        $result.html('Erro ao processar requisição.').css('background-color', '#f2dede').css('color', '#a94442').css('border', '1px solid #ebccd1').show();
                    }
                });
            });
        });
        </script>
        <?php
    }
    
    /**
     * Verificar diretório de upload
     */
    private function check_upload_directory() {
        $upload_dir = wp_upload_dir();
        $target_dir = $upload_dir['basedir'] . '/woocommerce_uploads/';
        
        if (!file_exists($target_dir)) {
            $this->add_log("Criando diretório de upload: {$target_dir}");
            
            $created = wp_mkdir_p($target_dir);
            if (!$created) {
                $this->add_log("ERRO: Não foi possível criar o diretório de upload");
                return false;
            }
        }
        
        if (!is_writable($target_dir)) {
            $this->add_log("ERRO: Diretório de upload não tem permissão de escrita");
            return false;
        }
        
        $this->add_log("Diretório de upload está OK: {$target_dir}");
        return true;
    }

    /**
     * Processar scan manual
     */
    public function process_manual_scan() {
        check_admin_referer('scan_ftp_folders_nonce');
        
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
                'page' => 'ftp-to-woo-auto',
                'error' => 'woocommerce'
            ), admin_url('admin.php')));
            exit;
        }
        
        // Verificar extensão FTP
        if (!function_exists('ftp_connect')) {
            $this->add_log("ERRO: Extensão FTP do PHP não está disponível");
            $this->save_activity_log();
            wp_redirect(add_query_arg(array(
                'page' => 'ftp-to-woo-auto',
                'error' => 'ftp_extension'
            ), admin_url('admin.php')));
            exit;
        }
        
        try {
            // Verificar se já existe um processo rodando
            if ($this->is_process_locked()) {
                $this->add_log("AVISO: Outro processo já está em execução");
                $this->save_activity_log();
                wp_redirect(add_query_arg(array(
                    'page' => 'ftp-to-woo-auto',
                    'error' => 'process_locked'
                ), admin_url('admin.php')));
                exit;
            }
            
            // Verificar diretório de upload
            if (!$this->check_upload_directory()) {
                $this->save_activity_log();
                wp_redirect(add_query_arg(array(
                    'page' => 'ftp-to-woo-auto',
                    'error' => 'upload_dir'
                ), admin_url('admin.php')));
                exit;
            }
            
            // Configurações FTP
            $ftp_host = get_option('ftp_host', '');
            $ftp_port = intval(get_option('ftp_port', 21));
            $ftp_username = get_option('ftp_username', '');
            $ftp_password = get_option('ftp_password', '');
            $ftp_path = get_option('ftp_path', '/');
            $passive = get_option('ftp_passive', 'yes') === 'yes';
            
            if (empty($ftp_host) || empty($ftp_username) || empty($ftp_password)) {
                $this->add_log("ERRO: Credenciais FTP não configuradas");
                $this->save_activity_log();
                wp_redirect(add_query_arg(array(
                    'page' => 'ftp-to-woo-auto',
                    'error' => 'ftp_credentials'
                ), admin_url('admin.php')));
                exit;
            }
            
            $this->add_log("Conectando ao servidor FTP: $ftp_host:$ftp_port");
            
            // Tentar conectar
            $this->ftp_connection = @ftp_connect($ftp_host, $ftp_port, 30);
            if (!$this->ftp_connection) {
                $this->add_log("ERRO: Falha ao conectar ao servidor FTP: $ftp_host:$ftp_port");
                $this->save_activity_log();
                wp_redirect(add_query_arg(array(
                    'page' => 'ftp-to-woo-auto',
                    'error' => 'ftp_connect'
                ), admin_url('admin.php')));
                exit;
            }
            
            // Tentar login
            $login = @ftp_login($this->ftp_connection, $ftp_username, $ftp_password);
            if (!$login) {
                $this->add_log("ERRO: Falha no login FTP. Verifique usuário e senha.");
                @ftp_close($this->ftp_connection);
                $this->save_activity_log();
                wp_redirect(add_query_arg(array(
                    'page' => 'ftp-to-woo-auto',
                    'error' => 'ftp_login'
                ), admin_url('admin.php')));
                exit;
            }
            
            // Modo passivo se necessário
            if ($passive) {
                @ftp_pasv($this->ftp_connection, true);
                $this->add_log("Modo passivo FTP ativado");
            }
            
            // Verificar acesso ao diretório base
            if (!@ftp_chdir($this->ftp_connection, $ftp_path)) {
                $this->add_log("ERRO: Diretório base não encontrado: $ftp_path");
                @ftp_close($this->ftp_connection);
                $this->save_activity_log();
                wp_redirect(add_query_arg(array(
                    'page' => 'ftp-to-woo-auto',
                    'error' => 'ftp_path'
                ), admin_url('admin.php')));
                exit;
            }
            
            $this->add_log("Conectado com sucesso ao FTP, iniciando processamento");
            
            // Iniciar processamento
            $result = $this->scan_ftp_directories(false);
            
            // Fechar conexão FTP
            @ftp_close($this->ftp_connection);
            $this->ftp_connection = null;
            
            // Atualizar hora do último processamento
            update_option('ftp_last_process_time', time());
            
            // Liberar o lock
            $this->release_process_lock();
            
            // Adicionar resultado final ao log
            $this->add_log("Processamento manual concluído. Total: $result produtos criados");
            $this->save_activity_log();
            
            // Redirecionar com resultado
            wp_redirect(add_query_arg(array(
                'page' => 'ftp-to-woo-auto',
                'processed' => $result
            ), admin_url('admin.php')));
            exit;
            
        } catch (Exception $e) {
            // Capturar qualquer exceção não tratada
            $this->add_log("ERRO CRÍTICO: " . $e->getMessage() . " em " . $e->getFile() . " na linha " . $e->getLine());
            
            // Tentar fechar conexão FTP se estiver aberta
            if ($this->ftp_connection) {
                @ftp_close($this->ftp_connection);
                $this->ftp_connection = null;
            }
            
            // Liberar o lock
            $this->release_process_lock();
            
            // Salvar o log
            $this->save_activity_log();
            
            // Redirecionar com erro
            wp_redirect(add_query_arg(array(
                'page' => 'ftp-to-woo-auto',
                'error' => 'exception',
                'message' => urlencode(substr($e->getMessage(), 0, 100))
            ), admin_url('admin.php')));
            exit;
        }
    }
    
    /**
     * Método para escaneamento automático
     */
    public function auto_scan_folders($alternative_method = false) {
        // Verificar se automação está ativada
        if (get_option('ftp_auto_enabled', 'yes') !== 'yes') {
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
        
        try {
            // Verificar WooCommerce
            if (!class_exists('WooCommerce')) {
                $this->add_log("ERRO: WooCommerce não está ativo - escaneamento automático abortado");
                $this->save_activity_log();
                $this->release_process_lock();
                return 0;
            }
            
            // Verificar extensão FTP
            if (!function_exists('ftp_connect')) {
                $this->add_log("ERRO: Extensão FTP do PHP não está disponível");
                $this->save_activity_log();
                $this->release_process_lock();
                return 0;
            }
            
            // Verificar diretório de upload
            if (!$this->check_upload_directory()) {
                $this->save_activity_log();
                $this->release_process_lock();
                return 0;
            }
            
            // Conectar ao FTP
            if (!$this->connect_to_ftp()) {
                $this->save_activity_log();
                $this->release_process_lock();
                return 0;
            }
            
            // Executar escaneamento
            $result = $this->scan_ftp_directories(true);
            
            // Fechar conexão FTP
            $this->close_ftp_connection();
            
            // Atualizar hora do último processamento automático
            update_option('ftp_last_auto_time', time());
            
            // Adicionar resultado ao log
            $this->add_log("Processamento automático concluído. Total: $result produtos criados");
            
            // Salvar log de atividade
            $this->save_activity_log();
            
            // Liberar o lock
            $this->release_process_lock();
            
            return $result;
        } catch (Exception $e) {
            // Capturar qualquer exceção não tratada
            $this->add_log("ERRO CRÍTICO: " . $e->getMessage());
            
            // Tentar fechar conexão FTP se estiver aberta
            $this->close_ftp_connection();
            
            // Liberar o lock
            $this->release_process_lock();
            
            // Salvar log de atividade
            $this->save_activity_log();
            
            return 0;
        }
    }
    
    /**
     * Testar conexão FTP via AJAX
     */
    public function test_ftp_connection_ajax() {
        check_ajax_referer('ftp-ajax-nonce', 'security');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Permissão negada');
            return;
        }
        
        $host = isset($_POST['host']) ? sanitize_text_field($_POST['host']) : '';
        $port = isset($_POST['port']) ? intval($_POST['port']) : 21;
        $username = isset($_POST['username']) ? sanitize_text_field($_POST['username']) : '';
        $password = isset($_POST['password']) ? $_POST['password'] : ''; 
        $path = isset($_POST['path']) ? sanitize_text_field($_POST['path']) : '/';
        $passive = isset($_POST['passive']) ? ($_POST['passive'] === 'yes') : true;
        
        // Verificar extensão FTP
        if (!function_exists('ftp_connect')) {
            wp_send_json_error('Extensão FTP do PHP não está disponível no servidor');
            return;
        }
        
        // Tentar conectar
        $conn = @ftp_connect($host, $port, 10);
        if (!$conn) {
            wp_send_json_error("Falha ao conectar ao servidor FTP: $host:$port");
            return;
        }
        
        // Tentar login
        $login = @ftp_login($conn, $username, $password);
        if (!$login) {
            @ftp_close($conn);
            wp_send_json_error('Falha na autenticação FTP. Verifique usuário e senha.');
            return;
        }
        
        // Configurar modo passivo se necessário
        if ($passive) {
            @ftp_pasv($conn, true);
        }
        
        // Verificar diretório
        if (!@ftp_chdir($conn, $path)) {
            @ftp_close($conn);
            wp_send_json_error("Diretório não encontrado: $path");
            return;
        }
        
        // Listar conteúdo
        $list = @ftp_nlist($conn, ".");
        $dirs = 0;
        $files = 0;
        
        if (is_array($list)) {
            $current = @ftp_pwd($conn);
            
            foreach ($list as $item) {
                if ($item === '.' || $item === '..') {
                    continue;
                }
                
                // Verificar se é diretório
                if (@ftp_chdir($conn, "$current/$item")) {
                    $dirs++;
                    @ftp_chdir($conn, $current);
                } else {
                    $files++;
                }
            }
        }
        
        @ftp_close($conn);
        
        $result = "<strong>Conexão FTP bem sucedida!</strong><br>";
        $result .= "Diretório: $path<br>";
        $result .= "Encontrados: $dirs diretórios e $files arquivos";
        
        wp_send_json_success($result);
    }
    
    /**
     * Conectar ao servidor FTP
     */
    private function connect_to_ftp() {
        // Obter configurações FTP
        $host = get_option('ftp_host', '');
        $port = intval(get_option('ftp_port', 21));
        $username = get_option('ftp_username', '');
        $password = get_option('ftp_password', '');
        $passive = get_option('ftp_passive', 'yes') === 'yes';
        
        $this->add_log("Conectando ao servidor FTP: $host:$port");
        
        // Verificar se as credenciais estão configuradas
        if (empty($host) || empty($username) || empty($password)) {
            $this->add_log("ERRO: Credenciais FTP não configuradas");
            return false;
        }
        
        // Tentar conectar
        $conn = @ftp_connect($host, $port, 30);
        if (!$conn) {
            $this->add_log("ERRO: Falha ao conectar ao servidor FTP: $host:$port");
            return false;
        }
        
        // Tentar login
        $login = @ftp_login($conn, $username, $password);
        if (!$login) {
            $this->add_log("ERRO: Falha no login FTP. Verifique usuário e senha.");
            @ftp_close($conn);
            return false;
        }
        
        // Configurar modo passivo se necessário
        if ($passive) {
            @ftp_pasv($conn, true);
        }
        
        // Armazenar conexão
        $this->ftp_connection = $conn;
        $this->add_log("Conexão FTP estabelecida com sucesso");
        
        return true;
    }
    
    /**
     * Fechar conexão FTP
     */
    private function close_ftp_connection() {
        if ($this->ftp_connection) {
            @ftp_close($this->ftp_connection);
            $this->ftp_connection = null;
            $this->add_log("Conexão FTP fechada");
        }
    }
    
    /**
     * Escanear diretórios FTP
     */
    private function scan_ftp_directories($auto_mode = false) {
        $processed = 0;
        $base_path = get_option('ftp_path', '/');
        
        $this->add_log("Acessando diretório base: $base_path");
        
        // Mudar para o diretório base
        if (!@ftp_chdir($this->ftp_connection, $base_path)) {
            $this->add_log("ERRO: Não foi possível acessar o diretório base: $base_path");
            return 0;
        }
        
        // Listar diretórios de clientes
        $list = @ftp_nlist($this->ftp_connection, ".");
        if (!is_array($list)) {
            $this->add_log("ERRO: Falha ao listar diretórios. Verifique permissões FTP.");
            return 0;
        }
        
        // Filtrar apenas diretórios (cliente)
        $client_folders = array();
        $current_dir = @ftp_pwd($this->ftp_connection);
        
        foreach ($list as $item) {
            // Pular entradas especiais
            if ($item === '.' || $item === '..') {
                continue;
            }
            
            // Verificar se é um diretório
            if (@ftp_chdir($this->ftp_connection, "$current_dir/$item")) {
                $client_folders[] = array(
                    'name' => $item,
                    'path' => "$current_dir/$item"
                );
                // Voltar para o diretório atual
                @ftp_chdir($this->ftp_connection, $current_dir);
            }
        }
        
        $this->add_log("Encontradas " . count($client_folders) . " pastas de cliente");
        
        foreach ($client_folders as $client_folder) {
            $client_name = $client_folder['name'];
            $client_path = $client_folder['path'];
            
            $this->add_log("Processando cliente: $client_name");
            
            $client_processed = $this->process_ftp_client_folder($client_path, $client_name, $auto_mode);
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
     * Processar pasta do cliente via FTP
     */
    private function process_ftp_client_folder($folder_path, $client_name, $auto_mode = false) {
        $processed = 0;
        
        // Acessar diretório do cliente
        if (!@ftp_chdir($this->ftp_connection, $folder_path)) {
            $this->add_log("ERRO: Não foi possível acessar a pasta do cliente: $folder_path");
            return 0;
        }
        
        // Obter lista de arquivos
        $client_files = $this->get_all_ftp_files($folder_path);
        
        if (empty($client_files)) {
            $this->add_log("Nenhum arquivo encontrado para o cliente $client_name");
            return 0;
        }
        
        $this->add_log("Encontrados " . count($client_files) . " arquivos para o cliente $client_name");
        
        // Lista de arquivos já processados
        $processed_files = get_option('ftp_processed_files', array());
        
        foreach ($client_files as $file) {
            $file_name = $file['name'];
            $file_path = $file['path'];
            $file_size = $file['size'];
            $file_time = $file['time'];
            
            // Criar hash único para o arquivo
            $file_hash = md5($file_path . '_' . $file_size . '_' . $file_time);
            
            // Verificar se já processamos este arquivo
            if (isset($processed_files[$file_hash])) {
                continue;
            }
            
            $this->add_log("Novo arquivo detectado: $file_name");
            $this->add_log("Criando produto para: $file_name");
            
            // Criar produto para este arquivo
            $product_id = $this->create_product_from_ftp_file($file, $client_name);
            
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
            update_option('ftp_processed_files', $processed_files);
        }
        
        return $processed;
    }
    
    /**
     * Obter todos os arquivos via FTP, incluindo em subpastas
     */
    private function get_all_ftp_files($dir, $current_path = '') {
        $files = array();
        $current = @ftp_pwd($this->ftp_connection);
        
        // Mudar para o diretório
        if (!empty($current_path)) {
            if (!@ftp_chdir($this->ftp_connection, $dir)) {
                $this->add_log("AVISO: Não foi possível acessar o diretório: $dir");
                return $files;
            }
        }
        
        // Listar conteúdo
        $list = @ftp_nlist($this->ftp_connection, ".");
        
        if (!is_array($list)) {
            $this->add_log("AVISO: Falha ao listar conteúdo do diretório: " . @ftp_pwd($this->ftp_connection));
            return $files;
        }
        
        foreach ($list as $item) {
            // Pular entradas especiais
            if ($item === '.' || $item === '..') {
                continue;
            }
            
            $current_dir = @ftp_pwd($this->ftp_connection);
            $full_path = $current_dir . '/' . $item;
            
            // Verificar se é um diretório
            if (@ftp_chdir($this->ftp_connection, $full_path)) {
                // É um diretório, processar recursivamente
                @ftp_chdir($this->ftp_connection, $current_dir);
                $sub_files = $this->get_all_ftp_files($full_path, $full_path);
                $files = array_merge($files, $sub_files);
            } else {
                // É um arquivo
                $file_size = @ftp_size($this->ftp_connection, $item);
                
                if ($file_size > 0) {
                    $files[] = array(
                        'name' => $item,
                        'path' => $current_dir . '/' . $item,
                        'size' => $file_size,
                        'time' => @ftp_mdtm($this->ftp_connection, $item) ?: time()
                    );
                }
            }
        }
        
        return $files;
    }
    
    /**
     * Criar produto WooCommerce para o arquivo FTP
     */
    private function create_product_from_ftp_file($file, $client_name) {
        // Informações do arquivo
        $file_name = $file['name'];
        $file_path = $file['path'];
        $file_size = $file['size'];
        
        $this->add_log("Processando arquivo: {$file_name} (" . size_format($file_size) . ")");
        
        // Gerar título do produto
        $title = $this->generate_product_title($file_name, $client_name);
        
        // Configurações do produto
        $price = get_option('ftp_default_price', '10');
        $status = get_option('ftp_product_status', 'publish');
        
        // Verificar WooCommerce
        if (!function_exists('wc_get_product') || !class_exists('WC_Product')) {
            $this->add_log("ERRO: Funções ou classes do WooCommerce não existem");
            return false;
        }
        
        try {
            // Baixar o arquivo do servidor FTP
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
            
            $this->add_log("Baixando arquivo para: {$target_path}");
            
            // Bailar ao diretório correto
            $dir = dirname($file_path);
            $basename = basename($file_path);
            
            if (!@ftp_chdir($this->ftp_connection, $dir)) {
                $this->add_log("ERRO: Não foi possível acessar o diretório do arquivo: $dir");
                return false;
            }
            
            // Baixar arquivo
            if (@ftp_get($this->ftp_connection, $target_path, $basename, FTP_BINARY)) {
                $this->add_log("Arquivo baixado com sucesso");
            } else {
                $this->add_log("ERRO: Falha ao baixar arquivo do FTP");
                return false;
            }
            
            // Verificar se o arquivo foi baixado corretamente
            if (!file_exists($target_path) || filesize($target_path) <= 0) {
                $this->add_log("ERRO: Arquivo baixado inválido");
                @unlink($target_path);
                return false;
            }
            
            // Criar produto
            $product = new WC_Product();
            
            // Título e descrições
            $product->set_name($title);
            
            $file_ext = pathinfo($file_name, PATHINFO_EXTENSION);
            
            $description = "Arquivo: {$file_name}\n";
            $description .= "Tipo: " . strtoupper($file_ext) . "\n";
            $description .= "Tamanho: " . size_format($file_size) . "\n";
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
            
            // Salvar produto
            $this->add_log("Salvando produto no banco de dados...");
            $product_id = $product->save();
            
            if (!$product_id) {
                $this->add_log("ERRO: Falha ao salvar produto. Retorno vazio.");
                return false;
            }
            
            // Adicionar metadados extras
            update_post_meta($product_id, '_ftp_source_path', $file_path);
            update_post_meta($product_id, '_ftp_client', $client_name);
            
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
     * Reagendar CRON quando configurações mudarem
     */
    public function maybe_reschedule_cron($old_value, $new_value) {
        // Remover agendamento existente
        $timestamp = wp_next_scheduled('ftp_auto_scan_hook');
        if ($timestamp) {
            wp_unschedule_event($timestamp, 'ftp_auto_scan_hook');
        }
        
        // Se automação estiver ativa, agendar novamente
        if (get_option('ftp_auto_enabled', 'yes') === 'yes') {
            $frequency = get_option('ftp_auto_frequency', 'minutely');
            wp_schedule_event(time(), $frequency, 'ftp_auto_scan_hook');
        }
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
        update_option('ftp_recent_log', $log_text);
    }
}

// Inicializar plugin
add_action('plugins_loaded', function() {
    if (class_exists('WooCommerce')) {
        global $ftp_to_woo;
        $ftp_to_woo = new FTP_To_Woo_Ultra();
        
        // Registrar AJAX para teste de conexão FTP
        add_action('wp_ajax_test_ftp_connection', array($ftp_to_woo, 'test_ftp_connection_ajax'));
    }
});