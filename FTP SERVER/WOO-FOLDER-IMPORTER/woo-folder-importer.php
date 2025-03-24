<?php
/**
 * Plugin Name: WooCommerce Simple File Importer
 * Plugin URI: https://yourwebsite.com/
 * Description: Automatically import files as WooCommerce products, with each folder representing a different store
 * Version: 1.2.0
 * Author: DevSpace
 * Text Domain: woo-file-importer
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
        echo '<div class="error"><p>' . __('WooCommerce Simple File Importer requires WooCommerce to be installed and active.', 'woo-file-importer') . '</p></div>';
    });
    return;
}

// Define constants
define('WFI_VERSION', '1.2.0');
define('WFI_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('WFI_PLUGIN_URL', plugin_dir_url(__FILE__));

// Add admin menu
add_action('admin_menu', 'wfi_add_admin_menu');

function wfi_add_admin_menu() {
    add_menu_page(
        __('WooCommerce File Importer', 'woo-file-importer'),
        __('File Importer', 'woo-file-importer'),
        'manage_woocommerce',
        'woo-file-importer',
        'wfi_admin_page',
        'dashicons-upload',
        56
    );
}

// Create admin page
function wfi_admin_page() {
    // Save settings
    if (isset($_POST['wfi_save_settings']) && wp_verify_nonce($_POST['wfi_nonce'], 'wfi_save_settings')) {
        $base_folder_path = sanitize_text_field($_POST['wfi_base_folder_path']);
        update_option('wfi_base_folder_path', $base_folder_path);
        
        $scan_interval = absint($_POST['wfi_scan_interval']);
        if ($scan_interval < 1) $scan_interval = 1;
        update_option('wfi_scan_interval', $scan_interval);
        
        $default_price = floatval($_POST['wfi_default_price']);
        update_option('wfi_default_price', $default_price);
        
        $product_status = sanitize_text_field($_POST['wfi_product_status']);
        update_option('wfi_product_status', $product_status);
        
        $log_enabled = isset($_POST['wfi_log_enabled']) ? 1 : 0;
        update_option('wfi_log_enabled', $log_enabled);
        
        $remove_deleted_files = isset($_POST['wfi_remove_deleted_files']) ? 1 : 0;
        update_option('wfi_remove_deleted_files', $remove_deleted_files);
        
        echo '<div class="notice notice-success is-dismissible"><p>' . __('Settings saved successfully.', 'woo-file-importer') . '</p></div>';
    }
    
    // Get current settings
    $base_folder_path = get_option('wfi_base_folder_path', ABSPATH . 'wp-content/uploads/stores');
    $scan_interval = get_option('wfi_scan_interval', 1);
    $default_price = get_option('wfi_default_price', 9.99);
    $product_status = get_option('wfi_product_status', 'publish');
    $log_enabled = get_option('wfi_log_enabled', 1);
    $remove_deleted_files = get_option('wfi_remove_deleted_files', 1);
    
    // Manual scan trigger
    if (isset($_POST['wfi_manual_scan']) && wp_verify_nonce($_POST['wfi_nonce'], 'wfi_manual_scan')) {
        wfi_scan_folders();
        echo '<div class="notice notice-success is-dismissible"><p>' . __('Manual scan completed.', 'woo-file-importer') . '</p></div>';
    }
    
    // Get last scan time
    $last_scan = get_option('wfi_last_scan_time', 0);
    $last_scan_date = ($last_scan > 0) ? date_i18n('Y-m-d H:i:s', $last_scan) : __('Never', 'woo-file-importer');
    $next_scan = ($last_scan > 0) ? date_i18n('Y-m-d H:i:s', $last_scan + ($scan_interval * 60)) : __('After first page load', 'woo-file-importer');
    
    // Display the form
    ?>
    <div class="wrap">
        <h1><?php _e('WooCommerce Simple File Importer', 'woo-file-importer'); ?></h1>
        
        <h2><?php _e('Settings', 'woo-file-importer'); ?></h2>
        <form method="post" action="">
            <?php wp_nonce_field('wfi_save_settings', 'wfi_nonce'); ?>
            <table class="form-table">
                <tr valign="top">
                    <th scope="row">
                        <label for="wfi_base_folder_path"><?php _e('Base Folder Path', 'woo-file-importer'); ?></label>
                    </th>
                    <td>
                        <input type="text" id="wfi_base_folder_path" name="wfi_base_folder_path" class="regular-text" 
                            value="<?php echo esc_attr($base_folder_path); ?>" />
                        <p class="description">
                            <?php _e('Enter the absolute server path to the base folder containing store subfolders', 'woo-file-importer'); ?>
                        </p>
                    </td>
                </tr>
                <tr valign="top">
                    <th scope="row">
                        <label for="wfi_scan_interval"><?php _e('Scan Interval (minutes)', 'woo-file-importer'); ?></label>
                    </th>
                    <td>
                        <input type="number" id="wfi_scan_interval" name="wfi_scan_interval" min="1" 
                            value="<?php echo esc_attr($scan_interval); ?>" />
                    </td>
                </tr>
                <tr valign="top">
                    <th scope="row">
                        <label for="wfi_default_price"><?php _e('Default Product Price', 'woo-file-importer'); ?></label>
                    </th>
                    <td>
                        <input type="number" id="wfi_default_price" name="wfi_default_price" min="0" step="0.01" 
                            value="<?php echo esc_attr($default_price); ?>" />
                        <p class="description">
                            <?php _e('Default price for new products when no price information is available', 'woo-file-importer'); ?>
                        </p>
                    </td>
                </tr>
                <tr valign="top">
                    <th scope="row">
                        <label for="wfi_product_status"><?php _e('Product Status', 'woo-file-importer'); ?></label>
                    </th>
                    <td>
                        <select id="wfi_product_status" name="wfi_product_status">
                            <option value="publish" <?php selected($product_status, 'publish'); ?>><?php _e('Published', 'woo-file-importer'); ?></option>
                            <option value="draft" <?php selected($product_status, 'draft'); ?>><?php _e('Draft', 'woo-file-importer'); ?></option>
                            <option value="pending" <?php selected($product_status, 'pending'); ?>><?php _e('Pending Review', 'woo-file-importer'); ?></option>
                            <option value="private" <?php selected($product_status, 'private'); ?>><?php _e('Private', 'woo-file-importer'); ?></option>
                        </select>
                        <p class="description">
                            <?php _e('Default status for newly created products', 'woo-file-importer'); ?>
                        </p>
                    </td>
                </tr>
                <tr valign="top">
                    <th scope="row">
                        <label for="wfi_remove_deleted_files"><?php _e('Remove Deleted Products', 'woo-file-importer'); ?></label>
                    </th>
                    <td>
                        <input type="checkbox" id="wfi_remove_deleted_files" name="wfi_remove_deleted_files" value="1" <?php checked($remove_deleted_files, 1); ?> />
                        <p class="description">
                            <?php _e('Automatically delete products when their source files are removed', 'woo-file-importer'); ?>
                        </p>
                    </td>
                </tr>
                <tr valign="top">
                    <th scope="row">
                        <label for="wfi_log_enabled"><?php _e('Enable Logging', 'woo-file-importer'); ?></label>
                    </th>
                    <td>
                        <input type="checkbox" id="wfi_log_enabled" name="wfi_log_enabled" value="1" <?php checked($log_enabled, 1); ?> />
                        <p class="description">
                            <?php _e('Log import activities for debugging', 'woo-file-importer'); ?>
                        </p>
                    </td>
                </tr>
            </table>
            <p class="submit">
                <input type="submit" name="wfi_save_settings" class="button-primary" value="<?php _e('Save Settings', 'woo-file-importer'); ?>" />
            </p>
        </form>
        
        <div class="wfi-scan-status" style="margin-bottom: 20px; padding: 15px; background: #f0f0f1; border-left: 4px solid #2271b1;">
            <h3 style="margin-top: 0;"><?php _e('Automatic Scanning Status', 'woo-file-importer'); ?></h3>
            <p><strong><?php _e('Last scan:', 'woo-file-importer'); ?></strong> <?php echo esc_html($last_scan_date); ?></p>
            <p><strong><?php _e('Next scan:', 'woo-file-importer'); ?></strong> <?php echo esc_html($next_scan); ?></p>
            <p><?php _e('The scanner will automatically run once every', 'woo-file-importer'); ?> <?php echo esc_html($scan_interval); ?> <?php _e('minute(s) when your site receives visitors.', 'woo-file-importer'); ?></p>
        </div>
        
        <h2><?php _e('Manual Operation', 'woo-file-importer'); ?></h2>
        <form method="post" action="">
            <?php wp_nonce_field('wfi_manual_scan', 'wfi_nonce'); ?>
            <p>
                <input type="submit" name="wfi_manual_scan" class="button-secondary" value="<?php _e('Run Manual Scan Now', 'woo-file-importer'); ?>" />
            </p>
        </form>
        
        <h2><?php _e('Import Log', 'woo-file-importer'); ?></h2>
        <div class="wfi-log-container" style="max-height: 300px; overflow-y: auto; border: 1px solid #ccc; padding: 10px; background: #f9f9f9;">
            <?php
            $log_entries = get_option('wfi_import_log', array());
            if (empty($log_entries)) {
                echo '<p>' . __('No import logs available.', 'woo-file-importer') . '</p>';
            } else {
                echo '<ul style="margin-top: 0;">';
                foreach (array_reverse($log_entries) as $log) {
                    echo '<li><strong>[' . esc_html($log['time']) . ']</strong> ' . esc_html($log['message']) . '</li>';
                }
                echo '</ul>';
                
                // Add clear log button
                ?>
                <form method="post" action="">
                    <?php wp_nonce_field('wfi_clear_log', 'wfi_nonce'); ?>
                    <input type="submit" name="wfi_clear_log" class="button-secondary" value="<?php _e('Clear Log', 'woo-file-importer'); ?>" />
                </form>
                <?php
            }
            
            // Clear log action
            if (isset($_POST['wfi_clear_log']) && wp_verify_nonce($_POST['wfi_nonce'], 'wfi_clear_log')) {
                delete_option('wfi_import_log');
                echo '<script>location.reload();</script>';
            }
            ?>
        </div>
        
        <h2><?php _e('How It Works', 'woo-file-importer'); ?></h2>
        <div class="wfi-help">
            <p><?php _e('This plugin scans the base folder and its subfolders for files to import:', 'woo-file-importer'); ?></p>
            <ul>
                <li><?php _e('Each subfolder in the base folder represents a different store/vendor', 'woo-file-importer'); ?></li>
                <li><?php _e('Any file placed in a store folder will be imported as a product', 'woo-file-importer'); ?></li>
                <li><?php _e('Image files will be used as the product image', 'woo-file-importer'); ?></li>
                <li><?php _e('Other files will be imported as downloadable products', 'woo-file-importer'); ?></li>
                <li><?php _e('The filename will be used as the product name', 'woo-file-importer'); ?></li>
                <li><strong><?php _e('Products will be automatically removed when their source files are deleted (if enabled)', 'woo-file-importer'); ?></strong></li>
            </ul>
            
            <h3><?php _e('Example folder structure:', 'woo-file-importer'); ?></h3>
            <pre style="background: #f1f1f1; padding: 10px;">
base_folder/
  ├── store1/                  # Store 1 folder
  │   ├── product1.jpg         # Will be imported as a product with image
  │   └── product2.pdf         # Will be imported as a product with downloadable file
  └── store2/                  # Store 2 folder
      ├── red-t-shirt.png      # Will be imported as a product with image
      └── blue-jeans.jpg       # Will be imported as a product with image
            </pre>
        </div>
    </div>
    <?php
}

/**
 * Add log entry
 * 
 * @param string $message The log message
 */
function wfi_add_log($message) {
    if (get_option('wfi_log_enabled', 1)) {
        $log_entries = get_option('wfi_import_log', array());
        
        // Limit to last 100 entries
        if (count($log_entries) >= 100) {
            array_shift($log_entries);
        }
        
        $log_entries[] = array(
            'time' => current_time('Y-m-d H:i:s'),
            'message' => $message
        );
        
        update_option('wfi_import_log', $log_entries);
    }
}

/**
 * Main function to scan folders and create/update products
 */
function wfi_scan_folders() {
    // Set a lock to prevent duplicate processing
    $lock_transient = 'wfi_scanning_lock';
    if (get_transient($lock_transient)) {
        wfi_add_log("Scan already running. Skipping this execution.");
        return;
    }
    
    // Set lock for 5 minutes max
    set_transient($lock_transient, true, 5 * MINUTE_IN_SECONDS);
    
    try {
        // Update last scan time
        update_option('wfi_last_scan_time', time());
        
        // Set a higher time limit to prevent timeout on large folders
        @set_time_limit(300);
        
        $base_folder = get_option('wfi_base_folder_path', ABSPATH . 'wp-content/uploads/stores');
        $remove_deleted = get_option('wfi_remove_deleted_files', 1);
        
        if (!is_dir($base_folder)) {
            wfi_add_log("Base folder not found: $base_folder");
            delete_transient($lock_transient);
            return;
        }
        
        wfi_add_log("Starting folder scan in: $base_folder");
        
        // Get currently tracked files
        $processed_files = get_option('wfi_processed_files', array());
        
        // Create a list of current files to compare against later
        $current_files = array();
        
        // Get all store folders
        $store_folders = scandir($base_folder);
        $store_folders = array_diff($store_folders, array('.', '..'));
        
        $total_created = 0;
        $total_updated = 0;
        
        foreach ($store_folders as $store_folder) {
            $store_path = $base_folder . '/' . $store_folder;
            
            if (!is_dir($store_path)) {
                continue;
            }
            
            wfi_add_log("Scanning store: $store_folder");
            
            // Get all files within this store
            $files = scandir($store_path);
            $files = array_diff($files, array('.', '..'));
            
            foreach ($files as $file) {
                $file_path = $store_path . '/' . $file;
                
                // Skip subfolders (we're only processing files directly in store folders)
                if (is_dir($file_path)) {
                    continue;
                }
                
                // Generate the unique key for this product
                $product_key = sanitize_title($store_folder . '-' . $file);
                
                // Add to current files list
                $current_files[$product_key] = $file_path;
                
                // Process the file
                $result = wfi_process_file($file, $file_path, $store_folder);
                
                if ($result === 'created') {
                    $total_created++;
                    wfi_add_log("Created product from file: $file (store: $store_folder)");
                } elseif ($result === 'updated') {
                    $total_updated++;
                    wfi_add_log("Updated product from file: $file (store: $store_folder)");
                } elseif ($result === 'error') {
                    wfi_add_log("Error processing file: $file (store: $store_folder)");
                } elseif ($result === 'skipped') {
                    // Don't log skipped files to prevent log bloat
                }
            }
        }
        
        // Check for deleted files and remove corresponding products
        if ($remove_deleted) {
            $deleted_count = wfi_remove_deleted_products($current_files, $processed_files);
            
            if ($deleted_count > 0) {
                wfi_add_log("Removed $deleted_count products for deleted files");
            }
        }
        
        wfi_add_log("Scan completed. Created: $total_created, Updated: $total_updated products");
    } catch (Exception $e) {
        wfi_add_log("Error during scan: " . $e->getMessage());
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
function wfi_remove_deleted_products($current_files, $processed_files) {
    $deleted_count = 0;
    
    // Find files that were processed before but don't exist anymore
    foreach ($processed_files as $product_key => $timestamp) {
        if (!isset($current_files[$product_key])) {
            // This file has been deleted - find and delete the product
            $existing_product_query = new WP_Query(array(
                'post_type' => 'product',
                'meta_query' => array(
                    array(
                        'key' => '_wfi_product_key',
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
                    
                    // Delete the product
                    wp_delete_post($product_id, true); // true = force delete, bypass trash
                    
                    // Remove from processed files list
                    unset($processed_files[$product_key]);
                    
                    wfi_add_log("Removed product '$product_name' because source file was deleted");
                    $deleted_count++;
                }
            }
        }
    }
    
    // Update the processed files list
    update_option('wfi_processed_files', $processed_files);
    
    return $deleted_count;
}

/**
 * Auto-scanner function - checks if it's time to run another scan
 */
function wfi_auto_scanner() {
    // Skip for AJAX requests
    if (wp_doing_ajax()) {
        return;
    }
    
    // Get settings
    $scan_interval = get_option('wfi_scan_interval', 1); // Minutes
    $last_scan = get_option('wfi_last_scan_time', 0);
    $current_time = time();
    
    // Check if enough time has passed since the last scan
    if (($current_time - $last_scan) >= ($scan_interval * 60)) {
        // Trigger scan in the background
        wp_schedule_single_event(time(), 'wfi_delayed_scan_hook');
        
        // Update last scan time to prevent multiple triggers
        update_option('wfi_last_scan_time', $current_time);
    }
}

/**
 * Hook the auto scanner to run on each page load
 */
add_action('init', 'wfi_auto_scanner', 999);

/**
 * Set up the delayed scan hook
 */
add_action('wfi_delayed_scan_hook', 'wfi_scan_folders');

/**
 * Process a single file and create/update a product
 * 
 * @param string $filename Name of the file
 * @param string $file_path Full path to the file
 * @param string $store_name Name of the store folder (vendor)
 * @return string 'created', 'updated', 'skipped', or 'error'
 */
function wfi_process_file($filename, $file_path, $store_name) {
    // Get the modification time of the file
    $file_mod_time = filemtime($file_path);
    
    // Generate a unique key for this product based on store and filename
    $product_key = sanitize_title($store_name . '-' . $filename);
    
    // Check if we've processed this file before and if it's been modified
    $processed_files = get_option('wfi_processed_files', array());
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
    $default_price = get_option('wfi_default_price', 9.99);
    
    // Get product status from settings
    $product_status = get_option('wfi_product_status', 'publish');
    
    // Check if product with this key already exists as post meta
    $existing_product_query = new WP_Query(array(
        'post_type' => 'product',
        'meta_query' => array(
            array(
                'key' => '_wfi_product_key',
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
                update_downloadable_file($product_id, $filename, $file_path);
            }
            
            $product_id = $product->save();
            
            if ($product_id && $is_image) {
                // Update product image
                update_product_image($product_id, $file_path, $filename);
            }
            
            // Update the processed files record
            $processed_files[$product_key] = time();
            update_option('wfi_processed_files', $processed_files);
            
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
        
        // Set basic store metadata
        $product->update_meta_data('_wfi_product_key', $product_key);
        $product->update_meta_data('_wfi_store', $store_name);
        $product->update_meta_data('_wfi_source_file', $filename);
        
        if ($is_downloadable) {
            $product->set_downloadable(true);
        }
        
        $product_id = $product->save();
        
        if ($product_id) {
            if ($is_image) {
                // Set product image
                update_product_image($product_id, $file_path, $filename);
            } elseif ($is_downloadable) {
                // Add downloadable file
                update_downloadable_file($product_id, $filename, $file_path);
            }
            
            // Update the processed files record
            $processed_files[$product_key] = time();
            update_option('wfi_processed_files', $processed_files);
            
            // Add store as product category
            create_or_assign_store_category($product_id, $store_name);
            
            return 'created';
        }
    }
    
    return 'error';
}

/**
 * Create a store category and assign product to it
 */
function create_or_assign_store_category($product_id, $store_name) {
    $store_term = term_exists($store_name, 'product_cat');
    
    if (!$store_term) {
        // Create a new category for this store
        $store_term = wp_insert_term(
            $store_name, // the term 
            'product_cat', // the taxonomy
            array(
                'description' => sprintf(__('Products from %s', 'woo-file-importer'), $store_name),
                'slug' => sanitize_title($store_name)
            )
        );
    }
    
    if (!is_wp_error($store_term)) {
        $term_id = is_array($store_term) ? $store_term['term_id'] : $store_term;
        
        // Assign the product to this category
        wp_set_object_terms($product_id, (int)$term_id, 'product_cat', true);
    }
}

/**
 * Update product image
 */
function update_product_image($product_id, $image_path, $image_name) {
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
 */
function update_downloadable_file($product_id, $filename, $file_path) {
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
function wfi_background_processor() {
    // Get settings
    $scan_interval = get_option('wfi_scan_interval', 1); // Minutes
    $last_scan = get_option('wfi_last_scan_time', 0);
    $current_time = time();
    
    // Check if enough time has passed since the last scan
    if (($current_time - $last_scan) >= ($scan_interval * 60)) {
        // Perform scan in background
        wfi_scan_folders();
    }
}
add_action('shutdown', 'wfi_background_processor');

/**
 * Setup a background ping system for continuous operation
 */
function wfi_setup_background_ping() {
    ?>
    <script type="text/javascript">
    // Only add this script in admin area to reduce front-end load
    <?php if (is_admin()) : ?>
    (function() {
        // Auto-refresh scan status periodically in admin area
        var refreshTimer;
        
        function setupRefresh() {
            if (document.querySelector('.wfi-scan-status')) {
                clearTimeout(refreshTimer);
                refreshTimer = setTimeout(function() {
                    // This just reloads the page when user is in the file importer admin screen
                    if (window.location.href.indexOf('page=woo-file-importer') > -1) {
                        var scanStatus = document.querySelector('.wfi-scan-status');
                        
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
                        xhttp.open("GET", "<?php echo admin_url('admin-ajax.php'); ?>?action=wfi_background_ping", true);
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
add_action('admin_footer', 'wfi_setup_background_ping');

/**
 * Handle background ping AJAX request
 */
function wfi_handle_background_ping() {
    // Process auto scan if needed
    wfi_auto_scanner();
    
    // Return last scan time
    echo get_option('wfi_last_scan_time', 0);
    wp_die();
}
add_action('wp_ajax_wfi_background_ping', 'wfi_handle_background_ping');
add_action('wp_ajax_nopriv_wfi_background_ping', 'wfi_handle_background_ping');