<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Models\Assemblee;

use App\Enums\Assemblee\StatoVerbale;
use App\Models\Condominio;
use App\Models\Documento;
use App\Models\ModelloTesto;
use App\Models\User;
use App\Traits\RegistraLeModifiche;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Il verbale di una riunione (art. 1136 ult. c. c.c.): una seduta aperta ha il suo verbale, e la seduta che prosegue dopo una sospensione ne ha un altro. Il
 * testo sta nelle parti ({@see ParteDelVerbale}: la discussione di ogni punto durante l'assemblea, poi il verbale intero), le versioni in
 * {@see VersioneDelVerbale}. Registrato, ha il numero del suo registro (del condominio, o della scala o della palazzina per le loro assemblee), il giorno
 * della firma e la copia sottoscritta, e il testo non cambia più; annullato, la riga resta nel registro con il perché.
 */
class VerbaleDellAssemblea extends Model
{
    use RegistraLeModifiche;

    protected $table = 'assemblee_verbali';

    protected $fillable = [
        'assemblea_id', 'condominio_id', 'registro', 'seduta_id', 'stato', 'modello_id', 'modello_nome', 'impronta_sala', 'generato_il', 'stampata', 'numero',
        'sottoscritto_il', 'documento_id', 'registrato_il', 'registrato_da', 'annullato_il', 'annullato_motivo', 'annullato_da', 'sostituisce_id',
        'nel_libro_il', 'trasmissione', 'created_by',
    ];

    protected $casts = [
        'assemblea_id' => 'integer',
        'condominio_id' => 'integer',
        'seduta_id' => 'integer',
        'stato' => StatoVerbale::class,
        'modello_id' => 'integer',
        'generato_il' => 'datetime',
        'stampata' => 'array',
        'numero' => 'integer',
        'sottoscritto_il' => 'date:Y-m-d',
        'documento_id' => 'integer',
        'registrato_il' => 'datetime',
        'registrato_da' => 'integer',
        'annullato_il' => 'datetime',
        'annullato_da' => 'integer',
        'sostituisce_id' => 'integer',
        'nel_libro_il' => 'date:Y-m-d',
        'trasmissione' => 'array',
    ];

    public function assemblea(): BelongsTo
    {
        return $this->belongsTo(Assemblea::class, 'assemblea_id');
    }

    public function condominio(): BelongsTo
    {
        return $this->belongsTo(Condominio::class);
    }

    public function seduta(): BelongsTo
    {
        return $this->belongsTo(SedutaDellAssemblea::class, 'seduta_id');
    }

    public function modello(): BelongsTo
    {
        return $this->belongsTo(ModelloTesto::class, 'modello_id');
    }

    /** La copia sottoscritta: un documento dell'assemblea, fuori dall'archivio e dal portale. */
    public function documento(): BelongsTo
    {
        return $this->belongsTo(Documento::class, 'documento_id');
    }

    public function registratoDa(): BelongsTo
    {
        return $this->belongsTo(User::class, 'registrato_da');
    }

    public function annullatoDa(): BelongsTo
    {
        return $this->belongsTo(User::class, 'annullato_da');
    }

    /** La registrazione annullata che questa sostituisce. */
    public function sostituisce(): BelongsTo
    {
        return $this->belongsTo(self::class, 'sostituisce_id');
    }

    public function parti(): HasMany
    {
        return $this->hasMany(ParteDelVerbale::class, 'verbale_id');
    }

    public function versioni(): HasMany
    {
        return $this->hasMany(VersioneDelVerbale::class, 'verbale_id')->orderBy('id');
    }

    public function eRegistrato(): bool
    {
        return $this->stato === StatoVerbale::REGISTRATO;
    }

    /** Il testo si cambia: in sala e in bozza. Registrato, è quello firmato; annullato, si riparte da una registrazione nuova. */
    public function siScrive(): bool
    {
        return in_array($this->stato, [StatoVerbale::IN_SALA, StatoVerbale::BOZZA], true);
    }
}
