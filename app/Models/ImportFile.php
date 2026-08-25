<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Un file caricato dentro un lotto, e cosa il sistema ha creduto che fosse.
 *
 * `confidenza` e `tipo_forzato` non sono statistica: sono la traccia di **chi ha deciso**.
 * Il punteggio è un suggerimento (§14.1, schermata S2) e l'ultima parola è dell'amministratore;
 * quando la usa, il rapporto deve poterlo dire.
 */
class ImportFile extends Model
{
    protected $table = 'import_files';

    protected $fillable = [
        'import_batch_id',
        'nome_originale',
        'percorso',
        'dimensione',
        'hash',           // sha256 — riconosce il ricaricamento dello stesso file identico
        'report_type',
        'confidenza',     // 0–100
        'tipo_forzato',
        'colonne',        // ['riconosciute' => [...], 'ignorate' => [...]]
    ];

    protected $casts = [
        'colonne' => 'array',
        'tipo_forzato' => 'boolean',
        'dimensione' => 'integer',
        'confidenza' => 'integer',
    ];

    public function batch(): BelongsTo
    {
        return $this->belongsTo(ImportBatch::class, 'import_batch_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(ImportBatchItem::class);
    }

    /**
     * Le colonne del file che non hanno un corrispondente nel canonico.
     *
     * Va mostrato **anche quando è tutto verde** (§14.0, decisione 2): la paura di chi migra
     * non è «sbaglia un dato», è «perdo dei dati», e questa è l'unica risposta possibile.
     */
    public function colonneIgnorate(): array
    {
        return $this->colonne['ignorate'] ?? [];
    }

    public function colonneRiconosciute(): array
    {
        return $this->colonne['riconosciute'] ?? [];
    }
}
