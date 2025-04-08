<?php
/**
 * Template for Video Watermark Settings page
 */
// Impedir acesso direto a este arquivo
if (!defined('ABSPATH')) {
    exit;
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