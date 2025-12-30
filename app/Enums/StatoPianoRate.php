<?php

namespace App\Enums;

enum StatoPianoRate: string
{
    case BOZZA = 'bozza';
    case APPROVATO = 'approvato';

    // Metodo utile per il frontend (badge colore)
    public function color(): string
    {
        return match($this) {
            self::BOZZA => 'gray',
            self::APPROVATO => 'emerald',
        };
    }

    public function label(): string
    {
        return match($this) {
            self::BOZZA => 'Bozza',
            self::APPROVATO => 'Approvato',
        };
    }
}