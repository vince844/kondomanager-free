<?php

return [
    /* ------------------------------------------------------------------
     | Backend notifications
     | ------------------------------------------------------------------ */
    'success_create_building' => 'O novo condomínio foi criado com sucesso.',
    'error_create_building'   => 'Ocorreu um erro durante a criação do condomínio.',
    'success_edit_building'   => 'O condomínio foi modificado com sucesso.',
    'error_edit_building'     => 'Ocorreu um erro durante a modificação do condomínio.',
    'success_delete_building' => 'O condomínio foi eliminado com sucesso.',
    'error_delete_building'   => 'Ocorreu um erro durante a eliminação do condomínio.',

    /* ------------------------------------------------------------------
     | Front‑end strings (headings, titles, descriptions)
     | ------------------------------------------------------------------ */
    'header' => [
        'list_buildings_head'           => 'Lista de condomínios',
        'list_buildings_title'          => 'Lista de condomínios',
        'list_buildings_description'    => 'A seguir a tabela com a lista de todos os perfis dos condomínios registados',
        'new_building_head'             => 'Criar condomínio',
        'new_building_title'            => 'Criar condomínio',
        'new_building_description'      => 'Preencha o seguinte formulário para criar um novo condomínio',
        'edit_building_head'            => 'Editar condomínio',
        'edit_building_title'           => 'Editar condomínio',
        'edit_building_description'     => 'Preencha o seguinte formulário para atualizar ou modificar os dados do condomínio',
        'building_info_heading'         => 'Dados identificativos',
        'building_info_description'     => 'A seguir pode especificar os dados identificativos do condomínio',
        'building_registry_heading'     => 'Dados cadastrais',
        'building_registry_description' => 'A seguir pode especificar os dados cadastrais do condomínio',
    ],

    /* ------------------------------------------------------------------
     | Table column headers & generic UI strings
     | ------------------------------------------------------------------ */
    'table' => [
        'name'             => 'Denominação',
        'address'          => 'Morada',
        'filter_by_name'   => 'Filtrar por nome...',
        'actions'          => 'Ações',
    ],

    /* ------------------------------------------------------------------
     | Labels for form fields
     | ------------------------------------------------------------------ */
    'label' => [
        'name'              => 'Denominação',
        'address'           => 'Morada',
        'tax_code'          => 'Número de contribuinte',
        'email'             => 'Endereço de correio eletrónico',
        'notes'             => 'Notas',
        'municipality'      => 'Município (cadastro)',
        'municipality_code' => 'Código do município',
        'section'           => 'Secção',
        'sheet'             => 'Folha',
        'parcel'            => 'Partícula',
    ],

    /* ------------------------------------------------------------------
     | Placeholders for inputs
     | ------------------------------------------------------------------ */
    'placeholder' => [
        'name'              => 'Inserir a denominação do condomínio',
        'address'           => 'Inserir a morada do condomínio',
        'tax_code'          => 'Número de contribuinte do condomínio',
        'email'             => 'Endereço de correio eletrónico do condomínio',
        'notes'             => 'Inserir uma nota adicional aqui',
        'municipality'      => 'Município (cadastro)',
        'municipality_code' => 'Código do município',
        'section'           => 'Secção cadastral',
        'sheet'             => 'Folha cadastral',
        'parcel'            => 'Partícula cadastral',
    ],

    /* ------------------------------------------------------------------
     | Empty‑state / dialog messages
     | ------------------------------------------------------------------ */
    'dialogs' => [
        'no_buildings_created' => 'Ainda não foi criado nenhum condomínio',
    ],

    /* ------------------------------------------------------------------
     | Action buttons (toolbar, card actions, etc.)
     | ------------------------------------------------------------------ */
    'actions' => [
        'new_building'    => 'Criar',
        'edit_building'   => 'Editar',
        'delete_building' => 'Eliminar',
        'save_building'   => 'Guardar',
        'list_buildings'  => 'Lista',
    ],
];