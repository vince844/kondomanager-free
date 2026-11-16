<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CategoriaEvento extends Model
{
    protected $table = 'categorie_evento';

    protected $fillable = ['name', 'description'];

    public function eventi()
    {
        return $this->hasMany(Evento::class, 'category_id');
    }
}
