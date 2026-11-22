<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Services\RegistroModifiche\Schede;

use App\Enums\Assemblee\StatoVerbale;
use App\Models\Assemblee\VerbaleDellAssemblea;
use App\Models\Documento;
use App\Models\ModelloTesto;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Il verbale di una riunione: compare nella «Storia» dell'assemblea, che è anche il suo «Diario». Ogni gesto del verbale (la generazione, una versione, la
 * bozza stampata, la registrazione, la copia sostituita, l'annullamento, la trasmissione) scrive la sua riga; il testo sta nelle parti e nelle versioni, che
 * hanno già le loro versioni e non sono righe. Qui non si ripristina: il verbale ha «Ripristina» fra le sue versioni, e una registrazione si annulla.
 */
final class VerbaleNelRegistro extends SchedaNelRegistro
{
    public const MODELLO = VerbaleDellAssemblea::class;

    public const AREA = 'assemblee';

    public const TIPO = 'verbale';

    public const PAROLA = 'Verbale';

    public const ETICHETTE = [
        'stato' => 'Stato del verbale',
        'modello_nome' => 'Il modello',
        'numero' => 'Numero nel registro',
        'sottoscritto_il' => 'Sottoscritto il',
        'documento_id' => 'La copia sottoscritta',
        'registrato_il' => 'Registrato il',
        'registrato_da' => 'Registrato da',
        'annullato_il' => 'Annullato il',
        'annullato_motivo' => 'Perché è annullato',
        'annullato_da' => 'Annullato da',
        'sostituisce_id' => 'Sostituisce',
        'nel_libro_il' => 'Incollato nel libro dei verbali il',
    ];

    public const FORMATI = [
        'stato' => ['scelta', [StatoVerbale::IN_SALA->value => 'In sala', StatoVerbale::BOZZA->value => 'Bozza', StatoVerbale::REGISTRATO->value => 'Registrato',
            StatoVerbale::ANNULLATO->value => 'Annullato']],
        'numero' => 'numero',
        'sottoscritto_il' => 'data',
        'documento_id' => ['chiave', Documento::class, 'name'],
        'registrato_il' => 'data_ora',
        'registrato_da' => ['chiave', User::class, 'name'],
        'annullato_il' => 'data_ora',
        'annullato_da' => ['chiave', User::class, 'name'],
        'sostituisce_id' => ['chiave', VerbaleDellAssemblea::class, 'numero'],
        'nel_libro_il' => 'data',
    ];

    /** L'assemblea, il condominio, il registro e la seduta vengono dall'indirizzo della pagina. */
    public const ESCLUSI = ['assemblea_id', 'condominio_id', 'registro', 'seduta_id'];

    /**
     * Il modello scelto (il nome copiato è la riga), l'impronta dei dati della sala, il giorno della generazione, la bozza stampata e le scelte su chi lo riceve
     * li scrive il programma dentro i gesti del verbale, che hanno la loro riga.
     */
    public const DEL_PROGRAMMA = ['modello_id', 'impronta_sala', 'generato_il', 'stampata', 'trasmissione'];

    /** Il perché dell'annullamento può nominare persone. */
    public const DATI_DELLA_PERSONA = ['annullato_motivo'];

    /** «Verbale n. 42», «Verbale della riunione del 15/12/2026» prima del numero. */
    public function nome(Model $modello): string
    {
        $numero = $modello->getAttribute('numero');
        if ($numero !== null) {
            return 'Verbale n. ' . $numero;
        }
        $giorno = \App\Models\Assemblee\SedutaDellAssemblea::query()->whereKey($modello->getAttribute('seduta_id'))->value('fissata_il');

        return 'Verbale' . ($giorno !== null ? ' della riunione del ' . \Carbon\Carbon::parse($giorno)->format('d/m/Y') : '');
    }

    public function condomini(Model $modello): array
    {
        return AssembleeNelRegistroComune::condominiDellAssemblea($modello->getAttribute('assemblea_id'));
    }

    public function scheda(Model $modello): array
    {
        return [AssembleaNelRegistro::TIPO, (int) $modello->getAttribute('assemblea_id')];
    }
}
