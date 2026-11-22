<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Models\Assemblee;

use App\Enums\Assemblee\ArrivoDellaDelega;
use App\Enums\Assemblee\StatoDelega;
use App\Models\Anagrafica;
use App\Models\Documento;
use App\Traits\ContaNelGesto;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Una delega per l'assemblea (art. 67 c. 1 disp. att.): vale per tutte le sue date, come la delega staccabile dell'avviso. Chi delega e per quali unità, a
 * chi (un condòmino o una persona fuori dall'anagrafica, mai l'amministratore: art. 67 c. 5), per quali punti (il conduttore), come è arrivata (in sala, su
 * carta prima, dal portale), lo stato con il motivo, il conto del quinto al momento in `dati`.
 *
 * «Solo gesto» nel registro: nasce in «Deleghe ricevute» o in un gesto della sala, che scrivono la loro riga.
 */
class DelegaDellAssemblea extends Model
{
    use ContaNelGesto;

    protected $table = 'assemblee_deleghe';

    protected $fillable = ['assemblea_id', 'delegante_anagrafica_id', 'delegante_nome', 'unita', 'delegato_anagrafica_id', 'delegato_nome', 'punti',
        'arrivata_come', 'ricevuta_il', 'segnata_il', 'documento_id', 'stato', 'motivo', 'revocata_il', 'dati', 'gesto', 'created_by'];

    protected $casts = [
        'assemblea_id' => 'integer',
        'delegante_anagrafica_id' => 'integer',
        'delegato_anagrafica_id' => 'integer',
        'documento_id' => 'integer',
        'unita' => 'array',
        'punti' => 'array',
        'dati' => 'array',
        'arrivata_come' => ArrivoDellaDelega::class,
        'stato' => StatoDelega::class,
        'ricevuta_il' => 'date:Y-m-d',
        'segnata_il' => 'datetime',
        'revocata_il' => 'datetime',
    ];

    public function assemblea(): BelongsTo
    {
        return $this->belongsTo(Assemblea::class, 'assemblea_id');
    }

    public function delegante(): BelongsTo
    {
        return $this->belongsTo(Anagrafica::class, 'delegante_anagrafica_id');
    }

    public function delegato(): BelongsTo
    {
        return $this->belongsTo(Anagrafica::class, 'delegato_anagrafica_id');
    }

    /** La scansione, facoltativa. */
    public function documento(): BelongsTo
    {
        return $this->belongsTo(Documento::class, 'documento_id');
    }

    public function eValida(): bool
    {
        return $this->stato === StatoDelega::VALIDA;
    }
}
