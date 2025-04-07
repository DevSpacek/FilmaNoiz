<?php
/**
 * Plugin Name: WooCommerce SFTP Folder Importer (Create Once)
 * Plugin URI: https://yourwebsite.com/
 * Description: Import products from SFTP folders only once and never update them
 * Version: 1.2.0
 * Author: DevSpacek
 * Text Domain: woo-sftp-importer
 * Requires at least: 5.0
 * Requires PHP: 7.2
 * WC requires at least: 3.0
 * WC tested up to: 8.0
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

// Check if WooCommerce is active
if (!in_array('woocommerce/woocommerce.php', apply_filters('active_plugins', get_option('active_plugins')))) {
    add_action('admin_notices', function() {
        echo '<div class="error"><p>' . __('WooCommerce SFTP Folder Importer requires WooCommerce to be installed and active.', 'woo-sftp-importer') . '</p></div>';
    });
    return;
}

// Check if PHP SSH2 extension is available
if (!extension_loaded('ssh2')) {
    add_action('admin_notices', function() {
        echo '<div class="error"><p>' . __('WooCommerce SFTP Folder Importer requires PHP SSH2 extension. Please contact your hosting provider to enable it.', 'woo-sftp-importer') . '</p></div>';
    });
}

// Define constants
define('WSFTP_VERSION', '1.2.0');
define('WSFTP_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('WSFTP_PLUGIN_URL', plugin_dir_url(__FILE__));

// Add admin menu
add_action('admin_menu', 'wsftp_add_admin_menu', 10);

function wsftp_add_admin_menu() {
    add_menu_page(
        'WooCommerce SFTP Importer',
        'SFTP Importer',
        'manage_options',
        'woo-sftp-importer',
        'wsftp_admin_page',
        'dashicons-upload',
        30
    );
}

// Create admin page
function wsftp_admin_page() {
    // Save settings
    if (isset($_POST['wsftp_save_settings']) && wp_verify_nonce($_POST['wsftp_nonce'], 'wsftp_save_settings')) {
        // SFTP Connection Settings
        $sftp_host = sanitize_text_field($_POST['wsftp_sftp_host']);
        update_option('wsftp_sftp_host', $sftp_host);
        
        $sftp_port = absint($_POST['wsftp_sftp_port']);
        if ($sftp_port < 1) $sftp_port = 22;
        update_option('wsftp_sftp_port', $sftp_port);
        
        $sftp_username = sanitize_text_field($_POST['wsftp_sftp_username']);
        update_option('wsftp_sftp_username', $sftp_username);
        
        // Only update password if provided (to avoid clearing it when editing settings)
        if (!empty($_POST['wsftp_sftp_password'])) {
            // Not using wp_hash_password as it's for user passwords specifically
            // Instead, store it with some basic encryption
            update_option('wsftp_sftp_password', wsftp_encrypt_decrypt($_POST['wsftp_sftp_password'], 'encrypt'));
        }
        
        $sftp_auth_method = sanitize_text_field($_POST['wsftp_sftp_auth_method']);
        update_option('wsftp_sftp_auth_method', $sftp_auth_method);
        
        $sftp_private_key_path = sanitize_text_field($_POST['wsftp_sftp_private_key_path']);
        update_option('wsftp_sftp_private_key_path', $sftp_private_key_path);
        
        $sftp_base_path = sanitize_text_field($_POST['wsftp_sftp_base_path']);
        update_option('wsftp_sftp_base_path', $sftp_base_path);
        
        // Product Settings
        $scan_interval = absint($_POST['wsftp_scan_interval']);
        if ($scan_interval < 1) $scan_interval = 1;
        update_option('wsftp_scan_interval', $scan_interval);
        
        $default_price = floatval($_POST['wsftp_default_price']);
        update_option('wsftp_default_price', $default_price);
        
        $product_status = sanitize_text_field($_POST['wsftp_product_status']);
        update_option('wsftp_product_status', $product_status);
        
        $log_enabled = isset($_POST['wsftp_log_enabled']) ? 1 : 0;
        update_option('wsftp_log_enabled', $log_enabled);
        
        $remove_deleted_files = isset($_POST['wsftp_remove_deleted_files']) ? 1 : 0;
        update_option('wsftp_remove_deleted_files', $remove_deleted_files);
        
        // Campo ACF para armazenar a pré-visualização
        $acf_field_group = sanitize_text_field($_POST['wsftp_acf_field_group']);
        update_option('wsftp_acf_field_group', $acf_field_group);
        
        $acf_preview_field = sanitize_text_field($_POST['wsftp_acf_preview_field']);
        update_option('wsftp_acf_preview_field', $acf_preview_field);
        
        // Opção para usar o mesmo arquivo como preview
        $use_same_file = isset($_POST['wsftp_use_same_file']) ? 1 : 0;
        update_option('wsftp_use_same_file', $use_same_file);
        
        echo '<div class="notice notice-success is-dismissible"><p>Settings saved successfully.</p></div>';
    }
    
    // Test SFTP Connection
    if (isset($_POST['wsftp_test_connection']) && wp_verify_nonce($_POST['wsftp_nonce'], 'wsftp_save_settings')) {
        $connection_result = wsftp_test_connection();
        if ($connection_result === true) {
            echo '<div class="notice notice-success is-dismissible"><p>SFTP connection successful! Connection verified.</p></div>';
        } else {
            echo '<div class="notice notice-error is-dismissible"><p>SFTP connection failed: ' . esc_html($connection_result) . '</p></div>';
        }
    }
    
    // Reset database if requested
    if (isset($_POST['wsftp_reset_db']) && wp_verify_nonce($_POST['wsftp_reset_nonce'], 'wsftp_reset_db')) {
        delete_option('wsftp_processed_files');
        delete_option('wsftp_last_scan_time');
        echo '<div class="notice notice-success is-dismissible"><p>Database reset successfully. All files will be treated as new in the next scan.</p></div>';
    }
    
    // Clear scanning lock if requested
    if (isset($_POST['wsftp_clear_lock']) && wp_verify_nonce($_POST['wsftp_reset_nonce'], 'wsftp_reset_db')) {
        delete_transient('wsftp_scanning_lock');
        wsftp_add_log("Scanning lock was manually cleared by admin.");
        echo '<div class="notice notice-success is-dismissible"><p>Scanning lock cleared successfully. You can now run a scan again.</p></div>';
    }
    
    // Diagnose and fix ACF preview files
    if (isset($_POST['wsftp_fix_acf_previews']) && wp_verify_nonce($_POST['wsftp_reset_nonce'], 'wsftp_reset_db')) {
        $fixed = wsftp_diagnose_and_fix_acf_previews();
        echo '<div class="notice notice-success is-dismissible"><p>ACF Preview diagnosis completed. ' . $fixed . ' product previews fixed/updated.</p></div>';
    }
    
    // Get current settings
    $sftp_host = get_option('wsftp_sftp_host', '');
    $sftp_port = get_option('wsftp_sftp_port', 22);
    $sftp_username = get_option('wsftp_sftp_username', '');
    $sftp_password = get_option('wsftp_sftp_password', ''); // Será descriptografado antes de usar
    $sftp_auth_method = get_option('wsftp_sftp_auth_method', 'password');
    $sftp_private_key_path = get_option('wsftp_sftp_private_key_path', '');
    $sftp_base_path = get_option('wsftp_sftp_base_path', '/user_folders');
    
    $scan_interval = get_option('wsftp_scan_interval', 1);
    $default_price = get_option('wsftp_default_price', 9.99);
    $product_status = get_option('wsftp_product_status', 'draft');
    $log_enabled = get_option('wsftp_log_enabled', 1);
    $remove_deleted_files = get_option('wsftp_remove_deleted_files', 1);
    $acf_field_group = get_option('wsftp_acf_field_group', 'product_details');
    $acf_preview_field = get_option('wsftp_acf_preview_field', 'preview_file');
    $use_same_file = get_option('wsftp_use_same_file', 1);
    
    // Manual scan trigger
    if (isset($_POST['wsftp_manual_scan']) && wp_verify_nonce($_POST['wsftp_nonce'], 'wsftp_manual_scan')) {
        wsftp_scan_folders();
        echo '<div class="notice notice-success is-dismissible"><p>Manual scan completed. Check the log for details.</p></div>';
    }
    
    // Get last scan time
    $last_scan = get_option('wsftp_last_scan_time', 0);
    $last_scan_date = ($last_scan > 0) ? date_i18n('Y-m-d H:i:s', $last_scan) : 'Never';
    $next_scan = ($last_scan > 0) ? date_i18n('Y-m-d H:i:s', $last_scan + ($scan_interval * 60)) : 'After first page load';
    
    // Get processed files count
    $processed_files = get_option('wsftp_processed_files', array());
    $processed_count = count($processed_files);
    
    // Display the form
    ?>
    <div class="wrap">
        <h1>WooCommerce SFTP Folder Importer (Create Once Mode) v1.2.0</h1>
        
        <div class="notice notice-info">
            <p><strong>Create Once Mode:</strong> In this mode, the plugin will only create products for files it has never seen before. 
            It will not update existing products even if the files change.</p>
        </div>
        
        <h2>SFTP Connection Settings</h2>
        <form method="post" action="">
            <?php wp_nonce_field('wsftp_save_settings', 'wsftp_nonce'); ?>
            
            <table class="form-table">
                <tr valign="top">
                    <th scope="row">
                        <label for="wsftp_sftp_host">SFTP Host</label>
                    </th>
                    <td>
                        <input type="text" id="wsftp_sftp_host" name="wsftp_sftp_host" class="regular-text" 
                            value="<?php echo esc_attr($sftp_host); ?>" placeholder="example.com or 192.168.1.1" />
                        <p class="description">
                            Enter the SFTP server hostname or IP address
                        </p>
                    </td>
                </tr>
                <tr valign="top">
                    <th scope="row">
                        <label for="wsftp_sftp_port">SFTP Port</label>
                    </th>
                    <td>
                        <input type="number" id="wsftp_sftp_port" name="wsftp_sftp_port" min="1" max="65535" 
                            value="<?php echo esc_attr($sftp_port); ?>" placeholder="22" />
                        <p class="description">
                            Default SFTP port is 22
                        </p>
                    </td>
                </tr>
                <tr valign="top">
                    <th scope="row">
                        <label for="wsftp_sftp_username">SFTP Username</label>
                    </th>
                    <td>
                        <input type="text" id="wsftp_sftp_username" name="wsftp_sftp_username" class="regular-text" 
                            value="<?php echo esc_attr($sftp_username); ?>" />
                    </td>
                </tr>
                <tr valign="top">
                    <th scope="row">
                        <label for="wsftp_sftp_auth_method">Authentication Method</label>
                    </th>
                    <td>
                        <select id="wsftp_sftp_auth_method" name="wsftp_sftp_auth_method">
                            <option value="password" <?php selected($sftp_auth_method, 'password'); ?>>Password</option>
                            <option value="key" <?php selected($sftp_auth_method, 'key'); ?>>Private Key</option>
                        </select>
                    </td>
                </tr>
                <tr valign="top" class="auth-password">
                    <th scope="row">
                        <label for="wsftp_sftp_password">SFTP Password</label>
                    </th>
                    <td>
                        <input type="password" id="wsftp_sftp_password" name="wsftp_sftp_password" class="regular-text" 
                            value="" placeholder="<?php echo !empty($sftp_password) ? '******' : ''; ?>" />
                        <p class="description">
                            Leave blank to keep existing password
                        </p>
                    </td>
                </tr>
                <tr valign="top" class="auth-key">
                    <th scope="row">
                        <label for="wsftp_sftp_private_key_path">Private Key Path</label>
                    </th>
                    <td>
                        <input type="text" id="wsftp_sftp_private_key_path" name="wsftp_sftp_private_key_path" class="regular-text" 
                            value="<?php echo esc_attr($sftp_private_key_path); ?>" placeholder="/path/to/id_rsa" />
                        <p class="description">
                            Full server path to private key file (must be readable by web server)
                        </p>
                    </td>
                </tr>
                <tr valign="top">
                    <th scope="row">
                        <label for="wsftp_sftp_base_path">SFTP Base Folder Path</label>
                    </th>
                    <td>
                        <input type="text" id="wsftp_sftp_base_path" name="wsftp_sftp_base_path" class="regular-text" 
                            value="<?php echo esc_attr($sftp_base_path); ?>" placeholder="/user_folders" />
                        <p class="description">
                            Path on the SFTP server to the folder containing user folders (e.g., /user_folders)
                        </p>
                    </td>
                </tr>
            </table>
            
            <p class="submit">
                <input type="submit" name="wsftp_test_connection" class="button-secondary" value="Test SFTP Connection" />
            </p>
            
            <h2>Product Settings</h2>
            <table class="form-table">
                <tr valign="top">
                    <th scope="row">
                        <label for="wsftp_use_same_file">Use Same File for Preview</label>
                    </th>
                    <td>
                        <input type="checkbox" id="wsftp_use_same_file" name="wsftp_use_same_file" value="1" <?php checked($use_same_file, 1); ?> />
                        <p class="description">
                            When enabled, the plugin will use the same file for both the product and preview in ACF
                        </p>
                    </td>
                </tr>
                <tr valign="top">
                    <th scope="row">
                        <label for="wsftp_acf_field_group">ACF Field Group Name</label>
                    </th>
                    <td>
                        <input type="text" id="wsftp_acf_field_group" name="wsftp_acf_field_group" class="regular-text" 
                            value="<?php echo esc_attr($acf_field_group); ?>" />
                        <p class="description">
                            Enter the ACF field group name that contains the preview file field
                        </p>
                    </td>
                </tr>
                <tr valign="top">
                    <th scope="row">
                        <label for="wsftp_acf_preview_field">ACF Preview Field Name</label>
                    </th>
                    <td>
                        <input type="text" id="wsftp_acf_preview_field" name="wsftp_acf_preview_field" class="regular-text" 
                            value="<?php echo esc_attr($acf_preview_field); ?>" />
                        <p class="description">
                            Enter the ACF field name that will store preview files (must be a file field type)
                        </p>
                    </td>
                </tr>
                <tr valign="top">
                    <th scope="row">
                        <label for="wsftp_scan_interval">Scan Interval (minutes)</label>
                    </th>
                    <td>
                        <input type="number" id="wsftp_scan_interval" name="wsftp_scan_interval" min="1" 
                            value="<?php echo esc_attr($scan_interval); ?>" />
                    </td>
                </tr>
                <tr valign="top">
                    <th scope="row">
                        <label for="wsftp_default_price">Default Product Price</label>
                    </th>
                    <td>
                        <input type="number" id="wsftp_default_price" name="wsftp_default_price" min="0" step="0.01" 
                            value="<?php echo esc_attr($default_price); ?>" />
                        <p class="description">
                            Default price for new products when no price information is available
                        </p>
                    </td>
                </tr>
                <tr valign="top">
                    <th scope="row">
                        <label for="wsftp_product_status">Product Status</label>
                    </th>
                    <td>
                        <select id="wsftp_product_status" name="wsftp_product_status">
                            <option value="publish" <?php selected($product_status, 'publish'); ?>>Published</option>
                            <option value="draft" <?php selected($product_status, 'draft'); ?>>Draft</option>
                            <option value="pending" <?php selected($product_status, 'pending'); ?>>Pending Review</option>
                            <option value="private" <?php selected($product_status, 'private'); ?>>Private</option>
                        </select>
                        <p class="description">
                            Default status for newly created products
                        </p>
                    </td>
                </tr>
                <tr valign="top">
                    <th scope="row">
                        <label for="wsftp_remove_deleted_files">Remove Deleted Products</label>
                    </th>
                    <td>
                        <input type="checkbox" id="wsftp_remove_deleted_files" name="wsftp_remove_deleted_files" value="1" <?php checked($remove_deleted_files, 1); ?> />
                        <p class="description">
                            Automatically delete products when their source files are removed from SFTP
                        </p>
                    </td>
                </tr>
                <tr valign="top">
                    <th scope="row">
                        <label for="wsftp_log_enabled">Enable Logging</label>
                    </th>
                    <td>
                        <input type="checkbox" id="wsftp_log_enabled" name="wsftp_log_enabled" value="1" <?php checked($log_enabled, 1); ?> />
                        <p class="description">
                            Log import activities for debugging
                        </p>
                    </td>
                </tr>
            </table>
            
            <p class="submit">
                <input type="submit" name="wsftp_save_settings" class="button-primary" value="Save Settings" />
            </p>
        </form>
        
        <div class="wsftp-scan-status" style="margin-bottom: 20px; padding: 15px; background: #f0f0f1; border-left: 4px solid #2271b1;" data-last-scan="<?php echo esc_attr($last_scan); ?>">
            <h3 style="margin-top: 0;">Automatic Scanning Status</h3>
            <p><strong>Last scan:</strong> <?php echo esc_html($last_scan_date); ?></p>
            <p><strong>Next scan:</strong> <?php echo esc_html($next_scan); ?></p>
            <p><strong>Files already processed:</strong> <?php echo esc_html($processed_count); ?> (these will not create new products)</p>
            <p>The scanner will automatically run once every <?php echo esc_html($scan_interval); ?> minute(s) when your site receives visitors.</p>
        </div>
        
        <div class="wsftp-actions" style="display: flex; gap: 10px; margin-bottom: 20px;">
            <form method="post" action="">
                <?php wp_nonce_field('wsftp_manual_scan', 'wsftp_nonce'); ?>
                <input type="submit" name="wsftp_manual_scan" class="button-secondary" value="Run Manual Scan Now" />
            </form>
            
            <form method="post" action="">
                <?php wp_nonce_field('wsftp_reset_db', 'wsftp_reset_nonce'); ?>
                <input type="submit" name="wsftp_reset_db" class="button-secondary" value="Reset Database" 
                    onclick="return confirm('Are you sure? This will cause ALL files to be treated as new and potentially create duplicate products.');" />
                
                <input type="submit" name="wsftp_clear_lock" class="button-secondary" value="Clear Scanning Lock" 
                    style="background-color:#ffaa00;border-color:#ff8800;color:#fff;" 
                    title="Use this if scans are not running and you see 'Scan already running' messages in the log" />
                
                <input type="submit" name="wsftp_fix_acf_previews" class="button-secondary" value="Fix ACF Preview Files" 
                    style="background-color:#0073aa;border-color:#005a87;color:#fff;" 
                    title="Use this to diagnose and fix preview files that are not showing in the ACF interface" />
            </form>
        </div>
        
        <h2>Import Log</h2>
        <div class="wsftp-log-container" style="max-height: 300px; overflow-y: auto; border: 1px solid #ccc; padding: 10px; background: #f9f9f9;">
            <?php
            $log_entries = get_option('wsftp_import_log', array());
            if (empty($log_entries)) {
                echo '<p>No import logs available.</p>';
            } else {
                echo '<ul style="margin-top: 0;">';
                foreach (array_reverse($log_entries) as $log) {
                    echo '<li><strong>[' . esc_html($log['time']) . ']</strong> ' . esc_html($log['message']) . '</li>';
                }
                echo '</ul>';
                
                // Add clear log button
                ?>
                <form method="post" action="">
                    <?php wp_nonce_field('wsftp_clear_log', 'wsftp_nonce'); ?>
                    <input type="submit" name="wsftp_clear_log" class="button-secondary" value="Clear Log" />
                </form>
                <?php
            }
            
            // Clear log action
            if (isset($_POST['wsftp_clear_log']) && wp_verify_nonce($_POST['wsftp_nonce'], 'wsftp_clear_log')) {
                delete_option('wsftp_import_log');
                echo '<script>location.reload();</script>';
            }
            ?>
        </div>
    </div>
    
    <script type="text/javascript">
    jQuery(document).ready(function($) {
        // Toggle authentication method fields
        function toggleAuthFields() {
            var method = $('#wsftp_sftp_auth_method').val();
            if (method === 'password') {
                $('.auth-password').show();
                $('.auth-key').hide();
            } else {
                $('.auth-password').hide();
                $('.auth-key').show();
            }
        }
        
        $('#wsftp_sftp_auth_method').on('change', toggleAuthFields);
        toggleAuthFields();
    });
    </script>
    <?php
}

/**
 * Add log entry
 */
function wsftp_add_log($message) {
    if (get_option('wsftp_log_enabled', 1)) {
        $log_entries = get_option('wsftp_import_log', array());
        
        // Limit to last 100 entries
        if (count($log_entries) >= 100) {
            array_shift($log_entries);
        }
        
        $log_entries[] = array(
            'time' => current_time('Y-m-d H:i:s'),
            'message' => $message
        );
        
        update_option('wsftp_import_log', $log_entries);
    }
}

/**
 * Simple encrypt/decrypt function for storing passwords
 */
function wsftp_encrypt_decrypt($string, $action = 'encrypt') {
    $encrypt_method = "AES-256-CBC";
    $secret_key = AUTH_KEY ?? 'wsftp-default-key';  // Uses WordPress AUTH_KEY if available
    $secret_iv = SECURE_AUTH_KEY ?? 'wsftp-default-iv';  // Uses WordPress SECURE_AUTH_KEY if available
    
    $key = hash('sha256', $secret_key);
    $iv = substr(hash('sha256', $secret_iv), 0, 16);
    
    if ($action == 'encrypt') {
        $output = base64_encode(openssl_encrypt($string, $encrypt_method, $key, 0, $iv));
    } else {
        $output = openssl_decrypt(base64_decode($string), $encrypt_method, $key, 0, $iv);
    }
    
    return $output;
}

/**
 * Test SFTP Connection
 */
function wsftp_test_connection() {
    if (!extension_loaded('ssh2')) {
        return 'The PHP SSH2 extension is not available on your server. Please contact your hosting provider.';
    }
    
    $host = get_option('wsftp_sftp_host', '');
    $port = get_option('wsftp_sftp_port', 22);
    $username = get_option('wsftp_sftp_username', '');
    $auth_method = get_option('wsftp_sftp_auth_method', 'password');
    
    if (empty($host) || empty($username)) {
        return 'Host and username are required.';
    }
    
    try {
        // Attempt to connect
        $connection = @ssh2_connect($host, $port);
        if (!$connection) {
            return "Failed to connect to $host on port $port";
        }
        
        // Authenticate
        $auth_success = false;
        
        if ($auth_method === 'password') {
            $password = wsftp_encrypt_decrypt(get_option('wsftp_sftp_password', ''), 'decrypt');
            if (empty($password)) {
                return 'Password is required for password authentication.';
            }
            
            $auth_success = @ssh2_auth_password($connection, $username, $password);
        } else {
            $key_path = get_option('wsftp_sftp_private_key_path', '');
            if (empty($key_path) || !file_exists($key_path)) {
                return "Private key file not found at: $key_path";
            }
            
            $auth_success = @ssh2_auth_pubkey_file(
                $connection, 
                $username, 
                $key_path . '.pub',  // Public key
                $key_path            // Private key
            );
        }
        
        if (!$auth_success) {
            return 'Authentication failed. Please check your credentials.';
        }
        
        // Initialize SFTP subsystem
        $sftp = @ssh2_sftp($connection);
        if (!$sftp) {
            return 'Failed to initialize SFTP subsystem.';
        }
        
        // Test if base path exists
        $base_path = get_option('wsftp_sftp_base_path', '/user_folders');
        $dir = "ssh2.sftp://$sftp$base_path";
        
        if (!file_exists($dir)) {
            return "The base path '$base_path' does not exist on the server.";
        }
        
        // All tests passed
        return true;
    } catch (Exception $e) {
        return 'Error: ' . $e->getMessage();
    }
}

/**
 * Main function to scan folders and create products (only for new files)
 */
function wsftp_scan_folders() {
    // Set a lock to prevent duplicate processing
    $lock_transient = 'wsftp_scanning_lock';
    
    // Check for an old lock (over 10 minutes)
    $lock_time = get_transient('wsftp_scanning_lock_time');
    if ($lock_time && (time() - $lock_time > 600)) {
        // Force release if lock is too old
        delete_transient($lock_transient);
        delete_transient('wsftp_scanning_lock_time');
        wsftp_add_log("Expired scanning lock detected and cleared (lock was older than 10 minutes)");
    }
    
    if (get_transient($lock_transient)) {
        wsftp_add_log("Scan already running. Skipping this execution.");
        return;
    }
    
    // Set lock for 5 minutes max and record the time
    set_transient($lock_transient, true, 5 * MINUTE_IN_SECONDS);
    set_transient('wsftp_scanning_lock_time', time(), 30 * MINUTE_IN_SECONDS);
    
    try {
        // Update last scan time
        update_option('wsftp_last_scan_time', time());
        
        // Set a higher time limit to prevent timeout on large folders
        @set_time_limit(300);
        
        // Establish SFTP connection
        $connection = wsftp_connect_to_sftp();
        if (!$connection) {
            wsftp_add_log("Failed to connect to SFTP server. Check your credentials.");
            delete_transient($lock_transient);
            return;
        }
        
        $sftp = ssh2_sftp($connection);
        $base_path = get_option('wsftp_sftp_base_path', '/user_folders');
        $remove_deleted = get_option('wsftp_remove_deleted_files', 1);
        $use_same_file = get_option('wsftp_use_same_file', 1);
        
        // Check if base path exists
        $dir = "ssh2.sftp://$sftp$base_path";
        if (!file_exists($dir)) {
            wsftp_add_log("Base folder not found on SFTP server: $base_path");
            delete_transient($lock_transient);
            return;
        }
        
        wsftp_add_log("Starting folder scan in SFTP path: $base_path");
        
        // Get already processed files
        $processed_files = get_option('wsftp_processed_files', array());
        
        // Current files for deletion tracking
        $current_files = array();
        
        // Get folder to user mappings
        $folder_mappings = wsftp_get_folder_user_mappings($sftp, $base_path);
        
        $total_created = 0;
        $total_skipped = 0;
        
        // Create a temporary directory to download files
        $temp_dir = wsftp_create_composer_file();
        if (!$temp_dir) {
            wsftp_add_log("Failed to create temporary directory for downloads.");
            delete_transient($lock_transient);
            return;
        }
        
        // Process each user folder
        foreach ($folder_mappings as $folder_name => $user_id) {
            $user_folder_path = "$base_path/$folder_name";
            
            wsftp_add_log("Scanning folder for user #{$user_id}: $folder_name");
            
            // Get all files within this user's folder
            $files = wsftp_list_directory($sftp, $user_folder_path);
            
            foreach ($files as $file) {
                if ($file === '.' || $file === '..' || wsftp_is_dir($sftp, "$user_folder_path/$file")) {
                    continue;
                }
                
                // Generate the unique key for this product
                $product_key = sanitize_title($user_id . '-' . $file);
                
                // Track this file for deletion checking
                $current_files[$product_key] = "$user_folder_path/$file";
                
                // Check if we've already processed this file before
                if (isset($processed_files[$product_key])) {
                    // We've already created a product for this file - SKIP IT COMPLETELY
                    $total_skipped++;
                    continue;
                }
                
                // Download the file to temporary location
                $local_file_path = $temp_dir . '/' . $file;
                if (!wsftp_download_file($sftp, "$user_folder_path/$file", $local_file_path)) {
                    wsftp_add_log("Failed to download file: $file");
                    continue;
                }
                
                // This is a NEW file - create a product for it
                $product_id = wsftp_create_product($file, $local_file_path, $user_id, $product_key);
                
                if ($product_id > 0) {
                    $total_created++;
                    wsftp_add_log("Created new product from file: $file (user: $user_id)");
                    
                    // Add to processed files to avoid future processing
                    $processed_files[$product_key] = time();
                    
                    // If we're using the same file for preview, attach it now
                    if ($use_same_file) {
                        $attach_result = wsftp_attach_preview_to_acf($product_id, $local_file_path, get_option('wsftp_acf_preview_field', 'preview_file'));
                        if ($attach_result) {
                            wsftp_add_log("Used same file as ACF preview for product #$product_id: $file");
                        }
                    }
                } else {
                    wsftp_add_log("Error processing file: $file (user: $user_id)");
                }
                
                // Delete the temporary file
                @unlink($local_file_path);
            }
        }
        
        // Update processed files list
        update_option('wsftp_processed_files', $processed_files);
        
        // Check for deleted files and remove corresponding products
        if ($remove_deleted) {
            $deleted_count = wsftp_remove_deleted_products($current_files, $processed_files);
            
            if ($deleted_count > 0) {
                wsftp_add_log("Removed $deleted_count products for deleted files");
            }
        }
        
        // Clean up temporary directory
        wsftp_cleanup_temp_directory($temp_dir);
        
        wsftp_add_log("Scan completed. Created: $total_created, Skipped: $total_skipped products");
    } catch (Exception $e) {
        wsftp_add_log("Error during scan: " . $e->getMessage());
    } finally {
        // Always release the lock, even in case of error
        delete_transient($lock_transient);
        delete_transient('wsftp_scanning_lock_time');
        wsftp_add_log("Scanning lock released");
    }
}

/**
 * Create a temporary directory for file downloads
 */
function wsftp_create_composer_file() {
    // Create a unique temporary directory
    $upload_dir = wp_upload_dir();
    $temp_dir = $upload_dir['basedir'] . '/wsftp_temp_' . time() . '_' . rand(1000, 9999);
    
    if (!file_exists($temp_dir)) {
        if (!wp_mkdir_p($temp_dir)) {
            wsftp_add_log("Failed to create temporary directory: $temp_dir");
            return false;
        }
    }
    
    // Create an index.html file to prevent directory listing
    $index_file = $temp_dir . '/index.html';
    file_put_contents($index_file, '');
    
    // Create a .htaccess file to prevent direct access
    $htaccess_file = $temp_dir . '/.htaccess';
    file_put_contents($htaccess_file, 'Deny from all');
    
    return $temp_dir;
}

/**
 * Clean up temporary directory
 */
function wsftp_cleanup_temp_directory($dir) {
    if (is_dir($dir)) {
        $files = scandir($dir);
        foreach ($files as $file) {
            if ($file !== '.' && $file !== '..') {
                if (is_dir("$dir/$file")) {
                    wsftp_cleanup_temp_directory("$dir/$file");
                } else {
                    unlink("$dir/$file");
                }
            }
        }
        rmdir($dir);
    }
}

/**
 * Connect to SFTP server
 */
function wsftp_connect_to_sftp() {
    $host = get_option('wsftp_sftp_host', '');
    $port = get_option('wsftp_sftp_port', 22);
    $username = get_option('wsftp_sftp_username', '');
    $auth_method = get_option('wsftp_sftp_auth_method', 'password');
    
    if (empty($host) || empty($username)) {
        wsftp_add_log("SFTP connection failed: Host and username must be provided.");
        return false;
    }
    
    // Connect to server
    $connection = @ssh2_connect($host, $port);
    if (!$connection) {
        wsftp_add_log("SFTP connection failed: Could not connect to $host:$port");
        return false;
    }
    
    // Authenticate
    $auth_success = false;
    
    if ($auth_method === 'password') {
        $password = wsftp_encrypt_decrypt(get_option('wsftp_sftp_password', ''), 'decrypt');
        if (empty($password)) {
            wsftp_add_log("SFTP authentication failed: No password provided");
            return false;
        }
        
        $auth_success = @ssh2_auth_password($connection, $username, $password);
    } else {
        $key_path = get_option('wsftp_sftp_private_key_path', '');
        if (empty($key_path)) {
            wsftp_add_log("SFTP authentication failed: No private key path provided");
            return false;
        }
        
        $auth_success = @ssh2_auth_pubkey_file(
            $connection, 
            $username, 
            $key_path . '.pub', 
            $key_path
        );
    }
    
    if (!$auth_success) {
        wsftp_add_log("SFTP authentication failed for user $username");
        return false;
    }
    
    return $connection;
}

/**
 * List directory contents via SFTP
 */
function wsftp_list_directory($sftp, $path) {
    $handle = @opendir("ssh2.sftp://$sftp$path");
    if (!$handle) {
        wsftp_add_log("Failed to open directory: $path");
        return array();
    }
    
    $files = array();
    while (false !== ($file = readdir($handle))) {
        $files[] = $file;
    }
    
    closedir($handle);
    return $files;
}

/**
 * Check if path is a directory via SFTP
 */
function wsftp_is_dir($sftp, $path) {
    $stat = @ssh2_sftp_stat($sftp, $path);
    if (!$stat) {
        return false;
    }
    
    return ($stat['mode'] & 0040000) == 0040000;
}

/**
 * Download file via SFTP
 */
function wsftp_download_file($sftp, $remote_path, $local_path) {
    $stream = @fopen("ssh2.sftp://$sftp$remote_path", 'r');
    if (!$stream) {
        wsftp_add_log("Failed to open remote file: $remote_path");
        return false;
    }
    
    $temp = @fopen($local_path, 'w');
    if (!$temp) {
        fclose($stream);
        wsftp_add_log("Failed to create local file: $local_path");
        return false;
    }
    
    $result = stream_copy_to_stream($stream, $temp);
    fclose($stream);
    fclose($temp);
    
    if (!$result) {
        wsftp_add_log("Failed to download file: $remote_path");
        return false;
    }
    
    return true;
}

/**
 * Get all user folders from SFTP
 */
function wsftp_get_folder_user_mappings($sftp, $base_path) {
    $mappings = array();
    
    $user_folders = wsftp_list_directory($sftp, $base_path);
    
    foreach ($user_folders as $folder) {
        if ($folder === '.' || $folder === '..' || !wsftp_is_dir($sftp, "$base_path/$folder")) {
            continue;
        }
        
        // Try to match folder name with a user
        $user_id = wsftp_get_user_id_from_folder($folder);
        if ($user_id) {
            $mappings[$folder] = $user_id;
        }
    }
    
    return $mappings;
}

/**
 * Get user ID from folder name
 */
function wsftp_get_user_id_from_folder($folder_name) {
    // First try: Exact match with username
    $user = get_user_by('login', $folder_name);
    if ($user) {
        return $user->ID;
    }
    
    // Second try: Match folder name pattern like "user_123" where 123 is the user ID
    if (preg_match('/user[_\-](\d+)/i', $folder_name, $matches)) {
        $user_id = intval($matches[1]);
        $user = get_user_by('id', $user_id);
        if ($user) {
            return $user->ID;
        }
    }
    
    // Third try: Look for user meta that might store their folder name
    global $wpdb;
    $result = $wpdb->get_var($wpdb->prepare(
        "SELECT user_id FROM {$wpdb->usermeta} 
         WHERE (meta_key = 'sftp_folder' OR meta_key = 'user_folder' OR meta_key LIKE %s) 
         AND meta_value = %s LIMIT 1",
        '%folder%',
        $folder_name
    ));
    
    if ($result) {
        return intval($result);
    }
    
    return false;
}

/**
 * Create a product for a file
 */
function wsftp_create_product($filename, $file_path, $user_id, $product_key) {
    // Get file extension
    $file_extension = pathinfo($filename, PATHINFO_EXTENSION);
    
    // Remove extension from filename to use as product name
    $product_name = pathinfo($filename, PATHINFO_FILENAME);
    $product_name = str_replace('-', ' ', $product_name);
    $product_name = str_replace('_', ' ', $product_name);
    $product_name = ucwords($product_name);
    
    // Default price from settings
    $default_price = get_option('wsftp_default_price', 9.99);
    
    // Get product status from settings
    $product_status = get_option('wsftp_product_status', 'draft');
    
    $is_image = in_array(strtolower($file_extension), array('jpg', 'jpeg', 'png', 'gif', 'webp'));
    $is_downloadable = !$is_image;

    // Get the folder name from file path
    $folder_name = basename(dirname($file_path));

    // Create or get the category with the folder name
    $category = get_term_by('name', $folder_name, 'product_cat');
    if (!$category) {
        // Create the category if it doesn't exist
        $category_id = wp_insert_term($folder_name, 'product_cat');
        if (is_wp_error($category_id)) {
            wsftp_add_log("Error creating category for folder: $folder_name");
            return 0;
        }
        $category_id = $category_id['term_id'];
    } else {
        $category_id = $category->term_id;
    }

    // Create new product
    $product = new WC_Product();
    $product->set_name($product_name);
    $product->set_status($product_status);
    $product->set_catalog_visibility('visible');
    $product->set_price($default_price);
    $product->set_regular_price($default_price);
    $product->set_category_ids(array($category_id)); // Set the category ID

    // Set basic user metadata
    $product->update_meta_data('_wsftp_product_key', $product_key);
    $product->update_meta_data('_wsftp_user_id', $user_id);
    $product->update_meta_data('_wsftp_source_file', $filename);
    
    if ($is_downloadable) {
        $product->set_downloadable(true);
    }
    
    $product_id = $product->save();
    
    if ($product_id) {
        if ($is_image) {
            // Set product image
            wsftp_update_product_image($product_id, $file_path, $filename);
        } elseif ($is_downloadable) {
            // Add downloadable file
            wsftp_update_downloadable_file($product_id, $filename, $file_path);
        }
        
        // Set post author explicitly to match folder owner
        wp_update_post(array(
            'ID' => $product_id,
            'post_author' => $user_id
        ));
        
        return $product_id;
    }
    
    return 0;
}

/**
 * Update product image
 */
function wsftp_update_product_image($product_id, $image_path, $image_name) {
    $upload_dir = wp_upload_dir();
    
    // Create unique filename
    $filename = wp_unique_filename($upload_dir['path'], $image_name);
    
    // Copy file to uploads directory
    $new_file = $upload_dir['path'] . '/' . $filename;
    copy($image_path, $new_file);
    
    // Check the type of file
    $filetype = wp_check_filetype($filename, null);
    
    // Prepare an array of post data for the attachment
    $attachment = array(
        'guid'           => $upload_dir['url'] . '/' . $filename,
        'post_mime_type' => $filetype['type'],
        'post_title'     => preg_replace('/\.[^.]+$/', '', $image_name),
        'post_content'   => '',
        'post_status'    => 'inherit'
    );
    
    // Insert the attachment
    $attach_id = wp_insert_attachment($attachment, $new_file, $product_id);
    
    // Generate metadata for the attachment
    require_once(ABSPATH . 'wp-admin/includes/image.php');
    $attach_data = wp_generate_attachment_metadata($attach_id, $new_file);
    wp_update_attachment_metadata($attach_id, $attach_data);
    
    // Set as product image
    set_post_thumbnail($product_id, $attach_id);
    
    // Store the attachment ID for use with ACF preview
    update_post_meta($product_id, '_wsftp_image_attachment_id', $attach_id);
    
    return $attach_id;
}

/**
 * Update downloadable file for product
 */
function wsftp_update_downloadable_file($product_id, $filename, $file_path) {
    $product = wc_get_product($product_id);
    
    if (!$product) {
        return 0;
    }
    
    // Create safe filename without spaces
    $safe_filename = sanitize_file_name($filename);
    
    // Create uploads folder if not exists
    $upload_dir = wp_upload_dir();
    $downloads_dir = $upload_dir['basedir'] . '/woocommerce_uploads';
    
    if (!file_exists($downloads_dir)) {
        wp_mkdir_p($downloads_dir);
    }
    
    // Create index.html to prevent directory listing
    if (!file_exists($downloads_dir . '/index.html')) {
        $file = @fopen($downloads_dir . '/index.html', 'w');
        if ($file) {
            fwrite($file, '');
            fclose($file);
        }
    }
    
    // Copy file to downloads directory
    $new_file_path = $downloads_dir . '/' . $safe_filename;
    copy($file_path, $new_file_path);
    
    // Create download URL
    $file_url = $upload_dir['baseurl'] . '/woocommerce_uploads/' . $safe_filename;
    
    // Set download file
    $download_id = md5($file_url);
    $downloads = array();
    
    $downloads[$download_id] = array(
        'id' => $download_id,
        'name' => $filename,
        'file' => $file_url,
    );
    
    // Update product data
    $product->set_downloadable(true);
    $product->set_downloads($downloads);
    $product->set_download_limit(-1); // Unlimited downloads
    $product->set_download_expiry(-1); // Never expires
    
    $product->save();
    
    // Create an attachment for the downloadable file for ACF preview
    // This won't be used for product downloads but only for preview
    $filetype = wp_check_filetype($filename, null);
    
    $attachment = array(
        'guid'           => $file_url,
        'post_mime_type' => $filetype['type'],
        'post_title'     => preg_replace('/\.[^.]+$/', '', $filename),
        'post_content'   => '',
        'post_status'    => 'inherit'
    );
    
    // Insert the attachment
    $attach_id = wp_insert_attachment($attachment, $new_file_path, $product_id);
    
    // Generate metadata for the attachment
    require_once(ABSPATH . 'wp-admin/includes/image.php');
    require_once(ABSPATH . 'wp-admin/includes/file.php'); 
    $attach_data = wp_generate_attachment_metadata($attach_id, $new_file_path);
    wp_update_attachment_metadata($attach_id, $attach_data);
    
    // Store the attachment ID for use with ACF preview
    update_post_meta($product_id, '_wsftp_file_attachment_id', $attach_id);
    
    return $attach_id;
}

/**
 * Attach a preview file to a product using ACF
 */
function wsftp_attach_preview_to_acf($product_id, $file_path, $acf_field_name) {
    if (!function_exists('update_field')) {
        wsftp_add_log("ACF functions not available. Skipping preview attachment.");
        return false;
    }
    
    try {
        // Get ACF field group name
        $acf_field_group = get_option('wsftp_acf_field_group', 'product_details');
        
        // Check if we already have an attachment for this product
        $attachment_id = 0;
        $file_type = wp_check_filetype(basename($file_path), null);
        
        // First check if it's an image
        $image_attachment_id = get_post_meta($product_id, '_wsftp_image_attachment_id', true);
        if (!empty($image_attachment_id)) {
            $attachment_id = $image_attachment_id;
        } else {
            // Then check if it's a downloadable file
            $file_attachment_id = get_post_meta($product_id, '_wsftp_file_attachment_id', true);
            if (!empty($file_attachment_id)) {
                $attachment_id = $file_attachment_id;
            }
        }
        
        // If we don't have an attachment, create one
        if (empty($attachment_id)) {
            $upload_dir = wp_upload_dir();
            $filename = basename($file_path);
            
            // Create unique filename
            $new_filename = wp_unique_filename($upload_dir['path'], $filename);
            $new_file = $upload_dir['path'] . '/' . $new_filename;
            
            // Copy the file
            copy($file_path, $new_file);
            
            // Prepare attachment data
            $attachment_data = [
                'post_mime_type' => $file_type['type'],
                'post_title' => sanitize_file_name(basename($file_path)),
                'post_content' => '',
                'post_status' => 'inherit'
            ];
            
            // Insert the attachment
            $attachment_id = wp_insert_attachment($attachment_data, $new_file, $product_id);
            
            // Generate metadata for the attachment
            require_once(ABSPATH . 'wp-admin/includes/image.php');
            $attach_data = wp_generate_attachment_metadata($attachment_id, $new_file);
            wp_update_attachment_metadata($attachment_id, $attach_data);
        }
        
        if ($attachment_id) {
            // Update ACF field with the attachment ID
            $field_key = wsftp_get_acf_field_key($acf_field_name, $acf_field_group);
            
            if ($field_key) {
                // Update using field key (more reliable)
                update_field($field_key, $attachment_id, $product_id);
                wsftp_add_log("ACF field updated via field key: $field_key for product #{$product_id}");
            } else {
                // Try updating directly by field name
                update_field($acf_field_name, $attachment_id, $product_id);
                wsftp_add_log("ACF field updated by name: $acf_field_name for product #{$product_id}");
            }
            
            // As a backup, also update directly in post meta
            update_post_meta($product_id, $acf_field_name, $attachment_id);
            
            // Record ID in specific field for diagnostic purposes
            update_post_meta($product_id, '_wsftp_preview_attachment_id', $attachment_id);
            
            // Try to force ACF cache refresh
            if (function_exists('acf_flush_value_cache')) {
                acf_flush_value_cache($product_id);
            }
            
            return true;
        }
    } catch (Exception $e) {
        wsftp_add_log("Error attaching preview: " . $e->getMessage());
    }
    
    return false;
}

/**
 * Get ACF field key by name
 */
function wsftp_get_acf_field_key($field_name, $group_name = '') {
    global $wpdb;
    
    if (!empty($group_name)) {
        // Try to get the field key from a specific group
        $sql = $wpdb->prepare(
            "SELECT acf.meta_value FROM $wpdb->posts posts
            INNER JOIN $wpdb->postmeta acf ON posts.ID = acf.post_id
            WHERE posts.post_excerpt = %s
            AND acf.meta_key = %s
            AND posts.post_status = 'publish'",
            $field_name,
            '_field_key'
        );
        
        $field_key = $wpdb->get_var($sql);
        
        if ($field_key) {
            return $field_key;
        }
    }
    
    // Fallback - try to get the field key directly
    $sql = $wpdb->prepare(
        "SELECT p.post_name FROM $wpdb->posts p
        WHERE p.post_excerpt = %s
        AND p.post_type = 'acf-field'",
        $field_name
    );
    
    $field_key = $wpdb->get_var($sql);
    
    return $field_key ? 'field_' . $field_key : false;
}

/**
 * Remove products for deleted files
 */
function wsftp_remove_deleted_products($current_files, $processed_files) {
    $deleted_count = 0;
    
    // Find files that were previously processed but not found in current scan
    $deleted_files = array_diff_key($processed_files, $current_files);
    
    foreach ($deleted_files as $product_key => $timestamp) {
        // Find product with this key
        $products = wc_get_products(array(
            'limit' => 1,
            'meta_key' => '_wsftp_product_key',
            'meta_value' => $product_key,
            'return' => 'ids',
        ));
        
        if (!empty($products)) {
            $product_id = $products[0];
            
            // Delete the product
            wp_delete_post($product_id, true);
            
            // Remove from processed list
            unset($processed_files[$product_key]);
            
            $deleted_count++;
            wsftp_add_log("Deleted product #$product_id for removed file: $product_key");
        }
    }
    
    // Update processed files list after removal
    if ($deleted_count > 0) {
        update_option('wsftp_processed_files', $processed_files);
    }
    
    return $deleted_count;
}

/**
 * Diagnose and fix ACF preview files
 */
function wsftp_diagnose_and_fix_acf_previews() {
    if (!function_exists('update_field')) {
        wsftp_add_log("Advanced Custom Fields not activated. Cannot fix preview files.");
        return 0;
    }
    
    $acf_field_group = get_option('wsftp_acf_field_group', 'product_details');
    $acf_field_name = get_option('wsftp_acf_preview_field', 'preview_file');
    $use_same_file = get_option('wsftp_use_same_file', 1);
    
    // Get all products created by this plugin
    $products = wc_get_products([
        'limit' => -1,
        'meta_key' => '_wsftp_product_key',
        'return' => 'ids',
    ]);
    
    $fixed_count = 0;
    
    foreach ($products as $product_id) {
        // For products using the same file, check for image or file attachment
        if ($use_same_file) {
            $image_attachment_id = get_post_meta($product_id, '_wsftp_image_attachment_id', true);
            $file_attachment_id = get_post_meta($product_id, '_wsftp_file_attachment_id', true);
            
            $attachment_id = !empty($image_attachment_id) ? $image_attachment_id : $file_attachment_id;
            
            if (!empty($attachment_id)) {
                $attachment = get_post($attachment_id);
                
                // Check if attachment still exists
                if ($attachment && $attachment->post_type == 'attachment') {
                    // Update ACF field with the attachment ID
                    $field_key = wsftp_get_acf_field_key($acf_field_name, $acf_field_group);
                    
                    if ($field_key) {
                        update_field($field_key, $attachment_id, $product_id);
                    } else {
                        update_field($acf_field_name, $attachment_id, $product_id);
                    }
                    
                    // Update post meta as backup
                    update_post_meta($product_id, $acf_field_name, $attachment_id);
                    update_post_meta($product_id, '_wsftp_preview_attachment_id', $attachment_id);
                    
                    $fixed_count++;
                    wsftp_add_log("Fixed ACF preview for product #{$product_id}");
                }
            }
        } else {
            // Check if product has a preview attachment ID but ACF field is empty
            $preview_id = get_post_meta($product_id, '_wsftp_preview_attachment_id', true);
            
            if (!empty($preview_id)) {
                $attachment = get_post($preview_id);
                
                // Check if attachment still exists
                if ($attachment && $attachment->post_type == 'attachment') {
                    // Try to fix ACF field connection
                    $field_key = wsftp_get_acf_field_key($acf_field_name, $acf_field_group);
                    
                    if ($field_key) {
                        update_field($field_key, $preview_id, $product_id);
                    } else {
                        update_field($acf_field_name, $preview_id, $product_id);
                    }
                    
                    // Update post meta as backup
                    update_post_meta($product_id, $acf_field_name, $preview_id);
                    
                    $fixed_count++;
                    wsftp_add_log("Fixed ACF preview for product #{$product_id}");
                }
            }
        }
    }
    
    return $fixed_count;
}

// Set up delayed scan callback
add_action('init', function() {
    // Schedule event if not already scheduled
    if (!wp_next_scheduled('wsftp_scan_hook')) {
        wp_schedule_event(time(), 'hourly', 'wsftp_scan_hook');
    }
});

// Hook for scheduled scan
add_action('wsftp_scan_hook', 'wsftp_scan_folders');

// Hook for page loads to trigger scan based on interval
add_action('shutdown', function() {
    // Only proceed for normal page loads, not admin-ajax or API calls
    if (defined('DOING_AJAX') || defined('REST_REQUEST') || is_admin()) {
        return;
    }
    
    $last_scan = get_option('wsftp_last_scan_time', 0);
    $interval = get_option('wsftp_scan_interval', 60); // in minutes
    
    if (time() - $last_scan >= ($interval * 60)) {
        // Use a promise to run the scan in the background
        wp_schedule_single_event(time(), 'wsftp_delayed_scan_hook');
    }
});

add_action('wsftp_delayed_scan_hook', 'wsftp_scan_folders');

// Add a setting to enable/disable the plugin
register_activation_hook(__FILE__, function() {
    // Set default options on activation
    if (get_option('wsftp_last_scan_time') === false) {
        update_option('wsftp_last_scan_time', 0);
    }
    
    if (get_option('wsftp_processed_files') === false) {
        update_option('wsftp_processed_files', array());
    }
    
    // Create necessary folders
    $upload_dir = wp_upload_dir();
    $temp_dir = $upload_dir['basedir'] . '/wsftp_temp';
    
    if (!file_exists($temp_dir)) {
        wp_mkdir_p($temp_dir);
        
        // Create index.html and .htaccess files
        file_put_contents($temp_dir . '/index.html', '');
        file_put_contents($temp_dir . '/.htaccess', 'Deny from all');
    }
});

// Clean up on deactivation
register_deactivation_hook(__FILE__, function() {
    wp_clear_scheduled_hook('wsftp_scan_hook');
    wp_clear_scheduled_hook('wsftp_delayed_scan_hook');
    
    // Remove any existing scanning locks
    delete_transient('wsftp_scanning_lock');
    delete_transient('wsftp_scanning_lock_time');
});