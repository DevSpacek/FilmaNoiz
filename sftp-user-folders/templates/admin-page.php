<div class="wrap">
    <h1>SFTP User Folders Settings</h1>
    
    <form method="post" action="options.php">
        <?php settings_fields('sftp_user_folders'); ?>
        
        <table class="form-table">
            <tr>
                <th scope="row">SFTP Host</th>
                <td>
                    <input type="text" name="sftp_host" value="<?php echo esc_attr(get_option('sftp_host')); ?>" class="regular-text" />
                    <p class="description">Insira o endereço do servidor SFTP.</p>
                </td>
            </tr>
            <tr>
                <th scope="row">SFTP Usuário</th>
                <td>
                    <input type="text" name="sftp_username" value="<?php echo esc_attr(get_option('sftp_username')); ?>" class="regular-text" />
                    <p class="description">Insira o nome de usuário para autenticação SFTP.</p>
                </td>
            </tr>
            <tr>
                <th scope="row">SFTP Senha</th>
                <td>
                    <input type="password" name="sftp_password" value="<?php echo esc_attr(get_option('sftp_password')); ?>" class="regular-text" />
                    <p class="description">Insira a senha para autenticação SFTP.</p>
                </td>
            </tr>
            <tr>
                <th scope="row">Diretório Base</th>
                <td>
                    <input type="text" name="sftp_base_directory" value="<?php echo esc_attr(get_option('sftp_base_directory')); ?>" class="regular-text" />
                    <p class="description">Caminho absoluto para o diretório base onde as pastas de usuários serão criadas.</p>
                </td>
            </tr>
        </table>
        
        <?php submit_button('Salvar Alterações'); ?>
    </form>
</div>