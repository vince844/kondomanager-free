<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Palazzina extends Model
{
    use HasFactory;

    protected $table = 'palazzine';

    protected $fillable = [
        'condominio_id',
        'name',
        'description',
        'note',
    ];

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
