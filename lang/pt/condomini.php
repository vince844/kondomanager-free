<?php

return [

    /* ------------------------------------------------------------------
     | Backend notifications
     | ------------------------------------------------------------------ */
    'success_create_building' => "O novo condomínio foi criado com sucesso.",
    'error_create_building'   => "Ocorreu um erro ao criar o condomínio.",
    'success_edit_building'   => "O condomínio foi atualizado com sucesso.",
    'error_edit_building'     => "Ocorreu um erro ao atualizar o condomínio.",
    'success_delete_building' => "O condomínio foi excluído com sucesso.",
    'error_delete_building'   => "Ocorreu um erro ao excluir o condomínio.",

    /* ------------------------------------------------------------------
     | Front‑end strings (headings, titles, descriptions)
     | ------------------------------------------------------------------ */
    'header' => [
        'list_buildings_head'           => "Lista de Condomínios",
        'list_buildings_title'          => "Condomínios",
        'list_buildings_description'    => "Abaixo está a tabela com a lista de todos os perfis de condomínios registrados.",
        'new_building_head'             => "Criar Condomínio",
        'new_building_title'            => "Novo Condomínio",
        'new_building_description'      => "Preencha o formulário abaixo para registrar um novo condomínio.",
        'edit_building_head'            => "Editar Condomínio",
        'edit_building_title'           => "Editar Condomínio",
        'edit_building_description'     => "Atualize os dados cadastrais e estruturais do condomínio.",
    ],

    /* ------------------------------------------------------------------
     | Sezioni del Modulo (Card)
     | ------------------------------------------------------------------ */
    'cards' => [
        'info_title'            => "Informações Principais",
        'info_desc'             => "Dados essenciais de identificação do condomínio.",
        'location_title'        => "Localização",
        'location_desc'         => "Endereço de referência do edifício.",
        'registry_title'        => "Dados Estruturais e Cadastrais",
        'registry_desc'         => "Informações sobre o edifício e detalhes de registro imobiliário.",
        'notes_helper'          => "As notas inseridas aqui serão visíveis apenas para a equipe da administração.",
    ],

    /* ------------------------------------------------------------------
     | Table column headers & generic UI strings
     | ------------------------------------------------------------------ */
    'table' => [
        'name'           => 'Denominação',
        'address'        => 'Endereço',
        'filter_by_name' => 'Filtrar por nome...',
        'actions'        => 'Ações',
        'residents'      => 'Cadastros',
        'residents_desc' => 'Consulte rapidamente a lista completa de pessoas associadas a este condomínio.',
        'total'          => '{1} 1 no total|[2,*] :count no total',
        'click_to_manage'=> 'Clique para gerenciar',
    ],

    /* ------------------------------------------------------------------
     | Labels for form fields
     | ------------------------------------------------------------------ */
    'label' => [
        'name'               => 'Denominação',
        'address'            => 'Endereço e número',
        'city'               => 'Cidade',
        'province'           => 'Estado / Prov.',
        'zip_code'           => 'CEP',
        'tax_code'           => 'Código Fiscal (NIF/CNPJ)',
        'email'              => 'E-mail',
        'notes'              => 'Notas internas adicionais',
        'build_year'         => 'Ano de construção',
        'acquisition_year'   => 'Ano de aquisição',
        'floors'             => 'Número de andares',
        'municipality'       => 'Município (Cadastro)',
        'municipality_code'  => 'Código do Município',
        'section'            => 'Seção',
        'sheet'              => 'Folha',
        'parcel'             => 'Lote / Parcela',
    ],

    /* ------------------------------------------------------------------
     | Placeholders for inputs
     | ------------------------------------------------------------------ */
    'placeholder' => [
        'name'               => 'Ex. Condomínio Girassol',
        'address'            => 'Rua, Avenida, Praça...',
        'city'               => 'Ex. São Paulo, Lisboa',
        'province'           => 'SP',
        'zip_code'           => '00000',
        'tax_code'           => 'Código fiscal',
        'email'              => 'email@condominio.com',
        'notes'              => 'Insira uma nota visível apenas para os administradores...',
        'build_year'         => 'Ex. 1980',
        'acquisition_year'   => 'Ex. 2024',
        'floors'             => 'Ex. 5',
        'municipality'       => 'Município de registro',
        'municipality_code'  => 'Código de registro',
        'section'            => 'Seção',
        'sheet'              => 'Folha',
        'parcel'             => 'Lote / Parcela',
        'no_address'         => 'Endereço não disponível',
    ],

    /* ------------------------------------------------------------------
     | Empty‑state / dialog messages
     | ------------------------------------------------------------------ */
    'dialogs' => [
        'no_buildings_created' => "Nenhum condomínio criado ainda",
        'close_list'           => "Fechar Lista",
    ],

    /* ------------------------------------------------------------------
     | Action buttons (toolbar, card actions, etc.)
     | ------------------------------------------------------------------ */
    'actions' => [
        'new_building'   => 'Criar',
        'edit_building'  => 'Editar',
        'delete_building'=> 'Excluir',
        'save_building'  => 'Salvar',
        'update_building'=> 'Atualizar', 
        'list_buildings' => 'Lista',
        'cancel'         => 'Cancelar',
    ],

    /* ------------------------------------------------------------------
     | Page Guides (Cards - PageHeaderGuide)
     | ------------------------------------------------------------------ */
    'guides' => [
        'portfolio_title'        => 'Portfólio de Edifícios',
        'portfolio_desc'         => 'Visão geral de todos os condomínios gerenciados. Aqui você tem controle total sobre seus mandatos.',
        'quick_access_title'     => 'Acesso Rápido',
        'quick_access_desc'      => 'Clique em um condomínio para entrar em sua área de gestão dedicada (faturas, parcelas, orçamentos).',
        'new_acquisitions_title' => 'Novas Aquisições',
        'new_acquisitions_desc'  => 'Adicione novos edifícios ao sistema e comece a configurar contatos e contas bancárias.',
        
        // Guide per la pagina CREATE (Nuovo Condominio)
        'create_info_title'      => 'Dados Gerais',
        'create_info_desc'       => 'Insira o nome, contatos e informações principais do novo edifício.',
        'create_registry_title'  => 'Dados Cadastrais',
        'create_registry_desc'   => 'Preencha os dados cadastrais, essenciais para conformidade fiscal e práticas imobiliárias.',
        'create_notes_title'     => 'Notas Internas',
        'create_notes_desc'      => 'Adicione notas, códigos de acesso ou instruções visíveis apenas para o escritório.',

        // Guide per la pagina EDIT (Modifica Condominio)
        'edit_info_title'        => 'Dados Gerais',
        'edit_info_desc'         => 'Edite o nome, contatos e informações principais do condomínio.',
        'edit_registry_title'    => 'Dados Cadastrais',
        'edit_registry_desc'     => 'Atualize os dados cadastrais para manter a contabilidade alinhada às regulamentações.',
        'edit_notes_title'       => 'Notas Internas',
        'edit_notes_desc'        => 'Atualize notas, códigos de acesso ou instruções reservadas ao escritório.',
    ],
];