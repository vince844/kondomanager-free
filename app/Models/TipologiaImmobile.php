<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TipologiaImmobile extends Model
{
    use HasFactory;

    protected $table = 'tipologie_immobili';

    protected $fillable = [
        'nome',
        'categoria',
    ];

    /**
     * Gli immobili che appartengono a questa tipologia.
     */
    public function immobili()
    {
        return $this->hasMany(Immobile::class, 'tipologia');
    }
}
