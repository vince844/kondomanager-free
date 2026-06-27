<?php

use App\Enums\NotificationType;
use App\Enums\Permission;

return [
    'types' => [
        'common' => [
            NotificationType::NEW_COMMUNICATION->value => [
                'label'       => 'Nuova comunicazione bacheca',
                'description' => 'Ricevi una notifica quando viene creata una nuova comunicazione',
                'permission'  => Permission::VIEW_COMUNICAZIONI,
            ],
            NotificationType::APPROVED_COMMUNICATION->value => [
                'label'       => 'Comunicazione bacheca approvata',
                'description' => 'Ricevi una notifica quando viene approvata la comunicazione da te inviata',
                'permission'  => Permission::VIEW_COMUNICAZIONI
            ],
            NotificationType::NEW_TICKET->value => [
                'label'       => 'Nuova segnalazione guasto',
                'description' => 'Ricevi una notifica quando viene creata una nuova segnalazione guasto',
                'permission'  => Permission::VIEW_SEGNALAZIONI
            ],
            NotificationType::APPROVED_TICKET->value => [
                'label'       => 'Segnalazione guasto approvata',
                'description' => 'Ricevi una notifica quando viene approvata la segnalazione guasto da te inviata',
                'permission'  => Permission::VIEW_SEGNALAZIONI
            ],
            NotificationType::NEW_ARCHIVE_DOCUMENT->value => [
                'label'       => 'Nuovo documento in archivio',
                'description' => 'Ricevi una notifica quando viene pubblicato un nuovo documento in archivio',
                'permission'  => Permission::VIEW_ARCHIVE_DOCUMENTS,
            ],
            NotificationType::APPROVED_ARCHIVE_DOCUMENT->value => [
                'label'       => 'Documento in archivio approvato',
                'description' => 'Ricevi una notifica quando viene approvato un documento in archivio da te inviato',
                'permission'  => Permission::VIEW_ARCHIVE_DOCUMENTS
            ],
            NotificationType::NEW_COMMENT->value => [
                'label'       => 'Nuovo commento',
                'description' => 'Ricevi una notifica quando viene aggiunto un commento a una segnalazione a cui partecipi',
                'permission'  => Permission::COMMENT_SEGNALAZIONI
            ],
            NotificationType::COMMENT_APPROVED->value => [
                'label'       => 'Commento approvato',
                'description' => 'Ricevi una notifica quando un tuo commento in attesa viene approvato',
                'permission'  => Permission::COMMENT_SEGNALAZIONI
            ],
            NotificationType::COMMENT_DELETED->value => [
                'label'       => 'Commento eliminato o nascosto',
                'description' => 'Ricevi una notifica quando un tuo commento viene eliminato o nascosto',
                'permission'  => Permission::COMMENT_SEGNALAZIONI
            ],
        ],
        'admin' => [
            NotificationType::NEW_USER->value => [
                'label'       => 'Nuovo utente registrato',
                'description' => 'Ricevi una notifica quando un nuovo utente si registra',
                'permission'  => Permission::VIEW_USERS,
            ],
            NotificationType::COMMENT_UNDER_MODERATION->value => [
                'label'       => 'Commento da moderare',
                'description' => 'Ricevi una notifica quando un nuovo commento richiede la tua approvazione',
                'permission'  => Permission::APPROVE_COMMENTS_SEGNALAZIONI,
            ],
        ],
    ],
];

