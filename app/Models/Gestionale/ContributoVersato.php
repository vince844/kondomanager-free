<?php

namespace App\Models\Gestionale;

use App\Models\Anagrafica;
use App\Models\Condominio;
use App\Models\Immobile;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Collection;

/**
 * Quanto un'unità immobiliare ha GIÀ VERSATO verso una spesa o un fondo.
 *
 * È il ledger che permette al motore di riparto di chiedere solo il residuo:
 * senza di esso una fattura da €1.100 su un'opera già coperta per €1.000 verrebbe
 * ripartita per intero, richiedendo una seconda volta denaro già incassato.
 *
 * @see docs/fondo_accantonato_e_quadratura_sp.md §4
 */
class ContributoVersato extends Model
{
    protected $table = 'contributi_versati';

    protected $fillable = [
        'condominio_id',
        'target_type',
        'target_id',
        'immobile_id',
        'anagrafica_id',
        'importo_cents',
        'natura',
        'origine',
        'descrizione',
    ];

    protected $casts = [
        'importo_cents' => 'integer',
    ];

    /** Vincolo di destinazione deliberato (fondo speciale art. 1135, riserva). */
    public const NATURA_FONDO_VINCOLATO = 'fondo_vincolato';

    /** Riscosso e non speso, senza vincolo: conguagliabile. */
    public const NATURA_AVANZO = 'avanzo';

    public function target(): MorphTo
    {
        return $this->morphTo();
    }

    public function condominio(): BelongsTo
    {
        return $this->belongsTo(Condominio::class);
    }

    public function immobile(): BelongsTo
    {
        return $this->belongsTo(Immobile::class);
    }

    public function anagrafica(): BelongsTo
    {
        return $this->belongsTo(Anagrafica::class);
    }

    /**
     * Totale già versato per ciascuna unità verso un target (voce di spesa o gestione).
     *
     * È la funzione che il motore di riparto interroga per il netting.
     *
     * @return Collection<int,int> mappa immobile_id => centesimi già versati
     */
    public static function perImmobile(string $targetType, int $targetId): Collection
    {
        return static::query()
            ->where('target_type', $targetType)
            ->where('target_id', $targetId)
            ->selectRaw('immobile_id, SUM(importo_cents) as totale')
            ->groupBy('immobile_id')
            ->pluck('totale', 'immobile_id')
            ->map(fn ($v) => (int) $v);
    }
}
