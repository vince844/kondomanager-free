<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Traits\RegistraLeModifiche;

class Scala extends Model
{
    use RegistraLeModifiche;

    use HasFactory;

    protected $table = 'scale';

    protected $fillable = [
        'condominio_id',
        'palazzina_id',
        'name',
        'description',
        'note',
    ];

    /** La tabella di proprietà generale scelta per questo perimetro (§1.2 del progetto della tappa 2). */
    public function tabellaDiProprieta()
    {
        return $this->belongsTo(Tabella::class, 'tabella_proprieta_id');
    }

    // Relazione con il condominio (obbligatoria)
    public function condominio()
    {
        return $this->belongsTo(Condominio::class);
    }

    // Relazione con la palazzina
    public function palazzina()
    {
        return $this->belongsTo(Palazzina::class);
    }

    // Una scala può avere molti immobili
    public function immobili()
    {
        return $this->hasMany(Immobile::class);
    }
}
