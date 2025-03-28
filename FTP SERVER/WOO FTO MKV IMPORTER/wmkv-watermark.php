<?php
/**
 * Generate a preview with watermark for a .mkv file
 */
function wmkv_generate_preview_with_watermark($file_path) {
    $output_path = str_replace('.mkv', '-preview.mkv', $file_path);
    $watermark_text = 'PREVIEW';
    
    // Command to add watermark using FFmpeg
    $command = "ffmpeg -i $file_path -vf \"drawtext=fontfile=/path/to/font.ttf:text='$watermark_text':x=10:y=10:fontsize=24:fontcolor=white\" $output_path";
    exec($command);
    
    return $output_path;
}

/**
 * Set preview with watermark as product image
 */
function wmkv_set_preview_with_watermark($product_id, $file_path) {
    $preview_path = wmkv_generate_preview_with_watermark($file_path);
    
    if (file_exists($preview_path)) {
        // Set product image
        wmkv_update_product_image($product_id, $preview_path, basename($preview_path));
    }
}

/**
 * Update product image
 */
function wmkv_update_product_image($product_id, $image_path, $image_name) {
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