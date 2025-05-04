<?php

namespace App\Enums;

enum NotificationType: string
{
    case NEW_COMMUNICATION = 'new_communication';
    case NEW_TICKET = 'new_ticket';
    case NEW_USER = 'new_user';
}
