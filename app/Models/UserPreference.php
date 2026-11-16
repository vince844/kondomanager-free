<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

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
