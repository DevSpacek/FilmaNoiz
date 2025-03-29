<?php
// Este arquivo contém a página de administração do plugin de marca d'água via FTP.

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Evita acesso direto ao arquivo.
}

function watermark_ftp_plugin_admin_page() {
    ?>
    <div class="wrap">
        <h1><?php esc_html_e( 'Configurações do Plugin de Marca D\'Água', 'watermark-ftp-plugin' ); ?></h1>
        
        <form method="post" action="options.php">
            <?php
            settings_fields( 'watermark_ftp_plugin_options_group' );
            do_settings_sections( 'watermark_ftp_plugin' );
            submit_button();
            ?>
        </form>

        <h2><?php esc_html_e( 'Processar Imagens', 'watermark-ftp-plugin' ); ?></h2>
        <form method="post" action="">
            <input type="hidden" name="action" value="process_images">
            <?php submit_button( esc_html__( 'Adicionar Marca D\'Água', 'watermark-ftp-plugin' ) ); ?>
        </form>
    </div>
    <?php
}

function watermark_ftp_plugin_register_settings() {
    register_setting( 'watermark_ftp_plugin_options_group', 'ftp_credentials' );
    // Adicione outras configurações conforme necessário.
}

add_action( 'admin_menu', function() {
    add_menu_page(
        'Marca D\'Água FTP',
        'Marca D\'Água',
        'manage_options',
        'watermark-ftp-plugin',
        'watermark_ftp_plugin_admin_page'
    );
});

add_action( 'admin_init', 'watermark_ftp_plugin_register_settings' );
?>