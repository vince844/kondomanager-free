<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EccezioneEvento extends Model
{
    protected $table = 'eccezioni_evento';

    protected $fillable = [
        'recurrence_id',
        'evento_id',
        'exception_date',
        'is_deleted',
        'override_data',
    ];

    protected $casts = [
        'exception_date' => 'datetime',
        'is_deleted' => 'boolean',
        'override_data' => 'array',
    ];

    /**
     * The recurrence rule this exception is associated with.
     */
    public function ricorrenza()
    {
        return $this->belongsTo(RicorrenzaEvento::class, 'recurrence_id');
    }

    /**
     * The specific event this exception may refer to (if overridden).
     */
    public function evento()
    {
        return $this->belongsTo(Evento::class, 'evento_id');
    }
}
