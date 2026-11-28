<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

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
        'expired'   => 'O convite expirou. Peça ao administrador que lhe envie um novo.',
        'already_used' => 'Este convite já foi utilizado. Inicie sessão com a sua conta.',
        'account_exists' => 'Já existe uma conta com este email: inicie sessão ou recupere a senha se não se lembrar dela.',
    ],

    // Novo utilizador criado pelo administrador (NewUserEmailNotification)
    'new_user_created' => [
        'subject'   => 'Bem-vindo ao :appName',
        'greeting'  => 'Olá :name,',
        'line_1'    => 'O administrador do condomínio criou o seu perfil. Clique no link a seguir para definir a sua senha.',
        'action'    => 'Definir senha',
        'line_2'    => 'Este link expirará em três dias.',
        'password_already_set' => 'A sua senha já foi definida. Inicie sessão com as suas credenciais.',
        'password_set' => 'A sua senha foi definida. Já pode iniciar sessão.',
        'link_expired' => 'O link de convite expirou ou já não é válido. Contacte o administrador para receber um novo convite.',
    ],

    // Comunicação pendente de aprovação (ApproveComunicazioneNotification)
    'approve_communication' => [
        'subject'   => 'Nova comunicação para aprovação',
        'greeting'  => 'Olá :name',
        'line_1'    => 'O utilizador :user criou uma nova comunicação no quadro de avisos do condomínio.',
        'line_2'    => 'A comunicação encontra-se pendente de aprovação porque o utilizador que a submeteu não tem permissões suficientes para a publicar.',
        'object'    => 'Assunto',
        'priority'  => 'Prioridade',
        'action'    => 'Ver comunicação',
    ],

    // Comunicação aprovada (ApprovedComunicazioneNotification)
    'approved_communication' => [
        'subject'   => 'Comunicação aprovada',
        'greeting'  => 'Olá :name',
        'line_1'    => 'O utilizador :user aprovou a comunicação no quadro de avisos do condomínio.',
        'object'    => 'Assunto',
        'priority'  => 'Prioridade',
        'action'    => 'Ver comunicação',
    ],

    'scheduled_communication_published' => [
        'subject'   => 'Comunicação publicada: :title',
        'line_1'    => 'A comunicação ":title", agendada para :date às :time, foi publicada.',
        'line_late' => 'Foi publicada com atraso, a :date às :time: à hora escolhida o agendador não estava ativo.',
        'action'    => 'Ver comunicação',
    ],

    // Documento aprovado (ApprovedDocumentoNotification)
    'approved_document' => [
        'subject'     => 'Documento aprovado',
        'greeting'    => 'Olá :name',
        'line_1'      => 'O utilizador :user aprovou o documento no arquivo do condomínio.',
        'title'       => 'Título',
        'description' => 'Descrição',
        'action'      => 'Ver documentos',
    ],

    // Aprovação de documento (ApproveDocumentoNotification)
    'approve_document' => [
        'subject'     => 'Novo documento no arquivo a aprovar',
        'greeting'    => 'Olá :name',
        'line_1'      => 'O utilizador :user criou um novo documento no arquivo do condomínio.',
        'line_2'      => 'O documento está à espera de aprovação porque o utilizador que o enviou não tem permissões suficientes para o publicar.',
        'title'       => 'Título',
        'description' => 'Descrição',
        'action'      => 'Ver documentos',
    ],

    // Novo documento publicado (NewDocumentoNotification)
    'new_document' => [
        'subject'     => 'Novo documento no arquivo',
        'greeting'    => 'Olá :name',
        'line_1'      => 'O utilizador :user publicou um novo documento no arquivo do condomínio.',
        'title'       => 'Título',
        'description' => 'Descrição',
        'action'      => 'Ver documentos',
    ],

    // Nova comunicação publicada (NewComunicazioneNotification)
    'new_communication' => [
        'subject'   => 'Nova comunicação no quadro de avisos',
        'greeting'  => 'Olá :name',
        'line_1'    => 'O utilizador :user criou uma nova comunicação no quadro de avisos do condomínio.',
        'object'    => 'Assunto',
        'priority'  => 'Prioridade',
        'action'    => 'Ver comunicação',
    ],

    // Relatório aprovado (ApprovedSegnalazioneNotification)
    'approved_ticket' => [
        'subject'     => 'Nova notificação de avaria aprovada',
        'greeting'    => 'Olá :name',
        'line_1'      => 'O utilizador :user aprovou a notificação de avaria.',
        'object'      => 'Assunto',
        'priority'    => 'Prioridade',
        'action'      => 'Ver notificação',
    ],

    // Segnalazione privata resa pubblica (SegnalazioneResaPubblicaNotification)
    'ticket_made_public' => [
        'subject'  => 'A sua sinalização agora é pública',
        'subject_pending' => 'A sua sinalização já não é privada',
        'greeting' => 'Olá :name',
        'line_1'   => ':user tornou pública a sua sinalização.',
        'line_1_pending' => ':user decidiu que a sua sinalização já não é privada.',
        'object'   => 'Assunto',
        'line_2'   => 'Agora todos os condóminos de :condominio a veem, com o seu nome.',
        'line_2_pending' => 'Todos os condóminos de :condominio a vão ver, com o seu nome, quando estiver publicada e aprovada.',
        'action'   => 'Ver sinalização',
    ],

    // Porque é que um email ao autor não leva o botão para a ficha (PulsanteSoloSeLaApre)
    'author_cannot_open' => [
        'outside_building' => 'Já não a vê no portal: não consta entre os condóminos de :condominio.',
        'no_permission'    => 'Não a vê no portal: a sua conta não tem permissão para a abrir.',
        'not_published'    => 'Não a vê no portal: neste momento não está publicada e aprovada.',
        'not_addressed'    => 'Já não a vê no portal: já não se dirige a si.',
        'no_anagrafica'    => 'Não a vê no portal: a sua conta não está associada a um registo. Para usar o portal, inicie sessão e preencha os seus dados.',
    ],

    // Aprovação de ticket (ApproveSegnalazioneNotification)
    'approve_ticket' => [
        'subject'     => 'Novo ticket a aprovar',
        'greeting'    => 'Olá :name',
        'line_1'      => 'O utilizador :user criou um novo ticket de avaria para o condomínio.',
        'line_2'      => 'O ticket está à espera de aprovação porque o utilizador que o enviou não tem permissões suficientes para o publicar.',
        'object'      => 'Assunto',
        'priority'    => 'Prioridade',
        'status'      => 'Estado',
        'action'      => 'Ver ticket',
    ],

    // Novo ticket (NewSegnalazioneNotification)
    'new_ticket' => [
        'subject'     => 'Novo ticket de avaria',
        'greeting'    => 'Olá :name',
        'line_1'      => 'O utilizador :user criou um novo ticket de avaria.',
        'object'      => 'Assunto',
        'priority'    => 'Prioridade',
        'status'      => 'Estado',
        'action'      => 'Ver ticket',
    ],

    // Novo comentário
    'new_ticket_comment' => [
        'subject'  => 'Novo comentário em: :entity',
        'greeting' => 'Olá!',
        'line_1'   => ':user deixou um novo comentário:',
        'action'   => 'Ver a notificação',
        'line_2'   => 'Está a receber este email porque está envolvido nesta notificação.',
    ],

    // Comentário aprovado
    'approved_ticket_comment' => [
        'subject' => 'O seu comentário foi aprovado',
        'line_1'  => 'O seu comentário sobre :entity foi aprovado e publicado.',
        'action'  => 'Ver a notificação',
    ],

    // Comentário pendente
    'pending_ticket_comment' => [
        'subject' => 'Novo comentário para aprovação em :entity',
        'line_1'  => ':user escreveu um comentário em ":title" que requer aprovação.',
        'line_2'  => 'Inicie sessão para aprovar ou rejeitar o comentário.',
        'action'  => 'Ver a notificação',
    ],

    'edited_pending_ticket_comment' => [
        'subject' => 'Comentário modificado para aprovação',
        'line_1'  => ':user modificou um comentário na sinalização ":title":',
        'line_2'  => 'Inicie sessão para aprovar ou rejeitar o comentário.',
        'action'  => 'Ver sinalização',
    ],

    // Comentário eliminado
    // La parola con cui i due avvisi del commento nascosto o eliminato chiamano l'elemento (con l'articolo, dove la lingua lo chiede).
    'comment_entity' => [
        'ticket'        => 'a sinalização',
        'communication' => 'o comunicado',
    ],

    'hidden_ticket_comment' => [
        'subject' => 'Um dos seus comentários foi ocultado',
        'line_1'  => 'O seu comentário de :data sobre :entity foi ocultado pelo administrador do condomínio: os outros já não o veem.',
        'action'  => 'Ver sinalização',
    ],

    'deleted_ticket_comment' => [
        'subject'        => 'Um dos seus comentários foi apagado',
        'subject_pending' => 'Um dos seus comentários não foi publicado',
        'line_1'         => 'O seu comentário de :data sobre :entity foi apagado pelo administrador do condomínio.',
        'line_1_pending' => 'O seu comentário de :data sobre :entity não foi aprovado e foi apagado pelo administrador do condomínio.',
        'started'        => 'O comentário começava assim: «:inizio»',
        'action'         => 'Ver sinalização',
    ],

    // Comentários em comunicações
    'new_communication_comment' => [
        'subject'  => 'Novo comentário em: :entity',
        'greeting' => 'Olá!',
        'line_1'   => ':user deixou um novo comentário:',
        'action'   => 'Ver a comunicação',
        'line_2'   => 'Está a receber este email porque está envolvido nesta comunicação.',
    ],

    'approved_communication_comment' => [
        'subject' => 'O seu comentário foi aprovado',
        'line_1'  => 'O seu comentário sobre :entity foi aprovado e publicado.',
        'action'  => 'Ver a comunicação',
    ],

    'pending_communication_comment' => [
        'subject' => 'Novo comentário para aprovação em :entity',
        'line_1'  => ':user escreveu um comentário em ":title" que requer aprovação.',
        'line_2'  => 'Inicie sessão para aprovar ou rejeitar o comentário.',
        'action'  => 'Ver a comunicação',
    ],

    'edited_pending_communication_comment' => [
        'subject' => 'Comentário modificado para aprovação',
        'line_1'  => ':user modificou um comentário no comunicado ":title":',
        'line_2'  => 'Inicie sessão para aprovar ou rejeitar o comentário.',
        'action'  => 'Ver comunicado',
    ],

    'hidden_communication_comment' => [
        'subject' => 'Um dos seus comentários foi ocultado',
        'line_1'  => 'O seu comentário de :data sobre :entity foi ocultado pelo administrador do condomínio: os outros já não o veem.',
        'action'  => 'Ver comunicado',
    ],

    'deleted_communication_comment' => [
        'subject'        => 'Um dos seus comentários foi apagado',
        'subject_pending' => 'Um dos seus comentários não foi publicado',
        'line_1'         => 'O seu comentário de :data sobre :entity foi apagado pelo administrador do condomínio.',
        'line_1_pending' => 'O seu comentário de :data sobre :entity não foi aprovado e foi apagado pelo administrador do condomínio.',
        'started'        => 'O comentário começava assim: «:inizio»',
        'action'         => 'Ver comunicado',
    ],

    // Stringhe comuni a tutte le notifiche
    'common' => [
        'regards'             => 'Cumprimentos',
        'copyright'           => 'Todos os direitos reservados.',
        'trouble_with_button' => 'Se tiver problemas ao clicar no botão ":actionText", copie e cole o URL seguinte no seu navegador:',
        'no_reply'            => 'Por favor, não responda a este email.',
        'auto_generated'      => 'Este é um email gerado automaticamente.',
    ],

    // ─── Avisos de alteração (beta.64) ─────────────────────────────────────────
    'updated_communication' => [
        'subject'   => 'Comunicação atualizada no placard',
        'greeting'  => 'Olá :name',
        'line_1'    => 'A comunicação foi atualizada por :user.',
        'object'    => 'Assunto',
        'priority'  => 'Prioridade',
        'action'    => 'Ver comunicação',
    ],

    'updated_ticket' => [
        'subject'   => 'Ocorrência atualizada',
        'greeting'  => 'Olá :name',
        'line_1'    => 'A ocorrência foi atualizada por :user.',
        'object'    => 'Assunto',
        'status'    => 'Estado',
        'action'    => 'Ver ocorrência',
    ],

    'updated_document' => [
        'subject'     => 'Documento atualizado no arquivo',
        'greeting'    => 'Olá :name',
        'line_1'      => 'O documento foi atualizado por :user no arquivo do condomínio.',
        'title'       => 'Título',
        'description' => 'Descrição',
        'action'      => 'Ver documentos',
    ],

    // ─── Avisos de segurança ao titular da conta (Coda 267) ────────────────────
    'security_alert' => [
        'greeting' => 'Olá :name,',
        'autore'   => 'A alteração foi feita por :autore.',
        'sempre'   => 'Este aviso é enviado a cada alteração da segurança da sua conta e não pode ser desativado.',
        'password_cambiata' => [
            'subject'   => 'A palavra-passe da sua conta foi alterada',
            'line_1'    => 'Em :data às :ora a palavra-passe da sua conta em :appName foi alterada.',
            'consiglio' => 'Se não foi você a fazer esta alteração, redefina já a palavra-passe com «Password dimenticata?» na página de acesso e avise o administrador.',
        ],
        'password_reimpostata' => [
            'subject'   => 'A palavra-passe da sua conta foi redefinida',
            'line_1'    => 'Em :data às :ora a palavra-passe da sua conta em :appName foi redefinida com o link «Password dimenticata?».',
            'consiglio' => 'Se não foi você a fazer esta alteração, redefina já a palavra-passe com «Password dimenticata?» na página de acesso e avise o administrador.',
        ],
        'email_cambiata' => [
            'subject'   => 'O endereço de email da sua conta foi alterado',
            'line_1'    => 'Em :data às :ora o endereço de email da sua conta em :appName foi alterado para :email. A partir de agora o acesso faz-se com o novo endereço, e os links para redefinir a palavra-passe chegam lá.',
            'consiglio' => 'Se não foi você a pedir esta alteração, contacte já o administrador.',
        ],
        'due_fattori_attivata' => [
            'subject'   => 'Autenticação de dois fatores ativada',
            'line_1'    => 'Em :data às :ora foi ativada a autenticação de dois fatores da sua conta em :appName.',
            'consiglio' => 'Se não foi você a fazer esta alteração, altere já a palavra-passe e avise o administrador.',
        ],
        'due_fattori_disattivata' => [
            'subject'   => 'Autenticação de dois fatores desativada',
            'line_1'    => 'Em :data às :ora foi desativada a autenticação de dois fatores da sua conta em :appName: para entrar basta agora a palavra-passe.',
            'consiglio' => 'Se não foi você a fazer esta alteração, altere já a palavra-passe, volte a ativar a autenticação de dois fatores e avise o administrador.',
        ],
        'codici_rigenerati' => [
            'subject'   => 'Novos códigos de recuperação',
            'line_1'    => 'Em :data às :ora foram gerados novos códigos de recuperação da autenticação de dois fatores da sua conta em :appName: os anteriores deixaram de valer.',
            'consiglio' => 'Se não foi você a fazer esta alteração, altere já a palavra-passe e avise o administrador.',
        ],
        'accesso_da_dispositivo_nuovo' => [
            'subject'   => "Novo acesso à sua conta",
            'line_1'    => "Em :data às :ora alguém entrou na sua conta em :appName a partir de um dispositivo que não conhecíamos: :dispositivo.",
            'consiglio' => "Se foi você, não precisa de fazer nada. Caso contrário, altere já a palavra-passe e avise o administrador.",
            'cookie'    => "Se usar a navegação privada, ou se o navegador apagar os cookies, este aviso chega a cada acesso.",
        ],
        'indirizzo' => "O endereço de rede era :indirizzo.",
        'dispositivo' => ":browser em :sistema",
        'dispositivo_sconosciuto' => "um navegador que não reconhecemos",
    ],

    'errore_avvisato' => [

        'subject' => 'Erro :codice: avisou-o :nome',

        'line_1' => ':nome (:ruolo) avisou-o de um erro em :quando.',

        'line_codice' => 'O código do erro: :codice.',

        'line_pagina' => 'A página: :pagina.',

        'action' => 'Abrir o erro',

        'line_dettaglio' => 'O detalhe, a nota e o relatório estão no separador «Errori» das definições: não estão neste email.',

    ],

    'assigned_ticket' => [
        'subject' => 'Ticket atribuído a si: :title',
        'line_1' => ':user atribuiu-lhe o ticket ":title" (:building).',
        'action' => 'Ver ticket',
    ],
    'unassigned_ticket' => [
        'subject' => 'Ticket já não atribuído a si: :title',
        'line_1' => ':user atribuiu a :name o ticket ":title", que estava ao seu encargo.',
        'line_1_nobody' => ':user removeu a atribuição do ticket ":title", que estava ao seu encargo.',
        'line_1_self' => ':user assumiu o ticket ":title", que estava ao seu encargo.',
        'action' => 'Ver ticket',
    ],
];
