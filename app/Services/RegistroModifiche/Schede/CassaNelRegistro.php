<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Services\RegistroModifiche\Schede;

use App\Models\Gestionale\Cassa;
use Illuminate\Database\Eloquent\Model;

/** Una risorsa (cassa, conto, fondo: `CasseEdit.vue`, P4). Non ripristinabile; i dati della banca sono il conto corrente. */
final class CassaNelRegistro extends SchedaNelRegistro
{
    public const MODELLO = Cassa::class;

    public const AREA = 'contabilita';

    public const TIPO = 'cassa';

    public const PAROLA = 'Risorsa';

    public const ETICHETTE = [
        'nome' => 'Nome identificativo',
        'descrizione' => 'Descrizione',
        'tipo' => 'Tipologia risorsa',
        'saldo_iniziale' => 'Saldo iniziale (apertura)',
        'note' => 'Note interne',
        'sottotipo_fondo' => 'Destinazione d\'uso',
        'vincolo_descrizione' => 'Specifica l\'opera/lavoro',
        'is_override_assemblea' => 'Consenti sblocco d\'emergenza',
        'motivazione_override' => 'Estremi della delibera o motivazione dello sblocco',
    ];

    public const FORMATI = [
        'tipo' => ['scelta', ['contanti' => 'Cassa contanti', 'banca' => 'Conto corrente bancario/postale', 'fondo' => 'Fondo di riserva',
            'virtuale' => 'Cassa virtuale']],
        'saldo_iniziale' => 'euro',
        'sottotipo_fondo' => ['scelta', ['generico' => 'Fondo cassa generico / Imprevisti', 'vincolato_lavori' => 'Fondo straordinario vincolato (es. Lavori)',
            'tfr' => 'Fondo accantonamento TFR', 'morosita' => 'Fondo morosità']],
        'is_override_assemblea' => 'si_no',
    ];

    /** Il condominio, il conto contabile creato con la risorsa, «attiva» alla creazione. */
    public const DEL_PROGRAMMA = ['condominio_id', 'conto_contabile_id', 'attiva'];

    public function nome(Model $modello): string
    {
        return 'Risorsa «' . $modello->getAttribute('nome') . '»';
    }
}
