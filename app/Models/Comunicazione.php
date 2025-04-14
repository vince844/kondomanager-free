<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Comunicazione extends Model
{
    use HasFactory;
    
    protected $table = 'comunicazioni';

    protected $fillable = [
        'subject',
        'description',
        'created_by',
        'priority',
        'is_featured',
        'is_private',
        'is_published',
        'is_approved',
        'can_comment',
        'slug',
        'reference',
    ];

    /**
     * The condomini that belong to the comunicazione.
     */
    public function condomini()
    {
        return $this->belongsToMany(Condominio::class, 'comunicazione_condominio')->withTimestamps();
    }

    /**
     * The anagrafiche that belong to the comunicazione.
     */
    public function anagrafiche()
    {
        return $this->belongsToMany(Anagrafica::class, 'anagrafica_comunicazione')->withTimestamps();
    }
}
