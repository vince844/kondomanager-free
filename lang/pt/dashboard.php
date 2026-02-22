<?php

return [
    'header' => [
        'control_panel' => 'Painel de controlo',
    ],

    'actions' => [
        'action_inbox' => 'Caixa de ações',
        'view_all' => 'Visualizar todos',
        'view_all_feminine' => 'Visualizar todas',
    ],

    'kpis' => [
        'registered_buildings' => 'Condomínios registados',
        'all_buildings' => 'Todos os edifícios',
        'open_tickets' => 'Sinalizações abertas',
        'action_required' => 'Ação necessária',
        'no_tickets' => 'Nenhuma sinalização',
        'upcoming_deadlines' => 'Prazos iminentes',
        'next_7_days' => 'Próximos 7 dias',
        'storage' => 'Armazenamento',
        'usage' => 'Utilização',
        'files_archived' => ':count ficheiros arquivados',
        'document_archive' => 'Arquivo de documentos',
    ],

    'widgets' => [
        'latest_documents_title' => 'Últimos documentos',
        'latest_documents_description' => 'Lista dos últimos documentos carregados no arquivo',
        'upcoming_events_title' => 'Próximos prazos na agenda',
        'upcoming_events_description' => 'Lista dos prazos nos próximos dias',
        'no_events_created' => 'Ainda não foi criado nenhum prazo na agenda!',
        'starts_on' => 'começa em',
    ],

    'permissions' => [
        'view_archive_documents' => 'Não possui permissões suficientes para visualizar documentos em arquivo!',
        'view_events' => 'Não possui permissões suficientes para visualizar os prazos da agenda!',
    ],

    'buildings_dropdown' => [
        'select_aria' => 'Selecionar condomínio',
        'select_placeholder' => 'Selecionar condomínio...',
        'search_placeholder' => 'Pesquisar condomínio...',
        'empty_state' => 'Nenhum condomínio encontrado.',
        'reset_selection' => 'Limpar seleção',
        'management' => 'Gestão',
        'go_to_management_title' => 'Ir para o painel de gestão',
    ],

    'inbox' => [
        'page_title' => 'Caixa de ações',
        'back_to_dashboard' => 'Voltar ao painel',
        'subtitle' => 'O seu centro de comando. Faça a gestão de prazos e recebimentos num único ponto.',
        'expiring_activities' => 'Atividades a expirar',
        'not_available' => '—',
        'yesterday' => 'Ontem',
        'days_late' => ':count dias de atraso',
        'results_shown' => 'Mostrados :count resultados',
        'filters' => [
            'urgent' => 'Expirados / Urgentes',
            'payments' => 'Verificações de recebimentos',
            'maintenance' => 'Tickets e manutenção',
            'all' => 'Ver tudo',
            'reset' => 'Limpar filtros',
        ],
        'table' => [
            'deadline' => 'Prazo',
            'building' => 'Condomínio',
            'activity' => 'Atividade',
            'actions' => 'Ações',
        ],
        'actions' => [
            'reject_report' => 'Rejeitar sinalização',
            'register' => 'Registar',
            'manage' => 'Gerir',
            'details' => 'Detalhes',
        ],
        'empty' => [
            'title' => 'Tudo em ordem!',
            'description' => 'Nenhuma atividade urgente requer atenção.',
        ],
        'reject_modal' => [
            'title' => 'Rejeitar sinalização',
            'description_prefix' => 'Está prestes a rejeitar o pagamento sinalizado por',
            'tenant_fallback' => 'Condómino',
            'description_warning' => 'Atenção: esta ação é irreversível.',
            'reason_label' => 'Motivo (visível para o utilizador)',
            'reason_placeholder' => 'Ex.: Transferência não encontrada no extrato...',
            'confirm' => 'Confirmar rejeição',
        ],
    ],
];
