<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CategoriaEvento extends Model
{
     protected $table = 'categorie_evento';

    protected $fillable = ['name', 'description', 'color'];

    public function eventi()
    {
        return $this->hasMany(Evento::class, 'category_id');
    }
}
