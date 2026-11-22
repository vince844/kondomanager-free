<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Services\RegistroModifiche\Schede;

use App\Models\Condominio;
use App\Models\Segnalazione;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/** La segnalazione (`lang/it/segnalazioni.php`, `SegnalazioniEdit.vue`); l'autore dal portale è fra i soggetti (P9). */
final class SegnalazioneNelRegistro extends SchedaNelRegistro
{
    public const MODELLO = Segnalazione::class;

    public const AREA = 'segnalazioni';

    public const TIPO = 'segnalazione';

    public const PAROLA = 'Segnalazione';

    public const ETICHETTE = [
        'subject' => 'Oggetto segnalazione',
        'description' => 'Descrizione segnalazione',
        'assigned_to' => 'Assegnata a',
        'condominio_id' => 'Condominio',
        'priority' => 'Priorità segnalazione',
        'stato' => 'Stato segnalazione',
        'is_locked' => 'Bloccata',
        'is_featured' => 'Segnalazione in evidenza',
        'is_private' => 'Privata',
        'is_published' => 'Visibilità segnalazione',
        'is_approved' => 'Approvazione',
        'can_comment' => 'Permetti di commentare',
        'allegati_accesso' => 'Accesso agli allegati',
        'allegati_moderati' => 'Allegati in moderazione',
        'commenti_moderati' => 'Commenti in moderazione',
    ];

    public const FORMATI = [
        'assigned_to' => ['chiave', User::class, 'name'],
        'condominio_id' => ['chiave', Condominio::class, 'nome'],
        'priority' => ['scelta', ComunicazioneNelRegistro::PRIORITA],
        'stato' => ['scelta', ['aperta' => 'Aperta', 'in lavorazione' => 'In lavorazione', 'chiusa' => 'Chiusa']],
        'is_locked' => 'si_no',
        'is_featured' => 'si_no',
        'is_private' => 'si_no',
        'is_published' => ['scelta', ['0' => 'Bozza', '1' => 'Pubblicata']],
        'is_approved' => ['scelta', ['0' => 'Da approvare', '1' => 'Approvata']],
        'can_comment' => 'si_no',
        'allegati_accesso' => ['scelta', ComunicazioneNelRegistro::ACCESSO_ALLEGATI],
        'allegati_moderati' => 'si_no',
        'commenti_moderati' => 'si_no',
    ];

    /** Nessuna pagina la scrive. */
    public const ESCLUSI = ['is_resolved'];

    /** Chi ha assegnato e quando: la riga stessa lo dice. */
    public const DEL_PROGRAMMA = ['assegnata_da', 'assegnata_il'];

    public const RIPRISTINABILI = ['subject', 'description'];

    public function nome(Model $modello): string
    {
        return 'Segnalazione «' . $modello->getAttribute('subject') . '»';
    }

    public function soggetti(Model $modello): array
    {
        $autore = $modello->getAttribute('created_by');

        return $autore === null ? [] : [['utente', (int) $autore]];
    }
}
