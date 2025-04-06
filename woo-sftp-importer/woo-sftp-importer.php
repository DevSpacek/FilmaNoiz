<?php
/**
 * Plugin Name: Woo SFTP Importer
 * Description: A plugin to import products via SFTP into WooCommerce.
 * Version: 1.0.0
 * Author: DevSpacek
 * Author URI: https://yourwebsite.com
 * License: GPL2
 */

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Define plugin path
define( 'WOO_SFTP_IMPORTER_PATH', plugin_dir_path( __FILE__ ) );

// Include necessary files
require_once WOO_SFTP_IMPORTER_PATH . 'includes/class-loader.php';
require_once WOO_SFTP_IMPORTER_PATH . 'includes/class-activator.php';
require_once WOO_SFTP_IMPORTER_PATH . 'includes/class-deactivator.php';
require_once WOO_SFTP_IMPORTER_PATH . 'includes/class-i18n.php';
require_once WOO_SFTP_IMPORTER_PATH . 'includes/class-sftp-connection.php';
require_once WOO_SFTP_IMPORTER_PATH . 'includes/class-product-creator.php';
require_once WOO_SFTP_IMPORTER_PATH . 'includes/class-file-processor.php';
require_once WOO_SFTP_IMPORTER_PATH . 'includes/class-logger.php';
require_once WOO_SFTP_IMPORTER_PATH . 'includes/class-plugin.php';

// Activation and deactivation hooks
register_activation_hook( __FILE__, array( 'WooSFTPImporter\Activator', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'WooSFTPImporter\Deactivator', 'deactivate' ) );

// Initialize the plugin
add_action( 'plugins_loaded', function() {
    $plugin = new WooSFTPImporter\Plugin();
    $plugin->run();
} );
?>