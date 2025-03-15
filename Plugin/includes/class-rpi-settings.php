<?php
/**
 * Classe para configurações do plugin
 */
class RPI_Settings {
    /**
     * Inicializar configurações
     */
    public function init() {
        add_action('admin_menu', array($this, 'add_settings_page'));
        add_action('admin_init', array($this, 'register_settings'));
    }
    
    /**
     * Adicionar página de configurações
     */
    public function add_settings_page() {
        add_options_page(
            'Raspberry Pi Uploader',
            'RPi Uploader',
            'manage_options',
            'rpi-uploader',
            array($this, 'render_settings_page')
        );
    }
    
    /**
     * Registrar configurações
     */
    public function register_settings() {
        register_setting('rpi_uploader', 'rpi_uploader_api_key');
        register_setting('rpi_uploader', 'rpi_uploader_add_to_media', array('default' => true));
        register_setting('rpi_uploader', 'rpi_uploader_validate_mime', array('default' => true));
        register_setting('rpi_uploader', 'rpi_uploader_enable_logs', array('default' => true));
        register_setting('rpi_uploader', 'rpi_uploader_logs');
        
        add_settings_section(
            'rpi_uploader_main',
            'Configurações da API',
            array($this, 'section_main_callback'),
            'rpi-uploader'
        );
        
        add_settings_field(
            'rpi_uploader_api_key',
            'Chave API',
            array($this, 'api_key_callback'),
            'rpi-uploader',
            'rpi_uploader_main'
        );
        
        add_settings_field(
            'rpi_uploader_add_to_media',
            'Adicionar à biblioteca de mídia',
            array($this, 'add_to_media_callback'),
            'rpi-uploader',
            'rpi_uploader_main'
        );
        
        add_settings_field(
            'rpi_uploader_validate_mime',
            'Validar tipos MIME',
            array($this, 'validate_mime_callback'),
            'rpi-uploader',
            'rpi_uploader_main'
        );
        
        add_settings_field(
            'rpi_uploader_enable_logs',
            'Habilitar logs',
            array($this, 'enable_logs_callback'),
            'rpi-uploader',
            'rpi_uploader_main'
        );
    }
    
    /**
     * Renderizar página de configurações
     */
    public function render_settings_page() {
        ?>
        <div class="wrap">
            <h1>Raspberry Pi Uploader</h1>
            
            <form method="post" action="options.php">
                <?php settings_fields('rpi_uploader'); ?>
                <?php do_settings_sections('rpi-uploader'); ?>
                
                <?php submit_button(); ?>
            </form>
            
            <hr>
            
            <h2>Informações da API</h2>
            <p>Endpoint de upload: <code><?php echo esc_url(rest_url('raspberry/v1/upload')); ?></code></p>
            <p>Endpoint de teste: <code><?php echo esc_url(rest_url('raspberry/v1/test')); ?></code></p>
            
            <h3>Como usar com Python no Raspberry Pi</h3>
            <pre>
import requests

api_url = "<?php echo esc_url(rest_url('raspberry/v1/upload')); ?>"
api_key = "<?php echo esc_attr(get_option('rpi_uploader_api_key')); ?>"

headers = {
    "X-API-KEY": api_key
}

files = {
    "file": ("image.jpg", open("caminho/para/seu/arquivo.jpg", "rb"))
}

response = requests.post(api_url, headers=headers, files=files)
print(response.json())
            </pre>
            
            <?php if (get_option('rpi_uploader_enable_logs')): ?>
            <h2>Logs de Upload</h2>
            <table class="widefat">
                <thead>
                    <tr>
                        <th>Data/Hora</th>
                        <th>Status</th>
                        <th>Arquivo</th>
                        <th>Mensagem</th>
                        <th>IP</th>
                    </tr>
                </thead>
                <tbody>
                    <?php 
                    $logs = array_reverse(get_option('rpi_uploader_logs', array()));
                    if (empty($logs)): 
                    ?>
                    <tr>
                        <td colspan="5">Nenhum log disponível.</td>
                    </tr>
                    <?php 
                    else:
                        foreach ($logs as $log): 
                    ?>
                    <tr>
                        <td><?php echo esc_html($log['time']); ?></td>
                        <td><?php echo esc_html($log['status']); ?></td>
                        <td><?php echo esc_html($log['filename']); ?></td>
                        <td><?php echo esc_html($log['message']); ?></td>
                        <td><?php echo esc_html($log['ip']); ?></td>
                    </tr>
                    <?php 
                        endforeach;
                    endif; 
                    ?>
                </tbody>
            </table>
            
            <form method="post" action="">
                <input type="hidden" name="clear_logs" value="1">
                <?php wp_nonce_field('clear_rpi_logs', 'rpi_logs_nonce'); ?>
                <p class="submit">
                    <input type="submit" class="button button-secondary" value="Limpar Logs">
                </p>
            </form>
            <?php endif; ?>
        </div>
        <?php
        
        // Processar limpeza de logs
        if (isset($_POST['clear_logs']) && check_admin_referer('clear_rpi_logs', 'rpi_logs_nonce')) {
            update_option('rpi_uploader_logs', array());
            echo '<div class="updated"><p>Logs limpos com sucesso.</p></div>';
            echo '<script>window.location.reload();</script>';
        }
    }
    
    /**
     * Callback para seção principal
     */
    public function section_main_callback() {
        echo '<p>Configure a API para upload de arquivos do Raspberry Pi.</p>';
    }
    
    /**
     * Callback para campo de chave API
     */
    public function api_key_callback() {
        $api_key = get_option('rpi_uploader_api_key');
        ?>
        <input type="text" id="rpi_uploader_api_key" name="rpi_uploader_api_key" 
               value="<?php echo esc_attr($api_key); ?>" class="regular-text">
        <p class="description">Chave de autenticação para requisições da API.</p>
        <button type="button" class="button" onclick="document.getElementById('rpi_uploader_api_key').value = '<?php echo esc_js(wp_generate_password(32, false)); ?>'">Gerar Nova Chave</button>
        <?php
    }
    
    /**
     * Callback para campo de adicionar à biblioteca
     */
    public function add_to_media_callback() {
        $add_to_media = get_option('rpi_uploader_add_to_media', true);
        ?>
        <input type="checkbox" id="rpi_uploader_add_to_media" name="rpi_uploader_add_to_media" 
               value="1" <?php checked(1, $add_to_media); ?>>
        <label for="rpi_uploader_add_to_media">Adicionar arquivos enviados à biblioteca de mídia do WordPress</label>
        <?php
    }
    
    /**
     * Callback para campo de validação MIME
     */
    public function validate_mime_callback() {
        $validate_mime = get_option('rpi_uploader_validate_mime', true);
        ?>
        <input type="checkbox" id="rpi_uploader_validate_mime" name="rpi_uploader_validate_mime" 
               value="1" <?php checked(1, $validate_mime); ?>>
        <label for="rpi_uploader_validate_mime">Validar tipos de arquivo (recomendado para segurança)</label>
        <?php
    }
    
    /**
     * Callback para campo de habilitar logs
     */
    public function enable_logs_callback() {
        $enable_logs = get_option('rpi_uploader_enable_logs', true);
        ?>
        <input type="checkbox" id="rpi_uploader_enable_logs" name="rpi_uploader_enable_logs" 
               value="1" <?php checked(1, $enable_logs); ?>>
        <label for="rpi_uploader_enable_logs">Registrar logs de upload</label>
        <?php
    }
}