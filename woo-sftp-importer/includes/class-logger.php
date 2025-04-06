<?php
/**
 * Logger class for the Woo SFTP Importer plugin.
 *
 * This class handles logging messages and errors for the plugin.
 */
class Logger {
    private $log_file;

    public function __construct() {
        $this->log_file = plugin_dir_path(__FILE__) . 'logs/plugin-log.txt';
        if (!file_exists($this->log_file)) {
            file_put_contents($this->log_file, '');
        }
    }

    /**
     * Log a message to the log file.
     *
     * @param string $message The message to log.
     */
    public function log($message) {
        $timestamp = current_time('Y-m-d H:i:s');
        $formatted_message = "[$timestamp] $message" . PHP_EOL;
        file_put_contents($this->log_file, $formatted_message, FILE_APPEND);
    }

    /**
     * Log an error message to the log file.
     *
     * @param string $error The error message to log.
     */
    public function log_error($error) {
        $this->log("ERROR: $error");
    }

    /**
     * Get the contents of the log file.
     *
     * @return string The contents of the log file.
     */
    public function get_log_contents() {
        return file_get_contents($this->log_file);
    }
}
?>