<?php
/**
 * Plugin Name: Diagnóstico de Pastas FTP
 * Description: Ferramenta para diagnosticar problemas com a criação de pastas
 * Version: 1.0
 */

// Prevenir acesso direto
if (!defined('ABSPATH')) {
    exit;
}

// Adicionar página ao menu do admin
add_action('admin_menu', 'diagnostico_pastas_menu');

function diagnostico_pastas_menu() {
    add_management_page(
        'Diagnóstico de Pastas',
        'Diagnóstico de Pastas',
        'manage_options',
        'diagnostico-pastas',
        'diagnostico_pastas_page'
    );
}

function diagnostico_pastas_page() {
    if (!current_user_can('manage_options')) {
        wp_die('Acesso negado');
    }
    
    $results = array();
    $messages = array();
    
    // Processar formulário de teste
    if (isset($_POST['test_folder_creation']) && check_admin_referer('diagnostico_pastas')) {
        $test_folder = isset($_POST['test_path']) ? sanitize_text_field($_POST['test_path']) : '';
        
        if (empty($test_folder)) {
            $messages[] = array('error', 'Caminho não especificado');
        } else {
            // Tentar criar a pasta
            $result = wp_mkdir_p($test_folder);
            
            if ($result) {
                $messages[] = array('success', "Pasta criada com sucesso: $test_folder");
                
                // Escrever um arquivo de teste
                $test_file = trailingslashit($test_folder) . 'test-' . time() . '.txt';
                $file_result = file_put_contents($test_file, 'Este é um arquivo de teste.');
                
                if ($file_result !== false) {
                    $messages[] = array('success', "Arquivo de teste criado: $test_file");
                } else {
                    $messages[] = array('error', "Não foi possível criar um arquivo na pasta (permissões)");
                }
            } else {
                $messages[] = array('error', "Falha ao criar pasta: $test_folder");
            }
        }
    }
    
    // Verificar configurações do plugin JetEngine User Folders
    $juf_dir = get_option('juf_base_directory', '');
    $juf_exists = !empty($juf_dir) && file_exists($juf_dir);
    $juf_writable = $juf_exists && is_writable($juf_dir);
    
    ?>
    <div class="wrap">
        <h1>Diagnóstico de Pastas FTP</h1>
        
        <?php if (!empty($messages)): ?>
            <div id="message">
                <?php foreach ($messages as $message): ?>
                    <div class="notice notice-<?php echo $message[0]; ?> is-dismissible">
                        <p><?php echo $message[1]; ?></p>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
        
        <div class="card">
            <h2>Informações do Sistema</h2>
            <table class="widefat">
                <tr>
                    <th>Usuário do PHP</th>
                    <td><?php echo function_exists('posix_getpwuid') ? posix_getpwuid(posix_geteuid())['name'] : 'Não disponível'; ?></td>
                </tr>
                <tr>
                    <th>Diretório WordPress</th>
                    <td><?php echo ABSPATH; ?></td>
                </tr>
                <tr>
                    <th>Diretório de uploads</th>
                    <td>
                        <?php 
                        $uploads = wp_upload_dir(); 
                        echo $uploads['basedir']; 
                        echo is_writable($uploads['basedir']) ? ' <span style="color:green">(gravável)</span>' : ' <span style="color:red">(não gravável)</span>';
                        ?>
                    </td>
                </tr>
                <tr>
                    <th>Diretório temporário PHP</th>
                    <td>
                        <?php 
                        $temp_dir = sys_get_temp_dir(); 
                        echo $temp_dir; 
                        echo is_writable($temp_dir) ? ' <span style="color:green">(gravável)</span>' : ' <span style="color:red">(não gravável)</span>';
                        ?>
                    </td>
                </tr>
                <tr>
                    <th>Diretório JetEngine User Folders</th>
                    <td>
                        <?php 
                        echo $juf_dir; 
                        if ($juf_exists) {
                            echo ' <span style="color:green">(existe)</span>';
                            echo $juf_writable ? ' <span style="color:green">(gravável)</span>' : ' <span style="color:red">(não gravável)</span>';
                        } else {
                            echo ' <span style="color:red">(não existe)</span>';
                        }
                        ?>
                    </td>
                </tr>
                <tr>
                    <th>open_basedir</th>
                    <td><?php echo ini_get('open_basedir') ?: 'Não definido'; ?></td>
                </tr>
                <tr>
                    <th>Permissões</th>
                    <td>
                        <ul>
                            <li>Diretório raiz: <?php echo substr(sprintf('%o', fileperms(ABSPATH)), -4); ?></li>
                            <li>Diretório uploads: <?php echo substr(sprintf('%o', fileperms($uploads['basedir'])), -4); ?></li>
                            <?php if ($juf_exists): ?>
                                <li>Diretório JetEngine User Folders: <?php echo substr(sprintf('%o', fileperms($juf_dir)), -4); ?></li>
                            <?php endif; ?>
                        </ul>
                    </td>
                </tr>
            </table>
        </div>
        
        <div class="card" style="margin-top: 20px;">
            <h2>Teste de Criação de Pasta</h2>
            <form method="post">
                <?php wp_nonce_field('diagnostico_pastas'); ?>
                
                <table class="form-table">
                    <tr>
                        <th>Caminho para teste</th>
                        <td>
                            <input type="text" name="test_path" class="large-text" value="<?php echo esc_attr($uploads['basedir'] . '/teste_ftp_' . time()); ?>">
                            <p class="description">Digite o caminho absoluto onde deseja criar uma pasta de teste</p>
                        </td>
                    </tr>
                </table>
                
                <?php submit_button('Testar Criação de Pasta', 'primary', 'test_folder_creation'); ?>
            </form>
        </div>
        
        <div class="card" style="margin-top: 20px;">
            <h2>Recomendações para Resolver o Problema</h2>
            <ol>
                <li><strong>Verifique o caminho correto</strong>: Certifique-se que o caminho configurado no plugin está acessível pelo WordPress</li>
                <li><strong>Permissões</strong>: O usuário do servidor web (provavelmente www-data ou similar) precisa ter permissão de escrita no diretório</li>
                <li><strong>Restrições da hospedagem</strong>: Algumas hospedagens limitam onde você pode criar arquivos. Use um caminho dentro de wp-content/uploads/</li>
                <li><strong>Use caminhos relativos</strong>: Em vez de especificar um caminho absoluto como /var/www/..., use um caminho relativo à instalação do WordPress</li>
                <li><strong>Atualização do cliente FTP</strong>: Às vezes, o cliente FTP não mostra novas pastas imediatamente. Tente desconectar e reconectar, ou usar o recurso "atualizar"</li>
            </ol>
        </div>
    </div>
    <?php
}