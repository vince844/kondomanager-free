<?php

namespace App\Models\Gestionale;

use App\Models\Anagrafica;
use App\Models\Immobile;
use App\Models\Tabella;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Una riga del dettaglio del riparto: **come** una quota è nata (1.11.0-beta.29).
 *
 * `rate_quote` congela il totale per (anagrafica, immobile) e per rata; questa tabella congela la
 * spiegazione — per ogni soggetto del piano, una riga per componente: (conto foglia, tabella,
 * ruolo richiesto) per il millesimale, la riga di fattura per l'ad personam, il conto per il già
 * versato — scritta da `GenerateRateQuotesAction` nella stessa transazione delle quote, con il
 * registro che `CalcoloQuoteService::getRigheDettaglio()` costruisce accanto ai totali.
 *
 * Invariante, per costruzione e per test: per ogni (anagrafica, immobile) del piano,
 * Σ `importo` delle righe con soggetto = Σ `regole_calcolo.importi.quota_pura_gestione` delle
 * sue quote (il saldo non passa dal motore ed è nella quota, non qui).
 *
 * ## Le quattro forme
 *
 * - `riparto`: tabella × ruolo richiesto; `importo` con il segno del conto (negativo per le entrate).
 * - `netting`: il già versato di quel soggetto su quel conto, **negativo**; niente tabella.
 * - `ad_personam`: la spesa di una sola unità, con `riga_fattura_id`; niente tabella.
 * - `quota_zero`: un'unità a 0 (non partecipa) o NULL (non compilato) in una tabella; nessun
 *   soggetto, importo 0. Serve alla stampa per mostrare «0,00» con il millesimo di allora.
 *
 * ## Il dettaglio è del piano, non della rata
 *
 * La fettina per rata (`GenerateRateQuotesAction::$calcolaFettina`) non è additiva al centesimo:
 * tre conti da 1 cent su due rate danno 1+1+1 sulla prima rata, mentre la fettina del totale 3
 * dà 2 e 1. Una lettura «per rata × conto» resta quindi derivata e non torna al centesimo con la
 * quota della rata: si dichiara, non si finge.
 *
 * `conto_nome`, `conto_radice_nome`, `tabella_nome` sono snapshot: il conto o la tabella possono
 * essere cancellati dopo la generazione (`nullOnDelete`) e la riga deve restare leggibile. La
 * radice è quella **al momento della generazione**.
 */
class RigaRiparto extends Model
{
    protected $table = 'righe_riparto';

    public const TIPO_RIPARTO = 'riparto';
    public const TIPO_NETTING = 'netting';
    public const TIPO_AD_PERSONAM = 'ad_personam';
    public const TIPO_QUOTA_ZERO = 'quota_zero';

    protected $fillable = [
        'piano_rate_id', 'tipo', 'anagrafica_id', 'immobile_id',
        'conto_id', 'conto_nome', 'conto_radice_id', 'conto_radice_nome',
        'tabella_id', 'tabella_nome', 'tabella_quota', 'coefficiente',
        'valore_millesimo', 'somma_valori', 'ruolo_richiesto', 'ruolo_risolto', 'quota_possesso',
        'riga_fattura_id', 'riga_descrizione', 'importo', 'versione_calcolo',
    ];

    protected $casts = [
        'importo'          => 'integer',
        'coefficiente'     => 'float',
        'valore_millesimo' => 'float',
        'somma_valori'     => 'float',
        'quota_possesso'   => 'float',
    ];

    public function pianoRate(): BelongsTo
    {
        return $this->belongsTo(PianoRate::class);
    }

    public function anagrafica(): BelongsTo
    {
        return $this->belongsTo(Anagrafica::class);
    }

    public function immobile(): BelongsTo
    {
        return $this->belongsTo(Immobile::class);
    }

    public function conto(): BelongsTo
    {
        return $this->belongsTo(Conto::class);
    }

    public function contoRadice(): BelongsTo
    {
        return $this->belongsTo(Conto::class, 'conto_radice_id');
    }

    public function tabella(): BelongsTo
    {
        return $this->belongsTo(Tabella::class);
    }

    public function rigaFattura(): BelongsTo
    {
        return $this->belongsTo(RigaFattura::class);
    }
}
