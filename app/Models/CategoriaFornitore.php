<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CategoriaFornitore extends Model
{
    protected $table = 'categorie_fornitore';

    protected $fillable = [
        'name',
        'description'
    ];

    public function fornitori()
    {
        return $this->hasMany(Fornitore::class, 'categoria_id');
    }
}
