<?php

namespace App\Models\Gestionale;

use App\Models\Anagrafica;
use App\Models\Condominio;
use App\Models\Esercizio;
use App\Models\Immobile;
use Illuminate\Database\Eloquent\Builder;
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
        'esercizio_id',
        'importo_cents',
        'natura',
        'origine',
        'descrizione',
        'liquidita_stato',
        'cassa_id',
    ];

    protected $casts = [
        'importo_cents' => 'integer',
        'esercizio_id'  => 'integer',
    ];

    /**
     * Il già versato che vale nei piani di un esercizio si divide fra tutti i piani della voce, di ogni esercizio: c'è almeno una riga senza
     * esercizio (decisione 183.1, «vuoto vuol dire tutti i piani della voce», il fondo lavori su più anni).
     */
    public const AMBITO_TUTTI_GLI_ESERCIZI = 'tutti';

    /** Il già versato che vale nei piani di un esercizio è tutto di quell'esercizio: si divide fra i soli piani di quell'esercizio. */
    public const AMBITO_ESERCIZIO = 'esercizio';

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

    /** L'esercizio per cui vale (Coda 438): nullo, vale per tutti i piani della voce. */
    public function esercizio(): BelongsTo
    {
        return $this->belongsTo(Esercizio::class);
    }

    /**
     * Le righe che valgono nei piani di un esercizio (Coda 438, decisione 183.1): quelle di quell'esercizio e quelle senza esercizio, che valgono in
     * tutti i piani della voce: una riga «del 2026» vale solo nei piani del 2026. L'esercizio è quello che il programma legge per il piano (il salvato o
     * il dedotto, `CompetenzaDelPiano::esercizioDelPiano`); nullo, cioè un piano che non ha un esercizio nemmeno dedotto (la gestione senza esercizi), dà
     * le sole righe senza esercizio.
     */
    public function scopeValeNellEsercizio(Builder $query, ?int $esercizioId): Builder
    {
        return $query->where(fn (Builder $q) => $q->whereNull('esercizio_id')
            ->when($esercizioId !== null, fn (Builder $q2) => $q2->orWhere('esercizio_id', $esercizioId)));
    }

    /**
     * Fra quali piani si divide il già versato di una voce che vale nei piani di un esercizio: `null` se nessuno vale lì; `AMBITO_TUTTI_GLI_ESERCIZI`
     * se c'è una riga senza esercizio (si divide fra tutti i piani della voce, di ogni esercizio: la guardia del motore conta tutti i piani e tutte
     * le fatture della voce); `AMBITO_ESERCIZIO` se sono tutte righe di quell'esercizio (contano i soli piani e le sole fatture di quell'esercizio).
     */
    public static function ambitoNellEsercizio(int $contoId, ?int $esercizioId): ?string
    {
        $righe = static::query()->where('target_type', Conto::class)->where('target_id', $contoId)->valeNellEsercizio($esercizioId);
        if (! (clone $righe)->exists()) {
            return null;
        }

        return (clone $righe)->whereNull('esercizio_id')->exists() ? self::AMBITO_TUTTI_GLI_ESERCIZI : self::AMBITO_ESERCIZIO;
    }

    /**
     * Totale già versato per ciascuna unità verso un target (voce di spesa o gestione).
     *
     * È la lettura **per unità** che il motore usa quando la riga non dice chi ha versato
     * (`anagrafica_id` nullo, com'è per tutto ciò che la pagina dei contributi ha registrato fin qui).
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

    /**
     * Il già versato verso un target, per unità **e per persona** (decisione 17 del progetto
     * `docs/subentro_e_competenza_temporale.md`, 1.11.0-beta.31).
     *
     * Chi ha versato lo dice `anagrafica_id`: dove c'è, la copertura sconta la quota **di quella
     * persona** — il venditore che ha anticipato € 500 per i lavori non regala lo sconto all'acquirente,
     * si regolano fra loro «per quanto pagato, salvo diverso accordo» (Cass. 11199/2021) — e dove manca
     * (le righe storiche, scritte per unità) resta della unità, come prima. La chiave nulla raccoglie
     * queste ultime.
     *
     * Solo le righe che valgono nei piani dell'esercizio dato (Coda 438, `valeNellEsercizio`): chi legge il già versato per un piano passa
     * l'esercizio del piano.
     *
     * @return array<int, array<int|string, int>> immobile_id => [anagrafica_id|'' => centesimi]
     */
    public static function perImmobileESoggetto(string $targetType, int $targetId, ?int $esercizioId): array
    {
        $righe = static::query()
            ->where('target_type', $targetType)
            ->where('target_id', $targetId)
            ->valeNellEsercizio($esercizioId)
            ->selectRaw('immobile_id, anagrafica_id, SUM(importo_cents) as totale')
            ->groupBy('immobile_id', 'anagrafica_id')
            ->get();

        $out = [];
        foreach ($righe as $r) {
            $out[(int) $r->immobile_id][$r->anagrafica_id === null ? '' : (int) $r->anagrafica_id] = (int) $r->totale;
        }

        return $out;
    }
}
