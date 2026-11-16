<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Models\Gestionale;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class ContoTabellaRipartizione extends Model
{
    use HasFactory;

    protected $table = 'conto_tabella_ripartizioni';

    protected $fillable = [
        'conto_tabella_millesimale_id',
        'soggetto',
        'percentuale',
    ];

    /** RELAZIONI */
    public function contoTabellaMillesimale()
    {
        return $this->belongsTo(ContoTabellaMillesimale::class, 'conto_tabella_millesimale_id');
    }
}
