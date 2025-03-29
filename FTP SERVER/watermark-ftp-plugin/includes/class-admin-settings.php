<?php
class Watermark_FTP_Plugin_Admin_Settings {
    private $ftp_credentials;
    private $source_directory;
    private $destination_directory;

    public function __construct() {
        // Initialize settings
        $this->ftp_credentials = get_option('ftp_credentials');
        $this->source_directory = get_option('source_directory');
        $this->destination_directory = get_option('destination_directory');

        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_init', array($this, 'settings_init'));
    }

    public function add_admin_menu() {
        add_options_page('Watermark FTP Plugin', 'Watermark FTP', 'manage_options', 'watermark_ftp_plugin', array($this, 'options_page'));
    }

    public function settings_init() {
        register_setting('pluginPage', 'ftp_credentials');
        register_setting('pluginPage', 'source_directory');
        register_setting('pluginPage', 'destination_directory');

        add_settings_section('pluginPage_section', __('Configurações do Plugin', 'watermark-ftp-plugin'), null, 'pluginPage');

        add_settings_field('ftp_credentials', __('Credenciais FTP', 'watermark-ftp-plugin'), array($this, 'ftp_credentials_render'), 'pluginPage', 'pluginPage_section');
        add_settings_field('source_directory', __('Diretório de Origem', 'watermark-ftp-plugin'), array($this, 'source_directory_render'), 'pluginPage', 'pluginPage_section');
        add_settings_field('destination_directory', __('Diretório de Destino', 'watermark-ftp-plugin'), array($this, 'destination_directory_render'), 'pluginPage', 'pluginPage_section');
    }

    public function ftp_credentials_render() {
        ?>
        <input type='text' name='ftp_credentials' value='<?php echo $this->ftp_credentials; ?>'>
        <?php
    }

    public function source_directory_render() {
        ?>
        <input type='text' name='source_directory' value='<?php echo $this->source_directory; ?>'>
        <?php
    }

    public function destination_directory_render() {
        ?>
        <input type='text' name='destination_directory' value='<?php echo $this->destination_directory; ?>'>
        <?php
    }

    public function options_page() {
        ?>
        <form action='options.php' method='post'>
            <h2>Watermark FTP Plugin</h2>
            <?php
            settings_fields('pluginPage');
            do_settings_sections('pluginPage');
            submit_button();
            ?>
        </form>
        <?php
    }
}
?>