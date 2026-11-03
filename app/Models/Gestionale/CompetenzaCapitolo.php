<?php

namespace App\Models\Gestionale;

use App\Support\InsiemePeriodi;
use App\Support\PeriodoCompetenza;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * Un tratto della competenza di un capitolo ordinario in un piano rate (tabella `competenze_capitolo`).
 *
 * Decisione 20 del progetto `docs/subentro_e_competenza_temporale.md`: la competenza del capitolo è un
 * **insieme di tratti**, non un intervallo — il riscaldamento in un esercizio solare è 01/01–15/04 e
 * 15/10–31/12. Ogni riga è un tratto; la riga madre è la pivot `piano_rate_capitoli` (piano × conto).
 * Nessuna riga = periodo della gestione, poi dell'esercizio (cascata di D3, ramo ordinario).
 *
 * L'insieme si costruisce con {@see insiemePer()}: è `InsiemePeriodi` a ordinare i tratti e a rifiutare
 * le sovrapposizioni, così la regola vive in un posto solo.
 */
class CompetenzaCapitolo extends Model
{
    protected $table = 'competenze_capitolo';

    protected $fillable = ['piano_rate_capitolo_id', 'dal', 'al', 'ordine'];

    protected $casts = [
        'dal' => 'date:Y-m-d',
        'al' => 'date:Y-m-d',
        'ordine' => 'integer',
    ];

    /** I tratti di un capitolo come insieme, o `null` se il capitolo non ne dichiara. */
    public static function insiemePer(int $pianoRateCapitoloId): ?InsiemePeriodi
    {
        $tratti = static::query()
            ->where('piano_rate_capitolo_id', $pianoRateCapitoloId)
            ->orderBy('dal')
            ->get();

        return static::insiemeDa($tratti);
    }

    /** @param Collection<int, CompetenzaCapitolo> $tratti */
    public static function insiemeDa(Collection $tratti): ?InsiemePeriodi
    {
        if ($tratti->isEmpty()) {
            return null;
        }

        return new InsiemePeriodi(...$tratti->map(
            fn (CompetenzaCapitolo $t) => new PeriodoCompetenza($t->dal->toDateString(), $t->al->toDateString())
        )->all());
    }
}
