<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Traits\RegistraLeModifiche;

class Palazzina extends Model
{
    use RegistraLeModifiche;

    use HasFactory;

    protected $table = 'palazzine';

    protected $fillable = [
        'condominio_id',
        'name',
        'description',
        'note',
    ];

    /** La tabella di proprietà generale scelta per questo perimetro (§1.2 del progetto della tappa 2). */
    public function tabellaDiProprieta()
    {
        return $this->belongsTo(Tabella::class, 'tabella_proprieta_id');
    }

    // Relazione con il condominio
    public function condominio()
    {
        return $this->belongsTo(Condominio::class);
    }

    // Una palazzina ha molte scale
    public function scale()
    {
        return $this->hasMany(Scala::class);
    }

     // Scale che appartengono a palazzine
    public function scaleConPalazzina()
    {
        return $this->hasManyThrough(Scala::class, Palazzina::class);
    }

    // Una palazzina ha molti immobili (relazione diretta)
    public function immobili()
    {
        return $this->hasMany(Immobile::class);
    }
}
