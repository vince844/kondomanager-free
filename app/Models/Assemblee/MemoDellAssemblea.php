<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Models\Assemblee;

use App\Enums\Assemblee\AChiMemo;
use App\Enums\Assemblee\AmbitoAssemblea;
use App\Enums\Assemblee\StatoMemo;
use App\Models\Condominio;
use App\Models\Evento;
use App\Models\User;
use App\Traits\RegistraLeModifiche;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un memo «Da portare in assemblea», la memoria del condominio (decisioni 499 e 501). L'origine tiene il suo nome **copiato** («Fattura n. 45 · Idraulica
 * Bianchi · € 1.800,00»), che resta se l'origine sparisce. Con `ricordami_dal` il memo è anche un compito dell'Action inbox (`evento_id`): **una cosa sola**,
 * e le risposte al compito passano dal memo (R10, {@see \App\Services\Assemblee\MemoriaDelCondominio}). La nota resta nello studio.
 */
class MemoDellAssemblea extends Model
{
    use RegistraLeModifiche;

    protected $table = 'assemblee_memo';

    protected $fillable = [
        'condominio_id', 'ambito', 'palazzina_id', 'scala_id', 'testo', 'nota', 'origine_tipo', 'origine_id', 'origine_nome', 'assemblea_id',
        'ricordami_dal', 'a_chi', 'assegnato_a', 'evento_id', 'stato', 'punto_id', 'chiuso_il', 'chiuso_motivo', 'created_by',
    ];

    protected $casts = [
        'condominio_id' => 'integer',
        'palazzina_id' => 'integer',
        'scala_id' => 'integer',
        'origine_id' => 'integer',
        'assemblea_id' => 'integer',
        'assegnato_a' => 'integer',
        'evento_id' => 'integer',
        'punto_id' => 'integer',
        'ambito' => AmbitoAssemblea::class,
        'a_chi' => AChiMemo::class,
        'stato' => StatoMemo::class,
        'ricordami_dal' => 'date:Y-m-d',
        'chiuso_il' => 'datetime',
    ];

    public function condominio(): BelongsTo
    {
        return $this->belongsTo(Condominio::class);
    }

    /** «Per quale assemblea»: vuota vuol dire la prossima. */
    public function assemblea(): BelongsTo
    {
        return $this->belongsTo(Assemblea::class, 'assemblea_id');
    }

    public function punto(): BelongsTo
    {
        return $this->belongsTo(PuntoDellOrdineDelGiorno::class, 'punto_id');
    }

    /** Il compito dell'Action inbox, quando il memo ha la data. */
    public function compito(): BelongsTo
    {
        return $this->belongsTo(Evento::class, 'evento_id');
    }

    public function assegnatoA(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assegnato_a');
    }

    public function creatoDa(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function eInAttesa(): bool
    {
        return $this->stato === StatoMemo::IN_ATTESA;
    }
}
