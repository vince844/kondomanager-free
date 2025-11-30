<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class ContoCorrente extends Model
{
     protected $table = 'conti_correnti';

    protected $fillable = [
        'intestatario',
        'iban',
        'swift',
        'istituto',
        'indirizzo',
        'comune',
        'provincia',
        'cap',
        'nazione',
        'predefinito',
        'tipo',
        'note',
    ];

    protected $casts = [
        'predefinito' => 'boolean',
    ];

    /** Polymorphic owner */
    public function contable(): MorphTo
    {
        return $this->morphTo();
    }
}
