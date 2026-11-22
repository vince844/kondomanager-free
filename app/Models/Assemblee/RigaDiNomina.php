<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Models\Assemblee;

use App\Enums\Assemblee\EventoNomina;
use App\Enums\Assemblee\StatoNomina;
use App\Models\Condominio;
use App\Models\Documento;
use App\Traits\RegistraLeModifiche;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Una riga del registro di nomina e revoca dell'amministratore (art. 1130 n. 7 c.c.): «in ordine cronologico, le date della nomina e della revoca di ciascun
 * amministratore del condominio, nonché gli estremi del decreto in caso di provvedimento giudiziale». Da dove viene: una delibera su carta, un verbale
 * registrato nel programma (la riga proposta dalla delibera, che resta «Proposta» finché l'amministratore non la conferma), un decreto. Una riga sbagliata
 * si annulla con il perché, non si cancella.
 */
class RigaDiNomina extends Model
{
    use RegistraLeModifiche;

    protected $table = 'registro_nomine';

    public const DA_CARTA = 'carta';

    public const DA_VERBALE = 'verbale';

    public const DA_DECRETO = 'decreto';

    public const DA_DOVE = [self::DA_CARTA, self::DA_VERBALE, self::DA_DECRETO];

    protected $fillable = ['condominio_id', 'evento', 'amministratore', 'dal', 'da_dove', 'estremi', 'delibera_id', 'verbale_id', 'decreto', 'documento_id',
        'nota', 'stato', 'confermata_il', 'confermata_da', 'annullata_il', 'annullata_motivo', 'annullata_da', 'created_by'];

    protected $casts = [
        'condominio_id' => 'integer',
        'evento' => EventoNomina::class,
        'dal' => 'date:Y-m-d',
        'delibera_id' => 'integer',
        'verbale_id' => 'integer',
        'decreto' => 'array',
        'documento_id' => 'integer',
        'stato' => StatoNomina::class,
        'confermata_il' => 'datetime',
        'confermata_da' => 'integer',
        'annullata_il' => 'datetime',
        'annullata_da' => 'integer',
    ];

    public function condominio(): BelongsTo
    {
        return $this->belongsTo(Condominio::class);
    }

    public function delibera(): BelongsTo
    {
        return $this->belongsTo(Delibera::class);
    }

    public function verbale(): BelongsTo
    {
        return $this->belongsTo(VerbaleDellAssemblea::class, 'verbale_id');
    }

    public function documento(): BelongsTo
    {
        return $this->belongsTo(Documento::class, 'documento_id');
    }

    /** Il documento della riga (la scansione della delibera su carta, il decreto): fuori dall'archivio e dal portale. */
    public function documenti(): \Illuminate\Database\Eloquent\Relations\MorphMany
    {
        return $this->morphMany(Documento::class, 'documentable');
    }
}
