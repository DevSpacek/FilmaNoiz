<?php
/**
 * Plugin Name: Raspberry Pi Uploader
 * Description: API REST para upload de arquivos multipart a partir do Raspberry Pi
 * Version: 1.0.0
 * Author: DevSpacek
 */

// Prevenir acesso direto
if (!defined('ABSPATH')) {
    exit;
}

// Incluir classes e funções
require_once(plugin_dir_path(__FILE__) . 'includes/class-rpi-api.php');
require_once(plugin_dir_path(__FILE__) . 'includes/class-rpi-settings.php');

// Inicializar o plugin
function rpi_uploader_init() {
    // Iniciar API
    $api = new RPI_API();
    $api->init();
    
    // Iniciar página de configurações
    $settings = new RPI_Settings();
    $settings->init();
}
add_action('plugins_loaded', 'rpi_uploader_init');

// Ativação do plugin
register_activation_hook(__FILE__, 'rpi_uploader_activate');
function rpi_uploader_activate() {
    // Gerar chave API padrão na ativação
    if (!get_option('rpi_uploader_api_key')) {
        update_option('rpi_uploader_api_key', wp_generate_password(32, false));
    }
}