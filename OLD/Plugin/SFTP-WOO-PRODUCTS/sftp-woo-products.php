<?php
/**
 * Plugin Name: SFTP to WooCommerce - Debug Version
 * Description: Converte arquivos SFTP em produtos WooCommerce (versão com diagnóstico)
 * Version: 1.1
 * Author: DevSpacek
 */

if (!defined('ABSPATH')) {
    exit;
}

class SFTP_To_Woo_Debug {
    
    private $log = array();
    
    public function __construct() {
        // Verificar WooCommerce
        add_action('admin_init', array($this, 'check_woocommerce'));
        
        // Admin
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_init', array($this, 'register_settings'));
        add_action('admin_notices', array($this, 'admin_notices'));
        
        // Processamento
        add_action('admin_post_scan_sftp_folders', array($this, 'process_manual_scan'));
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
            'sftp-to-woo-debug',
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
    }
    
    /**
     * Adicionar notices no admin
     */
    public function admin_notices() {
        if (!isset($_GET['page']) || $_GET['page'] !== 'sftp-to-woo-debug') {
            return;
        }
        
        if (isset($_GET['processed'])) {
            $count = intval($_GET['processed']);
            echo '<div class="notice notice-success is-dismissible"><p>' . 
                 sprintf(_n('%s produto criado com sucesso!', '%s produtos criados com sucesso!', $count), number_format_i18n($count)) . 
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
        
        // Verificar status do WooCommerce
        $woo_active = class_exists('WooCommerce');
        $wc_product_class = class_exists('WC_Product');
        $woo_version = $woo_active ? WC()->version : 'N/A';
        
        ?>
        <div class="wrap">
            <h1>SFTP para WooCommerce (Debug)</h1>
            
            <div class="notice notice-info">
                <p><strong>Status do Sistema:</strong></p>
                <ul style="list-style:disc;padding-left:20px;">
                    <li>WooCommerce Ativo: <?php echo $woo_active ? '<span style="color:green;">✓ Sim</span>' : '<span style="color:red;">✗ Não</span>'; ?></li>
                    <li>Versão do WooCommerce: <?php echo esc_html($woo_version); ?></li>
                    <li>Classe WC_Product disponível: <?php echo $wc_product_class ? '<span style="color:green;">✓ Sim</span>' : '<span style="color:red;">✗ Não</span>'; ?></li>
                    <li>Último processamento: <?php echo get_option('sftp_last_process_time') ? date('d/m/Y H:i:s', get_option('sftp_last_process_time')) : 'Nunca'; ?></li>
                </ul>
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
                                            echo '<ul style="font-size:12px;background:#f8f8f8;padding:10px;max-height:100px;overflow-y:auto;">';
                                            foreach (array_slice($dirs, 0, 5) as $dir) {
                                                echo '<li>' . basename($dir) . '</li>';
                                            }
                                            if (count($dirs) > 5) {
                                                echo '<li>... e mais ' . (count($dirs) - 5) . ' pasta(s)</li>';
                                            }
                                            echo '</ul>';
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
                                <option value="draft" <?php selected($product_status, 'draft'); ?>>Rascunho</option>
                                <option value="publish" <?php selected($product_status, 'publish'); ?>>Publicado</option>
                                <option value="private" <?php selected($product_status, 'private'); ?>>Privado</option>
                                <option value="pending" <?php selected($product_status, 'pending'); ?>>Pendente</option>
                            </select>
                            <p class="description">Status dos produtos quando criados</p>
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
            
            <h2>Escanear Pastas</h2>
            <p>Clique no botão abaixo para escanear as pastas SFTP e criar produtos para novos arquivos:</p>
            
            <form method="post" action="<?php echo admin_url('admin-post.php'); ?>">
                <input type="hidden" name="action" value="scan_sftp_folders">
                <?php wp_nonce_field('scan_sftp_folders_nonce'); ?>
                <?php submit_button('Escanear Pastas e Criar Produtos', 'primary', 'submit', false); ?>
            </form>
            
            <?php if ($woo_active): ?>
            <div style="margin-top:20px;padding:15px;background:#f8f8f8;border:1px solid #ddd;">
                <h3>Produtos Processados Recentemente</h3>
                <?php
                $processed_files = get_option('sftp_processed_files', array());
                
                if (!empty($processed_files)) {
                    $recent_files = array_slice($processed_files, -10, 10, true);
                    echo '<table class="widefat striped" style="margin-top:10px;">';
                    echo '<thead><tr><th>Arquivo</th><th>ID Produto</th><th>Data</th><th>Status</th></tr></thead>';
                    echo '<tbody>';
                    
                    foreach ($recent_files as $hash => $info) {
                        $product = wc_get_product($info['product_id']);
                        $status = $product ? '<span style="color:green;">Ativo</span>' : '<span style="color:red;">Não encontrado</span>';
                        
                        echo '<tr>';
                        echo '<td>' . esc_html($info['file']) . '</td>';
                        echo '<td>' . esc_html($info['product_id']) . '</td>';
                        echo '<td>' . date('d/m/Y H:i:s', $info['time']) . '</td>';
                        echo '<td>' . $status . '</td>';
                        echo '</tr>';
                    }
                    
                    echo '</tbody></table>';
                } else {
                    echo '<p>Nenhum arquivo processado ainda.</p>';
                }
                ?>
            </div>
            <?php endif; ?>
        </div>
        <?php
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
        $this->add_log("Iniciando processamento: " . date('d/m/Y H:i:s'));
        
        // Verificar WooCommerce
        if (!class_exists('WooCommerce')) {
            $this->add_log("ERRO: WooCommerce não está ativo");
            $this->save_debug_log();
            wp_redirect(add_query_arg(array(
                'page' => 'sftp-to-woo-debug',
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
                'page' => 'sftp-to-woo-debug',
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
        $this->add_log("Processamento concluído. Total: $result produtos criados");
        $this->save_debug_log();
        
        // Redirecionar com resultado
        wp_redirect(add_query_arg(array(
            'page' => 'sftp-to-woo-debug',
            'processed' => $result,
            'debug_log' => '1'
        ), admin_url('admin.php')));
        exit;
    }
    
    /**
     * Escanear diretórios
     */
    private function scan_directories($base_dir) {
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
            
            $client_processed = $this->process_client_folder($client_folder, $client_name);
            $processed += $client_processed;
            
            $this->add_log("Cliente $client_name: $client_processed arquivos processados");
        }
        
        return $processed;
    }
    
    /**
     * Processar pasta do cliente
     */
    private function process_client_folder($folder_path, $client_name) {
        $processed = 0;
        
        // Listar arquivos
        $files = glob($folder_path . '/*.*');
        $this->add_log("Encontrados " . count($files) . " arquivos para o cliente $client_name");
        
        // Lista de arquivos já processados
        $processed_files = get_option('sftp_processed_files', array());
        
        foreach ($files as $file_path) {
            // Ignorar diretórios
            if (is_dir($file_path)) {
                continue;
            }
            
            $file_name = basename($file_path);
            $this->add_log("Verificando arquivo: $file_name");
            
            $file_hash = md5($file_path . filemtime($file_path));
            
            // Verificar se já processamos este arquivo
            if (isset($processed_files[$file_hash])) {
                $this->add_log("Arquivo já processado anteriormente: $file_name");
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
                    'time' => time()
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
        $this->add_log("Título do produto: {$title}");
        
        // Configurações do produto
        $price = get_option('sftp_default_price', '0');
        $status = get_option('sftp_product_status', 'draft');
        
        // Verificar WooCommerce
        if (!class_exists('WC_Product')) {
            $this->add_log("ERRO: Classe WC_Product não existe");
            return false;
        }
        
        try {
            // Criar objeto do produto
            $this->add_log("Criando objeto WC_Product");
            $product = new WC_Product();
            
            // Título e descrição
            $product->set_name($title);
            
            $description = "Arquivo: {$file_name}\n";
            $description .= "Tipo: " . strtoupper($file_ext) . "\n";
            $description .= "Tamanho: {$file_size}\n";
            $description .= "Cliente: {$client_name}\n";
            $description .= "Data: " . date('d/m/Y H:i:s');
            
            $product->set_description($description);
            $product->set_short_description("Arquivo {$file_ext} do cliente {$client_name}");
            
            // Configurações básicas
            $this->add_log("Configurando produto como {$status} com preço {$price}");
            $product->set_status($status);
            $product->set_catalog_visibility('visible');
            $product->set_price($price);
            $product->set_regular_price($price);
            
            // Configurar como virtual e downloadable
            $product->set_virtual(true);
            $product->set_downloadable(true);
            
            // Preparar download
            $this->add_log("Preparando arquivo para download");
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
}

// Inicializar plugin de forma segura
add_action('plugins_loaded', function() {
    if (class_exists('WooCommerce')) {
        new SFTP_To_Woo_Debug();
    }
});