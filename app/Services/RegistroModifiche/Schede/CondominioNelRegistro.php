<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Services\RegistroModifiche\Schede;

use App\Models\Condominio;
use Illuminate\Database\Eloquent\Model;

/** Il condominio: le parole di `lang/it/condomini.php`, la pagina `BuildingsEdit.vue`. */
final class CondominioNelRegistro extends SchedaNelRegistro
{
    public const MODELLO = Condominio::class;

    public const AREA = 'condominio';

    public const TIPO = 'condominio';

    public const PAROLA = 'Condominio';

    public const ETICHETTE = [
        'nome' => 'Denominazione',
        'indirizzo' => 'Indirizzo e civico',
        'email' => 'Indirizzo email',
        'codice_fiscale' => 'Codice fiscale',
        'comune_catasto' => 'Comune catasto',
        'codice_catasto' => 'Codice catasto',
        'sezione_catasto' => 'Sezione',
        'foglio_catasto' => 'Foglio',
        'particella_catasto' => 'Particella',
        'note' => 'Note aggiuntive interne',
        'comune' => 'Comune',
        'provincia' => 'Provincia',
        'cap' => 'CAP',
        'anno_costruzione' => 'Anno di costruzione',
        'anno_acquisizione' => 'Anno di acquisizione',
        'numero_piani' => 'Numero di piani',
        // La scelta dell'amministratore per le assemblee (§6.2 del progetto della tappa 2).
        'tabella_proprieta_id' => 'Tabella di proprietà generale',
    ];

    public const FORMATI = [
        'tabella_proprieta_id' => ['chiave', \App\Models\Tabella::class, 'nome'],
    ];

    /** Il codice identificativo lo genera il programma alla creazione; «dimostrativo» lo scrive il condominio di prova. */
    public const DEL_PROGRAMMA = ['codice_identificativo', 'is_demo'];

    public const RIPRISTINABILI = ['nome', 'indirizzo', 'email', 'codice_fiscale', 'comune_catasto', 'codice_catasto', 'sezione_catasto', 'foglio_catasto',
        'particella_catasto', 'note', 'comune', 'provincia', 'cap', 'anno_costruzione', 'anno_acquisizione', 'numero_piani'];

    public function nome(Model $modello): string
    {
        $nome = trim((string) $modello->getAttribute('nome'));

        return str_starts_with(mb_strtolower($nome), 'condominio') ? $nome : 'Condominio ' . $nome;
    }

    public function condomini(Model $modello): array
    {
        return $modello->getKey() === null ? [] : [(int) $modello->getKey() => (string) $modello->getAttribute('nome')];
    }
}
