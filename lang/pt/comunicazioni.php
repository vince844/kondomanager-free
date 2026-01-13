<?php

return [
    'success_create_communication'               => "A nova comunicação foi criada com sucesso.",
    'success_create_communication_in_moderation' => "A nova comunicação foi criada com sucesso, mas necessita de aprovação pelo administrador.",
    'error_create_communication'                 => "Ocorreu um erro durante a criação da comunicação.",
    'success_update_communication'               => "A comunicação foi atualizada com sucesso.",
    'error_update_communication'                 => "Ocorreu um erro durante a atualização da comunicação.",
    'success_delete_communication'               => "A comunicação foi eliminada com sucesso.",
    'error_delete_communication'                 => "Ocorreu um erro durante a eliminação da comunicação.",
    'success_approve_communication'              => "A comunicação foi aprovada com sucesso.",
    'success_disapprove_communication'           => "A comunicação foi desaprovada com successo.",
    'error_approve_communication'                => "Ocorreu um erro durante a aprovação da comunicação.",
    'error_notify_new_communication'             => "A comunicação foi criada, mas ocorreu um erro no envio da notificação.",
    'error_notify_approved_communication'        => "A comunicação foi aprovada, mas ocorreu um erro no envio da notificação.",

    // Frontend
    'header' => [
        'list_communications_head'          => "Lista de comunicações do quadro",
        'list_communications_title'         => "Lista de comunicações do quadro",
        'list_communications_description'   => "A seguir a tabela com a lista de todas as comunicações guardadas no quadro do condomínio",
        'new_communication_head'            => "Criar nova comunicação",
        'new_communication_title'           => "Criar nova comunicação",
        'new_communication_description'     => "Preencha o seguinte formulário para criar uma nova comunicação para o quadro do condomínio",
        'edit_communication_head'           => "Editar comunicação",
        'edit_communication_title'          => "Editar comunicação",
        'edit_communication_description'    => "Preencha o seguinte formulário para editar a comunicação do quadro do condomínio",
        'view_communication_head'           => "Visualizar comunicação",
        'widget_communications_title'       => "Comunicações recentes registadas",
        'widget_communications_description' => "Lista das últimas comunicações publicadas no quadro",
    ],

    'dialogs' => [
        'delete_communication_title'        => "Tem a certeza de que pretende eliminar esta comunicação?",
        'delete_communication_description'  => "Esta ação é irreversível. Eliminará a comunicação e todos os dados a ela associados.",
        'no_communications'                 => "Nenhuma comunicação",
        'no_communications_created'         => "Ainda não foi criada nenhuma comunicação",
        'no_view_permission'                => "Não possui permissões suficientes para visualizar as comunicações!",
        'no_communications_found'           => "Nenhuma comunicação encontrada.",
        'change_search_criteria'            => "Altere os critérios de pesquisa e tente novamente.",
        'cancel_search'                     => "Cancelar pesquisa",
        'loading_error'                     => "Ocorreu um erro durante o carregamento das comunicações. Tente novamente mais tarde.",
        'loading'                           => "A carregar...",
        'try_again'                         => "Tentar novamente",
    ],

    'table' => [
        'priority'              => 'Prioridade',
        'status'                => 'Estado',
        'filter_by_title'       => 'Filtrar por título...',
        'title'                 => 'Título',
        'buildings'             => 'Condomínios',
        'residents'             => 'Registos',          
        'selected'              => 'Selecionados',
        'loading'               => 'A carregar...',
        'no_results'            => 'Nenhum resultado encontrado.',
        'clear_all_filters'     => 'Limpar todos os filtros',
        'approved_tooltip'      => 'Aprovada - clique para remover aprovação',
        'unapproved_tooltip'    => 'Não aprovada - clique para aprovar',
        'actions'               => 'Ações',
    ],

    'stats' => [
        'low_priority'      => "Prioridade baixa",
        'medium_priority'   => "Prioridade média",
        'high_priority'     => "Prioridade alta",
        'urgent_priority'   => "Prioridade urgente",
        'open_tickets'      => "Ocorrências abertas",    
    ],

    'label' => [
        'subject'       => 'Assunto da comunicação',
        'description'   => 'Descrição da comunicação',
        'visibility'    => 'Visibilidade da comunicação',
        'priority'      => 'Prioridade da comunicação',
        'buildings'     => 'Condomínios',
        'residents'     => 'Registos',
        'comments'      => 'Permitir comentários',
        'featured'      => 'Comunicação em destaque',
        'private'       => 'Criar comunicação como privada',
    ],

    'placeholder' => [
        'subject'       => 'Inserir assunto da comunicação',
        'description'   => 'Inserir descrição da comunicação',
        'visibility'    => 'Selecionar visibilidade',
        'priority'      => 'Selecionar prioridade',
        'buildings'     => 'Selecionar condomínios',
        'residents'     => 'Selecionar registos',
    ],

    'priority' => [
        'low'       => 'Baixa',
        'medium'    => 'Média',
        'high'      => 'Alta',
        'urgent'    => 'Urgente',
    ],

    'visibility' => [
        'public'        => 'Pública',
        'private'       => 'Privada',
        'created_on'    => 'Criada em',
        'sent_on_by'    => 'Enviada :date por :name',
    ],

    'actions' => [
        'new_communication'         => 'Criar',
        'edit_communication'        => 'Editar',
        'delete_communication'      => 'Eliminar',
        'save_communication'        => 'Guardar',
        'list_communications'       => 'Lista',
        'show_more'                 => 'Mostrar tudo',
        'show_less'                 => 'Mostrar menos',
        'view_all_communications'   => 'Visualizar todas',
    ],

    'tooltip' => [
        'visibility'    => 'Se definida como privada, apenas os administradores poderão visualizar a comunicação.',
        'priority'      => 'Selecione o nível de prioridade com que esta comunicação deve ser tratada. As prioridades podem influenciar a visibilidade ou a urgência no quadro.',
        'comments'      => 'Quando esta opção é selecionada, os comentários serão ativados para esta comunicação.',
        'featured'      => 'As comunicações em destaque são realçadas no quadro para atrair maior atenção.',
        'private'       => 'As comunicações privadas podem ser visualizadas apenas pelos administradores e por si.',
    ]
];