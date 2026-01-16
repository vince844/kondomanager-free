<?php

namespace App\Helpers;

class MoneyHelper
{
    public static function toCents($input): int
    {
        if (is_null($input) || $input === '') {
            return 0;
        }

        // CASO 1: Arriva un numero puro (es. 100 o 120.50)
        // Succede se cambi libreria JS o se passi dati da API
        if (is_numeric($input)) {
            return (int) round((float) $input * 100);
        }

        // CASO 2: Arriva la stringa mascherata (es. "1.200,50" o "-100,00")
        // Questa è la logica che volevi tu
        
        // 1. Rimuoviamo i punti delle migliaia
        $clean = str_replace('.', '', $input);
        
        // 2. Sostituiamo la virgola col punto per renderlo "capibile" da PHP
        $clean = str_replace(',', '.', $clean);

        // 3. Ora abbiamo "1200.50", moltiplichiamo per 100
        return (int) round((float) $clean * 100);
    }

    public static function format(int $cents, bool $withSymbol = true): string
    {
        if ($cents === 0) {
            return $withSymbol ? '€ 0,00' : '0,00';
        }
        
        $formatted = number_format($cents / 100, 2, ',', '.');
        return $withSymbol ? "€ {$formatted}" : $formatted;
    }
    
    // Utile per il frontend (edit form)
    public static function fromCents(int $cents): float
    {
        return round($cents / 100, 2);
    }

    public static function formatField($model, string $fieldName, bool $withSymbol = true): string
    {
        $cents = $model->{$fieldName} ?? 0;
        return self::format($cents, $withSymbol);
    }
}