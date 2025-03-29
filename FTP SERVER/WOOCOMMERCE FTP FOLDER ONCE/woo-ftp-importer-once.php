<?php
/**
 * Plugin Name: WooCommerce FTP Folder Importer (Create Once)
 * Plugin URI: https://yourwebsite.com/
 * Description: Import products from FTP folders only once and never update them
 * Version: 1.0.1
 * Author: DevSpace
 * Text Domain: woo-ftp-importer
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
define('WFTP_VERSION', '1.0.1');
define('WFTP_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('WFTP_PLUGIN_URL', plugin_dir_url(__FILE__));

// Add admin menu
add_action('admin_menu', 'wftp_add_admin_menu', 10);

function wftp_add_admin_menu() {
    add_menu_page(
        'WooCommerce FTP Importer',
        'FTP Importer',
        'manage_options',
        'woo-ftp-importer',
        'wftp_admin_page',
        'dashicons-upload',
        30
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
        
        // Nova configuração para pasta de pré-visualização
        $preview_folder_path = sanitize_text_field($_POST['wftp_preview_folder_path']);
        update_option('wftp_preview_folder_path', $preview_folder_path);
        
        // Campo ACF para armazenar a pré-visualização
        $acf_field_group = sanitize_text_field($_POST['wftp_acf_field_group']);
        update_option('wftp_acf_field_group', $acf_field_group);
        
        $acf_preview_field = sanitize_text_field($_POST['wftp_acf_preview_field']);
        update_option('wftp_acf_preview_field', $acf_preview_field);
        
        echo '<div class="notice notice-success is-dismissible"><p>Settings saved successfully.</p></div>';
    }
    
    // Reset database if requested
    if (isset($_POST['wftp_reset_db']) && wp_verify_nonce($_POST['wftp_reset_nonce'], 'wftp_reset_db')) {
        delete_option('wftp_processed_files');
        delete_option('wftp_last_scan_time');
        echo '<div class="notice notice-success is-dismissible"><p>Database reset successfully. All files will be treated as new in the next scan.</p></div>';
    }
    
    // Clear scanning lock if requested
    if (isset($_POST['wftp_clear_lock']) && wp_verify_nonce($_POST['wftp_reset_nonce'], 'wftp_reset_db')) {
        delete_transient('wftp_scanning_lock');
        wftp_add_log("Scanning lock was manually cleared by admin.");
        echo '<div class="notice notice-success is-dismissible"><p>Scanning lock cleared successfully. You can now run a scan again.</p></div>';
    }
    
    // Diagnose and fix ACF preview files
    if (isset($_POST['wftp_fix_acf_previews']) && wp_verify_nonce($_POST['wftp_reset_nonce'], 'wftp_reset_db')) {
        $fixed = wftp_diagnose_and_fix_acf_previews();
        echo '<div class="notice notice-success is-dismissible"><p>ACF Preview diagnosis completed. ' . $fixed . ' product previews fixed/updated.</p></div>';
    }
    
    // Get current settings
    $base_folder_path = get_option('wftp_base_folder_path', ABSPATH . 'wp-content/uploads/user_folders');
    $scan_interval = get_option('wftp_scan_interval', 1);
    $default_price = get_option('wftp_default_price', 9.99);
    $product_status = get_option('wftp_product_status', 'draft');
    $log_enabled = get_option('wftp_log_enabled', 1);
    $remove_deleted_files = get_option('wftp_remove_deleted_files', 1);
    $preview_folder_path = get_option('wftp_preview_folder_path', ABSPATH . 'wp-content/uploads/preview_files');
    $acf_field_group = get_option('wftp_acf_field_group', 'product_details');
    $acf_preview_field = get_option('wftp_acf_preview_field', 'preview_file');
    
    // Manual scan trigger
    if (isset($_POST['wftp_manual_scan']) && wp_verify_nonce($_POST['wftp_nonce'], 'wftp_manual_scan')) {
        wftp_scan_folders();
        echo '<div class="notice notice-success is-dismissible"><p>Manual scan completed. Check the log for details.</p></div>';
    }
    
    // Get last scan time
    $last_scan = get_option('wftp_last_scan_time', 0);
    $last_scan_date = ($last_scan > 0) ? date_i18n('Y-m-d H:i:s', $last_scan) : 'Never';
    $next_scan = ($last_scan > 0) ? date_i18n('Y-m-d H:i:s', $last_scan + ($scan_interval * 60)) : 'After first page load';
    
    // Get processed files count
    $processed_files = get_option('wftp_processed_files', array());
    $processed_count = count($processed_files);
    
    // Display the form
    ?>
    <div class="wrap">
        <h1>WooCommerce FTP Folder Importer (Create Once Mode)</h1>
        
        <div class="notice notice-info">
            <p><strong>Create Once Mode:</strong> In this mode, the plugin will only create products for files it has never seen before. 
            It will not update existing products even if the files change.</p>
        </div>
        
        <h2>Settings</h2>
        <form method="post" action="">
            <?php wp_nonce_field('wftp_save_settings', 'wftp_nonce'); ?>
            
            <table class="form-table">
                <tr valign="top">
                    <th scope="row">
                        <label for="wftp_base_folder_path">Base Folder Path</label>
                    </th>
                    <td>
                        <input type="text" id="wftp_base_folder_path" name="wftp_base_folder_path" class="regular-text" 
                            value="<?php echo esc_attr($base_folder_path); ?>" />
                        <p class="description">
                            Enter the absolute server path to the base folder containing user FTP folders
                        </p>
                    </td>
                </tr>
                <tr valign="top">
                    <th scope="row">
                        <label for="wftp_preview_folder_path">Preview Files Folder Path</label>
                    </th>
                    <td>
                        <input type="text" id="wftp_preview_folder_path" name="wftp_preview_folder_path" class="regular-text" 
                            value="<?php echo esc_attr($preview_folder_path); ?>" />
                        <p class="description">
                            Enter the absolute server path to the folder containing preview files (will be linked to ACF field)
                        </p>
                    </td>
                </tr>
                <tr valign="top">
                    <th scope="row">
                        <label for="wftp_acf_field_group">ACF Field Group Name</label>
                    </th>
                    <td>
                        <input type="text" id="wftp_acf_field_group" name="wftp_acf_field_group" class="regular-text" 
                            value="<?php echo esc_attr($acf_field_group); ?>" />
                        <p class="description">
                            Enter the ACF field group name that contains the preview file field
                        </p>
                    </td>
                </tr>
                <tr valign="top">
                    <th scope="row">
                        <label for="wftp_acf_preview_field">ACF Preview Field Name</label>
                    </th>
                    <td>
                        <input type="text" id="wftp_acf_preview_field" name="wftp_acf_preview_field" class="regular-text" 
                            value="<?php echo esc_attr($acf_preview_field); ?>" />
                        <p class="description">
                            Enter the ACF field name that will store preview files (must be a file field type)
                        </p>
                    </td>
                </tr>
                <tr valign="top">
                    <th scope="row">
                        <label for="wftp_scan_interval">Scan Interval (minutes)</label>
                    </th>
                    <td>
                        <input type="number" id="wftp_scan_interval" name="wftp_scan_interval" min="1" 
                            value="<?php echo esc_attr($scan_interval); ?>" />
                    </td>
                </tr>
                <tr valign="top">
                    <th scope="row">
                        <label for="wftp_default_price">Default Product Price</label>
                    </th>
                    <td>
                        <input type="number" id="wftp_default_price" name="wftp_default_price" min="0" step="0.01" 
                            value="<?php echo esc_attr($default_price); ?>" />
                        <p class="description">
                            Default price for new products when no price information is available
                        </p>
                    </td>
                </tr>
                <tr valign="top">
                    <th scope="row">
                        <label for="wftp_product_status">Product Status</label>
                    </th>
                    <td>
                        <select id="wftp_product_status" name="wftp_product_status">
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
                        <label for="wftp_remove_deleted_files">Remove Deleted Products</label>
                    </th>
                    <td>
                        <input type="checkbox" id="wftp_remove_deleted_files" name="wftp_remove_deleted_files" value="1" <?php checked($remove_deleted_files, 1); ?> />
                        <p class="description">
                            Automatically delete products when their source files are removed
                        </p>
                    </td>
                </tr>
                <tr valign="top">
                    <th scope="row">
                        <label for="wftp_log_enabled">Enable Logging</label>
                    </th>
                    <td>
                        <input type="checkbox" id="wftp_log_enabled" name="wftp_log_enabled" value="1" <?php checked($log_enabled, 1); ?> />
                        <p class="description">
                            Log import activities for debugging
                        </p>
                    </td>
                </tr>
            </table>
            
            <p class="submit">
                <input type="submit" name="wftp_save_settings" class="button-primary" value="Save Settings" />
            </p>
        </form>
        
        <div class="wftp-scan-status" style="margin-bottom: 20px; padding: 15px; background: #f0f0f1; border-left: 4px solid #2271b1;" data-last-scan="<?php echo esc_attr($last_scan); ?>">
            <h3 style="margin-top: 0;">Automatic Scanning Status</h3>
            <p><strong>Last scan:</strong> <?php echo esc_html($last_scan_date); ?></p>
            <p><strong>Next scan:</strong> <?php echo esc_html($next_scan); ?></p>
            <p><strong>Files already processed:</strong> <?php echo esc_html($processed_count); ?> (these will not create new products)</p>
            <p>The scanner will automatically run once every <?php echo esc_html($scan_interval); ?> minute(s) when your site receives visitors.</p>
        </div>
        
        <div class="wftp-actions" style="display: flex; gap: 10px; margin-bottom: 20px;">
            <form method="post" action="">
                <?php wp_nonce_field('wftp_manual_scan', 'wftp_nonce'); ?>
                <input type="submit" name="wftp_manual_scan" class="button-secondary" value="Run Manual Scan Now" />
            </form>
            
            <form method="post" action="">
                <?php wp_nonce_field('wftp_reset_db', 'wftp_reset_nonce'); ?>
                <input type="submit" name="wftp_reset_db" class="button-secondary" value="Reset Database" 
                    onclick="return confirm('Are you sure? This will cause ALL files to be treated as new and potentially create duplicate products.');" />
                
                <input type="submit" name="wftp_clear_lock" class="button-secondary" value="Clear Scanning Lock" 
                    style="background-color:#ffaa00;border-color:#ff8800;color:#fff;" 
                    title="Use this if scans are not running and you see 'Scan already running' messages in the log" />
                
                <input type="submit" name="wftp_fix_acf_previews" class="button-secondary" value="Fix ACF Preview Files" 
                    style="background-color:#0073aa;border-color:#005a87;color:#fff;" 
                    title="Use this to diagnose and fix preview files that are not showing in the ACF interface" />
            </form>
        </div>
        
        <h2>Import Log</h2>
        <div class="wftp-log-container" style="max-height: 300px; overflow-y: auto; border: 1px solid #ccc; padding: 10px; background: #f9f9f9;">
            <?php
            $log_entries = get_option('wftp_import_log', array());
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
                    <?php wp_nonce_field('wftp_clear_log', 'wftp_nonce'); ?>
                    <input type="submit" name="wftp_clear_log" class="button-secondary" value="Clear Log" />
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
    </div>
    <?php
}

/**
 * Add log entry
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
 * Get all user folders
 */
function wftp_get_folder_user_mappings() {
    $base_folder = get_option('wftp_base_folder_path', ABSPATH . 'wp-content/uploads/user_folders');
    $mappings = array();
    
    if (!is_dir($base_folder)) {
        return $mappings;
    }
    
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
 * Main function to scan folders and create products (only for new files)
 */
function wftp_scan_folders() {
    // Set a lock to prevent duplicate processing
    $lock_transient = 'wftp_scanning_lock';
    
    // Verificar se existe um bloqueio antigo (mais de 10 minutos)
    $lock_time = get_transient('wftp_scanning_lock_time');
    if ($lock_time && (time() - $lock_time > 600)) {
        // Se o bloqueio é muito antigo, força a liberação
        delete_transient($lock_transient);
        delete_transient('wftp_scanning_lock_time');
        wftp_add_log("Expired scanning lock detected and cleared (lock was older than 10 minutes)");
    }
    
    if (get_transient($lock_transient)) {
        wftp_add_log("Scan already running. Skipping this execution.");
        return;
    }
    
    // Set lock for 5 minutes max and record the time
    set_transient($lock_transient, true, 5 * MINUTE_IN_SECONDS);
    set_transient('wftp_scanning_lock_time', time(), 30 * MINUTE_IN_SECONDS);
    
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
        
        // Get already processed files
        $processed_files = get_option('wftp_processed_files', array());
        
        // Current files for deletion tracking
        $current_files = array();
        
        // Get folder to user mappings
        $folder_mappings = wftp_get_folder_user_mappings();
        
        $total_created = 0;
        $total_skipped = 0;
        
        // Process each user folder
        foreach ($folder_mappings as $folder_name => $user_id) {
            $user_folder_path = $base_folder . '/' . $folder_name;
            
            wftp_add_log("Scanning folder for user #{$user_id}: $folder_name");
            
            // Get all files within this user's folder
            $files = scandir($user_folder_path);
            $files = array_diff($files, array('.', '..'));
            
            foreach ($files as $file) {
                $file_path = $user_folder_path . '/' . $file;
                
                // Skip subfolders
                if (is_dir($file_path)) {
                    continue;
                }
                
                // Generate the unique key for this product
                $product_key = sanitize_title($user_id . '-' . $file);
                
                // Track this file for deletion checking
                $current_files[$product_key] = $file_path;
                
                // Check if we've already processed this file before
                if (isset($processed_files[$product_key])) {
                    // We've already created a product for this file - SKIP IT COMPLETELY
                    $total_skipped++;
                    continue;
                }
                
                // This is a NEW file - create a product for it
                $result = wftp_create_product($file, $file_path, $user_id, $product_key);
                
                if ($result === 'created') {
                    $total_created++;
                    wftp_add_log("Created new product from file: $file (user: $user_id)");
                    
                    // Add to processed files to avoid future processing
                    $processed_files[$product_key] = time();
                } elseif ($result === 'error') {
                    wftp_add_log("Error processing file: $file (user: $user_id)");
                }
            }
        }
        
        // Depois de processar todos os produtos, processe os arquivos de pré-visualização
        try {
            wftp_process_preview_files();
        } catch (Exception $e) {
            wftp_add_log("Error during preview processing: " . $e->getMessage());
        }
        
        // Update processed files list
        update_option('wftp_processed_files', $processed_files);
        
        // Check for deleted files and remove corresponding products
        if ($remove_deleted) {
            $deleted_count = wftp_remove_deleted_products($current_files, $processed_files);
            
            if ($deleted_count > 0) {
                wftp_add_log("Removed $deleted_count products for deleted files");
            }
        }
        
        wftp_add_log("Scan completed. Created: $total_created, Skipped: $total_skipped products");
    } catch (Exception $e) {
        wftp_add_log("Error during scan: " . $e->getMessage());
    } finally {
        // Sempre libera o bloqueio, mesmo em caso de erro
        delete_transient($lock_transient);
        delete_transient('wftp_scanning_lock_time');
        wftp_add_log("Scanning lock released");
    }
}

/**
 * Process preview files and attach them to products via ACF fields
 */
function wftp_process_preview_files() {
    $preview_folder = get_option('wftp_preview_folder_path', ABSPATH . 'wp-content/uploads/preview_files');
    $acf_field_group = get_option('wftp_acf_field_group', 'product_details');
    $acf_field_name = get_option('wftp_acf_preview_field', 'preview_file');
    
    // Verificar se ACF está ativo
    if (!function_exists('update_field')) {
        wftp_add_log("Advanced Custom Fields não está ativo. Não foi possível processar arquivos de pré-visualização.");
        return;
    }
    
    // Verificar se o campo ACF existe antes de iniciar o processamento
    $field_exists = wftp_verify_acf_field($acf_field_group, $acf_field_name);
    if (!$field_exists) {
        return;
    }
    
    // Get folder to user mappings (reutilizando a mesma estrutura de pastas)
    $folder_mappings = wftp_get_folder_user_mappings();
    $preview_counts = ['processed' => 0, 'skipped' => 0, 'attached' => 0];
    
    // Processar cada pasta de usuário
    foreach ($folder_mappings as $folder_name => $user_id) {
        $user_preview_path = $preview_folder . '/' . $folder_name;
        
        // Verificar se existe pasta de pré-visualização para este usuário
        if (!is_dir($user_preview_path)) {
            continue;
        }
        
        wftp_add_log("Escaneando pasta de pré-visualização para usuário #{$user_id}: $folder_name");
        
        // Obter todos os arquivos de pré-visualização
        $preview_files = glob($user_preview_path . '/*');
        
        foreach ($preview_files as $preview_file) {
            if (is_dir($preview_file)) {
                continue; // Pular subpastas
            }
            
            $filename = basename($preview_file);
            $basename = pathinfo($filename, PATHINFO_FILENAME); // Nome sem extensão
            
            $preview_counts['processed']++;
            
            // Encontrar o produto correspondente
            $product = wftp_find_product_by_filename($basename, $user_id);
            
            if (!$product) {
                wftp_add_log("Nenhum produto encontrado para o arquivo de pré-visualização: $filename (usuário: $user_id)");
                $preview_counts['skipped']++;
                continue;
            }
            
            // Anexar o arquivo ao campo ACF
            $result = wftp_attach_preview_to_acf($product, $preview_file, $acf_field_name);
            
            if ($result) {
                $preview_counts['attached']++;
                wftp_add_log("Anexado arquivo de pré-visualização ao produto '{$product->get_name()}': $filename");
            } else {
                $preview_counts['skipped']++;
                wftp_add_log("Falha ao anexar arquivo de pré-visualização ao produto '{$product->get_name()}': $filename");
            }
        }
    }
    
    wftp_add_log("Processamento de pré-visualização concluído. Processados: {$preview_counts['processed']}, " . 
                 "Anexados: {$preview_counts['attached']}, Ignorados: {$preview_counts['skipped']}");
}

/**
 * Find a product by the source filename
 */
function wftp_find_product_by_filename($basename, $user_id) {
    // Busca por correspondência exata no nome do arquivo
    $products = wc_get_products([
        'limit' => 1,
        'status' => ['publish', 'draft', 'pending', 'private'],
        'meta_key' => '_wftp_user_id',
        'meta_value' => $user_id,
    ]);
    
    // Verificar se algum produto corresponde ao basename
    foreach ($products as $product) {
        $source_file = $product->get_meta('_wftp_source_file');
        $source_basename = pathinfo($source_file, PATHINFO_FILENAME);
        
        if ($source_basename === $basename) {
            return $product;
        }
    }
    
    // Se não encontrou por correspondência exata, tenta pelo nome do produto
    $products = wc_get_products([
        'limit' => -1,
        'status' => ['publish', 'draft', 'pending', 'private'],
        'meta_key' => '_wftp_user_id',
        'meta_value' => $user_id,
    ]);
    
    foreach ($products as $product) {
        // Remove espaços, traços e sublinhados para comparação mais flexível
        $product_name = str_replace([' ', '-', '_'], '', strtolower($product->get_name()));
        $search_basename = str_replace([' ', '-', '_'], '', strtolower($basename));
        
        if ($product_name === $search_basename) {
            return $product;
        }
    }
    
    return false;
}

/**
 * Attach a preview file to a product using ACF
 */
function wftp_attach_preview_to_acf($product, $preview_file, $acf_field_name) {
    if (!function_exists('update_field') || !function_exists('get_field')) {
        wftp_add_log("ACF functions not available. Skipping preview attachment.");
        return false;
    }
    
    // Obter ID do produto
    $product_id = $product->get_id();
    
    // Obter o nome do grupo de campos ACF
    $acf_field_group = get_option('wftp_acf_field_group', 'product_details');
    
    try {
        // Upload do arquivo para a biblioteca de mídia do WordPress
        $file_info = wp_upload_bits(basename($preview_file), null, file_get_contents($preview_file));
        
        if (!$file_info['error']) {
            $file_path = $file_info['file'];
            $file_type = wp_check_filetype(basename($file_path), null);
            
            $attachment_data = [
                'post_mime_type' => $file_type['type'],
                'post_title' => sanitize_file_name(basename($file_path)),
                'post_content' => '',
                'post_status' => 'inherit'
            ];
            
            $attach_id = wp_insert_attachment($attachment_data, $file_path, $product_id);
            
            if ($attach_id) {
                // Gerar metadados para o anexo e atualizar o banco de dados
                require_once(ABSPATH . 'wp-admin/includes/image.php');
                $attach_data = wp_generate_attachment_metadata($attach_id, $file_path);
                wp_update_attachment_metadata($attach_id, $attach_data);
                
                // MÉTODO CORRIGIDO: Abordagem direta para atualização do campo ACF
                // Dependendo da versão do ACF e de como o campo está configurado, 
                // diferentes abordagens podem ser necessárias
                
                // 1. Identificar o campo ACF exato
                if (!empty($acf_field_group)) {
                    // Se estamos usando um grupo, precisamos encontrar o campo correto
                    $field_key = wftp_get_acf_field_key($acf_field_name, $acf_field_group);
                    if ($field_key) {
                        // Atualizar usando o field key (mais confiável)
                        update_field($field_key, $attach_id, $product_id);
                        wftp_add_log("Campo ACF atualizado via field key: $field_key para produto #{$product_id}");
                    } else {
                        // Tentar atualizar diretamente pelo nome do campo dentro do grupo
                        wftp_add_log("Tentando atualizar campo ACF diretamente pelo nome: $acf_field_name");
                        
                        // Recuperar o valor atual do grupo
                        if (have_rows($acf_field_group, $product_id)) {
                            while (have_rows($acf_field_group, $product_id)) {
                                the_row();
                                // Atualizar apenas o campo dentro do grupo
                                update_sub_field($acf_field_name, $attach_id);
                                wftp_add_log("Campo atualizado via update_sub_field()");
                            }
                        } else {
                            // Se o grupo ainda não existe, crie-o com o campo
                            $group_value = array(
                                $acf_field_name => $attach_id
                            );
                            update_field($acf_field_group, $group_value, $product_id);
                            wftp_add_log("Novo grupo criado com o campo de pré-visualização");
                        }
                    }
                } else {
                    // Campo direto (sem grupo)
                    $field_key = wftp_get_acf_field_key($acf_field_name);
                    if ($field_key) {
                        update_field($field_key, $attach_id, $product_id);
                        wftp_add_log("Campo ACF atualizado via field key: $field_key");
                    } else {
                        update_field($acf_field_name, $attach_id, $product_id);
                        wftp_add_log("Campo ACF atualizado pelo nome: $acf_field_name");
                    }
                }
                
                // Como backup, também atualiza diretamente no post meta
                update_post_meta($product_id, $acf_field_name, $attach_id);
                
                // Registra o ID no campo específico para diagnóstico
                update_post_meta($product_id, '_wftp_preview_attachment_id', $attach_id);
                
                // Tenta forçar a atualização do cache do ACF
                if (function_exists('acf_flush_value_cache')) {
                    acf_flush_value_cache($product_id);
                }
                
                return true;
            }
        }
    } catch (Exception $e) {
        wftp_add_log("Erro ao anexar arquivo de pré-visualização: " . $e->getMessage());
    }
    
    return false;
}

/**
 * Obtém a chave do campo ACF a partir do nome
 */
function wftp_get_acf_field_key($field_name, $group_name = '') {
    if (!function_exists('acf_get_field_groups') || !function_exists('acf_get_fields')) {
        return false;
    }
    
    // Se temos um nome de grupo, pesquisar apenas nesse grupo
    if (!empty($group_name)) {
        $field_groups = acf_get_field_groups(array('title' => $group_name));
        if (empty($field_groups)) {
            // Pesquisa menos restritiva
            $field_groups = acf_get_field_groups();
            $normalized_group_name = strtolower(str_replace(['_', ' '], '', $group_name));
            
            foreach ($field_groups as $index => $group) {
                $normalized_title = strtolower(str_replace(['_', ' '], '', $group['title']));
                if ($normalized_title !== $normalized_group_name) {
                    unset($field_groups[$index]);
                }
            }
        }
    } else {
        // Pesquisar em todos os grupos
        $field_groups = acf_get_field_groups();
    }
    
    foreach ($field_groups as $group) {
        $fields = acf_get_fields($group);
        if (!$fields) continue;
        
        foreach ($fields as $field) {
            // Comparação exata ou normalizada
            if ($field['name'] === $field_name) {
                return $field['key'];
            }
            
            // Comparação menos restritiva
            $normalized_field_name = strtolower(str_replace(['_', ' '], '', $field_name));
            $normalized_name = strtolower(str_replace(['_', ' '], '', $field['name']));
            
            if ($normalized_name === $normalized_field_name) {
                return $field['key'];
            }
        }
    }
    
    return false;
}

/**
 * Verifica e valida a existência dos campos ACF antes do processamento
 */
function wftp_verify_acf_field($group_name, $field_name) {
    if (!function_exists('acf_get_field_groups')) {
        wftp_add_log("As funções avançadas do ACF não estão disponíveis. Usando modo de compatibilidade.");
        return true;
    }
    
    // Verificar se o campo existe usando funções nativas do ACF
    $field_exists = wftp_acf_field_exists($group_name, $field_name);
    
    if (!$field_exists) {
        // Tentar obter todos os grupos de campo para debug
        $all_groups = acf_get_field_groups();
        $group_names = [];
        foreach ($all_groups as $group) {
            $group_names[] = $group['title'] . ' (key: ' . $group['key'] . ')';
        }
        
        wftp_add_log("ERRO: O campo ACF '$field_name' no grupo '$group_name' não existe. Verifique sua configuração no ACF.");
        wftp_add_log("Grupos ACF disponíveis: " . implode(', ', $group_names));
        
        // Tentar obter campos do primeiro grupo para ajudar no debug
        if (!empty($all_groups)) {
            $first_group = $all_groups[0];
            $fields = acf_get_fields($first_group);
            $field_names = [];
            foreach ($fields as $field) {
                $field_names[] = $field['name'] . ' (label: ' . $field['label'] . ')';
            }
            wftp_add_log("Campos no grupo '{$first_group['title']}': " . implode(', ', $field_names));
        }
        
        return false;
    }
    
    return true;
}

/**
 * Verifica se um campo ACF existe dentro de um grupo de campos
 * Versão melhorada com busca mais flexível
 */
function wftp_acf_field_exists($group_name, $field_name) {
    if (!function_exists('acf_get_field_groups')) {
        // Se a função ACF não estiver disponível, presuma que está correto
        return true;
    }
    
    // Primeiro, tentar busca exata pelo título
    $field_groups = acf_get_field_groups(array('title' => $group_name));
    
    // Se não encontrar pelo título exato, tenta uma busca menos restritiva
    if (empty($field_groups)) {
        $all_groups = acf_get_field_groups();
        foreach ($all_groups as $group) {
            // Compara de forma case insensitive e removendo espaços/underscores
            $normalized_group_name = strtolower(str_replace(['_', ' '], '', $group_name));
            $normalized_title = strtolower(str_replace(['_', ' '], '', $group['title']));
            
            if ($normalized_group_name == $normalized_title) {
                $field_groups = [$group];
                break;
            }
        }
    }
    
    if (empty($field_groups)) {
        return false;
    }
    
    // Verificar em todos os grupos encontrados
    foreach ($field_groups as $group) {
        $fields = acf_get_fields($group);
        if (!$fields) continue;
        
        foreach ($fields as $field) {
            // Busca pelo nome exato
            if ($field['name'] == $field_name) {
                return true;
            }
            
            // Busca mais flexível (case insensitive/remover underscores)
            $normalized_field_name = strtolower(str_replace(['_', ' '], '', $field_name));
            $normalized_name = strtolower(str_replace(['_', ' '], '', $field['name']));
            
            if ($normalized_field_name == $normalized_name) {
                return true;
            }
        }
    }
    
    return false;
}

/**
 * Diagnóstico e correção de arquivos de pré-visualização
 */
function wftp_diagnose_and_fix_acf_previews() {
    global $wpdb;
    wftp_add_log("Iniciando diagnóstico e correção dos campos ACF de pré-visualização...");
    
    $acf_field_group = get_option('wftp_acf_field_group', 'product_details');
    $acf_field_name = get_option('wftp_acf_preview_field', 'preview_file');
    
    // Buscar todos os produtos que têm um ID de anexo de pré-visualização armazenado
    $products_with_previews = $wpdb->get_results(
        "SELECT post_id, meta_value 
         FROM {$wpdb->postmeta} 
         WHERE meta_key = '_wftp_preview_attachment_id'"
    );
    
    $fixed_count = 0;
    
    if (empty($products_with_previews)) {
        wftp_add_log("Nenhum produto com pré-visualização encontrado para diagnóstico.");
        return $fixed_count;
    }
    
    wftp_add_log("Encontrados " . count($products_with_previews) . " produtos com pré-visualizações para diagnóstico.");
    
    // Tente obter a chave de campo ACF
    $field_key = wftp_get_acf_field_key($acf_field_name, $acf_field_group);
    
    foreach ($products_with_previews as $item) {
        $product_id = $item->post_id;
        $attachment_id = $item->meta_value;
        
        // Verificar se o anexo existe
        if (!wp_get_attachment_url($attachment_id)) {
            wftp_add_log("Produto #{$product_id}: Anexo #{$attachment_id} não existe mais. Pulando.");
            continue;
        }
        
        // MÉTODO 1: Verificar/corrigir usando field key se disponível
        if ($field_key) {
            update_field($field_key, $attachment_id, $product_id);
            wftp_add_log("Produto #{$product_id}: Campo atualizado via field_key '{$field_key}' com anexo #{$attachment_id}");
            $fixed_count++;
            continue;
        }
        
        // MÉTODO 2: Verificar/corrigir usando update_field diretamente
        $current_value = get_field($acf_field_name, $product_id);
        if (!$current_value || $current_value != $attachment_id) {
            update_field($acf_field_name, $attachment_id, $product_id);
            wftp_add_log("Produto #{$product_id}: Campo atualizado via update_field com anexo #{$attachment_id}");
            $fixed_count++;
            continue;
        }
        
        // MÉTODO 3: Verificar/corrigir caso esteja em um grupo
        if (!empty($acf_field_group)) {
            $group_value = get_field($acf_field_group, $product_id, true);
            
            if (is_array($group_value)) {
                $group_value[$acf_field_name] = $attachment_id;
                update_field($acf_field_group, $group_value, $product_id);
                wftp_add_log("Produto #{$product_id}: Campo atualizado via grupo '{$acf_field_group}' com anexo #{$attachment_id}");
                $fixed_count++;
                continue;
            }
        }
        
        // MÉTODO 4: Verificar/corrigir usando post meta diretamente
        $meta_field = "_{$acf_field_name}";
        update_post_meta($product_id, $meta_field, $attachment_id);
        update_post_meta($product_id, $acf_field_name, $attachment_id);
        wftp_add_log("Produto #{$product_id}: Campo atualizado via post_meta com anexo #{$attachment_id}");
        $fixed_count++;
    }
    
    wftp_add_log("Diagnóstico concluído. Campos corrigidos/atualizados: {$fixed_count}");
    return $fixed_count;
}

/**
 * Create a product for a file (only called for new files)
 */
function wftp_create_product($filename, $file_path, $user_id, $product_key) {
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
            wftp_add_log("Error creating category for folder: $folder_name");
            return 'error';
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
        
        // Set post author explicitly to match folder owner
        wp_update_post(array(
            'ID' => $product_id,
            'post_author' => $user_id
        ));
        
        return 'created';
    }
    
    return 'error';
}

/**
 * Remove products when their source files are deleted
 */
function wftp_remove_deleted_products($current_files, &$processed_files) {
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
    
    return $deleted_count;
}

/**
 * Update product image
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

/**
 * Auto-scanner function
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

// Set up the delayed scan hook
add_action('wftp_delayed_scan_hook', 'wftp_scan_folders');