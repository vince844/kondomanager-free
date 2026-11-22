<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Models\Assemblee;

use App\Enums\Assemblee\EsitoDelibera;
use App\Models\Condominio;
use App\Traits\RegistraLeModifiche;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * La delibera come cerniera (§1.1): sta in un punto di una riunione, anche registrata a mano. `deliberata_il` è **il giorno della riunione**, il dato che la
 * cerniera porta nelle schede; in una riunione a mano è sempre uguale ad `assemblee.data` (R2). L'importo in **centesimi**.
 *
 * Revoca, annullamento e impugnazione sono segni: nessun conto li legge (principi 4 e 5, art. 1137 c.c.). Le schede di oggi si legano qui con
 * {@see CollegamentoAllaDelibera}, che scrive solo {@see \App\Services\Assemblee\CollegamentiDelleDelibere}.
 */
class Delibera extends Model
{
    use RegistraLeModifiche;

    protected $table = 'delibere';

    protected $fillable = [
        'condominio_id', 'assemblea_id', 'punto_id', 'votazione', 'oggetto', 'testo', 'esito', 'deliberata_il', 'importo', 'revocata_il',
        'revocata_da_delibera_id', 'annullata_il', 'annullata_nota', 'impugnata_il', 'impugnata_nota', 'seguito_confermato_il', 'seguito_confermato_da', 'dati',
        'created_by',
    ];

    protected $casts = [
        'condominio_id' => 'integer',
        'assemblea_id' => 'integer',
        'punto_id' => 'integer',
        'votazione' => 'integer',
        'importo' => 'integer',
        'revocata_da_delibera_id' => 'integer',
        'esito' => EsitoDelibera::class,
        'deliberata_il' => 'date:Y-m-d',
        'revocata_il' => 'date:Y-m-d',
        'annullata_il' => 'date:Y-m-d',
        'impugnata_il' => 'date:Y-m-d',
        'seguito_confermato_il' => 'datetime',
        'seguito_confermato_da' => 'integer',
        'dati' => 'array',
    ];

    public function condominio(): BelongsTo
    {
        return $this->belongsTo(Condominio::class);
    }

    public function assemblea(): BelongsTo
    {
        return $this->belongsTo(Assemblea::class, 'assemblea_id');
    }

    public function punto(): BelongsTo
    {
        return $this->belongsTo(PuntoDellOrdineDelGiorno::class, 'punto_id');
    }

    /** Le schede che la usano (regola 2 del principio: «elenca i posti»). */
    public function collegamenti(): HasMany
    {
        return $this->hasMany(CollegamentoAllaDelibera::class, 'delibera_id');
    }

    /** Ciò che resta da fare dopo la delibera (il seguito, decisione 522 (1)). */
    public function coseDaFare(): HasMany
    {
        return $this->hasMany(CosaDaFareDellaDelibera::class, 'delibera_id');
    }

    public function revocataDa(): BelongsTo
    {
        return $this->belongsTo(self::class, 'revocata_da_delibera_id');
    }

    public function eRevocata(): bool
    {
        return $this->revocata_il !== null;
    }

    public function eAnnullata(): bool
    {
        return $this->annullata_il !== null;
    }

    /** Approvata, o approvata con modifiche: una delibera che si può scegliere per i ruoli che chiedono un voto (R7). */
    public function eApprovata(): bool
    {
        return in_array($this->esito, [EsitoDelibera::APPROVATA, EsitoDelibera::APPROVATA_CON_MODIFICHE], true);
    }
}
