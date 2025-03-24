<?php
/**
 * Plugin Name: FTP Sync para WooCommerce
 * Description: Sincroniza arquivos de um servidor FTP para produtos WooCommerce
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

// Definições básicas
define('FTP_SYNC_VERSION', '1.0.0');
define('FTP_SYNC_PATH', plugin_dir_path(__FILE__));
define('FTP_SYNC_URL', plugin_dir_url(__FILE__));

// Hooks de ativação/desativação
register_activation_hook(__FILE__, 'ftp_sync_activation');
register_deactivation_hook(__FILE__, 'ftp_sync_deactivation');

/**
 * Função de ativação
 */
function ftp_sync_activation() {
    // Verificar requisitos
    if (version_compare(PHP_VERSION, '7.2', '<')) {
        deactivate_plugins(plugin_basename(__FILE__));
        wp_die('FTP Sync para WooCommerce requer PHP 7.2 ou superior.');
    }
    
    if (!function_exists('ftp_connect')) {
        deactivate_plugins(plugin_basename(__FILE__));
        wp_die('FTP Sync para WooCommerce requer a extensão FTP do PHP.');
    }
    
    // Criar diretórios necessários
    ftp_sync_create_directories();
    
    // Configurações padrão
    ftp_sync_set_defaults();
    
    // Configurar cron
    ftp_sync_setup_cron();
    
    // Atualizar regras de reescrita
    flush_rewrite_rules();
}

/**
 * Função de desativação
 */
function ftp_sync_deactivation() {
    // Remover tarefas agendadas
    wp_clear_scheduled_hook('ftp_sync_check_files');
    
    // Atualizar regras de reescrita
    flush_rewrite_rules();
}

/**
 * Criar diretórios necessários
 */
function ftp_sync_create_directories() {
    // Diretórios do plugin
    if (!file_exists(FTP_SYNC_PATH . 'includes')) {
        wp_mkdir_p(FTP_SYNC_PATH . 'includes');
    }
    
    if (!file_exists(FTP_SYNC_PATH . 'assets/css')) {
        wp_mkdir_p(FTP_SYNC_PATH . 'assets/css');
    }
    
    if (!file_exists(FTP_SYNC_PATH . 'assets/js')) {
        wp_mkdir_p(FTP_SYNC_PATH . 'assets/js');
    }
    
    // Diretórios para arquivos baixados
    $upload_dir = wp_upload_dir();
    $directories = array(
        'ftp-sync-files',
        'ftp-sync-logs'
    );
    
    foreach ($directories as $dir) {
        $path = $upload_dir['basedir'] . '/' . $dir;
        if (!file_exists($path)) {
            wp_mkdir_p($path);
            // Adicionar arquivo .htaccess para proteção
            file_put_contents($path . '/.htaccess', "Order deny,allow\nDeny from all");
            // Adicionar arquivo index.php vazio
            file_put_contents($path . '/index.php', '<?php // Silêncio é ouro');
        }
    }
    
    // Criar CSS padrão se não existir
    $css_file = FTP_SYNC_PATH . 'assets/css/admin.css';
    if (!file_exists($css_file)) {
        file_put_contents($css_file, "
        /* Estilos para FTP Sync */
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
        
        .ftp-sync-table {
            width: 100%;
            border-collapse: collapse;
        }
        
        .ftp-sync-table th {
            text-align: left;
            width: 200px;
        }
        ");
    }
    
    // Criar JS padrão se não existir
    $js_file = FTP_SYNC_PATH . 'assets/js/admin.js';
    if (!file_exists($js_file)) {
        file_put_contents($js_file, "
        /* Scripts para FTP Sync */
        jQuery(document).ready(function($) {
            // Teste de conexão FTP
            $('#ftp-test-connection').on('click', function() {
                var \$button = $(this);
                var \$result = $('#ftp-test-result');
                
                \$button.prop('disabled', true).text('Testando...');
                \$result.hide();
                
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'ftp_sync_test_connection',
                        nonce: ftpSyncData.nonce
                    },
                    success: function(response) {
                        \$button.prop('disabled', false).text('Testar Conexão');
                        if (response.success) {
                            \$result.html(response.data)
                                .removeClass('ftp-sync-error')
                                .addClass('ftp-sync-success')
                                .show();
                        } else {
                            \$result.html(response.data)
                                .removeClass('ftp-sync-success')
                                .addClass('ftp-sync-error')
                                .show();
                        }
                    },
                    error: function() {
                        \$button.prop('disabled', false).text('Testar Conexão');
                        \$result.html('Erro de conexão com o servidor')
                            .removeClass('ftp-sync-success')
                            .addClass('ftp-sync-error')
                            .show();
                    }
                });
            });
            
            // Sincronização manual
            $('#ftp-sync-manual').on('click', function() {
                var \$button = $(this);
                var \$result = $('#ftp-sync-result');
                
                \$button.prop('disabled', true).text('Sincronizando...');
                \$result.hide();
                
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'ftp_sync_manual',
                        nonce: ftpSyncData.nonce
                    },
                    success: function(response) {
                        \$button.prop('disabled', false).text('Sincronizar Agora');
                        if (response.success) {
                            \$result.html(response.data)
                                .removeClass('ftp-sync-error')
                                .addClass('ftp-sync-success')
                                .show();
                        } else {
                            \$result.html(response.data)
                                .removeClass('ftp-sync-success')
                                .addClass('ftp-sync-error')
                                .show();
                        }
                    },
                    error: function() {
                        \$button.prop('disabled', false).text('Sincronizar Agora');
                        \$result.html('Erro de conexão com o servidor')
                            .removeClass('ftp-sync-success')
                            .addClass('ftp-sync-error')
                            .show();
                    }
                });
            });
        });
        ");
    }
}

/**
 * Definir opções padrão
 */
function ftp_sync_set_defaults() {
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
        'debug_mode' => 'no',
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
}

/**
 * Configurar tarefa agendada
 */
function ftp_sync_setup_cron() {
    if (!wp_next_scheduled('ftp_sync_check_files')) {
        $interval = get_option('ftp_sync_check_interval', 'hourly');
        wp_schedule_event(time(), $interval, 'ftp_sync_check_files');
    }
}

/**
 * Carregar arquivos necessários
 */
function ftp_sync_load_includes() {
    // Incluir arquivos principais
    require_once FTP_SYNC_PATH . 'includes/ftp-connector.php';
    require_once FTP_SYNC_PATH . 'includes/product-creator.php';
    require_once FTP_SYNC_PATH . 'includes/cron-manager.php';
    
    // Verificar se JetEngine está ativo
    if (defined('JET_ENGINE_VERSION')) {
        require_once FTP_SYNC_PATH . 'includes/jetengine-integration.php';
    }
}

/**
 * Adicionar menu administrativo
 */
function ftp_sync_add_admin_menu() {
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
}

/**
 * Enfileirar scripts e estilos para o admin
 */
function ftp_sync_admin_scripts($hook) {
    if (strpos($hook, 'ftp-sync') === false) {
        return;
    }
    
    wp_enqueue_style(
        'ftp-sync-admin-style', 
        FTP_SYNC_URL . 'assets/css/admin.css',
        array(),
        FTP_SYNC_VERSION
    );
    
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
        </div>
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
                <table class="ftp-sync-table widefat">
                    <tr>
                        <th>WooCommerce</th>
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
                </table>
            </div>
            
            <div class="ftp-sync-panel">
                <h3>Status da Sincronização</h3>
                <table class="ftp-sync-table widefat">
                    <tr>
                        <th>Arquivos processados</th>
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
 * AJAX para testar conexão FTP
 */
function ftp_sync_ajax_test_connection() {
    // Verificar nonce
    check_ajax_referer('ftp_sync_nonce', 'nonce');
    
    // Verificar permissões
    if (!current_user_can('manage_options')) {
        wp_send_json_error('Acesso negado');
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
    
    // Executar sincronização
    $result = ftp_sync_process_files(false);
    
    if ($result === false) {
        wp_send_json_error('Falha na sincronização. Verifique os logs para mais detalhes.');
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
        ftp_sync_log('Erro: WooCommerce não está ativo');
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
    
    // Criar arquivo de lock
    file_put_contents($lock_file, date('Y-m-d H:i:s'));
    
    ftp_sync_log('Iniciando processamento de arquivos ' . ($is_scheduled ? '(agendado)' : '(manual)'));
    
    try {
        // Conectar ao FTP
        $ftp_connection = ftp_sync_connect_ftp();
        
        if (!$ftp_connection) {
            @unlink($lock_file);
            return false;
        }
        
        // Carregar arquivo de processamento
        require_once FTP_SYNC_PATH . 'includes/ftp-connector.php';
        require_once FTP_SYNC_PATH . 'includes/product-creator.php';
        
        // Obter lista de diretórios de clientes
        $clients = ftp_sync_list_client_folders($ftp_connection);
        
        if (empty($clients)) {
            ftp_sync_log('Nenhum diretório de cliente encontrado');
            @ftp_close($ftp_connection);
            @unlink($lock_file);
            return 0;
        }
        
        $total_products = 0;
        
        // Processar cada cliente
        foreach ($clients as $client) {
            $client_name = basename($client);
            $result = ftp_sync_process_client_folder($ftp_connection, $client, $client_name);
            $total_products += $result;
        }
        
        // Fechar conexão
        @ftp_close($ftp_connection);
        
        // Atualizar última verificação
        update_option('ftp_sync_last_check', time());
        
        ftp_sync_log("Processamento concluído. {$total_products} produtos criados.");
        
        // Remover arquivo de lock
        @unlink($lock_file);
        
        return $total_products;
        
    } catch (Exception $e) {
        ftp_sync_log('ERRO: ' . $e->getMessage());
        @unlink($lock_file);
        return false;
    }
}

/**
 * Conectar ao servidor FTP
 * 
 * @return resource|bool Conexão FTP ou false em caso de erro
 */
function ftp_sync_connect_ftp() {
    $host = get_option('ftp_sync_ftp_host');
    $port = intval(get_option('ftp_sync_ftp_port', 21));
    $username = get_option('ftp_sync_ftp_username');
    $password = get_option('ftp_sync_ftp_password');
    $passive = get_option('ftp_sync_ftp_passive', 'yes') === 'yes';
    
    if (empty($host) || empty($username) || empty($password)) {
        ftp_sync_log('ERRO: Configurações FTP incompletas');
        return false;
    }
    
    // Conectar
    ftp_sync_log("Conectando ao servidor FTP: {$host}:{$port}");
    $conn = @ftp_connect($host, $port, 30);
    
    if (!$conn) {
        ftp_sync_log("ERRO: Falha ao conectar ao servidor FTP");
        return false;
    }
    
    // Login
    $login = @ftp_login($conn, $username, $password);
    if (!$login) {
        ftp_sync_log("ERRO: Falha na autenticação FTP");
        @ftp_close($conn);
        return false;
    }
    
    // Modo passivo
    if ($passive) {
        @ftp_pasv($conn, true);
    }
    
    ftp_sync_log("Conexão FTP estabelecida com sucesso");
    return $conn;
}

/**
 * Listar pastas de clientes
 * 
 * @param resource $ftp_connection Conexão FTP
 * @return array Lista de pastas
 */
function ftp_sync_list_client_folders($ftp_connection) {
    $base_path = get_option('ftp_sync_ftp_path', '/');
    
    // Mudar para o diretório base
    if (!@ftp_chdir($ftp_connection, $base_path)) {
        ftp_sync_log("ERRO: Não foi possível acessar o diretório base: {$base_path}");
        return array();
    }
    
    // Obter lista de itens
    $items = @ftp_nlist($ftp_connection, ".");
    
    if (!is_array($items)) {
        ftp_sync_log("ERRO: Falha ao listar conteúdo do diretório: {$base_path}");
        return array();
    }
    
    $folders = array();
    $current = @ftp_pwd($ftp_connection);
    
    foreach ($items as $item) {
        // Ignorar entradas especiais
        if ($item === '.' || $item === '..') {
            continue;
        }
        
        // Verificar se é um diretório
        if (@ftp_chdir($ftp_connection, $current . '/' . $item)) {
            $folders[] = $current . '/' . $item;
            @ftp_chdir($ftp_connection, $current);
        }
    }
    
    ftp_sync_log(count($folders) . " diretórios de clientes encontrados");
    return $folders;
}

/**
 * Processar pasta de cliente
 * 
 * @param resource $ftp_connection Conexão FTP
 * @param string $folder Caminho da pasta
 * @param string $client_name Nome do cliente
 * @return int Número de produtos criados
 */
function ftp_sync_process_client_folder($ftp_connection, $folder, $client_name) {
    ftp_sync_log("Processando pasta do cliente: {$client_name}");
    
    // Mudar para o diretório do cliente
    if (!@ftp_chdir($ftp_connection, $folder)) {
        ftp_sync_log("ERRO: Não foi possível acessar a pasta do cliente: {$folder}");
        return 0;
    }
    
    // Listar arquivos
    $items = @ftp_nlist($ftp_connection, ".");
    
    if (!is_array($items)) {
        ftp_sync_log("ERRO: Falha ao listar arquivos na pasta do cliente: {$folder}");
        return 0;
    }
    
    // Filtrar apenas arquivos (não diretórios)
    $files = array();
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        
        $size = @ftp_size($ftp_connection, $item);
        if ($size > 0) {
            $files[] = array(
                'name' => $item,
                'path' => $folder . '/' . $item,
                'size' => $size,
                'time' => @ftp_mdtm($ftp_connection, $item)
            );
        }
    }
    
    ftp_sync_log(count($files) . " arquivos encontrados na pasta do cliente {$client_name}");
    
    // Se não houver arquivos, retornar
    if (empty($files)) {
        return 0;
    }
    
    // Obter lista de arquivos já processados
    $processed_files = get_option('ftp_sync_processed_files', array());
    
    $products_created = 0;
    
    // Processar cada arquivo
    foreach ($files as $file) {
        // Gerar hash único para o arquivo
        $file_hash = md5($file['path'] . $file['size'] . $file['time']);
        
        // Verificar se já foi processado
        if (isset($processed_files[$file_hash])) {
            continue;
        }
        
        // Criar produto para o arquivo
        $product_id = ftp_sync_create_product($ftp_connection, $file, $client_name);
        
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
    update_option('ftp_sync_processed_files', $processed_files);
    
    ftp_sync_log("{$products_created} produtos criados para o cliente {$client_name}");
    
    return $products_created;
}

/**
 * Criar produto para um arquivo
 * 
 * @param resource $ftp_connection Conexão FTP
 * @param array $file Informações do arquivo
 * @param string $client_name Nome do cliente
 * @return int|bool ID do produto ou false em caso de erro
 */
function ftp_sync_create_product($ftp_connection, $file, $client_name) {
    ftp_sync_log("Criando produto para o arquivo: {$file['name']}");
    
    try {
        // Verificar WooCommerce
        if (!class_exists('WC_Product')) {
            ftp_sync_log('ERRO: WooCommerce não está disponível');
            return false;
        }
        
        // Download do arquivo FTP
        $upload_dir = wp_upload_dir();
        $target_dir = $upload_dir['basedir'] . '/ftp-sync-files/';
        $unique_name = uniqid($client_name . '-') . '-' . sanitize_file_name($file['name']);
        $local_path = $target_dir . $unique_name;
        
        // Download
        $download_success = @ftp_get($ftp_connection, $local_path, $file['name'], FTP_BINARY);
        
        if (!$download_success) {
            ftp_sync_log("ERRO: Falha ao baixar arquivo: {$file['name']}");
            return false;
        }
        
        // Verificar se o arquivo foi baixado
        if (!file_exists($local_path) || filesize($local_path) <= 0) {
            ftp_sync_log("ERRO: Arquivo baixado está vazio ou não existe: {$local_path}");
            return false;
        }
        
        // Criação do produto
        $product = new WC_Product();
        
        // Gerar título
        $title = ftp_sync_generate_title($file['name'], $client_name);
        $product->set_name($title);
        
        // Descrição
        $file_ext = pathinfo($file['name'], PATHINFO_EXTENSION);
        $file_size = size_format($file['size']);
        $description = ftp_sync_generate_description($file['name'], $file_ext, $file_size, $client_name);
        
        $product->set_description($description);
        $product->set_short_description("Arquivo {$file_ext} do cliente {$client_name}");
        
        // Configurações gerais
        $product->set_status(get_option('ftp_sync_product_status', 'publish'));
        $product->set_catalog_visibility('visible');
        
        // Preço
        $price = get_option('ftp_sync_product_price', '10');
        $product->set_price($price);
        $product->set_regular_price($price);
        
        // Definir como produto digital
        $product->set_virtual(true);
        $product->set_downloadable(true);
        
        // URL do arquivo
        $file_url = $upload_dir['baseurl'] . '/ftp-sync-files/' . $unique_name;
        
        // Adicionar download
        $download_data = array(
            'id' => md5($file['path']),
            'name' => $file['name'],
            'file' => $file_url
        );
        
        $product->set_downloads(array($download_data));
        $product->set_download_limit(-1); // Sem limite
        $product->set_download_expiry(-1); // Sem expiração
        
        // Meta dados
        $product->update_meta_data('_ftp_sync_client', $client_name);
        $product->update_meta_data('_ftp_sync_file_path', $file['path']);
        $product->update_meta_data('_ftp_sync_file_size', $file['size']);
        $product->update_meta_data('_ftp_sync_processed_date', date('Y-m-d H:i:s'));
        
        // Salvar produto
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

/**
 * Gerar título do produto
 * 
 * @param string $file_name Nome do arquivo
 * @param string $client_name Nome do cliente
 * @return string Título formatado
 */
function ftp_sync_generate_title($file_name, $client_name) {
    // Remover extensão
    $title = pathinfo($file_name, PATHINFO_FILENAME);
    
    // Substituir underscores e hífens por espaços
    $title = str_replace(['_', '-'], ' ', $title);
    
    // Capitalizar palavras
    $title = ucwords($title);
    
    return $title . ' - ' . $client_name;
}

/**
 * Gerar descrição do produto
 * 
 * @param string $file_name Nome do arquivo
 * @param string $file_ext Extensão do arquivo
 * @param string $file_size Tamanho do arquivo formatado
 * @param string $client_name Nome do cliente
 * @return string Descrição formatada
 */
function ftp_sync_generate_description($file_name, $file_ext, $file_size, $client_name) {
    $description = "Arquivo: {$file_name}\n";
    $description .= "Tipo: " . strtoupper($file_ext) . "\n";
    $description .= "Tamanho: {$file_size}\n";
    $description .= "Cliente: {$client_name}\n";
    $description .= "Data de processamento: " . date('d/m/Y H:i:s');
    
    return $description;
}

/**
 * Registrar mensagem no log
 * 
 * @param string $message Mensagem a ser registrada
 */
function ftp_sync_log($message) {
    // Verificar se debug está ativado
    if (get_option('ftp_sync_debug_mode') !== 'yes') {
        return;
    }
    
    $upload_dir = wp_upload_dir();
    $log_dir = $upload_dir['basedir'] . '/ftp-sync-logs';
    $log_file = $log_dir . '/sync-' . date('Y-m-d') . '.log';
    
    // Criar diretório se não existir
    if (!file_exists($log_dir)) {
        wp_mkdir_p($log_dir);
    }
    
    $log_entry = '[' . date('Y-m-d H:i:s') . '] ' . $message . "\n";
    file_put_contents($log_file, $log_entry, FILE_APPEND);
}

/**
 * Endpoint para processamento via cron externo
 */
function ftp_sync_register_endpoints() {
    add_rewrite_rule(
        'ftp-sync/process/([^/]+)/?$',
        'index.php?ftp_sync_action=process&security_key=$matches[1]',
        'top'
    );
    
    add_rewrite_tag('%ftp_sync_action%', '([^&]+)');
    add_rewrite_tag('%security_key%', '([^&]+)');
}
add_action('init', 'ftp_sync_register_endpoints');

/**
 * Processar endpoints
 */
function ftp_sync_handle_endpoints() {
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
        
        // Processar arquivos
        $result = ftp_sync_process_files(true);
        
        // Retornar resultado
        echo json_encode(array(
            'success' => ($result !== false),
            'products_created' => ($result !== false) ? $result : 0,
            'time' => date('Y-m-d H:i:s')
        ));
        exit;
    }
}
add_action('template_redirect', 'ftp_sync_handle_endpoints');

/**
 * Task agendada para verificar arquivos
 */
function ftp_sync_scheduled_check() {
    ftp_sync_process_files(true);
}
add_action('ftp_sync_check_files', 'ftp_sync_scheduled_check');

/**
 * Recalcular agendamento quando configurações são alteradas
 */
function ftp_sync_update_schedule($old_value, $new_value) {
    if ($old_value !== $new_value) {
        // Remover agendamento existente
        $timestamp = wp_next_scheduled('ftp_sync_check_files');
        if ($timestamp) {
            wp_unschedule_event($timestamp, 'ftp_sync_check_files');
        }
        
        // Criar novo agendamento
        wp_schedule_event(time(), $new_value, 'ftp_sync_check_files');
    }
}
add_action('update_option_ftp_sync_check_interval', 'ftp_sync_update_schedule', 10, 2);

/**
 * Integração com JetEngine
 */
function ftp_sync_jetengine_create_folder($user_id, $user_data) {
    // Verificar se JetEngine está ativo
    if (!defined('JET_ENGINE_VERSION')) {
        return;
    }
    
    // Verificar se tem nome de pasta FTP
    if (!isset($user_data['ftp_folder_name']) || empty($user_data['ftp_folder_name'])) {
        return;
    }
    
    $folder_name = sanitize_title($user_data['ftp_folder_name']);
    
    // Conectar ao FTP
    $ftp_connection = ftp_sync_connect_ftp();
    if (!$ftp_connection) {
        ftp_sync_log("ERRO: Não foi possível criar pasta FTP para o cliente {$folder_name}. Falha na conexão.");
        return;
    }
    
    // Caminho da pasta
    $base_path = get_option('ftp_sync_ftp_path', '/');
    $folder_path = rtrim($base_path, '/') . '/' . $folder_name;
    
    // Verificar se a pasta já existe
    $current = @ftp_pwd($ftp_connection);
    $exists = @ftp_chdir($ftp_connection, $folder_path);
    
    if ($exists) {
        @ftp_chdir($ftp_connection, $current);
        ftp_sync_log("Pasta FTP para cliente {$folder_name} já existe");
        @ftp_close($ftp_connection);
        return;
    }
    
    // Criar pasta
    $result = @ftp_mkdir($ftp_connection, $folder_path);
    
    if ($result) {
        ftp_sync_log("Pasta FTP criada com sucesso para cliente {$folder_name}: {$folder_path}");
    } else {
        ftp_sync_log("ERRO: Falha ao criar pasta FTP para cliente {$folder_name}");
    }
    
    @ftp_close($ftp_connection);
}

// Verificar se JetEngine está instalado antes de adicionar hooks
if (defined('JET_ENGINE_VERSION')) {
    add_action('jet-engine/user/after-add', 'ftp_sync_jetengine_create_folder', 10, 2);
    add_action('jet-engine/user/after-edit', 'ftp_sync_jetengine_create_folder', 10, 2);
    add_action('jet-engine/forms/booking/inserted-post-id', 'ftp_sync_handle_form_submission', 10, 2);
}

/**
 * Processar envio de formulário JetEngine
 */
function ftp_sync_handle_form_submission($inserted_id, $form_data) {
    // Verificar se o formulário tem um campo ftp_folder_name
    if (!isset($form_data['ftp_folder_name']) || empty($form_data['ftp_folder_name'])) {
        return $inserted_id;
    }
    
    // Se o post inserido for um usuário
    if (isset($form_data['_user_id']) && $form_data['_user_id']) {
        $user_id = $form_data['_user_id'];
        ftp_sync_jetengine_create_folder($user_id, $form_data);
    }
    
    return $inserted_id;
}

/**
 * Adicionar arquivo ftp-connector.php
 */
function ftp_sync_create_ftp_connector() {
    $file = FTP_SYNC_PATH . 'includes/ftp-connector.php';
    
    if (!file_exists($file)) {
        $content = '<?php
/**
 * FTP Connector - Gerencia conexões FTP
 */

if (!defined("ABSPATH")) {
    exit; // Saída se acessado diretamente
}

/**
 * Conectar ao servidor FTP
 * 
 * @return resource|bool Conexão FTP ou false em caso de erro
 */
function ftp_sync_connect_to_server() {
    $host = get_option("ftp_sync_ftp_host");
    $port = intval(get_option("ftp_sync_ftp_port", 21));
    $username = get_option("ftp_sync_ftp_username");
    $password = get_option("ftp_sync_ftp_password");
    $passive = get_option("ftp_sync_ftp_passive", "yes") === "yes";
    
    if (empty($host) || empty($username) || empty($password)) {
        ftp_sync_log("ERRO: Configurações FTP incompletas");
        return false;
    }
    
    // Conectar
    $conn = @ftp_connect($host, $port, 30);
    
    if (!$conn) {
        ftp_sync_log("ERRO: Falha ao conectar ao servidor FTP");
        return false;
    }
    
    // Login
    $login = @ftp_login($conn, $username, $password);
    if (!$login) {
        ftp_sync_log("ERRO: Falha na autenticação FTP");
        @ftp_close($conn);
        return false;
    }
    
    // Modo passivo
    if ($passive) {
        @ftp_pasv($conn, true);
    }
    
    return $conn;
}

/**
 * Listar diretórios no servidor FTP
 * 
 * @param resource $connection Conexão FTP
 * @param string $path Caminho para listar
 * @return array|bool Lista de diretórios ou false em caso de erro
 */
function ftp_sync_list_directories($connection, $path = "/") {
    if (!$connection) {
        return false;
    }
    
    // Mudar para o diretório especificado
    if (!@ftp_chdir($connection, $path)) {
        ftp_sync_log("ERRO: Não foi possível acessar o diretório: {$path}");
        return false;
    }
    
    // Lista conteúdo do diretório
    $items = @ftp_nlist($connection, ".");
    
    if (!is_array($items)) {
        return false;
    }
    
    $directories = array();
    $current = @ftp_pwd($connection);
    
    foreach ($items as $item) {
        if ($item === "." || $item === "..") {
            continue;
        }
        
        // Verificar se é um diretório
        if (@ftp_size($connection, $item) === -1) {
            $directories[] = $item;
        }
    }
    
    return $directories;
}

/**
 * Listar arquivos em um diretório
 * 
 * @param resource $connection Conexão FTP
 * @param string $path Caminho para listar
 * @return array|bool Lista de arquivos ou false em caso de erro
 */
function ftp_sync_list_files($connection, $path) {
    if (!$connection) {
        return false;
    }
    
    // Mudar para o diretório especificado
    if (!@ftp_chdir($connection, $path)) {
        ftp_sync_log("ERRO: Não foi possível acessar o diretório: {$path}");
        return false;
    }
    
    // Lista conteúdo do diretório
    $items = @ftp_nlist($connection, ".");
    
    if (!is_array($items)) {
        return false;
    }
    
    $files = array();
    
    foreach ($items as $item) {
        if ($item === "." || $item === "..") {
            continue;
        }
        
        $size = @ftp_size($connection, $item);
        
        // Se o tamanho é maior que 0, é um arquivo
        if ($size > 0) {
            $time = @ftp_mdtm($connection, $item);
            
            $files[] = array(
                "name" => $item,
                "size" => $size,
                "time" => ($time > 0) ? $time : time(),
                "path" => $path . "/" . $item
            );
        }
    }
    
    return $files;
}

/**
 * Criar diretório no servidor FTP
 * 
 * @param resource $connection Conexão FTP
 * @param string $path Caminho do novo diretório
 * @return bool Sucesso ou falha
 */
function ftp_sync_create_directory($connection, $path) {
    if (!$connection) {
        return false;
    }
    
    // Verificar se o diretório já existe
    $current = @ftp_pwd($connection);
    $exists = @ftp_chdir($connection, $path);
    
    if ($exists) {
        @ftp_chdir($connection, $current);
        return true; // Já existe
    }
    
    // Tentar criar o diretório
    $result = @ftp_mkdir($connection, $path);
    
    return $result !== false;
}

/**
 * Baixar arquivo do servidor FTP
 * 
 * @param resource $connection Conexão FTP
 * @param string $remote_path Caminho do arquivo remoto
 * @param string $local_path Caminho de destino local
 * @return bool Sucesso ou falha
 */
function ftp_sync_download_file($connection, $remote_path, $local_path) {
    if (!$connection) {
        return false;
    }
    
    // Tentar baixar o arquivo
    $result = @ftp_get($connection, $local_path, $remote_path, FTP_BINARY);
    
    return $result !== false;
}
';
        file_put_contents($file, $content);
    }
}

/**
 * Adicionar arquivo product-creator.php
 */
function ftp_sync_create_product_creator() {
    $file = FTP_SYNC_PATH . 'includes/product-creator.php';
    
    if (!file_exists($file)) {
        $content = '<?php
/**
 * Product Creator - Cria produtos WooCommerce
 */

if (!defined("ABSPATH")) {
    exit; // Saída se acessado diretamente
}

/**
 * Criar produto WooCommerce para um arquivo
 * 
 * @param array $file_data Dados do arquivo
 * @param string $client_name Nome do cliente
 * @param string $local_file_path Caminho local do arquivo baixado
 * @return int|bool ID do produto ou false em caso de erro
 */
function ftp_sync_create_wc_product($file_data, $client_name, $local_file_path) {
    // Verificar WooCommerce
    if (!class_exists("WC_Product")) {
        ftp_sync_log("ERRO: WooCommerce não está disponível");
        return false;
    }
    
    // Verificar arquivo local
    if (!file_exists($local_file_path) || filesize($local_file_path) <= 0) {
        ftp_sync_log("ERRO: Arquivo baixado inválido: {$local_file_path}");
        return false;
    }
    
    try {
        // Criar produto
        $product = new WC_Product();
        
        // Nome do arquivo
        $file_name = basename($file_data["name"]);
        
        // Título do produto
        $title = ftp_sync_generate_title($file_name, $client_name);
        $product->set_name($title);
        
        // Descrição
        $file_ext = pathinfo($file_name, PATHINFO_EXTENSION);
        $file_size = size_format($file_data["size"]);
        $description = ftp_sync_generate_description($file_name, $file_ext, $file_size, $client_name);
        
        $product->set_description($description);
        $product->set_short_description("Arquivo {$file_ext} do cliente {$client_name}");
        
        // Status e visibilidade
        $product->set_status(get_option("ftp_sync_product_status", "publish"));
        $product->set_catalog_visibility("visible");
        
        // Preço
        $price = get_option("ftp_sync_product_price", "10");
        $product->set_price($price);
        $product->set_regular_price($price);
        
        // Configurar como virtual e downloadable
        $product->set_virtual(true);
        $product->set_downloadable(true);
        
        // Configurar download
        $upload_dir = wp_upload_dir();
        $file_url = str_replace($upload_dir["basedir"], $upload_dir["baseurl"], $local_file_path);
        
        $download_data = array(
            "id" => md5($file_data["path"]),
            "name" => $file_name,
            "file" => $file_url
        );
        
        $product->set_downloads(array($download_data));
        $product->set_download_limit(-1); // Sem limite
        $product->set_download_expiry(-1); // Sem expiração
        
        // Metadata
        $product->update_meta_data("_ftp_sync_client", $client_name);
        $product->update_meta_data("_ftp_sync_file_path", $file_data["path"]);
        $product->update_meta_data("_ftp_sync_file_size", $file_data["size"]);
        $product->update_meta_data("_ftp_sync_processed_date", date("Y-m-d H:i:s"));
        
        // Salvar produto
        $product_id = $product->save();
        
        if (!$product_id) {
            ftp_sync_log("ERRO: Falha ao salvar produto");
            return false;
        }
        
        return $product_id;
        
    } catch (Exception $e) {
        ftp_sync_log("ERRO: " . $e->getMessage());
        return false;
    }
}
';
        file_put_contents($file, $content);
    }
}

/**
 * Adicionar arquivo cron-manager.php
 */
function ftp_sync_create_cron_manager() {
    $file = FTP_SYNC_PATH . 'includes/cron-manager.php';
    
    if (!file_exists($file)) {
        $content = '<?php
/**
 * Cron Manager - Gerencia tarefas agendadas
 */

if (!defined("ABSPATH")) {
    exit; // Saída se acessado diretamente
}

/**
 * Configurar agendamento
 * 
 * @param string $interval Intervalo de agendamento
 * @return bool Sucesso ou falha
 */
function ftp_sync_configure_schedule($interval = null) {
    // Se não for fornecido, usar o intervalo das configurações
    if ($interval === null) {
        $interval = get_option("ftp_sync_check_interval", "hourly");
    }
    
    // Remover agendamento atual, se existir
    $timestamp = wp_next_scheduled("ftp_sync_check_files");
    if ($timestamp) {
        wp_unschedule_event($timestamp, "ftp_sync_check_files");
    }
    
    // Criar novo agendamento
    return wp_schedule_event(time(), $interval, "ftp_sync_check_files") !== false;
}

/**
 * Registrar intervalos personalizados
 * 
 * @param array $schedules Agendamentos existentes
 * @return array Agendamentos atualizados
 */
function ftp_sync_cron_schedules($schedules) {
    // Adicionar intervalo de 5 minutos
    $schedules["ftp_every5minutes"] = array(
        "interval" => 300,
        "display" => __("A cada 5 minutos")
    );
    
    return $schedules;
}
add_filter("cron_schedules", "ftp_sync_cron_schedules");

/**
 * Verificar status do agendamento
 * 
 * @return array Informações sobre o agendamento
 */
function ftp_sync_get_schedule_info() {
    $timestamp = wp_next_scheduled("ftp_sync_check_files");
    $interval = get_option("ftp_sync_check_interval", "hourly");
    
    return array(
        "scheduled" => ($timestamp > 0),
        "next_run" => $timestamp ? date("Y-m-d H:i:s", $timestamp) : null,
        "interval" => $interval
    );
}
';
        file_put_contents($file, $content);
    }
}

/**
 * Adicionar arquivo jetengine-integration.php
 */
function ftp_sync_create_jetengine_integration() {
    $file = FTP_SYNC_PATH . 'includes/jetengine-integration.php';
    
    if (!file_exists($file)) {
        $content = '<?php
/**
 * JetEngine Integration - Integração com JetEngine
 */

if (!defined("ABSPATH")) {
    exit; // Saída se acessado diretamente
}

/**
 * Registrar campos personalizados para usuários JetEngine
 */
function ftp_sync_register_jetengine_fields() {
    if (!function_exists("jet_engine")) {
        return;
    }
    
    // Adicionar campo para nome da pasta FTP
    if (jet_engine()->meta_boxes) {
        // Verificar se já existe o campo
        $user_fields = jet_engine()->meta_boxes->get_registered_fields("user");
        $has_field = false;
        
        if (!empty($user_fields)) {
            foreach ($user_fields as $field) {
                if (isset($field["name"]) && $field["name"] === "ftp_folder_name") {
                    $has_field = true;
                    break;
                }
            }
        }
        
        // Adicionar campo se não existir
        if (!$has_field) {
            jet_engine()->meta_boxes->add_field_to_box("user", array(
                "type"        => "text",
                "name"        => "ftp_folder_name",
                "title"       => "Nome da pasta FTP",
                "description" => "Nome da pasta do cliente no servidor FTP",
                "is_required" => false,
            ));
        }
    }
}
add_action("init", "ftp_sync_register_jetengine_fields", 99);

/**
 * Criar/atualizar pasta FTP para um usuário
 * 
 * @param int $user_id ID do usuário
 * @param array $user_data Dados do usuário
 */
function ftp_sync_user_folder($user_id, $user_data) {
    // Verificar se tem campo ftp_folder_name
    if (!isset($user_data["ftp_folder_name"]) || empty($user_data["ftp_folder_name"])) {
        return;
    }
    
    $folder_name = sanitize_title($user_data["ftp_folder_name"]);
    
    // Salvar meta
    update_user_meta($user_id, "ftp_folder_name", $folder_name);
    
    // Criar pasta no servidor FTP
    ftp_sync_create_client_folder($folder_name);
}

/**
 * Criar pasta no servidor FTP para um cliente
 * 
 * @param string $folder_name Nome da pasta
 * @return bool Sucesso ou falha
 */
function ftp_sync_create_client_folder($folder_name) {
    // Conectar ao FTP
    $ftp_connection = ftp_sync_connect_ftp();
    if (!$ftp_connection) {
        ftp_sync_log("ERRO: Não foi possível conectar ao FTP para criar pasta do cliente: {$folder_name}");
        return false;
    }
    
    // Caminho completo da pasta
    $base_path = get_option("ftp_sync_ftp_path", "/");
    $folder_path = rtrim($base_path, "/") . "/" . $folder_name;
    
    // Verificar se a pasta já existe
    $current = @ftp_pwd($ftp_connection);
    $exists = @ftp_chdir($ftp_connection, $folder_path);
    
    if ($exists) {
        @ftp_chdir($ftp_connection, $current);
        ftp_sync_log("Pasta do cliente já existe: {$folder_path}");
        @ftp_close($ftp_connection);
        return true;
    }
    
    // Criar pasta
    $result = @ftp_mkdir($ftp_connection, $folder_path);
    
    if ($result) {
        ftp_sync_log("Pasta criada para cliente: {$folder_path}");
    } else {
        ftp_sync_log("ERRO: Falha ao criar pasta para cliente: {$folder_path}");
    }
    
    @ftp_close($ftp_connection);
    
    return $result !== false;
}
';
        file_put_contents($file, $content);
    }
}

/**
 * Verificar arquivos e criar se não existirem
 */
function ftp_sync_check_files() {
    ftp_sync_create_ftp_connector();
    ftp_sync_create_product_creator();
    ftp_sync_create_cron_manager();
    ftp_sync_create_jetengine_integration();
}

/**
 * Hooks de inicialização
 */
add_action('admin_menu', 'ftp_sync_add_admin_menu');
add_action('admin_init', 'ftp_sync_register_settings');
add_action('admin_enqueue_scripts', 'ftp_sync_admin_scripts');
add_action('wp_ajax_ftp_sync_test_connection', 'ftp_sync_ajax_test_connection');
add_action('wp_ajax_ftp_sync_manual', 'ftp_sync_ajax_manual_sync');

// Carregar arquivos na inicialização
add_action('plugins_loaded', 'ftp_sync_load_includes');
add_action('plugins_loaded', 'ftp_sync_check_files');