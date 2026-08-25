<?php

namespace App\Models\Gestionale;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Una riga della sezione Erario di una delega F24.
 *
 * Il tracciato ne ammette **sei per modello**: oltre, l'Agenzia consente esplicitamente più
 * modelli per la stessa scadenza, quindi la delega va splittata invece di comprimere le
 * righe. Una riga è la combinazione `codice tributo × mese × anno di riferimento`: due
 * ritenute dello stesso tributo operate nello stesso mese stanno sulla stessa riga, due di
 * mesi diversi no — ed è per questo che il numero di righe può crescere.
 *
 * `rateazione_mese_rif` è una **stringa di quattro caratteri**, non un numero: il tracciato
 * vuole `'0006'` per giugno, ma la stessa posizione ospita anche forme come `'0101'`.
 * Tenerlo intero costringerebbe a ri-serializzarlo, e a sbagliarlo, in ogni export.
 */
class RigaF24 extends Model
{
    protected $table = 'righe_f24';

    protected $guarded = ['id'];

    protected $casts = [
        'delega_f24_id' => 'integer',
        'ordine' => 'integer',
        'importo_debito' => 'integer',
    ];

    public function delega(): BelongsTo
    {
        return $this->belongsTo(DelegaF24::class, 'delega_f24_id');
    }

    /**
     * I pagamenti fornitore le cui ritenute questa riga sta versando.
     *
     * È il legame che, finché non esiste il registro `ritenute_operate` della Fase 2, rende
     * ricostruibile la scomposizione per percipiente: riga → pagamenti → fornitore.
     */
    public function pagamenti(): BelongsToMany
    {
        return $this->belongsToMany(
            PagamentoFornitore::class,
            'riga_f24_pagamento',
            'riga_f24_id',
            'pagamento_fornitore_id'
        )->withPivot('importo')->withTimestamps();
    }

    /** Il periodo di riferimento in forma leggibile: `'0006'` + `'2026'` → `06/2026`. */
    public function periodoLeggibile(): string
    {
        return substr($this->rateazione_mese_rif, 2, 2).'/'.$this->anno_riferimento;
    }
}
