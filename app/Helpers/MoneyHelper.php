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

    /**
     * Un importo spezzato come lo vuole il modello F24: euro da una parte, centesimi
     * dall'altra.
     *
     * Sul modello ministeriale la colonna degli importi è **divisa in due caselle** da una
     * virgola prestampata, e le avvertenze dell'Agenzia sono esplicite su due punti che
     * `format()` viola entrambi: i centesimi vanno «sempre indicati con le prime due cifre
     * decimali anche nel caso che tali cifre siano pari a zero» — quindi mai `52,7` né `52` —
     * e la casella degli euro non ospita separatori di migliaia, perché è una griglia di
     * caselle e non un numero scritto.
     *
     * `€ 1.060,00` a schermo diventa quindi `1060` + `00` sul modello. Sono due
     * rappresentazioni dello stesso dato, non due arrotondamenti: la sorgente resta l'intero
     * in centesimi e qui non si arrotonda niente.
     *
     * Il valore assoluto è voluto: le due colonne del modello (debito e credito) portano già
     * il verso, e un meno stampato dentro la casella dell'importo renderebbe la delega
     * irricevibile.
     *
     * @return array{euro: string, centesimi: string}
     */
    public static function perModelloF24(int $cents): array
    {
        $assoluto = abs($cents);

        return [
            'euro' => (string) intdiv($assoluto, 100),
            'centesimi' => str_pad((string) ($assoluto % 100), 2, '0', STR_PAD_LEFT),
        ];
    }
}