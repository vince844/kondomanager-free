<?php

return [
    /* ------------------------------------------------------------------
     | Backend notifications
     | ------------------------------------------------------------------ */
    'success_create_ticket'               => "A nova sinalização de avaria foi criada com sucesso.",
    'success_create_ticket_in_moderation' => "A nova sinalização de avaria foi criada com sucesso, mas necessita de aprovação pelo administrador.",
    'error_create_ticket'                 => "Ocorreu um erro durante a criação da sinalização de avaria.",
    'success_update_ticket'               => "A sinalização de avaria foi atualizada com sucesso.",
    'error_update_ticket'                 => "Ocorreu um erro durante a atualização da sinalização de avaria.",
    'success_delete_ticket'               => "A sinalização de avaria foi eliminada com sucesso.",
    'error_delete_ticket'                 => "Ocorreu um erro durante a eliminação da sinalização de avaria.",
    'success_approve_ticket'              => "A sinalização de avaria foi aprovada com sucesso.",
    'error_approve_ticket'                => "Ocorreu um erro durante a aprovação da sinalização de avaria.",
    'success_unapprove_ticket'            => "A sinalização de avaria foi colocada em moderação com sucesso.",
    'error_unapprove_ticket'              => "Ocorreu um erro ao colocar a sinalização de avaria em moderação.",
    'error_notify_approved_ticket'        => "A sinalização de avaria foi aprovada, mas ocorreu um erro no envio da notificação.",
    'error_notify_updated_ticket' => "A ocorrência foi atualizada, mas ocorreu um erro no envio da notificação.",
    'success_lock_ticket'                 => "A sinalização de avaria foi bloqueada com sucesso.",
    'error_lock_ticket'                   => "Ocorreu um erro ao bloquear a sinalização de avaria.",
    'success_unlock_ticket'               => "A sinalização de avaria foi desbloqueada com sucesso.",
    'error_unlock_ticket'                 => "Ocorreu um erro ao desbloquear a sinalização de avaria.",

    /* ------------------------------------------------------------------
     | Front-end strings (headings, titles, descriptions)
     | ------------------------------------------------------------------ */
    'header' => [
        'list_tickets_head'          => "Lista de sinalizações de avaria",
        'list_tickets_title'         => "Lista de sinalizações de avaria",
        'list_tickets_description'   => "A seguir a tabela com a lista de todas as sinalizações de avaria registadas",
        'new_ticket_head'            => "Criar sinalização de avaria",
        'new_ticket_title'           => "Criar sinalização de avaria",
        'new_ticket_description'     => "Preencha o seguinte formulário para criar uma nova sinalização de avaria",
        'view_ticket_head'           => "Visualizar sinalização de avaria",
        'view_ticket_title'          => "Detalhe da sinalização",
        'view_ticket_description'    => "Detalhes e estado da sinalização de avaria",
        'edit_ticket_head'           => "Editar sinalização de avaria",
        'edit_ticket_title'          => "Editar sinalização de avaria",
        'edit_ticket_description'    => "Preencha o seguinte formulário para editar a sinalização de avaria",
        'widget_tickets_title'       => "Sinalizações de avaria recentes",
        'widget_tickets_description' => "Lista das últimas sinalizações de avaria enviadas",
    ],

    /* ------------------------------------------------------------------
     | Form Sections (Cards)
     | ------------------------------------------------------------------ */
    'section' => [
        'content_title'  => "Detalhes da sinalização",
        'content_desc'   => "Insira as informações relativas ao problema a assinalar.",
        'location_title' => "Destinatários",
        'location_desc'  => "Indique o condomínio e os registos ligados a esta sinalização.",
        'settings_title' => "Gestão operativa",
        'settings_desc'  => "Defina o estado de processamento, a prioridade e a visibilidade da sinalização.",
    ],

    /* ------------------------------------------------------------------
     | View Details (Sidebar)
     | ------------------------------------------------------------------ */
    'details' => [
        'card_title'        => "Detalhes operacionais",
        'priority_level'    => "Nível de Prioridade",
        'visibility_status' => "Estado de Visibilidade",
        'current_status'    => "Estado de Processamento",
        'published'         => "Publicada",
        'draft'             => "Rascunho / Oculta",
        'interactions'      => "Interações",
        'comments_enabled'  => "Comentários ativados",
        'comments_disabled' => "Comentários desativados",
        'admin_sender'      => "Administrador",
        'locked'            => 'Bloqueada',
        'unlocked'          => 'Aberta',
    ],

    /* ------------------------------------------------------------------
     | Dialogs
     | ------------------------------------------------------------------ */
    'dialogs' => [
        'delete_ticket_title'       => "Tem a certeza de que pretende eliminar esta sinalização?",
        'delete_ticket_description' => "Esta ação é irreversível. Eliminará a sinalização e todos os dados a ela associados.",
        'no_tickets'                => "Nenhuma sinalização de avaria",
        'no_tickets_created'        => "Ainda não foi criada nenhuma sinalização de avaria",
        'no_view_permission'        => "Não possui permissões suficientes para visualizar as sinalizações!",
        'no_tickets_found'          => "Nenhuma sinalização de avaria encontrada.",
        'change_search_criteria'    => "Altere os critérios de pesquisa e tente novamente.",
        'cancel_search'             => "Cancelar pesquisa",
        'loading_error'             => "Ocorreu um erro durante o carregamento das sinalizações de avaria. Tente novamente mais tarde.",
        'loading'                   => "A carregar...",
        'try_again'                 => "Tentar novamente",
    ],

    /* ------------------------------------------------------------------
     | Stats
     | ------------------------------------------------------------------ */
    'stats' => [
        'low_priority'    => "Prioridade baixa",
        'medium_priority' => "Prioridade média",
        'high_priority'   => "Prioridade alta",
        'urgent_priority' => "Prioridade urgente",
        'open_tickets'    => "Sinalizações abertas",
    ],

    /* ------------------------------------------------------------------
     | Table
     | ------------------------------------------------------------------ */
    'table' => [
        'priority'           => 'Prioridade',
        'status'             => 'Estado',
        'filter_by_title'    => 'Filtrar por título...',
        'title'              => 'Título',
        'buildings'          => 'Condomínios',
        'residents'          => 'Registos',
        'visibility'         => 'Visibilidade',
        'approved_tooltip'   => 'Aprovada - clique para remover aprovação',
        'unapproved_tooltip' => 'Não aprovada - clique para aprovar',
        'clear_all_filters'  => 'Limpar todos os filtros',
        'sort_asc'           => 'Ascendente',
        'sort_desc'          => 'Descendente',
        'loading'            => 'A carregar...',
        'no_results'         => 'Nenhum resultado encontrado.',
        'selected'           => 'Selecionados',
        'actions'            => 'Ações',
    ],

    /* ------------------------------------------------------------------
     | Status
     | ------------------------------------------------------------------ */
    'status' => [
        'open'        => 'Aberta',
        'in_progress' => 'Em curso',
        'closed'      => 'Fechada',
    ],

    /* ------------------------------------------------------------------
     | Priority
     | ------------------------------------------------------------------ */
    'priority' => [
        'low'    => 'Baixa',
        'medium' => 'Média',
        'high'   => 'Alta',
        'urgent' => 'Urgente',
    ],

    /* ------------------------------------------------------------------
     | Visibility
     | ------------------------------------------------------------------ */
    'visibility' => [
        'public'     => 'Pública',
        'private'    => 'Privada',
        'created_on' => 'Criada em',
        'sent_on_by' => 'Enviada :date por :name',
    ],

    /* ------------------------------------------------------------------
     | Actions
     | ------------------------------------------------------------------ */
    'actions' => [
        'new_ticket'       => 'Criar sinalização',
        'edit_ticket'      => 'Editar',
        'delete_ticket'    => 'Eliminar',
        'save_ticket'      => 'Guardar',
        'list_tickets'     => 'Lista',
        'lock_ticket'      => 'Bloquear',
        'unlock_ticket'    => 'Desbloquear',
        'view_all_tickets' => 'Visualizar todas',
        'show_more'        => 'Mostrar tudo',
        'show_less'        => 'Mostrar menos',
        'cancel'           => 'Cancelar',
        'back'             => 'Voltar',
        'back_to_list'     => "Voltar à lista",
    ],

    /* ------------------------------------------------------------------
     | Labels
     | ------------------------------------------------------------------ */
    'label' => [
        'notify_update' => "Avisar por email quem acompanha esta ocorrência",
        'object'             => 'Assunto da sinalização',
        'description'        => 'Descrição da sinalização',
        'visibility'         => 'Visibilidade da sinalização',
        'priority'           => 'Prioridade da sinalização',
        'status'             => 'Estado da sinalização',
        'publication_status' => 'Estado da publicação',
        'published'          => 'Publicada',
        'draft'              => 'Rascunho',
        'building'           => 'Condomínio',
        'resident'           => 'Registo',
        'comments'           => 'Permitir comentários',
        'featured'           => 'Sinalização em destaque',
        'private'            => 'Criar sinalização como privada',
        'no_priority'        => 'Nenhuma',
        'no_status'          => 'Nenhum',
    ],

    /* ------------------------------------------------------------------
     | Placeholders
     | ------------------------------------------------------------------ */
    'placeholder' => [
        'object'      => 'Inserir assunto da sinalização',
        'description' => 'Inserir descrição da sinalização',
        'visibility'  => 'Selecionar visibilidade',
        'priority'    => 'Selecionar prioridade',
        'status'      => 'Selecionar estado',
        'building'    => 'Selecionar condomínio',
        'resident'    => 'Selecionar registo',
    ],

    /* ------------------------------------------------------------------
     | Tooltips
     | ------------------------------------------------------------------ */
    'tooltip' => [
        'notify_update' => "Envia um email a quem já era destinatário, a dizer que a ocorrência mudou. Deixe-a desligada se estiver a corrigir uma gralha: quem for adicionado agora recebe-a de qualquer forma, porque para ele é nova. Enquanto for um rascunho ou estiver a aguardar aprovação não é enviado nada; quando estiver publicada e aprovada, recebem-na todos os destinatários.",
        'visibility' => 'Um rascunho, ou uma sinalização a aguardar aprovação, não aparece aos condóminos; depois de publicada e aprovada, veem-na os condóminos a quem se dirige.',
        'priority'   => 'Defina o nível de prioridade desta sinalização para ajudar os administradores a gerirem-na adequadamente.',
        'status'     => 'Defina o estado atual da sinalização para acompanhar o progresso.',
        'comments'   => 'Quando selecionada, permite que os utilizadores adicionem comentários a esta sinalização.',
        'featured'   => 'A sinalização em destaque será sempre mostrada no topo da lista.',
        'private'    => 'As sinalizações privadas podem ser visualizadas apenas pelos administradores e por si.',
    ],

    /* ------------------------------------------------------------------
     | Breadcrumbs
     | ------------------------------------------------------------------ */
    'breadcrumbs' => [
        'list' => 'Sinalizações',
        'new'  => 'Nova sinalização',
        'edit' => 'Editar sinalização',
        'view' => 'Detalhe da sinalização',
    ],

    /* ------------------------------------------------------------------
     | Guides
     | ------------------------------------------------------------------ */
    'guides' => [
        'reports_title'    => 'Registo centralizado',
        'reports_desc'     => 'Visualize todas as sinalizações de avaria num único local, com estado e prioridade sempre atualizados.',
        'workflow_title'   => 'Fluxo operacional',
        'workflow_desc'    => 'Acompanhe o ciclo completo da avaria, desde a abertura até à resolução e encerramento.',
        'control_title'    => 'Controlo e prioridade',
        'control_desc'     => 'Priorize ocorrências críticas e mantenha a gestão técnica com critérios claros e rastreáveis.',
        'management_title' => 'Gestão de Avarias',
        'management_desc'  => 'Recolha e centralize as sinalizações para uma transição suave para futuras intervenções de manutenção.',
        'priority_title'   => 'Filtro de Urgências',
        'priority_desc'    => 'Identifique rapidamente emergências críticas (canos rotos, perigos) e separe-as da manutenção rotineira.',
        'resolution_title' => 'Estado das Intervenções',
        'resolution_desc'  => 'Acompanhe o progresso da resolução para manter os residentes sempre informados sobre os trabalhos.',
        'issue_title'      => 'Detalhes da Avaria',
        'issue_desc'       => 'Forneça uma descrição clara e completa do problema encontrado para facilitar a futura classificação da intervenção.',
        'location_title'   => 'Contexto e Envolvidos',
        'location_desc'    => 'Indique com precisão o condomínio afetado e associe os registos dos residentes para facilitar as comunicações e as inspeções.',
        'settings_title'   => 'Parâmetros Operativos',
        'settings_desc'    => 'Defina o nível de urgência, atualize o estado de processamento da ocorrência e ajuste as permissões de visibilidade no quadro.',
    ],

    /* ------------------------------------------------------------------
     | Pubblicazione e privacy (Code 304 e 306)
     | ------------------------------------------------------------------ */
    'publication' => [
        'pending'   => 'A aguardar aprovação',
        'draft'     => 'Rascunho',
        'published' => 'Publicada',
        'needs_approval' => 'Está a aguardar aprovação: escolher «Publicada» não basta para a mostrar aos condóminos. Vão vê-la quando estiver aprovada, com o escudo ao lado do título na lista de sinalizações.',
        'waiting_approver' => 'Está a aguardar aprovação: escolher «Publicada» não basta para a mostrar aos condóminos. Vão vê-la quando for aprovada por quem aprova as sinalizações.',
    ],

    'privacy' => [
        'private'                 => 'Privada',
        'private_hint'            => 'Visível apenas para o autor, as pessoas associadas e o administrador',
        'guide_title'             => 'Quem a vê',
        'guide_private'           => 'Esta sinalização é privada: só a veem quem a abriu, as pessoas a quem se dirige e o administrador.',
        'guide_not_private'       => 'Esta sinalização não é privada: veem-na os condóminos a quem se dirige (todo o condomínio, se não se dirigir a ninguém em particular) e o administrador.',
        'guide_private_pending'   => 'Esta sinalização é privada. Por agora mais ninguém a vê, além do administrador: as pessoas a quem se dirige vão vê-la quando estiver publicada e aprovada.',
        'guide_not_private_pending' => 'Esta sinalização não é privada. Por agora mais ninguém a vê, além do administrador: os condóminos a quem se dirige (todo o condomínio, se não se dirigir a ninguém em particular) vão vê-la quando estiver publicada e aprovada.',
        'make_public'             => 'Tornar pública',
        'make_public_title'       => 'Tornar pública a sinalização?',
        'make_public_description' => 'Todos os condóminos de :condominio a vão ver, com o nome de :autore, e quem não a via vai receber o aviso de uma nova sinalização. :autore vai receber um aviso. Não existe um comando para a tornar novamente privada.',
        'make_public_description_pending' => 'Todos os condóminos de :condominio a vão ver, com o nome de :autore, quando estiver publicada e aprovada: agora não está. :autore vai receber um aviso. Não existe um comando para a tornar novamente privada.',
        'make_public_author_loses_access' => 'Depois, :autore deixará de a ver no portal: não consta entre os condóminos de :condominio.',
        'make_public_confirm'     => 'Tornar pública',
        'already_public'          => 'A sinalização já é pública.',
        'success_make_public'     => 'A sinalização agora é pública.',
        'success_make_public_pending' => 'A sinalização já não é privada: todos os condóminos a vão ver quando estiver publicada e aprovada.',
        'error_make_public'       => 'Não foi possível tornar pública a sinalização.',
        'error_notify_author'     => 'A sinalização já não é privada, mas o aviso ao autor não foi enviado.',
        'error_notify_residents'  => 'A sinalização já não é privada, mas o aviso aos condóminos que não a viam não foi enviado.',
        'error_notify_both'       => 'A sinalização já não é privada, mas não foram enviados nem o aviso ao autor nem o dos condóminos que não a viam.',
        'residents_required'      => 'Uma sinalização privada tem de continuar dirigida a pelo menos um residente. Para a tornar visível a todo o condomínio, use «Tornar pública» na ficha da sinalização.',
        'residents_required_ask_approver' => 'Uma sinalização privada tem de continuar dirigida a pelo menos um residente. Para a tornar visível a todo o condomínio, pode torná-la pública quem aprova as sinalizações.',
    ],
];
