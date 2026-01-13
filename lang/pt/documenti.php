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
     | Front‑end strings (headings, titles, descriptions)
     | ------------------------------------------------------------------ */
    'header' => [
        'list_documents_head'        => 'Lista de arquivo de documentos',
        'list_documents_title'       => 'Lista de arquivo de documentos',
        'list_documents_description' => 'A seguir a tabela com a lista de todos os documentos guardados no arquivo do condomínio',
        'new_document_head'          => 'Criar novo documento',
        'new_document_title'         => 'Criar documento no arquivo',
        'new_document_description'   => 'Preencha o seguinte formulário para criar um novo documento para o arquivo do condomínio',
        'edit_document_head'         => 'Editar documento',
        'edit_document_title'        => 'Editar documento do arquivo',
        'edit_document_description'  => 'Preencha o seguinte formulário para editar o documento no arquivo do condomínio',
        'list_categories_head'       => 'Categorias do arquivo',
        'list_categories_title'      => 'Lista de categorias do arquivo de documentos',
        'list_categories_description' => 'A seguir a tabela com a lista de todas as categorias de documentos no arquivo do condomínio',
        'categories' => [
            'new_category_title'       => 'Criar nova categoria',
            'new_category_description' => 'Adicione uma nova categoria para os documentos',
            'edit_category_title'      => 'Editar categoria: :category',
            'edit_category_description' => 'A seguir pode modificar os detalhes da categoria',
        ],
    ],

    /* ------------------------------------------------------------------
     | Table
     | ------------------------------------------------------------------ */
    'table' => [
        'name'              => 'Nome do documento',
        'category'          => 'Categoria',
        'buildings'         => 'Condomínios',
        'residents'         => 'Registos',
        'status'            => 'Estado',
        'filter_by'         => 'Filtrar por nome...',
        'approved_tooltip'  => 'Aprovado - clique para remover aprovação',
        'unapproved_tooltip' => 'Não aprovado - clique para aprovar',
        'no_results'        => 'Nenhum resultado encontrado.',
        'actions'           => 'Ações',
        'selected'          => 'selecionados',
        'loading'           => 'A carregar...',
        'clear_all_filters' => 'Limpar todos os filtros',
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
        'name'                    => 'Nome do documento',
        'description'             => 'Descrição do documento',
        'category'                => 'Categoria',
        'buildings'               => 'Condomínios',
        'residents'               => 'Registos',
        'visibility'              => 'Visibilidade do documento',
        'select_document'         => 'Selecionar documento',
        'replace_document'        => 'Substituir ficheiro',
        'remove_document'         => 'Remover ficheiro',
        'replace_existing_document' => 'Este ficheiro substituirá o existente.',
        'document'                => 'Documento',
        'document_info'           => 'Informações',
        'created'                 => 'Criado em:',
        'status'                  => 'Estado do ficheiro:',
        'missing'                 => 'Em falta',
        'existing'                => 'Presente',
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
        'categories' => [
            'category_name'        => 'Nome da categoria',
            'category_description' => 'Descrição da categoria',
        ],
    ],

    /* ------------------------------------------------------------------
     | Dialogs
     | ------------------------------------------------------------------ */
    'dialogs' => [
        'no_documents_created'       => 'Ainda não foi criado nenhum documento no arquivo.',
        'delete_document_title'      => 'Tem a certeza de que pretende eliminar este documento?',
        'delete_document_description' => 'Esta ação é irreversível. Eliminará o documento e todos os dados associados.',
        'select_document_title'      => 'Arraste o seu documento aqui',
        'select_document_description' => 'Ou clique para o selecionar do seu dispositivo.',
        'document_supported_types'   => 'Apenas são permitidos os formatos PDF, JPEG, PNG.',
        'max_document_size'          => 'O ficheiro não pode exceder 20 MB',
        'categories' => [
            'delete_category_title'       => 'Tem a certeza de que pretende eliminar esta categoria?',
            'delete_category_description' => 'Esta ação é irreversível. Eliminará a categoria e todos os documentos associados.',
        ],
    ],

    /* ------------------------------------------------------------------
     | Stats
     | ------------------------------------------------------------------ */
    'stats' => [
        'total_storage_bytes' => 'Espaço total utilizado',
        'total_documents'     => 'Documentos totais',
        'uploaded_this_month' => 'Carregados este mês',
        'average_size_bytes'  => 'Tamanho médio do documento',
    ],

    /* ------------------------------------------------------------------
     | Visibility
     | ------------------------------------------------------------------ */
    'visibility' => [
        'public'     => 'Público',
        'private'    => 'Privado',
        'created_on' => 'Criado em',
        'sent_on_by' => 'Enviado :date por :name',
        'sent_on_by_category' => 'Enviado :date por :name em :category',
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
        'new_document'    => 'Criar',
        'list_categories' => 'Categorias',
        'edit_document'   => 'Editar',
        'delete_document' => 'Eliminar',
        'save_document'   => 'Guardar',
        'list_documents'  => 'Lista',
        'cancel'          => 'Cancelar',
        'show_more'       => 'Mostrar tudo',
        'show_less'       => 'Mostrar menos',
        'categories' => [
            'new_category'    => 'Criar',
            'list_documents'  => 'Documentos',
            'save_category'   => 'Guardar',
            'edit_category'   => 'Editar',
            'delete_category' => 'Eliminar',
        ],
    ],
];