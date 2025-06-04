<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Documento extends Model
{
    protected $table = 'documenti';

    protected $fillable = [
        'name',
        'description',
        'created_by',
        'is_published',
        'is_approved',
        'path',
        'mime_type',
        'file_size', 
        'category_id',
    ];

    public function categoria()
    {
        return $this->belongsTo(CategoriaDocumento::class, 'category_id');
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function anagrafiche()
    {
        return $this->belongsToMany(Anagrafica::class, 'anagrafica_documento');
    }

    public function condomini()
    {
        return $this->belongsToMany(Condominio::class, 'condominio_documento');
    }
}
