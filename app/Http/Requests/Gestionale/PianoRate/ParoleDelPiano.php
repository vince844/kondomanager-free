<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Http\Requests\Gestionale\PianoRate;

/**
 * Coda 686 (b), decisione 510: i messaggi del piano rate in parole semplici.
 *
 * Il salvataggio di un piano «Spese fuori preventivo» senza fatture rispondeva «fatture config è richiesto quando tipo è straordinario.»: il nome del campo
 * del modulo, con il trattino basso tolto, e il valore del codice. Vincenzo, guardando la bozza: «fatture config non significa niente per l'amministratore».
 * Qui ogni campo della creazione del piano e delle richieste vicine (la presa d'atto, l'approvazione, il ricalcolo) ha il nome che ha a video, e le regole
 * generiche hanno una frase che lo usa; le regole che dipendono da un altro campo («richiesto quando…») hanno la loro frase, perché la frase di serie
 * nominerebbe l'altro campo e il suo valore. La guardia è `tests/Feature/Gestionale/PianoRateMessaggiInParoleSempliciTest.php`: un messaggio con un nome
 * tecnico, o un campo nuovo senza il suo nome, la fanno fallire.
 */
final class ParoleDelPiano
{
    /** Il nome a video di ogni campo, come lo legge l'amministratore. */
    public const ATTRIBUTI = [
        'tipo' => 'che cosa mettere a rate',
        'fatture_config' => 'fatture da mettere a rate',
        'fatture_config.*.id' => 'fattura scelta',
        'fatture_config.*.importo' => 'importo da mettere a rate della fattura',
        'tipo_autorizzazione' => 'Tipo di autorizzazione',
        'motivazione_autorizzazione' => 'Motivazione o estremi del verbale',
        'data_delibera_assemblea' => 'Deliberata il',
        // Coda 688: il giorno dei lavori urgenti sulle voci di una gestione straordinaria.
        'giorno_dei_lavori' => 'Lavori ordinati il',
        'delibera_id' => 'La delibera',
        'gestione_id' => 'Gestione di riferimento',
        'nome' => 'Nome identificativo',
        'descrizione' => 'Descrizione',
        'metodo_distribuzione' => 'Metodo di distribuzione',
        'numero_rate' => 'numero delle rate',
        'giorno_scadenza' => 'Giorno del mese',
        'data_prima_scadenza' => 'Prima scadenza',
        'note' => 'Note',
        'recurrence_enabled' => 'regole di ricorrenza avanzate',
        'recurrence_frequency' => 'Frequenza',
        'recurrence_interval' => 'Intervallo',
        'recurrence_by_day' => 'giorni della settimana',
        'recurrence_until' => 'fine della ricorrenza',
        'genera_subito' => 'Genera calcolo scadenze subito',
        'capitoli_ids' => 'voci di bilancio incluse',
        'capitoli_ids.*' => 'voce di bilancio',
        'capitoli_config' => 'importi delle voci',
        'capitoli_config.*.id' => 'voce di bilancio',
        'capitoli_config.*.importo' => 'importo della voce',
        'capitoli_config.*.note' => 'nota della voce',
        'competenze_capitoli' => 'competenza delle voci',
        'competenze_capitoli.*.conto_id' => 'voce della competenza',
        'competenze_capitoli.*.tratti' => 'tratti di competenza',
        'competenze_capitoli.*.tratti.*.dal' => 'inizio del tratto',
        'competenze_capitoli.*.tratti.*.al' => 'fine del tratto',
        'saldi_config' => 'riparto manuale dei saldi',
        'saldi_config.*.saldo_id' => 'saldo da ripartire',
        'saldi_config.*.ripartizioni' => 'riparto del saldo',
        'saldi_config.*.ripartizioni.*.anagrafica_id' => 'persona del riparto',
        'saldi_config.*.ripartizioni.*.importo' => 'importo del riparto',
        // Issue 58: il giorno dei titolari scelto nella finestra «Riparto saldi iniziali».
        'saldi_config.*.alla_data' => 'a chi va il pregresso',
        // La presa d'atto (quote non assegnate, destinatari cambiati), alla creazione, al ricalcolo e quando si toglie una voce.
        'accetta_scoperti' => 'presa d\'atto delle quote non assegnate',
        'nota_scoperti' => 'nota delle quote non assegnate',
        'accetta_destinatari' => 'presa d\'atto dei destinatari cambiati',
        'nota_destinatari' => 'nota dei destinatari cambiati',
        // L'approvazione del piano (e il ritorno in bozza).
        'approvato' => 'approvazione',
        'numero_verbale' => 'Numero del verbale',
        'nota_approvazione' => 'Nota dell\'approvazione',
        // Il ricalcolo con le voci nuove della gestione.
        'orphan_ids' => 'voci da aggiungere',
        'orphan_ids.*' => 'voce da aggiungere',
    ];

    /**
     * Coda 166: l'importo di una fattura senza note a zero (o vuoto). Lo zero voleva dire «tutto» per il motore e «niente» per il carrello: la
     * frase chiede la cifra, e dice quella che resta.
     */
    public static function importoFatturaAZero(string $numero, int $resta): string
    {
        return "Scrivi quanto mettere a rate della fattura n. {$numero}: un importo più grande di zero, al massimo "
            . \App\Helpers\MoneyHelper::format($resta) . ', quanto ne resta da mettere a rate.';
    }

    /** Coda 166: l'importo di una fattura senza note oltre quanto ne resta, con la parte da mettere a rate, ciò che chiedono gli altri piani e il massimo. */
    public static function importoFatturaOltre(string $numero, int $documento, int $altriPiani, int $resta): string
    {
        return "Della fattura n. {$numero} si possono mettere a rate " . \App\Helpers\MoneyHelper::format($documento)
            . ($altriPiani > 0 ? ', e altri piani ne chiedono già ' . \App\Helpers\MoneyHelper::format($altriPiani) : '')
            . ': con questo piano al massimo ' . \App\Helpers\MoneyHelper::format($resta) . '.';
    }

    /** Issue 58: il giorno dei titolari scelto per un saldo dell'unità non è fra quelli della finestra (una pagina vecchia, una richiesta a mano). */
    public static function giornoNonOfferto(string $unita): string
    {
        return 'A chi va il pregresso' . ($unita !== '' ? " di {$unita}" : '') . ': il giorno scelto non è fra quelli che la finestra «Riparto saldi iniziali» '
            . 'offre. Riapri la finestra e scegli di nuovo.';
    }

    /** Le frasi delle regole: quelle di un campo prima, poi quelle di ogni regola, che usano il nome a video. */
    public static function messaggi(): array
    {
        $esempioVerbale = 'per esempio «Verbale del 12/03/2026»';

        return [
            // Che cosa mettere a rate: le due schede del nuovo piano (decisione 510).
            'tipo.required' => 'Scegli che cosa mettere a rate: «Dalle voci del preventivo» o «Spese fuori preventivo».',
            'tipo.in' => 'Scegli che cosa mettere a rate: «Dalle voci del preventivo» o «Spese fuori preventivo».',
            // Il caso del forum (18/11/2026): «fatture config è richiesto quando tipo è straordinario.».
            'fatture_config.required_if' => 'Scegli almeno una fattura da mettere a rate.',
            'fatture_config.array' => 'Scegli almeno una fattura da mettere a rate.',
            'fatture_config.*.importo.required_with' => 'Scrivi quanto mettere a rate per ogni fattura scelta.',
            // Issue 58: un saldo dell'unità si divide a mano o va ai titolari di un giorno; senza nessuno dei due segue la regola e non si manda.
            'saldi_config.*.ripartizioni.required_without' => 'Dividi a mano il pregresso, o scegli a chi va.',
            'saldi_config.*.alla_data.date_format' => 'A chi va il pregresso: il giorno scelto non è arrivato nella forma giusta. Riapri la finestra e scegli di nuovo.',
            // D194: gli errori dello scudo legale, che ora la pagina mostra sotto i due campi.
            'tipo_autorizzazione.required_if' => 'Scegli il tipo di autorizzazione: «Delibera Assembleare» o «Urgenza».',
            'tipo_autorizzazione.in' => 'Scegli il tipo di autorizzazione dal menu: «Delibera Assembleare» o «Urgenza».',
            'motivazione_autorizzazione.required_if' => "Scrivi la motivazione o gli estremi del verbale, {$esempioVerbale}.",
            'motivazione_autorizzazione.min' => "La motivazione è troppo corta: scrivi almeno :min caratteri, {$esempioVerbale}.",
            'gestione_id.required' => 'Scegli la gestione di riferimento.',
            'gestione_id.exists' => 'La gestione scelta non è di questo condominio, o non vale per questo esercizio: scegline una dal menu.',
            'nome.required' => 'Dai un nome al piano.',
            'nome.max' => 'Il nome del piano può avere al massimo :max caratteri.',
            'nome.unique' => 'C\'è già un piano con questo nome nella gestione scelta: scegline un altro.',
            'metodo_distribuzione.required' => 'Scegli come distribuire i saldi pregressi.',
            'metodo_distribuzione.in' => 'Scegli come distribuire i saldi pregressi, fra le tre possibilità del menu.',
            'numero_rate.required' => 'Scrivi il numero delle rate.',
            'numero_rate.integer' => 'Il numero delle rate è un numero intero, per esempio 12.',
            'giorno_scadenza.integer' => 'Il giorno del mese delle scadenze è un numero intero, per esempio 5.',
            'recurrence_frequency.required_if' => 'Scegli la frequenza della ricorrenza.',
            'recurrence_frequency.in' => 'Scegli la frequenza della ricorrenza dal menu.',
            'recurrence_interval.required_if' => 'Scrivi ogni quanto si ripete la rata, per esempio 2 per «ogni 2 mesi».',
            'recurrence_interval.integer' => 'L\'intervallo della ricorrenza è un numero intero da 1 in su.',
            'recurrence_interval.min' => 'L\'intervallo della ricorrenza è un numero intero da 1 in su.',
            'competenze_capitoli.*.tratti.required' => 'Ogni voce con una competenza propria vuole almeno un tratto di date.',
            'competenze_capitoli.*.tratti.min' => 'Ogni voce con una competenza propria vuole almeno un tratto di date.',
            'competenze_capitoli.*.tratti.max' => 'Una voce può avere al massimo :max tratti di competenza.',
            'capitoli_config.*.note.max' => 'La nota di una voce può avere al massimo :max caratteri.',
            'nota_scoperti.required_if_accepted' => 'Scrivi una nota di almeno 10 caratteri: perché procedi con delle quote non assegnate.',
            'nota_scoperti.min' => 'Scrivi una nota di almeno :min caratteri: perché procedi con delle quote non assegnate.',
            'nota_destinatari.required_if_accepted' => 'Scrivi una nota di almeno 10 caratteri: perché procedi con i destinatari cambiati.',
            'nota_destinatari.min' => 'Scrivi una nota di almeno :min caratteri: perché procedi con i destinatari cambiati.',
            'approvato.required' => 'Scegli se approvare il piano o riportarlo in bozza.',
            'numero_verbale.max' => 'Il numero del verbale può avere al massimo :max caratteri.',
            'delibera_id.integer' => 'La delibera scelta non è arrivata nella forma giusta: ricarica la pagina e scegli di nuovo.',
            'nota_approvazione.max' => 'La nota dell\'approvazione può avere al massimo :max caratteri.',

            // Le regole generiche, con il nome a video. Le regole «quando…» hanno la frase senza l'altro campo: il suo nome e il suo valore sono del codice.
            'required' => 'Manca «:attribute».',
            'required_if' => 'Manca «:attribute».',
            'required_with' => 'Manca «:attribute».',
            'required_if_accepted' => 'Manca «:attribute».',
            'array' => '«:attribute» non è arrivato nella forma che la pagina manda: ricarica la pagina e riprova.',
            'integer' => '«:attribute» dev\'essere un numero intero.',
            'numeric' => '«:attribute» dev\'essere un numero.',
            'string' => '«:attribute» dev\'essere un testo.',
            'boolean' => '«:attribute» dev\'essere un sì o un no.',
            'date' => '«:attribute» non è una data valida.',
            'exists' => '«:attribute» non è di questo condominio, o non c\'è più: ricarica la pagina e scegli di nuovo.',
            'in' => '«:attribute»: scegli una delle possibilità del menu.',
        ];
    }
}
