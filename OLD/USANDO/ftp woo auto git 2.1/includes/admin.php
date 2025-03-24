<?php
/**
 * Gerenciamento da interface administrativa
 */

if (!defined('ABSPATH')) {
    exit; // Saída direta se acessado diretamente
}

/**
 * Classe de administração do plugin
 */
class FTP_Woo_Admin {
    
    /**
     * Construtor
     */
    public function __construct() {
        // Adicionar menu admin - prioridade alta para garantir que aparece
        add_action('admin_menu', array($this, 'add_admin_menu'), 30);
        
        // Registrar configurações
        add_action('admin_init', array($this, 'register_settings'));
        
        // Adicionar notificações administrativas
        add_action('admin_notices', array($this, 'admin_notices'));
        
        // Adicionar ação para processamento manual
        add_action('admin_post_scan_ftp_folders', array($this, 'process_manual_scan'));
    }
    
    /**
     * Adicionar menu de administração
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
     * Registrar configurações do plugin
     */
    public function register_settings() {
        // FTP Server settings
        register_setting('ftp_to_woo_settings', 'ftp_server_host');
        register_setting('ftp_to_woo_settings', 'ftp_server_port');
        register_setting('ftp_to_woo_settings', 'ftp_server_username');
        register_setting('ftp_to_woo_settings', 'ftp_server_password');
        register_setting('ftp_to_woo_settings', 'ftp_server_path');
        register_setting('ftp_to_woo_settings', 'ftp_passive_mode');
        register_setting('ftp_to_woo_settings', 'ftp_timeout');
        
        // Product settings
        register_setting('ftp_to_woo_settings', 'ftp_default_price');
        register_setting('ftp_to_woo_settings', 'ftp_product_status');
        
        // Automation settings
        register_setting('ftp_to_woo_settings', 'ftp_auto_enabled');
        register_setting('ftp_to_woo_settings', 'ftp_auto_frequency');
        register_setting('ftp_to_woo_settings', 'ftp_force_minutes');
    }
    
    /**
     * Exibir notificações administrativas
     */
    public function admin_notices() {
        if (!isset($_GET['page']) || $_GET['page'] !== 'ftp-to-woo-auto') {
            return;
        }
        
        // Notificação de processamento bem-sucedido
        if (isset($_GET['processed'])) {
            $count = intval($_GET['processed']);
            echo '<div class="notice notice-success is-dismissible"><p>' . 
                 sprintf(_n('%s produto criado com sucesso!', '%s produtos criados com sucesso!', $count), number_format_i18n($count)) . 
                 '</p></div>';
        }
        
        // Notificação de erro
        if (isset($_GET['error'])) {
            $error = $_GET['error'];
            $message = '';
            
            switch ($error) {
                case 'ftp_connect':
                    $message = 'Não foi possível conectar ao servidor FTP. Verifique as configurações.';
                    break;
                case 'ftp_login':
                    $message = 'Falha no login FTP. Verifique usuário e senha.';
                    break;
                case 'woocommerce':
                    $message = 'O WooCommerce não está ativo. Este plugin requer o WooCommerce.';
                    break;
                case 'locked':
                    $message = 'Já existe um processamento em andamento. Aguarde a finalização ou remova o arquivo de lock se necessário.';
                    break;
                default:
                    $message = 'Ocorreu um erro desconhecido durante o processamento.';
            }
            
            echo '<div class="notice notice-error is-dismissible"><p>' . esc_html($message) . '</p></div>';
        }
    }
    
    /**
     * Renderizar página de administração
     */
    public function render_admin_page() {
        // Verificar permissões
        if (!current_user_can('manage_options')) {
            wp_die('Acesso negado');
        }
        
        // Obter configurações
        $ftp_host = get_option('ftp_server_host', '');
        $ftp_port = get_option('ftp_server_port', '21');
        $ftp_user = get_option('ftp_server_username', '');
        $ftp_pass = get_option('ftp_server_password', '');
        $ftp_path = get_option('ftp_server_path', '/');
        $ftp_passive = get_option('ftp_passive_mode', 'yes');
        $ftp_timeout = get_option('ftp_timeout', '90');
        
        $default_price = get_option('ftp_default_price', '10');
        $product_status = get_option('ftp_product_status', 'publish');
        $auto_enabled = get_option('ftp_auto_enabled', 'yes');
        $auto_frequency = get_option('ftp_auto_frequency', 'every5minutes');
        $force_minutes = get_option('ftp_force_minutes', '5');
        $security_key = get_option('ftp_security_key', '');
        
        // Verificar status FTP
        $ftp_status = $this->check_ftp_connection();
        
        // URL para processamento direto
        $direct_url = add_query_arg(array(
            'ftp_woo_endpoint' => 'process',
            'key' => $security_key
        ), home_url('/ftp-woo-process/'));
        
        // Informações do sistema
        $next_run = wp_next_scheduled('ftp_auto_scan_hook');
        
        ?>
        <div class="wrap">
            <h1>FTP para WooCommerce (Ultra Confiável) - v<?php echo FTP_WOO_AUTO_VERSION; ?></h1>
            
            <div class="notice notice-info">
                <p><strong>Status do Sistema:</strong></p>
                <ul style="list-style:disc;padding-left:20px;">
                    <li>WooCommerce: <?php echo class_exists('WooCommerce') ? '<span style="color:green;">✓ Ativo</span>' : '<span style="color:red;">✗ Inativo</span>'; ?></li>
                    <li>Data atual do servidor: <?php echo date('Y-m-d H:i:s'); ?></li>
                    <li>Último processamento manual: <?php echo get_option('ftp_last_process_time') ? date('d/m/Y H:i:s', get_option('ftp_last_process_time')) : 'Nunca'; ?></li>
                    <li>Último processamento automático: <?php echo get_option('ftp_last_auto_time') ? date('d/m/Y H:i:s', get_option('ftp_last_auto_time')) : 'Nunca'; ?></li>
                    <li>Próximo agendamento WP-Cron: <?php echo $next_run ? date('d/m/Y H:i:s', $next_run) : 'Não agendado'; ?></li>
                </ul>
            </div>
            
            <h2>Configurações do Servidor FTP</h2>
            <form method="post" action="options.php">
                <?php settings_fields('ftp_to_woo_settings'); ?>
                
                <table class="form-table">
                    <tr>
                        <th scope="row">Servidor FTP</th>
                        <td>
                            <input type="text" name="ftp_server_host" value="<?php echo esc_attr($ftp_host); ?>" class="regular-text" placeholder="ftp.example.com" />
                            <?php if (!empty($ftp_status)) echo '<p>' . $ftp_status . '</p>'; ?>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row">Porta FTP</th>
                        <td>
                            <input type="number" name="ftp_server_port" value="<?php echo esc_attr($ftp_port); ?>" class="small-text" min="1" max="65535" />
                            <p class="description">Padrão: 21</p>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row">Usuário FTP</th>
                        <td>
                            <input type="text" name="ftp_server_username" value="<?php echo esc_attr($ftp_user); ?>" class="regular-text" />
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row">Senha FTP</th>
                        <td>
                            <input type="password" name="ftp_server_password" value="<?php echo esc_attr($ftp_pass); ?>" class="regular-text" />
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row">Diretório Inicial</th>
                        <td>
                            <input type="text" name="ftp_server_path" value="<?php echo esc_attr($ftp_path); ?>" class="regular-text" placeholder="/" />
                            <p class="description">Caminho no servidor FTP onde estão as pastas dos clientes</p>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row">Modo Passivo</th>
                        <td>
                            <select name="ftp_passive_mode">
                                <option value="yes" <?php selected($ftp_passive, 'yes'); ?>>Ativado</option>
                                <option value="no" <?php selected($ftp_passive, 'no'); ?>>Desativado</option>
                            </select>
                            <p class="description">Recomendado manter ativado para evitar problemas de firewall</p>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row">Timeout (segundos)</th>
                        <td>
                            <input type="number" name="ftp_timeout" value="<?php echo esc_attr($ftp_timeout); ?>" class="small-text" min="10" max="600" />
                            <p class="description">Tempo máximo de espera para operações FTP</p>
                        </td>
                    </tr>
                </table>
                
                <h2>Configurações de Produtos</h2>
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
                                <option value="minutely" <?php selected($auto_frequency, 'minutely'); ?>>A cada minuto</option>
                                <option value="every5minutes" <?php selected($auto_frequency, 'every5minutes'); ?>>A cada 5 minutos (recomendado)</option>
                                <option value="hourly" <?php selected($auto_frequency, 'hourly'); ?>>A cada hora</option>
                                <option value="twicedaily" <?php selected($auto_frequency, 'twicedaily'); ?>>Duas vezes ao dia</option>
                                <option value="daily" <?php selected($auto_frequency, 'daily'); ?>>Diariamente</option>
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
                
                <h3>Depuração FTP</h3>
                <div style="background:#f8f8f8;padding:15px;border:1px solid #ddd;margin-bottom:20px;">
                    <?php if (!empty($ftp_host) && !empty($ftp_user) && !empty($ftp_pass)): ?>
                        <p><strong>Teste de Diretórios FTP:</strong></p>
                        <?php
                        global $ftp_woo_auto;
                        if ($ftp_woo_auto && $ftp_woo_auto->ftp) {
                            echo '<pre style="background:#f5f5f5;padding:10px;max-height:300px;overflow:auto;">';
                            echo esc_html($ftp_woo_auto->ftp->debug_list_directories($ftp_path));
                            echo '</pre>';
                        }
                        ?>
                    <?php else: ?>
                        <p>Preencha as informações de conexão FTP e salve para ver o conteúdo dos diretórios.</p>
                    <?php endif; ?>
                </div>


                <h3>Diagnóstico do Sistema</h3>
                <div style="background:#f8f8f8;padding:15px;border:1px solid #ddd;margin-bottom:20px;">
                    <table class="widefat" style="border:none;">
                        <tr>
                            <th style="width:30%;">Servidor FTP</th>
                            <td><?php echo !empty($ftp_host) ? esc_html($ftp_host) : '<span style="color:red;">Não configurado</span>'; ?></td>
                        </tr>
                        <tr>
                            <th>Diretório de uploads WP</th>
                            <td>
                                <?php 
                                $upload_dir = wp_upload_dir();
                                echo esc_html($upload_dir['basedir']);
                                echo ' - ';
                                echo is_writable($upload_dir['basedir']) ? 
                                    '<span style="color:green;">Gravável</span>' : 
                                    '<span style="color:red;">Não gravável</span>'; 
                                ?>
                            </td>
                        </tr>
                        <tr>
                            <th>Diretório WooCommerce Downloads</th>
                            <td>
                                <?php 
                                $woo_dir = $upload_dir['basedir'] . '/woocommerce_uploads';
                                $woo_dir_exists = file_exists($woo_dir);
                                echo esc_html($woo_dir);
                                echo ' - ';
                                
                                if (!$woo_dir_exists) {
                                    echo '<span style="color:red;">Não existe</span>';
                                } elseif (is_writable($woo_dir)) {
                                    echo '<span style="color:green;">Gravável</span>';
                                } else {
                                    echo '<span style="color:red;">Não gravável</span>'; 
                                }
                                
                                if (!$woo_dir_exists || !is_writable($woo_dir)) {
                                    echo ' <button type="button" class="button" id="fix-woo-dir">Criar/Corrigir Diretório</button>';
                                    ?>
                                    <script>
                                    jQuery(document).ready(function($) {
                                        $('#fix-woo-dir').on('click', function() {
                                            $(this).prop('disabled', true).text('Processando...');
                                            
                                            $.ajax({
                                                url: ajaxurl,
                                                type: 'POST',
                                                data: {
                                                    action: 'ftp_woo_fix_directory',
                                                    security: '<?php echo wp_create_nonce('ftp_woo_nonce'); ?>'
                                                },
                                                success: function(response) {
                                                    if (response.success) {
                                                        alert('Diretório corrigido! Recarregando página...');
                                                        location.reload();
                                                    } else {
                                                        alert('Erro: ' + (response.data || 'Falha na operação'));
                                                        $('#fix-woo-dir').prop('disabled', false).text('Tentar Novamente');
                                                    }
                                                },
                                                error: function() {
                                                    alert('Erro de conexão');
                                                    $('#fix-woo-dir').prop('disabled', false).text('Tentar Novamente');
                                                }
                                            });
                                        });
                                    });
                                    </script>
                                    <?php
                                }
                                ?>
                            </td>
                        </tr>
                        <tr>
                            <th>Extensões PHP necessárias</th>
                            <td>
                                FTP: <?php echo function_exists('ftp_connect') ? '<span style="color:green;">Disponível</span>' : '<span style="color:red;">Indisponível</span>'; ?><br>
                                cURL: <?php echo function_exists('curl_version') ? '<span style="color:green;">Disponível</span>' : '<span style="color:red;">Indisponível</span>'; ?><br>
                                GD/ImageMagick: <?php echo (extension_loaded('gd') || extension_loaded('imagick')) ? '<span style="color:green;">Disponível</span>' : '<span style="color:red;">Indisponível</span>'; ?>
                            </td>
                        </tr>
                        <tr>
                            <th>Limite de memória PHP</th>
                            <td><?php echo ini_get('memory_limit'); ?></td>
                        </tr>
                        <tr>
                            <th>WooCommerce Products</th>
                            <td>
                                <?php
                                $count_posts = wp_count_posts('product');
                                echo 'Publicados: ' . $count_posts->publish . '<br>';
                                echo 'Rascunhos: ' . $count_posts->draft;
                                ?>
                            </td>
                        </tr>
                    </table>
                </div>

                
                <?php submit_button('Salvar Configurações'); ?>
            </form>
            
            <hr>
            
            <div class="ftp-woo-process-container">
                <h2>Processamento Manual</h2>
                <p>Clique no botão abaixo para escanear o servidor FTP e criar produtos para novos arquivos imediatamente:</p>
                
                <div id="ftp-woo-manual-process">
                    <form method="post" action="<?php echo admin_url('admin-post.php'); ?>">
                        <input type="hidden" name="action" value="scan_ftp_folders">
                        <?php wp_nonce_field('scan_ftp_folders_nonce'); ?>
                        <?php submit_button('Escanear FTP Agora', 'primary', 'submit', false); ?>
                    </form>
                    
                    <div id="ftp-woo-ajax-process" style="margin-top: 15px;">
                        <button id="ftp-woo-ajax-button" class="button button-secondary">Escanear FTP via AJAX</button>
                        <span id="ftp-woo-ajax-status" style="margin-left: 10px; display: none;"></span>
                    </div>
                    
                    <script>
                        jQuery(document).ready(function($) {
                            $('#ftp-woo-ajax-button').on('click', function() {
                                var $button = $(this);
                                var $status = $('#ftp-woo-ajax-status');
                                
                                $button.prop('disabled', true);
                                $status.text('Processando...').show();
                                
                                $.ajax({
                                    url: ajaxurl,
                                    type: 'POST',
                                    data: {
                                        action: 'ftp_woo_process_now',
                                        security: '<?php echo wp_create_nonce('ftp_woo_nonce'); ?>'
                                    },
                                    success: function(response) {
                                        if (response.success) {
                                            $status.text('Concluído! ' + response.data.count + ' produtos criados.');
                                        } else {
                                            $status.text('Erro: ' + (response.data || 'Falha no processamento'));
                                        }
                                        $button.prop('disabled', false);
                                    },
                                    error: function() {
                                        $status.text('Erro de conexão. Verifique o log para mais detalhes.');
                                        $button.prop('disabled', false);
                                    }
                                });
                            });
                        });
                    </script>
                </div>
            </div>
            
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
                    <li>Também pode usar: <code>* * * * * curl -s '<?php echo esc_url($direct_url); ?>' >/dev/null 2>&1</code></li>
                </ol>
                <p><strong>Importante:</strong> Mantenha esta URL privada pois permite processamento sem autenticação</p>
            </div>
            
            <?php if (class_exists('WooCommerce')): ?>
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
        <?php
    }
    
    /**
     * Verificar conexão FTP
     * 
     * @return string HTML com status da conexão
     */
    private function check_ftp_connection() {
        $ftp_host = get_option('ftp_server_host', '');
        $ftp_port = intval(get_option('ftp_server_port', 21));
        $ftp_user = get_option('ftp_server_username', '');
        $ftp_pass = get_option('ftp_server_password', '');
        $ftp_timeout = intval(get_option('ftp_timeout', 90));
        
        if (empty($ftp_host) || empty($ftp_user) || empty($ftp_pass)) {
            return '';
        }
        
        $conn = @ftp_connect($ftp_host, $ftp_port, $ftp_timeout);
        if (!$conn) {
            return '<span style="color:red;">✗ Falha na conexão</span>';
        }
        
        $login = @ftp_login($conn, $ftp_user, $ftp_pass);
        if (!$login) {
            ftp_close($conn);
            return '<span style="color:red;">✗ Falha de autenticação</span>';
        }
        
        ftp_close($conn);
        return '<span style="color:green;">✓ Conexão bem sucedida</span>';
    }
    
    /**
     * Processar solicitação manual via formulário
     */
    public function process_manual_scan() {
        // Verificar nonce e permissões
        if (!isset($_POST['_wpnonce']) || !wp_verify_nonce($_POST['_wpnonce'], 'scan_ftp_folders_nonce')) {
            wp_die('Verificação de segurança falhou');
        }
        
        if (!current_user_can('manage_options')) {
            wp_die('Acesso negado');
        }
        
        global $ftp_woo_auto;
        
        // Processar arquivos
        $result = $ftp_woo_auto->process_files(false);
        
        // Redirecionar com resultado
        wp_redirect(add_query_arg(array(
            'page' => 'ftp-to-woo-auto',
            'processed' => $result
        ), admin_url('admin.php')));
        exit;
    }
}