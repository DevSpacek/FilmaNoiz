<?php
/**
 * Plugin Name: SFTP to WooCommerce - Reliable Auto
 * Description: Converte arquivos SFTP em produtos WooCommerce com automação confiável
 * Version: 1.4
 * Author: DevSpacek
 * Date: 2025-03-18
 */

if (!defined('ABSPATH')) {
    exit;
}

class SFTP_To_Woo_Auto {
    
    private $log = array();
    private $last_execution_time = 0;
    private $lock_file;
    
    public function __construct() {
        // Arquivo de lock para evitar execuções simultâneas
        $upload_dir = wp_upload_dir();
        $this->lock_file = $upload_dir['basedir'] . '/sftp_woo_process.lock';
        
        // Adicionar intervalo de cron personalizado para cada minuto
        add_filter('cron_schedules', array($this, 'add_cron_interval'));
        
        // Verificar WooCommerce
        add_action('admin_init', array($this, 'check_woocommerce'));
        
        // Admin
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_init', array($this, 'register_settings'));
        add_action('admin_notices', array($this, 'admin_notices'));
        add_action('admin_footer', array($this, 'admin_footer_scripts'));
        
        // Processamento
        add_action('admin_post_scan_sftp_folders', array($this, 'process_manual_scan'));
        add_action('wp_ajax_trigger_sftp_scan', array($this, 'ajax_trigger_scan'));
        
        // Automation - Múltiplos métodos para garantir execução
        add_action('sftp_auto_scan_hook', array($this, 'auto_scan_folders'));
        add_action('init', array($this, 'maybe_force_scan'));
        
        // Ativar cronograma na ativação do plugin
        register_activation_hook(__FILE__, array($this, 'activate_cron'));
        register_deactivation_hook(__FILE__, array($this, 'deactivate_cron'));
    }
    
    /**
     * Adicionar intervalo de cron personalizado para cada minuto
     */
    public function add_cron_interval($schedules) {
        $schedules['minutely'] = array(
            'interval' => 60, // 60 segundos = 1 minuto
            'display'  => 'A cada minuto'
        );
        
        $schedules['every5minutes'] = array(
            'interval' => 300, // 300 segundos = 5 minutos
            'display'  => 'A cada 5 minutos'
        );
        
        return $schedules;
    }
    
    /**
     * Configurar agendamento CRON na ativação
     */
    public function activate_cron() {
        // Primeiro desativar qualquer agendamento existente
        $this->deactivate_cron();
        
        // Agendar para rodar a cada minuto por padrão
        if (!wp_next_scheduled('sftp_auto_scan_hook')) {
            wp_schedule_event(time(), 'minutely', 'sftp_auto_scan_hook');
            update_option('sftp_auto_enabled', 'yes');
            update_option('sftp_auto_frequency', 'minutely');
        }
        
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
     * Verificar WooCommerce
     */
    public function check_woocommerce() {
        if (!class_exists('WooCommerce')) {
            add_action('admin_notices', function() {
                echo '<div class="notice notice-error"><p>O plugin <strong>SFTP to WooCommerce</strong> requer o WooCommerce ativo.</p></div>';
            });
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
            'default' => 'draft'
        ));
        register_setting('sftp_to_woo_settings', 'sftp_debug_mode', array(
            'default' => 'yes'
        ));
        register_setting('sftp_to_woo_settings', 'sftp_auto_enabled', array(
            'default' => 'yes'
        ));
        register_setting('sftp_to_woo_settings', 'sftp_auto_frequency', array(
            'default' => 'minutely'
        ));
        register_setting('sftp_to_woo_settings', 'sftp_force_minutes', array(
            'default' => '1'  // Verificar a cada 1 minuto
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
            $log = get_transient('sftp_woo_debug_log');
            if ($log) {
                echo '<div class="notice notice-info is-dismissible"><p>Log de depuração:</p><pre style="background:#f8f8f8;padding:10px;max-height:400px;overflow-y:auto;">' . esc_html($log) . '</pre></div>';
            }
        }
        
        // Verificar se o WP-Cron está ativado
        if (!defined('DISABLE_WP_CRON') || DISABLE_WP_CRON !== true) {
            $next_run = wp_next_scheduled('sftp_auto_scan_hook');
            if (!$next_run) {
                echo '<div class="notice notice-warning"><p><strong>Atenção!</strong> O agendamento do WP-Cron para o SFTP não está ativo. <a href="#" id="sftp-fix-cron">Clique aqui para corrigir</a>.</p></div>';
            }
        } else {
            echo '<div class="notice notice-warning"><p><strong>Atenção!</strong> O WP-Cron está desativado neste site (DISABLE_WP_CRON está definido como true). O escaneamento automático foi configurado para usar um método alternativo.</p></div>';
        }
    }
    
    /**
     * Scripts para o rodapé do admin
     */
    public function admin_footer_scripts() {
        if (!isset($_GET['page']) || $_GET['page'] !== 'sftp-to-woo-auto') {
            return;
        }
        ?>
        <script type="text/javascript">
            jQuery(document).ready(function($) {
                $('#sftp-fix-cron').on('click', function(e) {
                    e.preventDefault();
                    
                    $.ajax({
                        url: ajaxurl,
                        type: 'POST',
                        data: {
                            action: 'trigger_sftp_scan',
                            fix_cron: true,
                            security: '<?php echo wp_create_nonce('sftp-ajax-nonce'); ?>'
                        },
                        success: function(response) {
                            if (response.success) {
                                alert('Cronograma corrigido! O escaneamento automático agora está ativo.');
                                location.reload();
                            } else {
                                alert('Erro ao corrigir o cronograma: ' + response.data);
                            }
                        },
                        error: function() {
                            alert('Erro de comunicação com o servidor.');
                        }
                    });
                });
                
                // Atualizar o status a cada 30 segundos
                setInterval(function() {
                    if ($('#sftp-auto-status').length > 0) {
                        $.ajax({
                            url: ajaxurl,
                            type: 'POST',
                            data: {
                                action: 'trigger_sftp_scan',
                                get_status: true,
                                security: '<?php echo wp_create_nonce('sftp-ajax-nonce'); ?>'
                            },
                            success: function(response) {
                                if (response.success) {
                                    $('#sftp-auto-status').html(response.data.html);
                                }
                            }
                        });
                    }
                }, 30000);
            });
        </script>
        <?php
    }
    
    /**
     * AJAX para acionar scan e corrigir cron
     */
    public function ajax_trigger_scan() {
        check_ajax_referer('sftp-ajax-nonce', 'security');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Permissão negada');
            return;
        }
        
        // Para correção do cron
        if (isset($_POST['fix_cron'])) {
            $timestamp = wp_next_scheduled('sftp_auto_scan_hook');
            if ($timestamp) {
                wp_unschedule_event($timestamp, 'sftp_auto_scan_hook');
            }
            
            // Re-agendar cron
            $frequency = get_option('sftp_auto_frequency', 'minutely');
            if (wp_schedule_event(time(), $frequency, 'sftp_auto_scan_hook')) {
                update_option('sftp_cron_fixed_time', time());
                wp_send_json_success();
            } else {
                wp_send_json_error('Falha ao agendar o evento cron');
            }
            return;
        }
        
        // Para status em tempo real
        if (isset($_POST['get_status'])) {
            $next_run = wp_next_scheduled('sftp_auto_scan_hook');
            $last_auto_time = get_option('sftp_last_auto_time', 0);
            $last_processed = get_option('sftp_last_processed_count', 0);
            
            $html = '<div class="sftp-status-update">';
            $html .= '<p>Última execução automática: ' . ($last_auto_time ? date('d/m/Y H:i:s', $last_auto_time) : 'Nunca') . '</p>';
            $html .= '<p>Último número de produtos processados: ' . $last_processed . '</p>';
            
            if ($next_run) {
                $time_diff = $next_run - time();
                if ($time_diff > 0) {
                    $html .= '<p>Próxima execução: ' . date('d/m/Y H:i:s', $next_run) . ' (em ' . human_time_diff(time(), $next_run) . ')</p>';
                } else {
                    $html .= '<p>Próxima execução: Agendada, mas atrasada. Verificando agora se necessário.</p>';
                    
                    // Verificar se devemos forçar uma execução
                    if (time() - $last_auto_time > 300) { // 5 minutos
                        $result = $this->auto_scan_folders();
                        $html .= '<p>Execução forçada iniciada! Produtos processados: ' . $result . '</p>';
                    }
                }
            } else {
                $html .= '<p>Cronograma: <span style="color:red;">Não agendado</span> - Recarregue a página e clique em "Corrigir" quando solicitado.</p>';
            }
            
            $html .= '</div>';
            
            wp_send_json_success(array('html' => $html));
            return;
        }
        
        wp_send_json_error('Comando inválido');
    }

    /**
     * Método alternativo para forçar escaneamento baseado em tráfego
     */
    public function maybe_force_scan() {
        // Somente executar em requisições normais de página (não em AJAX, etc)
        if (wp_doing_ajax() || wp_doing_cron() || is_admin() || (defined('WP_CLI') && WP_CLI)) {
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
        
        // Tudo certo para executar via método alternativo
        $this->auto_scan_folders(true);
    }
    
    /**
     * Verificar e criar bloqueio de processo
     */
    private function is_process_locked() {
        // Se o arquivo de lock existe e tem menos de 5 minutos
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
        $product_status = get_option('sftp_product_status', 'draft');
        $debug_mode = get_option('sftp_debug_mode', 'yes');
        $auto_enabled = get_option('sftp_auto_enabled', 'yes');
        $auto_frequency = get_option('sftp_auto_frequency', 'minutely');
        $force_minutes = get_option('sftp_force_minutes', '1');
        
        // Verificar status do WooCommerce
        $woo_active = class_exists('WooCommerce');
        $wc_product_class = class_exists('WC_Product');
        $woo_version = $woo_active ? WC()->version : 'N/A';
        
        // Status do cron
        $is_cron_active = !defined('DISABLE_WP_CRON') || DISABLE_WP_CRON !== true;
        $next_run = wp_next_scheduled('sftp_auto_scan_hook');
        $cron_status = $next_run ? 'Ativo' : 'Inativo';
        
        ?>
        <div class="wrap">
            <h1>SFTP para WooCommerce (A Cada Minuto - Confiável)</h1>
            
            <div class="notice notice-info">
                <p><strong>Status do Sistema:</strong></p>
                <ul style="list-style:disc;padding-left:20px;">
                    <li>WooCommerce Ativo: <?php echo $woo_active ? '<span style="color:green;">✓ Sim</span>' : '<span style="color:red;">✗ Não</span>'; ?></li>
                    <li>Versão do WooCommerce: <?php echo esc_html($woo_version); ?></li>
                    <li>Data atual do servidor: <?php echo date('Y-m-d H:i:s'); ?></li>
                    <li>WP-Cron: <?php echo $is_cron_active ? '<span style="color:green;">✓ Habilitado</span>' : '<span style="color:orange;">✗ Desabilitado (usando método alternativo)</span>'; ?></li>
                    <li>Status do agendamento: <span style="<?php echo $next_run ? 'color:green;' : 'color:red;'; ?>"><?php echo $cron_status; ?></span></li>
                    <li>Último processamento manual: <?php echo get_option('sftp_last_process_time') ? date('d/m/Y H:i:s', get_option('sftp_last_process_time')) : 'Nunca'; ?></li>
                    <li>Último processamento automático: <?php echo get_option('sftp_last_auto_time') ? date('d/m/Y H:i:s', get_option('sftp_last_auto_time')) : 'Nunca'; ?></li>
                </ul>
                
                <div id="sftp-auto-status">
                    <?php if ($auto_enabled === 'yes'): ?>
                        <?php if ($next_run): ?>
                            <p>Próximo escaneamento automático: <?php echo date('d/m/Y H:i:s', $next_run); ?> (em <?php echo human_time_diff(time(), $next_run); ?>)</p>
                        <?php else: ?>
                            <p>Cronograma: <span style="color:red;">Não agendado</span> - Clique em "Corrigir" quando solicitado acima</p>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>
            
            <?php if (!$woo_active): ?>
                <div class="notice notice-error">
                    <p>O WooCommerce precisa estar instalado e ativado para que este plugin funcione.</p>
                </div>
            <?php endif; ?>
            
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
                                <option value="pending" <?php selected($product_status, 'pending'); ?>>Pendente</option>
                            </select>
                            <p class="description">Status dos produtos quando criados</p>
                        </td>
                    </tr>
                    
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
                        <th scope="row">Frequência Agendada</th>
                        <td>
                            <select name="sftp_auto_frequency">
                                <option value="minutely" <?php selected($auto_frequency, 'minutely'); ?>>A cada minuto (recomendado)</option>
                                <option value="every5minutes" <?php selected($auto_frequency, 'every5minutes'); ?>>A cada 5 minutos</option>
                                <option value="hourly" <?php selected($auto_frequency, 'hourly'); ?>>A cada hora</option>
                            </select>
                            <p class="description">Frequência do agendamento oficial WP-Cron</p>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row">Forçar Verificação a Cada</th>
                        <td>
                            <input type="number" name="sftp_force_minutes" value="<?php echo esc_attr($force_minutes); ?>" min="1" max="60" class="small-text" /> minutos
                            <p class="description">Verificação forçada durante visitas ao site (método alternativo). Recomendado: 1 minuto.</p>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row">Modo de Depuração</th>
                        <td>
                            <select name="sftp_debug_mode">
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
            <p>Clique no botão abaixo para escanear as pastas SFTP e criar produtos para novos arquivos imediatamente:</p>
            
            <form method="post" action="<?php echo admin_url('admin-post.php'); ?>">
                <input type="hidden" name="action" value="scan_sftp_folders">
                <?php wp_nonce_field('scan_sftp_folders_nonce'); ?>
                <?php submit_button('Escanear Pastas Agora', 'primary', 'submit', false); ?>
            </form>
            
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
        // Primeiro remover qualquer agendamento existente
        $timestamp = wp_next_scheduled('sftp_auto_scan_hook');
        if ($timestamp) {
            wp_unschedule_event($timestamp, 'sftp_auto_scan_hook');
        }
        
        // Se automação estiver ativa, agendar novamente
        if (get_option('sftp_auto_enabled', 'yes') === 'yes') {
            $frequency = get_option('sftp_auto_frequency', 'minutely');
            $result = wp_schedule_event(time(), $frequency, 'sftp_auto_scan_hook');
            
            // Registrar resultado no log
            $this->log = array();
            if ($result) {
                $this->add_log("Agendamento reconfigurado: escaneamento a cada $frequency");
            } else {
                $this->add_log("FALHA ao reconfigurar agendamento para $frequency");
            }
            $this->save_activity_log();
        }
    }
    
    /**
     * Processar scan manual
     */
    public function process_manual_scan() {
        // Verificar nonce
        check_admin_referer('scan_sftp_folders_nonce');
        
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
                'page' => 'sftp-to-woo-auto',
                'error' => 'woocommerce',
                'debug_log' => '1'
            ), admin_url('admin.php')));
            exit;
        }
        
        // Obter diretório base
        $base_dir = get_option('sftp_base_directory', '');
        $this->add_log("Diretório base: $base_dir");
        
        if (empty($base_dir) || !file_exists($base_dir) || !is_dir($base_dir)) {
            $this->add_log("ERRO: Diretório base inválido ou inacessível");
            $this->save_debug_log();
            wp_redirect(add_query_arg(array(
                'page' => 'sftp-to-woo-auto',
                'error' => 'directory',
                'debug_log' => '1'
            ), admin_url('admin.php')));
            exit;
        }
        
        // Iniciar processamento
        $result = $this->scan_directories($base_dir);
        
        // Atualizar hora do último processamento
        update_option('sftp_last_process_time', time());
        
        // Adicionar resultado final ao log
        $this->add_log("Processamento manual concluído. Total: $result produtos criados");
        $this->save_debug_log();
        
        // Redirecionar com resultado
        wp_redirect(add_query_arg(array(
            'page' => 'sftp-to-woo-auto',
            'processed' => $result,
            'debug_log' => '1'
        ), admin_url('admin.php')));
        exit;
    }
    
    /**
     * Método para escaneamento automático via CRON
     */
    public function auto_scan_folders($alternative_method = false) {
        // Verificar se automação está ativada
        if (get_option('sftp_auto_enabled', 'yes') !== 'yes') {
            return 0;
        }
        
        // Verificar se já existe um processo rodando
        if ($this->is_process_locked()) {
            // Log para debug, mas não exibir no painel
            error_log('SFTP_WOO: Processo já em execução, pulando esta execução.');
            return 0;
        }
        
        // Limpar log
        $this->log = array();
        $execution_type = $alternative_method ? 'ALTERNATIVO' : 'CRON';
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
        
        // Atualizar hora do último processamento automático e contador
        update_option('sftp_last_auto_time', time());
        update_option('sftp_last_processed_count', $result);
        
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
            $this->add_log("ERRO: Falha ao listar diretórios de clientes");
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
        update_option('sftp_processed_files', $processed_files);
        
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
        $price = get_option('sftp_default_price', '0');
        $status = get_option('sftp_product_status', 'draft');
        
        // Verificar WooCommerce
        if (!function_exists('wc_get_product') || !class_exists('WC_Product')) {
            $this->add_log("ERRO: Funções ou classes do WooCommerce não existem");
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
                $this->add_log("Criando diretório: {$target_dir}");
                wp_mkdir_p($target_dir);
            }
            
            $new_file_name = uniqid($client_name . '_') . '_' . $file_name;
            $target_path = $target_dir . $new_file_name;
            
            $this->add_log("Copiando arquivo para: {$target_path}");
            if (copy($file_path, $target_path)) {
                $this->add_log("Arquivo copiado com sucesso");
                
                $download_url = $upload_dir['baseurl'] . '/woocommerce_uploads/' . $new_file_name;
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
                $this->add_log("ERRO: Falha ao copiar arquivo");
                return false;
            }
            
            // Salvar produto
            $this->add_log("Salvando produto");
            $product_id = $product->save();
            
            if (!$product_id) {
                $this->add_log("ERRO: Falha ao salvar produto");
                return false;
            }
            
            // Adicionar metadados
            $this->add_log("Adicionando metadados ao produto");
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
        if (get_option('sftp_debug_mode', 'yes') === 'yes') {
            error_log('SFTP_WOO: ' . $message);
        }
    }
    
    /**
     * Salvar log para exibição
     */
    private function save_debug_log() {
        $log_text = implode("\n", $this->log);
        set_transient('sftp_woo_debug_log', $log_text, HOUR_IN_SECONDS);
    }
    
    /**
     * Salvar log de atividade
     */
    private function save_activity_log() {
        $log_text = implode("\n", $this->log);
        update_option('sftp_recent_log', $log_text);
    }
}

// Inicializar plugin de forma segura
add_action('plugins_loaded', function() {
    if (class_exists('WooCommerce')) {
        new SFTP_To_Woo_Auto();
    }
});