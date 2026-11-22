<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Models\Assemblee;

use App\Enums\Assemblee\AChiMemo;
use App\Enums\Assemblee\StatoCosaDaFare;
use App\Enums\Assemblee\TipoCosaDaFare;
use App\Models\Documento;
use App\Models\User;
use App\Traits\RegistraLeModifiche;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Una cosa da fare dopo una delibera (decisione 522 (1)): «Crea il piano straordinario entro il 15/01/2027, Andrea Costa», «Firma con Serramenti Marino ·
 * fuori dal programma». Con la data è anche un compito dell'Action inbox (`evento_id`): **una cosa sola**, e le risposte al compito passano da qui
 * ({@see \App\Services\Assemblee\Seguito\CoseDaFare}). La fa il programma quando la scheda nasce con la delibera (`oggetto_tipo`, `oggetto_id`), o
 * l'amministratore con «Segna eseguita»; la nota e il motivo restano nello studio.
 */
class CosaDaFareDellaDelibera extends Model
{
    use RegistraLeModifiche;

    protected $table = 'delibere_cose_da_fare';

    protected $fillable = [
        'delibera_id', 'condominio_id', 'tipo', 'testo', 'entro_il', 'a_chi', 'assegnato_a', 'evento_id', 'stato', 'fatta_il', 'fatta_da', 'nota',
        'chiusa_motivo', 'documento_id', 'oggetto_tipo', 'oggetto_id', 'created_by',
    ];

    protected $casts = [
        'delibera_id' => 'integer',
        'condominio_id' => 'integer',
        'assegnato_a' => 'integer',
        'evento_id' => 'integer',
        'fatta_da' => 'integer',
        'documento_id' => 'integer',
        'oggetto_id' => 'integer',
        'tipo' => TipoCosaDaFare::class,
        'stato' => StatoCosaDaFare::class,
        'a_chi' => AChiMemo::class,
        'entro_il' => 'date:Y-m-d',
        'fatta_il' => 'date:Y-m-d',
    ];

    public function delibera(): BelongsTo
    {
        return $this->belongsTo(Delibera::class, 'delibera_id');
    }

    public function documento(): BelongsTo
    {
        return $this->belongsTo(Documento::class, 'documento_id');
    }

    public function fattaDa(): BelongsTo
    {
        return $this->belongsTo(User::class, 'fatta_da');
    }

    public function assegnatoA(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assegnato_a');
    }

    public function eDaFare(): bool
    {
        return $this->stato === StatoCosaDaFare::DA_FARE;
    }
}
