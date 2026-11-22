<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Models\Assemblee;

use App\Models\Condominio;
use App\Models\Palazzina;
use App\Models\Scala;
use App\Traits\RegistraLeModifiche;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Il registro dei verbali di un perimetro (art. 1130 n. 7 c.c.): quello del condominio, e quello della scala o della palazzina per le assemblee della sola
 * parte, che hanno la loro numerazione. Tiene il punto di partenza scelto la prima volta: continua il libro di carta (l'ultimo numero, il giorno facoltativo)
 * o comincia dal n. 1. Si cambia finché il registro non ha un verbale registrato; dopo, il numero va solo avanti.
 */
class RegistroDelPerimetro extends Model
{
    use RegistraLeModifiche;

    protected $table = 'assemblee_registri_verbali';

    public const LIBRO = 'libro';

    public const DA_UNO = 'da_uno';

    protected $fillable = ['condominio_id', 'registro', 'palazzina_id', 'scala_id', 'partenza', 'ultimo_del_libro', 'libro_del', 'created_by', 'updated_by'];

    protected $casts = [
        'condominio_id' => 'integer',
        'palazzina_id' => 'integer',
        'scala_id' => 'integer',
        'ultimo_del_libro' => 'integer',
        'libro_del' => 'date:Y-m-d',
    ];

    public function condominio(): BelongsTo
    {
        return $this->belongsTo(Condominio::class);
    }

    public function palazzina(): BelongsTo
    {
        return $this->belongsTo(Palazzina::class);
    }

    public function scala(): BelongsTo
    {
        return $this->belongsTo(Scala::class);
    }
}
