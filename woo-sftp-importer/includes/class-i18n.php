<?php
/**
 * Handles the internationalization functionality of the plugin.
 *
 * This class is responsible for loading the plugin's text domain for translation.
 *
 * @link       https://developer.wordpress.org/plugins/internationalization/
 * @since      1.0.0
 *
 * @package    Woo_SFTP_Importer
 * @subpackage Woo_SFTP_Importer/includes
 */

class Woo_SFTP_Importer_i18n {

    /**
     * Load the plugin text domain for translation.
     *
     * @since 1.0.0
     */
    public function load_plugin_textdomain() {
        load_plugin_textdomain(
            'woo-sftp-importer',
            false,
            dirname(plugin_basename(__FILE__)) . '/languages/'
        );
    }
}
?>