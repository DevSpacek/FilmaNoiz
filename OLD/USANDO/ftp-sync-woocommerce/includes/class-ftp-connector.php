<?php
/**
 * Classe para gerenciar conexões FTP
 */

if (!defined('ABSPATH')) {
    exit; // Saída se acessado diretamente
}

class FTP_Sync_Connector {
    
    /**
     * Conexão FTP
     */
    public $connection = null;
    
    /**
     * Conectar ao servidor FTP
     */
    public function connect() {
        // Obter configurações
        $host = get_option('ftp_sync_ftp_host', '');
        $port = intval(get_option('ftp_sync_ftp_port', 21));
        $username = get_option('ftp_sync_ftp_username', '');
        $password = get_option('ftp_sync_ftp_password', '');
        $passive = get_option('ftp_sync_ftp_passive', 'yes') === 'yes';
        
        // Validar configurações
        if (empty($host) || empty($username) || empty($password)) {
            $this->log_error('Configurações FTP incompletas');
            return false;
        }
        
        // Estabelecer conexão
        $this->log("Conectando a {$host}:{$port}");
        $conn = @ftp_connect($host, $port, 30);
        
        if (!$conn) {
            $this->log_error("Falha ao conectar a {$host}:{$port}");
            return false;
        }
        
        // Login
        $login = @ftp_login($conn, $username, $password);
        if (!$login) {
            $this->log_error('Falha na autenticação FTP');
            @ftp_close($conn);
            return false;
        }
        
        // Configurar modo passivo
        if ($passive) {
            @ftp_pasv($conn, true);
        }
        
        $this->connection = $conn;
        $this->log('Conexão FTP estabelecida com sucesso');
        
        return true;
    }
    
    /**
     * Desconectar do servidor FTP
     */
    public function disconnect() {
        if ($this->connection) {
            @ftp_close($this->connection);
            $this->connection = null;
            $this->log('Conexão FTP encerrada');
        }
    }
    
    /**
     * Listar pastas de clientes
     */
    public function list_client_folders() {
        if (!$this->connection) {
            $this->log_error('Sem conexão FTP ativa');
            return array();
        }
        
        $base_path = get_option('ftp_sync_ftp_base_path', '/');
        
        // Mudar para o diretório base
        if (!@ftp_chdir($this->connection, $base_path)) {
            $this->log_error("Não foi possível acessar o diretório: {$base_path}");
            return array();
        }
        
        // Listar arquivos e diretórios
        $items = @ftp_nlist($this->connection, '.');
        
        if (!is_array($items)) {
            $this->log_error("Falha ao listar conteúdo do diretório: {$base_path}");
            return array();
        }
        
        $folders = array();
        $current_dir = @ftp_pwd($this->connection);
        
        foreach ($items as $item) {
            // Ignorar entradas especiais
            if ($item == '.' || $item == '..') {
                continue;
            }
            
            // Verificar se é um diretório
            if (@ftp_chdir($this->connection, $current_dir . '/' . $item)) {
                $folders[] = $current_dir . '/' . $item;
                @ftp_chdir($this->connection, $current_dir);
            }
        }
        
        return $folders;
    }
    
    /**
     * Listar arquivos em um diretório
     */
    public function list_files($directory) {
        if (!$this->connection) {
            $this->log_error('Sem conexão FTP ativa');
            return array();
        }
        
        // Mudar para o diretório
        if (!@ftp_chdir($this->connection, $directory)) {
            $this->log_error("Não foi possível acessar o diretório: {$directory}");
            return array();
        }
        
        // Listar arquivos
        $items = @ftp_nlist($this->connection, '.');
        
        if (!is_array($items)) {
            $this->log_error("Falha ao listar conteúdo do diretório: {$directory}");
            return array();
        }
        
        $files = array();
        
        foreach ($items as $item) {
            // Ignorar entradas especiais
            if ($item == '.' || $item == '..') {
                continue;
            }
            
            // Obter informações do arquivo
            $size = @ftp_size($this->connection, $item);
            
            // Se o tamanho é -1, é um diretório ou erro
            if ($size >= 0) {
                $time = @ftp_mdtm($this->connection, $item);
                
                $files[] = array(
                    'name' => $item,
                    'path' => $directory . '/' . $item,
                    'size' => $size,
                    'time' => $time > 0 ? $time : time()
                );
            }
        }
        
        return $files;
    }
    
    /**
     * Criar diretório FTP
     */
    public function create_directory($path) {
        if (!$this->connection) {
            if (!$this->connect()) {
                return false;
            }
        }
        
        // Verificar se já existe
        $current_dir = @ftp_pwd($this->connection);
        $exists = @ftp_chdir($this->connection, $path);
        
        if ($exists) {
            // Já existe, voltar para o diretório original
            @ftp_chdir($this->connection, $current_dir);
            return true;
        }
        
        // Criar diretório
        $result = @ftp_mkdir($this->connection, $path);
        
        if ($result) {
            $this->log("Diretório criado: {$path}");
            return true;
        } else {
            $this->log_error("Falha ao criar diretório: {$path}");
            return false;
        }
    }
    
    /**
     * Download de arquivo FTP
     */
    public function download_file($remote_path, $local_path) {
        if (!$this->connection) {
            $this->log_error('Sem conexão FTP ativa');
            return false;
        }
        
        // Download
        $result = @ftp_get($this->connection, $local_path, $remote_path, FTP_BINARY);
        
        if ($result) {
            $this->log("Arquivo baixado: {$remote_path} -> {$local_path}");
            return true;
        } else {
            $this->log_error("Falha ao baixar arquivo: {$remote_path}");
            return false;
        }
    }
    
    /**
     * Registrar log
     */
    private function log($message) {
        if (function_exists('ftp_sync_woocommerce')) {
            ftp_sync_woocommerce()->log("[FTP] " . $message);
        }
    }
    
    /**
     * Registrar erro
     */
    private function log_error($message) {
        if (function_exists('ftp_sync_woocommerce')) {
            ftp_sync_woocommerce()->log("[FTP ERRO] " . $message);
        }
    }
}