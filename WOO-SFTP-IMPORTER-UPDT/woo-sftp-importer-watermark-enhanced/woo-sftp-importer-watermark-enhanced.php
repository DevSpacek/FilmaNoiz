<?php
/**
 * Plugin Name: WooCommerce Video Watermark Processor
 * Plugin URI: https://yourwebsite.com/
 * Description: Adds watermarks to video files for WooCommerce products (companion to SFTP Importer)
 * Version: 2.0.0
 * Author: DevSpacek
 * Text Domain: woo-sftp-watermark
 * Requires at least: 5.0
 * Requires PHP: 7.2
 * WC requires at least: 3.0
 * WC tested up to: 8.0
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

// Check if WooCommerce is active
if (!in_array('woocommerce/woocommerce.php', apply_filters('active_plugins', get_option('active_plugins')))) {
    add_action('admin_notices', function () {
        echo '<div class="error"><p>' . __('WooCommerce Video Watermark Processor requires WooCommerce to be installed and active.', 'woo-sftp-watermark') . '</p></div>';
    });
    return;
}

// Define constants
define('WSFTP_WATERMARK_VERSION', '2.0.0');
define('WSFTP_WATERMARK_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('WSFTP_WATERMARK_PLUGIN_URL', plugin_dir_url(__FILE__));

// Add admin menu
add_action('admin_menu', 'wsftp_add_watermark_menu', 10);

function wsftp_add_watermark_menu()
{
    // Criar um menu próprio para o plugin de watermark
    add_menu_page(
        'Video Watermark',
        'Video Watermark',
        'manage_options',
        'video-watermark',
        'wsftp_watermark_page',
        'dashicons-format-video',
        32
    );

    // Adicionar submenu para configurações avançadas
    add_submenu_page(
        'video-watermark',
        'Watermark Settings',
        'Watermark Settings',
        'manage_options',
        'video-watermark',
        'wsftp_watermark_page'
    );

    add_submenu_page(
        'video-watermark',
        'Enhanced Settings',
        'Enhanced Settings',
        'manage_options',
        'enhanced-watermark',
        'wsftp_enhanced_watermark_page'
    );
}

/**
 * Add log entry
 */
function wsftp_add_watermark_log($message)
{
    $log_entries = get_option('wsftp_watermark_log', array());

    // Limit to last 100 entries
    if (count($log_entries) >= 100) {
        array_shift($log_entries);
    }

    $log_entries[] = array(
        'time' => current_time('Y-m-d H:i:s'),
        'message' => $message
    );

    update_option('wsftp_watermark_log', $log_entries);
}

// Página do Watermark básico
function wsftp_watermark_page()
{
    // Save settings
    if (isset($_POST['wsftp_save_watermark']) && wp_verify_nonce($_POST['wsftp_watermark_nonce'], 'wsftp_save_watermark')) {
        // Enable/disable watermarking
        $watermark_enabled = isset($_POST['wsftp_watermark_enabled']) ? 1 : 0;
        update_option('wsftp_watermark_enabled', $watermark_enabled);

        // Watermark position
        $watermark_position = sanitize_text_field($_POST['wsftp_watermark_position']);
        update_option('wsftp_watermark_position', $watermark_position);

        // Watermark size
        $watermark_size = absint($_POST['wsftp_watermark_size']);
        if ($watermark_size < 1)
            $watermark_size = 15;
        update_option('wsftp_watermark_size', $watermark_size);

        // Watermark opacity
        $watermark_opacity = floatval($_POST['wsftp_watermark_opacity']);
        if ($watermark_opacity < 0)
            $watermark_opacity = 0;
        if ($watermark_opacity > 1)
            $watermark_opacity = 1;
        update_option('wsftp_watermark_opacity', $watermark_opacity);

        // Save watermark image if uploaded
        if (!empty($_FILES['wsftp_watermark_image']['name'])) {
            // Check file type
            $file_info = wp_check_filetype($_FILES['wsftp_watermark_image']['name']);
            $allowed_types = array('image/png', 'image/jpeg', 'image/gif');

            if (in_array($file_info['type'], $allowed_types)) {
                $upload_dir = wp_upload_dir();
                $watermark_dir = $upload_dir['basedir'] . '/wsftp_watermark';

                // Create directory if not exists
                if (!file_exists($watermark_dir)) {
                    wp_mkdir_p($watermark_dir);

                    // Create index.html to prevent directory listing
                    file_put_contents($watermark_dir . '/index.html', '');
                    file_put_contents($watermark_dir . '/.htaccess', 'Deny from all');
                }

                // Delete old watermark if exists
                $old_watermark = get_option('wsftp_watermark_path');
                if (!empty($old_watermark) && file_exists($old_watermark)) {
                    @unlink($old_watermark);
                }

                // Save new watermark
                $destination = $watermark_dir . '/watermark_logo.' . $file_info['ext'];
                move_uploaded_file($_FILES['wsftp_watermark_image']['tmp_name'], $destination);

                // Save path to database
                update_option('wsftp_watermark_path', $destination);
                update_option('wsftp_watermark_url', $upload_dir['baseurl'] . '/wsftp_watermark/watermark_logo.' . $file_info['ext']);
            } else {
                echo '<div class="notice notice-error is-dismissible"><p>Somente arquivos de imagem (PNG, JPEG, GIF) são permitidos para a logo.</p></div>';
            }
        }

        echo '<div class="notice notice-success is-dismissible"><p>Configurações de marca d\'água salvas com sucesso.</p></div>';
    }

    // Process test video if requested
    if (isset($_POST['wsftp_test_watermark']) && wp_verify_nonce($_POST['wsftp_watermark_nonce'], 'wsftp_save_watermark')) {
        if (!empty($_FILES['wsftp_test_video']['name'])) {
            // Check file type
            $file_info = wp_check_filetype($_FILES['wsftp_test_video']['name']);
            $allowed_types = array('video/mp4', 'video/avi', 'video/quicktime', 'video/x-msvideo');

            if (in_array($file_info['type'], $allowed_types)) {
                $upload_dir = wp_upload_dir();
                $temp_dir = $upload_dir['basedir'] . '/wsftp_temp';

                // Create directory if not exists
                if (!file_exists($temp_dir)) {
                    wp_mkdir_p($temp_dir);
                    file_put_contents($temp_dir . '/index.html', '');
                }

                // Save original video
                $original_video = $temp_dir . '/original.' . $file_info['ext'];
                move_uploaded_file($_FILES['wsftp_test_video']['tmp_name'], $original_video);

                // Add watermark to test video
                $watermarked_video = $temp_dir . '/watermarked.' . $file_info['ext'];
                $result = wsftp_add_watermark_to_video($original_video, $watermarked_video);

                if ($result) {
                    $watermarked_url = $upload_dir['baseurl'] . '/wsftp_temp/watermarked.' . $file_info['ext'];
                    echo '<div class="notice notice-success is-dismissible">';
                    echo '<p>Vídeo processado com sucesso! Veja o resultado:</p>';
                    echo '<video width="400" controls><source src="' . esc_url($watermarked_url) . '" type="video/mp4">Seu navegador não suporta vídeos HTML5.</video>';
                    echo '</div>';
                } else {
                    // Exibir informações de diagnóstico mais detalhadas
                    $ffmpeg_path = get_option('wsftp_ffmpeg_path', 'não detectado');
                    $ffmpeg_version = 'desconhecida';

                    if (!empty($ffmpeg_path)) {
                        $version_output = [];
                        @exec("\"$ffmpeg_path\" -version 2>&1", $version_output);
                        if (!empty($version_output[0])) {
                            $ffmpeg_version = $version_output[0];
                        }
                    }

                    echo '<div class="notice notice-error is-dismissible">';
                    echo '<p>Falha ao adicionar marca d\'água no vídeo. Verifique o log para mais detalhes.</p>';
                    echo '<p><strong>Informações de diagnóstico:</strong></p>';
                    echo '<ul>';
                    echo '<li>Caminho do FFmpeg: ' . esc_html($ffmpeg_path) . '</li>';
                    echo '<li>Versão do FFmpeg: ' . esc_html($ffmpeg_version) . '</li>';
                    echo '<li>Função exec() disponível: ' . (function_exists('exec') ? 'Sim' : 'Não') . '</li>';
                    echo '</ul>';
                    echo '</div>';
                }

                // Clean up after 5 minutes
                wp_schedule_single_event(time() + 300, 'wsftp_cleanup_test_files', array($original_video, $watermarked_video));
            } else {
                echo '<div class="notice notice-error is-dismissible"><p>Apenas arquivos de vídeo (MP4, AVI, MOV) são permitidos para teste.</p></div>';
            }
        } else {
            echo '<div class="notice notice-error is-dismissible"><p>Por favor, selecione um vídeo para teste.</p></div>';
        }
    }

    // Get current settings
    $watermark_enabled = get_option('wsftp_watermark_enabled', 0);
    $watermark_path = get_option('wsftp_watermark_path', '');
    $watermark_url = get_option('wsftp_watermark_url', '');
    $watermark_position = get_option('wsftp_watermark_position', 'bottom-right');
    $watermark_size = get_option('wsftp_watermark_size', 15);
    $watermark_opacity = get_option('wsftp_watermark_opacity', 0.7);

    // Check FFmpeg availability
    $ffmpeg_available = wsftp_check_ffmpeg();

    // Incluir o template da página - verificação se o diretório existe
    $template_dir = WSFTP_WATERMARK_PLUGIN_DIR . 'templates';
    if (!file_exists($template_dir)) {
        wp_mkdir_p($template_dir);
    }

    $watermark_template = $template_dir . '/watermark-page.php';
    
    // Criar o template se não existir
    if (!file_exists($watermark_template)) {
        wsftp_create_template_files();
    }
    
    // Se mesmo assim o template não existir, renderizar diretamente
    if (!file_exists($watermark_template)) {
        // Renderizar template diretamente aqui
        ?>
        <div class="wrap">
            <h1>Configurações de Marca d'Água para Vídeos</h1>

            <?php if (!$ffmpeg_available): ?>
                <div class="notice notice-error">
                    <p><strong>Atenção:</strong> FFmpeg não foi encontrado no servidor. Esta funcionalidade requer FFmpeg para
                        processar vídeos.</p>
                    <p>Entre em contato com seu provedor de hospedagem para instalar o FFmpeg ou instale-o manualmente no servidor.
                    </p>
                </div>
            <?php endif; ?>

            <form method="post" action="" enctype="multipart/form-data">
                <?php wp_nonce_field('wsftp_save_watermark', 'wsftp_watermark_nonce'); ?>

                <table class="form-table">
                    <tr valign="top">
                        <th scope="row">
                            <label for="wsftp_watermark_enabled">Ativar Marca d'Água</label>
                        </th>
                        <td>
                            <input type="checkbox" id="wsftp_watermark_enabled" name="wsftp_watermark_enabled" value="1" <?php checked($watermark_enabled, 1); ?> />
                            <p class="description">
                                Quando ativado, todos os vídeos importados terão a logo adicionada automaticamente
                            </p>
                        </td>
                    </tr>
                    <tr valign="top">
                        <th scope="row">
                            <label for="wsftp_watermark_image">Logo para Marca d'Água</label>
                        </th>
                        <td>
                            <input type="file" id="wsftp_watermark_image" name="wsftp_watermark_image" accept="image/*" />
                            <p class="description">
                                Recomendado: Imagem PNG transparente para melhores resultados
                            </p>
                            <?php if (!empty($watermark_url)): ?>
                                <div style="margin-top: 10px;">
                                    <strong>Logo atual:</strong><br>
                                    <img src="<?php echo esc_url($watermark_url); ?>"
                                        style="max-height: 100px; border: 1px solid #ddd; padding: 5px; background: #f7f7f7;" />
                                </div>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr valign="top">
                        <th scope="row">
                            <label for="wsftp_watermark_position">Posição da Marca d'Água</label>
                        </th>
                        <td>
                            <select id="wsftp_watermark_position" name="wsftp_watermark_position">
                                <option value="top-left" <?php selected($watermark_position, 'top-left'); ?>>Superior Esquerdo
                                </option>
                                <option value="top-right" <?php selected($watermark_position, 'top-right'); ?>>Superior Direito
                                </option>
                                <option value="bottom-left" <?php selected($watermark_position, 'bottom-left'); ?>>Inferior
                                    Esquerdo</option>
                                <option value="bottom-right" <?php selected($watermark_position, 'bottom-right'); ?>>Inferior
                                    Direito</option>
                                <option value="center" <?php selected($watermark_position, 'center'); ?>>Centro</option>
                            </select>
                        </td>
                    </tr>
                    <tr valign="top">
                        <th scope="row">
                            <label for="wsftp_watermark_size">Tamanho da Marca d'Água (%)</label>
                        </th>
                        <td>
                            <input type="number" id="wsftp_watermark_size" name="wsftp_watermark_size" min="1" max="50"
                                value="<?php echo esc_attr($watermark_size); ?>" />
                            <p class="description">
                                Porcentagem em relação ao tamanho do vídeo (recomendado: 10-20%)
                            </p>
                        </td>
                    </tr>
                    <tr valign="top">
                        <th scope="row">
                            <label for="wsftp_watermark_opacity">Opacidade da Marca d'Água</label>
                        </th>
                        <td>
                            <input type="range" id="wsftp_watermark_opacity" name="wsftp_watermark_opacity" min="0" max="1"
                                step="0.1" value="<?php echo esc_attr($watermark_opacity); ?>" />
                            <span id="opacity-value"><?php echo esc_attr($watermark_opacity); ?></span>
                            <p class="description">
                                0 = Transparente, 1 = Totalmente opaco
                            </p>
                        </td>
                    </tr>
                </table>

                <p class="submit">
                    <input type="submit" name="wsftp_save_watermark" class="button-primary" value="Salvar Configurações" />
                </p>

                <h2>Testar Marca d'Água em Vídeo</h2>
                <p>Faça upload de um vídeo de teste para verificar como a marca d'água será aplicada:</p>

                <table class="form-table">
                    <tr valign="top">
                        <th scope="row">
                            <label for="wsftp_test_video">Vídeo de Teste</label>
                        </th>
                        <td>
                            <input type="file" id="wsftp_test_video" name="wsftp_test_video" accept="video/*" />
                            <p class="description">
                                Formatos suportados: MP4, AVI, MOV
                            </p>
                        </td>
                    </tr>
                </table>

                <p class="submit">
                    <input type="submit" name="wsftp_test_watermark" class="button-secondary" value="Testar Marca d'Água" <?php echo $ffmpeg_available ? '' : 'disabled'; ?> />
                </p>
            </form>

            <script type="text/javascript">
                jQuery(document).ready(function ($) {
                    // Update opacity value display when slider changes
                    $('#wsftp_watermark_opacity').on('input change', function () {
                        $('#opacity-value').text($(this).val());
                    });
                });
            </script>
        </div>
        <?php
    } else {
        // Incluir o template existente
        include_once $watermark_template;
    }
}

// Check if FFmpeg is installed
function wsftp_check_ffmpeg()
{
    // Verificamos se já existe no cache o resultado da verificação
    $ffmpeg_check = get_transient('wsftp_ffmpeg_check');
    if ($ffmpeg_check !== false) {
        return (bool) $ffmpeg_check;
    }

    // Múltiplas formas de verificar o FFmpeg
    $ffmpeg_path = '';
    $output = [];
    $return_var = -1;

    // Verificar se o comando está disponível de forma direta
    if (function_exists('exec')) {
        @exec("ffmpeg -version 2>&1", $output, $return_var);
        if ($return_var === 0) {
            $ffmpeg_path = 'ffmpeg';
        } else {
            // Testar caminhos comuns onde o FFmpeg pode estar instalado
            $possible_paths = [
                '/usr/bin/ffmpeg',
                '/usr/local/bin/ffmpeg',
                'C:\\ffmpeg\\bin\\ffmpeg.exe',
                'C:\\Program Files\\ffmpeg\\bin\\ffmpeg.exe',
                'C:\\Program Files (x86)\\ffmpeg\\bin\\ffmpeg.exe',
            ];

            foreach ($possible_paths as $path) {
                $output = [];
                $return_var = -1;
                @exec("\"$path\" -version 2>&1", $output, $return_var);
                if ($return_var === 0) {
                    $ffmpeg_path = $path;
                    break;
                }
            }
        }
    }

    // Se ainda não encontrou, tente outras funções PHP
    if (empty($ffmpeg_path) && function_exists('shell_exec')) {
        $result = @shell_exec('ffmpeg -version 2>&1');
        if (!empty($result) && strpos($result, 'ffmpeg version') !== false) {
            $ffmpeg_path = 'ffmpeg';
        }
    }

    // Verificar usando where/which (para sistemas Windows/Unix)
    if (empty($ffmpeg_path) && function_exists('exec')) {
        $cmd = (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') ? 'where ffmpeg' : 'which ffmpeg';
        @exec($cmd, $output, $return_var);
        if ($return_var === 0 && !empty($output[0])) {
            $ffmpeg_path = $output[0];
        }
    }

    // Salvamos o caminho do FFmpeg para uso posterior
    update_option('wsftp_ffmpeg_path', $ffmpeg_path);

    $is_available = !empty($ffmpeg_path);

    // Salvamos o resultado no cache por 24 horas
    set_transient('wsftp_ffmpeg_check', $is_available ? 1 : 0, DAY_IN_SECONDS);

    // Se o FFmpeg não estiver disponível, mostramos um aviso
    if (!$is_available) {
        add_action('admin_notices', function () {
            echo '<div class="error"><p>' . __('WooCommerce Video Watermark Processor requires FFmpeg to be installed on the server. Please contact your hosting provider.', 'woo-sftp-watermark') . '</p></div>';
        });
    }

    return $is_available;
}

/**
 * Add watermark to video using FFmpeg
 */
function wsftp_add_watermark_to_video($input_file, $output_file)
{
    // Check if watermarking is enabled
    if (!get_option('wsftp_watermark_enabled', 0)) {
        // Just copy the file if watermarking is disabled
        copy($input_file, $output_file);
        return true;
    }

    // Get watermark settings
    $watermark_path = get_option('wsftp_watermark_path', '');
    $watermark_position = get_option('wsftp_watermark_position', 'bottom-right');
    $watermark_size = get_option('wsftp_watermark_size', 15);
    $watermark_opacity = get_option('wsftp_watermark_opacity', 0.7);

    if (empty($watermark_path) || !file_exists($watermark_path)) {
        wsftp_add_watermark_log("Watermark logo file not found: $watermark_path");
        return false;
    }

    // Obtenha o caminho do FFmpeg que foi detectado
    $ffmpeg_path = get_option('wsftp_ffmpeg_path', 'ffmpeg');

    // Map position to FFmpeg overlay coordinates
    $position_map = array(
        'top-left' => '10:10',
        'top-right' => 'main_w-overlay_w-10:10',
        'bottom-left' => '10:main_h-overlay_h-10',
        'bottom-right' => 'main_w-overlay_w-10:main_h-overlay_h-10',
        'center' => '(main_w-overlay_w)/2:(main_h-overlay_h)/2'
    );

    $position = $position_map[$watermark_position] ?? $position_map['bottom-right'];

    // Preparar caminhos de arquivos para uso com FFmpeg (especialmente no Windows)
    $input_file_safe = str_replace('\\', '/', $input_file);
    $output_file_safe = str_replace('\\', '/', $output_file);
    $watermark_path_safe = str_replace('\\', '/', $watermark_path);

    // Build FFmpeg command - usando filtros mais simples para melhor compatibilidade
    $command = "\"$ffmpeg_path\" -y -i \"$input_file_safe\" -i \"$watermark_path_safe\" " .
        "-filter_complex \"[1:v]scale=iw*$watermark_size/100*main_w:ih*$watermark_size/100*main_w[overlay]; " .
        "[0:v][overlay]overlay=$position\" " .
        "-codec:a copy \"$output_file_safe\"";

    // Log do comando para diagnóstico
    wsftp_add_watermark_log("Executando comando FFmpeg: $command");

    // Execute command
    $output = array();
    $return_var = -1;

    if (function_exists('exec')) {
        @exec($command . " 2>&1", $output, $return_var);
    }

    if ($return_var !== 0) {
        wsftp_add_watermark_log("FFmpeg error (código $return_var): " . implode("\n", $output));

        // Tentar um comando alternativo mais simples
        $alt_command = "\"$ffmpeg_path\" -y -i \"$input_file_safe\" -i \"$watermark_path_safe\" " .
            "-filter_complex \"overlay=$position\" " .
            "-codec:a copy \"$output_file_safe\"";

        wsftp_add_watermark_log("Tentando comando alternativo: $alt_command");

        $output = array();
        $return_var = -1;

        if (function_exists('exec')) {
            @exec($alt_command . " 2>&1", $output, $return_var);
        }

        if ($return_var !== 0) {
            wsftp_add_watermark_log("FFmpeg comando alternativo falhou (código $return_var): " . implode("\n", $output));
            return false;
        }
    }

    return true;
}

/**
 * Clean up test files
 */
add_action('wsftp_cleanup_test_files', function ($original, $watermarked) {
    if (file_exists($original)) {
        @unlink($original);
    }
    if (file_exists($watermarked)) {
        @unlink($watermarked);
    }
});

// Página do Watermark avançado
function wsftp_enhanced_watermark_page() {
    // Get current settings
    $modify_originals = get_option('wsftp_modify_originals', 0);
    $auto_generate_previews = get_option('wsftp_auto_generate_previews', 1);
    $use_preview_as_featured = get_option('wsftp_use_preview_as_featured', 1);
    $batch_size = get_option('wsftp_batch_size', 5);
    
    // Check processing status
    $batch_processing = get_option('wsftp_batch_processing', false);
    $batch_progress = get_option('wsftp_batch_progress', array('total' => 0, 'processed' => 0));
    
    // Save settings
    if (isset($_POST['wsftp_save_enhanced_watermark']) && isset($_POST['wsftp_enhanced_watermark_nonce']) && 
        wp_verify_nonce($_POST['wsftp_enhanced_watermark_nonce'], 'wsftp_save_enhanced_watermark')) {
        
        // Opção para modificar arquivos originais
        $modify_originals = isset($_POST['wsftp_modify_originals']) ? 1 : 0;
        update_option('wsftp_modify_originals', $modify_originals);
        
        // Opção para gerar previews automaticamente
        $auto_generate_previews = isset($_POST['wsftp_auto_generate_previews']) ? 1 : 0;
        update_option('wsftp_auto_generate_previews', $auto_generate_previews);
        
        // Opção para usar preview em vez de imagem em destaque
        $use_preview_as_featured = isset($_POST['wsftp_use_preview_as_featured']) ? 1 : 0;
        update_option('wsftp_use_preview_as_featured', $use_preview_as_featured);
        
        // Opção para processamento em lote
        $batch_size = absint($_POST['wsftp_batch_size']);
        if ($batch_size < 1) $batch_size = 5;
        update_option('wsftp_batch_size', $batch_size);
        
        echo '<div class="notice notice-success is-dismissible"><p>Configurações avançadas salvas com sucesso.</p></div>';
    }
    
    // Process existing products if requested
    if (isset($_POST['wsftp_process_existing']) && isset($_POST['wsftp_enhanced_watermark_nonce']) && 
        wp_verify_nonce($_POST['wsftp_enhanced_watermark_nonce'], 'wsftp_save_enhanced_watermark')) {
        
        // Schedule batch processing
        if (!$batch_processing) {
            update_option('wsftp_batch_processing', true);
            update_option('wsftp_batch_progress', array('total' => 0, 'processed' => 0));
            wp_schedule_single_event(time() + 5, 'wsftp_process_existing_videos_batch');
            
            echo '<div class="notice notice-info is-dismissible"><p>Processamento em lote agendado. A página será atualizada em breve para mostrar o progresso.</p></div>';
            echo '<meta http-equiv="refresh" content="5">'; // Auto-refresh after 5 seconds
        }
    }
    
    ?>
    <div class="wrap">
        <h1>Configurações Avançadas de Marca d'Água em Vídeos</h1>
        
        <div class="notice notice-info">
            <p><strong>Importante:</strong> Esta página permite configurações avançadas para o processamento de vídeos com marca d'água.</p>
        </div>
        
        <form method="post" action="">
            <?php wp_nonce_field('wsftp_save_enhanced_watermark', 'wsftp_enhanced_watermark_nonce'); ?>
            
            <table class="form-table">
                <tr valign="top">
                    <th scope="row">
                        <label for="wsftp_auto_generate_previews">Gerar Previews Automaticamente</label>
                    </th>
                    <td>
                        <input type="checkbox" id="wsftp_auto_generate_previews" name="wsftp_auto_generate_previews" 
                               value="1" <?php checked($auto_generate_previews, 1); ?> />
                        <p class="description">
                            Quando ativado, gera automaticamente uma imagem de preview a partir do vídeo para cada produto
                        </p>
                    </td>
                </tr>
                <tr valign="top">
                    <th scope="row">
                        <label for="wsftp_use_preview_as_featured">Usar Preview como Imagem Destacada</label>
                    </th>
                    <td>
                        <input type="checkbox" id="wsftp_use_preview_as_featured" name="wsftp_use_preview_as_featured" 
                               value="1" <?php checked($use_preview_as_featured, 1); ?> />
                        <p class="description">
                            Define a imagem de preview gerada como imagem destacada do produto
                        </p>
                    </td>
                </tr>
                <tr valign="top">
                    <th scope="row">
                        <label for="wsftp_modify_originals">Substituir Arquivos Originais</label>
                    </th>
                    <td>
                        <input type="checkbox" id="wsftp_modify_originals" name="wsftp_modify_originals" 
                               value="1" <?php checked($modify_originals, 1); ?> />
                        <p class="description">
                            <strong>Atenção:</strong> Se ativado, tenta substituir os arquivos originais com as versões com marca d'água
                        </p>
                    </td>
                </tr>
                <tr valign="top">
                    <th scope="row">
                        <label for="wsftp_batch_size">Tamanho do Lote de Processamento</label>
                    </th>
                    <td>
                        <input type="number" id="wsftp_batch_size" name="wsftp_batch_size" min="1" max="50" 
                               value="<?php echo esc_attr($batch_size); ?>" />
                        <p class="description">
                            Número de vídeos processados de uma vez (recomendado: 5-10 para evitar timeouts)
                        </p>
                    </td>
                </tr>
            </table>
            
            <p class="submit">
                <input type="submit" name="wsftp_save_enhanced_watermark" class="button-primary" value="Salvar Configurações" />
            </p>
            
            <h2>Processamento em Lote</h2>
            <p>Use esta opção para processar todos os vídeos de produtos existentes com marca d'água:</p>
            
            <?php if ($batch_processing): ?>
                <div class="notice notice-info inline">
                    <p>Processamento em andamento: <?php echo $batch_progress['processed']; ?> de <?php echo $batch_progress['total']; ?> produtos processados.</p>
                    <div style="height: 20px; width: 100%; background: #f0f0f1; margin-top: 10px;">
                        <div style="height: 100%; width: <?php echo ($batch_progress['total'] > 0) ? ($batch_progress['processed'] / $batch_progress['total'] * 100) : 0; ?>%; background: #2271b1;"></div>
                    </div>
                    <p><em>Esta página será atualizada automaticamente a cada 30 segundos. Última atualização: <?php echo date('H:i:s'); ?></em></p>
                    <meta http-equiv="refresh" content="30">
                </div>
            <?php else: ?>
                <p class="submit">
                    <input type="submit" name="wsftp_process_existing" class="button-secondary" value="Processar Vídeos Existentes" 
                           onclick="return confirm('Esta ação irá processar todos os vídeos nos produtos existentes. Continuar?');" />
                </p>
            <?php endif; ?>
            
            <h2>Log de Processamento</h2>
            <div style="max-height: 300px; overflow-y: auto; background: #f5f5f5; padding: 10px; border: 1px solid #ddd;">
                <?php 
                $logs = get_option('wsftp_watermark_log', array());
                if (empty($logs)) {
                    echo '<p>Nenhum registro de processamento disponível.</p>';
                } else {
                    echo '<ul style="margin: 0; padding-left: 20px;">';
                    foreach (array_reverse($logs) as $log) {
                        echo '<li><strong>[' . esc_html($log['time']) . ']</strong> ' . esc_html($log['message']) . '</li>';
                    }
                    echo '</ul>';
                }
                ?>
            </div>
            
            <p>
                <input type="submit" name="wsftp_clear_log" class="button-secondary" value="Limpar Log" 
                       onclick="return confirm('Limpar todo o histórico de log?');" />
            </p>
        </form>
    </div>
    <?php
    
    // Process clear log if requested
    if (isset($_POST['wsftp_clear_log']) && isset($_POST['wsftp_enhanced_watermark_nonce']) && 
        wp_verify_nonce($_POST['wsftp_enhanced_watermark_nonce'], 'wsftp_save_enhanced_watermark')) {
        
        update_option('wsftp_watermark_log', array());
        echo '<script>window.location.reload();</script>';
    }
}

// Hook para processar vídeos existentes em lote
add_action('wsftp_process_existing_videos_batch', 'wsftp_enhanced_process_existing_videos_batch');

function wsftp_enhanced_process_existing_videos_batch() {
    // Verificar se já está em processamento
    if (get_option('wsftp_batch_processing', false) !== true) {
        return;
    }
    
    // Obter produtos com downloads
    $args = array(
        'status' => 'publish',
        'limit' => -1,
        'downloadable' => true,
        'return' => 'ids',
    );
    
    $product_ids = wc_get_products($args);
    
    if (empty($product_ids)) {
        update_option('wsftp_batch_processing', false);
        wsftp_add_watermark_log("Nenhum produto com download encontrado para processamento.");
        return;
    }
    
    // Atualizar progresso
    update_option('wsftp_batch_progress', array(
        'total' => count($product_ids),
        'processed' => 0
    ));
    
    // Iniciar processamento em lote
    wsftp_add_watermark_log("Iniciando processamento em lote de " . count($product_ids) . " produtos.");
    wp_schedule_single_event(time() + 5, 'wsftp_process_videos_batch', array($product_ids, 0));
}

// Processar lote de produtos
add_action('wsftp_process_videos_batch', 'wsftp_process_videos_batch', 10, 2);

function wsftp_process_videos_batch($product_ids, $offset) {
    $batch_size = get_option('wsftp_batch_size', 5);
    $current_batch = array_slice($product_ids, $offset, $batch_size);
    
    if (empty($current_batch)) {
        // Processamento concluído
        update_option('wsftp_batch_processing', false);
        wsftp_add_watermark_log("Processamento em lote concluído.");
        return;
    }
    
    $processed_count = 0;
    
    foreach ($current_batch as $product_id) {
        $product = wc_get_product($product_id);
        if (!$product || !$product->is_downloadable()) {
            continue;
        }
        
        // Processar downloads do produto
        $downloads = $product->get_downloads();
        foreach ($downloads as $download_id => $download) {
            if (wsftp_is_video_file($download->get_name())) {
                wsftp_process_woo_product_video($product_id, $download->get_file(), $download->get_name(), $download_id);
                $processed_count++;
            }
        }
    }
    
    // Atualizar progresso
    $progress = get_option('wsftp_batch_progress');
    $progress['processed'] += $processed_count;
    update_option('wsftp_batch_progress', $progress);
    
    // Agendar próximo lote
    wp_schedule_single_event(time() + 10, 'wsftp_process_videos_batch', array(
        $product_ids, 
        $offset + $batch_size
    ));
    
    wsftp_add_watermark_log("Lote processado: " . $processed_count . " vídeos. Progresso: " . 
                           $progress['processed'] . " de " . $progress['total']);
}

// Função para gerar preview do vídeo
function wsftp_generate_video_preview($video_file, $product_id) {
    // Verificar se FFmpeg está disponível
    $ffmpeg_path = get_option('wsftp_ffmpeg_path', 'ffmpeg');
    if (empty($ffmpeg_path)) {
        wsftp_add_watermark_log("FFmpeg não disponível para gerar preview do vídeo para produto #$product_id");
        return false;
    }
    
    // Criar caminho para o preview
    $upload_dir = wp_upload_dir();
    $preview_file = $upload_dir['path'] . '/preview_' . $product_id . '_' . time() . '.jpg';
    
    // Preparar comando para capturar frame aos 3 segundos do vídeo
    $video_file_safe = str_replace('\\', '/', $video_file);
    $preview_file_safe = str_replace('\\', '/', $preview_file);
    
    // Executar o comando FFmpeg
    $command = "\"$ffmpeg_path\" -y -i \"$video_file_safe\" -ss 00:00:03 -frames:v 1 \"$preview_file_safe\"";
    $output = array();
    $return_var = -1;
    
    if (function_exists('exec')) {
        @exec($command . " 2>&1", $output, $return_var);
    }
    
    if ($return_var !== 0 || !file_exists($preview_file)) {
        wsftp_add_watermark_log("Falha ao gerar preview para produto #$product_id. Erro: " . implode("\n", $output));
        return false;
    }
    
    // Criar anexo para o preview
    $filetype = wp_check_filetype(basename($preview_file), null);
    
    $attachment = array(
        'guid' => $upload_dir['url'] . '/' . basename($preview_file),
        'post_mime_type' => $filetype['type'],
        'post_title' => 'Preview for product #' . $product_id,
        'post_content' => '',
        'post_status' => 'inherit'
    );
    
    $attach_id = wp_insert_attachment($attachment, $preview_file, $product_id);
    
    if (!$attach_id) {
        wsftp_add_watermark_log("Falha ao criar anexo de preview para produto #$product_id");
        return false;
    }
    
    // Gerar metadados para o anexo
    require_once(ABSPATH . 'wp-admin/includes/image.php');
    $attach_data = wp_generate_attachment_metadata($attach_id, $preview_file);
    wp_update_attachment_metadata($attach_id, $attach_data);
    
    wsftp_add_watermark_log("Preview gerado com sucesso para produto #$product_id");
    
    return $attach_id;
}

// Verificar se o arquivo é um vídeo
function wsftp_is_video_file($filename)
{
    $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    $video_extensions = array('mp4', 'avi', 'mov', 'wmv', 'flv', 'webm', 'mkv');

    return in_array($ext, $video_extensions);
}

// Processar vídeo para produto WooCommerce
function wsftp_process_woo_product_video($product_id, $file_url, $file_name, $download_id)
{
    // Verificar se a marca d'água está ativada
    if (!get_option('wsftp_watermark_enabled', 0)) {
        return;
    }

    // Converter URL para caminho do arquivo
    $upload_dir = wp_upload_dir();
    $file_path = '';

    // Verificar se é uma URL local
    if (strpos($file_url, $upload_dir['baseurl']) === 0) {
        $file_path = str_replace($upload_dir['baseurl'], $upload_dir['basedir'], $file_url);
    } else {
        // Se for uma URL externa, baixar o arquivo
        $temp_file = download_url($file_url);
        if (!is_wp_error($temp_file)) {
            $file_path = $temp_file;
        }
    }

    if (empty($file_path) || !file_exists($file_path)) {
        wsftp_add_watermark_log("Arquivo não encontrado: $file_url");
        return;
    }

    // Preparar caminho para arquivo com marca d'água
    $downloads_dir = $upload_dir['basedir'] . '/woocommerce_uploads';
    $safe_filename = sanitize_file_name($file_name);
    $watermarked_file = $downloads_dir . '/watermarked_' . $safe_filename;

    // Garantir que o diretório existe
    if (!file_exists($downloads_dir)) {
        wp_mkdir_p($downloads_dir);
    }

    // Adicionar marca d'água
    $result = wsftp_add_watermark_to_video($file_path, $watermarked_file);

    if ($result) {
        // Atualizar produto com o arquivo com marca d'água
        $product = wc_get_product($product_id);
        $watermarked_url = str_replace($upload_dir['basedir'], $upload_dir['baseurl'], $watermarked_file);

        $downloads = $product->get_downloads();
        if (isset($downloads[$download_id])) {
            $downloads[$download_id]->set_file($watermarked_url);
            $product->set_downloads($downloads);
            $product->save();

            wsftp_add_watermark_log("Vídeo com marca d'água aplicado ao produto #$product_id: $file_name");

            // Gerar preview se necessário
            if (get_option('wsftp_auto_generate_previews', 1)) {
                $preview_image = wsftp_generate_video_preview($watermarked_file, $product_id);

                if ($preview_image && get_option('wsftp_use_preview_as_featured', 1)) {
                    set_post_thumbnail($product_id, $preview_image);
                }
            }
        }
    }

    // Limpar arquivo temporário se foi baixado
    if (isset($temp_file) && file_exists($temp_file)) {
        @unlink($temp_file);
    }
}

// Integrar com o plugin SFTP Importer (quando disponível)
add_action('wsftp_after_product_created', 'wsftp_handle_imported_product', 20, 3);

function wsftp_handle_imported_product($product_id, $file_path, $filename)
{
    // Verificar se é um vídeo
    if (!wsftp_is_video_file($filename)) {
        return;
    }

    // Verificar se a marca d'água está ativada
    if (!get_option('wsftp_watermark_enabled', 0)) {
        return;
    }

    // Preparar caminho para arquivo com marca d'água
    $upload_dir = wp_upload_dir();
    $downloads_dir = $upload_dir['basedir'] . '/woocommerce_uploads';
    $safe_filename = sanitize_file_name($filename);
    $watermarked_file = $downloads_dir . '/watermarked_' . $safe_filename;

    // Garantir que o diretório existe
    if (!file_exists($downloads_dir)) {
        wp_mkdir_p($downloads_dir);
    }

    // Adicionar marca d'água
    $result = wsftp_add_watermark_to_video($file_path, $watermarked_file);

    if ($result) {
        // Atualizar produto com o arquivo com marca d'água
        $product = wc_get_product($product_id);
        $watermarked_url = str_replace($upload_dir['basedir'], $upload_dir['baseurl'], $watermarked_file);

        $downloads = $product->get_downloads();
        if (!empty($downloads)) {
            $download_id = key($downloads);
            $downloads[$download_id]->set_file($watermarked_url);
            $product->set_downloads($downloads);
            $product->save();

            wsftp_add_watermark_log("Vídeo com marca d'água aplicado ao produto #$product_id: $filename");
        }   
            // Verificar se devemos usar o mesmo arquivo para preview no ACF
        // Gerar preview se necessáriosame_file', 1) && function_exists('wsftp_attach_preview_to_acf')) {
        if (get_option('wsftp_auto_generate_previews', 1)) { para definir o preview
            $preview_image = wsftp_generate_video_preview($watermarked_file, $product_id);
                $attach_result = wsftp_attach_preview_to_acf($product_id, $watermarked_file, $acf_field);
            if ($preview_image && get_option('wsftp_use_preview_as_featured', 1)) {
                set_post_thumbnail($product_id, $preview_image);rk também definido como preview ACF para produto #$product_id");
            }   }
        }   } else {
    }           // Criar um novo anexo específico para ACF usando o arquivo com watermark
}               $preview_image = wsftp_generate_video_preview($watermarked_file, $product_id);
                
// Adicionar hooks de ativação/desativaçãoiew no ACF se as funções ACF estão disponíveis
register_activation_hook(__FILE__, 'wsftp_watermark_activation');ld')) {
register_deactivation_hook(__FILE__, 'wsftp_watermark_deactivation');, 'preview_file');
                    $acf_group = get_option('wsftp_acf_field_group', 'product_details');
function wsftp_watermark_activation()
{                   // Tentar obter a chave do campo ACF
    // Inicializar opçõesd_key = false;
    if (get_option('wsftp_watermark_enabled') === false) {ld_key')) {
        update_option('wsftp_watermark_enabled', 0);ield_key($acf_field, $acf_group);
    }               }
                    
    if (get_option('wsftp_watermark_position') === false) {
        update_option('wsftp_watermark_position', 'bottom-right');product_id);
    }               } else {
                        update_field($acf_field, $preview_image, $product_id);
    if (get_option('wsftp_watermark_size') === false) {
        update_option('wsftp_watermark_size', 15);
    }               // Também armazenar diretamente como meta
                    update_post_meta($product_id, $acf_field, $preview_image);
    if (get_option('wsftp_watermark_opacity') === false) {
        update_option('wsftp_watermark_opacity', 0.7);om marca d'água configurado para produto #$product_id via ACF");
    }           }
                
    if (get_option('wsftp_auto_generate_previews') === false) {
        update_option('wsftp_auto_generate_previews', 1);se_preview_as_featured', 1)) {
    }               set_post_thumbnail($product_id, $preview_image);
                }
    if (get_option('wsftp_use_preview_as_featured') === false) {
        update_option('wsftp_use_preview_as_featured', 1);
    }
}
    // Criar diretórios necessários
    $upload_dir = wp_upload_dir();ativação
    $watermark_dir = $upload_dir['basedir'] . '/wsftp_watermark';
register_deactivation_hook(__FILE__, 'wsftp_watermark_deactivation');
    if (!file_exists($watermark_dir)) {
        wp_mkdir_p($watermark_dir);()
        file_put_contents($watermark_dir . '/index.html', '');
        file_put_contents($watermark_dir . '/.htaccess', 'Deny from all');
    }f (get_option('wsftp_watermark_enabled') === false) {
        update_option('wsftp_watermark_enabled', 0);
    // Limpar cache de verificação do FFmpeg para forçar nova verificação
    delete_transient('wsftp_ffmpeg_check');
    delete_option('wsftp_ffmpeg_path');ition') === false) {
}       update_option('wsftp_watermark_position', 'bottom-right');
    }
function wsftp_watermark_deactivation()
{   if (get_option('wsftp_watermark_size') === false) {
    // Limpar transientssftp_watermark_size', 15);
    delete_transient('wsftp_ffmpeg_check');
}
    if (get_option('wsftp_watermark_opacity') === false) {
// Criar o template para a página de watermark', 0.7);
function wsftp_create_template_files()
{
    $template_dir = WSFTP_WATERMARK_PLUGIN_DIR . 'templates'; {
        update_option('wsftp_auto_generate_previews', 1);
    if (!file_exists($template_dir)) {
        wp_mkdir_p($template_dir);
    }f (get_option('wsftp_use_preview_as_featured') === false) {
        update_option('wsftp_use_preview_as_featured', 1);
    $watermark_template = $template_dir . '/watermark-page.php';

    if (!file_exists($watermark_template)) {
        $template_content = <<<EOT
<div class="wrap"> = $upload_dir['basedir'] . '/wsftp_watermark';
    <h1>Configurações de Marca d'Água para Vídeos</h1>
    if (!file_exists($watermark_dir)) {
    <?php if (!$ffmpeg_available): ?>
        <div class="notice notice-error">. '/index.html', '');
            <p><strong>Atenção:</strong> FFmpeg não foi encontrado no servidor. Esta funcionalidade requer FFmpeg para
                processar vídeos.</p>
            <p>Entre em contato com seu provedor de hospedagem para instalar o FFmpeg ou instale-o manualmente no servidor.
            </p>che de verificação do FFmpeg para forçar nova verificação
        </div>nsient('wsftp_ffmpeg_check');
    <?php endif; ?>wsftp_ffmpeg_path');
}
    <form method="post" action="" enctype="multipart/form-data">
        <?php wp_nonce_field('wsftp_save_watermark', 'wsftp_watermark_nonce'); ?>
{
        <table class="form-table">
            <tr valign="top">fmpeg_check');
                <th scope="row">
                    <label for="wsftp_watermark_enabled">Ativar Marca d'Água</label>
                </th>ara a página de watermark
                <td>e_template_files()
                    <input type="checkbox" id="wsftp_watermark_enabled" name="wsftp_watermark_enabled" value="1" <?php checked($watermark_enabled, 1); ?> />
                    <p class="description">DIR . 'templates';
                        Quando ativado, todos os vídeos importados terão a logo adicionada automaticamente
                    </p>mplate_dir)) {
                </td>emplate_dir);
            </tr>
            <tr valign="top">
                <th scope="row">ate_dir . '/watermark-page.php';
                    <label for="wsftp_watermark_image">Logo para Marca d'Água</label>
                </th>$watermark_template)) {
                <td>ntent = <<<EOT
                    <input type="file" id="wsftp_watermark_image" name="wsftp_watermark_image" accept="image/*" />
                    <p class="description">Vídeos</h1>
                        Recomendado: Imagem PNG transparente para melhores resultados
                    </p>vailable): ?>
                    <?php if (!empty($watermark_url)): ?>
                        <div style="margin-top: 10px;"> encontrado no servidor. Esta funcionalidade requer FFmpeg para
                            <strong>Logo atual:</strong><br>
                            <img src="<?php echo esc_url($watermark_url); ?>"o FFmpeg ou instale-o manualmente no servidor.
                                style="max-height: 100px; border: 1px solid #ddd; padding: 5px; background: #f7f7f7;" />
                        </div>
                    <?php endif; ?>
                </td>
            </tr>"post" action="" enctype="multipart/form-data">
            <tr valign="top">'wsftp_save_watermark', 'wsftp_watermark_nonce'); ?>
                <th scope="row">
                    <label for="wsftp_watermark_position">Posição da Marca d'Água</label>
                </th>n="top">
                <td>scope="row">
                    <select id="wsftp_watermark_position" name="wsftp_watermark_position">
                        <option value="top-left" <?php selected($watermark_position, 'top-left'); ?>>Superior Esquerdo
                        </option>
                        <option value="top-right" <?php selected($watermark_position, 'top-right'); ?>>Superior Direitochecked($watermark_enabled, 1); ?> />
                        </option>cription">
                        <option value="bottom-left" <?php selected($watermark_position, 'bottom-left'); ?>>Inferior
                            Esquerdo</option>
                        <option value="bottom-right" <?php selected($watermark_position, 'bottom-right'); ?>>Inferior
                            Direito</option>
                        <option value="center" <?php selected($watermark_position, 'center'); ?>>Centro</option>
                    </select>w">
                </td>label for="wsftp_watermark_image">Logo para Marca d'Água</label>
            </tr>/th>
            <tr valign="top">
                <th scope="row">"file" id="wsftp_watermark_image" name="wsftp_watermark_image" accept="image/*" />
                    <label for="wsftp_watermark_size">Tamanho da Marca d'Água (%)</label>
                </th>   Recomendado: Imagem PNG transparente para melhores resultados
                <td></p>
                    <input type="number" id="wsftp_watermark_size" name="wsftp_watermark_size" min="1" max="50"
                        value="<?php echo esc_attr($watermark_size); ?>" />
                    <p class="description">ual:</strong><br>
                        Porcentagem em relação ao tamanho do vídeo (recomendado: 10-20%)
                    </p>        style="max-height: 100px; border: 1px solid #ddd; padding: 5px; background: #f7f7f7;" />
                </td>   </div>
            </tr>   <?php endif; ?>
            <tr valign="top">
                <th scope="row">
                    <label for="wsftp_watermark_opacity">Opacidade da Marca d'Água</label>
                </th>cope="row">
                <td><label for="wsftp_watermark_position">Posição da Marca d'Água</label>
                    <input type="range" id="wsftp_watermark_opacity" name="wsftp_watermark_opacity" min="0" max="1"
                        step="0.1" value="<?php echo esc_attr($watermark_opacity); ?>" />
                    <span id="opacity-value"><?php echo esc_attr($watermark_opacity); ?></span>
                    <p class="description">left" <?php selected($watermark_position, 'top-left'); ?>>Superior Esquerdo
                        0 = Transparente, 1 = Totalmente opaco
                    </p><option value="top-right" <?php selected($watermark_position, 'top-right'); ?>>Superior Direito
                </td>   </option>
            </tr>       <option value="bottom-left" <?php selected($watermark_position, 'bottom-left'); ?>>Inferior
        </table>            Esquerdo</option>
                        <option value="bottom-right" <?php selected($watermark_position, 'bottom-right'); ?>>Inferior
        <p class="submit">  Direito</option>
            <input type="submit" name="wsftp_save_watermark" class="button-primary" value="Salvar Configurações" />
        </p>        </select>
                </td>
        <h2>Testar Marca d'Água em Vídeo</h2>
        <p>Faça upload de um vídeo de teste para verificar como a marca d'água será aplicada:</p>
                <th scope="row">
        <table class="form-table">ftp_watermark_size">Tamanho da Marca d'Água (%)</label>
            <tr valign="top">
                <th scope="row">
                    <label for="wsftp_test_video">Vídeo de Teste</label>"wsftp_watermark_size" min="1" max="50"
                </th>   value="<?php echo esc_attr($watermark_size); ?>" />
                <td><p class="description">
                    <input type="file" id="wsftp_test_video" name="wsftp_test_video" accept="video/*" />
                    <p class="description">
                        Formatos suportados: MP4, AVI, MOV
                    </p>
                </td>n="top">
            </tr>th scope="row">
        </table>    <label for="wsftp_watermark_opacity">Opacidade da Marca d'Água</label>
                </th>
        <p class="submit">
            <input type="submit" name="wsftp_test_watermark" class="button-secondary" value="Testar Marca d'Água" <?php echo $ffmpeg_available ? '' : 'disabled'; ?> />
        </p>            step="0.1" value="<?php echo esc_attr($watermark_opacity); ?>" />
    </form>         <span id="opacity-value"><?php echo esc_attr($watermark_opacity); ?></span>
                    <p class="description">
    <script type="text/javascript">rente, 1 = Totalmente opaco
        jQuery(document).ready(function ($) {
            // Update opacity value display when slider changes
            $('#wsftp_watermark_opacity').on('input change', function () {
                $('#opacity-value').text($(this).val());
            });
        });class="submit">
    </script>input type="submit" name="wsftp_save_watermark" class="button-primary" value="Salvar Configurações" />
</div>  </p>
EOT;
        <h2>Testar Marca d'Água em Vídeo</h2>
        file_put_contents($watermark_template, $template_content);marca d'água será aplicada:</p>
    }
}       <table class="form-table">
            <tr valign="top">
// Criar templates na ativação">
register_activation_hook(__FILE__, 'wsftp_create_template_files');label>
                </th>






























































}    }        }            }                wsftp_process_woo_product_video($product_id, $download->get_file(), $download->get_name(), $download_id);            if (strpos($download->get_file(), '/watermarked_') === false) {            // Verificar se já tem watermark no URL        if (wsftp_is_video_file($download->get_name())) {    foreach ($downloads as $download_id => $download) {    $downloads = $product->get_downloads();    // Verificar downloads e aplicar watermark se necessário        }        return;    if (!$product || !$product->is_downloadable()) {    $product = wc_get_product($product_id);        }        return;    if (empty($product_key)) {    $product_key = get_post_meta($product_id, '_wsftp_product_key', true);    // Verificar se o produto foi criado pelo nosso pluginfunction wsftp_ensure_watermark_on_update($product_id) {add_action('woocommerce_process_product_meta', 'wsftp_ensure_watermark_on_update', 20, 1);// Adicionar ação para garantir que qualquer atualização de produto use o arquivo com watermark}    return $file_path;    // Se tudo falhar, retorne o caminho original        }        return $watermarked_file;        // Se o arquivo com watermark já existe, use-o    } else if (file_exists($watermarked_file)) {        }            return $watermarked_file;            wsftp_add_watermark_log("Watermark aplicado para preview ACF do produto #$product_id: $file_name");        if (wsftp_add_watermark_to_video($file_path, $watermarked_file)) {    if (!file_exists($watermarked_file)) {    // Adicionar marca d'água se o arquivo ainda não existir        $watermarked_file = $downloads_dir . '/watermarked_' . $safe_filename;    $safe_filename = sanitize_file_name($file_name);    $downloads_dir = $upload_dir['basedir'] . '/woocommerce_uploads';    $upload_dir = wp_upload_dir();    // Preparar caminho para arquivo com marca d'água        }        return $file_path;    if (strpos($file_path, '/watermarked_') !== false) {    // Verificar se o arquivo já tem watermark (para evitar processar duas vezes)        }        return $file_path;    if (!wsftp_is_video_file($file_name) || !get_option('wsftp_watermark_enabled', 0)) {    // Verificar se é um vídeo e se a watermark está ativadafunction wsftp_process_preview_watermark($file_path, $product_id, $file_name) {add_filter('wsftp_before_preview_attach', 'wsftp_process_preview_watermark', 10, 3);// Adicionar filtro para integrar com o processo de attach_preview do plugin SFTP Importer base                <td>
                    <input type="file" id="wsftp_test_video" name="wsftp_test_video" accept="video/*" />
                    <p class="description">
                        Formatos suportados: MP4, AVI, MOV
                    </p>
                </td>
            </tr>
        </table>

        <p class="submit">
            <input type="submit" name="wsftp_test_watermark" class="button-secondary" value="Testar Marca d'Água" <?php echo $ffmpeg_available ? '' : 'disabled'; ?> />
        </p>
    </form>
    
    <script type="text/javascript">
        jQuery(document).ready(function ($) {
            // Update opacity value display when slider changes
            $('#wsftp_watermark_opacity').on('input change', function () {
                $('#opacity-value').text($(this).val());
            });
        });
    </script>
</div>
EOT;

        file_put_contents($watermark_template, $template_content);
    }
}

// Criar templates na ativação
register_activation_hook(__FILE__, 'wsftp_create_template_files');
