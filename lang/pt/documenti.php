<?php

return [
    /* ------------------------------------------------------------------
     | Backend notifications
     | ------------------------------------------------------------------ */
    'success_create_document'        => 'O novo documento foi criado com sucesso.',
    'error_create_document'          => 'Ocorreu um erro durante a criação do documento.',
    'no_file_uploaded'               => 'Nenhum ficheiro carregado. Por favor tente novamente.',
    'file_not_found'                 => 'Nenhum ficheiro encontrado no servidor.',
    'success_delete_document'        => 'O documento foi eliminado com sucesso.',
    'success_update_document'        => 'O documento foi atualizado com sucesso.',
    'error_update_document'          => 'Ocorreu um erro durante a atualização do documento.',
    'error_delete_document'          => 'Ocorreu um erro durante a eliminação do documento.',
    'error_downloading_document'     => 'Ocorreu um erro durante o download do documento.',
    'success_approve_document'       => 'O documento foi aprovado com sucesso.',
    'error_approve_document'         => 'Ocorreu um erro durante a aprovação do documento.',
    'error_notify_new_document'      => 'O documento foi criado, mas ocorreu um erro no envio da notificação.',
    'error_notify_approved_document' => 'O documento foi aprovado, mas ocorreu um erro no envio da notificação.',
    'category_has_documents'         => 'Esta categoria contém documentos. Mova-os ou elimine-os antes de eliminar a categoria.',
    'success_delete_category'        => 'A categoria de documentos foi eliminada com sucesso.',
    'error_delete_category'          => 'Ocorreu um erro durante a eliminação da categoria de documentos.',
    'success_create_category'        => 'A categoria de documentos foi criada com sucesso.',
    'error_create_category'          => 'Ocorreu um erro durante a criação da categoria de documentos.',
    'success_update_category'        => 'A categoria de documentos foi atualizada com sucesso.',
    'error_update_category'          => 'Ocorreu um erro durante a atualização da categoria de documentos.',

    /* ------------------------------------------------------------------
     | Front-end strings (headings, titles, descriptions)
     | ------------------------------------------------------------------ */
    'header' => [
        'list_documents_head'           => 'Lista de arquivo de documentos',
        'list_documents_title'          => 'Lista de arquivo de documentos',
        'list_documents_description'    => 'A seguir a tabela com a lista de todos os documentos guardados no arquivo do condomínio',
        'new_document_head'             => 'Criar novo documento',
        'new_document_title'            => 'Criar documento no arquivo',
        'new_document_description'      => 'Preencha o seguinte formulário para criar um novo documento para o arquivo do condomínio',
        'edit_document_head'            => 'Editar documento',
        'edit_document_title'           => 'Editar documento do arquivo',
        'edit_document_description'     => 'Preencha o seguinte formulário para editar o documento no arquivo do condomínio',
        'list_categories_head'          => 'Categorias do arquivo',
        'list_categories_title'         => 'Lista de categorias do arquivo de documentos',
        'list_categories_description'   => 'A seguir a tabela com a lista de todas as categorias de documentos no arquivo do condomínio',
        'categories' => [
            'new_category_title'            => 'Criar nova categoria',
            'new_category_description'      => 'Adicione uma nova categoria para os documentos',
            'edit_category_title'           => 'Editar categoria: :category',
            'edit_category_description'     => 'A seguir pode modificar os detalhes da categoria',
        ],
    ],

    /* ------------------------------------------------------------------
     | Form Sections (Cards)
     | ------------------------------------------------------------------ */
    'section' => [
        'content_title'    => 'Ficheiro e descrição',
        'content_desc'     => 'Anexe o documento e insira os principais metadados.',
        'settings_title'   => 'Classificação',
        'settings_desc'    => 'Organize o documento no arquivo selecionando uma categoria e defina a sua visibilidade.',
        'recipients_title' => 'Destinatários do documento',
        'recipients_desc'  => 'Associe o ficheiro a condomínios e registos específicos.',
    ],

    /* ------------------------------------------------------------------
     | View Details (Sidebar)
     | ------------------------------------------------------------------ */
    'details' => [
        'card_title'        => 'Detalhes de classificação',
        'current_status'    => 'Estado do Ficheiro',
        'visibility_status' => 'Estado de Visibilidade',
        'published'         => 'Público',
        'draft'             => 'Privado (Apenas Admin)',
    ],

    /* ------------------------------------------------------------------
     | Table
     | ------------------------------------------------------------------ */
    'table' => [
        'name'                  => 'Nome do documento',
        'category'              => 'Categoria',
        'buildings'             => 'Condomínios',
        'residents'             => 'Registos',
        'status'                => 'Estado',
        'filter_by'             => 'Filtrar por nome...',
        'approved_tooltip'      => 'Aprovado - clique para remover aprovação',
        'unapproved_tooltip'    => 'Não aprovado - clique para aprovar',
        'no_results'            => 'Nenhum resultado encontrado.',
        'actions'               => 'Ações',
        'selected'              => 'selecionados',
        'loading'               => 'A carregar...',
        'clear_all_filters'     => 'Limpar todos os filtros',
        'sort_asc'              => 'Ascendente',
        'sort_desc'             => 'Descendente',
        'categories' => [
            'name'        => 'Nome da categoria',
            'description' => 'Descrição da categoria',
            'filter_by'   => 'Filtrar por nome...',
            'no_results'  => 'Nenhum resultado encontrado.',
            'actions'     => 'Ações',
        ],
    ],

    /* ------------------------------------------------------------------
     | Labels
     | ------------------------------------------------------------------ */
    'label' => [
        'name'                          => 'Nome do documento',
        'description'                   => 'Descrição do documento',
        'category'                      => 'Categoria',
        'buildings'                     => 'Condomínios',
        'residents'                     => 'Registos',
        'visibility'                    => 'Visibilidade do documento',
        'select_document'               => 'Selecionar documento',
        'replace_document'              => 'Substituir ficheiro',
        'remove_document'               => 'Remover ficheiro',
        'replace_existing_document'     => 'Este ficheiro substituirá o existente.',
        'document'                      => 'Documento',
        'document_info'                 => 'Informações',
        'created'                       => 'Criado em:',
        'status'                        => 'Estado do ficheiro:',
        'missing'                       => 'Em falta',
        'existing'                      => 'Presente',
        'categories' => [
            'category_name'        => 'Nome',
            'category_description' => 'Descrição',
        ],
    ],

    /* ------------------------------------------------------------------
     | Placeholders
     | ------------------------------------------------------------------ */
    'placeholder' => [
        'name'        => 'Inserir nome do documento',
        'description' => 'Inserir descrição do documento',
        'category'    => 'Selecionar categoria',
        'visibility'  => 'Selecionar visibilidade do documento',
        'buildings'   => 'Selecionar condomínios',
        'residents'   => 'Selecionar registos',
        'categories'  => [
            'category_name'        => 'Nome da categoria',
            'category_description' => 'Descrição da categoria',
        ],
    ],

    /* ------------------------------------------------------------------
     | Dialogs
     | ------------------------------------------------------------------ */
    'dialogs' => [
        'no_documents_created'          => 'Ainda não foi criado nenhum documento no arquivo.',
        'delete_document_title'         => 'Tem a certeza de que pretende eliminar este documento?',
        'delete_document_description'   => 'Esta ação é irreversível. Eliminará o documento e todos os dados associados.',
        'select_document_title'         => 'Arraste o seu documento aqui',
        'select_document_description'   => 'Ou clique para o selecionar do seu dispositivo.',
        'document_supported_types'      => 'Apenas é permitido o formato PDF.',
        'categories' => [
            'delete_category_title'       => 'Tem a certeza de que pretende eliminar esta categoria?',
            'delete_category_description' => 'Esta ação é irreversível. Eliminará a categoria e todos os documentos associados.',
        ],
    ],

    /* ------------------------------------------------------------------
     | Toast
     | ------------------------------------------------------------------ */
    'toast' => [
        'success_title'   => 'Sucesso',
        'success_message' => 'Categoria criada com sucesso.',
        'error_title'     => 'Erro',
        'error_message'   => 'Não foi possível criar a categoria. Tente novamente mais tarde.',
    ],

    /* ------------------------------------------------------------------
     | Stats
     | ------------------------------------------------------------------ */
    'stats' => [
        'total_storage_bytes'  => 'Espaço total utilizado',
        'total_documents'      => 'Documentos totais',
        'uploaded_this_month'  => 'Carregados este mês',
        'average_size_bytes'   => 'Tamanho médio',
    ],

    /* ------------------------------------------------------------------
     | Visibility
     | ------------------------------------------------------------------ */
    'visibility' => [
        'public'                => 'Público',
        'private'               => 'Privado',
        'created_on'            => 'Criado em',
        'sent_on_by'            => 'Enviado :date por :name',
        'sent_on_by_category'   => 'Enviado :date por :name em :category',
    ],

    /* ------------------------------------------------------------------
     | Tooltips
     | ------------------------------------------------------------------ */
    'tooltip' => [
        'visibility' => 'Se definida como privada, apenas os administradores poderão visualizar o documento.',
        'category'   => 'Selecione uma categoria para organizar melhor os documentos, ou crie uma nova.',
    ],

    /* ------------------------------------------------------------------
     | Actions
     | ------------------------------------------------------------------ */
    'actions' => [
        'new_document'       => 'Criar documento',
        'list_categories'    => 'Categorias',
        'edit_document'      => 'Editar',
        'delete_document'    => 'Eliminar',
        'save_document'      => 'Guardar',
        'list_documents'     => 'Lista',
        'cancel'             => 'Cancelar',
        'back'               => 'Voltar',
        'back_to_list'       => 'Voltar à lista',
        'show_more'          => 'Mostrar tudo',
        'show_less'          => 'Mostrar menos',
        'categories' => [
            'new_category'      => 'Criar categoria',
            'list_documents'    => 'Documentos',
            'save_category'     => 'Guardar',
            'edit_category'     => 'Editar',
            'delete_category'   => 'Eliminar',
            'back_to_documents' => 'Voltar aos documentos',
        ],
    ],

    /* ------------------------------------------------------------------
     | Default Categories
     | ------------------------------------------------------------------ */
    'categories' => [
        'bilanci'   => 'Balanços',
        'verbali'   => 'Atas',
        'avvisi'    => 'Avisos',
        'contratti' => 'Contratos',
    ],

    /* ------------------------------------------------------------------
     | User Dashboard (Frontend)
     | ------------------------------------------------------------------ */
    'user' => [
        'latest_documents_title'       => 'Últimos documentos carregados',
        'latest_documents_description' => 'Lista dos últimos documentos no arquivo.',
        'pdf_only'                     => 'Só são permitidos ficheiros PDF.',
        'selected_file'                => 'Ficheiro selecionado',
        'private_document_label'       => 'Criar documento privado',
        'private_document_title'       => 'Criar documento privado',
        'private_document_description' => 'Quando esta opção é selecionada, o documento fica privado e visível apenas para administradores.',
    ],

    /* ------------------------------------------------------------------
     | User Document List
     | ------------------------------------------------------------------ */
    'user_list' => [
        'category_title'             => 'Documentos: :category',
        'category_description'       => 'Gestão dos documentos digitais relativos a esta categoria do condomínio.',
        'search_placeholder'         => 'Pesquisar por título...',
        'loading'                    => 'A atualizar...',
        'load_error'                 => 'Erro de carregamento.',
        'try_again'                  => 'Tentar novamente',
        'no_results_title'           => 'Nenhum resultado encontrado',
        'no_results_description'     => 'Tente ajustar os termos da pesquisa.',
        'empty_category_title'       => 'Categoria vazia',
        'empty_category_description' => 'Ainda não foram carregados documentos nesta categoria.',
        'clear_search'               => 'Limpar pesquisa',
        'upload_document'            => 'Carregar documento',
    ],

    /* ------------------------------------------------------------------
     | Breadcrumbs
     | ------------------------------------------------------------------ */
    'breadcrumbs' => [
        'list' => 'Documentos',
        'new'  => 'Novo documento',
        'edit' => 'Editar documento',
        'view' => 'Detalhe do documento',
    ],

    /* ------------------------------------------------------------------
     | Guides
     | ------------------------------------------------------------------ */
    'guides' => [
        'storage_title'             => 'Arquivo Centralizado',
        'storage_desc'              => 'Guarde faturas, contratos e atas num único espaço cloud sempre acessível.',
        'organization_title'        => 'Organização Rápida',
        'organization_desc'         => 'Utilize pastas e etiquetas para encontrar instantaneamente documentos importantes durante as assembleias.',
        'privacy_title'             => 'Privacidade e Permissões',
        'privacy_desc'              => 'Faça a gestão de quem pode visualizar os documentos definindo níveis de visibilidade públicos ou privados.',
        'upload_title'              => 'Carregamento',
        'upload_desc'               => 'Anexe o ficheiro e defina nome e descrição para identificá-lo rapidamente no arquivo.',
        'category_title'            => 'Classificação',
        'category_desc'             => 'Atribua uma categoria organizacional e estabeleça o nível de visibilidade do documento.',
        'audience_title'            => 'Destinatários',
        'audience_desc'             => 'Associe o ficheiro a condomínios e registos específicos para torná-lo visível apenas aos interessados.',
        'categories_org_title'      => 'Organização',
        'categories_org_desc'       => 'Use categorias para criar pastas digitais (ex: "Faturas", "Atas", "Contratos").',
        'categories_assoc_title'    => 'Associação',
        'categories_assoc_desc'     => 'Cada documento que carregar no arquivo poderá ser atribuído a uma destas categorias.',
        'categories_search_title'   => 'Pesquisa Rápida',
        'categories_search_desc'    => 'Filtrar o arquivo por categoria permite encontrar instantaneamente os ficheiros durante as assembleias.',
    ]
];