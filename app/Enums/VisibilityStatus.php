<?php

namespace App\Enums;

enum VisibilityStatus: string
{
    case PUBLIC  = 'public';
    case PRIVATE = 'private';
    case HIDDEN  = 'hidden';

    /**
     * Return all enum values as a plain array.
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
