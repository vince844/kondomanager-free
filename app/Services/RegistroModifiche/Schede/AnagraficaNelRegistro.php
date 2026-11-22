<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Services\RegistroModifiche\Schede;

use App\Models\Anagrafica;
use Illuminate\Database\Eloquent\Model;

/** La persona (anagrafica): le parole di `lang/it/anagrafiche.php` (chiave `label`), la pagina `AnagraficheEdit.vue`. */
final class AnagraficaNelRegistro extends SchedaNelRegistro
{
    public const MODELLO = Anagrafica::class;

    public const AREA = 'persone';

    public const TIPO = 'anagrafica';

    public const PAROLA = 'Anagrafica';

    public const ETICHETTE = [
        'nome' => 'Nome e cognome',
        'indirizzo' => 'Indirizzo di residenza',
        'email' => 'Indirizzo email primario',
        'email_secondaria' => 'Indirizzo email secondario',
        'pec' => 'Indirizzo email PEC',
        'codice_fiscale' => 'Codice fiscale',
        'tipologia_documento' => 'Tipologia documento',
        'numero_documento' => 'Numero documento',
        'scadenza_documento' => 'Scadenza documento',
        'luogo_nascita' => 'Luogo di nascita',
        'data_nascita' => 'Data di nascita',
        'telefono' => 'Telefono',
        'cellulare' => 'Cellulare',
        'note' => 'Note aggiuntive',
        // Il mezzo dell'art. 66 disp. att. per la convocazione (§6.2 del progetto della tappa 2).
        'mezzo_convocazione' => 'Come si convoca',
        'pec_comunicata_il' => 'PEC comunicata dalla persona il',
    ];

    public const FORMATI = [
        'tipologia_documento' => ['scelta', ['passport' => 'Passaporto', 'id_card' => 'Carta d\'identità']],
        'scadenza_documento' => 'data',
        'data_nascita' => 'data',
        'mezzo_convocazione' => ['scelta', ['pec' => 'PEC', 'raccomandata' => 'Raccomandata', 'a_mano' => 'A mano', 'fax' => 'Fax']],
        'pec_comunicata_il' => 'data',
    ];

    /** L'utenza collegata si sceglie dalla scheda dell'utente: è un atto sulle utenze, non un dato della persona. */
    public const ESCLUSI = ['user_id'];

    public const DATI_DELLA_PERSONA = ['nome', 'indirizzo', 'email', 'email_secondaria', 'pec', 'codice_fiscale', 'tipologia_documento',
        'numero_documento', 'scadenza_documento', 'luogo_nascita', 'data_nascita', 'telefono', 'cellulare', 'note'];

    public const RIPRISTINABILI = ['nome', 'indirizzo', 'email', 'email_secondaria', 'pec', 'codice_fiscale', 'tipologia_documento',
        'numero_documento', 'scadenza_documento', 'luogo_nascita', 'data_nascita', 'telefono', 'cellulare', 'note'];

    public function nome(Model $modello): string
    {
        return 'Anagrafica di ' . $modello->getAttribute('nome');
    }

    /** I condomini della persona: alla creazione il legame non c'è ancora (lo scrive il gesto dei condomini, subito dopo). */
    public function condomini(Model $modello): array
    {
        if (! $modello->exists) {
            return [];
        }

        return $modello->condomini()->orderBy('condomini.id')->pluck('condomini.nome', 'condomini.id')
            ->mapWithKeys(fn ($nome, $id) => [(int) $id => (string) $nome])->all();
    }

    public function soggetti(Model $modello): array
    {
        return [['anagrafica', (int) $modello->getKey()]];
    }
}
