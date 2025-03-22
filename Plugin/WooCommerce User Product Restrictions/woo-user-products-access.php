<?php
/**
 * Plugin Name: WooCommerce User Product Restrictions
 * Description: Restringe o acesso aos produtos por usuário, com integração JetEngine e SFTP
 * Version: 1.0
 * Author: DevSpacek
 * Requires at least: 5.8
 * Requires PHP: 7.2
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

class WC_User_Product_Restrictions {
    private $plugin_path;
    private $plugin_url;
    
    public function __construct() {
        $this->plugin_path = plugin_dir_path(__FILE__);
        $this->plugin_url = plugin_dir_url(__FILE__);
        
        // Hooks para o painel admin
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_init', array($this, 'register_settings'));
        add_action('add_meta_boxes', array($this, 'add_product_meta_box'));
        add_action('save_post', array($this, 'save_product_meta_data'));
        
        // Hooks para filtragem de conteúdo
        add_filter('woocommerce_product_query', array($this, 'filter_products_by_user'), 10);
        add_filter('woocommerce_related_products', array($this, 'filter_related_products'), 10, 3);
        add_filter('woocommerce_product_get_visibility', array($this, 'product_get_visibility'), 99, 2);
        add_filter('the_posts', array($this, 'filter_product_posts'), 10, 2);
        
        // Hooks para páginas de produto
        add_action('template_redirect', array($this, 'check_product_access'));
        
        // Hooks para thumbnails em produtos
        add_filter('post_thumbnail_html', array($this, 'filter_product_thumbnail'), 10, 5);
        
        // Integração com JetEngine
        add_filter('jet-engine/listings/data/object-fields', array($this, 'add_jet_engine_fields'));
        add_action('init', array($this, 'register_meta_fields'));
        
        // Verificar assinaturas para SFTP
        if (class_exists('WC_Subscriptions') && class_exists('WC_Subscription')) {
            add_filter('wcs_renewal_order_created', array($this, 'update_subscription_meta'), 10, 2);
        }
    }
    
    /**
     * Adicionar menu admin
     */
    public function add_admin_menu() {
        add_submenu_page(
            'woocommerce',
            'Restrições de Produtos por Usuário',
            'Restrições por Usuário',
            'manage_woocommerce',
            'wc-user-product-restrictions',
            array($this, 'render_admin_page')
        );
    }
    
    /**
     * Registrar configurações
     */
    public function register_settings() {
        register_setting('wc_user_product_restrictions', 'wc_upr_enable_restrictions', array(
            'default' => 'yes'
        ));
        register_setting('wc_user_product_restrictions', 'wc_upr_admin_override', array(
            'default' => 'yes'
        ));
        register_setting('wc_user_product_restrictions', 'wc_upr_use_jet_engine', array(
            'default' => 'no'
        ));
        register_setting('wc_user_product_restrictions', 'wc_upr_jet_relation_field', array(
            'default' => ''
        ));
        register_setting('wc_user_product_restrictions', 'wc_upr_use_sftp_structure', array(
            'default' => 'yes' 
        ));
        register_setting('wc_user_product_restrictions', 'wc_upr_redirect_url', array(
            'default' => ''
        ));
    }
    
    /**
     * Renderizar página admin
     */
    public function render_admin_page() {
        // Verificar permissões
        if (!current_user_can('manage_woocommerce')) {
            wp_die('Você não tem permissões suficientes para acessar esta página.');
        }

        // Obter configurações
        $enable_restrictions = get_option('wc_upr_enable_restrictions', 'yes');
        $admin_override = get_option('wc_upr_admin_override', 'yes');
        $use_jet_engine = get_option('wc_upr_use_jet_engine', 'no');
        $jet_relation_field = get_option('wc_upr_jet_relation_field', '');
        $use_sftp = get_option('wc_upr_use_sftp_structure', 'yes');
        $redirect_url = get_option('wc_upr_redirect_url', '');
        
        // Verificar JetEngine
        $jet_engine_active = class_exists('Jet_Engine');
        
        // Obter campos de relacionamento JetEngine
        $jet_relation_fields = array();
        if ($jet_engine_active) {
            // Aqui buscaríamos os campos de relacionamento do JetEngine
            // Esta é uma simplificação - em um ambiente real precisaríamos usar a API do JetEngine
            $jet_relation_fields = $this->get_jet_engine_relation_fields();
        }
        
        ?>
        <div class="wrap">
            <h1>Restrições de Produtos por Usuário</h1>
            
            <form method="post" action="options.php">
                <?php settings_fields('wc_user_product_restrictions'); ?>
                
                <table class="form-table">
                    <tr>
                        <th scope="row">Ativar Restrições</th>
                        <td>
                            <select name="wc_upr_enable_restrictions">
                                <option value="yes" <?php selected($enable_restrictions, 'yes'); ?>>Sim</option>
                                <option value="no" <?php selected($enable_restrictions, 'no'); ?>>Não</option>
                            </select>
                            <p class="description">Ativar restrição de acesso aos produtos por usuário</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Acesso de Administrador</th>
                        <td>
                            <select name="wc_upr_admin_override">
                                <option value="yes" <?php selected($admin_override, 'yes'); ?>>Sim</option>
                                <option value="no" <?php selected($admin_override, 'no'); ?>>Não</option>
                            </select>
                            <p class="description">Administradores podem ver todos os produtos</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Usar Estrutura SFTP</th>
                        <td>
                            <select name="wc_upr_use_sftp_structure">
                                <option value="yes" <?php selected($use_sftp, 'yes'); ?>>Sim</option>
                                <option value="no" <?php selected($use_sftp, 'no'); ?>>Não</option>
                            </select>
                            <p class="description">Usar estrutura de pastas SFTP para definir propriedade de produtos</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Usar JetEngine</th>
                        <td>
                            <select name="wc_upr_use_jet_engine" <?php disabled(!$jet_engine_active); ?>>
                                <option value="yes" <?php selected($use_jet_engine, 'yes'); ?>>Sim</option>
                                <option value="no" <?php selected($use_jet_engine, 'no'); ?>>Não</option>
                            </select>
                            <p class="description">
                                <?php if ($jet_engine_active): ?>
                                    Usar campos de relacionamento do JetEngine para definir propriedade de produtos
                                <?php else: ?>
                                    <span style="color:red">JetEngine não está ativo</span>
                                <?php endif; ?>
                            </p>
                        </td>
                    </tr>
                    
                    <?php if ($jet_engine_active): ?>
                    <tr>
                        <th scope="row">Campo de Relacionamento JetEngine</th>
                        <td>
                            <select name="wc_upr_jet_relation_field">
                                <option value="">Selecione um campo</option>
                                <?php foreach ($jet_relation_fields as $field_id => $field_name): ?>
                                    <option value="<?php echo esc_attr($field_id); ?>" <?php selected($jet_relation_field, $field_id); ?>>
                                        <?php echo esc_html($field_name); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <p class="description">Campo do JetEngine que relaciona produtos a usuários</p>
                        </td>
                    </tr>
                    <?php endif; ?>
                    
                    <tr>
                        <th scope="row">URL de Redirecionamento</th>
                        <td>
                            <input type="text" name="wc_upr_redirect_url" value="<?php echo esc_attr($redirect_url); ?>" class="regular-text">
                            <p class="description">URL para redirecionar usuários sem acesso (deixe em branco para página 404 padrão)</p>
                        </td>
                    </tr>
                </table>
                
                <?php submit_button('Salvar Configurações'); ?>
            </form>
            
            <hr>
            
            <h2>Instruções</h2>
            
            <div style="background:#f8f8f8; padding:15px; border:1px solid #ddd;">
                <h3>Como funciona:</h3>
                
                <p><strong>Método 1: Estrutura SFTP</strong></p>
                <ul style="list-style-type:disc; margin-left:20px;">
                    <li>Os produtos importados de pastas SFTP serão automaticamente vinculados ao cliente correspondente</li>
                    <li>O nome da pasta do cliente no SFTP determinará o usuário que terá acesso</li>
                    <li>Este método funciona com a integração SFTP já existente</li>
                </ul>
                
                <p><strong>Método 2: Meta box no produto</strong></p>
                <ul style="list-style-type:disc; margin-left:20px;">
                    <li>Ao editar um produto, você encontrará uma seção "Restrição de Acesso"</li>
                    <li>Selecione um ou mais usuários que terão acesso ao produto</li>
                </ul>
                
                <?php if ($jet_engine_active): ?>
                <p><strong>Método 3: Relações JetEngine</strong></p>
                <ul style="list-style-type:disc; margin-left:20px;">
                    <li>Use campos de relação do JetEngine para vincular produtos a usuários</li>
                    <li>Configure o campo de relação apropriado nas configurações acima</li>
                </ul>
                <?php endif; ?>
                
                <p><strong>Comportamento:</strong></p>
                <ul style="list-style-type:disc; margin-left:20px;">
                    <li>Usuários só verão produtos aos quais têm acesso</li>
                    <li>Produtos restritos não aparecerão em buscas, listagens ou navegação da loja</li>
                    <li>Tentativas de acesso direto a produtos restritos serão redirecionadas</li>
                    <li>Administradores podem ver todos os produtos (se configurado)</li>
                </ul>
            </div>
        </div>
        <?php
    }
    
    /**
     * Obter campos de relacionamento JetEngine
     */
    private function get_jet_engine_relation_fields() {
        $fields = array();
        
        // Se JetEngine estiver ativo, buscar campos de relação
        if (class_exists('Jet_Engine')) {
            // Esta é uma implementação simplificada
            // Em um ambiente real, usaríamos a API do JetEngine para buscar os campos de relação
            
            // Exemplo de como poderíamos buscar os campos
            if (function_exists('jet_engine')) {
                $modules = jet_engine()->modules->get_modules();
                if (isset($modules['relations']) && $modules['relations']->instance) {
                    $relations = $modules['relations']->instance->get_active_relations();
                    
                    foreach ($relations as $relation) {
                        if ($relation['args']['parent_object'] === 'product' && $relation['args']['child_object'] === 'user') {
                            $fields[$relation['id']] = $relation['name'];
                        }
                    }
                }
            }
            
            // Se não conseguirmos buscar automaticamente, oferecemos alguns campos comuns
            if (empty($fields)) {
                $fields = array(
                    'product_to_user' => 'Produto para Usuário',
                    'user_products' => 'Produtos do Usuário',
                    'client_files' => 'Arquivos do Cliente'
                );
            }
        }
        
        return $fields;
    }
    
    /**
     * Adicionar meta box nos produtos
     */
    public function add_product_meta_box() {
        add_meta_box(
            'wc_upr_product_users',
            'Restrição de Acesso',
            array($this, 'render_product_meta_box'),
            'product',
            'side',
            'default'
        );
    }
    
    /**
     * Renderizar meta box de produto
     */
    public function render_product_meta_box($post) {
        // Obter usuários selecionados
        $product_users = get_post_meta($post->ID, '_wc_upr_allowed_users', true);
        if (!is_array($product_users)) {
            $product_users = array();
        }
        
        // Verificar se temos um usuário do SFTP
        $sftp_client = get_post_meta($post->ID, '_sftp_client', true);
        
        wp_nonce_field('wc_upr_product_meta_box', 'wc_upr_product_meta_box_nonce');
        ?>
        <div class="wc-upr-meta-box">
            <?php if ($sftp_client): ?>
            <p>
                <strong>Cliente SFTP:</strong> <?php echo esc_html($sftp_client); ?>
                <br>
                <small>Este produto está vinculado a um cliente SFTP</small>
            </p>
            <hr>
            <?php endif; ?>
            
            <p>Selecione os usuários que podem acessar este produto:</p>
            
            <div style="max-height: 150px; overflow-y: auto; margin-bottom: 10px; border: 1px solid #ddd; padding: 5px;">
                <?php
                // Buscar usuários com função de cliente
                $users = get_users(array(
                    'role__in' => array('customer', 'editor', 'shop_manager'),
                    'orderby' => 'display_name',
                    'order' => 'ASC',
                ));
                
                if (!empty($users)) {
                    foreach ($users as $user) {
                        $checked = in_array($user->ID, $product_users) ? 'checked="checked"' : '';
                        ?>
                        <label style="display: block; margin-bottom: 3px;">
                            <input type="checkbox" name="wc_upr_allowed_users[]" value="<?php echo esc_attr($user->ID); ?>" <?php echo $checked; ?>>
                            <?php echo esc_html($user->display_name); ?> (<?php echo esc_html($user->user_login); ?>)
                        </label>
                        <?php
                    }
                } else {
                    echo '<p>Nenhum usuário cliente encontrado</p>';
                }
                ?>
            </div>
            
            <p>
                <label>
                    <input type="checkbox" name="wc_upr_public_access" value="yes" <?php checked(get_post_meta($post->ID, '_wc_upr_public_access', true), 'yes'); ?>>
                    Acesso público (disponível para todos)
                </label>
            </p>
        </div>
        <?php
    }
    
    /**
     * Salvar meta dados do produto
     */
    public function save_product_meta_data($post_id) {
        // Verificar nonce
        if (!isset($_POST['wc_upr_product_meta_box_nonce']) || 
            !wp_verify_nonce($_POST['wc_upr_product_meta_box_nonce'], 'wc_upr_product_meta_box')) {
            return;
        }
        
        // Verificar se estamos salvando automaticamente
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }
        
        // Verificar permissões
        if (!current_user_can('edit_post', $post_id)) {
            return;
        }
        
        // Verificar tipo de post
        if (get_post_type($post_id) !== 'product') {
            return;
        }
        
        // Salvar usuários permitidos
        if (isset($_POST['wc_upr_allowed_users'])) {
            $allowed_users = array_map('intval', $_POST['wc_upr_allowed_users']);
            update_post_meta($post_id, '_wc_upr_allowed_users', $allowed_users);
        } else {
            update_post_meta($post_id, '_wc_upr_allowed_users', array());
        }
        
        // Salvar acesso público
        $public_access = isset($_POST['wc_upr_public_access']) ? 'yes' : 'no';
        update_post_meta($post_id, '_wc_upr_public_access', $public_access);
        
        // Se este produto foi criado do SFTP, verificar se existe um usuário com o nome do cliente
        $sftp_client = get_post_meta($post_id, '_sftp_client', true);
        if ($sftp_client) {
            // Buscar usuário pelo nome ou criar usuário se não existir
            $this->link_sftp_client_to_user($post_id, $sftp_client);
        }
    }
    
    /**
     * Vincular cliente SFTP a um usuário existente ou novo
     */
    private function link_sftp_client_to_user($product_id, $client_name) {
        // Verificar se já existe um usuário com esse nome de login
        $user = get_user_by('login', sanitize_user($client_name, true));
        
        if (!$user) {
            // Buscar por nome de exibição ou e-mail contendo o nome do cliente
            $users = get_users(array(
                'search' => "*{$client_name}*",
                'search_columns' => array('user_login', 'user_email', 'display_name'),
            ));
            
            if (!empty($users)) {
                $user = $users[0]; // Usar o primeiro usuário encontrado
            }
        }
        
        if ($user) {
            // Adicionar este usuário à lista de permissões do produto
            $allowed_users = get_post_meta($product_id, '_wc_upr_allowed_users', true);
            if (!is_array($allowed_users)) {
                $allowed_users = array();
            }
            
            if (!in_array($user->ID, $allowed_users)) {
                $allowed_users[] = $user->ID;
                update_post_meta($product_id, '_wc_upr_allowed_users', $allowed_users);
            }
        }
    }
    
    /**
     * Verificar se o usuário tem acesso ao produto
     */
    public function user_can_access_product($product_id, $user_id = null) {
        // Se as restrições estiverem desativadas, todos podem acessar
        if (get_option('wc_upr_enable_restrictions', 'yes') !== 'yes') {
            return true;
        }
        
        // Se não especificado, usar o usuário atual
        if (is_null($user_id)) {
            $user_id = get_current_user_id();
        }
        
        // Administradores podem acessar tudo se configurado
        if (get_option('wc_upr_admin_override', 'yes') === 'yes' && current_user_can('manage_options')) {
            return true;
        }
        
        // Verificar acesso público
        if (get_post_meta($product_id, '_wc_upr_public_access', true) === 'yes') {
            return true;
        }
        
        // Verificar lista de usuários permitidos
        $allowed_users = get_post_meta($product_id, '_wc_upr_allowed_users', true);
        if (is_array($allowed_users) && in_array($user_id, $allowed_users)) {
            return true;
        }
        
        // Verificar cliente SFTP se a opção estiver ativada
        if (get_option('wc_upr_use_sftp_structure', 'yes') === 'yes') {
            $sftp_client = get_post_meta($product_id, '_sftp_client', true);
            if ($sftp_client) {
                // Verificar se o nome de usuário coincide com o cliente SFTP
                $user = get_user_by('id', $user_id);
                if ($user && ($user->user_login === $sftp_client || 
                             strpos($user->display_name, $sftp_client) !== false || 
                             strpos($user->user_email, $sftp_client) !== false)) {
                    return true;
                }
            }
        }
        
        // Verificar relações JetEngine se a opção estiver ativada
        if (get_option('wc_upr_use_jet_engine', 'no') === 'yes' && class_exists('Jet_Engine')) {
            $relation_field = get_option('wc_upr_jet_relation_field', '');
            if ($relation_field) {
                // Esta implementação depende da estrutura específica do JetEngine
                // Aqui estamos verificando meta customizada, adaptada ao seu caso específico
                $related_users = get_post_meta($product_id, $relation_field, true);
                if (is_array($related_users) && in_array($user_id, $related_users)) {
                    return true;
                }
            }
        }
        
        // Não tem acesso
        return false;
    }
    
    /**
     * Filtrar consulta de produtos
     */
    public function filter_products_by_user($query) {
        // Verificar se as restrições estão ativas
        if (get_option('wc_upr_enable_restrictions', 'yes') !== 'yes') {
            return $query;
        }
        
        // Verificar se é admin e se pode sobrescrever
        if (get_option('wc_upr_admin_override', 'yes') === 'yes' && current_user_can('manage_options')) {
            return $query;
        }
        
        // Apenas filtrar se for frontend e consulta principal
        if (is_admin() || !$query->is_main_query()) {
            return $query;
        }
        
        $user_id = get_current_user_id();
        
        // Se o usuário não estiver logado, mostrar apenas produtos públicos
        if (!$user_id) {
            $meta_query = array(
                'relation' => 'OR',
                array(
                    'key'   => '_wc_upr_public_access',
                    'value' => 'yes',
                )
            );
            
            // Mesclar com meta query existente
            $query->set('meta_query', $this->merge_meta_queries($query->get('meta_query'), $meta_query));
            
            return $query;
        }
        
        // Construir query para produtos acessíveis por este usuário
        $meta_query = array(
            'relation' => 'OR',
            array(
                'key'   => '_wc_upr_public_access',
                'value' => 'yes',
            )
        );
        
        // Produtos com usuário específico
        $meta_query[] = array(
            'key'     => '_wc_upr_allowed_users',
            'value'   => serialize(strval($user_id)),
            'compare' => 'LIKE',
        );
        
        // Se usamos estrutura SFTP
        if (get_option('wc_upr_use_sftp_structure', 'yes') === 'yes') {
            $user = get_user_by('id', $user_id);
            if ($user) {
                $meta_query[] = array(
                    'key'   => '_sftp_client',
                    'value' => $user->user_login,
                );
            }
        }
        
        // Mesclar com meta query existente
        $query->set('meta_query', $this->merge_meta_queries($query->get('meta_query'), $meta_query));
        
        return $query;
    }
    
    /**
     * Mesclador de meta queries
     */
    private function merge_meta_queries($original_meta_query, $new_meta_query) {
        if (empty($original_meta_query)) {
            return $new_meta_query;
        }
        
        return array(
            'relation' => 'AND',
            $original_meta_query,
            $new_meta_query
        );
    }
    
    /**
     * Filtrar produtos relacionados
     */
    public function filter_related_products($related_posts, $product_id, $args) {
        // Verificar se as restrições estão ativas
        if (get_option('wc_upr_enable_restrictions', 'yes') !== 'yes') {
            return $related_posts;
        }
        
        // Se o usuário for admin e puder sobrescrever
        if (get_option('wc_upr_admin_override', 'yes') === 'yes' && current_user_can('manage_options')) {
            return $related_posts;
        }
        
        $user_id = get_current_user_id();
        $filtered_products = array();
        
        foreach ($related_posts as $product_id) {
            if ($this->user_can_access_product($product_id, $user_id)) {
                $filtered_products[] = $product_id;
            }
        }
        
        return $filtered_products;
    }
    
    /**
     * Filtrar visibilidade de produtos
     */
    public function product_get_visibility($visibility, $product) {
        // Verificar se as restrições estão ativas
        if (get_option('wc_upr_enable_restrictions', 'yes') !== 'yes') {
            return $visibility;
        }
        
        // Se o usuário for admin e puder sobrescrever
        if (get_option('wc_upr_admin_override', 'yes') === 'yes' && current_user_can('manage_options')) {
            return $visibility;
        }
        
        // Se o usuário não tiver acesso, ocultar o produto
        if (!$this->user_can_access_product($product->get_id())) {
            return 'hidden';
        }
        
        return $visibility;
    }
    
    /**
     * Filtrar posts de produtos (resultados de busca)
     */
    public function filter_product_posts($posts, $query) {
        // Verificar se as restrições estão ativas
        if (get_option('wc_upr_enable_restrictions', 'yes') !== 'yes') {
            return $posts;
        }
        
        // Se o usuário for admin e puder sobrescrever
        if (get_option('wc_upr_admin_override', 'yes') === 'yes' && current_user_can('manage_options')) {
            return $posts;
        }
        
        // Filtrar posts de produtos
        $filtered_posts = array();
        
        foreach ($posts as $post) {
            if ($post->post_type === 'product') {
                if ($this->user_can_access_product($post->ID)) {
                    $filtered_posts[] = $post;
                }
            } else {
                $filtered_posts[] = $post;
            }
        }
        
        return $filtered_posts;
    }
    
    /**
     * Verificar acesso à página de produto
     */
    public function check_product_access() {
        // Verificar se é uma página de produto
        if (!is_product()) {
            return;
        }
        
        // Verificar se as restrições estão ativas
        if (get_option('wc_upr_enable_restrictions', 'yes') !== 'yes') {
            return;
        }
        
        global $post;
        
        // Se o usuário não tiver acesso, redirecioná-lo
        if (!$this->user_can_access_product($post->ID)) {
            $redirect_url = get_option('wc_upr_redirect_url', '');
            
                        if (!empty($redirect_url)) {
                wp_redirect($redirect_url);
                exit;
            } else {
                // Se não houver URL de redirecionamento, mostrar página 404
                global $wp_query;
                $wp_query->set_404();
                status_header(404);
                get_template_part(404);
                exit;
            }
        }
    }
    
    /**
     * Filtrar miniatura de produto
     */
    public function filter_product_thumbnail($html, $post_id, $post_thumbnail_id, $size, $attr) {
        // Verificar se é um produto
        if (get_post_type($post_id) !== 'product') {
            return $html;
        }
        
        // Verificar se as restrições estão ativas
        if (get_option('wc_upr_enable_restrictions', 'yes') !== 'yes') {
            return $html;
        }
        
        // Se o usuário for admin e puder sobrescrever
        if (get_option('wc_upr_admin_override', 'yes') === 'yes' && current_user_can('manage_options')) {
            return $html;
        }
        
        // Se o usuário não tiver acesso, ocultar miniatura
        if (!$this->user_can_access_product($post_id)) {
            return '';
        }
        
        return $html;
    }
    
    /**
     * Adicionar campos ao JetEngine
     */
    public function add_jet_engine_fields($fields) {
        // Adicionar nossos campos personalizados ao JetEngine
        $fields['_wc_upr_allowed_users'] = __('Usuários com Acesso', 'wc-user-product-restrictions');
        $fields['_wc_upr_public_access'] = __('Acesso Público', 'wc-user-product-restrictions');
        $fields['_sftp_client'] = __('Cliente SFTP', 'wc-user-product-restrictions');
        
        return $fields;
    }
    
    /**
     * Registrar campos meta para JetEngine
     */
    public function register_meta_fields() {
        // Registrar campos meta personalizados
        if (function_exists('jet_engine')) {
            // Definir os campos para o JetEngine
            $meta_fields = array(
                array(
                    'name' => '_wc_upr_allowed_users',
                    'type' => 'text',
                    'title' => 'Usuários com Acesso',
                    'object_type' => 'product',
                ),
                array(
                    'name' => '_wc_upr_public_access',
                    'type' => 'text',
                    'title' => 'Acesso Público',
                    'object_type' => 'product',
                ),
                array(
                    'name' => '_sftp_client',
                    'type' => 'text',
                    'title' => 'Cliente SFTP',
                    'object_type' => 'product',
                ),
            );
            
            // Registrar com JetEngine se disponível
            if (method_exists(jet_engine()->meta_boxes, 'register_custom_fields')) {
                foreach ($meta_fields as $field) {
                    jet_engine()->meta_boxes->register_custom_fields($field);
                }
            }
        }
    }
    
    /**
     * Atualizar meta de assinatura quando renovada
     */
    public function update_subscription_meta($renewal_order, $subscription) {
        // Se o cliente renovar uma assinatura, garantir que todos os produtos
        // vinculados a ele ainda sejam acessíveis
        
        if (!$renewal_order || !$subscription) {
            return $renewal_order;
        }
        
        $user_id = $subscription->get_user_id();
        if (!$user_id) {
            return $renewal_order;
        }
        
        $user = get_user_by('id', $user_id);
        if (!$user) {
            return $renewal_order;
        }
        
        // Buscar todos os produtos acessíveis por este usuário
        $args = array(
            'post_type' => 'product',
            'posts_per_page' => -1,
            'meta_query' => array(
                'relation' => 'OR',
                array(
                    'key' => '_wc_upr_allowed_users',
                    'value' => serialize(strval($user_id)),
                    'compare' => 'LIKE',
                ),
                array(
                    'key' => '_sftp_client',
                    'value' => $user->user_login,
                    'compare' => '=',
                )
            )
        );
        
        $products = get_posts($args);
        
        // Log para depuração
        error_log('Atualização de assinatura para o usuário: ' . $user->user_login);
        error_log('Produtos encontrados: ' . count($products));
        
        return $renewal_order;
    }
    
    /**
     * Crie um usuário cliente a partir de uma pasta SFTP
     */
    public function create_user_from_sftp_client($client_name) {
        // Verificar se já existe um usuário com este nome
        $user = get_user_by('login', sanitize_user($client_name, true));
        
        if ($user) {
            return $user->ID;
        }
        
        // Criar um novo usuário
        $username = sanitize_user($client_name, true);
        $email = $username . '@' . parse_url(home_url(), PHP_URL_HOST);
        $password = wp_generate_password(12, true, true);
        
        $user_id = wp_insert_user(array(
            'user_login' => $username,
            'user_email' => $email,
            'user_pass' => $password,
            'display_name' => $client_name,
            'role' => 'customer'
        ));
        
        if (is_wp_error($user_id)) {
            error_log('Erro ao criar usuário para cliente SFTP: ' . $user_id->get_error_message());
            return false;
        }
        
        // Notificar administrador
        $admin_email = get_option('admin_email');
        $subject = 'Novo usuário criado a partir de cliente SFTP';
        $message = "Um novo usuário foi criado automaticamente a partir de um cliente SFTP:\n\n";
        $message .= "Nome de usuário: $username\n";
        $message .= "Email: $email\n";
        $message .= "Senha: $password\n\n";
        $message .= "Você pode editar este usuário em: " . admin_url('user-edit.php?user_id=' . $user_id);
        
        wp_mail($admin_email, $subject, $message);
        
        return $user_id;
    }
    
    /**
     * Verificar e criar usuários para clientes SFTP existentes
     * Pode ser executado via uma ação programada ou manualmente
     */
    public function sync_sftp_clients_to_users() {
        // Obter todos os clientes SFTP existentes
        global $wpdb;
        
        $clients = $wpdb->get_col(
            "SELECT DISTINCT meta_value FROM {$wpdb->postmeta} 
             WHERE meta_key = '_sftp_client' AND meta_value != ''"
        );
        
        if (empty($clients)) {
            return 0;
        }
        
        $count = 0;
        
        foreach ($clients as $client_name) {
            // Verificar se já existe um usuário para este cliente
            $user = get_user_by('login', sanitize_user($client_name, true));
            
            if (!$user) {
                // Criar novo usuário
                $user_id = $this->create_user_from_sftp_client($client_name);
                
                if ($user_id) {
                    $count++;
                    
                    // Associar todos os produtos deste cliente ao novo usuário
                    $products = $wpdb->get_col($wpdb->prepare(
                        "SELECT post_id FROM {$wpdb->postmeta} 
                         WHERE meta_key = '_sftp_client' AND meta_value = %s",
                        $client_name
                    ));
                    
                    foreach ($products as $product_id) {
                        $allowed_users = get_post_meta($product_id, '_wc_upr_allowed_users', true);
                        if (!is_array($allowed_users)) {
                            $allowed_users = array();
                        }
                        
                        if (!in_array($user_id, $allowed_users)) {
                            $allowed_users[] = $user_id;
                            update_post_meta($product_id, '_wc_upr_allowed_users', $allowed_users);
                        }
                    }
                }
            }
        }
        
        return $count;
    }
}

// Iniciar plugin
function wc_user_product_restrictions_init() {
    // Verificar se WooCommerce está ativo
    if (class_exists('WooCommerce')) {
        global $wc_user_product_restrictions;
        $wc_user_product_restrictions = new WC_User_Product_Restrictions();
    }
}
add_action('plugins_loaded', 'wc_user_product_restrictions_init');

// Hooks para SFTP-to-WooCommerce - Auto plugin
add_action('sftp_product_created', 'wc_upr_handle_new_sftp_product', 10, 3);
function wc_upr_handle_new_sftp_product($product_id, $file_path, $client_name) {
    global $wc_user_product_restrictions;
    
    if (!isset($wc_user_product_restrictions)) {
        return;
    }
    
    // Verificar se existe um usuário ou criar um novo
    $user_id = $wc_user_product_restrictions->create_user_from_sftp_client($client_name);
    
    if ($user_id) {
        // Associar produto ao usuário
        $allowed_users = get_post_meta($product_id, '_wc_upr_allowed_users', true);
        if (!is_array($allowed_users)) {
            $allowed_users = array();
        }
        
        if (!in_array($user_id, $allowed_users)) {
            $allowed_users[] = $user_id;
            update_post_meta($product_id, '_wc_upr_allowed_users', $allowed_users);
        }
    }
}

// Adicionar ação para sincronização
add_action('admin_post_wc_upr_sync_users', 'wc_upr_sync_users_callback');
function wc_upr_sync_users_callback() {
    // Verificar permissões
    if (!current_user_can('manage_options')) {
        wp_die('Acesso negado');
    }
    
    // Verificar nonce
    check_admin_referer('wc_upr_sync_users_nonce');
    
    global $wc_user_product_restrictions;
    $count = $wc_user_product_restrictions->sync_sftp_clients_to_users();
    
    // Redirecionar de volta com resultado
    wp_redirect(add_query_arg(array(
        'page' => 'wc-user-product-restrictions',
        'synced' => $count,
    ), admin_url('admin.php')));
    exit;
}

// Função de atalho para verificar acesso ao produto
function wc_upr_user_can_access_product($product_id, $user_id = null) {
    global $wc_user_product_restrictions;
    
    if (isset($wc_user_product_restrictions)) {
        return $wc_user_product_restrictions->user_can_access_product($product_id, $user_id);
    }
    
    // Se o plugin não estiver inicializado, permitir acesso
    return true;
}

// Shortcode para exibir produtos do usuário
add_shortcode('user_products', 'wc_upr_user_products_shortcode');
function wc_upr_user_products_shortcode($atts) {
    $atts = shortcode_atts(array(
        'columns' => 4,
        'orderby' => 'title',
        'order' => 'ASC',
        'limit' => -1,
        'user_id' => 0, // 0 = usuário atual
    ), $atts, 'user_products');
    
    // Se não especificado, usar usuário atual
    $user_id = intval($atts['user_id']);
    if ($user_id <= 0) {
        $user_id = get_current_user_id();
    }
    
    // Se não houver usuário logado e nenhum ID especificado, mostrar mensagem
    if ($user_id <= 0) {
        return '<p>Você precisa fazer login para ver seus produtos.</p>';
    }
    
    // Buscar produtos associados a este usuário
    $args = array(
        'post_type' => 'product',
        'post_status' => 'publish',
        'posts_per_page' => $atts['limit'],
        'orderby' => $atts['orderby'],
        'order' => $atts['order'],
        'meta_query' => array(
            'relation' => 'OR',
            array(
                'key' => '_wc_upr_allowed_users',
                'value' => serialize(strval($user_id)),
                'compare' => 'LIKE',
            )
        )
    );
    
    // Adicionar filtro para cliente SFTP se for usado
    if (get_option('wc_upr_use_sftp_structure', 'yes') === 'yes') {
        $user = get_user_by('id', $user_id);
        if ($user) {
            $args['meta_query'][] = array(
                'key' => '_sftp_client',
                'value' => $user->user_login,
                'compare' => '=',
            );
        }
    }
    
    // Buscar produtos
    $products = new WP_Query($args);
    
    ob_start();
    
    if ($products->have_posts()) {
        echo '<div class="woocommerce"><ul class="products columns-' . esc_attr($atts['columns']) . '">';
        
        while ($products->have_posts()) {
            $products->the_post();
            wc_get_template_part('content', 'product');
        }
        
        echo '</ul></div>';
        
        wp_reset_postdata();
    } else {
        echo '<p>Nenhum produto encontrado.</p>';
    }
    
    return ob_get_clean();
}