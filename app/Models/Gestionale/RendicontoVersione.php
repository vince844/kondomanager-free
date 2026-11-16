<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Models\Gestionale;

use App\Models\Condominio;
use App\Models\Documento;
use App\Models\Esercizio;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * Un rendiconto fermato in un giorno per l'assemblea: le cifre (`cifre`, nella forma `forma`), la nota sintetica com'era quel giorno, il PDF del
 * fascicolo conservato con la sua impronta (`pdf`, i `Documento` legati alla versione). **Presentata** quando si ferma, **approvata** quando
 * l'amministratore registra la delibera, **sostituita** quando una seconda approvazione dello stesso esercizio prende il suo posto.
 *
 * Il documento di riferimento è il PDF: è ciò che i condòmini hanno ricevuto e l'assemblea ha votato, e una ristampa più tardi uscirebbe diversa anche
 * a cifre uguali. Una versione non si ristampa: si scarica il suo PDF. Le cifre servono a chi le legge con un programma (la chiusura, il confronto).
 *
 * La versione non scrive denaro e non cambia lo stato dell'esercizio. Le liste non leggono `cifre` (nel condominio grande sono circa 0,8 MB).
 */
class RendicontoVersione extends Model
{
    protected $table = 'rendiconto_versioni';

    /**
     * La forma delle cifre. Sale quando cambia l'elenco delle chiavi: le cifre di una versione si leggono con le chiavi della sua forma. L'elenco
     * è scritto in `tests/Feature/Gestionale/RendicontoVersioniTest.php`. La forma 3 aggiunge alla 1 l'impronta del giornale (`giornale`: il contenuto
     * di ogni registrazione senza gli id, e i saldi prima dell'esercizio), con cui il confronto dopo l'approvazione dice se una registrazione è cambiata.
     * Una versione di una forma prima si confronta con le cifre che ha ({@see \App\Services\Gestionale\VersioniDelRendiconto::differenze()}).
     */
    public const FORMA = 3;

    public const PRESENTATA = 'presentata';

    public const APPROVATA = 'approvata';

    public const SOSTITUITA = 'sostituita';

    /** Le colonne che una lista legge: tutte tranne le cifre e la nota. */
    public const COLONNE_DELLA_LISTA = ['id', 'condominio_id', 'esercizio_id', 'numero', 'stato', 'forma', 'calcolato_il', 'data_delibera', 'numero_verbale',
        'riparto_approvato', 'nota_approvazione', 'pdf', 'verbale_documento_id', 'creata_da', 'approvata_da', 'approvata_il', 'created_at', 'updated_at'];

    protected $fillable = [
        'condominio_id', 'esercizio_id', 'numero', 'stato', 'forma', 'cifre', 'nota', 'calcolato_il', 'data_delibera', 'numero_verbale', 'riparto_approvato',
        'nota_approvazione', 'pdf', 'verbale_documento_id', 'creata_da', 'approvata_da', 'approvata_il',
    ];

    protected $casts = [
        'cifre' => 'array',
        'nota' => 'array',
        'pdf' => 'array',
        'numero' => 'integer',
        'forma' => 'integer',
        'calcolato_il' => 'datetime',
        'data_delibera' => 'date',
        'approvata_il' => 'datetime',
        'riparto_approvato' => 'boolean',
    ];

    public function condominio(): BelongsTo
    {
        return $this->belongsTo(Condominio::class);
    }

    public function esercizio(): BelongsTo
    {
        return $this->belongsTo(Esercizio::class);
    }

    /** I documenti della versione: il PDF del fascicolo (o le sue parti, o quello caricato) e il verbale. */
    public function documenti(): MorphMany
    {
        return $this->morphMany(Documento::class, 'documentable');
    }

    public function verbale(): BelongsTo
    {
        return $this->belongsTo(Documento::class, 'verbale_documento_id');
    }

    public function creataDa(): BelongsTo
    {
        return $this->belongsTo(User::class, 'creata_da');
    }

    public function approvataDa(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approvata_da');
    }
}
