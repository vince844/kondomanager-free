<?php

return [
    // Notifica per nuovi utenti registrati (RegisteredUserNotification)
    'new_user_registered' => [
        'subject'   => 'Novo utilizador registado',
        'greeting'  => 'Olá :name',
        'line_1'    => 'Um novo utilizador registou-se no portal. Após confirmar o endereço de correio eletrónico, poderá aceder à área privada.',
        'line_2'    => 'Certifique-se de associar o registo a um ou mais condomínios se pretender permitir que o utilizador visualize os dados desses condomínios.',
        'action'    => 'Aceder ao portal',
    ],

    // Reset password (CustomResetPasswordNotification)
    'reset_password' => [
        'subject'   => 'Notificação de recuperação de palavra-passe',
        'greeting'  => 'Olá!',
        'line_1'    => 'Está a receber este email porque recebemos um pedido de recuperação da palavra-passe para a sua conta.',
        'action'    => 'Recuperar palavra-passe',
        'line_2'    => 'Este link de recuperação expirará em :count minutos.',
        'line_3'    => 'Se não solicitou a recuperação da palavra-passe, ignore este email.',
    ],

    // Verifica email (CustomVerifyEmailNotification)
    'verify_email' => [
        'subject'   => 'Verificar endereço de correio eletrónico',
        'greeting'  => 'Olá',
        'line_1'    => 'Por favor, clique no botão seguinte para verificar o seu endereço de correio eletrónico.',
        'action'    => 'Verificar endereço de correio eletrónico',
        'line_2'    => 'Se não criou uma conta, ignore este email.',
    ],

    // Invito utente (InviteUserNotification)
    'invite_user' => [
        'subject'   => 'Bem-vindo ao :appName',
        'line_1'    => 'O administrador do condomínio convidou-o a registar a sua conta online.',
        'action'    => 'Registar agora',
        'line_2'    => 'Este convite expirará dentro de três dias.',
        'app_name'  => config('app.name'),
    ],

    // Nuovo utente creato dall'amministratore (NewUserEmailNotification)
    'new_user_created' => [
        'subject'   => 'Bem-vindo ao :appName',
        'greeting'  => 'Olá :name,',
        'line_1'    => 'O administrador do condomínio criou o seu perfil. Clique no link seguinte para definir a sua palavra-passe.',
        'action'    => 'Definir palavra-passe',
        'line_2'    => 'Este link expirará em 60 minutos.',
        'app_name'  => config('app.name'),
    ],

    // Stringhe comuni a tutte le notifiche
    'common' => [
        'regards'             => 'Cumprimentos',
        'app_name'            => config('app.name'),
        'copyright'           => 'Todos os direitos reservados.',
        'trouble_with_button' => 'Se tiver problemas ao clicar no botão ":actionText", copie e cole o URL seguinte no seu navegador:',
        'no_reply'            => 'Por favor, não responda a este email.',
        'auto_generated'      => 'Este é um email gerado automaticamente.',
    ],
];