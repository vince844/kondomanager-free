<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Traits\RegistraLeModifiche;

class CategoriaFornitore extends Model
{
    use RegistraLeModifiche;

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
