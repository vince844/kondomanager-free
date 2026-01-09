<?php

return [
    // Backend notifications
    'success_create_ticket'                     => 'A nova sinalização de avaria foi criada com sucesso.',
    'success_create_ticket_in_moderation'       => 'A nova sinalização de avaria foi criada com sucesso, mas necessita de aprovação pelo administrador.',
    'error_create_ticket'                       => 'Ocorreu um erro durante a criação da sinalização de avaria.',
    'success_update_ticket'                     => 'A sinalização de avaria foi atualizada com sucesso.',
    'error_update_ticket'                       => 'Ocorreu um erro durante a atualização da sinalização de avaria.',
    'success_delete_ticket'                     => 'A sinalização de avaria foi eliminada com sucesso.',
    'error_delete_ticket'                       => 'Ocorreu um erro durante a eliminação da sinalização de avaria.',
    'success_approve_ticket'                    => 'A sinalização de avaria foi aprovada com sucesso.',
    'error_approve_ticket'                      => 'Ocorreu um erro durante a aprovação da sinalização de avaria.',
    'success_unapprove_ticket'                  => 'A sinalização de avaria foi colocada em moderação com sucesso.',
    'error_unapprove_ticket'                    => 'Ocorreu um erro ao colocar a sinalização de avaria em moderação.',
    'error_notify_approved_ticket'              => 'A sinalização de avaria foi aprovada, mas ocorreu um erro no envio da notificação.',
    'success_lock_ticket'                       => 'A sinalização de avaria foi bloqueada com sucesso.',
    'error_lock_ticket'                         => 'Ocorreu um erro ao bloquear a sinalização de avaria.',
    'success_unlock_ticket'                     => 'A sinalização de avaria foi desbloqueada com sucesso.',
    'error_unlock_ticket'                       => 'Ocorreu um erro ao desbloquear a sinalização de avaria.',

    // Frontend
    'header' => [
        'list_tickets_head'             => 'Lista de sinalizações de avaria',
        'list_tickets_title'            => 'Lista de sinalizações de avaria',
        'list_tickets_description'      => 'A seguir a tabela com a lista de todas as sinalizações de avaria registadas',
        'new_ticket_head'               => 'Criar sinalização de avaria',
        'new_ticket_title'              => 'Criar sinalização de avaria',
        'new_ticket_description'        => 'Preencha o seguinte formulário para criar uma nova sinalização de avaria',
        'view_ticket_head'              => 'Visualizar sinalização de avaria',
        'view_ticket_title'             => 'Visualizar sinalização de avaria',
        'view_ticket_description'       => 'Detalhes e estado da sinalização de avaria',
        'edit_ticket_head'              => 'Editar sinalização de avaria',
        'edit_ticket_title'             => 'Editar sinalização de avaria',
        'edit_ticket_description'       => 'Preencha o seguinte formulário para editar a sinalização de avaria',
        'widget_tickets_title'          => 'Sinalizações de avaria recentes',
        'widget_tickets_description'    => 'Lista das últimas sinalizações de avaria enviadas',
    ],

    'dialogs' => [
        'delete_ticket_title'       => 'Tem a certeza de que pretende eliminar esta sinalização?',
        'delete_ticket_description' => 'Esta ação é irreversível. Eliminará a sinalização e todos os dados a ela associados.',
        'no_tickets'                => 'Nenhuma sinalização de avaria',
        'no_tickets_created'        => 'Ainda não foi criada nenhuma sinalização de avaria',
        'no_view_permission'        => 'Não possui permissões suficientes para visualizar as sinalizações!',
        'no_tickets_found'          => 'Nenhuma sinalização de avaria encontrada.',
        'change_search_criteria'    => 'Altere os critérios de pesquisa e tente novamente.',
        'cancel_search'             => 'Cancelar pesquisa',
        'loading_error'             => 'Ocorreu um erro durante o carregamento das sinalizações de avaria. Tente novamente mais tarde.',
        'loading'                   => 'A carregar...',
        'try_again'                 => 'Tentar novamente',
    ],

    'stats' => [
        'low_priority'    => 'Prioridade baixa',
        'medium_priority' => 'Prioridade média',
        'high_priority'   => 'Prioridade alta',
        'urgent_priority' => 'Prioridade urgente',
        'open_tickets'    => 'Sinalizações abertas',
    ],

    'table' => [
        'priority'            => 'Prioridade',
        'status'              => 'Estado',
        'filter_by_title'     => 'Filtrar por título...',
        'title'               => 'Título',
        'buildings'           => 'Condomínios',
        'residents'           => 'Registos',
        'visibility'          => 'Visibilidade',
        'approved_tooltip'    => 'Aprovada - clique para remover aprovação',
        'unapproved_tooltip'  => 'Não aprovada - clique para aprovar',
        'clear_all_filters'   => 'Limpar todos os filtros',
        'loading'             => 'A carregar...',
        'no_results'          => 'Nenhum resultado encontrado.',
        'selected'            => 'Selecionados',
        'actions'             => 'Ações',
    ],

    'status' => [
        'open'         => 'Aberta',
        'in_progress'  => 'Em curso',
        'closed'       => 'Fechada',
    ],

    'priority' => [
        'low'    => 'Baixa',
        'medium' => 'Média',
        'high'   => 'Alta',
        'urgent' => 'Urgente',
    ],

    'visibility' => [
        'public'      => 'Pública',
        'private'     => 'Privada',
        'created_on'  => 'Criada em',
        'sent_on_by'  => 'Enviada :date por :name',
    ],

    'actions' => [
        'new_ticket'       => 'Criar',
        'edit_ticket'      => 'Editar',
        'delete_ticket'    => 'Eliminar',
        'save_ticket'      => 'Guardar',
        'list_tickets'     => 'Lista',
        'lock_ticket'      => 'Bloquear',
        'unlock_ticket'    => 'Desbloquear',
        'view_all_tickets' => 'Visualizar todas',
        'show_more'        => 'Mostrar tudo',
        'show_less'        => 'Mostrar menos',
    ],

    'label' => [
        'object'      => 'Assunto da sinalização',
        'description' => 'Descrição da sinalização',
        'visibility'  => 'Visibilidade da sinalização',
        'priority'    => 'Prioridade da sinalização',
        'status'      => 'Estado da sinalização',
        'building'    => 'Condomínio',
        'resident'    => 'Registo',
        'comments'    => 'Permitir comentários',
        'featured'    => 'Sinalização em destaque',
        'private'     => 'Criar sinalização como privada',
    ],

    'placeholder' => [
        'object'      => 'Inserir assunto da sinalização',
        'description' => 'Inserir descrição da sinalização',
        'visibility'  => 'Selecionar visibilidade',
        'priority'    => 'Selecionar prioridade',
        'status'      => 'Selecionar estado',
        'building'    => 'Selecionar condomínio',
        'resident'    => 'Selecionar registo',
    ],

    'tooltip' => [
        'visibility' => 'Se definida como privada, apenas os administradores poderão visualizar a sinalização.',
        'priority'   => 'Defina o nível de prioridade desta sinalização para ajudar os administradores a gerirem-na adequadamente.',
        'status'     => 'Defina o estado atual da sinalização para acompanhar o progresso.',
        'comments'   => 'Quando selecionada, permite que os utilizadores adicionem comentários a esta sinalização.',
        'featured'   => 'A sinalização em destaque será sempre mostrada no topo da lista.',
        'private'    => 'As sinalizações privadas podem ser visualizadas apenas pelos administradores e por si.',
    ],
];