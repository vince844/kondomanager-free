<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Models\Assemblee;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Una versione di una parte del verbale: il testo com'era, con il perché («generato dal modello «Verbale ordinario dello studio»», «correzioni», «ripristino
 * della versione 2», «bozza stampata per la firma», «registrato n. 42»), l'impronta, il dispositivo e l'ora del gesto. `in_attesa` è una versione che
 * aspetta la scelta dell'amministratore: due versioni dello stesso punto (`attesa = due_versioni`), la discussione di un punto arrivata dopo la generazione
 * (`attesa = dopo_la_generazione`: il verbale intero non la legge più, e si mette nel punto con una scelta), o un testo arrivato dopo la registrazione
 * (`attesa = dopo_la_registrazione`), che non si applica.
 *
 * Fuori dal registro delle modifiche come modello: la riga la scrive il gesto che crea la versione, con l'ora del gesto e l'ora di arrivo.
 */
class VersioneDelVerbale extends Model
{
    protected $table = 'assemblee_verbali_versioni';

    public const DUE_VERSIONI = 'due_versioni';

    public const DOPO_LA_REGISTRAZIONE = 'dopo_la_registrazione';

    public const DOPO_LA_GENERAZIONE = 'dopo_la_generazione';

    protected $fillable = ['verbale_id', 'parte', 'numero', 'documento', 'impronta', 'perche', 'dispositivo', 'gesto', 'segnata_il', 'in_attesa', 'attesa',
        'scelta_il', 'scelta_da', 'scelta', 'created_by'];

    protected $casts = [
        'verbale_id' => 'integer',
        'numero' => 'integer',
        'documento' => 'array',
        'segnata_il' => 'datetime',
        'in_attesa' => 'boolean',
        'scelta_il' => 'datetime',
        'scelta_da' => 'integer',
        'created_by' => 'integer',
    ];

    public function verbale(): BelongsTo
    {
        return $this->belongsTo(VerbaleDellAssemblea::class, 'verbale_id');
    }

    public function autore(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** Una scelta ancora aperta: in attesa e non scelta. */
    public function aperta(): bool
    {
        return $this->in_attesa && $this->scelta_il === null;
    }
}
