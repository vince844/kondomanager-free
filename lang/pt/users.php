<?php

return [
    /* ------------------------------------------------------------------
     | Backend notifications
     | ------------------------------------------------------------------ */
    'success_create_user'        => 'O novo utilizador foi criado com sucesso.',
    'error_create_user'          => 'Ocorreu um erro durante a criação do novo utilizador.',
    'success_update_user'        => 'O utilizador foi atualizado com sucesso.',
    'error_update_user'          => 'Ocorreu um erro durante a atualização do utilizador.',
    'success_delete_user'        => 'O utilizador foi eliminado com sucesso.',
    'error_delete_user'          => 'Ocorreu um erro durante a eliminação do utilizador.',
    'success_send_user_invite'   => 'O convite foi enviado com sucesso.',
    'error_send_user_invite'     => 'Ocorreu um erro durante o envio do convite ao utilizador.',
    'success_delete_user_invite' => 'O convite foi eliminado com sucesso.',
    'error_delete_user_invite'   => 'Ocorreu um erro durante a eliminação do convite.',
    'success_suspend_user'       => 'O utilizador foi suspenso com sucesso.',
    'error_suspend_user'         => 'Ocorreu um erro ao tentar suspender o utilizador.',
    'success_unsuspend_user'     => 'O utilizador foi reativado com sucesso.',
    'error_unsuspend_user'       => 'Ocorreu um erro ao tentar reativar o utilizador.',
    'success_verify_user'        => 'O utilizador foi verificado com sucesso.',
    'success_revoke_verify_user' => 'A verificação do utilizador foi revogada.',
    'error_verify_user'          => 'Ocorreu um erro durante a verificação do utilizador.',
    'error_email_not_sent'       => 'O utilizador foi criado corretamente, mas não foi possível enviar o email de convite.',

    /* ------------------------------------------------------------------
     | Front‑end strings (headings, titles, descriptions)
     | ------------------------------------------------------------------ */
    'header' => [
        'list_users_head'                    => 'Lista de utilizadores',
        'edit_user_head'                     => 'Editar utilizador',
        'new_user_head'                      => 'Criar novo utilizador',
        'new_user_title'                     => 'Criar novo utilizador',
        'new_user_description'               => 'A seguir pode criar um novo utilizador. Pode atribuir um papel, um registo e permissões específicas para este utilizador',
        'permissions_title'                  => 'Permissões herdadas do papel',
        'permissions_description_line_1'     => 'Estas permissões são herdadas através do papel',
        'permissions_description_line_2'     => 'e serão atribuídas automaticamente ao utilizador',
        'additional_permissions_title'       => 'Permissões adicionais',
        'additional_permissions_description' => 'Permissões atribuídas diretamente ao utilizador, além das do papel',
    ],

    /* ------------------------------------------------------------------
     | Table
     | ------------------------------------------------------------------ */
    'table' => [
        'name'               => 'Nome completo',
        'email'              => 'Endereço de correio eletrónico',
        'role'               => 'Papel',
        'permissions'        => 'Permissões',
        'status'             => 'Estado',
        'suspended'          => 'Suspenso',
        'active'             => 'Ativo',
        'actions'            => 'Ações',
        'filter'             => 'Filtrar por nome...',
        'no_permissions'     => 'Nenhuma permissão',
        'verified_tooltip'   => 'Utilizador verificado - clique para revogar verificação',
        'unverified_tooltip' => 'Utilizador não verificado - clique para verificar',
    ],

    /* ------------------------------------------------------------------
     | Labels for fields
     | ------------------------------------------------------------------ */
    'label' => [
        'name'                         => 'Nome completo',
        'email'                        => 'Endereço de correio eletrónico',
        'role'                         => 'Papel do utilizador',
        'resident'                     => 'Registo associado',
        'permissions'                  => 'Permissões adicionais',
        'permission_notice'            => 'As permissões do papel selecionado são herdadas automaticamente',
        'permissions_assigned'         => 'Permissões do utilizador',
        'permissions_assigned_to_user' => 'Permissões atribuídas a :name',
        'permissions_count'            => ':count permissões',
    ],

    /* ------------------------------------------------------------------
     | Placeholders
     | ------------------------------------------------------------------ */
    'placeholder' => [
        'name'        => 'Inserir nome completo',
        'email'       => 'Inserir endereço de correio eletrónico',
        'role'        => 'Selecionar papel do utilizador',
        'resident'    => 'Selecionar registo',
        'permissions' => 'Selecionar permissões adicionais',
    ],

    /* ------------------------------------------------------------------
     | Actions
     | ------------------------------------------------------------------ */
    'actions' => [
        'new_user'      => 'Criar utilizador',
        'edit_user'     => 'Editar',
        'delete_user'   => 'Eliminar',
        'suspend_user'  => 'Suspender',
        'activate_user' => 'Ativar',
        'invite_user'   => 'Reenviar convite',
    ],

    /* ------------------------------------------------------------------
     | Tooltips / Hover cards
     | ------------------------------------------------------------------ */
    'tooltip' => [
        'role_line_1' => 'Selecione o papel a atribuir ao utilizador. Escolha entre os papéis predefinidos ou um criado por si.',
        'role_line_2' => 'As permissões associadas ao papel serão herdadas automaticamente.',
        'resident'    => 'Selecione o registo a associar ao utilizador. O registo associado poderá aceder ao sistema com as credenciais do utilizador criado e consultar os seus dados e os relacionados.',
        'permissions' => 'Selecione permissões específicas a atribuir ao utilizador além das herdadas do papel selecionado.',
    ],

    /* ------------------------------------------------------------------
     | Dialogs
     | ------------------------------------------------------------------ */
    'dialogs' => [
        'no_users_created'         => 'Ainda não foi criado nenhum utilizador',
        'delete_user_title'        => 'Tem a certeza de que pretende eliminar este utilizador?',
        'delete_user_description'  => 'Esta ação é irreversível. Eliminará o utilizador e todos os dados associados.',
        'invite_user_title'        => 'Tem a certeza de que pretende reenviar o convite a este utilizador?',
        'invite_user_description'  => 'O utilizador receberá um email com um novo link para criar uma nova palavra-passe.',
    ],

    /* ------------------------------------------------------------------
     | Empty states
     | ------------------------------------------------------------------ */
    'empty_state' => [
        'inherited_permissions'   => 'Nenhuma permissão herdada do papel',
        'additional_permissions'  => 'Nenhuma permissão adicional atribuída',
        'no_assigned_permissions' => 'Nenhuma permissão atribuída',
    ],

    /* ------------------------------------------------------------------
     | Badges (labels/statuses)
     | ------------------------------------------------------------------ */
    'badge' => [
        'previously_direct' => 'atribuído anteriormente',
    ],

    /* ------------------------------------------------------------------
     | Layout
     | ------------------------------------------------------------------ */
    'layout' => [
        'heading_title'       => 'Gestão de utilizadores',
        'heading_description' => 'A seguir uma lista dos utilizadores registados, papéis, permissões e convites',
    ],
];