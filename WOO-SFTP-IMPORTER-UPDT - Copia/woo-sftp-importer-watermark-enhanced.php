<?php
/**
 * Plugin Name: WooCommerce SFTP Folder Importer with Enhanced Video Watermark
 * Plugin URI: https://yourwebsite.com/
 * Description: Import products from SFTP folders and add logo watermark to video files with automatic preview generation
 * Version: 1.4.0
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

// Define constants
define('WSFTP_ENHANCED_VERSION', '1.4.0');
define('WSFTP_ENHANCED_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('WSFTP_ENHANCED_PLUGIN_URL', plugin_dir_url(__FILE__));

// NÃO incluir arquivos aqui, vai causar conflito
// Vamos verificar apenas a existência para gerar warnings
$original_plugin_found = false;
$version_1_3_path = dirname(__FILE__) . '/woo-sftp-importer-watermark.php';
$version_1_2_path = dirname(__FILE__) . '/woo-sftp-importer 1.2.php';

if (file_exists($version_1_3_path)) {
    $original_plugin_found = true;
} elseif (file_exists($version_1_2_path)) {
    $original_plugin_found = true;
}

// Avisar se o plugin base não foi encontrado
if (!$original_plugin_found) {
    add_action('admin_notices', function () {
        echo '<div class="notice notice-warning"><p>' . __('WooCommerce SFTP Folder Importer with Enhanced Watermark works better with either version 1.2 or 1.3 of the base plugin. Some features might be limited.', 'woo-sftp-importer') . '</p></div>';
    });
}

// Adicionar configurações extras para o plugin aprimorado
add_action('admin_menu', 'wsftp_add_enhanced_watermark_menu', 30);

function wsftp_add_enhanced_watermark_menu()
{
    // Verificamos se o menu principal existe
    global $submenu;

    if (isset($submenu['woo-sftp-importer'])) {
        // Se o menu principal existir, adicionamos o submenu
        add_submenu_page(
            'woo-sftp-importer',
            'Enhanced Watermark',
            'Enhanced Watermark',
            'manage_options',
            'wsftp-enhanced-watermark',
            'wsftp_enhanced_watermark_page'
        );
    } else {
        // Criar um menu próprio
        add_menu_page(
            'Enhanced SFTP Watermark',
            'Enhanced SFTP Watermark',
            'manage_options',
            'wsftp-enhanced-watermark',
            'wsftp_enhanced_watermark_page',
            'dashicons-format-video',
            32
        );
    }
}

// Página de configurações aprimoradas
function wsftp_enhanced_watermark_page()
{
    // Salvar configurações
    if (isset($_POST['wsftp_save_enhanced_watermark']) && wp_verify_nonce($_POST['wsftp_enhanced_watermark_nonce'], 'wsftp_save_enhanced_watermark')) {
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
        if ($batch_size < 1)
            $batch_size = 5;
        update_option('wsftp_batch_size', $batch_size);

        echo '<div class="notice notice-success is-dismissible"><p>Configurações avançadas salvas com sucesso.</p></div>';
    }

    // Processar lote de arquivos existentes
    if (isset($_POST['wsftp_process_existing']) && wp_verify_nonce($_POST['wsftp_enhanced_watermark_nonce'], 'wsftp_save_enhanced_watermark')) {
        // Agendar processamento em lote
        wp_schedule_single_event(time(), 'wsftp_process_existing_videos_batch');
        echo '<div class="notice notice-info is-dismissible"><p>Processamento de vídeos existentes agendado. Este processo pode levar algum tempo, dependendo da quantidade de vídeos.</p></div>';
    }

    // Verificar o status do processamento em lote
    $batch_processing = get_option('wsftp_batch_processing', false);
    $batch_progress = get_option('wsftp_batch_progress', array('total' => 0, 'processed' => 0));

    // Obter configurações atuais
    $modify_originals = get_option('wsftp_modify_originals', 0);
    $auto_generate_previews = get_option('wsftp_auto_generate_previews', 1);
    $use_preview_as_featured = get_option('wsftp_use_preview_as_featured', 1);
    $batch_size = get_option('wsftp_batch_size', 5);

    ?>
    <div class="wrap">
        <h1>Configurações Avançadas de Marca d'Água em Vídeos</h1>

        <div class="notice notice-info">
            <p><strong>Importante:</strong> Esta versão aprimorada do plugin permite controle adicional sobre como as marcas
                d'água são aplicadas, incluindo a modificação dos arquivos originais no servidor SFTP e geração automática
                de previews.</p>
        </div>

        <form method="post" action="">
            <?php wp_nonce_field('wsftp_save_enhanced_watermark', 'wsftp_enhanced_watermark_nonce'); ?>

            <table class="form-table">
                <tr valign="top">
                    <th scope="row">
                        <label for="wsftp_modify_originals">Modificar Arquivos Originais no SFTP</label>
                    </th>
                    <td>
                        <input type="checkbox" id="wsftp_modify_originals" name="wsftp_modify_originals" value="1" <?php checked($modify_originals, 1); ?> />
                        <p class="description">
                            <strong>Atenção:</strong> Quando ativado, os arquivos originais no servidor SFTP serão
                            substituídos por versões com marca d'água. Esta ação é irreversível!
                        </p>
                    </td>
                </tr>
                <tr valign="top">
                    <th scope="row">
                        <label for="wsftp_auto_generate_previews">Gerar Previews Automaticamente</label>
                    </th>
                    <td>
                        <input type="checkbox" id="wsftp_auto_generate_previews" name="wsftp_auto_generate_previews"
                            value="1" <?php checked($auto_generate_previews, 1); ?> />
                        <p class="description">
                            Quando ativado, imagens de preview serão geradas automaticamente a partir do vídeo para todos os
                            produtos.
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
                            Usar a imagem de preview gerada como imagem destacada do produto (recomendado).
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
                            Número de vídeos a serem processados em cada lote (recomendado: 5-10).
                        </p>
                    </td>
                </tr>
            </table>

            <p class="submit">
                <input type="submit" name="wsftp_save_enhanced_watermark" class="button-primary"
                    value="Salvar Configurações" />
            </p>

            <h2>Processamento de Vídeos Existentes</h2>
            <p>Use esta opção para aplicar marca d'água em vídeos de produtos já existentes:</p>

            <?php if ($batch_processing): ?>
                <div class="notice notice-info inline">
                    <p>Processamento em lote em andamento: <?php echo $batch_progress['processed']; ?> de
                        <?php echo $batch_progress['total']; ?> vídeos processados.
                    </p>
                    <div class="wsftp-progress-bar" style="height: 20px; width: 100%; background: #f0f0f1; margin-top: 10px;">
                        <div
                            style="height: 100%; width: <?php echo ($batch_progress['total'] > 0) ? ($batch_progress['processed'] / $batch_progress['total'] * 100) : 0; ?>%; background: #2271b1;">
                        </div>
                    </div>
                </div>
            <?php else: ?>
                <p class="submit">
                    <input type="submit" name="wsftp_process_existing" class="button-secondary"
                        value="Processar Vídeos Existentes"
                        onclick="return confirm('Esta ação irá processar todos os vídeos de produtos existentes. Continuar?');" />
                </p>
            <?php endif; ?>
        </form>
    </div>
    <?php
}

// Hook para processar vídeos existentes em lote
add_action('wsftp_process_existing_videos_batch', 'wsftp_enhanced_process_existing_videos_batch');

function wsftp_enhanced_process_existing_videos_batch()
{
    // Verificar se já está em processamento
    if (get_option('wsftp_batch_processing', false)) {
        return;
    }

    // Marcar como em processamento
    update_option('wsftp_batch_processing', true);

    // Obter todos os produtos criados pelo plugin
    $products = wc_get_products([
        'limit' => -1,
        'meta_key' => '_wsftp_product_key',
        'return' => 'ids',
    ]);

    // Salvar progresso inicial
    update_option('wsftp_batch_progress', array('total' => count($products), 'processed' => 0));

    // Agendar o primeiro lote
    wp_schedule_single_event(time() + 5, 'wsftp_enhanced_process_videos_batch', array($products, 0));

    if (function_exists('wsftp_add_log')) {
        wsftp_add_log("Processamento em lote agendado para " . count($products) . " produtos.");
    }
}

// Processar lote de vídeos
add_action('wsftp_enhanced_process_videos_batch', 'wsftp_enhanced_process_videos_batch', 10, 2);

function wsftp_enhanced_process_videos_batch($product_ids, $offset = 0)
{
    $batch_size = get_option('wsftp_batch_size', 5);
    $batch = array_slice($product_ids, $offset, $batch_size);

    if (empty($batch)) {
        // Processamento concluído
        update_option('wsftp_batch_processing', false);
        if (function_exists('wsftp_add_log')) {
            wsftp_add_log("Processamento em lote concluído.");
        }
        return;
    }

    foreach ($batch as $product_id) {
        wsftp_enhanced_process_existing_product_video($product_id);
    }

    // Atualizar progresso
    $progress = get_option('wsftp_batch_progress', array('total' => count($product_ids), 'processed' => 0));
    $progress['processed'] += count($batch);
    update_option('wsftp_batch_progress', $progress);

    // Agendar próximo lote
    wp_schedule_single_event(time() + 15, 'wsftp_enhanced_process_videos_batch', array($product_ids, $offset + $batch_size));

    if (function_exists('wsftp_add_log')) {
        wsftp_add_log("Lote de processamento concluído. Processados: " . $progress['processed'] . " de " . $progress['total']);
    }
}

// Função para processar vídeo de um produto existente
function wsftp_enhanced_process_existing_product_video($product_id)
{
    $product = wc_get_product($product_id);
    if (!$product)
        return false;

    // Verificar se é um produto com arquivo de vídeo
    $downloads = $product->get_downloads();
    if (empty($downloads))
        return false;

    foreach ($downloads as $download) {
        $file_url = $download->get_file();
        $file_name = $download->get_name();
        $file_extension = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));

        // Verificar se é um vídeo
        $video_extensions = array('mp4', 'avi', 'mov', 'wmv', 'flv', 'webm', 'mkv');
        if (!in_array($file_extension, $video_extensions))
            continue;

        // Encontrar o caminho local do arquivo
        $upload_dir = wp_upload_dir();
        $file_path = '';

        // Verificar se é uma URL local
        if (strpos($file_url, $upload_dir['baseurl']) === 0) {
            $file_path = str_replace($upload_dir['baseurl'], $upload_dir['basedir'], $file_url);
        } else {
            // Tentar encontrar no diretório de uploads do WooCommerce
            $woo_uploads = $upload_dir['basedir'] . '/woocommerce_uploads';
            $safe_filename = sanitize_file_name($file_name);
            $potential_path = $woo_uploads . '/' . $safe_filename;

            if (file_exists($potential_path)) {
                $file_path = $potential_path;
            }
        }

        if (!empty($file_path) && file_exists($file_path)) {
            // Processar o vídeo com marca d'água
            $watermarked_file = wsftp_enhanced_add_watermark_to_video($file_path, $file_name, $product_id);

            if ($watermarked_file) {
                // Atualizar o produto com o novo arquivo
                wsftp_enhanced_update_product_with_watermarked_video($product_id, $watermarked_file, $file_name, $download->get_id());

                // Gerar preview se necessário
                if (get_option('wsftp_auto_generate_previews', 1)) {
                    $preview_image = wsftp_enhanced_generate_video_preview($watermarked_file, $product_id);

                    if ($preview_image && get_option('wsftp_use_preview_as_featured', 1)) {
                        set_post_thumbnail($product_id, $preview_image);
                    }
                }

                if (function_exists('wsftp_add_log')) {
                    wsftp_add_log("Vídeo processado com sucesso para produto existente #$product_id: $file_name");
                }
                return true;
            }
        }
    }

    return false;
}

// Função para adicionar marca d'água aprimorada ao vídeo
function wsftp_enhanced_add_watermark_to_video($input_file, $file_name, $product_id)
{
    // Verificar se a função original existe
    if (!function_exists('wsftp_add_watermark_to_video')) {
        // Se a função original não existir, precisamos implementar uma versão própria
        // Primeiro verificamos se o FFmpeg está disponível
        $ffmpeg_path = wsftp_enhanced_find_ffmpeg();
        if (empty($ffmpeg_path)) {
            if (function_exists('wsftp_add_log')) {
                wsftp_add_log("FFmpeg não encontrado. Não é possível adicionar marca d'água ao vídeo.");
            }
            return false;
        }

        // Criar o arquivo de saída
        $upload_dir = wp_upload_dir();
        $downloads_dir = $upload_dir['basedir'] . '/woocommerce_uploads';
        $safe_filename = sanitize_file_name($file_name);

        // Garantir que o diretório existe
        if (!file_exists($downloads_dir)) {
            wp_mkdir_p($downloads_dir);
        }

        // Caminho para o arquivo com marca d'água
        $watermarked_file = $downloads_dir . '/enhanced_' . $safe_filename;

        // Verificar se temos uma logo
        $watermark_path = get_option('wsftp_watermark_path', '');
        if (empty($watermark_path) || !file_exists($watermark_path)) {
            // Se não tivermos logo, simplesmente copiamos o arquivo
            copy($input_file, $watermarked_file);
            return $watermarked_file;
        }

        // Posição da marca d'água
        $watermark_position = get_option('wsftp_watermark_position', 'bottom-right');
        $watermark_size = get_option('wsftp_watermark_size', 15);

        // Mapeamento de posições
        $position_map = array(
            'top-left' => '10:10',
            'top-right' => 'main_w-overlay_w-10:10',
            'bottom-left' => '10:main_h-overlay_h-10',
            'bottom-right' => 'main_w-overlay_w-10:main_h-overlay_h-10',
            'center' => '(main_w-overlay_w)/2:(main_h-overlay_h)/2'
        );

        $position = $position_map[$watermark_position] ?? $position_map['bottom-right'];

        // Preparar caminhos de arquivos
        $input_file_safe = str_replace('\\', '/', $input_file);
        $output_file_safe = str_replace('\\', '/', $watermarked_file);
        $watermark_path_safe = str_replace('\\', '/', $watermark_path);

        // Comando FFmpeg simplificado
        $command = "\"$ffmpeg_path\" -y -i \"$input_file_safe\" -i \"$watermark_path_safe\" " .
            "-filter_complex \"overlay=$position\" " .
            "-codec:a copy \"$output_file_safe\"";

        // Executar comando
        $output = array();
        $return_var = -1;

        if (function_exists('exec')) {
            @exec($command . " 2>&1", $output, $return_var);
        }

        if ($return_var !== 0) {
            if (function_exists('wsftp_add_log')) {
                wsftp_add_log("Erro ao adicionar marca d'água: " . implode("\n", $output));
            }
            return false;
        }

        return $watermarked_file;
    }

    // Se a função original existe, podemos usá-la
    // Criar o arquivo de saída
    $upload_dir = wp_upload_dir();
    $downloads_dir = $upload_dir['basedir'] . '/woocommerce_uploads';
    $safe_filename = sanitize_file_name($file_name);

    // Garantir que o diretório existe
    if (!file_exists($downloads_dir)) {
        wp_mkdir_p($downloads_dir);
    }

    // Caminho para o arquivo com marca d'água
    $watermarked_file = $downloads_dir . '/enhanced_' . $safe_filename;

    // Aplicar marca d'água usando a função original
    $result = wsftp_add_watermark_to_video($input_file, $watermarked_file);

    if ($result) {
        // Se for para modificar arquivos originais no SFTP
        if (get_option('wsftp_modify_originals', 0)) {
            // Adicionar à fila para modificação no SFTP
            wsftp_enhanced_schedule_sftp_file_replacement($product_id, $file_name, $watermarked_file);
        }

        return $watermarked_file;
    }

    return false;
}

// Função para atualizar o produto com o vídeo com marca d'água
function wsftp_enhanced_update_product_with_watermarked_video($product_id, $watermarked_file, $file_name, $download_id)
{
    $product = wc_get_product($product_id);
    if (!$product)
        return false;

    // Obter URL do arquivo
    $upload_dir = wp_upload_dir();
    $file_url = str_replace($upload_dir['basedir'], $upload_dir['baseurl'], $watermarked_file);

    // Atualizar download do produto
    $downloads = $product->get_downloads();
    if (isset($downloads[$download_id])) {
        $downloads[$download_id]->set_file($file_url);
        $product->set_downloads($downloads);
        $product->save();

        if (function_exists('wsftp_add_log')) {
            wsftp_add_log("Download atualizado para o produto #$product_id com arquivo com marca d'água.");
        }
        return true;
    }

    return false;
}

// Função para encontrar o FFmpeg
function wsftp_enhanced_find_ffmpeg()
{
    // Primeiro tenta obter o caminho salvo
    $ffmpeg_path = get_option('wsftp_ffmpeg_path', '');
    if (!empty($ffmpeg_path)) {
        return $ffmpeg_path;
    }

    // Se não temos um caminho salvo, tentamos encontrar
    $output = [];
    $return_var = -1;

    // Verificar se o comando está disponível de forma direta
    if (function_exists('exec')) {
        @exec("ffmpeg -version 2>&1", $output, $return_var);
        if ($return_var === 0) {
            update_option('wsftp_ffmpeg_path', 'ffmpeg');
            return 'ffmpeg';
        }

        // Testar caminhos comuns
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
                update_option('wsftp_ffmpeg_path', $path);
                return $path;
            }
        }
    }

    // Se ainda não encontrou, tente outras funções PHP
    if (function_exists('shell_exec')) {
        $result = @shell_exec('ffmpeg -version 2>&1');
        if (!empty($result) && strpos($result, 'ffmpeg version') !== false) {
            update_option('wsftp_ffmpeg_path', 'ffmpeg');
            return 'ffmpeg';
        }
    }

    return '';
}

// Função para gerar preview do vídeo
function wsftp_enhanced_generate_video_preview($video_file, $product_id)
{
    // Verificar se FFmpeg está disponível
    $ffmpeg_path = wsftp_enhanced_find_ffmpeg();
    if (empty($ffmpeg_path))
        return false;

    // Criar nome do arquivo de preview
    $upload_dir = wp_upload_dir();
    $preview_dir = $upload_dir['path']; // diretório atual de uploads
    $preview_file = $preview_dir . '/preview_' . $product_id . '.jpg';

    // Preparar comando FFmpeg para capturar um frame do meio do vídeo
    $video_file_safe = str_replace('\\', '/', $video_file);
    $preview_file_safe = str_replace('\\', '/', $preview_file);

    // Capturar frame do meio do vídeo com o FFmpeg
    $command = "\"$ffmpeg_path\" -y -i \"$video_file_safe\" -ss 00:00:05 -frames:v 1 \"$preview_file_safe\"";

    $output = array();
    $return_var = -1;

    if (function_exists('exec')) {
        @exec($command . " 2>&1", $output, $return_var);
    }

    if ($return_var !== 0) {
        if (function_exists('wsftp_add_log')) {
            wsftp_add_log("Erro ao gerar preview do vídeo: " . implode("\n", $output));
        }
        return false;
    }

    // Criar attachment para a imagem de preview
    if (file_exists($preview_file)) {
        $filetype = wp_check_filetype(basename($preview_file), null);

        $attachment = array(
            'guid' => $upload_dir['url'] . '/' . basename($preview_file),
            'post_mime_type' => $filetype['type'],
            'post_title' => preg_replace('/\.[^.]+$/', '', basename($preview_file)),
            'post_content' => '',
            'post_status' => 'inherit'
        );

        // Inserir attachment
        $attach_id = wp_insert_attachment($attachment, $preview_file, $product_id);

        // Gerar metadados
        require_once(ABSPATH . 'wp-admin/includes/image.php');
        $attach_data = wp_generate_attachment_metadata($attach_id, $preview_file);
        wp_update_attachment_metadata($attach_id, $attach_data);

        // Salvar ID do attachment como meta
        update_post_meta($product_id, '_wsftp_preview_image_id', $attach_id);

        // Se tiver ACF, adicionar ao campo de preview
        if (function_exists('update_field')) {
            $acf_field_name = get_option('wsftp_acf_preview_field', 'preview_file');
            update_field($acf_field_name, $attach_id, $product_id);
        }

        if (function_exists('wsftp_add_log')) {
            wsftp_add_log("Preview gerado com sucesso para o produto #$product_id");
        }

        return $attach_id;
    }

    return false;
}

// Agendar substituição de arquivo no SFTP
function wsftp_enhanced_schedule_sftp_file_replacement($product_id, $file_name, $watermarked_file)
{
    $replacements = get_option('wsftp_sftp_replacements', array());

    $replacements[] = array(
        'product_id' => $product_id,
        'file_name' => $file_name,
        'watermarked_file' => $watermarked_file,
        'scheduled_time' => time(),
        'status' => 'pending'
    );

    update_option('wsftp_sftp_replacements', $replacements);

    // Agendar processamento
    if (!wp_next_scheduled('wsftp_enhanced_process_sftp_replacements')) {
        wp_schedule_single_event(time() + 60, 'wsftp_enhanced_process_sftp_replacements');
    }

    if (function_exists('wsftp_add_log')) {
        wsftp_add_log("Agendada substituição do arquivo SFTP para o produto #$product_id: $file_name");
    }
}

// Processar substituições de arquivos no SFTP
add_action('wsftp_enhanced_process_sftp_replacements', 'wsftp_enhanced_process_sftp_replacements');

function wsftp_enhanced_process_sftp_replacements()
{
    $replacements = get_option('wsftp_sftp_replacements', array());

    if (empty($replacements)) {
        return;
    }

    // Conectar ao SFTP
    $connection = wsftp_connect_to_sftp();
    if (!$connection) {
        if (function_exists('wsftp_add_log')) {
            wsftp_add_log("Falha ao conectar ao servidor SFTP para substituição de arquivos.");
        }
        return;
    }

    $sftp = ssh2_sftp($connection);
    $base_path = get_option('wsftp_sftp_base_path', '/user_folders');

    $processed = 0;

    foreach ($replacements as $key => &$replacement) {
        if ($replacement['status'] !== 'pending') {
            continue;
        }

        // Limite de 5 arquivos por vez
        if ($processed >= 5) {
            break;
        }

        // Buscar informações do produto
        $product_id = $replacement['product_id'];
        $file_name = $replacement['file_name'];
        $watermarked_file = $replacement['watermarked_file'];

        // Encontrar o caminho SFTP do arquivo original
        $product = wc_get_product($product_id);
        if (!$product) {
            $replacement['status'] = 'error';
            $replacement['message'] = "Produto não encontrado";
            continue;
        }

        $user_id = get_post_meta($product_id, '_wsftp_user_id', true);
        $source_file = get_post_meta($product_id, '_wsftp_source_file', true);

        if (empty($user_id) || empty($source_file)) {
            $replacement['status'] = 'error';
            $replacement['message'] = "Informações de origem do arquivo não encontradas";
            continue;
        }

        // Encontrar a pasta do usuário
        $user_folder = false;
        $folder_mappings = wsftp_get_sftp_folders();

        foreach ($folder_mappings as $folder => $folder_user_id) {
            if ($folder_user_id == $user_id) {
                $user_folder = $folder;
                break;
            }
        }

        if (!$user_folder) {
            $replacement['status'] = 'error';
            $replacement['message'] = "Pasta do usuário não encontrada";
            continue;
        }

        // Caminho completo no SFTP
        $sftp_path = "$base_path/$user_folder/$source_file";

        // Fazer upload do arquivo com marca d'água
        $result = wsftp_enhanced_upload_file_to_sftp($sftp, $watermarked_file, $sftp_path);

        if ($result) {
            $replacement['status'] = 'completed';
            $replacement['completed_time'] = time();
            if (function_exists('wsftp_add_log')) {
                wsftp_add_log("Arquivo substituído com sucesso no SFTP: $sftp_path");
            }
        } else {
            $replacement['status'] = 'error';
            $replacement['message'] = "Falha ao fazer upload do arquivo para o SFTP";
            if (function_exists('wsftp_add_log')) {
                wsftp_add_log("Falha ao substituir o arquivo no SFTP: $sftp_path");
            }
        }

        $processed++;
    }

    // Atualizar lista de substituições
    update_option('wsftp_sftp_replacements', $replacements);

    // Verificar se ainda existem pendentes
    $pending = 0;
    foreach ($replacements as $replacement) {
        if ($replacement['status'] === 'pending') {
            $pending++;
        }
    }

    // Reagendar se ainda houver pendentes
    if ($pending > 0) {
        wp_schedule_single_event(time() + 120, 'wsftp_enhanced_process_sftp_replacements');
    }
}

// Compatibilidade com o log
if (!function_exists('wsftp_enhanced_add_log')) {
    function wsftp_enhanced_add_log($message)
    {
        if (function_exists('wsftp_add_log')) {
            wsftp_add_log($message);
        } else {
            // Log próprio para quando o plugin base não estiver disponível
            $log_entries = get_option('wsftp_enhanced_log', array());

            // Limitar a 100 entradas
            if (count($log_entries) >= 100) {
                array_shift($log_entries);
            }

            $log_entries[] = array(
                'time' => current_time('Y-m-d H:i:s'),
                'message' => $message
            );

            update_option('wsftp_enhanced_log', $log_entries);
        }
    }
}

// Upload de arquivo para o SFTP
function wsftp_enhanced_upload_file_to_sftp($sftp, $local_file, $remote_path)
{
    if (!file_exists($local_file)) {
        if (function_exists('wsftp_add_log')) {
            wsftp_add_log("Arquivo local não encontrado: $local_file");
        }
        return false;
    }

    $stream = @fopen("ssh2.sftp://$sftp$remote_path", 'w');
    if (!$stream) {
        if (function_exists('wsftp_add_log')) {
            wsftp_add_log("Falha ao abrir fluxo de escrita para o arquivo remoto: $remote_path");
        }
        return false;
    }

    $data = @file_get_contents($local_file);
    if ($data === false) {
        fclose($stream);
        if (function_exists('wsftp_add_log')) {
            wsftp_add_log("Falha ao ler arquivo local: $local_file");
        }
        return false;
    }

    $result = @fwrite($stream, $data);
    fclose($stream);

    if ($result === false || $result != strlen($data)) {
        if (function_exists('wsftp_add_log')) {
            wsftp_add_log("Falha ao escrever no arquivo remoto: $remote_path");
        }
        return false;
    }

    return true;
}

// Integração com o plugin original para processar novos produtos
add_action('wsftp_after_product_created', 'wsftp_enhanced_handle_new_product', 15, 3);

function wsftp_enhanced_handle_new_product($product_id, $file_path, $filename)
{
    // Verificar se é um vídeo
    $file_extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    $video_extensions = array('mp4', 'avi', 'mov', 'wmv', 'flv', 'webm', 'mkv');

    if (!in_array($file_extension, $video_extensions)) {
        return;
    }

    // Gerar preview se necessário
    if (get_option('wsftp_auto_generate_previews', 1)) {
        // Primeiro verificar se já temos uma versão com marca d'água
        $upload_dir = wp_upload_dir();
        $downloads_dir = $upload_dir['basedir'] . '/woocommerce_uploads';
        $safe_filename = sanitize_file_name($filename);
        $watermarked_file = $downloads_dir . '/watermarked_' . $safe_filename;

        if (!file_exists($watermarked_file)) {
            $watermarked_file = $file_path; // Usar o original se não tiver com marca d'água
        }

        // Gerar preview
        $preview_image = wsftp_enhanced_generate_video_preview($watermarked_file, $product_id);

        if ($preview_image && get_option('wsftp_use_preview_as_featured', 1)) {
            set_post_thumbnail($product_id, $preview_image);
        }
    }

    // Modificar arquivo original se necessário
    if (get_option('wsftp_modify_originals', 0)) {
        // Primeiro verificar se temos uma versão com marca d'água
        $upload_dir = wp_upload_dir();
        $downloads_dir = $upload_dir['basedir'] . '/woocommerce_uploads';
        $safe_filename = sanitize_file_name($filename);
        $watermarked_file = $downloads_dir . '/watermarked_' . $safe_filename;

        if (file_exists($watermarked_file)) {
            // Agendar para substituição no SFTP
            wsftp_enhanced_schedule_sftp_file_replacement($product_id, $filename, $watermarked_file);
        }
    }
}

// Adicionar hooks de ativação/desativação
register_activation_hook(__FILE__, 'wsftp_enhanced_watermark_activation');
register_deactivation_hook(__FILE__, 'wsftp_enhanced_watermark_deactivation');

function wsftp_enhanced_watermark_activation()
{
    // Inicializar opções
    if (get_option('wsftp_modify_originals') === false) {
        update_option('wsftp_modify_originals', 0);
    }

    if (get_option('wsftp_auto_generate_previews') === false) {
        update_option('wsftp_auto_generate_previews', 1);
    }

    if (get_option('wsftp_use_preview_as_featured') === false) {
        update_option('wsftp_use_preview_as_featured', 1);
    }

    if (get_option('wsftp_batch_size') === false) {
        update_option('wsftp_batch_size', 5);
    }

    // Resetar status de processamento
    update_option('wsftp_batch_processing', false);
    update_option('wsftp_batch_progress', array('total' => 0, 'processed' => 0));
}

function wsftp_enhanced_watermark_deactivation()
{
    // Limpar eventos agendados
    wp_clear_scheduled_hook('wsftp_enhanced_process_sftp_replacements');
    wp_clear_scheduled_hook('wsftp_process_existing_videos_batch');
    wp_clear_scheduled_hook('wsftp_enhanced_process_videos_batch');
}

// Registrar AJAX handlers
add_action('wp_ajax_wsftp_enhanced_status', 'wsftp_enhanced_ajax_status');

function wsftp_enhanced_ajax_status()
{
    check_ajax_referer('wsftp_enhanced_nonce', 'nonce');

    $response = array(
        'processing' => get_option('wsftp_batch_processing', false),
        'progress' => get_option('wsftp_batch_progress', array('total' => 0, 'processed' => 0))
    );

    wp_send_json_success($response);
}

// Adicionar inicialização para garantir que hooks sejam registrados
add_action('plugins_loaded', 'wsftp_enhanced_initialize');

function wsftp_enhanced_initialize()
{
    // Verificar se o plugin base está ativo
    $base_plugin_active = false;

    if (function_exists('wsftp_add_watermark_to_video')) {
        $base_plugin_active = true;
    }

    // Se não estiver ativo, precisamos registrar alguns hooks adicionais
    if (!$base_plugin_active) {
        add_action('admin_notices', function () {
            echo '<div class="notice notice-warning is-dismissible"><p>' .
                __('Enhanced Watermark plugin detected that the base SFTP Importer plugin is not loaded. Some features may be limited.', 'woo-sftp-importer') .
                '</p></div>';
        });

        // Aqui podemos adicionar hooks para funcionalidades básicas que seriam fornecidas pelo plugin base
    }
}
