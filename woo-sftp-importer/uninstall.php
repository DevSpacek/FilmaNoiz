<?php
/**
 * Uninstall the plugin and clean up any data.
 */

// If uninstall not called from WordPress, exit.
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

// Clean up options
delete_option('woo_sftp_importer_option_name');

// Remove any custom database tables if they were created
global $wpdb;
$table_name = $wpdb->prefix . 'your_custom_table_name'; // Replace with your actual table name
$wpdb->query("DROP TABLE IF EXISTS $table_name");

// Additional cleanup can be added here
?>