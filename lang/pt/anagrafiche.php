<?php

return [
    /* ------------------------------------------------------------------
     | Backend notifications
     | ------------------------------------------------------------------ */
    'success_create_anagrafica' => 'O novo registo foi criado com sucesso.',
    'error_create_anagrafica'   => 'Ocorreu um erro durante a criação do novo registo.',
    'success_update_anagrafica' => 'O registo foi atualizado com sucesso.',
    'error_update_anagrafica'   => 'Ocorreu um erro durante a atualização do registo.',
    'success_delete_anagrafica' => 'O registo foi eliminado com sucesso.',
    'error_delete_anagrafica'   => 'Ocorreu um erro durante a eliminação do registo.',
    'anagrafica_has_building'   => 'O registo não pode ser eliminado porque está associado a um ou mais condomínios',

    /* ------------------------------------------------------------------
     | Front‑end strings (headings, titles, descriptions)
     | ------------------------------------------------------------------ */
    'header' => [
        'list_residents_head'         => 'Lista de registos',
        'list_residents_title'        => 'Registos na lista de contactos',
        'list_residents_description'  => 'A seguir a tabela com a lista de todos os registos na lista de contactos',
        'new_resident_head'           => 'Criar registo',
        'new_resident_title'          => 'Criar novo registo',
        'new_resident_description'    => 'Preencha o seguinte formulário para criar um novo registo',
        'edit_resident_head'          => 'Editar registo',
        'edit_resident_title'         => 'Editar registo',
        'edit_resident_description'   => 'Preencha o seguinte formulário para editar o registo na lista de contactos',
        'resident_info_heading'       => 'Dados pessoais',
        'resident_info_description'   => 'A seguir pode especificar os dados pessoais gerais do registo',
        'resident_fiscal_heading'     => 'Dados fiscais',
        'resident_fiscal_description' => 'A seguir pode especificar os dados fiscais do registo',
    ],

    /* ------------------------------------------------------------------
     | Table
     | ------------------------------------------------------------------ */
    'table' => [
        'name'      => 'Nome completo',
        'address'   => 'Morada',
        'buildings' => 'Condomínios',
        'actions'   => 'Ações',
        'filter'    => 'Filtrar por nome...',
    ],

    /* ------------------------------------------------------------------
     | Actions
     | ------------------------------------------------------------------ */
    'actions' => [
        'new_resident'    => 'Criar',
        'edit_resident'   => 'Editar',
        'delete_resident' => 'Eliminar',
        'save_resident'   => 'Guardar',
        'list_residents'  => 'Lista',
    ],

    /* ------------------------------------------------------------------
     | Labels for form fields
     | ------------------------------------------------------------------ */
    'label' => [
        'name'              => 'Nome completo',
        'address'           => 'Morada de residência',
        'telephone'         => 'Telefone fixo',
        'mobile'            => 'Telemóvel',
        'primary_email'     => 'Endereço de correio eletrónico principal',
        'secondary_email'   => 'Endereço de correio eletrónico secundário',
        'pec'               => 'Endereço de correio eletrónico certificado (PEC)',
        'document_type'     => 'Tipo de documento',
        'document_number'   => 'Número do documento',
        'document_expiry'   => 'Data de validade do documento',
        'fiscal_code'       => 'Número de contribuinte',
        'birth_place'       => 'Local de nascimento',
        'birthday'          => 'Data de nascimento',
        'buildings'         => 'Condomínios',
        'notes'             => 'Notas adicionais',
        'passport'          => 'Passaporte',
        'id_card'           => 'Cartão de cidadão',
    ],

    /* ------------------------------------------------------------------
     | Placeholders for inputs
     | ------------------------------------------------------------------ */
    'placeholder' => [
        'name'              => 'Inserir nome completo',
        'address'           => 'Inserir morada de residência',
        'telephone'         => 'Inserir número de telefone fixo',
        'mobile'            => 'Inserir número de telemóvel',
        'primary_email'     => 'Inserir endereço de correio eletrónico principal',
        'secondary_email'   => 'Inserir endereço de correio eletrónico secundário',
        'pec'               => 'Inserir endereço de correio eletrónico certificado (PEC)',
        'document_type'     => 'Selecionar tipo de documento',
        'document_number'   => 'Inserir número do documento',
        'document_expiry'   => 'Data de validade do documento',
        'fiscal_code'       => 'Inserir número de contribuinte',
        'birth_place'       => 'Inserir local de nascimento',
        'birthday'          => 'Inserir data de nascimento',
        'buildings'         => 'Selecionar condomínios',
        'notes'             => 'Inserir uma nota adicional',
    ],

    /* ------------------------------------------------------------------
     | Tooltips
     | ------------------------------------------------------------------ */
    'tooltip' => [
        'buildings_header'      => 'Selecionar e associar condomínios',
        'buildings_description' => 'Pode selecionar um ou mais condomínios para associar a este registo. Isto permitirá que o registo visualize os dados dos condomínios associados.',
    ],

    /* ------------------------------------------------------------------
     | Dialogs
     | ------------------------------------------------------------------ */
    'dialogs' => [
        'delete_resident_title'       => 'Tem a certeza de que pretende eliminar este registo?',
        'delete_resident_description' => 'Esta ação é irreversível. Eliminará o registo e todos os dados associados.',
        'no_residents_created'        => 'Ainda não foi criado nenhum registo',
    ],
];