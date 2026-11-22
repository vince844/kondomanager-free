<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Services\RegistroModifiche\Schede;

use App\Models\Comunicazione;
use Illuminate\Database\Eloquent\Model;

/** La comunicazione (`lang/it/comunicazioni.php`, `ComunicazioniEdit.vue`); i destinatari e i condomini sono il gesto dei legami. */
final class ComunicazioneNelRegistro extends SchedaNelRegistro
{
    public const MODELLO = Comunicazione::class;

    public const AREA = 'comunicazioni';

    public const TIPO = 'comunicazione';

    public const PAROLA = 'Comunicazione';

    public const PRIORITA = ['bassa' => 'Bassa', 'media' => 'Media', 'alta' => 'Alta', 'urgente' => 'Urgente'];

    public const ACCESSO_ALLEGATI = ['aperti' => 'Aperti', 'consultazione' => 'Solo consultazione', 'riservati' => 'Solo amministrazione'];

    public const ETICHETTE = [
        'subject' => 'Oggetto comunicazione',
        'description' => 'Descrizione comunicazione',
        'priority' => 'Priorità comunicazione',
        'is_featured' => 'Comunicazione in evidenza',
        'is_private' => 'Privata',
        'is_published' => 'Visibilità comunicazione',
        'is_approved' => 'Approvazione',
        'can_comment' => 'Consenti commenti',
        'allegati_accesso' => 'Accesso agli allegati',
        'allegati_moderati' => 'Allegati in moderazione',
        'commenti_moderati' => 'Commenti in moderazione',
        'programmata_per' => 'Data e ora di pubblicazione',
    ];

    public const FORMATI = [
        'priority' => ['scelta', self::PRIORITA],
        'is_featured' => 'si_no',
        'is_private' => 'si_no',
        'is_published' => ['scelta', ['0' => 'Bozza', '1' => 'Pubblicata']],
        'is_approved' => ['scelta', ['0' => 'Da approvare', '1' => 'Approvata']],
        'can_comment' => 'si_no',
        'allegati_accesso' => ['scelta', self::ACCESSO_ALLEGATI],
        'allegati_moderati' => 'si_no',
        'commenti_moderati' => 'si_no',
        'programmata_per' => 'data_ora',
    ];

    /** Nessuno la scrive. */
    public const ESCLUSI = ['reference'];

    /** Ricavato dall'oggetto; chi l'ha programmata e quando è uscita (la riga «Pubblicata in bacheca» lo dice). */
    public const DEL_PROGRAMMA = ['slug', 'programmata_da', 'pubblicata_il'];

    public const RIPRISTINABILI = ['subject', 'description'];

    public function nome(Model $modello): string
    {
        return 'Comunicazione «' . $modello->getAttribute('subject') . '»';
    }

    public function condomini(Model $modello): array
    {
        if (! $modello->exists) {
            return [];
        }

        return $modello->condomini()->orderBy('condomini.id')->pluck('condomini.nome', 'condomini.id')
            ->mapWithKeys(fn ($nome, $id) => [(int) $id => (string) $nome])->all();
    }
}
