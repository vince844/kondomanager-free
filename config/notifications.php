<?php

use App\Enums\NotificationType;
use App\Enums\Permission;

return [
    'types' => [
        'common' => [
            NotificationType::NEW_COMMUNICATION->value => [
                'label' => 'Nuova comunicazione bacheca',
                'description' => 'Ricevi una notifica quando viene creata una nuova comunicazione',
                'permission' => Permission::VIEW_COMUNICAZIONI,
            ],
            NotificationType::APPROVED_COMMUNICATION->value => [
                'label' => 'Comunicazione bacheca approvata',
                'description' => 'Ricevi una notifica quando viene approvata la comunicazione da te inviata',
                'permission' => Permission::VIEW_COMUNICAZIONI
            ],
            NotificationType::NEW_TICKET->value => [
                'label' => 'Nuova segnalazione guasto',
                'description' => 'Ricevi una notifica quando viene creata una nuova segnalazione guasto',
                'permission' => Permission::VIEW_SEGNALAZIONI
            ],
            NotificationType::APPROVED_TICKET->value => [
                'label' => 'Segnalazione guasto approvata',
                'description' => 'Ricevi una notifica quando viene approvata la segnalazione guasto da te inviata',
                'permission' => Permission::VIEW_SEGNALAZIONI
            ],
            NotificationType::NEW_ARCHIVE_DOCUMENT->value => [
                'label' => 'Nuovo documento in archivio',
                'description' => 'Ricevi una notifica quando viene pubblicato un nuovo documento nell\'archivio',
                'permission' => Permission::VIEW_ARCHIVE_DOCUMENTS,
            ],
        ],
        'admin' => [
            NotificationType::NEW_USER->value => [
                'label' => 'Nuovo utente registrato',
                'description' => 'Ricevi una notifica quando un nuovo utente si registra',
                'permission' => Permission::VIEW_USERS,
            ],
        ],
    ],
];

