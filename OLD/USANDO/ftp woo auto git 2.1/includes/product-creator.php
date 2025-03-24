<?php
/**
 * Criador de produtos WooCommerce
 */

if (!defined('ABSPATH')) {
    exit; // Saída direta se acessado diretamente
}

/**
 * Classe para criar produtos WooCommerce a partir de arquivos FTP
 */
class FTP_Woo_Product_Creator {
    
    /** @var array Informações do arquivo */
    private $file_info = array();
    
    /** @var string Nome do cliente */
    private $client_name = '';
    
    /** @var resource Conexão FTP */
    private $connection = null;
    
    /**
     * Definir informações do arquivo
     * 
     * @param array $file_info Informações do arquivo
     */
    public function set_file_info($file_info) {
        $this->file_info = $file_info;
    }
    
    /**
     * Definir nome do cliente
     * 
     * @param string $client_name Nome do cliente
     */
    public function set_client_name($client_name) {
        $this->client_name = $client_name;
    }
    
    /**
     * Definir conexão FTP
     * 
     * @param resource $connection Conexão FTP
     */
    public function set_connection($connection) {
        $this->connection = $connection;
    }
    
    /**
     * Criar produto WooCommerce
     * 
     * @return int|bool ID do produto ou false em caso de erro
     */
    public function create() {
        global $ftp_woo_auto;
        
        // Verificar dados necessários
        if (empty($this->file_info) || empty($this->client_name) || $this->connection === null) {
            $ftp_woo_auto->log("ERRO: Dados insuficientes para criar produto");
            return false;
        }
        
        $file_name = $this->file_info['name'];
        $file_ext = pathinfo($file_name, PATHINFO_EXTENSION);
        $file_size = size_format($this->file_info['size']);
        
        $ftp_woo_auto->log("Criando produto para: {$file_name} ({$file_size})");
        
        // Verificar WooCommerce
        if (!function_exists('wc_get_product') || !class_exists('WC_Product')) {
            $ftp_woo_auto->log("ERRO: WooCommerce não disponível");
            return false;
        }
        
        try {
            // Verifica e prepara diretórios
            if (!$this->prepare_directories()) {
                return false;
            }
            
            // Baixar e configurar o arquivo para download
            $download_data = $this->prepare_download_file($file_name);
            
            if ($download_data === false) {
                $ftp_woo_auto->log("ERRO: Falha ao preparar arquivo para download");
                return false;
            }
            
            // Criar objeto do produto
            $product = new WC_Product();
            
            // Gerar título
            $title = $this->generate_title($file_name);
            $product->set_name($title);
            
            // Descrição
            $description = $this->generate_description($file_name, $file_ext, $file_size);
            $product->set_description($description);
            $product->set_short_description("Arquivo {$file_ext} do cliente {$this->client_name}");
            
            // Configurações básicas
            $price = get_option('ftp_default_price', '10');
            $status = get_option('ftp_product_status', 'publish');
            
            $product->set_status($status);
            $product->set_catalog_visibility('visible');
            $product->set_price($price);
            $product->set_regular_price($price);
            
            // Configurar como virtual e downloadable
            $product->set_virtual(true);
            $product->set_downloadable(true);
            
            // Adicionar download ao produto
            $product->set_downloads(array($download_data));
            $product->set_download_limit(-1); // Sem limite
            $product->set_download_expiry(-1); // Sem expiração
            
            // Salvar o produto
            $ftp_woo_auto->log("Salvando produto no banco de dados...");
            $product_id = $product->save();
            
            if (!$product_id) {
                $ftp_woo_auto->log("ERRO: Retorno vazio ao salvar produto");
                return false;
            }
            
            // Verificar se o produto foi realmente criado
            $saved_product = wc_get_product($product_id);
            if (!$saved_product) {
                $ftp_woo_auto->log("ERRO: Produto não encontrado após salvar (ID: {$product_id})");
                return false;
            }
            
            // Adicionar meta dados adicionais
            update_post_meta($product_id, '_ftp_client', $this->client_name);
            update_post_meta($product_id, '_ftp_source', $this->file_info['path']);
            update_post_meta($product_id, '_ftp_processed_date', date('Y-m-d H:i:s'));
            
            $ftp_woo_auto->log("Produto criado com sucesso - ID: {$product_id}, Título: {$title}");
            
            return $product_id;
            
        } catch (Exception $e) {
            $ftp_woo_auto->log("EXCEÇÃO: " . $e->getMessage() . " em " . $e->getFile() . " linha " . $e->getLine());
            if (defined('WP_DEBUG') && WP_DEBUG) {
                $ftp_woo_auto->log("Stack trace: " . $e->getTraceAsString());
            }
            return false;
        }
    }
    
    /**
     * Verifica e prepara diretórios necessários
     * 
     * @return bool Sucesso ou falha
     */
    private function prepare_directories() {
        global $ftp_woo_auto;
        
        // Preparar diretório de destino
        $upload_dir = wp_upload_dir();
        $target_dir = $upload_dir['basedir'] . '/woocommerce_uploads/';
        
        if (!file_exists($target_dir)) {
            $ftp_woo_auto->log("Criando diretório: {$target_dir}");
            $created = wp_mkdir_p($target_dir);
            if (!$created) {
                $ftp_woo_auto->log("ERRO: Não foi possível criar o diretório: {$target_dir}");
                return false;
            }
        }
        
        if (!is_writable($target_dir)) {
            $ftp_woo_auto->log("ERRO: Diretório não tem permissão de escrita: {$target_dir}");
            $ftp_woo_auto->log("Permissões atuais: " . decoct(fileperms($target_dir) & 0777));
            
            // Tentar corrigir as permissões
            $chmod_result = @chmod($target_dir, 0755);
            if ($chmod_result) {
                $ftp_woo_auto->log("Permissões ajustadas para: 0755");
            } else {
                $ftp_woo_auto->log("Falha ao ajustar permissões. Por favor, configure manualmente.");
                return false;
            }
        }
        
        // Verificar espaço em disco
        $free_space = function_exists('disk_free_space') ? disk_free_space($target_dir) : false;
        if ($free_space !== false && $free_space < 10 * 1024 * 1024) { // 10MB mínimo
            $ftp_woo_auto->log("AVISO: Pouco espaço em disco disponível: " . size_format($free_space));
        }
        
        return true;
    }
    
    /**
     * Gerar título do produto
     * 
     * @param string $file_name Nome do arquivo
     * @return string Título formatado
     */
    private function generate_title($file_name) {
        // Remover extensão
        $title = pathinfo($file_name, PATHINFO_FILENAME);
        
        // Substituir underscores e hífens por espaços
        $title = str_replace(['_', '-'], ' ', $title);
        
        // Capitalizar palavras
        $title = ucwords($title);
        
        return $title . ' - ' . $this->client_name;
    }
    
    /**
     * Gerar descrição do produto
     * 
     * @param string $file_name Nome do arquivo
     * @param string $file_ext Extensão do arquivo
     * @param string $file_size Tamanho do arquivo formatado
     * @return string Descrição formatada
     */
    private function generate_description($file_name, $file_ext, $file_size) {
        $description = "Arquivo: {$file_name}\n";
        $description .= "Tipo: " . strtoupper($file_ext) . "\n";
        $description .= "Tamanho: {$file_size}\n";
        $description .= "Cliente: {$this->client_name}\n";
        $description .= "Data: " . date('d/m/Y H:i:s');
        
        return $description;
    }
    
    /**
     * Preparar arquivo para download
     * 
     * @param string $file_name Nome do arquivo
     * @return array|bool Dados do download ou false
     */
    private function prepare_download_file($file_name) {
        global $ftp_woo_auto;
        
        // Preparar diretório de destino
        $upload_dir = wp_upload_dir();
        $target_dir = $upload_dir['basedir'] . '/woocommerce_uploads/';
        
        // Nome de arquivo único para evitar sobreposições
        $new_file_name = uniqid($this->client_name . '_') . '_' . $file_name;
        $target_path = $target_dir . $new_file_name;
        
        $ftp_woo_auto->log("Baixando arquivo FTP para: {$target_path}");
        
        // Verificar se o arquivo existe no servidor FTP
        $file_size = @ftp_size($this->connection, $this->file_info['name']);
        if ($file_size <= 0) {
            $ftp_woo_auto->log("ERRO: Arquivo não encontrado ou vazio no servidor FTP: " . $this->file_info['name']);
            return false;
        }
        
        // Verificar se podemos escrever no arquivo de destino
        $target_dir_writable = is_writable(dirname($target_path));
        if (!$target_dir_writable) {
            $ftp_woo_auto->log("ERRO: Diretório de destino não tem permissão de escrita: " . dirname($target_path));
            return false;
        }
        
        // Baixar arquivo via FTP com verificação de tempo limite
        $download_start = time();
        $success = @ftp_get($this->connection, $target_path, $this->file_info['name'], FTP_BINARY);
        $download_time = time() - $download_start;
        
        if (!$success) {
            $ftp_woo_auto->log("ERRO: Falha ao baixar arquivo FTP após {$download_time} segundos");
            return false;
        }
        
        // Verificar se o arquivo foi realmente baixado
        if (!file_exists($target_path)) {
            $ftp_woo_auto->log("ERRO: Arquivo não existe após download: {$target_path}");
            return false;
        }
        
        // Verificar tamanho do arquivo
        $local_size = filesize($target_path);
        if ($local_size <= 0) {
            $ftp_woo_auto->log("ERRO: Arquivo baixado está vazio: {$target_path}");
            @unlink($target_path);
            return false;
        }
        
        $ftp_woo_auto->log("Arquivo baixado com sucesso: {$local_size} bytes em {$download_time} segundos");
        
        // URL do arquivo
        $download_url = $upload_dir['baseurl'] . '/woocommerce_uploads/' . $new_file_name;
        
        // Dados do download
        return array(
            'id' => md5($target_path),
            'name' => $file_name,
            'file' => $download_url
        );
    }
}