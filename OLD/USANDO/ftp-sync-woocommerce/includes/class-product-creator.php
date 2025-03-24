<?php
/**
 * Classe para criar produtos do WooCommerce
 */

if (!defined('ABSPATH')) {
    exit; // Saída se acessado diretamente
}

class FTP_Sync_Product_Creator {
    
    /**
     * Criar produto para um arquivo
     * 
     * @param array $file_data Dados do arquivo
     * @param string $client_name Nome do cliente
     * @param resource $ftp_connection Conexão FTP ativa
     * @return int|bool ID do produto ou false em caso de erro
     */
    public function create_product($file_data, $client_name, $ftp_connection) {
        $this->log("Criando produto para arquivo: {$file_data['name']} do cliente {$client_name}");
        
        // Verificar requisitos
        if (!function_exists('wc_get_product') || !class_exists('WC_Product')) {
            $this->log_error('WooCommerce não está disponível');
            return false;
        }
        
        try {
            // Baixar arquivo do FTP
            $download_data = $this->download_file($file_data, $client_name, $ftp_connection);
            
            if (!$download_data) {
                return false;
            }
            
            // Criar produto
            $product = new WC_Product();
            
            // Definir nome do produto
            $title = $this->generate_title($file_data['name'], $client_name);
            $product->set_name($title);
            
            // Descrição
            $file_ext = pathinfo($file_data['name'], PATHINFO_EXTENSION);
            $file_size = size_format($file_data['size']);
            $description = $this->generate_description($file_data['name'], $file_ext, $file_size, $client_name);
            
            $product->set_description($description);
            $product->set_short_description("Arquivo {$file_ext} do cliente {$client_name}");
            
            // Status e visibilidade
            $product->set_status(get_option('ftp_sync_product_status', 'publish'));
            $product->set_catalog_visibility('visible');
            
            // Preço
            $price = get_option('ftp_sync_product_price', '10');
            $product->set_price($price);
            $product->set_regular_price($price);
            
            // Configurar como virtual e downloadable
            $product->set_virtual(true);
            $product->set_downloadable(true);
            
            // Adicionar download
            $product->set_downloads(array($download_data));
            $product->set_download_limit(-1); // Sem limite
            $product->set_download_expiry(-1); // Sem expiração
            
            // Metadados relacionados ao arquivo
            $product->update_meta_data('_ftp_sync_client', $client_name);
            $product->update_meta_data('_ftp_sync_file_path', $file_data['path']);
            $product->update_meta_data('_ftp_sync_file_size', $file_data['size']);
            $product->update_meta_data('_ftp_sync_processed_date', date('Y-m-d H:i:s'));
            
            // Salvar produto
            $product_id = $product->save();
            
            if (!$product_id) {
                $this->log_error('Erro ao salvar produto no banco de dados');
                return false;
            }
            
            $this->log("Produto criado com sucesso (ID: {$product_id})");
            return $product_id;
            
        } catch (Exception $e) {
            $this->log_error('Exceção: ' . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Download do arquivo FTP
     */
    private function download_file($file_data, $client_name, $ftp_connection) {
        // Preparar diretórios
        $upload_dir = wp_upload_dir();
        $target_dir = $upload_dir['basedir'] . '/ftp-sync-files';
        
        if (!file_exists($target_dir)) {
            wp_mkdir_p($target_dir);
        }
        
        // Gerar nome de arquivo único
        $file_name = $file_data['name'];
        $unique_name = uniqid($client_name . '-') . '-' . sanitize_file_name($file_name);
        $local_path = $target_dir . '/' . $unique_name;
        
        // Download via FTP
        $download_result = @ftp_get($ftp_connection, $local_path, $file_data['name'], FTP_BINARY);
        
        if (!$download_result) {
            $this->log_error("Falha ao baixar arquivo: {$file_data['path']}");
            return false;
        }
        
        // Verificar se o arquivo foi baixado corretamente
        if (!file_exists($local_path) || filesize($local_path) <= 0) {
            $this->log_error("Arquivo baixado está vazio ou não existe: {$local_path}");
            return false;
        }
        
        // URL pública do arquivo
        $file_url = $upload_dir['baseurl'] . '/ftp-sync-files/' . $unique_name;
        
        // Dados para WooCommerce
        return array(
            'id' => md5($file_data['path']),
            'name' => $file_name,
            'file' => $file_url
        );
    }
    
    /**
     * Gerar título do produto
     */
    private function generate_title($file_name, $client_name) {
        // Remover extensão
        $name_without_ext = pathinfo($file_name, PATHINFO_FILENAME);
        
        // Remover caracteres especiais e substitui underscores por espaços
        $clean_name = str_replace(['_', '-'], ' ', $name_without_ext);
        
        // Capitalizar palavras
        $formatted_name = ucwords($clean_name);
        
        return $formatted_name . ' - ' . $client_name;
    }
    
    /**
     * Gerar descrição do produto
     */
    private function generate_description($file_name, $file_ext, $file_size, $client_name) {
        $description = "Arquivo: {$file_name}\n";
        $description .= "Tipo: " . strtoupper($file_ext) . "\n";
        $description .= "Tamanho: {$file_size}\n";
        $description .= "Cliente: {$client_name}\n";
        $description .= "Processado em: " . date('d/m/Y H:i:s');
        
        return $description;
    }
    
    /**
     * Registrar log
     */
    private function log($message) {
        if (function_exists('ftp_sync_woocommerce')) {
            ftp_sync_woocommerce()->log("[Produto] " . $message);
        }
    }
    
    /**
     * Registrar erro
     */
    private function log_error($message) {
        if (function_exists('ftp_sync_woocommerce')) {
            ftp_sync_woocommerce()->log("[Produto ERRO] " . $message);
        }
    }
}