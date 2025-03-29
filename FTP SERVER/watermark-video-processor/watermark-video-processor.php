<?php
/**
 * Plugin Name: Processador de Marca D'água para Vídeos
 * Description: Processa vídeos de um servidor FTP, adiciona marca d'água e salva em outra pasta.
 * Version: 1.0
 * Author: GitHub Copilot
 * License: GPL2
 */

// Evitar acesso direto ao arquivo
if (!defined('ABSPATH')) {
    exit;
}

class WatermarkVideoProcessor {
    private $ftp_server;
    private $ftp_username;
    private $ftp_password;
    private $source_dir;
    private $target_dir;
    private $watermark_image;
    private $processing_interval;

    public function __construct() {
        // Registrar hooks de ativação e desativação
        register_activation_hook(__FILE__, array($this, 'activate'));
        register_deactivation_hook(__FILE__, array($this, 'deactivate'));
        
        // Adicionar página no menu administrativo
        add_action('admin_menu', array($this, 'add_admin_menu'));
        
        // Registrar configurações
        add_action('admin_init', array($this, 'register_settings'));
        
        // Inicializar configurações
        $this->init_settings();
        
        // Configurar o cron job para processamento periódico
        add_action('watermark_video_cron_event', array($this, 'process_videos'));
    }
    
    public function activate() {
        // Configurar o cron job ao ativar o plugin
        if (!wp_next_scheduled('watermark_video_cron_event')) {
            wp_schedule_event(time(), 'hourly', 'watermark_video_cron_event');
        }
        
        // Criar diretório para uploads temporários
        $upload_dir = wp_upload_dir();
        $temp_dir = $upload_dir['basedir'] . '/watermark-temp';
        if (!file_exists($temp_dir)) {
            wp_mkdir_p($temp_dir);
        }
    }
    
    public function deactivate() {
        // Remover o cron job ao desativar o plugin
        wp_clear_scheduled_hook('watermark_video_cron_event');
    }
    
    private function init_settings() {
        $this->ftp_server = get_option('watermark_ftp_server', '');
        $this->ftp_username = get_option('watermark_ftp_username', '');
        $this->ftp_password = get_option('watermark_ftp_password', '');
        $this->source_dir = get_option('watermark_source_dir', '');
        $this->target_dir = get_option('watermark_target_dir', '');
        $this->watermark_image = get_option('watermark_image', '');
        $this->processing_interval = get_option('watermark_processing_interval', 'hourly');
    }
    
    public function add_admin_menu() {
        add_options_page(
            'Configurações do Processador de Marca D\'água',
            'Processador de Marca D\'água',
            'manage_options',
            'watermark-video-processor',
            array($this, 'render_admin_page')
        );
    }
    
    public function register_settings() {
        register_setting('watermark_video_settings', 'watermark_ftp_server');
        register_setting('watermark_video_settings', 'watermark_ftp_username');
        register_setting('watermark_video_settings', 'watermark_ftp_password');
        register_setting('watermark_video_settings', 'watermark_source_dir');
        register_setting('watermark_video_settings', 'watermark_target_dir');
        register_setting('watermark_video_settings', 'watermark_image');
        register_setting('watermark_video_settings', 'watermark_processing_interval');
    }
    
    public function render_admin_page() {
        // Verificar se FFmpeg está instalado
        $ffmpeg_installed = $this->check_ffmpeg_installed();
        
        ?>
        <div class="wrap">
            <h1>Configurações do Processador de Marca D'água para Vídeos</h1>
            
            <?php if (!$ffmpeg_installed): ?>
            <div class="notice notice-error">
                <p><strong>Atenção:</strong> O FFmpeg não foi encontrado no servidor. Este plugin requer FFmpeg para processar vídeos.</p>
            </div>
            <?php endif; ?>
            
            <form method="post" action="options.php" enctype="multipart/form-data">
                <?php settings_fields('watermark_video_settings'); ?>
                
                <table class="form-table">
                    <tr>
                        <th scope="row">Servidor FTP</th>
                        <td><input type="text" name="watermark_ftp_server" value="<?php echo esc_attr($this->ftp_server); ?>" class="regular-text" /></td>
                    </tr>
                    <tr>
                        <th scope="row">Usuário FTP</th>
                        <td><input type="text" name="watermark_ftp_username" value="<?php echo esc_attr($this->ftp_username); ?>" class="regular-text" /></td>
                    </tr>
                    <tr>
                        <th scope="row">Senha FTP</th>
                        <td><input type="password" name="watermark_ftp_password" value="<?php echo esc_attr($this->ftp_password); ?>" class="regular-text" /></td>
                    </tr>
                    <tr>
                        <th scope="row">Diretório de Origem</th>
                        <td><input type="text" name="watermark_source_dir" value="<?php echo esc_attr($this->source_dir); ?>" class="regular-text" /></td>
                    </tr>
                    <tr>
                        <th scope="row">Diretório de Destino</th>
                        <td><input type="text" name="watermark_target_dir" value="<?php echo esc_attr($this->target_dir); ?>" class="regular-text" /></td>
                    </tr>
                    <tr>
                        <th scope="row">Imagem de Marca D'água</th>
                        <td>
                            <?php if ($this->watermark_image): ?>
                                <img src="<?php echo esc_url($this->watermark_image); ?>" style="max-width: 200px; margin-bottom: 10px;" /><br>
                            <?php endif; ?>
                            
                            <input type="hidden" name="watermark_image" id="watermark_image" value="<?php echo esc_attr($this->watermark_image); ?>" />
                            <input type="button" class="button" id="upload_watermark_button" value="Upload Marca D'água" />
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Intervalo de Processamento</th>
                        <td>
                            <select name="watermark_processing_interval">
                                <option value="hourly" <?php selected($this->processing_interval, 'hourly'); ?>>A cada hora</option>
                                <option value="twicedaily" <?php selected($this->processing_interval, 'twicedaily'); ?>>Duas vezes ao dia</option>
                                <option value="daily" <?php selected($this->processing_interval, 'daily'); ?>>Diariamente</option>
                            </select>
                        </td>
                    </tr>
                </table>
                
                <?php submit_button(); ?>
            </form>
            
            <hr>
            
            <h2>Processar Vídeos Manualmente</h2>
            <p>Clique no botão abaixo para processar vídeos imediatamente.</p>
            <form method="post" action="">
                <?php wp_nonce_field('process_videos_manually', 'process_videos_nonce'); ?>
                <input type="submit" name="process_videos_manually" class="button button-primary" value="Processar Vídeos Agora">
            </form>
        </div>
        
        <script>
        jQuery(document).ready(function($) {
            $('#upload_watermark_button').click(function(e) {
                e.preventDefault();
                var image = wp.media({
                    title: 'Upload ou Selecione Marca D\'água',
                    multiple: false
                }).open().on('select', function() {
                    var uploaded_image = image.state().get('selection').first();
                    var image_url = uploaded_image.toJSON().url;
                    $('#watermark_image').val(image_url);
                });
            });
        });
        </script>
        <?php
        
        // Processar vídeos manualmente
        if (isset($_POST['process_videos_manually']) && check_admin_referer('process_videos_manually', 'process_videos_nonce')) {
            $this->process_videos();
            echo '<div class="updated"><p>Processamento de vídeos iniciado!</p></div>';
        }
    }
    
    private function check_ffmpeg_installed() {
        $command = 'which ffmpeg';
        $return_value = null;
        $output = array();
        exec($command, $output, $return_value);
        return $return_value === 0;
    }
    
    public function process_videos() {
        if (empty($this->ftp_server) || empty($this->ftp_username) || empty($this->ftp_password) || 
            empty($this->source_dir) || empty($this->target_dir) || empty($this->watermark_image)) {
            error_log('Processador de Marca D\'água: Configurações incompletas.');
            return;
        }
        
        // Conectar ao FTP
        $conn_id = ftp_connect($this->ftp_server);
        if (!$conn_id) {
            error_log('Processador de Marca D\'água: Não foi possível conectar ao servidor FTP.');
            return;
        }
        
        // Login no FTP
        $login_result = ftp_login($conn_id, $this->ftp_username, $this->ftp_password);
        if (!$login_result) {
            error_log('Processador de Marca D\'água: Falha no login FTP.');
            ftp_close($conn_id);
            return;
        }
        
        // Listar arquivos no diretório de origem
        $file_list = ftp_nlist($conn_id, $this->source_dir);
        if (!$file_list) {
            error_log('Processador de Marca D\'água: Não foi possível listar os arquivos no diretório de origem.');
            ftp_close($conn_id);
            return;
        }
        
        // Obter diretório de upload para arquivos temporários
        $upload_dir = wp_upload_dir();
        $temp_dir = $upload_dir['basedir'] . '/watermark-temp';
        
        // Baixar a imagem de marca d'água
        $watermark_path = $temp_dir . '/watermark.png';
        file_put_contents($watermark_path, file_get_contents($this->watermark_image));
        
        // Processar cada vídeo encontrado
        foreach ($file_list as $file) {
            $file_name = basename($file);
            $file_ext = pathinfo($file_name, PATHINFO_EXTENSION);
            
            // Pular se não for um arquivo de vídeo
            $video_extensions = array('mp4', 'avi', 'mov', 'wmv', 'flv', 'mkv');
            if (!in_array(strtolower($file_ext), $video_extensions)) {
                continue;
            }
            
            // Baixar o vídeo para processamento
            $local_file = $temp_dir . '/' . $file_name;
            if (ftp_get($conn_id, $local_file, $this->source_dir . '/' . $file_name, FTP_BINARY)) {
                // Processar o vídeo com FFmpeg para adicionar marca d'água
                $output_file = $temp_dir . '/watermarked_' . $file_name;
                $this->add_watermark_to_video($local_file, $output_file, $watermark_path);
                
                // Fazer upload do vídeo processado
                if (file_exists($output_file)) {
                    ftp_put($conn_id, $this->target_dir . '/' . $file_name, $output_file, FTP_BINARY);
                    
                    // Limpar arquivos temporários
                    unlink($local_file);
                    unlink($output_file);
                }
            }
        }
        
        // Limpar marca d'água temporária
        unlink($watermark_path);
        
        // Fechar conexão FTP
        ftp_close($conn_id);
    }
    
    private function add_watermark_to_video($input_file, $output_file, $watermark_file) {
        // Comando FFmpeg para adicionar marca d'água
        $command = "ffmpeg -i \"$input_file\" -i \"$watermark_file\" -filter_complex \"overlay=10:10\" -codec:a copy \"$output_file\"";
        
        // Executar o comando
        $return_value = null;
        $output = array();
        exec($command, $output, $return_value);
        
        return $return_value === 0;
    }
}

// Inicializar o plugin
$watermark_video_processor = new WatermarkVideoProcessor();
?>