<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

return [
    'layout' => [
        'title' => 'Definições',
        'description' => 'Gestão do seu perfil e das definições da conta',
        'nav' => [
            'profile' => 'Perfil',
            'password' => 'Palavra-passe',
            'two_factor' => 'Proteção 2FA',
            'notifications' => 'Notificações',
            'appearance' => 'Aspeto',
        ],
    ],

    'appearance' => [
        'title' => 'Definições de aspeto',
        'description' => 'Atualize as definições de aspeto da aplicação',
        'tabs' => [
            'light' => 'Claro',
            'dark' => 'Escuro',
            'system' => 'Sistema',
        ],
    ],

    'profile' => [
        'title' => 'Definições de perfil',
        'heading' => 'Informações de perfil',
        'description' => 'Atualize o seu nome e endereço de email',
        'name' => 'Nome completo',
        'name_placeholder' => 'Nome completo',
        'email' => 'Endereço de email',
        'email_placeholder' => 'Endereço de email',
        'email_unverified' => 'O seu endereço de email não está verificado.',
        'resend_verification' => 'Clique aqui para receber um novo email de verificação.',
        'verification_sent' => 'Foi enviado um novo link de verificação para o seu endereço de email.',
        'save' => 'Guardar',
        'saved' => 'Guardado',
        // Coda 261: la password attuale, solo quando l'email cambia.
        'current_password' => 'Palavra-passe atual',
        'current_password_placeholder' => 'Insira a palavra-passe atual',
        'current_password_hint' => 'Para alterar o endereço de email é necessária a palavra-passe atual.',
    ],

    'password' => [
        'title' => 'Definições de palavra-passe',
        'heading' => 'Atualizar palavra-passe',
        'description' => 'Utilize uma palavra-passe longa e aleatória para manter a sua conta segura',
        'current_password' => 'Palavra-passe atual',
        'current_password_placeholder' => 'Palavra-passe atual',
        'new_password' => 'Nova palavra-passe',
        'new_password_placeholder' => 'Nova palavra-passe',
        'confirm_password' => 'Confirmar palavra-passe',
        'confirm_password_placeholder' => 'Confirmar palavra-passe',
        'save' => 'Guardar palavra-passe',
        'saved' => 'Guardada',
    ],

    'notifications' => [
        'title' => 'Definições de notificações',
        'heading' => 'Definições de notificações',
        'description' => 'Selecione abaixo as notificações por email que pretende receber',
        'empty' => 'Não existem notificações por email disponíveis para selecionar.',
        'save' => 'Guardar preferências',
        'enable_all' => 'Ativar todas',
        'disable_all' => 'Desativar todas',
        'counter' => ':attive ativas de :totali',
    ],

    'two_factor' => [
        // Coda 261: `enable` con la 2FA già confermata.
        'already_enabled' => 'A autenticação de dois fatores já está ativa: para a alterar, desative-a primeiro.',
        'title' => 'Autenticação de dois fatores',
        'heading' => 'Autenticação de dois fatores',
        'description' => 'Gestão das definições de autenticação de dois fatores',
        'disabled' => 'Desativado',
        'enabled' => 'Ativado',
        'enable' => 'Ativar',
        'disable' => 'Desativar 2FA',
        'intro' => 'Ao ativar a autenticação de dois fatores (2FA), terá de introduzir um código de segurança durante o login. Este código pode ser obtido na aplicação Google Authenticator no seu telemóvel.',
        'download_app' => 'Pode descarregar a aplicação aqui:',
        'store_android' => 'Google Play Store (Android)',
        'store_ios' => 'Apple App Store (iOS)',
        'follow_steps' => 'Para ativar 2FA, siga as instruções abaixo.',
        'enabled_description' => 'Com a autenticação de dois fatores ativa, será solicitado um token seguro e aleatório no acesso, que pode obter na app Google Authenticator.',
        'dialog_title_enable' => 'Ativar verificação em dois passos',
        'dialog_title_verify' => 'Verificar código de autenticação',
        'dialog_desc_enable' => 'Abra a sua app de autenticação e selecione “Ler código QR”',
        'dialog_desc_verify' => 'Introduza o código de 6 dígitos da sua app de autenticação',
        'continue' => 'Continuar',
        'manual_code' => 'ou introduza o código manualmente',
        'back' => 'Voltar',
        'confirm' => 'Confirmar',
        'recovery_codes_title' => 'Códigos de recuperação 2FA',
        'recovery_codes_desc' => 'Os códigos de recuperação permitem recuperar o acesso caso perca o dispositivo usado na 2FA. Guarde-os num gestor de palavras-passe seguro ou imprima e guarde em local seguro.',
        'show_recovery_codes' => 'Mostrar códigos de recuperação',
        'hide_recovery_codes' => 'Ocultar códigos de recuperação',
        'regenerate_codes' => 'Regenerar códigos',
        'remaining_codes' => 'Restam-lhe :count códigos de recuperação. Cada código só pode ser usado uma vez para aceder à sua conta e será removido após utilização. Se precisar de novos códigos, clique em Regenerar códigos acima.',
        'invalid_code' => 'Código de verificação inválido',
        'confirm_error' => 'Ocorreu um erro ao confirmar a autenticação de dois fatores',
    ],

    'delete_user' => [
        'title' => 'Eliminar conta',
        'description' => 'Elimine a sua conta e todos os dados associados',
        'warning_title' => 'Atenção',
        'warning_description' => 'Prossiga com cuidado, esta ação é irreversível.',
        'button' => 'Eliminar conta',
        'confirm_title' => 'Tem a certeza de que pretende eliminar a sua conta?',
        'confirm_description' => 'Depois de eliminar a sua conta, todos os dados associados serão removidos permanentemente. Introduza a sua palavra-passe para confirmar a eliminação permanente.',
        'password' => 'Palavra-passe',
        'password_placeholder' => 'Palavra-passe',
        'cancel' => 'Cancelar',
    ],

    'notification_types' => [
        'new_communication' => [
            'label' => 'Nova comunicação no quadro de avisos',
            'description' => 'Receba uma notificação quando for criada uma nova comunicação',
        ],
        'approved_communication' => [
            'label' => 'Comunicação aprovada',
            'description' => 'Receba uma notificação quando a sua comunicação for aprovada',
        ],
        'updated_communication' => [
            'label' => 'Comunicação do placard atualizada',
            'description' => 'Receba uma notificação quando uma comunicação que já recebeu for alterada',
        ],
        'scheduled_communication_published' => [
            'label' => 'Comunicação agendada publicada',
            'description' => 'Receba uma confirmação quando uma comunicação que agendou for publicada',
        ],
        'new_ticket' => [
            'label' => 'Novo relato de avaria',
            'description' => 'Receba uma notificação quando for criado um novo relato de avaria',
        ],
        'approved_ticket' => [
            'label' => 'Relato de avaria aprovado',
            'description' => 'Receba uma notificação quando o seu relato de avaria for aprovado',
        ],
        'updated_ticket' => [
            'label' => 'Ocorrência atualizada',
            'description' => 'Receba uma notificação quando uma ocorrência que acompanha for alterada',
        ],
        'ticket_assigned' => [
            'label' => 'Ticket de avaria atribuído',
            'description' => 'Receba uma notificação quando um ticket de avaria lhe for atribuído, ou deixar de estar atribuído a si',
        ],
        'new_archive_document' => [
            'label' => 'Novo documento no arquivo',
            'description' => 'Receba uma notificação quando for publicado um novo documento no arquivo',
        ],
        'approved_archive_document' => [
            'label' => 'Documento de arquivo aprovado',
            'description' => 'Receba uma notificação quando o seu documento de arquivo for aprovado',
        ],
        'updated_archive_document' => [
            'label' => 'Documento do arquivo atualizado',
            'description' => 'Receba uma notificação quando um documento que já recebeu for alterado',
        ],
        'new_comment' => [
            'label' => 'Novo comentário (sinalizações)',
            'description' => 'Receba uma notificação quando for adicionado um comentário a uma sinalização em que participa',
        ],
        'comment_approved' => [
            'label' => 'Comentário aprovado (sinalizações)',
            'description' => 'Receba uma notificação quando o seu comentário numa sinalização for aprovado',
        ],
        'comment_deleted' => [
            'label' => 'Comentário oculto (sinalizações)',
            'description' => 'Receba uma notificação quando o seu comentário numa sinalização for ocultado',
        ],
        'comment_removed' => [
            'label' => 'Comentário apagado (sinalizações)',
            'description' => 'Receba uma notificação quando o seu comentário numa sinalização for apagado por outra pessoa',
        ],
        'new_communication_comment' => [
            'label' => 'Novo comentário (comunicados)',
            'description' => 'Receba uma notificação quando for adicionado um comentário a um comunicado que recebe',
        ],
        'communication_comment_approved' => [
            'label' => 'Comentário aprovado (comunicados)',
            'description' => 'Receba uma notificação quando o seu comentário num comunicado for aprovado',
        ],
        'communication_comment_deleted' => [
            'label' => 'Comentário oculto (comunicados)',
            'description' => 'Receba uma notificação quando o seu comentário num comunicado for ocultado',
        ],
        'communication_comment_removed' => [
            'label' => 'Comentário apagado (comunicados)',
            'description' => 'Receba uma notificação quando o seu comentário num comunicado for apagado por outra pessoa',
        ],
        'new_user' => [
            'label' => 'Novo utilizador registado',
            'description' => 'Receba uma notificação quando um novo utilizador se registar',
        ],
        'comment_under_moderation' => [
            'label' => 'Comentário a aguardar moderação (sinalizações)',
            'description' => 'Receba uma notificação quando um novo comentário numa sinalização exigir a sua aprovação',
        ],
        'comment_edited_under_moderation' => [
            'label' => 'Comentário modificado para moderar (sinalizações)',
            'description' => 'Receba uma notificação quando um comentário modificado numa sinalização exigir a sua aprovação',
        ],
        'communication_comment_under_moderation' => [
            'label' => 'Comentário a aguardar moderação (comunicados)',
            'description' => 'Receba uma notificação quando um novo comentário num comunicado exigir a sua aprovação',
        ],
        'communication_comment_edited_under_moderation' => [
            'label' => 'Comentário modificado para moderar (comunicados)',
            'description' => 'Receba uma notificação quando um comentário modificado num comunicado exigir a sua aprovação',
        ],
        'new_attachment' => [
            'label' => 'Novo anexo',
            'description' => 'Receba uma notificação quando forem adicionados ficheiros a uma sinalização em que participa',
        ],
        'attachment_approved' => [
            'label' => 'Anexo aprovado',
            'description' => 'Receba uma notificação quando um anexo seu pendente for aprovado',
        ],
        'attachment_deleted' => [
            'label' => 'Anexo eliminado',
            'description' => 'Receba uma notificação quando um anexo seu for eliminado por outra pessoa',
        ],
        'attachment_under_moderation' => [
            'label' => 'Anexo a aprovar',
            'description' => 'Receba uma notificação quando um novo anexo exigir a sua aprovação',
        ],
    ],
];