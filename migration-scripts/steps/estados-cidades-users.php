<?php

// Criamos uma tabela para fazer a transição

$table = 'migra_cidades_u';

$this->log('Limpando metadados');
$wpdb->query("DELETE FROM $wpdb->usermeta WHERE meta_key = '_uf'");
$wpdb->query("DELETE FROM $wpdb->usermeta WHERE meta_key = '_municipio'");

$this->log('Criando tabela temporária');
$wpdb->query("DROP TABLE IF EXISTS `$table`");
$wpdb->query(
    
    "
    CREATE TABLE $table (
    
        tid BIGINT,
        parent BIGINT,
        name VARCHAR(255),
        target_id BIGINT,
        parent_ibge BIGINT default 0,
        cod_ibge BIGINT default 0,
        parent_name VARCHAR(255)
    
    )
    "
    

);

// Pega cidades relacionadas com posts no drupal
$this->log('Importando lista de cidades associadas a usuários');
$this->query('cidades-posts-get', ['{{table}}' => $table, '{{source}}' => 'taxonomy_vocabulary_5', '{{bundle}}' => 'user']);


// Substituindo cidades que sabemos que não existem na base do IBGE
include('estados-cidades-de-para.php');


$this->log('Identificando ID IBGE dos estados');
$this->query('cidades-set-state-ibge', ['{{table}}' => $table]);

$this->log('Atualizando ID IBGE dos estados para os municipios');
$this->query('cidades-set-parent-ibge', ['{{table}}' => $table]);


///// LIMPEZA MANUAL

// Remover referencia a posts que não existem na nossa base
$wpdb->query("DELETE FROM $table WHERE target_id NOT IN (SELECT ID FROM $wpdb->users)");






$this->log('Inserindo metados de uf para usuários');
$this->query('cidades-add-metadata-uf', ['{{table}}' => $table, '{{target}}' => $wpdb->usermeta, '{{target_col}}' => 'user_id']);


$this->log('Inserindo metados de municipios para usuários');
$this->query('cidades-add-metadata-municipios', ['{{table}}' => $table, '{{target}}' => $wpdb->usermeta, '{{target_col}}' => 'user_id']);

$this->log('Cidade e estado dos usuários importadas. ');
$this->log("IMPORTANTE: Os itens da tabela $table com a coluna cod_ibge = NULL trazem os municíios que não foram encontrados na tabela do IBGE. É preciso corrigir e rodar essa migração de novo");