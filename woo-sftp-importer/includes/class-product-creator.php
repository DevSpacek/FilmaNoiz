<?php
/**
 * ProductCreator class
 *
 * This class handles the creation of products in WooCommerce from files received via SFTP.
 */

class ProductCreator {
    private $sftp_connection;

    public function __construct($sftp_connection) {
        $this->sftp_connection = $sftp_connection;
    }

    public function create_product($filename, $file_path, $user_id) {
        // Get file extension
        $file_extension = pathinfo($filename, PATHINFO_EXTENSION);
        
        // Remove extension from filename to use as product name
        $product_name = pathinfo($filename, PATHINFO_FILENAME);
        $product_name = str_replace(['-', '_'], ' ', $product_name);
        $product_name = ucwords($product_name);
        
        // Default price from settings
        $default_price = get_option('sftp_default_price', 9.99);
        
        // Get product status from settings
        $product_status = get_option('sftp_product_status', 'draft');
        
        $is_image = in_array(strtolower($file_extension), ['jpg', 'jpeg', 'png', 'gif', 'webp']);
        $is_downloadable = !$is_image;

        // Create new product
        $product = new WC_Product();
        $product->set_name($product_name);
        $product->set_status($product_status);
        $product->set_catalog_visibility('visible');
        $product->set_price($default_price);
        $product->set_regular_price($default_price);
        
        // Set basic user metadata
        $product->update_meta_data('_sftp_user_id', $user_id);
        $product->update_meta_data('_sftp_source_file', $filename);
        
        if ($is_downloadable) {
            // Add downloadable file
            $this->update_downloadable_file($product, $filename, $file_path);
        } elseif ($is_image) {
            // Set product image
            $this->update_product_image($product, $file_path, $filename);
        }
        
        $product_id = $product->save();
        
        return $product_id ? 'created' : 'error';
    }

    private function update_downloadable_file($product, $filename, $file_path) {
        $safe_filename = sanitize_file_name($filename);
        $upload_dir = wp_upload_dir();
        $downloads_dir = $upload_dir['basedir'] . '/sftp_uploads';

        if (!file_exists($downloads_dir)) {
            wp_mkdir_p($downloads_dir);
        }

        $new_file_path = $downloads_dir . '/' . $safe_filename;
        copy($file_path, $new_file_path);

        $file_url = $upload_dir['baseurl'] . '/sftp_uploads/' . $safe_filename;
        $download_id = md5($file_url);
        $downloads = [
            $download_id => [
                'id' => $download_id,
                'name' => $filename,
                'file' => $file_url,
            ],
        ];

        $product->set_downloadable(true);
        $product->set_downloads($downloads);
        $product->set_download_limit(-1);
        $product->set_download_expiry(-1);
        $product->save();
    }

    private function update_product_image($product, $image_path, $image_name) {
        $upload_dir = wp_upload_dir();
        $filename = wp_unique_filename($upload_dir['path'], $image_name);
        $new_file = $upload_dir['path'] . '/' . $filename;
        copy($image_path, $new_file);

        $filetype = wp_check_filetype($filename, null);
        $attachment = [
            'guid' => $upload_dir['url'] . '/' . $filename,
            'post_mime_type' => $filetype['type'],
            'post_title' => preg_replace('/\.[^.]+$/', '', $image_name),
            'post_content' => '',
            'post_status' => 'inherit',
        ];

        $attach_id = wp_insert_attachment($attachment, $new_file, $product->get_id());
        require_once(ABSPATH . 'wp-admin/includes/image.php');
        $attach_data = wp_generate_attachment_metadata($attach_id, $new_file);
        wp_update_attachment_metadata($attach_id, $attach_data);
        set_post_thumbnail($product->get_id(), $attach_id);
    }
}
?>