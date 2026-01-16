<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UserPreference extends Model
{
    protected $fillable = [
        'user_id',
        'open_condominio_on_login',
        'default_condominio_id',
    ];

    public function condominio()
    {
        return $this->belongsTo(Condominio::class, 'default_condominio_id');
    }
}
