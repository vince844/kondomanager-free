<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Enums;

/**
 * Enum NotificationType
 *
 * Represents the different types of notifications in the system.
 *
 * @package App\Enums
 */
enum NotificationType: string
{
    /**
     * I nomi di prima delle due preferenze «nascosto» (decisione 478 (2)): le migrazioni già rilasciate li usano ancora. Sono la stessa
     * preferenza, con lo stesso valore.
     */
    public const COMMENT_DELETED = self::COMMENT_HIDDEN;

    public const COMMUNICATION_COMMENT_DELETED = self::COMMUNICATION_COMMENT_HIDDEN;

    case NEW_COMMUNICATION = 'new_communication';
    case APPROVED_COMMUNICATION = 'approved_communication';
    case UPDATED_COMMUNICATION = 'updated_communication';
    case SCHEDULED_COMMUNICATION_PUBLISHED = 'scheduled_communication_published';
    case NEW_TICKET = 'new_ticket';
    case APPROVED_TICKET = 'approved_ticket';
    case UPDATED_TICKET = 'updated_ticket';
    case TICKET_ASSIGNED = 'ticket_assigned';
    case NEW_USER = 'new_user';
    case NEW_ARCHIVE_DOCUMENT = 'new_archive_document';
    case APPROVED_ARCHIVE_DOCUMENT = 'approved_archive_document';
    case UPDATED_ARCHIVE_DOCUMENT = 'updated_archive_document';
    
    // Commenti
    case NEW_COMMENT = 'new_comment';
    case COMMENT_UNDER_MODERATION = 'comment_under_moderation';
    case COMMENT_EDITED_UNDER_MODERATION = 'comment_edited_under_moderation';
    case COMMENT_APPROVED = 'comment_approved';
    // Decisione 478 (2): il valore storico `comment_deleted` è la preferenza «Commento nascosto» (stessa riga nel database, cambia solo il nome);
    // quella di «Commento eliminato» è nuova.
    case COMMENT_HIDDEN = 'comment_deleted';
    case COMMENT_REMOVED = 'comment_removed';

    // Commenti comunicazioni
    case NEW_COMMUNICATION_COMMENT = 'new_communication_comment';
    case COMMUNICATION_COMMENT_UNDER_MODERATION = 'communication_comment_under_moderation';
    case COMMUNICATION_COMMENT_EDITED_UNDER_MODERATION = 'communication_comment_edited_under_moderation';
    case COMMUNICATION_COMMENT_APPROVED = 'communication_comment_approved';
    case COMMUNICATION_COMMENT_HIDDEN = 'communication_comment_deleted';
    case COMMUNICATION_COMMENT_REMOVED = 'communication_comment_removed';

    // Allegati
    case NEW_ATTACHMENT = 'new_attachment';
    case ATTACHMENT_UNDER_MODERATION = 'attachment_under_moderation';
    case ATTACHMENT_APPROVED = 'attachment_approved';
    case ATTACHMENT_DELETED = 'attachment_deleted';

    // Gli errori (1.12.0-beta.14, decisione 701): un condòmino o un collaboratore ha avvisato lo studio di un errore dalla pagina d'errore.
    case ERROR_REPORTED = 'error_reported';

    /**
     * Get a human-readable label for the notification type.
     *
     * @return string
     */
    public function label(): string
    {
        return match ($this) {
            self::NEW_COMMUNICATION => 'Nuova comunicazione',
            self::APPROVED_COMMUNICATION => 'Comunicazione approvata',
            self::UPDATED_COMMUNICATION => 'Comunicazione aggiornata',
            self::SCHEDULED_COMMUNICATION_PUBLISHED => 'Comunicazione programmata pubblicata',
            self::NEW_TICKET => 'Nuova segnalazione',
            self::APPROVED_TICKET => 'Segnalazione approvata',
            self::UPDATED_TICKET => 'Segnalazione aggiornata',
            self::TICKET_ASSIGNED => 'Segnalazione assegnata',
            self::NEW_USER => 'Nuovo utente registrato',
            self::NEW_ARCHIVE_DOCUMENT => 'Nuovo documento in archivio',
            self::APPROVED_ARCHIVE_DOCUMENT => 'Nuovo documento in archivio approvato',
            self::UPDATED_ARCHIVE_DOCUMENT => 'Documento in archivio aggiornato',
            
            // Commenti
            self::NEW_COMMENT => 'Nuovo commento (segnalazioni)',
            self::COMMENT_UNDER_MODERATION => 'Commento da moderare (segnalazioni)',
            self::COMMENT_EDITED_UNDER_MODERATION => 'Commento modificato da moderare (segnalazioni)',
            self::COMMENT_APPROVED => 'Commento approvato (segnalazioni)',
            self::COMMENT_HIDDEN => 'Commento nascosto (segnalazioni)',
            self::COMMENT_REMOVED => 'Commento eliminato (segnalazioni)',

            // Commenti comunicazioni
            self::NEW_COMMUNICATION_COMMENT => 'Nuovo commento (comunicazioni)',
            self::COMMUNICATION_COMMENT_UNDER_MODERATION => 'Commento da moderare (comunicazioni)',
            self::COMMUNICATION_COMMENT_EDITED_UNDER_MODERATION => 'Commento modificato da moderare (comunicazioni)',
            self::COMMUNICATION_COMMENT_APPROVED => 'Commento approvato (comunicazioni)',
            self::COMMUNICATION_COMMENT_HIDDEN => 'Commento nascosto (comunicazioni)',
            self::COMMUNICATION_COMMENT_REMOVED => 'Commento eliminato (comunicazioni)',

            // Allegati
            self::NEW_ATTACHMENT => 'Nuovo allegato',
            self::ATTACHMENT_UNDER_MODERATION => 'Allegato in attesa di moderazione',
            self::ATTACHMENT_APPROVED => 'Allegato approvato',
            self::ATTACHMENT_DELETED => 'Allegato eliminato',

            // Gli errori
            self::ERROR_REPORTED => 'Errore avvisato',
        };
    }
}
