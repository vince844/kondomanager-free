<?php

return [
    /* ------------------------------------------------------------------
     | Backend notifications
     | ------------------------------------------------------------------ */
    'success_create_communication'               => "A nova comunicação foi criada com sucesso.",
    'success_create_communication_in_moderation' => "A nova comunicação foi criada com sucesso, mas necessita de aprovação pelo administrador.",
    'error_create_communication'                 => "Ocorreu um erro durante a criação da comunicação.",
    'success_update_communication'               => "A comunicação foi atualizada com sucesso.",
    'error_update_communication'                 => "Ocorreu um erro durante a atualização da comunicação.",
    'success_delete_communication'               => "A comunicação foi eliminada com sucesso.",
    'error_delete_communication'                 => "Ocorreu um erro durante a eliminação da comunicação.",
    'success_approve_communication'              => "A comunicação foi aprovada com sucesso.",
    'success_disapprove_communication'           => "A comunicação foi desaprovada com sucesso.",
    'error_approve_communication'                => "Ocorreu um erro durante a aprovação da comunicação.",
    'error_notify_new_communication'             => "A comunicação foi criada, mas ocorreu um erro no envio da notificação.",
    'error_notify_updated_communication'          => "A comunicação foi atualizada, mas ocorreu um erro no envio da notificação.",
    'error_notify_approved_communication'        => "A comunicação foi aprovada, mas ocorreu um erro no envio da notificação.",

    /* ------------------------------------------------------------------
     | Front-end strings (headings, titles, descriptions)
     | ------------------------------------------------------------------ */
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
        'view_communication_title'          => "Detalhe da comunicação",
        'view_communication_description'    => "Visualize o conteúdo e as informações de entrega da mensagem.",
        'widget_communications_title'       => "Comunicações recentes registadas",
        'widget_communications_description' => "Lista das últimas comunicações publicadas no quadro",
    ],

    /* ------------------------------------------------------------------
     | Form Sections (Cards)
     | ------------------------------------------------------------------ */
    'section' => [
        'content_title'    => "Conteúdo da comunicação",
        'content_desc'     => "Detalhes principais da mensagem.",
        'recipients_title' => "Destinatários",
        'recipients_desc'  => "Selecione os condomínios e os registos para os quais enviar a comunicação.",
        'settings_title'   => "Definições de publicação",
        'settings_desc'    => "Gira o estado, a prioridade e as permissões do comunicado.",
    ],

    /* ------------------------------------------------------------------
     | Dialogs
     | ------------------------------------------------------------------ */
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

    /* ------------------------------------------------------------------
     | Table
     | ------------------------------------------------------------------ */
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
        'sort_asc'              => 'Ascendente',
        'sort_desc'             => 'Descendente',
        'approved_tooltip'      => 'Aprovada - clique para remover aprovação',
        'unapproved_tooltip'    => 'Não aprovada - clique para aprovar',
        'actions'               => 'Ações',
    ],

    /* ------------------------------------------------------------------
     | Stats
     | ------------------------------------------------------------------ */
    'stats' => [
        'low_priority'      => "Prioridade baixa",
        'medium_priority'   => "Prioridade média",
        'high_priority'     => "Prioridade alta",
        'urgent_priority'   => "Prioridade urgente",
        'open_tickets'      => "Ocorrências abertas",    
    ],

    /* ------------------------------------------------------------------
     | Labels
     | ------------------------------------------------------------------ */
    'label' => [
        'subject'           => 'Assunto da comunicação',
        'description'       => 'Descrição da comunicação',
        'visibility'        => 'Visibilidade da comunicação',
        'priority'          => 'Prioridade da comunicação',
        'buildings'         => 'Condomínios',
        'residents'         => 'Registos',
        'comments'          => 'Permitir comentários',
        'featured'          => 'Comunicação em destaque',
        'notify_update'     => 'Avisar por email quem já recebeu esta comunicação',
        'private'           => 'Criar comunicação como privada',
        'administrator'     => 'Administrador',
        'none'              => 'Nenhuma',
        'published'         => 'Publicado no quadro',
        'draft_hidden'      => 'Rascunho / Oculto',
        'interactions'      => 'Interações',
        'comments_enabled'  => 'Comentários ativados',
        'comments_disabled' => 'Comentários desativados',
    ],

    /* ------------------------------------------------------------------
     | Placeholders
     | ------------------------------------------------------------------ */
    'placeholder' => [
        'subject'       => 'Inserir assunto da comunicação',
        'description'   => 'Inserir descrição da comunicação',
        'visibility'    => 'Selecionar visibilidade',
        'priority'      => 'Selecionar prioridade',
        'buildings'     => 'Selecionar condomínios',
        'residents'     => 'Selecionar registos',
    ],

    /* ------------------------------------------------------------------
     | Priority
     | ------------------------------------------------------------------ */
    'priority' => [
        'low'       => 'Baixa',
        'medium'    => 'Média',
        'high'      => 'Alta',
        'urgent'    => 'Urgente',
    ],

    /* ------------------------------------------------------------------
     | Visibility
     | ------------------------------------------------------------------ */
    'visibility' => [
        'public'        => 'Pública',
        'private'       => 'Privada',
        'created_on'    => 'Criada em',
        'sent_on_by'    => 'Enviada :date por :name',
    ],

    /* ------------------------------------------------------------------
     | Actions
     | ------------------------------------------------------------------ */
    'actions' => [
        'new_communication'         => 'Criar aviso',
        'edit_communication'        => 'Editar',
        'delete_communication'      => 'Eliminar',
        'save_communication'        => 'Guardar',
        'list_communications'       => 'Lista',
        'show_more'                 => 'Mostrar tudo',
        'show_less'                 => 'Mostrar menos',
        'view_all_communications'   => 'Visualizar todas',
        'cancel'                    => 'Cancelar',
        'back'                      => 'Voltar',
        'back_to_list'              => 'Voltar à lista',
    ],

    /* ------------------------------------------------------------------
     | Tooltips
     | ------------------------------------------------------------------ */
    'tooltip' => [
        'visibility'    => 'Se definida como privada, apenas os administradores poderão visualizar a comunicação.',
        'priority'      => 'Selecione o nível de prioridade com que esta comunicação deve ser tratada. As prioridades podem influenciar a visibilidade ou a urgência no quadro.',
        'comments'      => 'Quando esta opção é selecionada, os comentários serão ativados para esta comunicação.',
        'featured'      => 'As comunicações em destaque são realçadas no quadro para atrair maior atenção.',
        'notify_update' => "Envia um email a quem já era destinatário, a dizer que a comunicação mudou. Deixe-a desligada se estiver a corrigir uma gralha: quem for adicionado agora recebe-a de qualquer forma, porque para ele é nova.",
        'private'       => 'As comunicações privadas podem ser visualizadas apenas pelos administradores e por si.',
    ],

    /* ------------------------------------------------------------------
     | Breadcrumbs
     | ------------------------------------------------------------------ */
    'breadcrumbs' => [
        'list' => 'Comunicações',
        'new'  => 'Nova comunicação',
        'edit' => 'Editar comunicação',
        'view' => 'Detalhe da comunicação',
    ],

    /* ------------------------------------------------------------------
     | Guides
     | ------------------------------------------------------------------ */
    'guides' => [
        'board_title'      => 'Quadro centralizado',
        'board_desc'       => 'Acompanhe todas as comunicações publicadas e mantenha o histórico organizado por prioridade e estado.',
        'target_title'     => 'Segmentação',
        'target_desc'      => 'Defina condomínios e registos alvo para garantir que cada comunicação chega ao público certo.',
        'delivery_title'   => 'Entrega e aprovação',
        'delivery_desc'    => 'Controle o fluxo de aprovação e publicação para manter a comunicação consistente e segura.',
        'message_title'    => 'Redação da Mensagem',
        'message_desc'     => 'Redija o conteúdo da comunicação definindo um assunto claro e um texto explicativo completo. Certifique-se de que as informações estão estruturadas para garantir a máxima legibilidade.',
        'audience_title'   => 'Seleção de Destinatários',
        'audience_desc'    => 'Identifique com precisão o alvo da sua comunicação. Pode enviar a mensagem para condomínios inteiros ou filtrar registos específicos para comunicações direcionadas e estritamente confidenciais.',
        'priority_title'   => 'Gestão de Prioridades',
        'priority_desc'    => 'Atribua níveis de urgência para destacar as mensagens mais importantes no quadro, decida se pretende destacar o comunicado e gira as permissões de interação ativando ou desativando comentários.',
        'tracking_title'   => 'Rastreio de Envios',
        'tracking_desc'    => 'Monitorize o estado de entrega e leitura de cada comunicação enviada aos residentes.',
        'history_title'    => 'Histórico Completo',
        'history_desc'     => 'Consulte o arquivo de todas as comunicações anteriores com os respetivos anexos e comentários.',
        'visibility_title' => 'Visibilidade',
        'visibility_desc'  => 'Verifique o estado de publicação e a quem se destina a mensagem.',
    ]
];