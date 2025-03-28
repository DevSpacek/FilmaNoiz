<?php
/**
 * Grant access to original video after purchase
 */
function wmkv_grant_access_after_purchase($order_id) {
    $order = wc_get_order($order_id);
    
    if (!$order) {
        return;
    }
    
    foreach ($order->get_items() as $item_id => $item) {
        $product_id = $item->get_product_id();
        $product = wc_get_product($product_id);
        
        if ($product && $product->is_downloadable()) {
            // Grant access to the original video
            $downloads = $product->get_downloads();
            foreach ($downloads as $download) {
                $download['access_granted'] = true;
            }
            $product->set_downloads($downloads);
            $product->save();
        }
    }
}
add_action('woocommerce_order_status_completed', 'wmkv_grant_access_after_purchase');