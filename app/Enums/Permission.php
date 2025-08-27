<?php

namespace App\Enums;

/**
 * Enum Permission
 *
 * Defines all possible permissions in the system with associated descriptions.
 * Each permission governs access to specific features or actions in the application.
 *
 * @package App\Enums
 */
enum Permission: string
{
    // Access
    case ACCESS_ADMIN_PANEL = 'Accesso pannello amministratore';

    // Utenti
    case CREATE_USERS = 'Crea utenti';
    case EDIT_USERS = 'Modifica utenti';
    case DELETE_USERS = 'Elimina utenti';
    case VIEW_USERS = 'Visualizza utenti';

    // Anagrafiche
    case CREATE_ANAGRAFICHE = 'Crea anagrafiche';
    case EDIT_ANAGRAFICHE = 'Modifica anagrafiche';
    case DELETE_ANAGRAFICHE = 'Elimina anagrafiche';
    case VIEW_ANAGRAFICHE = 'Visualizza anagrafiche';

    // Condomini
    case CREATE_CONDOMINI = 'Crea condomini';
    case EDIT_CONDOMINI = 'Modifica condomini';
    case DELETE_CONDOMINI = 'Elimina condomini';
    case VIEW_CONDOMINI = 'Visualizza condomini';

    // Comunicazioni
    case CREATE_COMUNICAZIONI = 'Crea comunicazioni';
    case APPROVE_COMUNICAZIONI = 'Approva comunicazioni';
    case PUBLISH_COMUNICAZIONI = 'Pubblica comunicazioni';
    case PUBLISH_OWN_COMUNICAZIONI = 'Pubblica proprie comunicazioni';
    case EDIT_COMUNICAZIONI = 'Modifica comunicazioni';
    case EDIT_OWN_COMUNICAZIONI = 'Modifica proprie comunicazioni';
    case DELETE_COMUNICAZIONI = 'Elimina comunicazioni';
    case DELETE_OWN_COMUNICAZIONI = 'Elimina proprie comunicazioni';
    case VIEW_COMUNICAZIONI = 'Visualizza comunicazioni';
    case VIEW_OWN_COMUNICAZIONI = 'Visualizza proprie comunicazioni';

    // Commenti comunicazioni
    case COMMENT_COMUNICAZIONI = 'Commenta comunicazioni';
    case PUBLISH_COMMENTS_COMUNICAZIONI = 'Pubblica commenti comunicazioni';
    case APPROVE_COMMENTS_COMUNICAZIONI = 'Approva commenti comunicazioni';
    case EDIT_COMMENTS_COMUNICAZIONI = 'Modifica commenti comunicazioni';
    case DELETE_COMMENTS_COMUNICAZIONI = 'Elimina commenti comunicazioni';
    case VIEW_COMMENTS_COMUNICAZIONI = 'Visualizza commenti comunicazioni';

    // Segnalazioni
    case CREATE_SEGNALAZIONI = 'Crea segnalazioni';
    case APPROVE_SEGNALAZIONI = 'Approva segnalazioni';
    case PUBLISH_SEGNALAZIONI = 'Pubblica segnalazioni';
    case EDIT_SEGNALAZIONI = 'Modifica segnalazioni';
    case EDIT_OWN_SEGNALAZIONI = 'Modifica proprie segnalazioni';
    case DELETE_SEGNALAZIONI = 'Elimina segnalazioni';
    case DELETE_OWN_SEGNALAZIONI = 'Elimina proprie segnalazioni';
    case VIEW_SEGNALAZIONI = 'Visualizza segnalazioni';
    case VIEW_OWN_SEGNALAZIONI = 'Visualizza proprie segnalazioni';

    // Commenti segnalazioni
    case COMMENT_SEGNALAZIONI = 'Commenta segnalazioni';
    case EDIT_COMMENTS_SEGNALAZIONI = 'Modifica commenti segnalazioni';
    case EDIT_OWN_COMMENTS_SEGNALAZIONI = 'Modifica propri commenti segnalazioni';
    case DELETE_COMMENTS_SEGNALAZIONI = 'Elimina commenti segnalazioni';
    case DELETE_OWN_COMMENTS_SEGNALAZIONI = 'Elimina propri commenti segnalazioni';
    case PUBLISH_COMMENTS_SEGNALAZIONI = 'Pubblica commenti segnalazioni';
    case APPROVE_COMMENTS_SEGNALAZIONI = 'Approva commenti segnalazioni';
    case VIEW_COMMENTS_SEGNALAZIONI = 'Visualizza commenti segnalazioni';

    // Documenti archivio
    case VIEW_ARCHIVE_DOCUMENTS = 'Visualizza documenti archivio';
    case CREATE_ARCHIVE_DOCUMENTS = 'Crea documenti archivio';
    case DELETE_ARCHIVE_DOCUMENTS = 'Elimina documenti archvio';
    case EDIT_ARCHIVE_DOCUMENTS = 'Modifica documenti archivio';
    case EDIT_OWN_ARCHIVE_DOCUMENTS = 'Modifica propri documenti archivio';
    case PUBLISH_ARCHIVE_DOCUMENTS = 'Pubblica documenti archivio';
    case APPROVE_ARCHIVE_DOCUMENTS = 'Approva documenti archivio';
    case DELETE_OWN_ARCHIVE_DOCUMENTS = 'Elimina propri documenti archivio';

    // Eventi
    case VIEW_EVENTS = 'Visualizza scadenze in agenda';
    case CREATE_EVENTS = 'Crea scadenza in agenda';
    case DELETE_EVENTS = 'Elimina scadenza in agenda';
    case EDIT_EVENTS = 'Modifica scadenza in agenda';
    case EDIT_OWN_EVENTS = 'Modifica proprie scadenze in agenda';
    case PUBLISH_EVENTS = 'Pubblica scadenze in agenda';
    case APPROVE_EVENTS = 'Approva scadenze in agenda';
    case DELETE_OWN_EVENTS = 'Elimina proprie scadenza in agenda';

    /**
     * Get a human-readable description for the permission.
     *
     * @return string Description of what the permission allows.
     */
    public function description(): string
    {
        return match ($this) {
            // Access
            self::ACCESS_ADMIN_PANEL => 'Permette di dare accesso al layout amministratore',

            // Utenti
            self::CREATE_USERS => 'Permette di creare nuovi utenti',
            self::EDIT_USERS => 'Permette di modificare gli utenti registrati',
            self::DELETE_USERS => 'Permette di eliminare gli utenti registrati',
            self::VIEW_USERS => 'Permette di visualizzare gli utenti registrati',

            // Anagrafiche
            self::CREATE_ANAGRAFICHE => 'Permette di creare nuove anagrafiche',
            self::EDIT_ANAGRAFICHE => 'Permette di modificare le anagrafiche registrate',
            self::DELETE_ANAGRAFICHE => 'Permette di eliminare le anagrafiche registrate',
            self::VIEW_ANAGRAFICHE => 'Permette di visualizzare le anagrafiche registrate',

            // Condomini
            self::CREATE_CONDOMINI => 'Permette di creare nuovi condomini',
            self::EDIT_CONDOMINI => 'Permette di modificare i condomini registrati',
            self::DELETE_CONDOMINI => 'Permette di eliminare i condomini registrati',
            self::VIEW_CONDOMINI => 'Permette di visualizzare i condomini registrati',

            // Comunicazioni
            self::CREATE_COMUNICAZIONI => 'Permette di creare comunicazioni in bacheca',
            self::APPROVE_COMUNICAZIONI => 'Permette di approvare comunicazioni in bacheca',
            self::PUBLISH_COMUNICAZIONI => 'Permette di pubblicare le comunicazioni in bacheca',
            self::PUBLISH_OWN_COMUNICAZIONI => 'Permette di pubblicare solo le proprie comunicazioni in bacheca',
            self::EDIT_COMUNICAZIONI => 'Permette di modificare le comunicazioni in bacheca',
            self::EDIT_OWN_COMUNICAZIONI => 'Permette di modificare solo le proprie comunicazioni in bacheca',
            self::DELETE_COMUNICAZIONI => 'Permette di eliminare le comunicazioni in bacheca',
            self::DELETE_OWN_COMUNICAZIONI => 'Permette di eliminare solo le proprie comunicazioni in bacheca',
            self::VIEW_COMUNICAZIONI => 'Permette di visualizzare le comunicazioni in bacheca',
            self::VIEW_OWN_COMUNICAZIONI => 'Permette di visualizzare solo le proprie comunicazioni in bacheca',

            // Commenti comunicazioni
            self::COMMENT_COMUNICAZIONI => 'Permette di commentare le comunicazioni in bacheca',
            self::PUBLISH_COMMENTS_COMUNICAZIONI => 'Permette di pubblicare commenti lasciati in comunicazione in bacheca',
            self::APPROVE_COMMENTS_COMUNICAZIONI => 'Permette di approvare commenti lasciati in comunicazione in bacheca',
            self::EDIT_COMMENTS_COMUNICAZIONI => 'Permette di modificare commenti lasciati in comunicazione in bacheca',
            self::DELETE_COMMENTS_COMUNICAZIONI => 'Permette di eliminare un commento lasciato in comunicazione in bacheca',
            self::VIEW_COMMENTS_COMUNICAZIONI => 'Permette di visualizzare i commenti delle comunicazioni in bacheca',

            // Segnalazioni
            self::CREATE_SEGNALAZIONI => 'Permette di creare una segnalazione di guasto',
            self::APPROVE_SEGNALAZIONI => 'Permette di approvare una segnalazione di guasto',
            self::PUBLISH_SEGNALAZIONI => 'Permette di pubblicare una segnalazione di guasto',
            self::EDIT_SEGNALAZIONI => 'Permette di modificare una segnalazione di guasto',
            self::EDIT_OWN_SEGNALAZIONI => 'Permette di modificare solo le proprie segnalazioni di guasto',
            self::DELETE_SEGNALAZIONI => 'Permette di eliminare una segnalazione di guasto',
            self::DELETE_OWN_SEGNALAZIONI => 'Permette di eliminare solo le proprie segnalazioni di guasto',
            self::VIEW_SEGNALAZIONI => 'Permette di visualizzare una segnalazione di guasto',
            self::VIEW_OWN_SEGNALAZIONI => 'Permette di visualizzare solo le proprie segnalazioni di guasto',

            // Commenti segnalazioni
            self::COMMENT_SEGNALAZIONI => 'Permette di commentare le segnalazioni guasto',
            self::EDIT_COMMENTS_SEGNALAZIONI => 'Permette di modificare commenti lasciati in una segnalazione guasto',
            self::EDIT_OWN_COMMENTS_SEGNALAZIONI => 'Permette di modificare solo i propri commenti lasciati in una segnalazione guasto',
            self::DELETE_COMMENTS_SEGNALAZIONI => 'Permette di eliminare un commento lasciato in una segnalazione guasto',
            self::DELETE_OWN_COMMENTS_SEGNALAZIONI => 'Permette di eliminare solo i propri commenti lasciati in una segnalazione guasto',
            self::PUBLISH_COMMENTS_SEGNALAZIONI => 'Permette di pubblicare commenti lasciati in una segnalazione guasto',
            self::APPROVE_COMMENTS_SEGNALAZIONI => 'Permette di approvare commenti lasciati in una segnalazione guasto',
            self::VIEW_COMMENTS_SEGNALAZIONI => 'Permette di visualizzare commenti lasciati in una segnalazione guasto',

            // Documenti archivio
            self::VIEW_ARCHIVE_DOCUMENTS => 'Permette di visualizzare i documenti in archivio',
            self::CREATE_ARCHIVE_DOCUMENTS => 'Permette di creare nuovi documenti in archivio',
            self::DELETE_ARCHIVE_DOCUMENTS => 'Permette di eliminare documenti in archivio',
            self::EDIT_ARCHIVE_DOCUMENTS => 'Permette di modificare documenti in archivio',
            self::EDIT_OWN_ARCHIVE_DOCUMENTS => 'Permette di modificare solo i propri documenti in archivio',
            self::PUBLISH_ARCHIVE_DOCUMENTS => 'Permette di pubblicare documenti in archivio',
            self::APPROVE_ARCHIVE_DOCUMENTS => 'Permette di approvare documenti in archivio',
            self::DELETE_OWN_ARCHIVE_DOCUMENTS => 'Permette di eliminare i propri documenti in archivio',

            // Eventi
            self::VIEW_EVENTS => 'Permette di visualizzare le scadenze in agenda',
            self::CREATE_EVENTS => 'Permette di creare nuove scadenze in agenda',
            self::DELETE_EVENTS => 'Permette di eliminare scadenze in agenda',
            self::EDIT_EVENTS => 'Permette di modificare scadenze in agenda',
            self::EDIT_OWN_EVENTS => 'Permette di modificare solo le proprie scadenze in agenda',
            self::PUBLISH_EVENTS => 'Permette di pubblicare scadenze in agenda',
            self::APPROVE_EVENTS => 'Permette di approvare scadenze in agenda',
            self::DELETE_OWN_EVENTS => 'Permette di eliminare le proprie scadenze in agenda',
        };
    }
}
