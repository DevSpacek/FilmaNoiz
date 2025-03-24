<?php
/**
 * Gerenciamento de conexões FTP
 */

if (!defined('ABSPATH')) {
    exit; // Saída direta se acessado diretamente
}

/**
 * Classe para gerenciamento de FTP
 */
class FTP_Woo_FTP_Handler {
    
    /** @var resource Conexão FTP */
    private $connection = null;
    
    /**
     * Estabelecer conexão com o servidor FTP
     * 
     * @return bool Sucesso da conexão
     */
    public function connect() {
        global $ftp_woo_auto;
        
        // Verificar se já existe uma conexão
        if ($this->connection !== null) {
            return true;
        }
        
        // Obter configurações
        $ftp_host = get_option('ftp_server_host', '');
        $ftp_port = intval(get_option('ftp_server_port', 21));
        $ftp_user = get_option('ftp_server_username', '');
        $ftp_pass = get_option('ftp_server_password', '');
        $ftp_passive = get_option('ftp_passive_mode', 'yes') === 'yes';
        $ftp_timeout = intval(get_option('ftp_timeout', 90));
        
        // Validar configurações
        if (empty($ftp_host) || empty($ftp_user) || empty($ftp_pass)) {
            $ftp_woo_auto->log("ERRO: Configurações de FTP incompletas");
            return false;
        }
        
        // Estabelecer conexão
        $ftp_woo_auto->log("Conectando ao servidor FTP: {$ftp_host}:{$ftp_port}");
        
        $conn = @ftp_connect($ftp_host, $ftp_port, $ftp_timeout);
        if (!$conn) {
            $ftp_woo_auto->log("ERRO: Falha ao conectar ao servidor FTP");
            return false;
        }
        
        // Login
        $login = @ftp_login($conn, $ftp_user, $ftp_pass);
        if (!$login) {
            $ftp_woo_auto->log("ERRO: Falha na autenticação FTP");
            @ftp_close($conn);
            return false;
        }
        
        // Configurar modo passivo se necessário
        if ($ftp_passive) {
            @ftp_pasv($conn, true);
            $ftp_woo_auto->log("Modo passivo ativado");
        }
        
        $this->connection = $conn;
        $ftp_woo_auto->log("Conexão FTP estabelecida com sucesso");
        
        return true;
    }
    /**
 * Depurar e listar diretórios FTP - Função de ajuda
 */
public function debug_list_directories($path = '') {
    if (!$this->connect()) {
        return "ERRO: Falha na conexão FTP";
    }
    
    if (empty($path)) {
        $path = get_option('ftp_server_path', '/');
    }
    
    global $ftp_woo_auto;
    $output = "Listando conteúdo de: " . $path . "\n\n";
    
    // Tentar mudar para o diretório
    if (!@ftp_chdir($this->connection, $path)) {
        return $output . "ERRO: Não foi possível acessar o diretório: " . $path;
    }
    
    // Obter diretório atual
    $current_dir = @ftp_pwd($this->connection);
    $output .= "Diretório atual: " . $current_dir . "\n\n";
    
    // Listar conteúdo
    $items = @ftp_rawlist($this->connection, ".");
    
    if (!is_array($items) || empty($items)) {
        return $output . "AVISO: Diretório vazio ou problema ao listar conteúdo.";
    }
    
    $output .= "Conteúdo encontrado (" . count($items) . " itens):\n";
    foreach ($items as $item) {
        $output .= $item . "\n";
    }
    
    return $output;
}
    /**
     * Encerrar conexão FTP
     */
    public function disconnect() {
        if ($this->connection !== null) {
            @ftp_close($this->connection);
            $this->connection = null;
            
            global $ftp_woo_auto;
            $ftp_woo_auto->log("Conexão FTP encerrada");
        }
    }
    
    /**
     * Processar diretórios FTP e criar produtos
     * 
     * @param bool $is_auto Se é processamento automático ou manual
     * @return int Número de produtos criados
     */
    public function process_directories($is_auto = true) {
        global $ftp_woo_auto;
        $processed_count = 0;
        
        // Verificar conexão
        if ($this->connection === null) {
            $ftp_woo_auto->log("ERRO: Sem conexão FTP ativa");
            return 0;
        }
        
        // Obter diretório base
        $base_dir = get_option('ftp_server_path', '/');
        
        try {
            // Mudar para o diretório base
            if (!@ftp_chdir($this->connection, $base_dir)) {
                $ftp_woo_auto->log("ERRO: Não foi possível acessar o diretório {$base_dir}");
                return 0;
            }
            
            // Listar diretórios de clientes
            $ftp_woo_auto->log("Listando diretórios em: {$base_dir}");
            $client_folders = $this->list_directories($base_dir);
            
            if (empty($client_folders)) {
                $ftp_woo_auto->log("AVISO: Nenhum diretório de cliente encontrado");
                return 0;
            }
            
            $ftp_woo_auto->log("Encontradas " . count($client_folders) . " pastas de cliente");
            
            // Processar cada pasta de cliente
            foreach ($client_folders as $client_folder) {
                $client_name = basename($client_folder);
                $ftp_woo_auto->log("Processando cliente: $client_name");
                
                // Verificar acesso à pasta
                if (!@ftp_chdir($this->connection, $client_folder)) {
                    $ftp_woo_auto->log("AVISO: Não foi possível acessar a pasta do cliente: $client_name");
                    continue;
                }
                
                // Processar arquivos do cliente
                $client_processed = $this->process_client_folder($client_folder, $client_name, $is_auto);
                $processed_count += $client_processed;
                
                // Voltar ao diretório base
                @ftp_chdir($this->connection, $base_dir);
                
                if ($client_processed > 0) {
                    $ftp_woo_auto->log("Cliente $client_name: $client_processed arquivos processados");
                } else {
                    $ftp_woo_auto->log("Cliente $client_name: nenhum arquivo novo encontrado");
                }
            }
            
        } catch (Exception $e) {
            $ftp_woo_auto->log("EXCEÇÃO: " . $e->getMessage());
        }
        
        return $processed_count;
    }
    
    /**
     * Listar diretórios FTP
     * 
     * @param string $path Caminho para listar
     * @return array Lista de diretórios
     */
    private function list_directories($path) {
        $directories = array();
        $current_dir = @ftp_pwd($this->connection);
        
        // Mudar para o diretório especificado
        if (!@ftp_chdir($this->connection, $path)) {
            return $directories;
        }
        
        // Listar conteúdo
        $items = @ftp_nlist($this->connection, ".");
        
        if (!is_array($items)) {
            @ftp_chdir($this->connection, $current_dir);
            return $directories;
        }
        
        // Filtrar diretórios
        foreach ($items as $item) {
            // Ignorar entradas especiais
            if ($item == "." || $item == "..") {
                continue;
            }
            
            // Verificar se é um diretório
            if (@ftp_chdir($this->connection, $item)) {
                $directories[] = $item;
                @ftp_chdir($this->connection, ".."); // Voltar ao diretório pai
            }
        }
        
        // Voltar ao diretório original
        @ftp_chdir($this->connection, $current_dir);
        
        return $directories;
    }
    
    /**
     * Processar pasta de cliente
     * 
     * @param string $folder_path Caminho da pasta
     * @param string $client_name Nome do cliente
     * @param bool $is_auto Se é processamento automático
     * @return int Número de produtos criados
     */
    private function process_client_folder($folder_path, $client_name, $is_auto = true) {
        global $ftp_woo_auto;
        $processed = 0;
        
        // Obter todos os arquivos na pasta do cliente
        $files = $this->get_all_files($folder_path);
        
        if (empty($files)) {
            $ftp_woo_auto->log("Nenhum arquivo encontrado para o cliente $client_name");
            return 0;
        }
        
        $ftp_woo_auto->log("Encontrados " . count($files) . " arquivos para o cliente $client_name");
        
        // Obter lista de arquivos já processados
        $processed_files = get_option('ftp_processed_files', array());
        
        // Processar cada arquivo
        foreach ($files as $file_info) {
            $file_path = $file_info['path'];
            $file_name = $file_info['name'];
            $file_size = $file_info['size'];
            $file_time = $file_info['time'];
            
            // Criar hash único para o arquivo
            $file_hash = md5($file_path . $file_time);
            
            // Verificar se já foi processado
            if (isset($processed_files[$file_hash])) {
                continue;
            }
            
            $ftp_woo_auto->log("Novo arquivo detectado: $file_name ($file_size bytes)");
            
            // Criar produto
            $product_id = $this->create_product($file_info, $client_name);
            
            if ($product_id) {
                $ftp_woo_auto->log("SUCESSO: Produto criado com ID: $product_id");
                
                // Registrar arquivo como processado
                $processed_files[$file_hash] = array(
                    'file' => $file_name,
                    'product_id' => $product_id,
                    'time' => time(),
                    'auto' => $is_auto
                );
                
                $processed++;
            } else {
                $ftp_woo_auto->log("ERRO: Falha ao criar produto para: $file_name");
            }
        }
        
        // Atualizar lista de arquivos processados
        if ($processed > 0) {
            update_option('ftp_processed_files', $processed_files);
        }
        
        return $processed;
    }
    
    /**
     * Obter todos os arquivos recursivamente
     * 
     * @param string $dir Diretório a ser escaneado
     * @param string $relative_path Caminho relativo
     * @return array Lista de arquivos
     */
    private function get_all_files($dir, $relative_path = '') {
        $files = array();
        $current_dir = @ftp_pwd($this->connection);
        
        if (empty($relative_path)) {
            $relative_path = $dir;
        }
        
        // Listar conteúdo
        $items = @ftp_nlist($this->connection, ".");
        
        if (!is_array($items)) {
            global $ftp_woo_auto;
            $ftp_woo_auto->log("AVISO: Não foi possível listar conteúdo do diretório: " . $relative_path);
            return $files;
        }
        
        foreach ($items as $item) {
            // Ignorar entradas especiais
            if ($item == "." || $item == "..") {
                continue;
            }
            
            // Verificar se é um diretório
            if (@ftp_chdir($this->connection, $item)) {
                // Buscar arquivos recursivamente
                $sub_path = $relative_path . '/' . $item;
                $sub_files = $this->get_all_files($dir, $sub_path);
                $files = array_merge($files, $sub_files);
                
                // Voltar ao diretório anterior
                @ftp_chdir($this->connection, $current_dir);
            } else {
                // É um arquivo
                $file_path = $relative_path . '/' . $item;
                
                // Obter informações do arquivo
                $file_time = @ftp_mdtm($this->connection, $item);
                $file_size = @ftp_size($this->connection, $item);
                
                if ($file_size >= 0) { // -1 significa erro
                    $files[] = array(
                        'name' => $item,
                        'path' => $file_path,
                        'size' => $file_size,
                        'time' => $file_time > 0 ? $file_time : time()
                    );
                }
            }
        }
        
        return $files;
    }
    
    /**
     * Criar produto para um arquivo
     * 
     * @param array $file_info Informações do arquivo
     * @param string $client_name Nome do cliente
     * @return int|bool ID do produto ou false em caso de erro
     */
    private function create_product($file_info, $client_name) {
        global $ftp_woo_auto;
        
        // Passar para o criador de produtos
        $ftp_woo_auto->product->set_file_info($file_info);
        $ftp_woo_auto->product->set_client_name($client_name);
        $ftp_woo_auto->product->set_connection($this->connection);
        
        return $ftp_woo_auto->product->create();
    }
}