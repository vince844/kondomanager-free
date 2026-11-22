// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * Le parole della tenuta senza rete del verbale, come le scene 3 e 4 della bozza approvata dell'editor: la scritta nei quattro stati (e la sessione scaduta,
 * il server che non risponde), il lavoro ritrovato, le due versioni; e il testo di ogni parte come lo vede chi scrive.
 */
import { describe, expect, test } from 'vitest';
import type { NodoDelVerbale } from '@/lib/assemblee/verbale/struttura';
import type { VerbaleDellaPagina } from '@/types/verbale';
import type { EsitoDiUnGesto } from './coda';
import type { VoceInCoda } from './deposito';
import { correzioni, dueVersioniAperte, fraseDelLavoroRitrovato, modificheRitrovate, scrittaDelSalvataggio, vistaDelleParti } from './verbale';

const doc = (t: string): NodoDelVerbale => ({ type: 'doc', content: [{ type: 'paragraph', content: [{ type: 'text', text: t }] }] });
const punti = [{ punto_id: 31, numero: 4 }, { punto_id: 33, numero: 6 }];
const voce = (parte: string, t: string, base: number, il = '2026-12-15T19:16:00Z', ordine = 1): VoceInCoda => ({ gesto: `g-${parte}-${ordine}`, ambito: 'verbale:12:5', ordine,
    sessione: 'prima', tipo: 'testo', dati: { verbale_id: 7, parte, documento: doc(t) }, base, orologio: il, segnato_il: il, fondi: 'testo:' + parte });
const base = { stato: 'in_linea' as const, serverFermo: false, daMandare: false, inAttesa: 0, salvatoAlle: null, partite: null };

describe('la scritta del salvataggio (N4)', () => {
    test('i quattro stati della bozza: salvato, salvataggio, senza connessione con le modifiche in attesa, di nuovo in linea', () => {
        expect(scrittaDelSalvataggio({ ...base, salvatoAlle: '2026-12-15T19:14:00Z' }))
            .toEqual({ stato: 'salvato', titolo: 'Salvato alle 20:14', dettaglio: 'tutto sul server' });
        expect(scrittaDelSalvataggio({ ...base, daMandare: true })).toEqual({ stato: 'salvataggio', titolo: 'Salvataggio…', dettaglio: 'pochi secondi' });
        expect(scrittaDelSalvataggio({ ...base, stato: 'invio' })).toMatchObject({ stato: 'salvataggio' });
        expect(scrittaDelSalvataggio({ ...base, stato: 'senza_rete', daMandare: true, inAttesa: 3 }))
            .toEqual({ stato: 'senza_rete', titolo: 'Non salvato: senza connessione. Lo tengo qui, nel browser.', dettaglio: '3 modifiche in attesa' });
        expect(scrittaDelSalvataggio({ ...base, stato: 'senza_rete', inAttesa: 1 }).dettaglio).toBe('1 modifica in attesa');
        expect(scrittaDelSalvataggio({ ...base, partite: { quante: 3, alle: '2026-12-15T19:19:00Z' }, salvatoAlle: '2026-12-15T19:19:00Z' }))
            .toEqual({ stato: 'di_nuovo_in_linea', titolo: 'Di nuovo in linea: salvato alle 20:19', dettaglio: 'le 3 modifiche sono partite' });
    });

    test('la sessione scaduta, il permesso, il server che non risponde: il testo resta qui, e la scritta lo dice', () => {
        expect(scrittaDelSalvataggio({ ...base, stato: 'sessione_scaduta', inAttesa: 2 }))
            .toEqual({ stato: 'sessione_scaduta', titolo: 'Non salvato: la sessione è scaduta. Lo tengo qui, nel browser.', dettaglio: '2 modifiche in attesa · rientra' });
        expect(scrittaDelSalvataggio({ ...base, stato: 'non_puoi' }).titolo).toBe('Non salvato: per scrivere il verbale serve «Gestisce le assemblee». Lo tengo qui, nel browser.');
        expect(scrittaDelSalvataggio({ ...base, stato: 'senza_rete', serverFermo: true }).titolo).toBe('Non salvato: il server non risponde. Lo tengo qui, nel browser.');
    });
});

describe('il lavoro ritrovato (N5)', () => {
    const modifiche = [{ parte: 'punto:31', il: '2026-12-15T19:16:00Z' }, { parte: 'punto:31', il: '2026-12-15T19:17:00Z' }, { parte: 'intero', il: '2026-12-15T19:18:00Z' }];

    test('prima che partano: quante, di quando, che cosa, «partono da sole quando torna la rete»', () => {
        expect(fraseDelLavoroRitrovato({ modifiche, finito: false, arrivate_alle: null, esiti: [] }, punti)).toEqual({
            titolo: 'Ho ritrovato 3 modifiche che non erano partite: partono da sole quando torna la rete.',
            righe: ['Del 15/12/2026, dalle 20:16 alle 20:18: due correzioni alla discussione del punto 4 e una correzione al verbale.'],
        });
    });

    test('partite e arrivate, come la scena 4: «e le ho mandate», «Arrivate alle 20:31»; con due versioni, quale parte aspetta la scelta', () => {
        const arrivato = { esito: 'applicato', voce: voce('punto:31', 'x', 3) } as EsitoDiUnGesto;
        const due = { esito: 'in_conflitto', voce: voce('intero', 'y', 2) } as EsitoDiUnGesto;
        expect(fraseDelLavoroRitrovato({ modifiche, finito: true, arrivate_alle: '2026-12-15T19:31:00Z', esiti: [arrivato] }, punti)).toEqual({
            titolo: 'Ho ritrovato 3 modifiche che non erano partite, e le ho mandate.',
            righe: ['Del 15/12/2026, dalle 20:16 alle 20:18: due correzioni alla discussione del punto 4 e una correzione al verbale.', 'Arrivate alle 20:31.'],
        });
        expect(fraseDelLavoroRitrovato({ modifiche: modifiche.slice(2), finito: true, arrivate_alle: '2026-12-15T19:31:00Z', esiti: [due] }, punti).righe)
            .toEqual(['Del 15/12/2026, alle 20:18: una correzione al verbale.', 'Il verbale ha due versioni: non sovrascrivo niente, scegli tu.', 'Arrivata alle 20:31.']);
    });

    test('la pagina chiusa prima della pausa: il testo era sul disco, e conta come una modifica di quella parte', () => {
        expect(modificheRitrovate([{ parte: 'punto:31', il: '2026-12-15T19:16:00Z' }], [voce('punto:31', 'a', 3, '2026-12-15T19:16:30Z'),
            voce('punto:33', 'b', 0, '2026-12-15T19:17:00Z', 2)])).toEqual([{ parte: 'punto:31', il: '2026-12-15T19:16:00Z' }, { parte: 'punto:33', il: '2026-12-15T19:17:00Z' }]);
        expect(correzioni('punto:33', 1, punti)).toBe('una correzione alla discussione del punto 6');
        expect(correzioni('punto:99', 12, punti)).toBe('12 correzioni alla discussione di un punto');
    });
});

describe('le due versioni (N6)', () => {
    const verbale = {
        parti: { 'punto:31': { documento: doc('Dal tablet'), revisione: 5, impronta: 'x', caratteri: 10, dispositivo: 'tablet di Marco Galli#ab12cd34', scritta_il: '2026-12-15T19:24:00Z' } },
        in_attesa: [{ id: 41, parte: 'punto:31', numero: 3, perche: null, impronta: 'y', dispositivo: 'computer di Andrea Costa#11aa22bb', segnata_il: '2026-12-15T19:18:00Z',
            arrivata_il: '2026-12-15T19:31:00Z', chi: 'Andrea Costa', in_attesa: true, attesa: 'due_versioni', scelta: null, scelta_il: null,
            nome_della_parte: 'la discussione del punto 4', documento: doc('Dal portatile') }],
    } as unknown as VerbaleDellaPagina;

    test('detto da chi aveva il testo arrivato dopo: «cambiata anche dal tablet di Marco Galli, alle 20:24»', () => {
        const [d] = dueVersioniAperte(verbale, 'computer di Andrea Costa#11aa22bb');
        expect(d.frase).toBe('La discussione del punto 4 è stata cambiata anche dal tablet di Marco Galli, alle 20:24. Non sovrascrivo niente: le due versioni restano, '
            + 'e scegli tu quale tenere, o le unisci.');
        expect(d).toMatchObject({ parte: 'punto:31', versione_id: 41, arrivata: { documento: doc('Dal portatile'), daQui: true }, adesso: { documento: doc('Dal tablet'), daQui: false } });
    });

    test('detto dal tablet: l\'altro è il computer di Andrea Costa, all\'ora in cui aveva scritto', () => {
        expect(dueVersioniAperte(verbale, 'tablet di Marco Galli#ab12cd34')[0].frase)
            .toMatch(/^La discussione del punto 4 è stata cambiata anche dal computer di Andrea Costa, alle 20:18\./);
        expect(dueVersioniAperte({ ...verbale, in_attesa: [{ ...verbale.in_attesa[0], scelta: 'questa' }] } as VerbaleDellaPagina, 'x')).toEqual([]);
    });
});

describe('il testo di ogni parte come lo vede chi scrive', () => {
    test('in memoria > in coda > arrivato > server; ciò che è qui parte dalla base del suo gesto, mai da quella del server', () => {
        const server = { 'punto:31': { documento: doc('server'), revisione: 9 }, intero: { documento: doc('verbale'), revisione: 2 }, 'punto:33': { documento: doc('vecchio'), revisione: 1 } };
        const vista = vistaDelleParti(server, [voce('punto:31', 'in coda', 4)], { 'punto:33': { documento: doc('arrivato'), revisione: 3 } }, { intero: doc('in memoria') });
        expect(vista['punto:31']).toEqual({ documento: doc('in coda'), revisione: 4, qui: true });
        expect(vista['punto:33']).toEqual({ documento: doc('arrivato'), revisione: 3, qui: false });
        expect(vista.intero).toEqual({ documento: doc('in memoria'), revisione: 2, qui: true });
    });
});
