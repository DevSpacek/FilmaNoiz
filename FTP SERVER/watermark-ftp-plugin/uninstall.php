<?php
// Este arquivo é responsável por limpar as configurações e dados do plugin quando ele é desinstalado.

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

// Limpar opções do banco de dados
delete_option( 'watermark_ftp_plugin_options' );

// Se houver dados adicionais a serem removidos, adicione aqui
// Exemplo: delete_post_meta( $post_id, 'meta_key' );
?>