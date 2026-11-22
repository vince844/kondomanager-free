// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * Le frasi dei casi speciali del registro delle modifiche (1.12.0-beta.13, §10 e §11, decisione 550), come le scrive la bozza approvata (scena 4): la durata
 * con «Adesso: …» e l'esempio della «i», lo stato della sospensione detto a parole, le righe di una persona da oscurare, il pulsante e l'esito, e gli
 * errori con le parole dell'amministratore.
 *
 * Che cosa non copre: i componenti montati (i loro test in `components/registroModifiche/`); le cifre e le date, che arrivano dal server già all'italiana.
 */
import { describe, expect, test } from 'vitest';
import {
    annoDellaFrase, esempioDellaDurata, fraseDelleSospensioni, messaggioDiErrore, rigaAdesso, testoDegliAnni, testoEsitoOscuramento, testoPulsanteOscura, testoRigheDellaPersona,
    testoSospesa,
} from './casiSpeciali';

describe('la durata', () => {
    test('«10 anni», la riga «Adesso: …» e l\'esempio della «i» con l\'anno della frase del server', () => {
        expect(testoDegliAnni(15)).toBe('15 anni');
        expect(rigaAdesso({ adesso: '10 anni' })).toBe('Come le scritture e i documenti giustificativi del condominio (art. 1130-bis c.c.). Adesso: 10 anni.');
        expect(annoDellaFrase('le righe del 2026 si tolgono da sole nel 2036')).toBe(2026);
        expect(esempioDellaDurata(10, 2026)).toBe('Esempio: con 10 anni, le righe del 2026 si tolgono da sole nel 2036.');
    });
});

describe('le sospensioni dello studio (decisione 558)', () => {
    const roma = { condominio_id: 1, nome: 'Via Roma 12', dal: '03/03/2035', motivo: 'causa Rossi', da: 'Andrea Costa', eliminato: false };

    test('«Adesso: …» dice che la cancellazione è ferma per tutto lo studio, con il condominio, il giorno e il motivo', () => {
        expect(rigaAdesso({ adesso: '10 anni', sospensioni: [roma] })).toBe('Come le scritture e i documenti giustificativi del condominio (art. 1130-bis c.c.). '
            + 'Adesso: 10 anni. La cancellazione è ferma per tutto lo studio finché è sospesa Via Roma 12 (dal 03/03/2035: causa Rossi).');
    });

    test('con più condomini, e con un condominio eliminato', () => {
        expect(fraseDelleSospensioni([roma, { ...roma, condominio_id: 2, nome: 'Corso Italia 3', dal: '01/04/2035', motivo: null, eliminato: true }]))
            .toBe('La cancellazione è ferma per tutto lo studio finché sono sospese Via Roma 12 (dal 03/03/2035: causa Rossi) e Corso Italia 3 (eliminato, dal 01/04/2035).');
        expect(fraseDelleSospensioni([])).toBe('');
    });
});

describe('la sospensione', () => {
    test('lo stato detto a parole, con il motivo e senza', () => {
        expect(testoSospesa({ dal: '12/03/2035', motivo: 'causa Rossi, tribunale di Napoli' })).toBe('Cancellazione sospesa dal 12/03/2035: causa Rossi, tribunale di Napoli');
        expect(testoSospesa({ dal: '12/03/2035', motivo: null })).toBe('Cancellazione sospesa dal 12/03/2035');
    });
});

describe('l\'oscuramento', () => {
    test('le righe della persona, il pulsante e l\'esito, al singolare e al plurale', () => {
        expect(testoRigheDellaPersona(18, '01/11/2026', '15/01/2027')).toBe('18 righe con i suoi dati, dal 01/11/2026 al 15/01/2027');
        expect(testoRigheDellaPersona(1, '01/11/2026', '01/11/2026')).toBe('1 riga con i suoi dati, del 01/11/2026');
        expect(testoRigheDellaPersona(0, null, null)).toBe('Nessuna riga del registro ha ancora i suoi dati: non c\'è niente da oscurare.');
        expect(testoRigheDellaPersona(1250, '01/11/2026', '15/01/2027')).toBe('1.250 righe con i suoi dati, dal 01/11/2026 al 15/01/2027');
        expect(testoPulsanteOscura(18)).toBe('Oscura 18 righe');
        expect(testoPulsanteOscura(1)).toBe('Oscura 1 riga');
        expect(testoEsitoOscuramento(18, '12/03/2027')).toBe('Fatto: 18 righe oscurate il 12/03/2027.');
    });
});

describe('gli errori', () => {
    test('il messaggio del campo dal 422, il 403 dell\'amministratore, la sessione finita, il resto', () => {
        expect(messaggioDiErrore({ response: { status: 422, data: { errors: { motivo: ['Scrivi perché.'] } } } }, 'motivo')).toBe('Scrivi perché.');
        expect(messaggioDiErrore({ response: { status: 403 } })).toBe('Lo può fare solo l\'amministratore.');
        expect(messaggioDiErrore({ response: { status: 419 } })).toBe('La sessione è finita: ricarica la pagina e rientra.');
        expect(messaggioDiErrore(new Error('rete'))).toBe('Non è andata: riprova fra poco.');
    });
});
