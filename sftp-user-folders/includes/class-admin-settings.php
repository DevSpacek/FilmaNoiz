<?php
/**
 * Admin Settings Class for SFTP User Folders Plugin
 */
class AdminSettings {
    
    public function __construct() {
        // Hooks for admin settings
        add_action('admin_init', array($this, 'register_settings'));
        add_action('admin_menu', array($this, 'add_admin_menu'));
    }

    /**
     * Register plugin settings
     */
    public function register_settings() {
        register_setting('sftp_user_folders', 'sftp_base_directory');
        register_setting('sftp_user_folders', 'sftp_selected_roles');
        register_setting('sftp_user_folders', 'sftp_folder_structure');
        register_setting('sftp_user_folders', 'sftp_folder_naming');
    }

    /**
     * Add admin menu for the plugin
     */
    public function add_admin_menu() {
        add_options_page(
            'SFTP User Folders',
            'SFTP User Folders',
            'manage_options',
            'sftp-user-folders',
            array($this, 'render_settings_page')
        );
    }

    /**
     * Render the settings page
     */
    public function render_settings_page() {
        if (!current_user_can('manage_options')) {
            wp_die('Access denied');
        }

        // Get current settings
        $base_directory = get_option('sftp_base_directory');
        $selected_roles = get_option('sftp_selected_roles', array());
        $folder_structure = get_option('sftp_folder_structure', "imports\nimagens\ndocumentos");
        $folder_naming = get_option('sftp_folder_naming', 'username');

        if (empty($selected_roles) || !is_array($selected_roles)) {
            $selected_roles = array();
        }

        ?>
        <div class="wrap">
            <h1>SFTP User Folders</h1>
            <form method="post" action="options.php">
                <?php settings_fields('sftp_user_folders'); ?>
                
                <table class="form-table">
                    <tr>
                        <th scope="row">Base Directory</th>
                        <td>
                            <input type="text" name="sftp_base_directory" value="<?php echo esc_attr($base_directory); ?>" class="regular-text" />
                            <p class="description">Absolute path for the base directory where user folders will be created.</p>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row">User Roles</th>
                        <td>
                            <?php
                            $all_roles = wp_roles()->get_names();
                            foreach ($all_roles as $role_id => $role_name) {
                                $checked = in_array($role_id, $selected_roles) ? 'checked' : '';
                                ?>
                                <label>
                                    <input type="checkbox" name="sftp_selected_roles[]" value="<?php echo esc_attr($role_id); ?>" <?php echo $checked; ?> />
                                    <?php echo esc_html($role_name); ?>
                                </label><br>
                                <?php
                            }
                            ?>
                            <p class="description">Select which user roles will have folders created automatically.</p>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row">Folder Structure</th>
                        <td>
                            <textarea name="sftp_folder_structure" rows="5" class="large-text"><?php echo esc_textarea($folder_structure); ?></textarea>
                            <p class="description">List of subfolders to be created for each user (one per line).</p>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row">Folder Naming</th>
                        <td>
                            <select name="sftp_folder_naming">
                                <option value="username" <?php selected('username', $folder_naming); ?>>Username</option>
                                <option value="user_id" <?php selected('user_id', $folder_naming); ?>>User ID</option>
                                <option value="email" <?php selected('email', $folder_naming); ?>>Email (before @)</option>
                                <option value="display_name" <?php selected('display_name', $folder_naming); ?>>Display Name</option>
                            </select>
                            <p class="description">How user folders will be named.</p>
                        </td>
                    </tr>
                </table>
                
                <?php submit_button('Save Changes'); ?>
            </form>
        </div>
        <?php
    }
}
?>