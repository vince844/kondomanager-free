<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Evento extends Model
{
    protected $table = 'eventi';

    protected $fillable = [
        'title',
        'description',
        'note',
        'start_time',
        'end_time',
        'created_by',
        'category_id',
        'recurrence_id',
        'visibility',
    ];

    protected $casts = [
        'start_time' => 'datetime',
        'end_time' => 'datetime',
    ];

    public function categoria()
    {
        return $this->belongsTo(CategoriaEvento::class, 'category_id');
    }

    public function ricorrenza()
    {
        return $this->belongsTo(RicorrenzaEvento::class, 'recurrence_id');
    }

    public function anagrafiche()
    {
        return $this->belongsToMany(Anagrafica::class, 'anagrafica_evento', 'evento_id', 'anagrafica_id');
    }

    public function condomini()
    {
        return $this->belongsToMany(Condominio::class, 'condominio_evento', 'evento_id', 'condominio_id');
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

}
