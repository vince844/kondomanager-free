<?php

namespace App\Enums;

enum Permission: string
{
     // Access
    case ACCESS_ADMIN_PANEL = 'Accesso pannello amministratore';

    // Utenti
    case CREATE_USERS = 'Crea utenti';
    case EDIT_USERS = 'Modifica utenti';
    case DELETE_USERS = 'Elimina utenti';
    case VIEW_USERS = 'Visualizza utenti';

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

    // Commenti Comunicazioni
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

    // Commenti Segnalazioni
    case COMMENT_SEGNALAZIONI = 'Commenta segnalazioni';
    case EDIT_COMMENTS_SEGNALAZIONI = 'Modifica commenti segnalazioni';
    case EDIT_OWN_COMMENTS_SEGNALAZIONI = 'Modifica propri commenti segnalazioni';
    case DELETE_COMMENTS_SEGNALAZIONI = 'Elimina commenti segnalazioni';
    case DELETE_OWN_COMMENTS_SEGNALAZIONI = 'Elimina propri commenti segnalazioni';
    case PUBLISH_COMMENTS_SEGNALAZIONI = 'Pubblica commenti segnalazioni';
    case APPROVE_COMMENTS_SEGNALAZIONI = 'Approva commenti segnalazioni';
    case VIEW_COMMENTS_SEGNALAZIONI = 'Visualizza commenti segnalazioni';

    // Docuemnti archivio
    case VIEW_ARCHIVE_DOCUMENTS = 'Visualizza documenti';
    case CREATE_ARCHIVE_DOCUMENTS = 'Crea documenti';

}
