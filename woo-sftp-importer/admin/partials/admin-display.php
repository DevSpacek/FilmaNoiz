<?php
// This file contains the HTML that is displayed on the plugin's admin page.

?>

<div class="wrap">
    <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
    
    <form method="post" action="options.php">
        <?php
        // Output security fields for the registered setting "woo_sftp_importer_options"
        settings_fields('woo_sftp_importer_options');
        
        // Output setting sections and their fields
        do_settings_sections('woo_sftp_importer');
        
        // Output the save settings button
        submit_button(__('Save Settings', 'woo-sftp-importer'));
        ?>
    </form>
    
    <h2><?php _e('SFTP Connection Settings', 'woo-sftp-importer'); ?></h2>
    <table class="form-table">
        <tr valign="top">
            <th scope="row"><?php _e('SFTP Host', 'woo-sftp-importer'); ?></th>
            <td><input type="text" name="woo_sftp_host" value="<?php echo esc_attr(get_option('woo_sftp_host')); ?>" /></td>
        </tr>
        <tr valign="top">
            <th scope="row"><?php _e('SFTP Port', 'woo-sftp-importer'); ?></th>
            <td><input type="number" name="woo_sftp_port" value="<?php echo esc_attr(get_option('woo_sftp_port', 22)); ?>" /></td>
        </tr>
        <tr valign="top">
            <th scope="row"><?php _e('Username', 'woo-sftp-importer'); ?></th>
            <td><input type="text" name="woo_sftp_username" value="<?php echo esc_attr(get_option('woo_sftp_username')); ?>" /></td>
        </tr>
        <tr valign="top">
            <th scope="row"><?php _e('Password', 'woo-sftp-importer'); ?></th>
            <td><input type="password" name="woo_sftp_password" value="<?php echo esc_attr(get_option('woo_sftp_password')); ?>" /></td>
        </tr>
    </table>
</div>