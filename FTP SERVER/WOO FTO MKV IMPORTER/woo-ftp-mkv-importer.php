<?php
/**
 * Plugin Name: WooCommerce FTP MKV Importer
 * Plugin URI: https://yourwebsite.com/
 * Description: Import .mkv products from a specific FTP folder and generate previews with watermark.
 * Version: 1.0.0
 * Author: DevSpacek
 * Text Domain: woo-ftp-mkv-importer
 * Requires at least: 5.0
 * Requires PHP: 7.2
 * WC requires at least: 3.0
 * WC tested up to: 8.0
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

// Define constants
define('WMKV_VERSION', '1.0.0');
define('WMKV_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('WMKV_PLUGIN_URL', plugin_dir_url(__FILE__));

// Add admin menu
add_action('admin_menu', 'wmkv_add_admin_menu', 10);

function wmkv_add_admin_menu() {
    add_menu_page(
        'WooCommerce FTP MKV Importer',
        'FTP MKV Importer',
        'manage_options',
        'woo-ftp-mkv-importer',
        'wmkv_admin_page',
        'dashicons-upload',
        30
    );
}

// Create admin page
function wmkv_admin_page() {
    // Save settings
    if (isset($_POST['wmkv_save_settings']) && wp_verify_nonce($_POST['wmkv_nonce'], 'wmkv_save_settings')) {
        $base_folder_path = sanitize_text_field($_POST['wmkv_base_folder_path']);
        update_option('wmkv_base_folder_path', $base_folder_path);
        
        echo '<div class="notice notice-success is-dismissible"><p>Settings saved successfully.</p></div>';
    }
    
    // Get current settings
    $base_folder_path = get_option('wmkv_base_folder_path', ABSPATH . 'wp-content/uploads/user_folders');
    
    // Display the form
    ?>
    <div class="wrap">
        <h1>WooCommerce FTP MKV Importer</h1>
        
        <h2>Settings</h2>
        <form method="post" action="">
            <?php wp_nonce_field('wmkv_save_settings', 'wmkv_nonce'); ?>
            
            <table class="form-table">
                <tr valign="top">
                    <th scope="row">
                        <label for="wmkv_base_folder_path">Base Folder Path</label>
                    </th>
                    <td>
                        <input type="text" id="wmkv_base_folder_path" name="wmkv_base_folder_path" class="regular-text" 
                            value="<?php echo esc_attr($base_folder_path); ?>" />
                        <p class="description">
                            Enter the absolute server path to the base folder containing user FTP folders
                        </p>
                    </td>
                </tr>
            </table>
            
            <p class="submit">
                <input type="submit" name="wmkv_save_settings" class="button-primary" value="Save Settings" />
            </p>
        </form>
        
        <div class="wmkv-scan-status" style="margin-bottom: 20px; padding: 15px; background: #f0f0f1; border-left: 4px solid #2271b1;">
            <h3 style="margin-top: 0;">Scanning Status</h3>
            <p>This plugin will scan the specified folder for .mkv files and create products with previews and watermark.</p>
        </div>
    </div>
    <?php
}

/**
 * Scan folders for .mkv files and create products
 */
function wmkv_scan_folders() {
    // Set a lock to prevent duplicate processing
    $lock_transient = 'wmkv_scanning_lock';
    if (get_transient($lock_transient)) {
        return;
    }
    
    // Set lock for 5 minutes max
    set_transient($lock_transient, true, 5 * MINUTE_IN_SECONDS);
    
    try {
        // Set a higher time limit to prevent timeout on large folders
        @set_time_limit(300);
        
        $base_folder = get_option('wmkv_base_folder_path', ABSPATH . 'wp-content/uploads/user_folders');
        
        if (!is_dir($base_folder)) {
            return;
        }
        
        // Get all .mkv files in the folder
        $mkv_files = glob($base_folder . '/*.mkv');
        
        foreach ($mkv_files as $file_path) {
            $filename = basename($file_path);
            $product_key = sanitize_title($filename);
            
            // Check if product already exists
            $existing_product = get_posts(array(
                'post_type' => 'product',
                'meta_key' => '_wmkv_product_key',
                'meta_value' => $product_key,
                'posts_per_page' => 1
            ));
            
            if ($existing_product) {
                continue;
            }
            
            // Create new product
            wmkv_create_product($filename, $file_path, $product_key);
        }
    } catch (Exception $e) {
        // Handle exception
    }
    
    // Release the lock
    delete_transient($lock_transient);
}

/**
 * Create a product for a .mkv file
 */
function wmkv_create_product($filename, $file_path, $product_key) {
    // Create new product
    $product = new WC_Product();
    $product->set_name($filename);
    $product->set_status('draft');
    $product->set_catalog_visibility('visible');
    $product->set_price(0); // Set default price to 0 for now
    $product->set_regular_price(0);
    
    // Set product metadata
    $product->update_meta_data('_wmkv_product_key', $product_key);
    $product->update_meta_data('_wmkv_source_file', $filename);
    
    $product_id = $product->save();
    
    if ($product_id) {
        // Set downloadable file
        wmkv_set_downloadable_file($product_id, $filename, $file_path);
        
        // Generate and set preview with watermark
        wmkv_set_preview_with_watermark($product_id, $file_path);
        
        return 'created';
    }
    
    return 'error';
}

/**
 * Set downloadable file for product
 */
function wmkv_set_downloadable_file($product_id, $filename, $file_path) {
    $product = wc_get_product($product_id);
    
    if (!$product) {
        return;
    }
    
    // Create safe filename
    $safe_filename = sanitize_file_name($filename);
    
    // Create uploads folder if not exists
    $upload_dir = wp_upload_dir();
    $downloads_dir = $upload_dir['basedir'] . '/woocommerce_uploads';
    
    if (!file_exists($downloads_dir)) {
        wp_mkdir_p($downloads_dir);
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
 * Background processor to scan folders
 */
function wmkv_background_processor() {
    wmkv_scan_folders();
}
add_action('shutdown', 'wmkv_background_processor');