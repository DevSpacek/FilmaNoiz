<?php
/**
 * WooCommerce SFTP Importer - Diagnóstico
 * 
 * Este arquivo deve ser colocado na pasta do plugin para diagnóstico de problemas
 * com o escaneamento automático e categorização de produtos.
 */

// Sair se acessado diretamente
if (!defined('ABSPATH')) {
    require_once(dirname(dirname(dirname(__FILE__))) . '/wp-load.php');
}

// Verificar permissões de administrador
if (!current_user_can('manage_options')) {
    wp_die('Acesso negado. Você precisa ser um administrador para acessar esta página.');
}

echo '<h1>Diagnóstico do WooCommerce SFTP Importer</h1>';

// Verificar extensão SSH2
echo '<h2>Verificando dependências</h2>';
if (!extension_loaded('ssh2')) {
    echo '<p style="color:red;">❌ A extensão PHP SSH2 não está instalada ou ativada no servidor.</p>';
} else {
    echo '<p style="color:green;">✅ Extensão PHP SSH2 está instalada e ativada.</p>';
}

// Verificar configurações do cron
echo '<h2>Verificando agendamentos do WordPress</h2>';
$cron = _get_cron_array();
$encontrou_wsftp = false;

echo '<p>Horário atual do servidor: ' . date('Y-m-d H:i:s') . '</p>';
echo '<p>Próximos agendamentos relevantes:</p>';
echo '<ul>';

foreach ($cron as $timestamp => $hooks) {
    foreach ($hooks as $hook => $events) {
        if (strpos($hook, 'wsftp') !== false) {
            $encontrou_wsftp = true;
            echo '<li>' . $hook . ' - Agendado para: ' . date('Y-m-d H:i:s', $timestamp) . '</li>';
        }
    }
}

if (!$encontrou_wsftp) {
    echo '<li style="color:red;">❌ Nenhum agendamento WSFTP encontrado no cron do WordPress!</li>';
}
echo '</ul>';

// Verificar configurações do plugin
echo '<h2>Verificando configurações do plugin</h2>';
$scan_interval = get_option('wsftp_scan_interval', 1);
$last_scan = get_option('wsftp_last_scan_time', 0);
$last_scan_date = ($last_scan > 0) ? date('Y-m-d H:i:s', $last_scan) : 'Nunca';

echo '<ul>';
echo '<li>Intervalo de escaneamento: ' . $scan_interval . ' minutos</li>';
echo '<li>Último escaneamento: ' . $last_scan_date . '</li>';
echo '<li>Próximo escaneamento deveria ser em: ' . ($last_scan > 0 ? date('Y-m-d H:i:s', $last_scan + ($scan_interval * 60)) : 'Nunca') . '</li>';
echo '</ul>';

// Verificar configurações do SFTP
echo '<h2>Verificando configurações SFTP</h2>';
$sftp_host = get_option('wsftp_sftp_host', '');
$sftp_port = get_option('wsftp_sftp_port', 22);
$sftp_username = get_option('wsftp_sftp_username', '');
$sftp_base_path = get_option('wsftp_sftp_base_path', '/user_folders');

echo '<ul>';
echo '<li>Host: ' . ($sftp_host ? $sftp_host : '<span style="color:red">Não configurado!</span>') . '</li>';
echo '<li>Port: ' . $sftp_port . '</li>';
echo '<li>Username: ' . ($sftp_username ? $sftp_username : '<span style="color:red">Não configurado!</span>') . '</li>';
echo '<li>Base Path: ' . $sftp_base_path . '</li>';
echo '</ul>';

// Verificar configurações de transients
echo '<h2>Verificando estado dos transients</h2>';
$scanning_lock = get_transient('wsftp_scanning_lock');
$scanning_lock_time = get_transient('wsftp_scanning_lock_time');
$folder_mappings = get_transient('wsftp_folder_mappings');

echo '<ul>';
echo '<li>Scanning Lock: ' . ($scanning_lock ? '<span style="color:red">ATIVO - o escaneamento está bloqueado!</span>' : 'Inativo') . '</li>';
echo '<li>Scanning Lock Time: ' . ($scanning_lock_time ? date('Y-m-d H:i:s', $scanning_lock_time) . ' (há ' . round((time() - $scanning_lock_time) / 60) . ' minutos)' : 'Não definido') . '</li>';
echo '<li>Folder Mappings Cache: ' . ($folder_mappings ? 'Ativo com ' . count($folder_mappings) . ' pastas' : 'Não armazenado em cache') . '</li>';
echo '</ul>';

// Testar conexão SFTP e listar pastas
echo '<h2>Tentando conexão SFTP e listagem de pastas</h2>';

if (empty($sftp_host) || empty($sftp_username)) {
    echo '<p style="color:red;">❌ Configurações SFTP incompletas. Configure o host e o username.</p>';
} else {
    try {
        // Autenticação
        $auth_method = get_option('wsftp_sftp_auth_method', 'password');
        if ($auth_method === 'password') {
            $password = function_exists('wsftp_encrypt_decrypt') ?
                wsftp_encrypt_decrypt(get_option('wsftp_sftp_password', ''), 'decrypt') :
                'FUNÇÃO DE DESCRIPTOGRAFIA NÃO ENCONTRADA';

            echo '<p>Tentando autenticação por senha...</p>';

            if (empty($password)) {
                echo '<p style="color:red;">❌ Senha não configurada!</p>';
            }
        } else {
            $key_path = get_option('wsftp_sftp_private_key_path', '');
            echo '<p>Tentando autenticação por chave privada: ' . $key_path . '</p>';

            if (empty($key_path) || !file_exists($key_path)) {
                echo '<p style="color:red;">❌ Arquivo de chave privada não encontrado: ' . $key_path . '</p>';
            }
        }

        // Conectar ao servidor
        echo '<p>Conectando ao servidor: ' . $sftp_host . ':' . $sftp_port . '...</p>';
        $connection = @ssh2_connect($sftp_host, $sftp_port);

        if (!$connection) {
            echo '<p style="color:red;">❌ Falha ao conectar ao servidor SFTP!</p>';
        } else {
            echo '<p style="color:green;">✅ Conexão ao servidor SFTP estabelecida!</p>';

            // Autenticar
            $auth_success = false;

            if ($auth_method === 'password' && !empty($password)) {
                $auth_success = @ssh2_auth_password($connection, $sftp_username, $password);
            } elseif ($auth_method === 'key' && file_exists($key_path)) {
                $auth_success = @ssh2_auth_pubkey_file(
                    $connection,
                    $sftp_username,
                    $key_path . '.pub',
                    $key_path
                );
            }

            if (!$auth_success) {
                echo '<p style="color:red;">❌ Falha na autenticação SFTP!</p>';
            } else {
                echo '<p style="color:green;">✅ Autenticação SFTP bem-sucedida!</p>';

                // Inicializar subsistema SFTP
                $sftp = @ssh2_sftp($connection);
                if (!$sftp) {
                    echo '<p style="color:red;">❌ Falha ao inicializar subsistema SFTP!</p>';
                } else {
                    echo '<p style="color:green;">✅ Subsistema SFTP inicializado!</p>';

                    // Verificar pasta base
                    $dir = "ssh2.sftp://$sftp$sftp_base_path";
                    if (!file_exists($dir)) {
                        echo '<p style="color:red;">❌ Pasta base não encontrada: ' . $sftp_base_path . '</p>';
                    } else {
                        echo '<p style="color:green;">✅ Pasta base encontrada: ' . $sftp_base_path . '</p>';

                        // Listar pastas
                        echo '<p>Listando pastas em ' . $sftp_base_path . ':</p>';
                        echo '<ul>';

                        $handle = @opendir($dir);
                        if (!$handle) {
                            echo '<li style="color:red;">❌ Falha ao abrir diretório!</li>';
                        } else {
                            while (false !== ($entry = readdir($handle))) {
                                if ($entry != "." && $entry != "..") {
                                    $path = "$sftp_base_path/$entry";
                                    $is_dir = is_dir("ssh2.sftp://$sftp$path");

                                    if ($is_dir) {
                                        echo '<li>📁 ' . $entry . ' (diretório)</li>';

                                        // Verificar mapeamento de usuário
                                        if (function_exists('wsftp_get_user_id_from_folder')) {
                                            $user_id = wsftp_get_user_id_from_folder($entry);
                                            if ($user_id) {
                                                $user = get_user_by('id', $user_id);
                                                echo '<li style="margin-left: 20px;color:green;">✅ Mapeado para usuário: ' .
                                                    ($user ? $user->user_login . ' (ID: ' . $user_id . ')' : 'ID: ' . $user_id) . '</li>';
                                            } else {
                                                echo '<li style="margin-left: 20px;color:red;">❌ Não foi possível mapear para nenhum usuário</li>';
                                            }
                                        } else {
                                            echo '<li style="margin-left: 20px;color:red;">❌ Função wsftp_get_user_id_from_folder não encontrada</li>';
                                        }

                                        // Verificar categoria
                                        $category = get_term_by('name', $entry, 'product_cat');
                                        if ($category) {
                                            echo '<li style="margin-left: 20px;color:green;">✅ Categoria existente: ' .
                                                $category->name . ' (ID: ' . $category->term_id . ')</li>';
                                        } else {
                                            echo '<li style="margin-left: 20px;color:orange;">⚠️ Categoria não encontrada para esta pasta</li>';
                                        }
                                    } else {
                                        echo '<li>📄 ' . $entry . ' (arquivo)</li>';
                                    }
                                }
                            }
                            closedir($handle);
                        }

                        echo '</ul>';
                    }
                }
            }
        }
    } catch (Exception $e) {
        echo '<p style="color:red;">❌ Erro: ' . $e->getMessage() . '</p>';
    }
}

// Verificar produtos e suas categorias
echo '<h2>Verificando produtos e categorias</h2>';
$products = wc_get_products([
    'limit' => 10,
    'meta_key' => '_wsftp_product_key',
    'return' => 'ids',
]);

if (empty($products)) {
    echo '<p>Nenhum produto criado pelo plugin encontrado.</p>';
} else {
    echo '<p>Encontrados ' . count($products) . ' produtos (mostrando os primeiros 10):</p>';
    echo '<ul>';

    foreach ($products as $product_id) {
        $product = wc_get_product($product_id);
        if (!$product)
            continue;

        echo '<li>Produto: ' . $product->get_name() . ' (ID: ' . $product_id . ')</li>';

        // Verificar categoria
        $categories = wc_get_product_category_list($product_id);
        echo '<li style="margin-left: 20px;">Categorias: ' . ($categories ? $categories : 'Nenhuma') . '</li>';

        // Verificar metadados
        $user_id = get_post_meta($product_id, '_wsftp_user_id', true);
        $source_file = get_post_meta($product_id, '_wsftp_source_file', true);
        $source_folder = get_post_meta($product_id, '_wsftp_source_folder', true);
        $product_key = get_post_meta($product_id, '_wsftp_product_key', true);

        echo '<li style="margin-left: 20px;">User ID: ' . ($user_id ? $user_id : 'Não definido') . '</li>';
        echo '<li style="margin-left: 20px;">Arquivo fonte: ' . ($source_file ? $source_file : 'Não definido') . '</li>';
        echo '<li style="margin-left: 20px;">Pasta fonte: ' . ($source_folder ? $source_folder : 'Não definido') . '</li>';
        echo '<li style="margin-left: 20px;">Chave do produto: ' . ($product_key ? $product_key : 'Não definido') . '</li>';
    }

    echo '</ul>';
}

// Verificação manual para corrigir o problema
echo '<h2>Ações de Reparo</h2>';
echo '<p>Use estes botões para tentar corrigir os problemas:</p>';

echo '<form method="post">';
echo '<input type="hidden" name="wsftp_diagnostics_action" value="clear_locks">';
echo '<button type="submit" style="margin:5px;padding:10px;background:#ffaa00;color:white;border:none;cursor:pointer;">Limpar todos os locks de escaneamento</button>';
echo '</form>';

echo '<form method="post">';
echo '<input type="hidden" name="wsftp_diagnostics_action" value="reschedule_cron">';
echo '<button type="submit" style="margin:5px;padding:10px;background:#00aa00;color:white;border:none;cursor:pointer;">Reconfigurar agendamentos Cron</button>';
echo '</form>';

echo '<form method="post">';
echo '<input type="hidden" name="wsftp_diagnostics_action" value="fix_categories">';
echo '<button type="submit" style="margin:5px;padding:10px;background:#0073aa;color:white;border:none;cursor:pointer;">Corrigir categorias de produtos</button>';
echo '</form>';

echo '<form method="post">';
echo '<input type="hidden" name="wsftp_diagnostics_action" value="run_scan">';
echo '<button type="submit" style="margin:5px;padding:10px;background:#46b450;color:white;border:none;cursor:pointer;">Executar escaneamento agora</button>';
echo '</form>';

// Processar ações
if (isset($_POST['wsftp_diagnostics_action'])) {
    $action = $_POST['wsftp_diagnostics_action'];

    switch ($action) {
        case 'clear_locks':
            delete_transient('wsftp_scanning_lock');
            delete_transient('wsftp_scanning_lock_time');
            delete_transient('wsftp_folder_mappings');
            echo '<div style="background:#d4edda;color:#155724;padding:10px;margin:10px 0;">Locks de escaneamento limpos com sucesso!</div>';
            break;

        case 'reschedule_cron':
            // Limpar agendamentos existentes
            wp_clear_scheduled_hook('wsftp_scan_hook');
            wp_clear_scheduled_hook('wsftp_delayed_scan_hook');

            // Recriar agendamentos
            if (!wp_next_scheduled('wsftp_scan_hook')) {
                wp_schedule_event(time(), 'wsftp_custom_interval', 'wsftp_scan_hook');
                echo '<div style="background:#d4edda;color:#155724;padding:10px;margin:10px 0;">Agendamento Cron reconfigurado!</div>';
            } else {
                echo '<div style="background:#f8d7da;color:#721c24;padding:10px;margin:10px 0;">Falha ao reagendar o Cron!</div>';
            }
            break;

        case 'fix_categories':
            if (function_exists('wsftp_fix_all_product_categories')) {
                $count = wsftp_fix_all_product_categories();
                echo '<div style="background:#d4edda;color:#155724;padding:10px;margin:10px 0;">' . $count . ' categorias de produtos foram atualizadas!</div>';
            } else {
                echo '<div style="background:#f8d7da;color:#721c24;padding:10px;margin:10px 0;">Função wsftp_fix_all_product_categories não encontrada!</div>';

                // Implementação de fallback
                echo '<div style="background:#cce5ff;color:#004085;padding:10px;margin:10px 0;">Tentando reparo alternativo de categorias...</div>';

                // Buscar todos os produtos
                $products = wc_get_products([
                    'limit' => -1,
                    'meta_key' => '_wsftp_product_key',
                    'return' => 'ids',
                ]);

                $count = 0;
                foreach ($products as $product_id) {
                    $user_id = get_post_meta($product_id, '_wsftp_user_id', true);
                    if (empty($user_id))
                        continue;

                    // Obter nome do usuário para usar como categoria fallback
                    $user = get_user_by('id', $user_id);
                    $category_name = $user ? $user->display_name : 'User Products';

                    // Criar categoria se não existir
                    $term = get_term_by('name', $category_name, 'product_cat');
                    if (!$term) {
                        $term_data = wp_insert_term($category_name, 'product_cat');
                        if (is_wp_error($term_data))
                            continue;
                        $category_id = $term_data['term_id'];
                    } else {
                        $category_id = $term->term_id;
                    }

                    // Adicionar produto à categoria
                    wp_set_object_terms($product_id, array($category_id), 'product_cat');
                    $count++;
                }

                echo '<div style="background:#d4edda;color:#155724;padding:10px;margin:10px 0;">' . $count . ' categorias de produtos foram atualizadas pelo método alternativo!</div>';
            }
            break;

        case 'run_scan':
            if (function_exists('wsftp_scan_folders')) {
                // Limpar locks primeiro
                delete_transient('wsftp_scanning_lock');
                delete_transient('wsftp_scanning_lock_time');

                // Executar scan
                wsftp_scan_folders();
                echo '<div style="background:#d4edda;color:#155724;padding:10px;margin:10px 0;">Escaneamento manual iniciado! Verifique os logs.</div>';
            } else {
                echo '<div style="background:#f8d7da;color:#721c24;padding:10px;margin:10px 0;">Função wsftp_scan_folders não encontrada!</div>';
            }
            break;
    }

    echo '<p><a href="' . $_SERVER['REQUEST_URI'] . '" style="padding:10px;background:#f0f0f1;text-decoration:none;display:inline-block;">Atualizar diagnóstico</a></p>';
}
