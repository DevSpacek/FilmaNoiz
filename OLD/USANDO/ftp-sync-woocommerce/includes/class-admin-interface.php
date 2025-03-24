<?php
/**
 * Classe para gerenciar a interface administrativa
 */

if (!defined('ABSPATH')) {
    exit; // Saída se acessado diretamente
}

class FTP_Sync_Admin_Interface {
    
    /**
     * Construtor
     */
    public function __construct() {
        // CORREÇÃO: Adicionar menu com prioridade alta
        add_action('admin_menu', array($this, 'add_menu_pages'), 30);
        add_action('admin_init', array($this, 'register_settings'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_assets'));
    }
    
    /**
     * Adicionar páginas de menu
     */
    public function add_menu_pages() {
        add_menu_page(
            'FTP Sync',
            'FTP Sync',
            'manage_options',
            'ftp-sync-woocommerce',
            array($this, 'render_main_page'),
            'dashicons-share-alt',
            57
        );
        
        add_submenu_page(
            'ftp-sync-woocommerce',
            'Configurações',
            'Configurações',
            'manage_options',
            'ftp-sync-woocommerce',
            array($this, 'render_main_page')
        );
        
        add_submenu_page(
            'ftp-sync-woocommerce',
            'Logs',
            'Logs',
            'manage_options',
            'ftp-sync-logs',
            array($this, 'render_logs_page')
        );
        
        add_submenu_page(
            'ftp-sync-woocommerce',
            'Status',
            'Status',
            'manage_options',
            'ftp-sync-status',
            array($this, 'render_status_page')
        );
    }


    /**
     * Registrar configurações
     */
    public function register_settings() {
        register_setting('ftp_sync_settings', 'ftp_sync_ftp_host');
        register_setting('ftp_sync_settings', 'ftp_sync_ftp_port');
        register_setting('ftp_sync_settings', 'ftp_sync_ftp_username');
        register_setting('ftp_sync_settings', 'ftp_sync_ftp_password');
        register_setting('ftp_sync_settings', 'ftp_sync_ftp_passive');
        register_setting('ftp_sync_settings', 'ftp_sync_ftp_base_path');
        register_setting('ftp_sync_settings', 'ftp_sync_check_interval');
        register_setting('ftp_sync_settings', 'ftp_sync_product_status');
        register_setting('ftp_sync_settings', 'ftp_sync_product_price');
        register_setting('ftp_sync_settings', 'ftp_sync_debug_mode');
    }
    
    /**
     * Carregar assets
     */
    public function enqueue_assets($hook) {
        if (strpos($hook, 'ftp-sync') === false) {
            return;
        }
        
        wp_enqueue_style(
            'ftp-sync-admin-css',
            FTP_SYNC_URL . 'assets/css/admin.css',
            array(),
            FTP_SYNC_VERSION
        );
        
        wp_enqueue_script(
            'ftp-sync-admin-js',
            FTP_SYNC_URL . 'assets/js/admin.js',
            array('jquery'),
            FTP_SYNC_VERSION,
            true
        );
        
        wp_localize_script('ftp-sync-admin-js', 'ftpSyncData', array(
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('ftp_sync_nonce')
        ));
    }
    
    /**
     * Renderizar página principal
     */
    public function render_main_page() {
        // Verificar permissões
        if (!current_user_can('manage_options')) {
            wp_die('Acesso negado');
        }
        
        // Obter configurações
        $ftp_host = get_option('ftp_sync_ftp_host', '');
        $ftp_port = get_option('ftp_sync_ftp_port', '21');
        $ftp_username = get_option('ftp_sync_ftp_username', '');
        $ftp_password = get_option('ftp_sync_ftp_password', '');
        $ftp_passive = get_option('ftp_sync_ftp_passive', 'yes');
        $ftp_base_path = get_option('ftp_sync_ftp_base_path', '/');
        
        $check_interval = get_option('ftp_sync_check_interval', 'hourly');
        $product_status = get_option('ftp_sync_product_status', 'publish');
        $product_price = get_option('ftp_sync_product_price', '10');
        $debug_mode = get_option('ftp_sync_debug_mode', 'no');
        
        $security_key = get_option('ftp_sync_security_key', '');
        $last_check = get_option('ftp_sync_last_check', 0);
        
        // Obter URL de cron externo
        $cron_url = home_url('/ftp-sync/process/' . $security_key);
        
        ?>
        <div class="wrap">
            <h1>FTP Sync para WooCommerce</h1>
            
            <div class="ftp-sync-intro">
                <p>Configure o plugin para sincronizar arquivos do servidor FTP para produtos WooCommerce automaticamente.</p>
            </div>
            
            <?php if (empty($ftp_host) || empty($ftp_username) || empty($ftp_password)): ?>
            <div class="notice notice-warning">
                <p><strong>Configurações incompletas!</strong> Por favor, configure as informações do servidor FTP.</p>
            </div>
            <?php endif; ?>
            
            <div class="ftp-sync-dashboard">
                <div class="ftp-sync-section">
                    <h2>Configurações</h2>
                    
                    <form method="post" action="options.php">
                        <?php settings_fields('ftp_sync_settings'); ?>
                        
                        <div class="ftp-sync-panel">
                            <h3>Servidor FTP</h3>
                            <table class="form-table">
                                <tr>
                                    <th scope="row">Servidor FTP</th>
                                    <td><input type="text" name="ftp_sync_ftp_host" value="<?php echo esc_attr($ftp_host); ?>" class="regular-text" /></td>
                                </tr>
                                <tr>
                                    <th scope="row">Porta FTP</th>
                                    <td><input type="text" name="ftp_sync_ftp_port" value="<?php echo esc_attr($ftp_port); ?>" class="small-text" /></td>
                                </tr>
                                <tr>
                                    <th scope="row">Usuário FTP</th>
                                    <td><input type="text" name="ftp_sync_ftp_username" value="<?php echo esc_attr($ftp_username); ?>" class="regular-text" /></td>
                                </tr>
                                <tr>
                                    <th scope="row">Senha FTP</th>
                                    <td><input type="password" name="ftp_sync_ftp_password" value="<?php echo esc_attr($ftp_password); ?>" class="regular-text" /></td>
                                </tr>
                                <tr>
                                    <th scope="row">Modo Passivo</th>
                                    <td>
                                        <select name="ftp_sync_ftp_passive">
                                            <option value="yes" <?php selected($ftp_passive, 'yes'); ?>>Sim</option>
                                            <option value="no" <?php selected($ftp_passive, 'no'); ?>>Não</option>
                                        </select>
                                        <p class="description">Recomendado para a maioria dos servidores</p>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row">Pasta Base</th>
                                    <td>
                                        <input type="text" name="ftp_sync_ftp_base_path" value="<?php echo esc_attr($ftp_base_path); ?>" class="regular-text" />
                                        <p class="description">Diretório no servidor FTP onde estão as pastas dos clientes</p>
                                    </td>
                                </tr>
                            </table>
                        </div>
                        
                        <div class="ftp-sync-panel">
                            <h3>Configurações de Sincronização</h3>
                            <table class="form-table">
                                <tr>
                                    <th scope="row">Intervalo de Verificação</th>
                                    <td>
                                        <select name="ftp_sync_check_interval">
                                            <option value="hourly" <?php selected($check_interval, 'hourly'); ?>>A cada hora</option>
                                            <option value="twicedaily" <?php selected($check_interval, 'twicedaily'); ?>>Duas vezes ao dia</option>
                                            <option value="daily" <?php selected($check_interval, 'daily'); ?>>Diariamente</option>
                                        </select>
                                        <p class="description">Com que frequência o plugin verifica novos arquivos</p>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row">Status dos Produtos</th>
                                    <td>
                                        <select name="ftp_sync_product_status">
                                            <option value="publish" <?php selected($product_status, 'publish'); ?>>Publicado</option>
                                            <option value="draft" <?php selected($product_status, 'draft'); ?>>Rascunho</option>
                                            <option value="private" <?php selected($product_status, 'private'); ?>>Privado</option>
                                        </select>
                                        <p class="description">Status dos produtos ao serem criados</p>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row">Preço Padrão</th>
                                    <td>
                                        <input type="text" name="ftp_sync_product_price" value="<?php echo esc_attr($product_price); ?>" class="small-text" />
                                        <p class="description">Preço padrão para os produtos criados</p>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row">Modo Debug</th>
                                    <td>
                                        <select name="ftp_sync_debug_mode">
                                            <option value="yes" <?php selected($debug_mode, 'yes'); ?>>Ativado</option>
                                            <option value="no" <?php selected($debug_mode, 'no'); ?>>Desativado</option>
                                        </select>
                                        <p class="description">Registrar informações detalhadas de diagnóstico</p>
                                    </td>
                                </tr>
                            </table>
                        </div>
                        
                        <?php submit_button('Salvar Configurações'); ?>
                    </form>
                </div>
                
                <div class="ftp-sync-section">
                    <h2>Ações</h2>
                    
                    <div class="ftp-sync-panel">
                        <h3>Teste de Conexão</h3>
                        <p>Verifique se as configurações FTP estão corretas:</p>
                        <button id="ftp-sync-test-connection" class="button button-primary">Testar Conexão FTP</button>
                        <div id="ftp-sync-test-result" class="ftp-sync-result"></div>
                    </div>
                    
                    <div class="ftp-sync-panel">
                        <h3>Sincronização Manual</h3>
                        <p>Execute uma sincronização agora:</p>
                        <button id="ftp-sync-manual-sync" class="button button-primary">Sincronizar Agora</button>
                        <div id="ftp-sync-manual-result" class="ftp-sync-result"></div>
                    </div>
                    
                    <div class="ftp-sync-panel">
                        <h3>Sincronização Externa</h3>
                        <p>URL para cron externo:</p>
                        <input type="text" readonly value="<?php echo esc_url($cron_url); ?>" class="large-text code" />
                        <p class="description">Use esta URL com um serviço de cron externo para realizar sincronizações automáticas:</p>
                        <pre>*/15 * * * * wget -q -O /dev/null "<?php echo esc_url($cron_url); ?>"</pre>
                    </div>
                    
                    <div class="ftp-sync-panel">
                        <h3>Status</h3>
                        <p><strong>Última verificação:</strong> <?php echo $last_check ? date('d/m/Y H:i:s', $last_check) : 'Nunca'; ?></p>
                        <p><strong>Próxima verificação:</strong> <?php 
                            $next_run = wp_next_scheduled('ftp_sync_check_event');
                            echo $next_run ? date('d/m/Y H:i:s', $next_run) : 'Não agendado';
                        ?></p>
                        <?php 
                        $processed_files = get_option('ftp_sync_processed_files', array());
                        echo '<p><strong>Total de arquivos processados:</strong> ' . count($processed_files) . '</p>';
                        ?>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }
    
    /**
     * Renderizar página de logs
     */
    public function render_logs_page() {
        // Verificar permissões
        if (!current_user_can('manage_options')) {
            wp_die('Acesso negado');
        }
        
        $upload_dir = wp_upload_dir();
        $logs_dir = $upload_dir['basedir'] . '/ftp-sync-logs';
        
        ?>
        <div class="wrap">
            <h1>FTP Sync - Logs</h1>
            
            <div class="ftp-sync-logs">
                <?php
                // Obter arquivos de log
                $log_files = array();
                if (file_exists($logs_dir)) {
                    $log_files = glob($logs_dir . '/sync-*.log');
                }
                
                if (empty($log_files)) {
                    echo '<p>Nenhum log disponível.</p>';
                } else {
                    // Ordenar por data (mais recentes primeiro)
                    rsort($log_files);
                    
                    // Mostrar seletor de logs
                    echo '<h2>Selecione um Log</h2>';
                    echo '<select id="ftp-sync-log-selector">';
                    foreach ($log_files as $log_file) {
                        $log_date = basename($log_file, '.log');
                        $log_date = str_replace('sync-', '', $log_date);
                        echo '<option value="' . esc_attr($log_file) . '">' . esc_html($log_date) . '</option>';
                    }
                    echo '</select>';
                    
                    // Mostrar conteúdo do log
                    echo '<h3>Conteúdo</h3>';
                    echo '<div id="ftp-sync-log-content" class="ftp-sync-log-viewer">';
                    echo '<pre>' . esc_html(file_get_contents($log_files[0])) . '</pre>';
                    echo '</div>';
                }
                ?>
            </div>
            
            <script>
            jQuery(document).ready(function($) {
                $('#ftp-sync-log-selector').on('change', function() {
                    var logFile = $(this).val();
                    
                    $.ajax({
                        url: ajaxurl,
                        type: 'POST',
                        data: {
                            action: 'ftp_sync_view_log',
                            log_file: logFile,
                            security: ftpSyncData.nonce
                        },
                        success: function(response) {
                            if (response.success) {
                                $('#ftp-sync-log-content pre').text(response.data);
                            } else {
                                $('#ftp-sync-log-content pre').text('Erro ao carregar log: ' + response.data);
                            }
                        },
                        error: function() {
                            $('#ftp-sync-log-content pre').text('Erro de conexão');
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
    public function render_status_page() {
        // Verificar permissões
        if (!current_user_can('manage_options')) {
            wp_die('Acesso negado');
        }
        
        // Estatísticas
        $processed_files = get_option('ftp_sync_processed_files', array());
        $last_check = get_option('ftp_sync_last_check', 0);
        
        // Produtos por cliente
        $client_stats = array();
        foreach ($processed_files as $file) {
            $client = isset($file['client']) ? $file['client'] : 'Desconhecido';
            
            if (!isset($client_stats[$client])) {
                $client_stats[$client] = 0;
            }
            
            $client_stats[$client]++;
        }
        
        ?>
        <div class="wrap">
            <h1>FTP Sync - Status</h1>
            
            <div class="ftp-sync-status">
                <div class="ftp-sync-panel">
                    <h3>Estatísticas</h3>
                    <table class="widefat">
                        <tr>
                            <th>Total de arquivos processados</th>
                            <td><?php echo count($processed_files); ?></td>
                        </tr>
                        <tr>
                            <th>Última verificação</th>
                            <td><?php echo $last_check ? date('d/m/Y H:i:s', $last_check) : 'Nunca'; ?></td>
                        </tr>
                        <tr>
                            <th>Próxima verificação agendada</th>
                            <td>
                                <?php 
                                $next_run = wp_next_scheduled('ftp_sync_check_event');
                                echo $next_run ? date('d/m/Y H:i:s', $next_run) : 'Não agendado';
                                ?>
                            </td>
                        </tr>
                    </table>
                </div>
                
                <?php if (!empty($client_stats)): ?>
                <div class="ftp-sync-panel">
                    <h3>Arquivos por Cliente</h3>
                    <table class="widefat">
                        <thead>
                            <tr>
                                <th>Cliente</th>
                                <th>Arquivos</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($client_stats as $client => $count): ?>
                            <tr>
                                <td><?php echo esc_html($client); ?></td>
                                <td><?php echo $count; ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
                
                <div class="ftp-sync-panel">
                    <h3>Últimos Produtos Criados</h3>
                    <?php
                    // Obtém os últimos 10 produtos criados pelo plugin
                    $recent_files = array_slice($processed_files, -10, 10, true);
                    
                    if (empty($recent_files)) {
                        echo '<p>Nenhum produto criado ainda.</p>';
                    } else {
                        echo '<table class="widefat">';
                        echo '<thead><tr><th>Arquivo</th><th>Cliente</th><th>Data</th><th>Produto</th></tr></thead>';
                        echo '<tbody>';
                        
                        foreach ($recent_files as $hash => $file) {
                            echo '<tr>';
                            echo '<td>' . esc_html($file['file']) . '</td>';
                            echo '<td>' . esc_html(isset($file['client']) ? $file['client'] : 'Desconhecido') . '</td>';
                            echo '<td>' . date('d/m/Y H:i:s', $file['time']) . '</td>';
                            echo '<td><a href="' . admin_url('post.php?post=' . $file['product_id'] . '&action=edit') . '">' . $file['product_id'] . '</a></td>';
                            echo '</tr>';
                        }
                        
                        echo '</tbody></table>';
                    }
                    ?>
                </div>
                
                <div class="ftp-sync-panel">
                    <h3>Ambiente do Sistema</h3>
                    <table class="widefat">
                        <tr>
                            <th>WordPress</th>
                            <td><?php echo get_bloginfo('version'); ?></td>
                        </tr>
                        <tr>
                            <th>WooCommerce</th>
                            <td><?php echo defined('WC_VERSION') ? WC_VERSION : 'Não detectado'; ?></td>
                        </tr>
                        <tr>
                            <th>PHP</th>
                            <td><?php echo PHP_VERSION; ?></td>
                        </tr>
                        <tr>
                            <th>Extensão FTP</th>
                            <td><?php echo function_exists('ftp_connect') ? 'Disponível' : 'Indisponível'; ?></td>
                        </tr>
                        <tr>
                            <th>JetEngine</th>
                            <td><?php echo defined('JET_ENGINE_VERSION') ? JET_ENGINE_VERSION : 'Não detectado'; ?></td>
                        </tr>
                    </table>
                </div>
            </div>
        </div>
        <?php
    }
    
    /**
     * AJAX - Testar conexão FTP
     */
    public function test_connection() {
        // Verificar nonce
        check_ajax_referer('ftp_sync_nonce', 'security');
        
        // Verificar permissões
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Acesso negado');
        }
        
        $connector = new FTP_Sync_Connector();
        $connection_result = $connector->connect();
        
        if ($connection_result) {
            // Listar diretório base
            $base_path = get_option('ftp_sync_ftp_base_path', '/');
            
            try {
                // Tentar mudar para o diretório base
                if (!@ftp_chdir($connector->connection, $base_path)) {
                    $connector->disconnect();
                    wp_send_json_error("Conexão bem sucedida, mas não foi possível acessar o diretório: {$base_path}");
                    return;
                }
                
                // Listar conteúdo
                $items = @ftp_nlist($connector->connection, '.');
                
                if (!is_array($items) || empty($items)) {
                    $connector->disconnect();
                    wp_send_json_error("Conexão bem sucedida, mas o diretório {$base_path} está vazio ou não pode ser listado");
                    return;
                }
                
                // Contar pastas e arquivos
                $folders = 0;
                foreach ($items as $item) {
                    if ($item != '.' && $item != '..' && @ftp_size($connector->connection, $item) === -1) {
                        $folders++;
                    }
                }
                
                $connector->disconnect();
                
                wp_send_json_success("Conexão bem sucedida! Encontradas {$folders} pastas no diretório {$base_path}");
                
            } catch (Exception $e) {
                $connector->disconnect();
                wp_send_json_error("Erro ao listar diretório: " . $e->getMessage());
            }
            
        } else {
            wp_send_json_error("Falha na conexão com o servidor FTP. Verifique as configurações.");
        }
    }
    
    /**
     * AJAX - Sincronização manual
     */
    public function manual_sync() {
        // Verificar nonce
        check_ajax_referer('ftp_sync_nonce', 'security');
        
        // Verificar permissões
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Acesso negado');
        }
        
        ftp_sync_woocommerce()->scheduled_sync();
        
        $processed_files = get_option('ftp_sync_processed_files', array());
        $count = count($processed_files);
        
        wp_send_json_success("Sincronização concluída. Total de {$count} produtos cadastrados.");
    }
    
    /**
     * AJAX - Visualizar log
     */
    public function view_log() {
        // Verificar nonce
        check_ajax_referer('ftp_sync_nonce', 'security');
        
        // Verificar permissões
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Acesso negado');
        }
        
        $log_file = isset($_POST['log_file']) ? sanitize_text_field($_POST['log_file']) : '';
        
        if (empty($log_file) || !file_exists($log_file)) {
            wp_send_json_error('Arquivo de log não encontrado');
        }
        
        $content = file_get_contents($log_file);
        
        if ($content === false) {
            wp_send_json_error('Erro ao ler arquivo de log');
        }
        
        wp_send_json_success($content);
    }
}