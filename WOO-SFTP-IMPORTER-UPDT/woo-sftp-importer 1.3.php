<?php
/**
 * Plugin Name: WooCommerce SFTP Folder Importer (Create Once)
 * Plugin URI: https://yourwebsite.com/
 * Description: Import products from SFTP folders only once and never update them
 * Version: 1.3.0
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
define('WSFTP_VERSION', '1.3.0');
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
    
    // Repair processed files registry
    if (isset($_POST['wsftp_repair_registry']) && wp_verify_nonce($_POST['wsftp_reset_nonce'], 'wsftp_reset_db')) {
        $repaired = wsftp_repair_processed_files();
        echo '<div class="notice notice-success is-dismissible"><p>Processamento concluído. ' . $repaired . ' produtos foram adicionados ao registro para evitar duplicações.</p></div>';
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
    
    // Manual scan trigger with improved error handling
    if (isset($_POST['wsftp_manual_scan']) && wp_verify_nonce($_POST['wsftp_nonce'], 'wsftp_manual_scan')) {
        // Aumentar limites para evitar erros de timeout e memória
        @ini_set('memory_limit', '512M');
        @set_time_limit(300); // 5 minutos
        
        // Iniciar buffer de saída para capturar erros
        ob_start();
        
        try {
            wsftp_add_log("Escaneamento manual iniciado pelo administrador.");
            
            // Executa o escaneamento usando um hook agendado para melhor performance
            wp_schedule_single_event(time(), 'wsftp_delayed_scan_hook');
            spawn_cron(); // Força a execução do cron imediatamente
            
            wsftp_add_log("Escaneamento manual agendado com sucesso.");
            echo '<div class="notice notice-success is-dismissible"><p>Escaneamento manual agendado. Aguarde alguns instantes e verifique o log para detalhes.</p></div>';
        } catch (Exception $e) {
            wsftp_add_log("Erro durante o escaneamento manual: " . $e->getMessage());
            echo '<div class="notice notice-error is-dismissible"><p>Erro durante escaneamento: ' . esc_html($e->getMessage()) . '</p></div>';
        } catch (Error $e) {
            wsftp_add_log("Erro crítico durante escaneamento manual: " . $e->getMessage());
            echo '<div class="notice notice-error is-dismissible"><p>Erro crítico: ' . esc_html($e->getMessage()) . '</p></div>';
        }
        
        // Verificar erros PHP capturados no buffer
        $output = ob_get_clean();
        if (!empty($output) && strpos($output, 'Fatal error') !== false) {
            wsftp_add_log("Saída capturada durante escaneamento: " . $output);
            echo '<div class="notice notice-error is-dismissible"><p>Erro detectado durante processamento. Verifique o log para detalhes.</p></div>';
        }
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
        <h1>WooCommerce SFTP Folder Importer (Create Once Mode) - v1.3</h1>
        
        <div class="notice notice-info">
            <p><strong>Create Once Mode:</strong> In this mode, the plugin will only create products for files it has never seen before. 
            It will not update existing products even if the files change.</p>
            <p><strong>Versão 1.3:</strong> Esta versão usa o mesmo arquivo do produto como arquivo de preview, sem necessidade de uma pasta separada.</p>
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
                    title="Use this if scans are not running and you see 'Scan already running' messages in the log" />  <input type="submit" name="wsftp_repair_registry" class="button-secondary" value="Repair Product Registry" 
                            style="background-color:#7235a7;border-color:#5b2a85;color:#fff;" 
                <input type="submit" name="wsftp_fix_acf_previews" class="button-secondary" value="Fix ACF Preview Files" Use this to fix product duplication by repairing the processed files registry" />
                    style="background-color:#0073aa;border-color:#005a87;color:#fff;" wing in the ACF interface" />
                    title="Use this to diagnose and fix preview files that are not showing in the ACF interface" />
                <input type="submit" name="wsftp_repair_registry" class="button-secondary" value="Repair Product Registry" 
                <input type="submit" name="wsftp_repair_registry" class="button-secondary" value="Repair Product Registry" 
                    style="background-color:#00a32a;border-color:#008a1c;color:#fff;" ht: 300px; overflow-y: auto; border: 1px solid #ccc; padding: 10px; background: #f9f9f9;">
                    title="Use this to repair the processed files registry and avoid duplicate products" />
            </form>_log', array());
        </div>
        <h2>Import Log</h2>
        <h2>Import Log</h2>e {tainer" style="max-height: 300px; overflow-y: auto; border: 1px solid #ccc; padding: 10px; background: #f9f9f9;">
        <div class="wsftp-log-container" style="max-height: 300px; overflow-y: auto; border: 1px solid #ccc; padding: 10px; background: #f9f9f9;">le="margin-top: 0;">';
            <?phpforeach (array_reverse($log_entries) as $log) {
            $log_entries = get_option('wsftp_import_log', array());' . esc_html($log['time']) . ']</strong> ' . esc_html($log['message']) . '</li>';
            if (empty($log_entries)) {s available.</p>';
                echo '<p>No import logs available.</p>';
            } else { '<ul style="margin-top: 0;">';
                echo '<ul style="margin-top: 0;">';) as $log) {
                foreach (array_reverse($log_entries) as $log) {]) . ']</strong> ' . esc_html($log['message']) . '</li>';
                    echo '<li><strong>[' . esc_html($log['time']) . ']</strong> ' . esc_html($log['message']) . '</li>'; method="post" action="">
                }       <?php wp_nonce_field('wsftp_clear_log', 'wsftp_nonce'); ?>
                echo '</ul>';        <input type="submit" name="wsftp_clear_log" class="button-secondary" value="Clear Log" />
                
                // Add clear log button
                ?>wp_nonce_field('wsftp_clear_log', 'wsftp_nonce'); ?>
                <form method="post" action=""> value="Clear Log" />
                    <?php wp_nonce_field('wsftp_clear_log', 'wsftp_nonce'); ?>/ Clear log action
                    <input type="submit" name="wsftp_clear_log" class="button-secondary" value="Clear Log" /> (isset($_POST['wsftp_clear_log']) && wp_verify_nonce($_POST['wsftp_nonce'], 'wsftp_clear_log')) {
                </form>  delete_option('wsftp_import_log');ss="button-secondary" value="Clear Log" />
                <?php      echo '<script>location.reload();</script>';
            }        }
            pocument).ready(function($) {
            // Clear log actionthod fields
            if (isset($_POST['wsftp_clear_log']) && wp_verify_nonce($_POST['wsftp_nonce'], 'wsftp_clear_log')) {
                delete_option('wsftp_import_log');val();
                echo '<script>location.reload();</script>';
            }('.auth-password').show();
            ?>lds-key').hide();
        </div> {
    </div>od = $('#wsftp_sftp_auth_method').val();
    <script type="text/javascript">            $('.auth-key').show();
    <script type="text/javascript">ow();
    jQuery(document).ready(function($) {   $('.auth-key').hide();
        // Toggle authentication method fields   } else {
        function toggleAuthFields() {        $('.auth-password').hide();
            var method = $('#wsftp_sftp_auth_method').val();
            if (method === 'password') {();
                $('.auth-password').show(); }
                $('.auth-key').hide();
            } else {('#wsftp_sftp_auth_method').on('change', toggleAuthFields);
                $('.auth-password').hide();       toggleAuthFields();
                $('.auth-key').show();    });
            } </script>
        }
        $('#wsftp_sftp_auth_method').on('change', toggleAuthFields); wsftp_add_log($message) {
        $('#wsftp_sftp_auth_method').on('change', toggleAuthFields);
        toggleAuthFields();_log', array());
    });cript> // Limit to last 100 entries
    </script>($log_entries) >= 100) {
    <?php_shift($log_entries);
})) {
tp_import_log', array());
/**Add log entry         'time' => current_time('Y-m-d H:i:s'),
 * Add log entry// Limit to last 100 entries
 */ >= 100) {p_add_log($message) {
function wsftp_add_log($message) {led', 1)) {
    if (get_option('wsftp_log_enabled', 1)) {rt_log', array());g_entries);
        $log_entries = get_option('wsftp_import_log', array());
        $log_entries[] = array( >= 100) {
        // Limit to last 100 entries);
        if (count($log_entries) >= 100) {       'message' => $message
            array_shift($log_entries);       );
        }        ' => current_time('Y-m-d H:i:s'),
             update_option('wsftp_import_log', $log_entries);
        $log_entries[] = array(
            'time' => current_time('Y-m-d H:i:s'),
            'message' => $messagert_log', $log_entries);?? 'wsftp-default-iv';  // Uses WordPress SECURE_AUTH_KEY if available
        );
        ('sha256', $secret_key);
        update_option('wsftp_import_log', $log_entries);
    }tion wsftp_encrypt_decrypt($string, $action = 'encrypt') {
}* Simple encrypt/decrypt function for storing passwords   if ($action == 'encrypt') {
// Uses WordPress AUTH_KEY if availablekey, 0, $iv));
/**$secret_iv = SECURE_AUTH_KEY ?? 'wsftp-default-iv';  // Uses WordPress SECURE_AUTH_KEY if available
 * Simple encrypt/decrypt function for storing passwordskey, 0, $iv);
 */ $secret_key = AUTH_KEY ?? 'wsftp-default-key';  // Uses WordPress AUTH_KEY if available }
function wsftp_encrypt_decrypt($string, $action = 'encrypt') {bstr(hash('sha256', $secret_iv), 0, 16);e
    $encrypt_method = "AES-256-CBC";
    $secret_key = AUTH_KEY ?? 'wsftp-default-key';  // Uses WordPress AUTH_KEY if availablef ($action == 'encrypt') {
    $secret_iv = SECURE_AUTH_KEY ?? 'wsftp-default-iv';  // Uses WordPress SECURE_AUTH_KEY if available    $output = base64_encode(openssl_encrypt($string, $encrypt_method, $key, 0, $iv));
    
    $key = hash('sha256', $secret_key);       $output = openssl_decrypt(base64_decode($string), $encrypt_method, $key, 0, $iv);
    $iv = substr(hash('sha256', $secret_iv), 0, 16);    }$encrypt_method, $key, 0, $iv));
      else {ion wsftp_test_connection() {
    if ($action == 'encrypt') {pt(base64_decode($string), $encrypt_method, $key, 0, $iv);')) {
        $output = base64_encode(openssl_encrypt($string, $encrypt_method, $key, 0, $iv));
    } else {
        $output = openssl_decrypt(base64_decode($string), $encrypt_method, $key, 0, $iv);
    } = get_option('wsftp_sftp_host', '');
    t = get_option('wsftp_sftp_port', 22);
    return $output;tion wsftp_test_connection() {
}* Test SFTP Connection   $auth_method = get_option('wsftp_sftp_auth_method', 'password');
available on your server. Please contact your hosting provider.';
/**ction wsftp_test_connection() { if (empty($host) || empty($username)) {
 * Test SFTP Connectioned('ssh2')) {d username are required.';
 */$host = get_option('wsftp_sftp_host', ''); available on your server. Please contact your hosting provider.';
function wsftp_test_connection() {2);
    if (!extension_loaded('ssh2')) { '');
        return 'The PHP SSH2 extension is not available on your server. Please contact your hosting provider.';auth_method = get_option('wsftp_sftp_auth_method', 'password');
    }port = get_option('wsftp_sftp_port', 22);f (!$connection) {
    mpty($host) || empty($username)) {_username', '');host on port $port";
    $host = get_option('wsftp_sftp_host', '');name are required.';);
    $port = get_option('wsftp_sftp_port', 22);
    $username = get_option('wsftp_sftp_username', '');
    $auth_method = get_option('wsftp_sftp_auth_method', 'password');
    / Attempt to connect
    if (empty($host) || empty($username)) {$connection = @ssh2_connect($host, $port);
        return 'Host and username are required.';n) {
    }connect to $host on port $port";, $port);_decrypt(get_option('wsftp_sftp_password', ''), 'decrypt');
    }f (!$connection) {       if (empty($password)) {
    try {eturn "Failed to connect to $host on port $port";       return 'Password is required for password authentication.';
        // Attempt to connect
        $connection = @ssh2_connect($host, $port);
        if (!$connection) {
            return "Failed to connect to $host on port $port";auth_method === 'password') {
        }$password = wsftp_encrypt_decrypt(get_option('wsftp_sftp_password', ''), 'decrypt');
                return "Private key file not found at: $key_path";
        // Authenticatereturn 'Password is required for password authentication.';
        $auth_success = false;encrypt_decrypt(get_option('wsftp_sftp_password', ''), 'decrypt');sh2_auth_pubkey_file(
            if (empty($password)) {        $connection, 
        if ($auth_method === 'password') {name, $password);uthentication.';
            $password = wsftp_encrypt_decrypt(get_option('wsftp_sftp_password', ''), 'decrypt');e {
            if (empty($password)) {$key_path = get_option('wsftp_sftp_private_key_path', '');
                return 'Password is required for password authentication.';ey_path)) {
            }te key file not found at: $key_path";vate_key_path', '');
            if (empty($key_path) || !file_exists($key_path)) {
            $auth_success = @ssh2_auth_password($connection, $username, $password);
        } else {'Authentication failed. Please check your credentials.';
            $key_path = get_option('wsftp_sftp_private_key_path', '');  $connection, 
            if (empty($key_path) || !file_exists($key_path)) {       $username, 
                return "Private key file not found at: $key_path";        $key_path . '.pub',  // Public key
            }        // Private key  // Public keyion);
                $key_path            // Private key!$sftp) {
            $auth_success = @ssh2_auth_pubkey_file(
                $connection, 
                $username, 
                $key_path . '.pub',  // Public keyd. Please check your credentials.';
                $key_path            // Private keyheck your credentials.';th', '/user_folders');
            );2.sftp://$sftp$base_path";
        }/ Initialize SFTP subsystem
        $sftp = @ssh2_sftp($connection);h' does not exist on the server.";
        if (!$auth_success) {onnection);
            return 'Authentication failed. Please check your credentials.';
        }   return 'Failed to initialize SFTP subsystem.';/ All tests passed
        }return true;
        // Initialize SFTP subsystemts
        $sftp = @ssh2_sftp($connection);
        if (!$sftp) {dir = "ssh2.sftp://$sftp$base_path";/user_folders');
            return 'Failed to initialize SFTP subsystem.';
        }ir)) {le_exists($dir)) {
        The base path '$base_path' does not exist on the server.";he server.";
        // Test if base path existsr new files)
        $base_path = get_option('wsftp_sftp_base_path', '/user_folders');
        $dir = "ssh2.sftp://$sftp$base_path";   // All tests passed
               return true;cate processing
        if (!file_exists($dir)) {    } catch (Exception $e) {
            return "The base path '$base_path' does not exist on the server.";     return 'Error: ' . $e->getMessage();
        }for an old lock (over 10 minutes)
        ime && (time() - $lock_time > 600)) {
        // All tests passed
        return true;ent);
    } catch (Exception $e) {products (only for new files)y for new files)
        return 'Error: ' . $e->getMessage();n 10 minutes)");
    }ion wsftp_scan_folders() {
}   // Set a lock to prevent duplicate processing   
    $lock_transient = 'wsftp_scanning_lock';    if (get_transient($lock_transient)) {
/** $lock_time = get_transient('wsftp_scanning_lock_time');     wsftp_add_log("Scan already running. Skipping this execution.");
 * Main function to scan folders and create products (only for new files)tes)
 */ime');lock_time && (time() - $lock_time > 600)) {
function wsftp_scan_folders() {k is too old
    // Set a lock to prevent duplicate processing   // Force release if lock is too old
    $lock_transient = 'wsftp_scanning_lock';    delete_transient($lock_transient);
    lock_time');d_log("Expired scanning lock detected and cleared (lock was older than 10 minutes)");t('wsftp_scanning_lock_time', time(), 30 * MINUTE_IN_SECONDS);
    // Check for an old lock (over 10 minutes)was older than 10 minutes)");
    $lock_time = get_transient('wsftp_scanning_lock_time');
    if ($lock_time && (time() - $lock_time > 600)) {
        // Force release if lock is too oldif (get_transient($lock_transient)) {
        delete_transient($lock_transient); this execution.");
        delete_transient('wsftp_scanning_lock_time');
        wsftp_add_log("Expired scanning lock detected and cleared (lock was older than 10 minutes)");
    }/ Set lock for 5 minutes max and record the time   
    t lock for 5 minutes max and record the timeUTE_IN_SECONDS);
    if (get_transient($lock_transient)) {t, true, 5 * MINUTE_IN_SECONDS);NDS);
        wsftp_add_log("Scan already running. Skipping this execution.");30 * MINUTE_IN_SECONDS);
        return;_log("Failed to connect to SFTP server. Check your credentials.");
    }   // Update last scan time       delete_transient($lock_transient);
    ime update_option('wsftp_last_scan_time', time());     return;
    // Set lock for 5 minutes max and record the timeupdate_option('wsftp_last_scan_time', time());
    set_transient($lock_transient, true, 5 * MINUTE_IN_SECONDS);olders
    set_transient('wsftp_scanning_lock_time', time(), 30 * MINUTE_IN_SECONDS);timeout on large folders
    );    $base_path = get_option('wsftp_sftp_base_path', '/user_folders');
    try {/ Establish SFTP connectionremove_deleted = get_option('wsftp_remove_deleted_files', 1);
        // Update last scan timeect_to_sftp();
        update_option('wsftp_last_scan_time', time()); = wsftp_connect_to_sftp();
        f (!$connection) {"Failed to connect to SFTP server. Check your credentials.");//$sftp$base_path";
        // Set a higher time limit to prevent timeout on large folders    wsftp_add_log("Failed to connect to SFTP server. Check your credentials.");
        @set_time_limit(300);sient);FTP server: $base_path");
        }    delete_transient($lock_transient);
        // Establish SFTP connection
        $connection = wsftp_connect_to_sftp();
        if (!$connection) {n);n('wsftp_sftp_base_path', '/user_folders');
            wsftp_add_log("Failed to connect to SFTP server. Check your credentials.");ase_path', '/user_folders');
            delete_transient($lock_transient);ion('wsftp_remove_deleted_files', 1);
            return; base path existsady processed files
        }dir = "ssh2.sftp://$sftp$base_path";processed_files = get_option('wsftp_processed_files', array());
        2.sftp://$sftp$base_path";
        $sftp = ssh2_sftp($connection);f (!file_exists($dir)) {: $base_path");
        $base_path = get_option('wsftp_sftp_base_path', '/user_folders');    wsftp_add_log("Base folder not found on SFTP server: $base_path");
        $remove_deleted = get_option('wsftp_remove_deleted_files', 1);
            return;
        // Check if base path exists
        $dir = "ssh2.sftp://$sftp$base_path";n SFTP path: $base_path");
        if (!file_exists($dir)) {wsftp_add_log("Starting folder scan in SFTP path: $base_path");
            wsftp_add_log("Base folder not found on SFTP server: $base_path");
            delete_transient($lock_transient);filessed_files', array());
            return;$processed_files = get_option('wsftp_processed_files', array());
        }  wsftp_add_log("Failed to create temporary directory for downloads.");
        // Get folder to user mappings    delete_transient($lock_transient);
        wsftp_add_log("Starting folder scan in SFTP path: $base_path");$current_files = array();
        }
        // Get already processed fileser mappings
        $processed_files = get_option('wsftp_processed_files', array());$folder_mappings = wsftp_get_folder_user_mappings($sftp, $base_path);
        foreach ($folder_mappings as $folder_name => $user_id) {
        // Current files for deletion trackingnload filesolder_name";
        $current_files = array();0;mposer_file(); folder for user #{$user_id}: $folder_name");
        if (!$temp_dir) {    
        // Get folder to user mappingsnload filesrary directory for downloads.");older
        $folder_mappings = wsftp_get_folder_user_mappings($sftp, $base_path); wsftp_create_composer_file();
        f (!$temp_dir) {
        $total_created = 0;    wsftp_add_log("Failed to create temporary directory for downloads.");
        $total_skipped = 0;transient);$sftp, "$user_folder_path/$file")) {
        // Process each user folder            continue;
        // Create a temporary directory to download filesr_id) {
        $temp_dir = wsftp_create_composer_file();er_name";
        if (!$temp_dir) {("Scanning folder for user #{$user_id}: $folder_name");te the unique key for this product
            wsftp_add_log("Failed to create temporary directory for downloads.");ach ($folder_mappings as $folder_name => $user_id) {
            delete_transient($lock_transient);me";lder
            return;= wsftp_list_directory($sftp, $user_folder_path);Check if we've already processed this file before
        }wsftp_add_log("Scanning folder for user #{$user_id}: $folder_name");
            foreach ($files as $file) {            // We've already created a product for this file - SKIP IT COMPLETELY
        // Process each user folder| $file === '..' || wsftp_is_dir($sftp, "$user_folder_path/$file")) {+;
        foreach ($folder_mappings as $folder_name => $user_id) {st_directory($sftp, $user_folder_path);
            $user_folder_path = "$base_path/$folder_name";
            ach ($files as $file) {
            wsftp_add_log("Scanning folder for user #{$user_id}: $folder_name");p_is_dir($sftp, "$user_folder_path/$file")) {
                $product_key = sanitize_title($user_id . '-' . $file);    $current_files[$product_key] = "$user_folder_path/$file";
            // Get all files within this user's folder}
            $files = wsftp_list_directory($sftp, $user_folder_path);
                if (isset($processed_files[$product_key])) {    $local_file_path = $temp_dir . '/' . $file;
            foreach ($files as $file) {$product_key = sanitize_title($user_id . '-' . $file);
                if ($file === '.' || $file === '..' || wsftp_is_dir($sftp, "$user_folder_path/$file")) {
                    continue;cessados
                }   continue;
                
                // Generate the unique key for this product
                $product_key = sanitize_title($user_id . '-' . $file);
                
                // Track this file for deletion checkingon
                $current_files[$product_key] = "$user_folder_path/$file";
                if (!wsftp_download_file($sftp, "$user_folder_path/$file", $local_file_path)) {    $total_created++;
                // Verificação dupla para evitar duplicidades:ile (user: $user_id)");
                // 1. Primeiro verifica no array de arquivos processados
                if (isset($processed_files[$product_key])) {
                    // We've already created a product for this file - SKIP IT COMPLETELY
                    $total_skipped++;file: $file (user: $user_id)");
                    continue;/ This is a NEW file - create a product for it
                }le, $local_file_path, $user_id, $product_key, $folder_name);
                
                // 2. Mesmo que não esteja no array, verifica diretamente no banco se já existe um produto com esse product_key
                $existing_products = wc_get_products(array(
                    'limit' => 1,ser_id)");
                    'meta_key' => '_wsftp_product_key', processed files to avoid future processing
                    'meta_value' => $product_key,   $processed_files[$product_key] = time();for deleted files and remove corresponding products
                    'return' => 'ids',
                )); (user: $user_id)");rent_files, $processed_files);
                
                if (!empty($existing_products)) {
                    // Produto já existe mas não estava no array de processados - adiciona agora
                    $processed_files[$product_key] = time();
                    wsftp_add_log("Encontrou produto existente para arquivo: $file (product_key: $product_key) - adicionando ao registro de processados");
                    $total_skipped++;
                    continue;
                }
                
                // 3. Cria um lock temporário específico para este arquivo para evitar processamento duplicado;
                $file_lock_key = 'wsftp_file_lock_' . md5($product_key);deleted_count > 0) {
                if (get_transient($file_lock_key)) { products for deleted files");l_created, Skipped: $total_skipped products");
                    wsftp_add_log("Arquivo $file já está sendo processado em outro processo - ignorando");
                    $total_skipped++;
                    continue;
                }/ Update processed files list/ Always release the lock, even in case of error
                
                // Estabelece o lock com duração de 10 minutos
                set_transient($file_lock_key, true, 10 * MINUTE_IN_SECONDS);
                
                // Download the file to temporary location
                $local_file_path = $temp_dir . '/' . $file;pped: $total_skipped products");
                if (!wsftp_download_file($sftp, "$user_folder_path/$file", $local_file_path)) {
                    wsftp_add_log("Failed to download file: $file");
                    delete_transient($file_lock_key); // Remove o lock se falhou
                    continue;
                }
                elete_transient('wsftp_scanning_lock_time');ad_dir = wp_upload_dir();
                // This is a NEW file - create a product for itemp_' . time() . '_' . rand(1000, 9999);
                $result = wsftp_create_product($file, $local_file_path, $user_id, $product_key, $folder_name);
                
                if ($result === 'created') {
                    $total_created++;
                    wsftp_add_log("Created new product from file: $file (user: $user_id)");
                    
                    // Add to processed files to avoid future processing
                    $processed_files[$product_key] = time();
                } elseif ($result === 'error') {ory listing
                    wsftp_add_log("Error processing file: $file (user: $user_id)");' . rand(1000, 9999);
                }
                
                // Delete the temporary filef (!wp_mkdir_p($temp_dir)) {eate a .htaccess file to prevent direct access
                @unlink($local_file_path);ailed to create temporary directory: $temp_dir");ir . '/.htaccess';
                rn false;tents($htaccess_file, 'Deny from all');
                // Remove o lock deste arquivo
                delete_transient($file_lock_key);
            }
        }
        dex.html';
        // Check for deleted files and remove corresponding products
        if ($remove_deleted) {
            $deleted_count = wsftp_remove_deleted_products($current_files, $processed_files);
            if ($deleted_count > 0) {s';
                wsftp_add_log("Removed $deleted_count products for deleted files");
            }
        }.') {
        
        // Update processed files list
        update_option('wsftp_processed_files', $processed_files);
        
        // Clean up temporary directory
        wsftp_cleanup_temp_directory($temp_dir);
        
        wsftp_add_log("Scan completed. Created: $total_created, Skipped: $total_skipped products");
    } catch (Exception $e) {
        wsftp_add_log("Error during scan: " . $e->getMessage());        if ($file !== '.' && $file !== '..') {
    } finally {s_dir("$dir/$file")) {
        // Always release the lock, even in case of error                   wsftp_cleanup_temp_directory("$dir/$file");**
        delete_transient($lock_transient);                } else { * Connect to SFTP server
        delete_transient('wsftp_scanning_lock_time');/$file");
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
    ption('wsftp_sftp_host', ''); server
    if (!file_exists($temp_dir)) {et_option('wsftp_sftp_port', 22);on = @ssh2_connect($host, $port);
        if (!wp_mkdir_p($temp_dir)) {name = get_option('wsftp_sftp_username', '');$connection) {
            wsftp_add_log("Failed to create temporary directory: $temp_dir");et_option('wsftp_sftp_auth_method', 'password');g("SFTP connection failed: Could not connect to $host:$port");
            return false;  return false;
        }
    }     wsftp_add_log("SFTP connection failed: Host and username must be provided."); 
         return false; // Authenticate
    // Create an index.html file to prevent directory listing
    $index_file = $temp_dir . '/index.html';  
    file_put_contents($index_file, '');
    
    // Create a .htaccess file to prevent direct access
    $htaccess_file = $temp_dir . '/.htaccess';onnect to $host:$port");password provided");
    file_put_contents($htaccess_file, 'Deny from all');
    
    return $temp_dir;
}

/**
 * Clean up temporary directory'password') {("SFTP authentication failed: No private key path provided");
 */p_encrypt_decrypt(get_option('wsftp_sftp_password', ''), 'decrypt');;
function wsftp_cleanup_temp_directory($dir) {
    if (is_dir($dir)) {og("SFTP authentication failed: No password provided"); @ssh2_auth_pubkey_file(
        $files = scandir($dir);
        foreach ($files as $file) {
            if ($file !== '.' && $file !== '..') {
                if (is_dir("$dir/$file")) {
                    wsftp_cleanup_temp_directory("$dir/$file"); get_option('wsftp_sftp_private_key_path', '');
                } else {
                    unlink("$dir/$file");        wsftp_add_log("SFTP authentication failed: No private key path provided");
                }
            }
        }ey_file(
        rmdir($dir);
    }
}
    $key_path
/**
 * Connect to SFTP server
 */
function wsftp_connect_to_sftp() {
    $host = get_option('wsftp_sftp_host', '');
    $port = get_option('wsftp_sftp_port', 22);
    $username = get_option('wsftp_sftp_username', '');
    $auth_method = get_option('wsftp_sftp_auth_method', 'password');p_add_log("Failed to open directory: $path");
    
    if (empty($host) || empty($username)) {
        wsftp_add_log("SFTP connection failed: Host and username must be provided.");
        return false;
    }
    
    // Connect to servern wsftp_list_directory($sftp, $path) {
    $connection = @ssh2_connect($host, $port);$handle = @opendir("ssh2.sftp://$sftp$path");closedir($handle);
    if (!$connection) {
        wsftp_add_log("SFTP connection failed: Could not connect to $host:$port");
        return false;);
    }
    
    // Authenticate
    $auth_success = false;
    e;sftp, $path) {
    if ($auth_method === 'password') {
        $password = wsftp_encrypt_decrypt(get_option('wsftp_sftp_password', ''), 'decrypt');
        if (empty($password)) {
            wsftp_add_log("SFTP authentication failed: No password provided");
            return false;
        }
        
        $auth_success = @ssh2_auth_password($connection, $username, $password);
    } else {
        $key_path = get_option('wsftp_sftp_private_key_path', '');
        if (empty($key_path)) {p_stat($sftp, $path);
            wsftp_add_log("SFTP authentication failed: No private key path provided");
            return false;tp://$sftp$remote_path", 'r');
        }f (!$stream) {
        
        $auth_success = @ssh2_auth_pubkey_file(
            $connection, 
            $username, 
            $key_path . '.pub', /**    $temp = @fopen($local_path, 'w');
            $key_path
        );
    }tion wsftp_download_file($sftp, $remote_path, $local_path) {    wsftp_add_log("Failed to create local file: $local_path");
    
    if (!$auth_success) {
        wsftp_add_log("SFTP authentication failed for user $username");_log("Failed to open remote file: $remote_path");
        return false;copy_to_stream($stream, $temp);
    }
    fclose($temp);
    return $connection;
}
stream);d_log("Failed to download file: $remote_path");
/** create local file: $local_path");
 * List directory contents via SFTP
 */
function wsftp_list_directory($sftp, $path) {
    $handle = @opendir("ssh2.sftp://$sftp$path");
    if (!$handle) {
        wsftp_add_log("Failed to open directory: $path");
        return array();
    } (!$result) {
        wsftp_add_log("Failed to download file: $remote_path");tion wsftp_get_folder_user_mappings($sftp, $base_path) {
    $files = array();
    while (false !== ($file = readdir($handle))) {
        $files[] = $file;tp, $base_path);
    }
    closedir($handle);
    sftp, "$base_path/$folder")) {
    return $files;
}

/**
 * Check if path is a directory via SFTP$mappings = array();    $user_id = wsftp_get_user_id_from_folder($folder);
 */
function wsftp_is_dir($sftp, $path) {
    $stat = @ssh2_sftp_stat($sftp, $path);
    if (!$stat) {der) {
        return false;    if ($folder === '.' || $folder === '..' || !wsftp_is_dir($sftp, "$base_path/$folder")) {
    }
           }
    return ($stat['mode'] & 0040000) == 0040000;        
} user
_id_from_folder($folder);
/**
 * Download file via SFTP
 */name
function wsftp_download_file($sftp, $remote_path, $local_path) {}$user = get_user_by('login', $folder_name);
    $stream = @fopen("ssh2.sftp://$sftp$remote_path", 'r');
    if (!$stream) {
        wsftp_add_log("Failed to open remote file: $remote_path");
        return false;
    }
    folder_name, $matches)) {
    $temp = @fopen($local_path, 'w');
    if (!$temp) {me) {
        fclose($stream);
        wsftp_add_log("Failed to create local file: $local_path");
        return false;
    }eturn $user->ID;
    
    $result = stream_copy_to_stream($stream, $temp);// Third try: Look for user meta that might store their folder name
    fclose($stream);"user_123" where 123 is the user ID
    fclose($temp); $folder_name, $matches)) {pare(
    
    if (!$result) {     $user = get_user_by('id', $user_id);      WHERE (meta_key = 'sftp_folder' OR meta_key = 'user_folder' OR meta_key LIKE %s) 
        wsftp_add_log("Failed to download file: $remote_path");
        return false;         return $user->ID;     '%folder%',
    }
    
    return true;
}t store their folder name

/**
 * Get all user folders from SFTP    "SELECT user_id FROM {$wpdb->usermeta} 
 */ %s) 
function wsftp_get_folder_user_mappings($sftp, $base_path) {
    $mappings = array();
    
    $user_folders = wsftp_list_directory($sftp, $base_path);
    foreach ($user_folders as $folder) {
        if ($folder === '.' || $folder === '..' || !wsftp_is_dir($sftp, "$base_path/$folder")) {user_id, $product_key, $folder_name) {
            continue;
        }
        
        // Try to match folder name with a userension from filename to use as product name
        $user_id = wsftp_get_user_id_from_folder($folder);
        if ($user_id) {
            $mappings[$folder] = $user_id;
        }
    }
    , $product_key, $folder_name) {
    return $mappings;file extensiont_price = get_option('wsftp_default_price', 9.99);
}sion = pathinfo($filename, PATHINFO_EXTENSION);
ettings
/**lename to use as product namen('wsftp_product_status', 'draft');
 * Get user ID from folder nameproduct_name = pathinfo($filename, PATHINFO_FILENAME);
 */
function wsftp_get_user_id_from_folder($folder_name) {);
    // First try: Exact match with username   $product_name = ucwords($product_name);   
    $user = get_user_by('login', $folder_name); Create or get the category with the folder name
    if ($user) { // Default price from settings $category = get_term_by('name', $folder_name, 'product_cat');
        return $user->ID;price', 9.99);
    }
    
    // Second try: Match folder name pattern like "user_123" where 123 is the user ID_option('wsftp_product_status', 'draft');category_id)) {
    if (preg_match('/user[_\-](\d+)/i', $folder_name, $matches)) {
        $user_id = intval($matches[1]);$is_image = in_array(strtolower($file_extension), array('jpg', 'jpeg', 'png', 'gif', 'webp'));        return 'error';
        $user = get_user_by('id', $user_id);
        if ($user) {
            return $user->ID;
        }
    }
        // Create the category if it doesn't exist
    // Third try: Look for user meta that might store their folder name
    global $wpdb;
    $result = $wpdb->get_var($wpdb->prepare(        wsftp_add_log("Error creating category for folder: $folder_name");$product->set_name($product_name);
        "SELECT user_id FROM {$wpdb->usermeta} 
         WHERE (meta_key = 'sftp_folder' OR meta_key = 'user_folder' OR meta_key LIKE %s) 
         AND meta_value = %s LIMIT 1",    $category_id = $category_id['term_id'];$product->set_price($default_price);
        '%folder%',
        $folder_name
    ));    }    
    
    if ($result) {
        return intval($result);duct();_data('_wsftp_user_id', $user_id);
    }
    
    return false;
}
rice($default_price);oadable(true);
/**uct->set_category_ids(array($category_id)); // Set the category ID
 * Create a product for a file
 */asic user metadata_id = $product->save();
function wsftp_create_product($filename, $file_path, $user_id, $product_key, $folder_name) {duct_key', $product_key);
    // Get file extension);
    $file_extension = pathinfo($filename, PATHINFO_EXTENSION);update_meta_data('_wsftp_source_file', $filename);s_image) {
    _data('_wsftp_folder_name', $folder_name);t image
    // Remove extension from filename to use as product name
    $product_name = pathinfo($filename, PATHINFO_FILENAME);
    $product_name = str_replace('-', ' ', $product_name);
    $product_name = str_replace('_', ' ', $product_name);
    $product_name = ucwords($product_name);
    
    // Default price from settings
    $default_price = get_option('wsftp_default_price', 9.99);name, $file_path);
    
    // Get product status from settings
    $product_status = get_option('wsftp_product_status', 'draft');file_path, $filename);nt_id);
    
    $is_image = in_array(strtolower($file_extension), array('jpg', 'jpeg', 'png', 'gif', 'webp'));
    $is_downloadable = !$is_image;            wsftp_attach_preview_to_product($product_id, $attachment_id);    
    
    // Create or get the category with the folder name
    $category = get_term_by('name', $folder_name, 'product_cat'); downloadable file> $product_id,
    if (!$category) {ment_id = wsftp_update_downloadable_file($product_id, $filename, $file_path);uthor' => $user_id
        // Create the category if it doesn't exist
        $category_id = wp_insert_term($folder_name, 'product_cat');($attachment_id) {
        if (is_wp_error($category_id)) {attach_preview_to_product($product_id, $attachment_id);d';
            wsftp_add_log("Error creating category for folder: $folder_name");
            return 'error';
        }
        $category_id = $category_id['term_id'];or explicitly to match folder owner
    } else {
        $category_id = $category->term_id;
    }
    
    // Create new product
    $product = new WC_Product();
    $product->set_name($product_name);
    $product->set_status($product_status); filename
    $product->set_catalog_visibility('visible');
    $product->set_price($default_price);
    $product->set_regular_price($default_price);
    $product->set_category_ids(array($category_id)); // Set the category ID
    
    // Set basic user metadata
    $product->update_meta_data('_wsftp_product_key', $product_key);
    $product->update_meta_data('_wsftp_user_id', $user_id);
    $product->update_meta_data('_wsftp_source_file', $filename);
    $product->update_meta_data('_wsftp_folder_name', $folder_name);attachment
    name($upload_dir['path'], $image_name);
    if ($is_downloadable) {d'           => $upload_dir['url'] . '/' . $filename,
        $product->set_downloadable(true);
    }h'] . '/' . $filename;_replace('/\.[^.]+$/', '', $image_name),
    copy($image_path, $new_file);    'post_content'   => '',
    $product_id = $product->save();
    
    if ($product_id) {    $filetype = wp_check_filetype($filename, null);    
        if ($is_image) {  // Insert the attachment
            // Set product imaget_id);
            $attachment_id = wsftp_update_product_image($product_id, $file_path, $filename);
            // Usar o mesmo arquivo como preview
            if ($attachment_id) {['type'],includes/image.php');
                wsftp_attach_preview_to_product($product_id, $attachment_id);age_name), $new_file);
            }',data($attach_id, $attach_data);
        } elseif ($is_downloadable) {
            // Add downloadable file);// Set as product image
            $attachment_id = wsftp_update_downloadable_file($product_id, $filename, $file_path);
            
            // Usar o mesmo arquivo como previewment($attachment, $new_file, $product_id);
            if ($attachment_id) {
                wsftp_attach_preview_to_product($product_id, $attachment_id);
            }
        }tach_data = wp_generate_attachment_metadata($attach_id, $new_file);te downloadable file for product
        ata);
        // Set post author explicitly to match folder ownerd, $filename, $file_path) {
        wp_update_post(array(
            'ID' => $product_id,);
            'post_author' => $user_id
        ));
        
        return 'created';
    }
    
    return 'error';
}e_file($product_id, $filename, $file_path) {ot exists

/**
 * Update product image
 */
function wsftp_update_product_image($product_id, $image_path, $image_name) {}}
    $upload_dir = wp_upload_dir();
    
    // Create unique filename$safe_filename = sanitize_file_name($filename);if (!file_exists($downloads_dir . '/index.html')) {
    $filename = wp_unique_filename($upload_dir['path'], $image_name);
    sts
    // Copy file to uploads directory
    $new_file = $upload_dir['path'] . '/' . $filename; $downloads_dir = $upload_dir['basedir'] . '/woocommerce_uploads';         fclose($file);
    copy($image_path, $new_file);
    
    // Check the type of file
    $filetype = wp_check_filetype($filename, null);
    // Create index.html to prevent directory listing$new_file_path = $downloads_dir . '/' . $safe_filename;
    // Prepare an array of post data for the attachmentdownloads_dir . '/index.html')) {new_file_path);
    $attachment = array(downloads_dir . '/index.html', 'w');
        'guid'           => $upload_dir['url'] . '/' . $filename,   if ($file) {/ Create download URL
        'post_mime_type' => $filetype['type'],
        'post_title'     => preg_replace('/\.[^.]+$/', '', $image_name),
        'post_content'   => '',
        'post_status'    => 'inherit'}$download_id = md5($file_url);
    );
    
    // Insert the attachment
    $attach_id = wp_insert_attachment($attachment, $new_file, $product_id);h);
    
    // Generate metadata for the attachment
    require_once(ABSPATH . 'wp-admin/includes/image.php');merce_uploads/' . $safe_filename;
    $attach_data = wp_generate_attachment_metadata($attach_id, $new_file);
    wp_update_attachment_metadata($attach_id, $attach_data);
    
    // Set as product image
    set_post_thumbnail($product_id, $attach_id);
     array(iry(-1); // Never expires
    return $attach_id;
}name' => $filename,
   'file' => $file_url,/ Também cria uma mídia para o arquivo para poder usar como preview
/**
 * Update downloadable file for product
 */
function wsftp_update_downloadable_file($product_id, $filename, $file_path) {
    $product = wc_get_product($product_id);ads($downloads);     => $file_url,
    _limit(-1); // Unlimited downloadse' => $filetype['type'],
    if (!$product) {
        return false;
    }
    o arquivo para poder usar como preview
    // Create safe filename without spacesfiletype($safe_filename, null);
    $safe_filename = sanitize_file_name($filename);$file_url = $upload_dir['baseurl'] . '/woocommerce_uploads/' . $safe_filename;    // Insert the attachment
    vo que pode ser anexadoment($attachment, $new_file_path, $product_id);
    // Create uploads folder if not exists
    $upload_dir = wp_upload_dir();
    $downloads_dir = $upload_dir['basedir'] . '/woocommerce_uploads';
    if (!file_exists($downloads_dir)) {      'guid'           => $file_url,      require_once(ABSPATH . 'wp-admin/includes/image.php');
        wp_mkdir_p($downloads_dir);        'post_mime_type' => $filetype['type'],        require_once(ABSPATH . 'wp-admin/includes/media.php');
    }ame),, $new_file_path);
    ta($attach_id, $attach_data);
    // Create index.html to prevent directory listing
    if (!file_exists($downloads_dir . '/index.html')) {
        $file = @fopen($downloads_dir . '/index.html', 'w');
        if ($file) {attachment
            fwrite($file, '');wp_insert_attachment($attachment, $new_file_path, $product_id);
            fclose($file);    return false;
        }
    }
    
    // Copy file to downloads directory
    $new_file_path = $downloads_dir . '/' . $safe_filename;attachment_metadata($attach_id, $new_file_path);
    copy($file_path, $new_file_path);ch_data);chment_id) {
    
    // Create download URLview attachment.");
    $file_url = $upload_dir['baseurl'] . '/woocommerce_uploads/' . $safe_filename;
    
    // Set download file
    $download_id = md5($file_url);
    $downloads = array(); = get_option('wsftp_acf_field_group', 'product_details');
    
    $downloads[$download_id] = array(
        'id' => $download_id,
        'name' => $filename,
        'file' => $file_url,
    );
    
    // Update product data
    $product->set_downloadable(true);
    $product->set_downloads($downloads);
    $product->set_download_limit(-1); // Unlimited downloadsduct_details');eld_key for product #{$product_id}");
    $product->set_download_expiry(-1); // Never expireson('wsftp_acf_preview_field', 'preview_file');
    $product->save(); Try updating directly by field name
    ry {       update_field($acf_field_name, $attachment_id, $product_id);
    // Também cria uma mídia para o arquivo para poder usar como previewachment ID for this previewa post meta as backup
    $filetype = wp_check_filetype($safe_filename, null);
    if (!empty($filetype['type'])) {
        // Prepare an array of post data for the attachment
        $attachment = array(     $field_key = wsftp_get_acf_field_key($acf_field_name, $acf_field_group);     
            'guid'           => $file_url,
            'post_mime_type' => $filetype['type'],tachment_id, $product_id);alue_cache')) {
            'post_title'     => preg_replace('/\.[^.]+$/', '', $filename),d}");
            'post_content'   => '',
            'post_status'    => 'inherit'
        );
               // Update via post meta as backup catch (Exception $e) {
        // Insert the attachment        update_post_meta($product_id, $acf_field_name, $attachment_id);    wsftp_add_log("Error attaching preview: " . $e->getMessage());
        $attach_id = wp_insert_attachment($attachment, $new_file_path, $product_id);
        
        if (!is_wp_error($attach_id)) {
            // Generate metadata for the attachment    // Try to force ACF cache refresh
            require_once(ABSPATH . 'wp-admin/includes/image.php'); {
            require_once(ABSPATH . 'wp-admin/includes/media.php');
            $attach_data = wp_generate_attachment_metadata($attach_id, $new_file_path);
            wp_update_attachment_metadata($attach_id, $attach_data);
            
            return $attach_id;
        }
    }
    
    return false;
}('wsftp_acf_preview_field', 'preview_file');

/**
 * Attach a preview file to a product using ACF
 */
function wsftp_attach_preview_to_product($product_id, $attachment_id) {
    if (!function_exists('update_field')) { Custom Fields not activated. Cannot fix preview files.");
        wsftp_add_log("Advanced Custom Fields not activated. Cannot set preview attachment.");
        return false;
    }
    
    // Get ACF field information
    $acf_field_group = get_option('wsftp_acf_field_group', 'product_details');
    $acf_field_name = get_option('wsftp_acf_preview_field', 'preview_file');t all products created by this pluginpreview_id = get_post_meta($product_id, '_wsftp_preview_attachment_id', true);
    
    try {1,preview_id)) {
        // Store the attachment ID for this previewp_product_key',t_post($preview_id);
        update_post_meta($product_id, '_wsftp_preview_attachment_id', $attachment_id);
        achment->post_type == 'attachment') {
        // Try to update the ACF field
        $field_key = wsftp_get_acf_field_key($acf_field_name, $acf_field_group);
        if ($field_key) {
            update_field($field_key, $attachment_id, $product_id); foreach ($products as $product_id) {                 update_field($field_key, $preview_id, $product_id);
            wsftp_add_log("ACF field updated via field key: $field_key for product #{$product_id}");
        } else {     $preview_id = get_post_meta($product_id, '_wsftp_preview_attachment_id', true);                 update_field($acf_field_name, $preview_id, $product_id);
            // Try updating directly by field name
            update_field($acf_field_name, $attachment_id, $product_id);
            // Update via post meta as backup
            update_post_meta($product_id, $acf_field_name, $attachment_id);ill exists
            wsftp_add_log("ACF field updated by name: $acf_field_name for product #{$product_id}");
        }ix ACF field connectionog("Fixed ACF preview for product #{$product_id}");
        
        // Try to force ACF cache refresh
        if (function_exists('acf_flush_value_cache')) {                update_field($field_key, $preview_id, $product_id);        // Se não tem preview ID, tenta usar a imagem em destaque ou o arquivo de download
            acf_flush_value_cache($product_id);
        }$acf_field_name, $preview_id, $product_id);et_post_thumbnail_id($product_id);
        
        return true;
    } catch (Exception $e) {_name, $preview_id);
        wsftp_add_log("Error attaching preview: " . $e->getMessage());
        return false;            $fixed_count++;                $fixed_count++;
    }_add_log("Fixed ACF preview for product #{$product_id}");sftp_add_log("Fixed ACF preview using featured image for product #{$product_id}");
}        }            }
{
/**
 * Diagnose and fix ACF preview files
 */    $feature_image_id = get_post_thumbnail_id($product_id);        if (!empty($downloads)) {
function wsftp_diagnose_and_fix_acf_previews() {
    if (!function_exists('update_field')) {
        wsftp_add_log("Advanced Custom Fields not activated. Cannot fix preview files.");
        return 0;
    }
    ew using featured image for product #{$product_id}");)) {
    $acf_field_group = get_option('wsftp_acf_field_group', 'product_details');
    $acf_field_name = get_option('wsftp_acf_preview_field', 'preview_file');s_downloadable()) {
    r o arquivo de download e criar uma nova mídia para eleia um novo anexo
    // Get all products created by this plugin
    $products = wc_get_products([        => $file_url,
        'limit' => -1,
        'meta_key' => '_wsftp_product_key',et_file();     => basename($file_path),
        'return' => 'ids',    // Só prossegue se o arquivo estiver no servidor                'post_content'   => '',
    ]);l()) === 0) {=> 'inherit'
    
    $fixed_count = 0;        if (file_exists($file_path)) {            
    letype = wp_check_filetype(basename($file_path), null);tach_id = wp_insert_attachment($attachment, $file_path, $product_id);
    foreach ($products as $product_id) {
        // Check if product has a preview attachment ID but ACF field is empty               // Cria um novo anexo                   // Generate metadata for the attachment
        $preview_id = get_post_meta($product_id, '_wsftp_preview_attachment_id', true);
        
        if (!empty($preview_id)) { $filetype['type'],enerate_attachment_metadata($attach_id, $file_path);
            $attachment = get_post($preview_id);
            // Check if attachment still exists                    'post_content'   => '',                    
            if ($attachment && $attachment->post_type == 'attachment') { 'inherit'eview_to_product($product_id, $attach_id)) {
                // Try to fix ACF field connection
                $field_key = wsftp_get_acf_field_key($acf_field_name, $acf_field_group);
                if ($field_key) {_id = wp_insert_attachment($attachment, $file_path, $product_id);
                    update_field($field_key, $preview_id, $product_id);
                } else {               require_once(ABSPATH . 'wp-admin/includes/image.php');       }
                    update_field($acf_field_name, $preview_id, $product_id);
                };
                // Update post meta as backuptadata($attach_id, $attach_data);
                update_post_meta($product_id, $acf_field_name, $preview_id);
                ;
                $fixed_count++; preview using download file for product #{$product_id}");
                wsftp_add_log("Fixed ACF preview for product #{$product_id}");
            }
        } else {
            // Se não tem preview ID, tenta usar a imagem em destaque ou o arquivo de download
            $product = wc_get_product($product_id);les
            $feature_image_id = get_post_thumbnail_id($product_id);
            
            if ($feature_image_id) {
                // Usa a imagem em destaque como preview
                if (wsftp_attach_preview_to_product($product_id, $feature_image_id)) {t scan
                    $fixed_count++;
                    wsftp_add_log("Fixed ACF preview using featured image for product #{$product_id}");
                }
            } else if ($product && $product->is_downloadable()) {
                // Tenta encontrar o arquivo de download e criar uma nova mídia para ele
                $downloads = $product->get_downloads();products($current_files, $processed_files) {
                if (!empty($downloads)) {
                    $download = reset($downloads);
                    $file_url = $download->get_file();but not found in current scan
                    
                    // Só prossegue se o arquivo estiver no servidor
                    if (strpos($file_url, site_url()) === 0) {
                        $file_path = str_replace(site_url('/'), ABSPATH, $file_url);
                        
                        if (file_exists($file_path)) {
                            $filetype = wp_check_filetype(basename($file_path), null);
                            
                            // Cria um novo anexo
                            $attachment = array(
                                'guid'           => $file_url,
                                'post_mime_type' => $filetype['type'],
                                'post_title'     => basename($file_path),id = $products[0];count++;
                                'post_content'   => '',
                                'post_status'    => 'inherit'/ Delete the product
                            ); true);
                            
                            $attach_id = wp_insert_attachment($attachment, $file_path, $product_id);        // Remove from processed listif ($deleted_count > 0) {
                            _files[$product_key]);_processed_files', $processed_files);
                            if (!is_wp_error($attach_id)) {
                                // Generate metadata for the attachment            // Update processed files list after removal    
                                require_once(ABSPATH . 'wp-admin/includes/image.php');
                                require_once(ABSPATH . 'wp-admin/includes/media.php');t_key");
                                $attach_data = wp_generate_attachment_metadata($attach_id, $file_path);
                                wp_update_attachment_metadata($attach_id, $attach_data);
                                
                                if (wsftp_attach_preview_to_product($product_id, $attach_id)) {
                                    $fixed_count++;
                                    wsftp_add_log("Fixed ACF preview using download file for product #{$product_id}");
                                }
                            }
                        }
                    }
                }
            }
        }
    }ey($field_name, $field_group) {
    s') || !function_exists('acf_get_fields')) {
    return $fixed_count;return false;ach ($products as $product_id) {
}roduct_key', true);

/**et_field_groups();duto tem product_key mas não está na lista de processados
 * Remove products for deleted files
 */
function wsftp_remove_deleted_products($current_files, $processed_files) {$fields = acf_get_fields($group['key']);$count++;
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
        
        if (!empty($products)) {) { plugin
            $product_id = $products[0];    $processed_files = get_option('wsftp_processed_files', array());register_activation_hook(__FILE__, function() {
            
            // Delete the product
            wp_delete_post($product_id, true);0);
            
            // Remove from processed list
            unset($processed_files[$product_key]);alse) {
            
            // Update processed files list after removal
            $deleted_count++;
            wsftp_add_log("Deleted product #$product_id for removed file: $product_key");
        } agora
    }p_product_key', true);
     !isset($processed_files[$product_key])) {
    // Update processed files list after removal
    if ($deleted_count > 0) {
        update_option('wsftp_processed_files', $processed_files);
    }
    tents($temp_dir . '/index.html', '');
    return $deleted_count;
}
    wsftp_add_log("Repaired processed files array: added $count missing products to the registry");
// Set up delayed scan callback
add_action('wsftp_delayed_scan_hook', 'wsftp_scan_folders');

// Hook for scheduled scan
add_action('wsftp_scan_hook', 'wsftp_scan_folders');
 up scheduled scan
// Hook for page loads to trigger scan based on interval
add_action('shutdown', function() {    // Schedule event if not already scheduled    delete_transient('wsftp_scanning_lock');
    // Only proceed for normal page loads, not admin-ajax or API calls) {e');
    if (defined('DOING_AJAX') || defined('REST_REQUEST') || is_admin()) {
        return;
    }
    
    $last_scan = get_option('wsftp_last_scan_time', 0);t up delayed scan callbackf (!wp_next_scheduled('wsftp_scan_hook')) {
    $interval = get_option('wsftp_scan_interval', 60); // in minutesn_folders');tp_scan_hook');
    
    if (time() - $last_scan >= ($interval * 60)) {
        // Use a promise to run the scan in the backgroundction('wsftp_scan_hook', 'wsftp_scan_folders');
        wp_schedule_single_event(time(), 'wsftp_delayed_scan_hook');for scheduled scan
    }
});

// Add a setting to enable/disable the plugin)) {
register_activation_hook(__FILE__, function() {ajax or API calls
    // Set default options on activation) || is_admin()) {
    if (get_option('wsftp_last_scan_time') === false) {rn;
        update_option('wsftp_last_scan_time', 0);
    }tes
    
    if (get_option('wsftp_processed_files') === false) {e() - $last_scan >= ($interval * 60)) {al = get_option('wsftp_scan_interval', 60); // in minutes
        update_option('wsftp_processed_files', array());e background
    }        wp_schedule_single_event(time(), 'wsftp_delayed_scan_hook');    if (time() - $last_scan >= ($interval * 60)) {
        }        // Use a promise to run the scan in the background
    // Create necessary folders});        wp_schedule_single_event(time(), 'wsftp_delayed_scan_hook');
    $upload_dir = wp_upload_dir();    }
    $temp_dir = $upload_dir['basedir'] . '/wsftp_temp';// Add a setting to enable/disable the plugin});
    register_activation_hook(__FILE__, function() {
    if (!file_exists($temp_dir)) {    // Set default options on activation/**
        wp_mkdir_p($temp_dir);    if (get_option('wsftp_last_scan_time') === false) { * Get ACF field key by field name and group
                update_option('wsftp_last_scan_time', 0); */
        // Create index.html and .htaccess files    }function wsftp_get_acf_field_key($field_name, $field_group) {
        file_put_contents($temp_dir . '/index.html', '');
        file_put_contents($temp_dir . '/.htaccess', 'Deny from all');    if (get_option('wsftp_processed_files') === false) {        return false;
    }        update_option('wsftp_processed_files', array());    }
});    }    

// Clean up on deactivation    // Create necessary folders    foreach ($groups as $group) {
register_deactivation_hook(__FILE__, function() {    $upload_dir = wp_upload_dir();        if ($group['title'] === $field_group) {
    wp_clear_scheduled_hook('wsftp_scan_hook');    $temp_dir = $upload_dir['basedir'] . '/wsftp_temp';            $fields = acf_get_fields($group['key']);
    wp_clear_scheduled_hook('wsftp_delayed_scan_hook');                foreach ($fields as $field) {
        if (!file_exists($temp_dir)) {                if ($field['name'] === $field_name) {
    // Remove any existing scanning locks        wp_mkdir_p($temp_dir);                    return $field['key'];
    delete_transient('wsftp_scanning_lock');                        }
    delete_transient('wsftp_scanning_lock_time');        // Create index.html and .htaccess files            }
});