<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Models;

use App\Models\Gestionale\PianoConto;
use App\Models\Gestionale\PianoRate;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use App\Traits\RegistraLeModifiche;

class Gestione extends Model
{
    use RegistraLeModifiche;

    use HasFactory;

    protected $table = 'gestioni';

    protected $fillable = [
        'condominio_id',
        'nome',
        'descrizione',
        'tipo',
        'attiva',
        'data_inizio',
        'data_fine',
        'note',
        'saldo_applicato', 
        'nota_saldo',
    ];

    protected $casts = [
        'data_inizio' => 'date',
        'data_fine'   => 'date',
        'saldo_applicato' => 'boolean',
    ];

    public function condominio()
    {
        return $this->belongsTo(Condominio::class);
    }

    public function esercizi()
    {
        return $this->belongsToMany(Esercizio::class, 'esercizio_gestione')
            ->withPivot(['attiva', 'data_inizio', 'data_fine'])
            ->withTimestamps();
    }

    public function pianoConto() 
    {
        return $this->hasOne(PianoConto::class); 
    }

    /**
     * I piani rate associati a questa gestione.
     */
    public function pianiRate()
    {
        // Usa il path corretto del tuo modello PianoRate
        return $this->hasMany(PianoRate::class, 'gestione_id');
    }
}
