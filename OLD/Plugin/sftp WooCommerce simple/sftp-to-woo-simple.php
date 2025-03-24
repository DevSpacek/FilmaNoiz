<?php
/**
 * Plugin Name: SFTP to WooCommerce - Simple
 * Description: Converte arquivos SFTP em produtos WooCommerce (versão estável)
 * Version: 1.0
 * Author: DevSpacek
 */

if (!defined('ABSPATH')) {
    exit;
}

class SFTP_To_Woo_Simple {
    
    public function __construct() {
        // Verificar se WooCommerce está ativo
        add_action('admin_init', array($this, 'check_woocommerce'));
        
        // Adicionar página de administração
        add_action('admin_menu', array($this, 'add_admin_menu'));
        
        // Registrar configurações
        add_action('admin_init', array($this, 'register_settings'));
        
        // Processar scan manual
        add_action('admin_post_scan_sftp_folders', array($this, 'process_manual_scan'));
    }
    
    /**
     * Verificar se o WooCommerce está instalado e ativo
     */
    public function check_woocommerce() {
        if (!class_exists('WooCommerce')) {
            add_action('admin_notices', function() {
                echo '<div class="notice notice-error"><p>O plugin <strong>SFTP to WooCommerce</strong> requer o WooCommerce ativo.</p></div>';
            });
        }
    }
    
    /**
     * Adicionar menu ao painel
     */
    public function add_admin_menu() {
        add_menu_page(
            'SFTP para WooCommerce',
            'SFTP para Woo',
            'manage_options',
            'sftp-to-woo',
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
    }
    
    /**
     * Renderizar página de administração
     */
    public function render_admin_page() {
        // Checar permissões
        if (!current_user_can('manage_options')) {
            wp_die('Acesso negado');
        }
        
        // Obter configurações
        $base_dir = get_option('sftp_base_directory', '');
        $default_price = get_option('sftp_default_price', '0');
        $product_status = get_option('sftp_product_status', 'draft');
        
        ?>
        <div class="wrap">
            <h1>SFTP para WooCommerce</h1>
            
            <?php if (!class_exists('WooCommerce')): ?>
                <div class="notice notice-error">
                    <p>O WooCommerce precisa estar instalado e ativado para que este plugin funcione.</p>
                </div>
                <?php return; ?>
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
                            </select>
                            <p class="description">Status dos produtos quando criados</p>
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
                <?php submit_button('Escanear Pastas Agora', 'primary', 'submit', false); ?>
            </form>
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
        
        $base_dir = get_option('sftp_base_directory', '');
        
        if (empty($base_dir) || !file_exists($base_dir) || !is_dir($base_dir)) {
            wp_redirect(add_query_arg(array(
                'page' => 'sftp-to-woo',
                'error' => 'directory'
            ), admin_url('admin.php')));
            exit;
        }
        
        // Iniciar processamento
        $result = $this->scan_directories($base_dir);
        
        // Redirecionar com resultado
        wp_redirect(add_query_arg(array(
            'page' => 'sftp-to-woo',
            'processed' => $result
        ), admin_url('admin.php')));
        exit;
    }
    
    /**
     * Escanear diretórios
     */
    private function scan_directories($base_dir) {
        if (!class_exists('WooCommerce')) {
            return 0;
        }
        
        $processed = 0;
        $client_folders = glob($base_dir . '/*', GLOB_ONLYDIR);
        
        foreach ($client_folders as $client_folder) {
            $client_name = basename($client_folder);
            $processed += $this->process_client_folder($client_folder, $client_name);
        }
        
        return $processed;
    }
    
    /**
     * Processar pasta do cliente
     */
    private function process_client_folder($folder_path, $client_name) {
        $processed = 0;
        $files = glob($folder_path . '/*.*');
        
        // Lista de arquivos já processados
        $processed_files = get_option('sftp_processed_files', array());
        
        foreach ($files as $file_path) {
            // Ignorar diretórios
            if (is_dir($file_path)) {
                continue;
            }
            
            $file_hash = md5($file_path . filemtime($file_path));
            
            // Verificar se já processamos este arquivo
            if (isset($processed_files[$file_hash])) {
                continue;
            }
            
            // Criar produto para este arquivo
            $product_id = $this->create_product_for_file($file_path, $client_name);
            
            if ($product_id) {
                // Marcar arquivo como processado
                $processed_files[$file_hash] = array(
                    'file' => basename($file_path),
                    'product_id' => $product_id,
                    'time' => time()
                );
                
                $processed++;
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
        
        // Gerar título do produto
        $title = $this->generate_product_title($file_name, $client_name);
        
        // Configurações do produto
        $price = get_option('sftp_default_price', '0');
        $status = get_option('sftp_product_status', 'draft');
        
        // Criar produto se WooCommerce estiver disponível
        if (!class_exists('WC_Product')) {
            return false;
        }
        
        try {
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
            
            // Configurar download
            $upload_dir = wp_upload_dir();
            $target_dir = $upload_dir['basedir'] . '/woocommerce_uploads/';
            
            if (!file_exists($target_dir)) {
                wp_mkdir_p($target_dir);
            }
            
            $new_file_name = uniqid($client_name . '_') . '_' . $file_name;
            $target_path = $target_dir . $new_file_name;
            
            if (copy($file_path, $target_path)) {
                $download = array(
                    'id' => md5($target_path),
                    'name' => $file_name,
                    'file' => $upload_dir['baseurl'] . '/woocommerce_uploads/' . $new_file_name
                );
                
                $product->set_downloads(array($download));
                $product->set_download_limit(-1); // Sem limite
                $product->set_download_expiry(-1); // Sem expiração
            }
            
            // Salvar produto
            $product_id = $product->save();
            
            // Adicionar metadados
            update_post_meta($product_id, '_sftp_source', $file_path);
            update_post_meta($product_id, '_sftp_client', $client_name);
            
            return $product_id;
        } catch (Exception $e) {
            // Log do erro
            error_log('Erro ao criar produto para arquivo ' . $file_path . ': ' . $e->getMessage());
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
}

// Inicializar plugin de forma segura
add_action('plugins_loaded', function() {
    new SFTP_To_Woo_Simple();
});