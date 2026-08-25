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
        'liquidita_stato',
        'cassa_id',
    ];

    protected $casts = [
        'importo_cents' => 'integer',
    ];

    /** Vincolo di destinazione deliberato (fondo speciale art. 1135, riserva). */
    public const NATURA_FONDO_VINCOLATO = 'fondo_vincolato';

    /** Riscosso e non speso, senza vincolo: conguagliabile. */
    public const NATURA_AVANZO = 'avanzo';

    /** I soldi sono ancora fermi: portati a giornale su una cassa/fondo esistente. */
    public const LIQUIDITA_REGISTRATA_IN_CASSA = 'registrata_in_cassa';

    /** I soldi sono già usciti come acconto al fornitore, prima di Kondomanager. */
    public const LIQUIDITA_GIA_SPESO_ACCONTO = 'gia_speso_acconto';

    /**
     * I soldi ci sono, sono in banca, **e il saldo di apertura della cassa li comprende già**.
     *
     * ⚠️ **Perché serviva un terzo stato, e non bastava un avviso.** Con i due soli stati
     * precedenti, chi apriva la cassa con il saldo dell'estratto conto — che è l'ordine di
     * lavoro corretto, ed è quello che la guida raccomanda — e poi dichiarava il già versato
     * come «ancora fermi» si ritrovava quei soldi contati **due volte**: una dentro l'apertura,
     * una nell'accantonamento. Misurato sul caso di prova: cassa a giornale € 5.000,00 contro
     * € 3.000,00 di estratto conto reale.
     *
     * Il sistema non può indovinare quale dei due ordini l'utente abbia seguito, ed entrambi
     * sono legittimi: se il già versato si dichiara **prima** di aprire la cassa, accreditare è
     * giusto. Questo stato dice esattamente l'altro caso, e **non produce alcuna scrittura**:
     * il vincolo resta registrato per il riparto, la liquidità è già a giornale da altrove.
     */
    public const LIQUIDITA_GIA_IN_APERTURA = 'gia_in_apertura';

    public function target(): MorphTo
    {
        return $this->morphTo();
    }

    public function condominio(): BelongsTo
    {
        return $this->belongsTo(Condominio::class);
    }

    public function cassa(): BelongsTo
    {
        return $this->belongsTo(Cassa::class);
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
