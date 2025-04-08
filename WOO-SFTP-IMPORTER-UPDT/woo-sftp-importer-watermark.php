<?php
/**
 * Plugin Name: WooCommerce SFTP Folder Importer with Video Watermark
 * Plugin URI: https://yourwebsite.com/
 * Description: Import products from SFTP folders only once and add logo watermark to video files
 * Version: 1.3.0
 * Author: DevSpacek
 * Text Domain: woo-sftp-importer
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
        echo '<div class="error"><p>' . __('WooCommerce SFTP Folder Importer requires WooCommerce to be installed and active.', 'woo-sftp-importer') . '</p></div>';
    });
    return;
}

// Check if PHP SSH2 extension is available
if (!extension_loaded('ssh2')) {
    add_action('admin_notices', function () {
        echo '<div class="error"><p>' . __('WooCommerce SFTP Folder Importer requires PHP SSH2 extension. Please contact your hosting provider to enable it.', 'woo-sftp-importer') . '</p></div>';
    });
}

// Define constants
define('WSFTP_WATERMARK_VERSION', '1.3.0');
define('WSFTP_WATERMARK_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('WSFTP_WATERMARK_PLUGIN_URL', plugin_dir_url(__FILE__));

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
            echo '<div class="error"><p>' . __('WooCommerce SFTP Folder Importer with Video Watermark requires FFmpeg to be installed on the server. Please contact your hosting provider or install it manually.', 'woo-sftp-importer') . '</p></div>';
        });
    }

    return $is_available;
}

// Verificação segura para garantir que o plugin original está instalado e ativo
function wsftp_is_base_plugin_active()
{
    return file_exists(WP_PLUGIN_DIR . '/woo-sftp-importer/woo-sftp-importer.php') &&
        in_array('woo-sftp-importer/woo-sftp-importer.php', apply_filters('active_plugins', get_option('active_plugins')));
}

// Verificar se podemos incluir o arquivo original
function wsftp_include_original_plugin()
{
    // Caminho para o arquivo original (ajuste conforme sua estrutura de diretórios)
    $original_plugin_path = dirname(__FILE__) . '/woo-sftp-importer 1.2.php';

    if (file_exists($original_plugin_path)) {
        // Incluímos o arquivo original com segurança para evitar duplicação de funções
        include_once($original_plugin_path);
        return true;
    } else {
        // Arquivo original não encontrado
        add_action('admin_notices', function () use ($original_plugin_path) {
            echo '<div class="error"><p>' . sprintf(__('WooCommerce SFTP Folder Importer with Video Watermark requires the original plugin file (%s). Please make sure it exists.', 'woo-sftp-importer'), $original_plugin_path) . '</p></div>';
        });
        return false;
    }
}

// Incluir o arquivo base apenas se ele existir
$original_plugin_included = wsftp_include_original_plugin();

// Add watermark tab to admin menu
add_action('admin_menu', 'wsftp_add_watermark_menu', 20);

function wsftp_add_watermark_menu()
{
    // Verificamos se o menu principal existe
    global $submenu;

    if (isset($submenu['woo-sftp-importer'])) {
        // Se o menu principal existir, adicionamos o submenu
        add_submenu_page(
            'woo-sftp-importer',
            'Video Watermark',
            'Video Watermark',
            'manage_options',
            'wsftp-watermark',
            'wsftp_watermark_page'
        );
    } else {
        // Se não, criamos um menu principal separado
        add_menu_page(
            'SFTP Watermark',
            'SFTP Watermark',
            'manage_options',
            'wsftp-watermark',
            'wsftp_watermark_page',
            'dashicons-format-video',
            31
        );
    }
}

// Create watermark settings page
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
                    echo '<li>Safe mode: ' . (ini_get('safe_mode') ? 'Ativado' : 'Desativado') . '</li>';
                    echo '<li>Sistema operacional: ' . PHP_OS . '</li>';
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

    // Adicionar seção de diagnóstico para FFmpeg
    $ffmpeg_path = get_option('wsftp_ffmpeg_path', 'não detectado');
    $ffmpeg_version = 'desconhecida';
    $ffmpeg_detected = !empty($ffmpeg_path);

    if ($ffmpeg_detected) {
        $version_output = [];
        @exec("\"$ffmpeg_path\" -version 2>&1", $version_output);
        if (!empty($version_output[0])) {
            $ffmpeg_version = $version_output[0];
        }
    }

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

        <!-- Mostrar informações de diagnóstico do FFmpeg -->
        <div class="card" style="max-width: 100%; margin-bottom: 20px; padding: 10px;">
            <h2>Diagnóstico do FFmpeg</h2>
            <table class="form-table">
                <tr>
                    <th>FFmpeg detectado:</th>
                    <td><?php echo $ffmpeg_detected ? '<span style="color:green">✓ Sim</span>' : '<span style="color:red">✗ Não</span>'; ?>
                    </td>
                </tr>
                <tr>
                    <th>Caminho do FFmpeg:</th>
                    <td><?php echo esc_html($ffmpeg_path); ?></td>
                </tr>
                <tr>
                    <th>Versão do FFmpeg:</th>
                    <td><?php echo esc_html($ffmpeg_version); ?></td>
                </tr>
                <tr>
                    <th>Funções de sistema:</th>
                    <td>
                        exec():
                        <?php echo function_exists('exec') ? '<span style="color:green">✓ Disponível</span>' : '<span style="color:red">✗ Não disponível</span>'; ?><br>
                        shell_exec():
                        <?php echo function_exists('shell_exec') ? '<span style="color:green">✓ Disponível</span>' : '<span style="color:red">✗ Não disponível</span>'; ?><br>
                        system():
                        <?php echo function_exists('system') ? '<span style="color:green">✓ Disponível</span>' : '<span style="color:red">✗ Não disponível</span>'; ?>
                    </td>
                </tr>
            </table>

            <p>
                <button type="button" id="reset-ffmpeg-detection" class="button button-secondary">
                    Redefinir detecção do FFmpeg
                </button>
                <span id="reset-ffmpeg-message" style="display:none; margin-left: 10px;"></span>
            </p>
        </div>

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
    </div>

    <script type="text/javascript">
        jQuery(document).ready(function ($) {
            // Update opacity value display when slider changes
            $('#wsftp_watermark_opacity').on('input change', function () {
                $('#opacity-value').text($(this).val());
            });

            // Reset FFmpeg detection
            $('#reset-ffmpeg-detection').on('click', function () {
                var $button = $(this);
                var $message = $('#reset-ffmpeg-message');

                $button.prop('disabled', true);
                $message.text('Redefinindo detecção...').css('color', '#666').show();

                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'wsftp_reset_ffmpeg_detection',
                        nonce: '<?php echo wp_create_nonce('wsftp_reset_ffmpeg'); ?>'
                    },
                    success: function (response) {
                        if (response.success) {
                            $message.text('Detecção redefinida com sucesso! Recarregando...').css('color', 'green');
                            setTimeout(function () {
                                location.reload();
                            }, 1500);
                        } else {
                            $message.text('Erro ao redefinir detecção: ' + response.data).css('color', 'red');
                            $button.prop('disabled', false);
                        }
                    },
                    error: function () {
                        $message.text('Erro de comunicação com o servidor.').css('color', 'red');
                        $button.prop('disabled', false);
                    }
                });
            });
        });
    </script>
    <?php
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
        wsftp_add_log("Watermark logo file not found: $watermark_path");
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
    wsftp_add_log("Executando comando FFmpeg: $command");

    // Execute command
    $output = array();
    $return_var = -1;

    if (function_exists('exec')) {
        @exec($command . " 2>&1", $output, $return_var);
    }

    if ($return_var !== 0) {
        wsftp_add_log("FFmpeg error (código $return_var): " . implode("\n", $output));

        // Tentar um comando alternativo mais simples
        $alt_command = "\"$ffmpeg_path\" -y -i \"$input_file_safe\" -i \"$watermark_path_safe\" " .
            "-filter_complex \"overlay=$position\" " .
            "-codec:a copy \"$output_file_safe\"";

        wsftp_add_log("Tentando comando alternativo: $alt_command");

        $output = array();
        $return_var = -1;

        if (function_exists('exec')) {
            @exec($alt_command . " 2>&1", $output, $return_var);
        }

        if ($return_var !== 0) {
            wsftp_add_log("FFmpeg comando alternativo falhou (código $return_var): " . implode("\n", $output));
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

/**
 * Hook into product creation process to add watermark to videos
 */
function wsftp_process_video_with_watermark($product_id, $file_path, $filename)
{
    // Only process video files
    $file_extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    $video_extensions = array('mp4', 'avi', 'mov', 'wmv', 'flv', 'webm', 'mkv');

    if (!in_array($file_extension, $video_extensions)) {
        return false;
    }

    // Create watermarked version
    $upload_dir = wp_upload_dir();
    $downloads_dir = $upload_dir['basedir'] . '/woocommerce_uploads';
    $watermarked_file = $downloads_dir . '/watermarked_' . sanitize_file_name($filename);

    // Add watermark
    $result = wsftp_add_watermark_to_video($file_path, $watermarked_file);

    if ($result) {
        wsftp_add_log("Added watermark to video for product #{$product_id}: $filename");
        return $watermarked_file;
    }

    return false;
}

/**
 * Override the original update_downloadable_file function to include watermarking
 * Esta função só será definida se o plugin original não estiver presente
 * ou se a função original não existir
 */
if (!function_exists('wsftp_update_downloadable_file_with_watermark')) {
    function wsftp_update_downloadable_file_with_watermark($product_id, $filename, $file_path)
    {
        $product = wc_get_product($product_id);

        if (!$product) {
            return 0;
        }

        // Create safe filename without spaces
        $safe_filename = sanitize_file_name($filename);

        // Create uploads folder if not exists
        $upload_dir = wp_upload_dir();
        $downloads_dir = $upload_dir['basedir'] . '/woocommerce_uploads';

        if (!file_exists($downloads_dir)) {
            wp_mkdir_p($downloads_dir);
        }

        // Create index.html to prevent directory listing
        if (!file_exists($downloads_dir . '/index.html')) {
            $file = @fopen($downloads_dir . '/index.html', 'w');
            if ($file) {
                fwrite($file, '');
                fclose($file);
            }
        }

        // Check if this is a video file and apply watermark if enabled
        $file_extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        $video_extensions = array('mp4', 'avi', 'mov', 'wmv', 'flv', 'webm', 'mkv');
        $is_video = in_array($file_extension, $video_extensions);

        // Determine which file to use
        if ($is_video && get_option('wsftp_watermark_enabled', 0)) {
            // Process video with watermark
            $watermarked_file = wsftp_process_video_with_watermark($product_id, $file_path, $filename);

            if ($watermarked_file) {
                // Use watermarked file instead
                $new_file_path = $downloads_dir . '/watermarked_' . $safe_filename;
                $file_url = $upload_dir['baseurl'] . '/woocommerce_uploads/watermarked_' . $safe_filename;

                // Store original file for reference
                copy($file_path, $downloads_dir . '/original_' . $safe_filename);
                update_post_meta($product_id, '_wsftp_original_file', $downloads_dir . '/original_' . $safe_filename);
            } else {
                // Fall back to original if watermarking fails
                $new_file_path = $downloads_dir . '/' . $safe_filename;
                copy($file_path, $new_file_path);
                $file_url = $upload_dir['baseurl'] . '/woocommerce_uploads/' . $safe_filename;
            }
        } else {
            // Regular file processing without watermark
            $new_file_path = $downloads_dir . '/' . $safe_filename;
            copy($file_path, $new_file_path);
            $file_url = $upload_dir['baseurl'] . '/woocommerce_uploads/' . $safe_filename;
        }

        // Set download file
        $download_id = md5($file_url);
        $downloads = array();

        $downloads[$download_id] = array(
            'id' => $download_id,
            'name' => $filename,
            'file' => $file_url,
        );

        // Update product data
        $product->set_downloadable(true);
        $product->set_downloads($downloads);
        $product->set_download_limit(-1); // Unlimited downloads
        $product->set_download_expiry(-1); // Never expires

        $product->save();

        // Create an attachment for the downloadable file for ACF preview
        // This won't be used for product downloads but only for preview
        $filetype = wp_check_filetype($filename, null);

        $attachment = array(
            'guid' => $file_url,
            'post_mime_type' => $filetype['type'],
            'post_title' => preg_replace('/\.[^.]+$/', '', $filename),
            'post_content' => '',
            'post_status' => 'inherit'
        );

        // Insert the attachment
        $attach_id = wp_insert_attachment($attachment, $new_file_path, $product_id);

        // Generate metadata for the attachment
        require_once(ABSPATH . 'wp-admin/includes/image.php');
        require_once(ABSPATH . 'wp-admin/includes/file.php');
        $attach_data = wp_generate_attachment_metadata($attach_id, $new_file_path);
        wp_update_attachment_metadata($attach_id, $attach_data);

        // Store the attachment ID for use with ACF preview
        update_post_meta($product_id, '_wsftp_file_attachment_id', $attach_id);

        return $attach_id;
    }
}

/**
 * Adicionar hook para processar vídeos com marca d'água
 * Esta abordagem é mais segura para evitar conflitos com funções existentes
 */
add_action('init', function () {
    // Verificamos se a função do plugin original está definida
    if (function_exists('wsftp_create_product')) {
        // Adicionamos um hook ao final do processamento de produtos para adicionar marca d'água
        add_action('wsftp_after_product_created', 'wsftp_process_video_with_watermark', 20, 3);

        // Registramos callback para esse hook no plugin original
        add_filter('wsftp_before_downloadable_file_update', function ($file_path, $product_id, $filename) {
            // Verificar se é um vídeo e se a marca d'água está habilitada
            $file_extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
            $video_extensions = array('mp4', 'avi', 'mov', 'wmv', 'flv', 'webm', 'mkv');
            $is_video = in_array($file_extension, $video_extensions);

            if ($is_video && get_option('wsftp_watermark_enabled', 0)) {
                // Processar vídeo com marca d'água
                $watermarked_file = wsftp_process_video_with_watermark($product_id, $file_path, $filename);

                if ($watermarked_file) {
                    // Retornar o caminho do arquivo com marca d'água
                    return $watermarked_file;
                }
            }

            // Retornar o caminho original se não for vídeo ou se a marcação falhar
            return $file_path;
        }, 10, 3);
    }
});

// Register activation/deactivation hooks
register_activation_hook(__FILE__, 'wsftp_watermark_activation');
register_deactivation_hook(__FILE__, 'wsftp_watermark_deactivation');

function wsftp_watermark_activation()
{
    // Initialize watermark options
    if (get_option('wsftp_watermark_enabled') === false) {
        update_option('wsftp_watermark_enabled', 0);
    }

    if (get_option('wsftp_watermark_position') === false) {
        update_option('wsftp_watermark_position', 'bottom-right');
    }

    if (get_option('wsftp_watermark_size') === false) {
        update_option('wsftp_watermark_size', 15);
    }

    if (get_option('wsftp_watermark_opacity') === false) {
        update_option('wsftp_watermark_opacity', 0.7);
    }

    // Create necessary folders
    $upload_dir = wp_upload_dir();
    $watermark_dir = $upload_dir['basedir'] . '/wsftp_watermark';

    if (!file_exists($watermark_dir)) {
        wp_mkdir_p($watermark_dir);
        file_put_contents($watermark_dir . '/index.html', '');
        file_put_contents($watermark_dir . '/.htaccess', 'Deny from all');
    }

    // Limpar cache de verificação do FFmpeg para forçar nova verificação
    delete_transient('wsftp_ffmpeg_check');
    delete_option('wsftp_ffmpeg_path');
}

function wsftp_watermark_deactivation()
{
    // Cleanup transients
    delete_transient('wsftp_ffmpeg_check');
}

// Adicionar log helper se a função original não existir
if (!function_exists('wsftp_add_log')) {
    function wsftp_add_log($message)
    {
        if (get_option('wsftp_log_enabled', 1)) {
            $log_entries = get_option('wsftp_import_log', array());

            // Limit to last 100 entries
            if (count($log_entries) >= 100) {
                array_shift($log_entries);
            }

            $log_entries[] = array(
                'time' => current_time('Y-m-d H:i:s'),
                'message' => $message
            );

            update_option('wsftp_import_log', $log_entries);
        }
    }
}

// Adicionar AJAX handler para redefinir a detecção do FFmpeg
add_action('wp_ajax_wsftp_reset_ffmpeg_detection', 'wsftp_reset_ffmpeg_detection_ajax');

function wsftp_reset_ffmpeg_detection_ajax()
{
    // Verificar segurança
    if (!wp_verify_nonce($_POST['nonce'], 'wsftp_reset_ffmpeg')) {
        wp_send_json_error('Verificação de segurança falhou.');
        exit;
    }

    // Verificar permissões
    if (!current_user_can('manage_options')) {
        wp_send_json_error('Permissão negada.');
        exit;
    }

    // Limpar dados de cache
    delete_transient('wsftp_ffmpeg_check');
    delete_option('wsftp_ffmpeg_path');

    // Forçar nova verificação
    wsftp_check_ffmpeg();

    wp_send_json_success('FFmpeg detection reset successfully.');
}
