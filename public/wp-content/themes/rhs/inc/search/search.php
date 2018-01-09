<?php

class RHSSearch {

    const BASE_URL = 'busca';
    const BASE_USERS_URL = 'busca/usuarios';
    const USERS_PER_PAGE = 12;
    const EXPORT_TOTAL_PER_PAGE = 500;
    
    function __construct() {
        add_action('pre_get_posts', array(&$this, 'pre_get_posts'), 2);
        add_action('wp_enqueue_scripts', array(&$this, 'addJS'), 2);
        add_action('wp_ajax_generate_csv', array($this, 'generate_csv'));
        add_action('wp_ajax_nopriv_generate_csv', array($this,'generate_csv'));
    }
    
    function addJS() {
        if (get_query_var('rhs_busca')) {
            wp_enqueue_script('rhs_search', get_template_directory_uri() . '/inc/search/search.js', array('bootstrap-datapicker', 'magicJS'));
            wp_localize_script( 'rhs_search', 'search_vars', array( 
                'ajaxurl' => admin_url( 'admin-ajax.php' ),
                'selectedTags' => $this->get_param('tag')
            ) );
        }
    }
    
    static function get_query_string_for_search_urls() {
        $q = [];
        if (self::get_param('keyword')) $q[] = 'keyword=' . self::get_param('keyword');
        if (self::get_param('uf')) $q[] = 'uf=' . self::get_param('uf');
        if (self::get_param('municipio')) $q[] = 'municipio=' . self::get_param('municipio');
        return '?' . implode('&', $q);
    }
    
    /**
     * Monta nova URL de busca, mantendo os parametros de busca atuais, mas removendo a paginação e acrescentando o paramentro de ordenação
     * @param  string $neworder tipo de rodenação
     * @return string           Nova URL
     */
    static function get_search_neworder_urls($neworder) {
        
        
        $base = get_query_var('rhs_busca') == 'users' ? self::BASE_USERS_URL : self::BASE_URL;
        $busca_atual = $_GET;
        
        // Nos certificamos de adicionar estado e cidade a busca, pq eles podem ter vindo
        // na URL (ex: /CE/1234567-fortaleza) e não estar no GET
        $busca_atual['uf'] = self::get_param('uf');
        $busca_atual['municipio'] = self::get_param('municipio');
        
        // Adicionamos a ordenação
        $busca_atual['rhs_order'] = $neworder;
        
        // isso não deve existir, mas não custa excluir
        // apenas de retornar a URL nova, sem o /page/X já resolve
        unset($busca_atual['paged']);
        
        return add_query_arg( $busca_atual, home_url($base) ); 
    }
    
    static function get_search_url() {
        $querystring = self::get_query_string_for_search_urls();
        return home_url(self::BASE_URL) . $querystring;
    }
    
    static function get_users_search_url() {
        $querystring = self::get_query_string_for_search_urls();
        return home_url(self::BASE_USERS_URL) . $querystring;
    }

    function pre_get_posts(&$wp_query) {

        if ( $wp_query->is_main_query() && $wp_query->get( 'rhs_busca' ) == 'posts' ) {

            $wp_query->is_home = false;

            $keyword   =      $this->get_param('keyword');
            $full_term =      $this->get_param('full-term');
            $uf =           $this->get_param('uf');
            $municipio =    $this->get_param('municipio');
            $date_from =    $this->get_param('date_from');
            $date_to =      $this->get_param('date_to');
            $order =        $this->get_param('rhs_order');

            /**
            * Tags e categorias são buscadas automaticamente passando os parametros padrão do WP
            * Ex: &cat=3&tag=2
            *
            * A informação de paginação também já é magicamente tratada pelo WP, pq jogamos ela pra query_var 'paged' lá na rewrite rule
            */

            if (!empty($keyword)) {
                $wp_query->set('s', $keyword);
            }

            if( $full_term == "true" ) {
                $wp_query->set('exact', true);
            }

            // DATAS
            if (!empty($date_from) || !empty($date_to)) {
                $date_query = [];
                $has_date_query = false;

                if (!empty($date_from) && $_datefrom = $this->parse_date($date_from)) {
                    $date_query['after'] = $_datefrom;
                    $has_date_query = true;
                }

                if (!empty($date_to) && $_dateto = $this->parse_date($date_to)) {
                    $date_query['before'] = $_dateto;
                    $has_date_query = true;
                }

                if ($has_date_query) {
                    $date_query['inclusive'] = true;
                    $wp_query->set('date_query', [$date_query]);
                }

            }

            $meta_query = [];
            $has_meta_query = false;

            // ESTADOS E MUNICIPIOS
            if (!empty($uf) || !empty($municipio)) {

                if (!empty($municipio)) {

                    if (is_numeric($municipio)) {
                        $meta_query['municipio_clause'] = [
                            'key' => UFMunicipio::MUN_META,
                            'value' => $municipio,
                            'compare' => '='
                        ];
                        $has_meta_query = true;
                    }


                }

                if (!empty($uf) && !isset($meta_query['municipio']) /* se já tem municipio não precisa filtrar por estado tb */ ) {

                    $cod_uf = is_numeric($uf) ? $uf : UFMunicipio::get_uf_id_from_sigla($uf);
                    
                    if (is_numeric($cod_uf)) {
                        $meta_query['uf_clause'] = [
                            'key' => UFMunicipio::UF_META,
                            'value' => $cod_uf,
                            'compare' => '='
                        ];
                        $has_meta_query = true;
                    }

                }

            }

            // ORDER
            switch ($order) {
                case 'comments':
                    $q_order = 'DESC';
                    $q_order_by = 'comment_count';
                    break;

                // META KEYS
                case 'votes':
                    $q_order_meta = RHSVote::META_TOTAL_VOTES;
                    break;
                case 'shares':
                    $q_order_meta = RHSNetwork::META_KEY_TOTAL_SHARES;
                    break;
                case 'views':
                    $q_order_meta = RHSNetwork::META_KEY_VIEW;
                    break;

                case 'date':
                default:
                    $q_order = 'DESC';
                    $q_order_by = 'post_date';
                    break;
            }

            if (!empty($q_order_meta)) {
                $meta_query['rhs_meta_order'] = [
                    'key' => $q_order_meta,
                    'compare' => 'EXISTS',
                    'type' => 'numeric'
                ];
                $has_meta_query = true;
                $q_order_by = ['rhs_meta_order' => 'DESC'];
                $q_order = 'DESC';
            }

            if ($has_meta_query) {
                $meta_query['relation'] = 'AND';
                $wp_query->set('meta_query', [$meta_query]);
            }

            $wp_query->set('order', $q_order);
            $wp_query->set('orderby', $q_order_by);
            $wp_query->set('post_type', 'post');
            
        }

    }
    
    /**
     * Faz parse de uma string de data no formato YYYY-MM-DD e retorna 
     * um array no formato utilizado nas meta_queries do WP.
     *
     * Se a string não for no formato esperado, retorna False.
     * 
     * @param  string $str_date string no formato YYYY-MM-DD
     * @return array|false           array composto de de três elementos, com as chaves year, month e day e seus respectivos valores. Falso caso a string não seja no formato esperado
     */
    private function parse_date($str_date) {

        preg_match('/^([0-9]{2})-([0-9]{2})-([0-9]{4})$/', $str_date, $matches);

        if (isset($matches[1]) && isset($matches[2]) && isset($matches[3])) {
            return [
                'day' => $matches[1],
                'month' => $matches[2],
                'year' => $matches[3]
            ];
        }

        return false;

    }
    
    /**
     * Retorna o valor do parâmetro, que pode estar sendo passado via URL (rewrite rule)
     * ou diretamente via query string. 
     * 
     * Por exemplo, a função retorna o valor para 'uf' e para 'paged' nos dois formatos:
     * /busca/BA/page/2
     * /busca?uf=BA&paged=2
     * 
     * @param  string $param o nome do parâmetro
     * @return string        o valor do parâmetro
     */
    static public function get_param($param) {
        if (isset($_GET[$param]))
            return $_GET[$param];
            
        $value = get_query_var($param);
        
        if (empty($value) && $param == 'keyword')
            $value = get_query_var('s');
        
        if ($param == 'municipio') {
            // podemos passar só o ID do município, ex: 2900702
            // ou o formato da URL, com {id}-{slug}, ex: 2900702-alagoinhas
            preg_match('/^([0-9]{7})(-.+)?$/', $value, $cod_municipio);
            if (is_array($cod_municipio) && isset($cod_municipio[1]) && is_numeric($cod_municipio[1]))
                $value = $cod_municipio[1];
        }
        
        if ($param == 'uf' && !is_numeric($value))
            $value = UFMunicipio::get_uf_id_from_sigla($value);

        if( $param === 'full-term' && $value === "true") {
            $value = "checked";
        }
        
        return $value;
    }
    

    /**
     * Busca usuários com filtros específicos da RHS
     *
     * Pode-se chamar o método sem nenhum parâmetro (como no template search-users.php). Neste Cadastro
     * serão usados os parâmetros disponíveis na URL de busca.
     * 
     * Opcionalmente pode-se chamar o método passando manualmente os filtros de busca em um array.
     *
     * Os parâmetros possíveis são:
     * $params = array(
     *     'uf' => 'BA', // pode ser a sigla ou id do estado
     *     'municipio' => '2922999' // pode ser o id ou url do municipio (ex: 2922999-nome-da-cidade)
     *     'keyword' => 'aless' // string de busca por nome do usuário
     *     'paged' => 2 // número da página para paginação de resultados,
     *     'rhs_order' => 'votes' // ordenação dos resultados. Valores possíveis são: name, register_date, posts, votes
     * )
     *
     * Exemplos de URs que funcionam:
     * /busca/usuarios/BA
     * /busca/usuarios/BA/page/2/?keyword=caetano
     * /busca/usuarios/?uf=BA&keyword=caetano&paged=2&rhs_order=votes
     * /busca/usuarios/?uf=29&keyword=caetano&paged=2&rhs_order=votes
     *
     * 
     * @param  array  $params opcional, os filtros de busca
     * @return Object WP_User_Query 
     */
    public function search_users($params = array()) {
        
        $users_per_page = isset($params['export_number']);
        if ($users_per_page) {
            $users_per_page = self::EXPORT_TOTAL_PER_PAGE;
        } else {
            $users_per_page = self::USERS_PER_PAGE;
        }
    
        $meta_query = [];
        $has_meta_query = false;
        
        $_filters = array_merge([
            'uf' => $this->get_param('uf'),
            'keyword' => $this->get_param('keyword'),
            'municipio' => $this->get_param('municipio'),
            'rhs_order' => $this->get_param('rhs_order'),
            'paged' => get_query_var('paged'),
        ], $params);
        
        $keyword =      $_filters['keyword'];
        $uf =           $_filters['uf'];
        $municipio =    $_filters['municipio'];
        $rhs_order =    $_filters['rhs_order'];
        $paged =    $_filters['paged'] ? $_filters['paged'] : 1;
        
      
        if (!empty($uf) || !empty($municipio)) {
            if (!empty($municipio)) {
                // podemos passar só o ID do município, ex: 2900702
                // ou o formato da URL, com {id}-{slug}, ex: 2900702-alagoinhas
                preg_match('/^([0-9]{7})(-.+)?$/', $municipio, $cod_municipio);
                if (is_numeric($cod_municipio[1])) {
                    $meta_query['_municipio'] = [
                        'key' => UFMunicipio::MUN_META,
                        'value' => $cod_municipio[1],
                        'compare' => '='
                    ];
                    $cod_municipio = $cod_municipio[1];
                    $has_meta_query = true;
                }

            }

            if (!empty($uf) && !isset($meta_query['municipio']) /* se já tem municipio não precisa filtrar por estado tb */ ) {           
                $cod_uf = is_numeric($uf) ? $uf : UFMunicipio::get_uf_id_from_sigla($uf);
                if (is_numeric($cod_uf)) {
                    $meta_query['_uf'] = [
                        'key' => UFMunicipio::UF_META,
                        'value' => $cod_uf,
                        'compare' => '='
                    ];
                    $has_meta_query = true;
                }
            }
        }
        
        $q_has_publish_posts = false;

        switch ($rhs_order) {
            case 'name':
                $q_order = 'ASC';
                $q_order_by = 'display_name';
                break;
            
            case 'register_date':
                $q_order = 'DESC';
                $q_order_by = 'registered';
                break;
            
            case 'posts':
                $q_order = 'DESC';
                $q_order_by = 'post_count';
                $q_has_publish_posts = true;
                break;
                
            case 'votes':
                $q_order_meta = RHSVote::META_TOTAL_VOTES;
                break;
            
            default:
                $q_order = 'ASC';;
                $q_order_by = 'display_name';
                $q_order = 'ASC';
                break;
        }

        if (!empty($q_order_meta)) {
            if($q_order_meta == RHSLogin::META_KEY_LAST_LOGIN) {
                $meta_query['rhs_order'] = [
                    'key' => $q_order_meta,
                    'value' => date("Y-m-d H:i:s"),
                    'compare' => '<',
                    'type' => 'DATE'
                ];
            } else {
                $meta_query['rhs_order'] = [
                    'key' => $q_order_meta,
                    'compare' => 'EXISTS',
                    'type' => 'numeric'
                ];

                $q_order_by = ['rhs_order' => 'DESC'];
                $q_order = 'DESC';
            }
            $has_meta_query = true;            
        }

        $offset = $users_per_page * ($paged - 1);
        $cod_uf = ($uf) ? $uf : '' ;
        $cod_municipio = ($municipio) ? $municipio : '' ;
        
        $filters = array(
            //'role'       => 'contributor',
            'order'      => $q_order,
            'orderby'    => $q_order_by,
            'search'     => '*' . esc_attr($this->get_param('keyword')) . '*',
            'paged'     => $paged,
            'number'    => $users_per_page,
            'offset'    => $offset,
            'has_published_posts' => $q_has_publish_posts,
         );
         
         if ($has_meta_query) {
             $filters['meta_query'] = $meta_query;
         }
        
        // Retorna o objeto com a lista de usuários encontrados
        return new WP_User_Query($filters);      
        
    }
    /**
     * Exibe paginação
     * 
     * @return mixed Return html with paginate links
     */
    function show_users_pagination($users) {
        $paged = $users->query_vars['paged'];
        $users_per_page = self::USERS_PER_PAGE;
        $total_pages = 1;
        $total_pages = ceil($users->total_users / $users_per_page);
        $big = 999999999;
        $content = paginate_links( array(
            'base'         => str_replace($big, '%#%', get_pagenum_link($big)),
            'format'       => '/page/%#%',
            'prev_text'    => __('&laquo; Anterior'),
            'next_text'    => __('Próxima &raquo;'), 
            'total'        => $total_pages,
            'current'      => $paged,
            'end_size'     => 4,
            'type'         => 'array',
            'mid_size'     => 8,
            'prev_next'    => true,
        ));
        
        if (is_array($content)) {
            $current_page = $paged;
            echo '<ul class="pagination">';
            foreach ($content as $i => $page) {
                echo "<li>$page</li>";
            }
            echo '</ul>';
        }
    }


    /**
    * Exibe botão para download de CSV
    */
    static function show_button_download_report() {
        global $wp_query;
        global $RHSSearch;
        global $RHSVote;

        $users = $RHSSearch->search_users();
        $search_user_has_uf_mun = isset($users->query_vars['meta_query']);      
        $search_user_has_keyword = strlen(utf8_decode($users->query_vars['search']));
        
        $search_post_has_cat = isset($wp_query->query['cat']);
        $search_post_has_tag = isset($wp_query->query['tag']);
        $search_post_has_date = $wp_query->date_query;

        if(get_search_query() || $search_user_has_keyword > 2 || $search_user_has_uf_mun || $search_post_has_cat || $search_post_has_tag || $search_post_has_date) {
            echo "<button type='button' class='btn btn-default filtro' data-toggle='modal' data-target='#exportModal'> Exportar CSV <i class='fa fa-save fa-fw'></i></button>";
        }
    }

    /**
    * Convertendo dados em csv
    */

    static function generate_csv() {
        global $wp_query;
        global $RHSVote;
        global $RHSNetwork;
        global $RHSSearch;
        
        $user_id = get_current_user_id();
        $pagename = get_transient('page_name_'.$user_id);
        $query_params = get_transient('download_query_'.$user_id);
        
        if ($pagename == 'posts') {
            $query_params['paged'] = $_POST['paged'];
            $content_file = get_posts($query_params);
        }

        if($pagename == 'users') {
            $query_params['paged'] = $_POST['paged'];
            $query_params['export_number'] = true;
            $get_users = $RHSSearch->search_users($query_params);
            $content_file = $get_users->results;
        }
       
        $file = fopen('php://output', 'w');

        

        if($pagename == 'users') {
            fputcsv($file, array('Nome do Usuário', 'Data de Cadastro', 'Total de Postagens', 'Total de Votos Recebidos', 'Estado', 'Cidade'));

            foreach($content_file as $user) {
                
                $name = $user->display_name;
                $register_date = $user->user_registered;;
                
                $get_total_posts = count_user_posts($user->ID);
                $get_total_votes = $RHSVote->get_total_votes_by_author($user->ID);

                $total_posts = return_value_or_zero($get_total_posts); 
                $total_votes = return_value_or_zero($get_total_votes); 

                $user_ufmun = get_user_ufmun($user->ID);
                $uf = return_value_or_dash($user_ufmun['uf']['sigla']);
                $mun = return_value_or_dash($user_ufmun['mun']['nome']);

                $row_data[] = [
                    'nome'=> $name,
                    'date' => date("d/m/Y H:i:s",strtotime($register_date)),
                    'total_posts' => $total_posts,
                    'total_votes' => $total_votes,
                    'state' => $uf,
                    'city' => $mun
                ];
            }
        }

        if($pagename == 'posts') {
            fputcsv($file, array('Título', 'Conteúdo','Data', 'Autor', 'Link', 'Visualizações', 'Compartilhamentos', 'Votos', 'Comentários', 'Estado', 'Cidade'));
                    
            foreach($content_file as $post) {
                
                $get_title = html_entity_decode(get_the_title($post->ID), ENT_QUOTES, "UTF-8");
                
                $raw_content = get_post_field('post_content', $post->ID);
                $post_content = iconv( "utf-8", "utf-8", $raw_content );
                $post_content = strip_html_tags( $post_content );
                $post_content = html_entity_decode($post_content, ENT_QUOTES, "UTF-8");

                $get_date = get_the_date('d/m/Y H:i:s', $post->ID);
                $get_author = get_the_author_meta('user_firstname', $post->post_author) . " " . get_the_author_meta('user_lastname', $post->post_author);
                $get_link = $post->guid;
                $get_views = $RHSNetwork->get_post_total_views($post->ID);
                $get_shares = $RHSNetwork->get_post_total_shares($post->ID);
                $get_comments = wp_count_comments($post->ID);
                $get_votes = $RHSVote->get_total_votes($post->ID);

                $views = return_value_or_zero($get_views);
                $shares = return_value_or_zero($get_shares);
                $votes = return_value_or_zero($get_votes);
                $comments = return_value_or_zero($get_comments);
                
                $post_ufmun = get_post_ufmun($post->ID);
                $uf = $post_ufmun['uf']['sigla'];
                $mun = $post_ufmun['mun']['nome'];

                $row_data[] = [
                    'titulo'=> $get_title,
                    'conteudo' => $post_content,
                    'data'=> $get_date,
                    'autor' => $get_author,
                    'link' => $get_link,
                    'visualizacoes' => $views,
                    'compartilhamentos' => $shares,
                    'votos' => $votes,
                    'comentarios' => $comments,
                    'estado' => return_value_or_dash($uf),
                    'cidade' => return_value_or_dash($mun)
                ];
            }

        }

        foreach ($row_data as $row) {
            fputcsv($file, $row);
        }

        mb_convert_encoding($file, 'UTF-16LE', 'UTF-8');

        fclose($file);
        exit;        

    }

    public static function render_uf_city_select() {
        UFMunicipio::form( array(
            'content_before_field' => '<div class="form-group col-md-6">',
            'content_after_field' => '</div>',
            'select_before' => ' ',
            'select_after' => ' ',
            'state_label' => 'Estado &nbsp',
            'state_field_name' => 'uf',
            'city_label' => 'Cidade &nbsp',
            'select_class' => 'form-control',
            'label_class' => 'control-label',
            'selected_state' => RHSSearch::get_param('uf'),
            'selected_municipio' => RHSSearch::get_param('municipio'),
        ) );
    }

    public static function getSearchButtons() {
        $default_classes = "btn btn-default filtro";
        return "<button type='submit' class='$default_classes btn-rhs'>Filtrar</button> <button type='reset' class='$default_classes'>Limpar Filtros</button>";
    }

    /**
     * Show result posts
     *
     * @return o resultado dos posts.
     */

    function exibir_resultado_post() {
        global $wp_query;
        $result = $wp_query;

        $total = $result->found_posts;
        $paged = empty($result->query['paged']) ? 1 : $result->query['paged'];
        $per_page = $result->query_vars['posts_per_page'];
        $final = $per_page * $paged;

        if($total > 0) {
            $initial = $final - ($per_page-1);
            if ($final > $total) $final = $total;
            echo "Exibindo $initial a $final de $total resultados";
        } else {
            _e("Nenhum post encontrado com estes termos de busca!", "rhs");
        }
    }
}


/**
 * Show result usuarios
 *
 * @return o resultado dos usuarios.
*/

function exibir_resultado_user(){
    $RHSSearch = new RHSSearch();
    $users = $RHSSearch->search_users();
    $total = $users->total_users;
    $paged = empty($users->query_vars['paged']) ? 1 : $users->query_vars['paged'];
    $per_page = $users->query_vars['number'];

    $final = $per_page * $paged;

    $initial = $final - ($per_page-1);
    if ($final > $total) $final = $total;

    echo "Exibindo $initial a $final de $total resultados";
}

function return_value_or_zero($value){
    $value = (int)$value;
    if($value > 0)
        return $value;
    else
        return '0';
}

function return_value_or_dash($value){
    if(!$value)
        return '-';
    else
        return $value;
}

function show_results_from_search() {
    global $wp_query;
    global $RHSSearch;
    $per_page = $RHSSearch::EXPORT_TOTAL_PER_PAGE;

    $wp_query->query($wp_query->query_vars);
    $wp_query->set('posts_per_page', $per_page);
    $result = $wp_query;
    $search_page = get_query_var('rhs_busca');
    $user_id = get_current_user_id();
    
    if($search_page == 'posts') {
        $total = $result->found_posts;
        set_transient('download_query_'.$user_id, $wp_query->query_vars, 60*60);
    }

    if($search_page == 'users') {
        $get_users = $RHSSearch->search_users($wp_query->query_vars);
        $total = $get_users->total_users;
        set_transient('download_query_'.$user_id, $wp_query->query_vars, 60*60);
    }
    
    $total_pages = ceil($total / $per_page);
    
    set_transient('page_name_'.$user_id, get_query_var('rhs_busca'), 60*60);
    
    $count = 1;
    
    if (empty($total_pages)) {
        $total_pages = 0;
    } elseif ($total_pages == 1){
        $text_pages = 'página';
    } else {
        $text_pages = 'páginas';
    }

    echo "<p>Conteúdo disponível: <strong>" . $total_pages . " </strong> ". $text_pages .".</p>";
    echo "<p>Clique em uma página abaixo para iniciar a exportação:</p>";
    while($count < $total_pages+1) {
        echo "<a class='btn btn-rhs export-csv' data-page='". $count . "' data-page-title=". $search_page .">Página ". $count ." <i class='export-loader fa fa-circle-o-notch fa-spin fa-fw hide'></i></a> ";
        $count++;
    }
    echo "<hr>";
    echo "<p>Exibindo $initial a $final de $total resultados</p>";
}

global $RHSSearch;
$RHSSearch = new RHSSearch();
