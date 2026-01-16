<?php

namespace App\Enums;

enum TipoCassa: string
{
    case CONTANTI = 'contanti';
    case BANCA = 'banca';
    case FONDO = 'fondo';
    case VIRTUALE = 'virtuale';

    /**
     * Restituisce il ruolo contabile associato al tipo di cassa.
     */
    public function getRuoloContabile(): string
    {
        return match($this) {
            self::CONTANTI => 'cassa_contanti',
            self::BANCA    => 'conto_bancario',
            self::FONDO    => 'fondo_riserva',
            self::VIRTUALE => 'gateway_pagamento',
        };
    }
    
    /**
     * Helper per ottenere il valore di default se non si trova corrispondenza
     * (anche se la validazione dovrebbe impedirlo)
     */
    public static function getRuoloFromValue(string $value): string 
    {
        return self::tryFrom($value)?->getRuoloContabile() ?? 'cassa_generica';
    }
}