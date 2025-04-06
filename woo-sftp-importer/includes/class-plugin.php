<?php
/**
 * The core plugin class.
 *
 * This is used to define the plugin's functionality and hooks.
 *
 * @link       https://example.com
 * @since      1.0.0
 *
 * @package    Woo_SFTP_Importer
 * @subpackage Woo_SFTP_Importer/includes
 */

class Woo_SFTP_Importer {

    /**
     * The single instance of the class.
     *
     * @var Woo_SFTP_Importer
     */
    protected static $instance = null;

    /**
     * Main Instance.
     *
     * Ensures only one instance of the class is loaded or can be loaded.
     *
     * @return Woo_SFTP_Importer - Main instance.
     */
    public static function instance() {
        if ( is_null( self::$instance ) ) {
            self::$instance = new self();
            self::$instance->init();
        }

        return self::$instance;
    }

    /**
     * Initialize the plugin.
     */
    public function init() {
        // Load necessary classes and hooks.
        $this->load_dependencies();
        $this->set_hooks();
    }

    /**
     * Load the required dependencies for this plugin.
     */
    private function load_dependencies() {
        require_once plugin_dir_path( __FILE__ ) . 'class-activator.php';
        require_once plugin_dir_path( __FILE__ ) . 'class-deactivator.php';
        require_once plugin_dir_path( __FILE__ ) . 'class-i18n.php';
        require_once plugin_dir_path( __FILE__ ) . 'class-loader.php';
        require_once plugin_dir_path( __FILE__ ) . 'class-sftp-connection.php';
        require_once plugin_dir_path( __FILE__ ) . 'class-product-creator.php';
        require_once plugin_dir_path( __FILE__ ) . 'class-file-processor.php';
        require_once plugin_dir_path( __FILE__ ) . 'class-logger.php';
    }

    /**
     * Set the hooks for the plugin.
     */
    private function set_hooks() {
        // Register activation and deactivation hooks.
        register_activation_hook( __FILE__, array( 'Woo_SFTP_Importer_Activator', 'activate' ) );
        register_deactivation_hook( __FILE__, array( 'Woo_SFTP_Importer_Deactivator', 'deactivate' ) );

        // Add other hooks as necessary.
    }
}

// Initialize the plugin.
Woo_SFTP_Importer::instance();
?>