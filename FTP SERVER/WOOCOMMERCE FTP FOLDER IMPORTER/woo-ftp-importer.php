<?php
/**
 * Plugin Name: WooCommerce FTP Folder Importer
 * Plugin URI: https://yourwebsite.com/
 * Description: Automatically import products from existing user FTP folders with access restrictions
 * Version: 1.0.0
 * Author: DevSpace
 * Text Domain: woo-ftp-importer
 * Domain Path: /languages
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
        echo '<div class="error"><p>' . __('WooCommerce FTP Folder Importer requires WooCommerce to be installed and active.', 'woo-ftp-importer') . '</p></div>';
    });
    return;
}

// Define constants
define('WFTP_VERSION', '1.0.0');
define('WFTP_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('WFTP_PLUGIN_URL', plugin_dir_url(__FILE__));

// Add admin menu
add_action('admin_menu', 'wftp_add_admin_menu', 10);

function wftp_add_admin_menu() {
    add_menu_page(
        __('WooCommerce FTP Importer', 'woo-ftp-importer'),
        __('FTP Importer', 'woo-ftp-importer'),
        'manage_options', // Mudado para manage_options
        'woo-ftp-importer',
        'wftp_admin_page',
        'dashicons-upload',
        30 // Posição mais alta no menu
    );
}

// Create admin page
function wftp_admin_page() {
    // Save settings
    if (isset($_POST['wftp_save_settings']) && wp_verify_nonce($_POST['wftp_nonce'], 'wftp_save_settings')) {
        $base_folder_path = sanitize_text_field($_POST['wftp_base_folder_path']);
        update_option('wftp_base_folder_path', $base_folder_path);
        
        $scan_interval = absint($_POST['wftp_scan_interval']);
        if ($scan_interval < 1) $scan_interval = 1;
        update_option('wftp_scan_interval', $scan_interval);
        
        $default_price = floatval($_POST['wftp_default_price']);
        update_option('wftp_default_price', $default_price);
        
        $product_status = sanitize_text_field($_POST['wftp_product_status']);
        update_option('wftp_product_status', $product_status);
        
        $log_enabled = isset($_POST['wftp_log_enabled']) ? 1 : 0;
        update_option('wftp_log_enabled', $log_enabled);
        
        $remove_deleted_files = isset($_POST['wftp_remove_deleted_files']) ? 1 : 0;
        update_option('wftp_remove_deleted_files', $remove_deleted_files);
        
        echo '<div class="notice notice-success is-dismissible"><p>' . __('Settings saved successfully.', 'woo-ftp-importer') . '</p></div>';
    }
    
    // Get current settings
    $base_folder_path = get_option('wftp_base_folder_path', ABSPATH . 'wp-content/uploads/user_folders');
    $scan_interval = get_option('wftp_scan_interval', 1);
    $default_price = get_option('wftp_default_price', 9.99);
    $product_status = get_option('wftp_product_status', 'draft'); // Default to draft for security
    $log_enabled = get_option('wftp_log_enabled', 1);
    $remove_deleted_files = get_option('wftp_remove_deleted_files', 1);
    
    // Manual scan trigger
    if (isset($_POST['wftp_manual_scan']) && wp_verify_nonce($_POST['wftp_nonce'], 'wftp_manual_scan')) {
        wftp_scan_folders();
        echo '<div class="notice notice-success is-dismissible"><p>' . __('Manual scan completed. Check the log for details.', 'woo-ftp-importer') . '</p></div>';
    }
    
    // Get last scan time
    $last_scan = get_option('wftp_last_scan_time', 0);
    $last_scan_date = ($last_scan > 0) ? date_i18n('Y-m-d H:i:s', $last_scan) : __('Never', 'woo-ftp-importer');
    $next_scan = ($last_scan > 0) ? date_i18n('Y-m-d H:i:s', $last_scan + ($scan_interval * 60)) : __('After first page load', 'woo-ftp-importer');
    
    // Get folder mappings
    $folder_mappings = wftp_get_folder_user_mappings();
    
    // Display the form
    ?>
    <div class="wrap">
        <h1><?php _e('WooCommerce FTP Folder Importer', 'woo-ftp-importer'); ?></h1>
        
        <h2><?php _e('Settings', 'woo-ftp-importer'); ?></h2>
        <form method="post" action="">
            <?php wp_nonce_field('wftp_save_settings', 'wftp_nonce'); ?>
            
            <table class="form-table">
                <tr valign="top">
                    <th scope="row">
                        <label for="wftp_base_folder_path"><?php _e('Base Folder Path', 'woo-ftp-importer'); ?></label>
                    </th>
                    <td>
                        <input type="text" id="wftp_base_folder_path" name="wftp_base_folder_path" class="regular-text" 
                            value="<?php echo esc_attr($base_folder_path); ?>" />
                        <p class="description">
                            <?php _e('Enter the absolute server path to the base folder containing user FTP folders', 'woo-ftp-importer'); ?>
                        </p>
                    </td>
                </tr>
                <tr valign="top">
                    <th scope="row">
                        <label for="wftp_scan_interval"><?php _e('Scan Interval (minutes)', 'woo-ftp-importer'); ?></label>
                    </th>
                    <td>
                        <input type="number" id="wftp_scan_interval" name="wftp_scan_interval" min="1" 
                            value="<?php echo esc_attr($scan_interval); ?>" />
                    </td>
                </tr>
                <tr valign="top">
                    <th scope="row">
                        <label for="wftp_default_price"><?php _e('Default Product Price', 'woo-ftp-importer'); ?></label>
                    </th>
                    <td>
                        <input type="number" id="wftp_default_price" name="wftp_default_price" min="0" step="0.01" 
                            value="<?php echo esc_attr($default_price); ?>" />
                        <p class="description">
                            <?php _e('Default price for new products when no price information is available', 'woo-ftp-importer'); ?>
                        </p>
                    </td>
                </tr>
                <tr valign="top">
                    <th scope="row">
                        <label for="wftp_product_status"><?php _e('Product Status', 'woo-ftp-importer'); ?></label>
                    </th>
                    <td>
                        <select id="wftp_product_status" name="wftp_product_status">
                            <option value="publish" <?php selected($product_status, 'publish'); ?>><?php _e('Published', 'woo-ftp-importer'); ?></option>
                            <option value="draft" <?php selected($product_status, 'draft'); ?>><?php _e('Draft', 'woo-ftp-importer'); ?></option>
                            <option value="pending" <?php selected($product_status, 'pending'); ?>><?php _e('Pending Review', 'woo-ftp-importer'); ?></option>
                            <option value="private" <?php selected($product_status, 'private'); ?>><?php _e('Private', 'woo-ftp-importer'); ?></option>
                        </select>
                        <p class="description">
                            <?php _e('Default status for newly created products. Use "Draft" to review before publishing.', 'woo-ftp-importer'); ?>
                        </p>
                    </td>
                </tr>
                <tr valign="top">
                    <th scope="row">
                        <label for="wftp_remove_deleted_files"><?php _e('Remove Deleted Products', 'woo-ftp-importer'); ?></label>
                    </th>
                    <td>
                        <input type="checkbox" id="wftp_remove_deleted_files" name="wftp_remove_deleted_files" value="1" <?php checked($remove_deleted_files, 1); ?> />
                        <p class="description">
                            <?php _e('Automatically delete products when their source files are removed', 'woo-ftp-importer'); ?>
                        </p>
                    </td>
                </tr>
                <tr valign="top">
                    <th scope="row">
                        <label for="wftp_log_enabled"><?php _e('Enable Logging', 'woo-ftp-importer'); ?></label>
                    </th>
                    <td>
                        <input type="checkbox" id="wftp_log_enabled" name="wftp_log_enabled" value="1" <?php checked($log_enabled, 1); ?> />
                        <p class="description">
                            <?php _e('Log import activities for debugging', 'woo-ftp-importer'); ?>
                        </p>
                    </td>
                </tr>
            </table>
            
            <p class="submit">
                <input type="submit" name="wftp_save_settings" class="button-primary" value="<?php _e('Save Settings', 'woo-ftp-importer'); ?>" />
            </p>
        </form>
        
        <div class="wftp-scan-status" style="margin-bottom: 20px; padding: 15px; background: #f0f0f1; border-left: 4px solid #2271b1;" data-last-scan="<?php echo esc_attr($last_scan); ?>">
            <h3 style="margin-top: 0;"><?php _e('Automatic Scanning Status', 'woo-ftp-importer'); ?></h3>
            <p><strong><?php _e('Last scan:', 'woo-ftp-importer'); ?></strong> <?php echo esc_html($last_scan_date); ?></p>
            <p><strong><?php _e('Next scan:', 'woo-ftp-importer'); ?></strong> <?php echo esc_html($next_scan); ?></p>
            <p><?php _e('The scanner will automatically run once every', 'woo-ftp-importer'); ?> <?php echo esc_html($scan_interval); ?> <?php _e('minute(s) when your site receives visitors.', 'woo-ftp-importer'); ?></p>
        </div>
        
        <h2><?php _e('Manual Operation', 'woo-ftp-importer'); ?></h2>
        <form method="post" action="">
            <?php wp_nonce_field('wftp_manual_scan', 'wftp_nonce'); ?>
            <p>
                <input type="submit" name="wftp_manual_scan" class="button-secondary" value="<?php _e('Run Manual Scan Now', 'woo-ftp-importer'); ?>" />
            </p>
        </form>
        
        <h2><?php _e('Detected User Folders', 'woo-ftp-importer'); ?></h2>
        <div class="wftp-folder-mappings" style="margin-bottom: 20px; max-height: 300px; overflow-y: auto; border: 1px solid #ccc; padding: 10px; background: #f9f9f9;">
            <?php if (empty($folder_mappings)): ?>
                <p><?php _e('No user folders detected. Make sure your base folder path is correct.', 'woo-ftp-importer'); ?></p>
            <?php else: ?>
                <table class="widefat striped">
                    <thead>
                        <tr>
                            <th><?php _e('Folder Name', 'woo-ftp-importer'); ?></th>
                            <th><?php _e('Associated User', 'woo-ftp-importer'); ?></th>
                            <th><?php _e('Products', 'woo-ftp-importer'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($folder_mappings as $folder => $user_id): 
                            $user = get_user_by('id', $user_id);
                            $product_count = wftp_count_user_products($user_id);
                            ?>
                        <tr>
                            <td><?php echo esc_html($folder); ?></td>
                            <td><?php echo $user ? esc_html($user->display_name) . ' (' . $user->user_login . ')' : __('Unknown User', 'woo-ftp-importer'); ?></td>
                            <td><?php echo $product_count; ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
        
        <h2><?php _e('Import Log', 'woo-ftp-importer'); ?></h2>
        <div class="wftp-log-container" style="max-height: 300px; overflow-y: auto; border: 1px solid #ccc; padding: 10px; background: #f9f9f9;">
            <?php
            $log_entries = get_option('wftp_import_log', array());
            if (empty($log_entries)) {
                echo '<p>' . __('No import logs available.', 'woo-ftp-importer') . '</p>';
            } else {
                echo '<ul style="margin-top: 0;">';
                foreach (array_reverse($log_entries) as $log) {
                    echo '<li><strong>[' . esc_html($log['time']) . ']</strong> ' . esc_html($log['message']) . '</li>';
                }
                echo '</ul>';
                
                // Add clear log button
                ?>
                <form method="post" action="">
                    <?php wp_nonce_field('wftp_clear_log', 'wftp_nonce'); ?>
                    <input type="submit" name="wftp_clear_log" class="button-secondary" value="<?php _e('Clear Log', 'woo-ftp-importer'); ?>" />
                </form>
                <?php
            }
            
            // Clear log action
            if (isset($_POST['wftp_clear_log']) && wp_verify_nonce($_POST['wftp_nonce'], 'wftp_clear_log')) {
                delete_option('wftp_import_log');
                echo '<script>location.reload();</script>';
            }
            ?>
        </div>
        
        <h2><?php _e('How It Works', 'woo-ftp-importer'); ?></h2>
        <div class="wftp-help">
            <p><?php _e('This plugin scans user folders for files and creates products from them:', 'woo-ftp-importer'); ?></p>
            <ul>
                <li><?php _e('Each user\'s folder is automatically mapped to their WordPress account', 'woo-ftp-importer'); ?></li>
                <li><?php _e('Users can only see products created from their own folders', 'woo-ftp-importer'); ?></li>
                <li><?php _e('Products start in the selected status (Draft/Pending/etc.) and are only visible to everyone when published', 'woo-ftp-importer'); ?></li>
                <li><?php _e('Image files will be used as the product image', 'woo-ftp-importer'); ?></li>
                <li><?php _e('Other files will be imported as downloadable products', 'woo-ftp-importer'); ?></li>
                <li><?php _e('The filename will be used as the product name', 'woo-ftp-importer'); ?></li>
                <li><strong><?php _e('Products will be automatically removed when their source files are deleted (if enabled)', 'woo-ftp-importer'); ?></strong></li>
            </ul>
            
            <h3><?php _e('Example folder structure:', 'woo-ftp-importer'); ?></h3>
            <pre style="background: #f1f1f1; padding: 10px;">
base_folder/
  ├── user1_folder/           # Folder automatically mapped to user1
  │   ├── product1.jpg        # Will be imported as a product with image
  │   └── product2.pdf        # Will be imported as a downloadable product
  └── user2_folder/           # Folder automatically mapped to user2
      ├── red-t-shirt.png     # Will be imported as a product with image
      └── blue-jeans.jpg      # Will be imported as a product with image
            </pre>
        </div>
    </div>
    <?php
}

/**
 * Get the folder to user ID mapping
 */
function wftp_get_folder_user_mappings() {
    $base_folder = get_option('wftp_base_folder_path', ABSPATH . 'wp-content/uploads/user_folders');
    $mappings = array();
    
    if (!is_dir($base_folder)) {
        return $mappings;
    }
    
    // Get all user folders
    $user_folders = scandir($base_folder);
    $user_folders = array_diff($user_folders, array('.', '..'));
    
    foreach ($user_folders as $folder) {
        if (!is_dir($base_folder . '/' . $folder)) {
            continue;
        }
        
        // Try to match folder name with a user
        $user_id = wftp_get_user_id_from_folder($folder);
        if ($user_id) {
            $mappings[$folder] = $user_id;
        }
    }
    
    return $mappings;
}

/**
 * Get user ID from folder name
 */
function wftp_get_user_id_from_folder($folder_name) {
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
         WHERE (meta_key = 'ftp_folder' OR meta_key = 'user_folder' OR meta_key LIKE %s) 
         AND meta_value = %s LIMIT 1",
        '%folder%',
        $folder_name
    ));
    
    if ($result) {
        return intval($result);
    }
    
    // Return false if no match found
    return false;
}

/**
 * Count products created from a user's folder
 */
function wftp_count_user_products($user_id) {
    $query = new WP_Query(array(
        'post_type' => 'product',
        'post_status' => 'any',
        'meta_query' => array(
            array(
                'key' => '_wftp_user_id',
                'value' => $user_id,
                'compare' => '='
            )
        ),
        'posts_per_page' => -1,
        'fields' => 'ids'
    ));
    
    return $query->found_posts;
}

/**
 * Add log entry
 * 
 * @param string $message The log message
 */
function wftp_add_log($message) {
    if (get_option('wftp_log_enabled', 1)) {
        $log_entries = get_option('wftp_import_log', array());
        
        // Limit to last 100 entries
        if (count($log_entries) >= 100) {
            array_shift($log_entries);
        }
        
        $log_entries[] = array(
            'time' => current_time('Y-m-d H:i:s'),
            'message' => $message
        );
        
        update_option('wftp_import_log', $log_entries);
    }
}

/**
 * Main function to scan folders and create/update products
 */
function wftp_scan_folders() {
    // Set a lock to prevent duplicate processing
    $lock_transient = 'wftp_scanning_lock';
    if (get_transient($lock_transient)) {
        wftp_add_log("Scan already running. Skipping this execution.");
        return;
    }
    
    // Set lock for 5 minutes max
    set_transient($lock_transient, true, 5 * MINUTE_IN_SECONDS);
    
    try {
        // Update last scan time
        update_option('wftp_last_scan_time', time());
        
        // Set a higher time limit to prevent timeout on large folders
        @set_time_limit(300);
        
        $base_folder = get_option('wftp_base_folder_path', ABSPATH . 'wp-content/uploads/user_folders');
        $remove_deleted = get_option('wftp_remove_deleted_files', 1);
        
        if (!is_dir($base_folder)) {
            wftp_add_log("Base folder not found: $base_folder");
            delete_transient($lock_transient);
            return;
        }
        
        wftp_add_log("Starting folder scan in: $base_folder");
        
        // Get currently tracked files
        $processed_files = get_option('wftp_processed_files', array());
        
        // Create a list of current files to compare against later
        $current_files = array();
        
        // Get folder to user mappings
        $folder_mappings = wftp_get_folder_user_mappings();
        
        $total_created = 0;
        $total_updated = 0;
        
        // Get current user
        $current_user_id = get_current_user_id();
        $is_admin = current_user_can('administrator');
        
        // Process each user folder
        foreach ($folder_mappings as $folder_name => $user_id) {
            $user_folder_path = $base_folder . '/' . $folder_name;
            
            // Skip if not admin and not the owner of the folder (except for cron)
            if (!wp_doing_cron() && !$is_admin && $current_user_id != $user_id) {
                continue;
            }
            
            wftp_add_log("Scanning folder for user #{$user_id}: $folder_name");
            
            // Get all files within this user's folder
            $files = scandir($user_folder_path);
            $files = array_diff($files, array('.', '..'));
            
            foreach ($files as $file) {
                $file_path = $user_folder_path . '/' . $file;
                
                // Skip subfolders (we're only processing files directly in user folders)
                if (is_dir($file_path)) {
                    continue;
                }
                
                // Generate the unique key for this product
                $product_key = sanitize_title($user_id . '-' . $file);
                
                // Add to current files list
                $current_files[$product_key] = $file_path;
                
                // Process the file
                $result = wftp_process_file($file, $file_path, $user_id);
                
                if ($result === 'created') {
                    $total_created++;
                    wftp_add_log("Created product from file: $file (user: $user_id)");
                } elseif ($result === 'updated') {
                    $total_updated++;
                    wftp_add_log("Updated product from file: $file (user: $user_id)");
                } elseif ($result === 'error') {
                    wftp_add_log("Error processing file: $file (user: $user_id)");
                } elseif ($result === 'skipped') {
                    // Don't log skipped files to prevent log bloat
                }
            }
        }
        
        // Check for deleted files and remove corresponding products
        if ($remove_deleted) {
            $deleted_count = wftp_remove_deleted_products($current_files, $processed_files);
            
            if ($deleted_count > 0) {
                wftp_add_log("Removed $deleted_count products for deleted files");
            }
        }
        
        wftp_add_log("Scan completed. Created: $total_created, Updated: $total_updated products");
    } catch (Exception $e) {
        wftp_add_log("Error during scan: " . $e->getMessage());
    }
    
    // Release the lock
    delete_transient($lock_transient);
}

/**
 * Remove products when their source files are deleted
 * 
 * @param array $current_files List of files that currently exist
 * @param array $processed_files List of all previously processed files
 * @return int Number of products removed
 */
function wftp_remove_deleted_products($current_files, $processed_files) {
    $deleted_count = 0;
    
    // Find files that were processed before but don't exist anymore
    foreach ($processed_files as $product_key => $timestamp) {
        if (!isset($current_files[$product_key])) {
            // This file has been deleted - find and delete the product
            $existing_product_query = new WP_Query(array(
                'post_type' => 'product',
                'meta_query' => array(
                    array(
                        'key' => '_wftp_product_key',
                        'value' => $product_key,
                        'compare' => '='
                    )
                ),
                'posts_per_page' => 1
            ));
            
            if ($existing_product_query->have_posts()) {
                $existing_product_query->the_post();
                $product_id = get_the_ID();
                $product = wc_get_product($product_id);
                
                if ($product) {
                    $product_name = $product->get_name();
                    $user_id = get_post_meta($product_id, '_wftp_user_id', true);
                    
                    // Delete the product
                    wp_delete_post($product_id, true); // true = force delete, bypass trash
                    
                    // Remove from processed files list
                    unset($processed_files[$product_key]);
                    
                    wftp_add_log("Removed product '$product_name' (user: $user_id) because source file was deleted");
                    $deleted_count++;
                }
            }
        }
    }
    
    // Update the processed files list
    update_option('wftp_processed_files', $processed_files);
    
    return $deleted_count;
}

/**
 * Auto-scanner function - checks if it's time to run another scan
 */
function wftp_auto_scanner() {
    // Skip for AJAX requests
    if (wp_doing_ajax()) {
        return;
    }
    
    // Get settings
    $scan_interval = get_option('wftp_scan_interval', 1); // Minutes
    $last_scan = get_option('wftp_last_scan_time', 0);
    $current_time = time();
    
    // Check if enough time has passed since the last scan
    if (($current_time - $last_scan) >= ($scan_interval * 60)) {
        // Trigger scan in the background
        wp_schedule_single_event(time(), 'wftp_delayed_scan_hook');
        
        // Update last scan time to prevent multiple triggers
        update_option('wftp_last_scan_time', $current_time);
    }
}

/**
 * Hook the auto scanner to run on each page load
 */
add_action('init', 'wftp_auto_scanner', 999);

/**
 * Set up the delayed scan hook
 */
add_action('wftp_delayed_scan_hook', 'wftp_scan_folders');

/**
 * Process a single file and create/update a product
 * 
 * @param string $filename Name of the file
 * @param string $file_path Full path to the file
 * @param int $user_id ID of the user who owns the folder
 * @return string 'created', 'updated', 'skipped', or 'error'
 */
function wftp_process_file($filename, $file_path, $user_id) {
    // Get the modification time of the file
    $file_mod_time = filemtime($file_path);
    
    // Generate a unique key for this product based on user ID and filename
    $product_key = sanitize_title($user_id . '-' . $filename);
    
    // Check if we've processed this file before and if it's been modified
    $processed_files = get_option('wftp_processed_files', array());
    $last_processed = isset($processed_files[$product_key]) ? $processed_files[$product_key] : 0;
    
    // If file hasn't changed since last import, skip it
    if ($last_processed >= $file_mod_time) {
        return 'skipped';
    }
    
    // Get file extension
    $file_extension = pathinfo($filename, PATHINFO_EXTENSION);
    
    // Remove extension from filename to use as product name
    $product_name = pathinfo($filename, PATHINFO_FILENAME);
    $product_name = str_replace('-', ' ', $product_name);
    $product_name = str_replace('_', ' ', $product_name);
    $product_name = ucwords($product_name);
    
    // Default price from settings
    $default_price = get_option('wftp_default_price', 9.99);
    
    // Get product status from settings
    $product_status = get_option('wftp_product_status', 'draft');
    
    // Check if product with this key already exists as post meta
    $existing_product_query = new WP_Query(array(
        'post_type' => 'product',
        'meta_query' => array(
            array(
                'key' => '_wftp_product_key',
                'value' => $product_key,
                'compare' => '='
            )
        ),
        'posts_per_page' => 1
    ));
    
    $is_image = in_array(strtolower($file_extension), array('jpg', 'jpeg', 'png', 'gif', 'webp'));
    $is_downloadable = !$is_image;
    
    if ($existing_product_query->have_posts()) {
        // Update existing product
        $existing_product_query->the_post();
        $product_id = get_the_ID();
        $product = wc_get_product($product_id);
        
        if ($product) {
            $product->set_name($product_name);
            
            // If it's a downloadable file, update the downloadable file
            if ($is_downloadable) {
                wftp_update_downloadable_file($product_id, $filename, $file_path);
            }
            
            $product_id = $product->save();
            
            if ($product_id && $is_image) {
                // Update product image
                wftp_update_product_image($product_id, $file_path, $filename);
            }
            
            // Update the processed files record
            $processed_files[$product_key] = time();
            update_option('wftp_processed_files', $processed_files);
            
            return 'updated';
        }
    } else {
        // Create new product
        $product = new WC_Product();
        $product->set_name($product_name);
        $product->set_status($product_status); // Use the status from settings
        $product->set_catalog_visibility('visible');
        $product->set_price($default_price);
        $product->set_regular_price($default_price);
        
        // Set basic user metadata
        $product->update_meta_data('_wftp_product_key', $product_key);
        $product->update_meta_data('_wftp_user_id', $user_id);
        $product->update_meta_data('_wftp_source_file', $filename);
        
        if ($is_downloadable) {
            $product->set_downloadable(true);
        }
        
        $product_id = $product->save();
        
        if ($product_id) {
            if ($is_image) {
                // Set product image
                wftp_update_product_image($product_id, $file_path, $filename);
            } elseif ($is_downloadable) {
                // Add downloadable file
                wftp_update_downloadable_file($product_id, $filename, $file_path);
            }
            
            // Update the processed files record
            $processed_files[$product_key] = time();
            update_option('wftp_processed_files', $processed_files);
            
            // Set post author explicitly to match folder owner
            wp_update_post(array(
                'ID' => $product_id,
                'post_author' => $user_id
            ));
            
            return 'created';
        }
    }
    
    return 'error';
}

/**
 * Update product image
 * Renamed from update_product_image to wftp_update_product_image to avoid conflicts
 */
function wftp_update_product_image($product_id, $image_path, $image_name) {
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
}

/**
 * Update downloadable file for product
 * Renamed from update_downloadable_file to wftp_update_downloadable_file to avoid conflicts
 */
function wftp_update_downloadable_file($product_id, $filename, $file_path) {
    $product = wc_get_product($product_id);
    
    if (!$product) {
        return;
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
}

/**
 * Add background processor using WordPress shutdown hook
 * This runs after a page is fully loaded, minimizing impact on user experience
 */
function wftp_background_processor() {
    // Get settings
    $scan_interval = get_option('wftp_scan_interval', 1); // Minutes
    $last_scan = get_option('wftp_last_scan_time', 0);
    $current_time = time();
    
    // Check if enough time has passed since the last scan
    if (($current_time - $last_scan) >= ($scan_interval * 60)) {
        // Perform scan in background
        wftp_scan_folders();
    }
}
add_action('shutdown', 'wftp_background_processor');

/**
 * Filter products in WooCommerce queries
 * This ensures users only see their own products unless they're admin
 */
function wftp_filter_products_by_user($query) {
    // Only apply to product queries in frontend or admin
    if ((is_admin() || is_shop() || is_product_category() || is_product_tag()) && 
        $query->get('post_type') === 'product') {
        
        // Check if user is admin
        if (current_user_can('administrator')) {
            return $query; // Admin can see all products
        }
        
        $current_user_id = get_current_user_id();
        
        if ($current_user_id) {
            // Get the current meta query
            $meta_query = $query->get('meta_query', array());
            
            // Add condition for products from this user's folder
            $meta_query[] = array(
                'relation' => 'OR',
                array(
                    'key' => '_wftp_user_id',
                    'value' => $current_user_id,
                    'compare' => '='
                ),
                array(
                    'key' => '_wftp_user_id',
                    'compare' => 'NOT EXISTS'  // Products not created by our plugin
                )
            );
            
            $query->set('meta_query', $meta_query);
        }
    }
    
    return $query;
}
add_filter('pre_get_posts', 'wftp_filter_products_by_user');

/**
 * Filter product visibility based on status and ownership
 */
function wftp_filter_products_by_status($visible, $product_id) {
    // Check if product is from our importer
    $user_id = get_post_meta($product_id, '_wftp_user_id', true);
    if (!$user_id) {
        // Not one of our products, return default visibility
        return $visible;
    }
    
    // Get product status
    $product_status = get_post_status($product_id);
    
    // For administrators, always visible
    if (current_user_can('administrator')) {
        return true;
    }
    
    // For the owner, always visible
    $current_user_id = get_current_user_id();
    if ($current_user_id && $current_user_id == $user_id) {
        return true;
    }
    
    // For others, only visible if published
    return ($product_status === 'publish');
}
add_filter('woocommerce_product_is_visible', 'wftp_filter_products_by_status', 10, 2);

/**
 * Restrict downloadable product access
 */
function wftp_restrict_download_access($downloadable, $product) {
    // Check if it's our imported product
    $user_id = $product->get_meta('_wftp_user_id');
    if (!$user_id) {
        return $downloadable;
    }
    
    // Check product status - must be published for non-owners
    if ($product->get_status() !== 'publish') {
        $current_user_id = get_current_user_id();
        
        // If not admin and not owner, deny access
        if (!current_user_can('administrator') && $current_user_id != $user_id) {
            return false;
        }
    }
    
    return $downloadable;
}
add_filter('woocommerce_is_downloadable', 'wftp_restrict_download_access', 10, 2);

/**
 * Add our own folder path to the default paths scanned
 */
add_filter('jetengine/user_folder_rest/folder_paths', function($paths) {
    $base_folder = get_option('wftp_base_folder_path', ABSPATH . 'wp-content/uploads/user_folders');
    if (is_dir($base_folder) && !in_array($base_folder, $paths)) {
        $paths[] = $base_folder;
    }
    return $paths;
});

/**
 * Setup a background ping system for continuous operation
 */
function wftp_setup_background_ping() {
    ?>
    <script type="text/javascript">
    // Only add this script in admin area to reduce front-end load
    <?php if (is_admin()) : ?>
    (function() {
        // Auto-refresh scan status periodically in admin area
        var refreshTimer;
        
        function setupRefresh() {
            if (document.querySelector('.wftp-scan-status')) {
                clearTimeout(refreshTimer);
                refreshTimer = setTimeout(function() {
                    // This just reloads the page when user is in the file importer admin screen
                    if (window.location.href.indexOf('page=woo-ftp-importer') > -1) {
                        var scanStatus = document.querySelector('.wftp-scan-status');
                        
                        // Send background ping to keep scans running
                        var xhttp = new XMLHttpRequest();
                        xhttp.onreadystatechange = function() {
                            if (this.readyState == 4 && this.status == 200) {
                                // Reload only if last scan time has changed
                                if (this.responseText != scanStatus.dataset.lastScan) {
                                    location.reload();
                                }
                            }
                        };
                        xhttp.open("GET", "<?php echo admin_url('admin-ajax.php'); ?>?action=wftp_background_ping", true);
                        xhttp.send();
                    }
                    setupRefresh();
                }, 30000); // Check every 30 seconds
            }
        }
        
        // Initialize the refresh cycle
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', setupRefresh);
        } else {
            setupRefresh();
        }
    })();
    <?php endif; ?>
    </script>
    <?php
}

// Add the background ping script
add_action('admin_footer', 'wftp_setup_background_ping');

/**
 * Handle background ping AJAX request
 */
function wftp_handle_background_ping() {
    // Process auto scan if needed
    wftp_auto_scanner();
    
    // Return last scan time
    echo get_option('wftp_last_scan_time', 0);
    wp_die();
}
add_action('wp_ajax_wftp_background_ping', 'wftp_handle_background_ping');
add_action('wp_ajax_nopriv_wftp_background_ping', 'wftp_handle_background_ping');