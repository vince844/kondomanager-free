<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Scala extends Model
{
    use HasFactory;

    protected $table = 'scale';

    protected $fillable = [
        'palazzina_id',
        'name',
        'description',
        'note',
    ];

    // 🔁 Relazione con la palazzina
    public function palazzina()
    {
        return $this->belongsTo(Palazzina::class);
    }

    // 🔁 Una scala può avere molti immobili
    public function immobili()
    {
        return $this->hasMany(Immobile::class);
    }
}
