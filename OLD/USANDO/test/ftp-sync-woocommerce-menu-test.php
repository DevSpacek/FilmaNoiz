<?php
/**
 * Plugin Name: FTP Sync Menu Test
 * Description: Versão simplificada para testar apenas o menu
 * Version: 1.0.0
 * Author: DevSpacek
 */

if (!defined('ABSPATH')) {
    exit;
}

// Função para adicionar o menu de forma direta
function ftp_sync_add_menu_test() {
    add_menu_page(
        'FTP Sync Test',
        'FTP Sync Test',
        'manage_options',
        'ftp-sync-test',
        'ftp_sync_render_test_page',
        'dashicons-admin-generic',
        30
    );
}

// Adicionar o hook com prioridade muito alta
add_action('admin_menu', 'ftp_sync_add_menu_test', 999);

// Função simples para renderizar a página
function ftp_sync_render_test_page() {
    echo '<div class="wrap">';
    echo '<h1>FTP Sync Test Page</h1>';
    echo '<p>O menu está funcionando corretamente!</p>';
    
    // Informações de diagnóstico
    echo '<h2>Informações de diagnóstico</h2>';
    echo '<pre>';
    echo 'Hora atual: ' . date('Y-m-d H:i:s') . "\n";
    echo 'Usuário: ' . wp_get_current_user()->user_login . "\n";
    echo 'Permissão manage_options: ' . (current_user_can('manage_options') ? 'Sim' : 'Não') . "\n";
    echo 'Plugins ativos: ' . implode(', ', get_option('active_plugins')) . "\n";
    echo '</pre>';
    echo '</div>';
}