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
    case NEW_TICKET = 'new_ticket';
    case APPROVED_TICKET = 'approved_ticket';
    case NEW_USER = 'new_user';

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
            self::NEW_TICKET => 'Nuova segnalazione',
            self::APPROVED_TICKET => 'Segnalazione approvata',
            self::NEW_USER => 'Nuovo utente registrato',
        };
    }
}
