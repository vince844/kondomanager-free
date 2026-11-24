<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Models\Errori;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Un errore del registro degli errori (decisione 698): gli errori uguali, con la stessa impronta, sono uno solo, con quante volte è successo, la prima e
 * l'ultima volta; ogni volta ha la sua riga ({@see VoltaDellErrore}) con il suo codice. Lo stato è quello della scheda «Errori»: «nuovo» finché nessuno lo
 * apre, «avvisato» quando qualcuno ha mandato l'avviso dalla pagina d'errore (resta così anche dopo averlo aperto), «visto» dopo.
 *
 * La pulizia di ogni notte (`model:prune`) toglie l'errore che non ha più volte: le volte si tolgono dopo 90 giorni.
 */
class Errore extends Model
{
    use MassPrunable;

    public const NUOVO = 'nuovo';
    public const AVVISATO = 'avvisato';
    public const VISTO = 'visto';

    public const STATI = [self::NUOVO, self::AVVISATO, self::VISTO];

    protected $table = 'errori';

    protected $fillable = ['impronta', 'classe', 'messaggio', 'file', 'riga', 'volte', 'prima_volta_il', 'ultima_volta_il', 'stato'];

    protected $casts = [
        'riga' => 'integer',
        'volte' => 'integer',
        'prima_volta_il' => 'datetime',
        'ultima_volta_il' => 'datetime',
    ];

    public function volteRegistrate(): HasMany
    {
        return $this->hasMany(VoltaDellErrore::class, 'errore_id');
    }

    /** L'impronta come si legge e si scrive nel rapporto: otto caratteri. */
    public function improntaBreve(): string
    {
        return strtoupper(substr((string) $this->impronta, 0, 8));
    }

    /** L'errore che non ha più volte: le sue volte erano tutte di più di 90 giorni fa, e la pulizia le ha tolte. */
    public function prunable(): Builder
    {
        return static::query()->whereDoesntHave('volteRegistrate');
    }
}
