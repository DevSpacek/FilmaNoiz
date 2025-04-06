<?php
/**
 * Admin class for managing the admin interface of the Woo SFTP Importer plugin.
 */
class Woo_SFTP_Importer_Admin {

    /**
     * Constructor for the admin class.
     */
    public function __construct() {
        // Load the admin styles and scripts
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_assets'));
        
        // Add admin menu
        add_action('admin_menu', array($this, 'add_admin_menu'));
    }

    /**
     * Enqueue admin styles and scripts.
     */
    public function enqueue_admin_assets() {
        wp_enqueue_style('woo-sftp-importer-admin-style', plugin_dir_url(__FILE__) . 'css/admin-style.css');
        wp_enqueue_script('woo-sftp-importer-admin-script', plugin_dir_url(__FILE__) . 'js/admin-script.js', array('jquery'), null, true);
    }

    /**
     * Add admin menu for the plugin.
     */
    public function add_admin_menu() {
        add_menu_page(
            __('SFTP Importer', 'woo-sftp-importer'),
            __('SFTP Importer', 'woo-sftp-importer'),
            'manage_options',
            'woo-sftp-importer',
            array($this, 'display_admin_page'),
            'dashicons-upload',
            6
        );
    }

    /**
     * Display the admin page.
     */
    public function display_admin_page() {
        include_once plugin_dir_path(__FILE__) . 'partials/admin-display.php';
    }
}
?>