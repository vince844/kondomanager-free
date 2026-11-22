<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Models\Assemblee;

use App\Enums\Assemblee\RuoloCollegamento;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Una scheda di oggi legata a una delibera (§1.4): una tabella sola al posto di una colonna su dieci tabelle, così «elenca i posti» e «lo dice a tutti i
 * posti» sono una lettura sola. `oggetto_tipo` è il nome stabile del registro, mai la classe PHP. **La delibera di una scheda sta solo qui** (§13, rilievo
 * 22); le colonne di oggi restano la verità dei motori.
 *
 * Si scrive solo da {@see \App\Services\Assemblee\CollegamentiDelleDelibere} (lo controlla `SoloIlServizioScriveICollegamentiTest`), dentro il campo
 * «Delibera» della scheda che collega: fuori dal registro come modello.
 */
class CollegamentoAllaDelibera extends Model
{
    protected $table = 'delibere_collegamenti';

    protected $fillable = ['delibera_id', 'condominio_id', 'oggetto_tipo', 'oggetto_id', 'ruolo', 'nota', 'collegata_da', 'collegata_il'];

    protected $casts = [
        'delibera_id' => 'integer',
        'condominio_id' => 'integer',
        'oggetto_id' => 'integer',
        'collegata_da' => 'integer',
        'ruolo' => RuoloCollegamento::class,
        'collegata_il' => 'datetime',
    ];

    public function delibera(): BelongsTo
    {
        return $this->belongsTo(Delibera::class, 'delibera_id');
    }

    public function collegataDa(): BelongsTo
    {
        return $this->belongsTo(User::class, 'collegata_da');
    }
}
