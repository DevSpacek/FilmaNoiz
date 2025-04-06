<?php
/**
 * Loader class for the Woo SFTP Importer plugin.
 *
 * This class is responsible for loading the necessary classes for the plugin.
 */

class Woo_SFTP_Importer_Loader {

    /**
     * Holds the classes to be loaded.
     *
     * @var array
     */
    protected $classes = [];

    /**
     * Constructor to initialize the loader.
     */
    public function __construct() {
        $this->classes = [
            'activator' => 'class-activator.php',
            'deactivator' => 'class-deactivator.php',
            'i18n' => 'class-i18n.php',
            'sftp_connection' => 'class-sftp-connection.php',
            'product_creator' => 'class-product-creator.php',
            'file_processor' => 'class-file-processor.php',
            'logger' => 'class-logger.php',
            'plugin' => 'class-plugin.php',
        ];
    }

    /**
     * Load the classes.
     */
    public function load_classes() {
        foreach ($this->classes as $class_name => $file_name) {
            $file_path = plugin_dir_path(__FILE__) . $file_name;
            if (file_exists($file_path)) {
                require_once $file_path;
            }
        }
    }
}
?>