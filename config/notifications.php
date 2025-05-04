<?php

use App\Enums\NotificationType;

return [
    'types' => [
        'common' => [
            NotificationType::NEW_COMMUNICATION->value => [
                'label' => 'Nuova comunicazione bacheca',
                'description' => 'Ricevi una notifica quando viene creata una nuova comunicazione',
            ],
            NotificationType::NEW_TICKET->value => [
                'label' => 'Nuova segnalazione guasto',
                'description' => 'Ricevi una notifica quando viene creata una nuova segnalazione guasto',
            ],
        ],
        'admin' => [
            NotificationType::NEW_USER->value => [
                'label' => 'Nuovo utente registrato',
                'description' => 'Ricevi una notifica quando un nuovo utente si registra',
            ],
        ],
    ],
];

