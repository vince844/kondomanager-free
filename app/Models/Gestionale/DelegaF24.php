<?php

namespace App\Models\Gestionale;

use App\Enums\Fiscale\PlafondRitenuta;
use App\Enums\Fiscale\StatoDelegaF24;
use App\Models\Condominio;
use App\Models\Esercizio;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Una delega di versamento F24: il documento con cui il condominio versa all'Erario le
 * ritenute d'acconto che ha operato pagando i fornitori.
 *
 * È il pezzo che mancava per **chiudere il conto 2202**. Fino alla beta.37 quel conto
 * accumulava soltanto: verificato a database, `dare = 0` su tutti i condomìni: le ritenute
 * ci entravano e non ne uscivano mai, perché nel gestionale non esisteva il movimento che
 * le versa.
 *
 * ## Cosa NON è
 *
 * Non è il modello ministeriale. La stampa conforme e il tracciato telematico sono la Fase 6
 * del design, marcata «oltre». Qui la delega è il documento *interno*: raccoglie le ritenute
 * da versare, le raggruppa per codice tributo e periodo, e produce il prospetto con i campi
 * che l'amministratore trascrive nell'home banking o su F24 online.
 *
 * ## Il sigillo
 *
 * Dallo stato `versata` la delega non si rettifica: si storna. È la regola non negoziabile
 * del design, e la ragione non è tecnica — un versamento all'Erario è un fatto accaduto, e
 * modificarlo a posteriori significherebbe raccontarlo diversamente da come l'Agenzia lo ha
 * registrato.
 */
class DelegaF24 extends Model
{
    protected $table = 'deleghe_f24';

    protected $guarded = ['id'];

    protected $casts = [
        'condominio_id' => 'integer',
        'esercizio_id' => 'integer',
        'stato' => StatoDelegaF24::class,
        'plafond' => PlafondRitenuta::class,
        'data_scadenza' => 'date',
        'data_versamento' => 'date',
        'data_quietanza' => 'date',
        'totale_debito' => 'integer',
        'saldo' => 'integer',
    ];

    // ── Relazioni ────────────────────────────────────────────────────────────

    public function condominio(): BelongsTo
    {
        return $this->belongsTo(Condominio::class);
    }

    public function esercizio(): BelongsTo
    {
        return $this->belongsTo(Esercizio::class);
    }

    public function righe(): HasMany
    {
        return $this->hasMany(RigaF24::class, 'delega_f24_id')->orderBy('ordine');
    }

    public function scrittura(): BelongsTo
    {
        return $this->belongsTo(ScritturaContabile::class, 'scrittura_contabile_id');
    }

    public function padre(): BelongsTo
    {
        return $this->belongsTo(self::class, 'delega_padre_id');
    }

    public function figlie(): HasMany
    {
        return $this->hasMany(self::class, 'delega_padre_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    // ── Scope ────────────────────────────────────────────────────────────────

    public function scopeDelCondominio(Builder $query, int $condominioId): Builder
    {
        return $query->where('condominio_id', $condominioId);
    }

    /** Le deleghe che pesano ancora: non versate, non annullate. */
    public function scopeAperte(Builder $query): Builder
    {
        return $query->whereIn('stato', [
            StatoDelegaF24::BOZZA->value,
            StatoDelegaF24::CONFERMATA->value,
        ]);
    }

    public function scopeScadute(Builder $query): Builder
    {
        return $query->aperte()->whereDate('data_scadenza', '<', now()->toDateString());
    }

    // ── Stato ────────────────────────────────────────────────────────────────

    public function isVersata(): bool
    {
        return $this->stato === StatoDelegaF24::VERSATA;
    }

    public function isModificabile(): bool
    {
        return $this->stato->isModificabile();
    }

    /**
     * Il motivo per cui questa delega non si può modificare, o `null` se si può.
     *
     * Stesso schema di `FatturaPassiva::motivoBloccoEliminazione()`: una sola funzione che
     * decide *e* spiega, usata sia dalla guardia sia dall'interfaccia, così le due non
     * possono divergere. È la lezione della beta.34 — il divieto muto.
     */
    public function motivoBloccoModifica(): ?string
    {
        return match ($this->stato) {
            StatoDelegaF24::VERSATA => 'La delega è già stata versata: per correggerla va stornata.',
            StatoDelegaF24::STORNATA => 'La delega è stata stornata: non è più modificabile.',
            StatoDelegaF24::ANNULLATA => 'La delega è stata annullata.',
            StatoDelegaF24::CONFERMATA => 'La delega è confermata: riportala in bozza per modificarla.',
            StatoDelegaF24::BOZZA => null,
        };
    }
}
