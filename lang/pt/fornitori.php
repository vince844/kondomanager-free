<?php

return [

    /* ------------------------------------------------------------------
     | Notificações Backend
     | ------------------------------------------------------------------ */
    'success_create_fornitore'           => "O novo fornecedor foi criado com sucesso.",
    'error_create_fornitore'             => "Ocorreu um erro ao criar o novo fornecedor.",
    'success_update_fornitore'           => "O fornecedor foi atualizado com sucesso.",
    'error_update_fornitore'             => "Ocorreu um erro ao atualizar o fornecedor.",
    'success_delete_fornitore'           => "O fornecedor foi eliminado com sucesso.",     
    'error_delete_fornitore'             => "Ocorreu um erro ao eliminar o fornecedor.",
    'success_attach_anagrafica'          => "O registo foi associado com sucesso ao fornecedor.",
    'error_attach_anagrafica'            => "Ocorreu um erro ao associar o registo ao fornecedor.",
    'success_detach_anagrafica'          => "O registo foi desassociado com sucesso do fornecedor.",
    'error_detach_anagrafica'            => "Ocorreu um erro ao desassociar o registo do fornecedor.",

    /* ------------------------------------------------------------------
     | Cabeçalhos, Títulos e Descrições
     | ------------------------------------------------------------------ */
    'header' => [
        'list_fornitori_head'           => "Lista de fornecedores",
        'list_fornitori_title'          => "Lista de fornecedores",
        'list_fornitori_description'    => "Gerencie o registo de fornecedores e profissionais associados aos seus condomínios.",
        'new_fornitore_head'            => "Criar fornecedor",
        'new_fornitore_title'           => "Criar fornecedor",
        'new_fornitore_description'     => "Insira os dados para registar uma nova empresa ou profissional.",
    ],

    /* ------------------------------------------------------------------
     | Tabela
     | ------------------------------------------------------------------ */
    'table' => [
        'name'           => 'Razão Social',
        'address'        => 'Morada',
        'contacts'       => 'Contactos',
        'type'           => 'Tipo',
        'actions'        => 'Ações',
        'click_to_view'  => 'Clique para visualizar',
        'filter_by_name' => 'Procurar fornecedor...',
        'residents'      => 'Referentes',
        'residents_desc' => 'Lista de contactos e referentes associados a este fornecedor.',
    ],

    /* ------------------------------------------------------------------
     | Rótulos e Placeholders
     | ------------------------------------------------------------------ */
    'label' => [
        'tax_code'   => 'Número de Contribuinte',
        'vat_number' => 'Número de IVA',
    ],

    'placeholder' => [
        'no_address' => 'Morada não disponível',
    ],

    /* ------------------------------------------------------------------
     | Ações
     | ------------------------------------------------------------------ */
    'actions' => [
        'new_fornitore'   => 'Novo Fornecedor',
        'edit_fornitore'  => 'Editar',
        'delete_fornitore'=> 'Eliminar',
        'save_fornitore'  => 'Guardar Fornecedor',
        'list'            => 'Lista', 
    ],

    /* ------------------------------------------------------------------
     | Guias (PageHeaderGuide)
     | ------------------------------------------------------------------ */
    'guides' => [
        'portfolio_title'             => 'Directório de Fornecedores',
        'portfolio_desc'              => 'Aceda rapidamente aos dados fiscais e de contacto de todas as empresas e profissionais.',
        'compliance_title'            => 'Conformidade Fiscal',
        'compliance_desc'             => 'Verifique NIFs e códigos fiscais para uma faturação eletrónica correta.',
        'management_title'            => 'Gestão Rápida',
        'management_desc'             => 'Adicione novos fornecedores ou atualize contactos para melhorar a comunicação.',
        'new_fornitore_guide_title'   => 'Inserção de Dados', 
        'new_fornitore_guide_desc'    => 'Certifique-se de introduzir corretamente o NIF para permitir o envio de retenções na fonte.', 
    ],
    
];