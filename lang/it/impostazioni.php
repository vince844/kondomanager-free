<?php

return [
    /* ------------------------------------------------------------------
     | Backend notifications
     | ------------------------------------------------------------------ */
    'success_update_notification_preferences' => 'Le tue preferenze di notifica sono state aggiornate con successo',
    'error_update_notification_preferences'   => 'Si è verificato un errore nel tentativo di aggiornare le tue preferenze di notifica',
    'success_save_general_settings'           => 'Le impostazioni generali sono state salvate con successo',
    'error_save_general_settings'             => 'Si è verificato un errore durante il salvataggio delle impostazioni generali',

    /* ------------------------------------------------------------------
     | Front‑end strings (headings, titles, descriptions)
     | ------------------------------------------------------------------ */
    'header' => [
        'settings_head'                => 'Settings',
        'settings_title'               => 'Impostazioni applicazione',
        'settings_description'         => 'Di seguito un elenco di tutte le impostazioni configurabili per l\'applicazione',
        'general_settings_title'       => 'Impostazioni generali',
        'general_settings_description' => 'On this page you can manage the general settings of the application',
    ],

    /* ------------------------------------------------------------------
     | Labels
     | ------------------------------------------------------------------ */
    'label' => [
        'manage'    => 'Gestisci',
        'settings'  => 'Impostazioni',
    ],

    /* ------------------------------------------------------------------
     | Empty‑state / dialog messages
     | ------------------------------------------------------------------ */
    'dialogs' => [
        'general_settings_title'        => 'Impostazioni generali',
        'general_settings_description'  => 'Impostazioni generali di configurazione dell\'applicazione',
        'users_settings_title'          => 'Gestione utenti',
        'users_settings_description'    => 'Impostazioni di gestione degli utenti, ruoli e permessi',
        'backups_settings_title'        => 'Gestione backups',
        'backups_settings_description'  => 'Impostazioni di gestione dei backups',
        'language_settings_title'       => 'Lingua applicazione',
        'language_settings_description' => 'Seleziona la lingua principale per l\'applicazione',
        'default_building_title'        => 'Apri condominio al login',
        'default_building_description'  => 'Se attivato, l\'utente verrà reindirizzato direttamente al condominio selezionato',
        'select_building_title'         => 'Condominio predefinito',
        'select_building_description'   => 'Seleziona il condominio da aprire automaticamente il gestionale dopo il login',
        'user_registration_title'       => 'Abilita registrazione utenti',
        'user_registration_description' => 'Se attivato, gli utenti possono registrarsi dalla home page',
    ],

    /* ------------------------------------------------------------------
     | Placeholders for inputs
     | ------------------------------------------------------------------ */
    'placeholder' => [
        'select_building' => 'Seleziona condominio',
        'select_language' => 'Seleziona lingua',
        'search_settings' => 'Filtrar configurações...',
        'language' => [
            'it' => 'Italiano',
            'en' => 'Inglese',
            'pt' => 'Portoghese',
        ],
    ],

    /* ------------------------------------------------------------------
     | Action buttons (toolbar, card actions, etc.)
     | ------------------------------------------------------------------ */
    'actions' => [
        'save_settings' => 'Salva impostazioni',
    ],
];