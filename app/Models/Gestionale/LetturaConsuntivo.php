<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Models\Gestionale;

use App\Traits\ContaNelGesto;
use Illuminate\Database\Eloquent\Model;

/**
 * Una lettura di fine anno di una voce per un esercizio (`letture_consuntivo`): il contatore generale (senza tabella) o quella di una tabella della voce, con
 * la lettura, la percentuale scritta o «per differenza». Le scrive e le toglie `LettureDiFineAnno`, tutte insieme, in un gesto del registro delle modifiche.
 */
class LetturaConsuntivo extends Model
{
    use ContaNelGesto;

    protected $table = 'letture_consuntivo';

    protected $fillable = ['conto_id', 'tabella_id', 'esercizio_id', 'lettura', 'percentuale', 'per_differenza', 'unita'];

    protected $casts = ['per_differenza' => 'boolean'];
}
