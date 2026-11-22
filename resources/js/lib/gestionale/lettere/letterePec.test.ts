// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * Le parole delle lettere per PEC dalla casella dello studio (1.12.0-beta.13): i canali del menu con quelli spenti e il perché, i conteggi, la riga sopra la
 * tabella per le PEC non comunicate, la conferma di «Manda N PEC», la riga del registro di una PEC (la frase la scrive il server), la colonna «Ultima lettera»
 * e il termine contato dalla consegna. «Consegnata» si dice solo con la consegna provata, e solo in `consegnaProvata.ts`.
 */
import { describe, expect, test } from 'vitest';
import {
    NOMI_CANALI, SOLO_CARTA, opzioniDelCanale, personeDelCanale, testoConfermaPec, testoConteggi, testoOltreTetto, testoPecNonComunicate, testoRigaDelRegistro,
    testoTermineDallaConsegna, testoUltimaLettera, type PersonaDellAnteprima,
} from './lettere';
import { BADGE_CONSEGNATA, testoPecDellUltimaLettera } from './consegnaProvata';

const persona = (id: number, canale: string, altro: Partial<PersonaDellAnteprima> = {}): PersonaDellAnteprima => ({
    id, nome: `P${id}`, totale: 1000, righe: 1, credito: 0, canale: canale as PersonaDellAnteprima['canale'], canali: ['pec', 'pec_e_carta', 'stampa', 'zip'],
    indirizzi: [], senza_email: true, solo_pec: false, senza_indirizzo_postale: false, ...altro,
});

describe('i canali', () => {
    test('i nomi del menu, e la frase della diffida come quella del server', () => {
        expect(NOMI_CANALI).toEqual({ email: 'Email', pec: 'PEC dello studio', pec_e_carta: 'PEC e carta', stampa: 'Stampa per la posta', zip: 'PDF per persona (.zip)' });
        expect(SOLO_CARTA).toBe('Diffida e messa in mora non partono per email: mandale per PEC dalla casella dello studio, o stampale o scarica un PDF per persona e '
            + 'registra la spedizione.');
    });

    test('le voci del menu di una persona: quelle del tipo, con il sotto-titolo; la PEC spenta con il perché', () => {
        const p = persona(1, 'stampa', { canali_spenti: { pec: 'Prima imposta la casella PEC dello studio.', pec_e_carta: 'Prima imposta la casella PEC dello studio.' } });
        expect(opzioniDelCanale(p)).toEqual([
            { valore: 'pec', nome: 'PEC dello studio', sotto: 'Prima imposta la casella PEC dello studio.', spento: true },
            { valore: 'pec_e_carta', nome: 'PEC e carta', sotto: 'Prima imposta la casella PEC dello studio.', spento: true },
            { valore: 'stampa', nome: 'Stampa per la posta', sotto: 'poi registri la spedizione', spento: false },
            { valore: 'zip', nome: 'PDF per persona (.zip)', sotto: 'da mandare o stampare tu', spento: false },
        ]);
        expect(opzioniDelCanale(persona(2, 'pec'))[0]).toEqual({ valore: 'pec', nome: 'PEC dello studio', sotto: 'dalla casella PEC, con la ricevuta', spento: false });
    });

    test('«PEC e carta» conta fra le PEC e fra le lettere da stampare', () => {
        const persone = [persona(1, 'pec'), persona(2, 'pec_e_carta'), persona(3, 'stampa')];
        expect(personeDelCanale(persone, {}, [], ['pec', 'pec_e_carta']).map((p) => p.id)).toEqual([1, 2]);
        expect(personeDelCanale(persone, {}, [], ['stampa', 'pec_e_carta']).map((p) => p.id)).toEqual([2, 3]);
        expect(testoConteggi({ email: 0, pec: 1, pec_e_carta: 1, stampa: 1, zip: 0 })).toBe('Lettere: 1 per PEC, 1 per PEC e su carta, 1 da stampare.');
        expect(testoOltreTetto({ email: 0, pec: 0, pec_e_carta: 150, stampa: 60, zip: 0 }, 200)).toContain('Le lettere da stampare sono 210');
    });
});

describe('la PEC non comunicata e la conferma', () => {
    test('la riga sopra la tabella conta le PEC scelte che la persona non ha comunicato', () => {
        expect(testoPecNonComunicate(0)).toBeNull();
        expect(testoPecNonComunicate(1)).toBe('1 PEC non risulta comunicata dalla persona: la ricevuta dirà che la PEC è arrivata in quella casella. Se la persona non '
            + 'l\'ha mai data, scegli tu se mandarla lì o stamparla.');
        expect(testoPecNonComunicate(2)).toContain('2 PEC non risultano comunicate dalle persone');
    });

    test('la conferma di «Manda N PEC»: la casella da cui partono, che cosa vuol dire «partita», dove arriva la ricevuta', () => {
        expect(testoConfermaPec(2, 'diffida', 'studio.costa@pec.it')).toEqual([
            'Mandare 2 diffide per PEC?',
            'Partono adesso dalla casella PEC dello studio, studio.costa@pec.it, ognuna con il suo PDF.',
            '«Partita» vuol dire che il gestore l\'ha accettata. La consegna la dice la ricevuta di consegna, che arriva nella casella dello studio.',
            'Salva la ricevuta (il file .eml) e trascinala sulla lettera, nella corrispondenza della persona: il programma ne legge il giorno e l\'ora.',
        ]);
        expect(testoConfermaPec(1, 'sollecito', 'x@pec.it')[0]).toBe('Mandare 1 sollecito per PEC?');
    });
});

describe('il registro e l\'ultima lettera', () => {
    test('la riga di una PEC è la frase del server', () => {
        expect(testoRigaDelRegistro({ canale: 'pec', destinatario: 'u@pec.it', stato: 'inviata', quando: '2026-12-03 10:12', errore: null,
            pec: { frase: 'Partita per PEC il 03/12 alle 10:12 a u@pec.it, dalla casella dello studio. Il gestore l\'ha accettata.' } }))
            .toBe('Partita per PEC il 03/12 alle 10:12 a u@pec.it, dalla casella dello studio. Il gestore l\'ha accettata.');
    });

    test('l\'ultima lettera per PEC: «Diffida · PEC partita il 03/12/2026»; con la consegna provata, dalla consegna', () => {
        expect(testoUltimaLettera({ tipo: 'diffida', data: '2026-12-03', canale: 'pec', stato: 'inviata' })).toBe('Diffida · PEC partita il 03/12/2026');
        expect(testoUltimaLettera({ tipo: 'diffida', data: '2026-12-03', canale: 'pec_e_carta', stato: 'inviata' })).toBe('Diffida · PEC partita il 03/12/2026, e su carta');
        expect(testoUltimaLettera({ tipo: 'diffida', data: '2026-12-03', canale: 'pec', stato: 'inviata', consegna: { giorno: '2026-12-04', a_mano: false } }))
            .toBe(`Diffida · ${testoPecDellUltimaLettera({ giorno: '2026-12-04', a_mano: false })}`);
        expect(testoPecDellUltimaLettera({ giorno: '2026-12-04', a_mano: false })).toBe('PEC consegnata il 04/12/2026');
        expect(testoPecDellUltimaLettera({ giorno: '2026-12-04', a_mano: true })).toBe('PEC consegnata il 04/12/2026, scritta a mano');
        expect(BADGE_CONSEGNATA).toBe('Consegnata');
    });

    test('il termine contato dalla consegna', () => {
        expect(testoTermineDallaConsegna(null)).toBeNull();
        expect(testoTermineDallaConsegna({ entro: '2026-12-18', giorni: 15, consegna: '2026-12-03', a_mano: false }))
            .toBe('Entro il 18/12/2026: 15 giorni dal ricevimento, contati dalla consegna del 03/12/2026.');
        expect(testoTermineDallaConsegna({ entro: '2026-12-04', giorni: 1, consegna: '2026-12-03', a_mano: true }))
            .toBe('Entro il 04/12/2026: 1 giorno dal ricevimento, contato dalla consegna del 03/12/2026, scritta a mano.');
    });
});
