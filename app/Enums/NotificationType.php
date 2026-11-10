<?php

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
    case NEW_COMMUNICATION = 'new_communication';
    case APPROVED_COMMUNICATION = 'approved_communication';
    case UPDATED_COMMUNICATION = 'updated_communication';
    case NEW_TICKET = 'new_ticket';
    case APPROVED_TICKET = 'approved_ticket';
    case UPDATED_TICKET = 'updated_ticket';
    case NEW_USER = 'new_user';
    case NEW_ARCHIVE_DOCUMENT = 'new_archive_document';
    case APPROVED_ARCHIVE_DOCUMENT = 'approved_archive_document';
    case UPDATED_ARCHIVE_DOCUMENT = 'updated_archive_document';
    
    // Commenti
    case NEW_COMMENT = 'new_comment';
    case COMMENT_UNDER_MODERATION = 'comment_under_moderation';
    case COMMENT_APPROVED = 'comment_approved';
    case COMMENT_DELETED = 'comment_deleted';

    // Allegati
    case NEW_ATTACHMENT = 'new_attachment';
    case ATTACHMENT_UNDER_MODERATION = 'attachment_under_moderation';
    case ATTACHMENT_APPROVED = 'attachment_approved';
    case ATTACHMENT_DELETED = 'attachment_deleted';

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
            self::NEW_TICKET => 'Nuova segnalazione',
            self::APPROVED_TICKET => 'Segnalazione approvata',
            self::UPDATED_TICKET => 'Segnalazione aggiornata',
            self::NEW_USER => 'Nuovo utente registrato',
            self::NEW_ARCHIVE_DOCUMENT => 'Nuovo documento in archivio',
            self::APPROVED_ARCHIVE_DOCUMENT => 'Nuovo documento in archivio approvato',
            self::UPDATED_ARCHIVE_DOCUMENT => 'Documento in archivio aggiornato',
            
            // Commenti
            self::NEW_COMMENT => 'Nuovo commento',
            self::COMMENT_UNDER_MODERATION => 'Commento in attesa di moderazione',
            self::COMMENT_APPROVED => 'Commento approvato',
            self::COMMENT_DELETED => 'Commento eliminato',

            // Allegati
            self::NEW_ATTACHMENT => 'Nuovo allegato',
            self::ATTACHMENT_UNDER_MODERATION => 'Allegato in attesa di moderazione',
            self::ATTACHMENT_APPROVED => 'Allegato approvato',
            self::ATTACHMENT_DELETED => 'Allegato eliminato',
        };
    }
}
