<?php

namespace App\Settings;

use Spatie\LaravelSettings\Settings;

/**
 * Il tasso legale degli anni che `config/fiscale.php` non ha ancora, scritto a mano dallo studio: uno per anno, per tutti i condomini. Lo legge e lo
 * scrive {@see \App\Services\Gestionale\Accessori\TassoLegaleAMano}; la forma di ogni riga la dice quella classe. (Senza il tipo degli elementi nel
 * commento: la libreria delle impostazioni lo leggerebbe come un tipo da convertire.)
 */
class TassoLegaleSettings extends Settings
{
    public array $scritti_a_mano = [];

    public static function group(): string
    {
        return 'tasso_legale';
    }
}
