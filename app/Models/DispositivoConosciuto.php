<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un dispositivo da cui un utente è già entrato (Coda 385): l'impronta del gettone del suo cookie, la descrizione
 * del browser, l'ultimo indirizzo, la prima e l'ultima volta. Lo scrive solo `App\Listeners\AvvisaDispositivoNuovo`.
 *
 * Le righe non viste da tredici mesi le toglie la pulizia notturna (`model:prune`): l'indirizzo è un dato personale,
 * e un dispositivo non usato da più di un anno torna a essere nuovo.
 */
class DispositivoConosciuto extends Model
{
    use MassPrunable;

    /** Dopo quanti mesi senza accessi un dispositivo si dimentica. */
    public const MESI_DI_CONSERVAZIONE = 13;

    protected $table = 'dispositivi_conosciuti';

    protected $fillable = [
        'user_id',
        'impronta',
        'descrizione',
        'ultimo_indirizzo',
        'prima_volta',
        'ultima_volta',
    ];

    protected $casts = [
        'prima_volta' => 'datetime',
        'ultima_volta' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function prunable(): Builder
    {
        return static::where('ultima_volta', '<', now()->subMonths(self::MESI_DI_CONSERVAZIONE));
    }
}
