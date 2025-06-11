<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CategoriaDocumento extends Model
{
    protected $table = 'categorie_documento';

    protected $fillable = [
        'name',
        'description'
    ];

    public function documenti()
    {
        return $this->hasMany(Documento::class, 'category_id');
    }
}
