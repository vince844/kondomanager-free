// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * La sala vista dal dispositivo: lo stato del server più i gesti in coda, con la barra che conta sul posto; i voti di una votazione aperta senza rete; il
 * numero che il server non ridice; i gesti arrivati e non ancora nello stato tenuto (lo sfarfallio, C46); le frasi del lavoro ritrovato, come le scrive la
 * bozza della sala (la scena 2).
 */

import { describe, expect, test } from 'vitest';
import { riferimento, type Ritrovati } from './coda';
import { cercaNelloStato, contaIVoti, fraseDeiRitrovati, idPerIlGesto, proiettaLaSala, tipoDiDispositivo } from './sala';
import { gestoDiProva as g, PERSONE, salaDiProva } from './salaDiProva';

describe('la sala con i gesti in coda', () => {
    test('le entrate e le uscite senza rete: le presenze, le versioni e la barra contata sul posto; lo stato del server non si tocca', () => {
        const sala = salaDiProva();
        const prima = JSON.stringify(sala);
        const p = proiettaLaSala(sala, [
            g('entra', { seduta_id: 8, persona: { anagrafica_id: PERSONE.rossi }, come: 'in_sala' }, '2026-12-15T18:35:00Z', 0),
            g('entra', { seduta_id: 8, persona: { anagrafica_id: PERSONE.bianchi }, come: 'in_sala' }, '2026-12-15T18:36:00Z', 0),
            g('esce', { seduta_id: 8, persona: { anagrafica_id: PERSONE.romano } }, '2026-12-15T18:37:00Z', 1),
        ]);
        expect(JSON.stringify(sala)).toBe(prima);
        expect(p.barra!.barra).toBe('4 su 26 · 435,80 su 1.000,00');
        expect(p.versioni).toMatchObject({ 'anagrafica:1': 1, 'anagrafica:2': 1, 'anagrafica:3': 2 });
        expect(p.presenze.find((x) => x.chiave === 'anagrafica:3')!.uscita_il).toBe('2026-12-15T18:37:00Z');
        expect(p.in_coda).toEqual(['presenza:anagrafica:1', 'presenza:anagrafica:2', 'presenza:anagrafica:3']);
    });

    test('la barra senza rete conta come il server: 3 presenti da 33,80 e due che entrano', () => {
        const sala = salaDiProva();
        expect(proiettaLaSala(sala, []).barra!.barra).toBe('3 su 26 · 33,80 su 1.000,00');
        const p = proiettaLaSala(sala, [g('entra', { seduta_id: 8, persona: { anagrafica_id: 30 }, come: 'in_sala' }, undefined, 0),
            g('entra', { seduta_id: 8, persona: { anagrafica_id: 31 }, come: 'in_collegamento', motivo: 'fuori città' }, undefined, 0)]);
        expect(p.barra!.barra).toBe('5 su 26 · 89,30 su 1.000,00');
        expect(p.presenze.at(-1)).toMatchObject({ come: 'in_collegamento', motivo_collegamento: 'fuori città', nome: 'Condomino 31' });
    });

    test('la delega segnata in sala senza rete e il delegato che entra: il delegante conta presente', () => {
        const sala = salaDiProva();
        const delega = g('delega', { delegante_anagrafica_id: PERSONE.rossi, delegato: { nome: 'Ricci Stefano' } });
        const p = proiettaLaSala(sala, [delega, g('entra', { seduta_id: 8, persona: { delega_id: riferimento(delega.gesto, 'delega_id') }, come: 'in_sala' }, undefined, 0)]);
        const provvisoria = p.deleghe[0];
        expect(provvisoria).toMatchObject({ delegante_nome: 'Rossi Mario', delegato_nome: 'Ricci Stefano', stato: 'valida', arrivata_come: 'in_sala' });
        expect(provvisoria.id).toBeLessThan(0);
        expect(idPerIlGesto(provvisoria as any)).toEqual({ $gesto: delega.gesto, campo: 'delega_id' });
        expect(p.barra!.presenti).toContain(PERSONE.rossi);
        expect(p.barra!.barra.startsWith('4 su 26')).toBe(true);
    });

    test('il voto di una votazione aperta senza rete: gli intervenuti di quel momento, «Tutti i presenti favorevoli», un contrario, i conteggi e l\'esito proposto', () => {
        const sala = salaDiProva();
        const entra = g('entra', { seduta_id: 8, persona: { anagrafica_id: PERSONE.rossi }, come: 'in_sala' }, '2026-12-15T18:50:00Z', 0);
        const siVota = g('si_vota', { seduta_id: 8, punto_id: 10 }, '2026-12-15T19:00:00Z');
        const rif = riferimento(siVota.gesto, 'votazione_id');
        const p = proiettaLaSala(sala, [entra, siVota, g('tutti_favorevoli', { votazione_id: rif }, '2026-12-15T19:01:00Z'),
            g('voto', { votazione_id: rif, testa: 'p-' + PERSONE.deluca, voto: 'contrario' }, '2026-12-15T19:02:00Z', 1),
            // Chi entra dopo il voto non è un intervenuto di quel voto.
            g('entra', { seduta_id: 8, persona: { anagrafica_id: 30 }, come: 'in_sala' }, '2026-12-15T19:03:00Z', 0)]);
        const v = p.punti[0].votazioni[0];
        expect(v.id).toBeLessThan(0);
        expect(idPerIlGesto(v as any)).toEqual(rif);
        expect(v.intervenuti.numero).toBe(4);
        expect(v.conteggi!.favorevoli.teste).toBe(3);
        expect(v.conteggi!.contrari.nomi).toEqual([{ nome: 'De Luca Gianni', millesimi: 540000 }]);
        expect(v.esito_proposto).toBe('approvata');
        expect(p.in_coda).toContain(`votazione:${v.id}`);
        expect(p.in_coda).toContain(`voto:${v.id}:p-${PERSONE.deluca}`);
    });

    test('il voto su una votazione del server: la versione del voto cresce, e un voto cambiato dopo la conferma toglie l\'esito finché non si riconferma', () => {
        const sala = salaDiProva();
        const p1 = proiettaLaSala(sala, [g('voto', { votazione_id: 12, testa: 'p-3', voto: 'favorevole' }, undefined, 0),
            g('voto', { votazione_id: 12, testa: 'p-3', voto: 'contrario' }, undefined, 1)]);
        expect(p1.punti[1].votazioni[0].voti).toEqual([expect.objectContaining({ testa: 'p-3', voto: 'contrario', versione: 2, nome: 'Romano Ida' })]);
        expect(contaIVoti(p1.punti[1].votazioni[0]).non_votato.teste).toBe(2);

        sala.punti[1].votazioni[0].confermata_il = '2026-12-15T18:40:00Z';
        sala.punti[1].votazioni[0].esito = 'approvata';
        const p2 = proiettaLaSala(sala, [g('voto', { votazione_id: 12, testa: 'p-3', voto: 'contrario', perche: 'errore di battitura' }, undefined, 0)]);
        expect(p2.punti[1].votazioni[0].esito).toBeNull();
    });

    test('la conferma, la dichiarazione a verbale, il conflitto e la scelta fra due versioni', () => {
        const sala = salaDiProva();
        sala.conflitti = [{ gesto: 'x-1', tipo: 'voto', dispositivo: 'tablet di Marco Galli', questa: 'Romano Ida: Contrario', quella: 'Romano Ida: Astenuto', su: 'votazione:12',
            segnato_il: '2026-12-15T18:41:00Z' }];
        const p = proiettaLaSala(sala, [
            g('voto', { votazione_id: 12, testa: 'p-4', voto: 'favorevole' }, undefined, 0),
            g('conflitto', { votazione_id: 12, testa: 'p-4', in_conflitto: true, nota: 'è il fornitore' }),
            g('conferma', { votazione_id: 12, esito: 'approvata' }, '2026-12-15T19:10:00Z', 2),
            g('dichiarazione', { seduta_id: 8, punto_id: 40, anagrafica_id: PERSONE.romano, testo: 'Chiedo tre preventivi.' }),
            g('scegli', { gesto: 'x-1', scelta: 'quella' }),
        ]);
        const v = p.punti[1].votazioni[0];
        expect(v.voti[0]).toMatchObject({ in_conflitto: true, nota_conflitto: 'è il fornitore' });
        expect(v).toMatchObject({ esito: 'approvata', confermata_il: '2026-12-15T19:10:00Z' });
        expect(p.punti[1].esito).toBe('approvata');
        expect(p.dichiarazioni.at(-1)).toMatchObject({ nome: 'Romano Ida', testo: 'Chiedo tre preventivi.', punto_id: 40 });
        expect(p.conflitti).toEqual([]);
    });

    test('l\'entrata di chi rappresenta l\'unità con l\'altro contitolare (C53): la presenza lo porta, la barra conta una testa sola', () => {
        const sala = salaDiProva();
        // La testa di Rossi e Bianchi, contitolari dell'Int. 1.
        const t = sala.fotografia!.costituzione.teste;
        const rossi = t.find((x) => x.persone[0] === PERSONE.rossi)!;
        const bianchi = t.find((x) => x.persone[0] === PERSONE.bianchi)!;
        rossi.persone = [PERSONE.rossi, PERSONE.bianchi];
        rossi.millesimi += bianchi.millesimi;
        sala.fotografia!.costituzione.teste = t.filter((x) => x !== bianchi);
        sala.fotografia!.costituzione.totale_teste = 25;
        const p = proiettaLaSala(sala, [g('entra', { seduta_id: 8, persona: { anagrafica_id: PERSONE.rossi }, come: 'in_sala', con: [PERSONE.bianchi] }, undefined, 0)]);
        const riga = p.presenze.find((x) => x.chiave === 'anagrafica:1')!;
        expect(riga.insieme).toEqual([{ anagrafica_id: PERSONE.bianchi, nome: 'Bianchi Giorgio' }]);
        expect(p.barra!.barra.startsWith('4 su 25')).toBe(true);
        // Chi è entrato insieme non vota: fra le persone presenti c'è chi rappresenta l'unità.
        expect(p.barra!.presenti).toContain(PERSONE.rossi);
        expect(p.barra!.presenti).not.toContain(PERSONE.bianchi);
    });

    test('la dichiarazione a verbale cambiata e tolta (C53): la sala vista la mostra com\'è sul computer, anche quella nata senza rete', () => {
        const sala = { ...salaDiProva(), dichiarazioni: [{ id: 31, seduta_id: 8, punto_id: 40, anagrafica_id: PERSONE.romano, nome: 'Romano Ida', testo: 'Prima.' },
            { id: 32, seduta_id: 8, punto_id: 40, anagrafica_id: PERSONE.rossi, nome: 'Rossi Mario', testo: 'Da togliere.' }] };
        const nuova = g('dichiarazione', { seduta_id: 8, punto_id: 40, anagrafica_id: PERSONE.deluca, testo: 'Senza rete.' });
        const p = proiettaLaSala(sala, [
            g('dichiarazione_cambia', { dichiarazione_id: 31, testo: 'Dopo.' }),
            g('dichiarazione_togli', { dichiarazione_id: 32 }),
            nuova,
            g('dichiarazione_cambia', { dichiarazione_id: riferimento(nuova.gesto, 'dichiarazione_id'), testo: 'Senza rete, corretta.' }),
        ]);
        expect(p.dichiarazioni.map((d) => [d.nome, d.testo])).toEqual([['Romano Ida', 'Dopo.'], ['De Luca Gianni', 'Senza rete, corretta.']]);
        expect(p.in_coda).toContain('dichiarazione:31');
        expect(p.in_coda).toContain('dichiarazione:32');
        // Quella nata qui porta il riferimento al gesto che l'ha fatta nascere: «Modifica» e «Togli» la indicano così.
        expect((p.dichiarazioni[1] as { riferimento?: unknown }).riferimento).toEqual({ $gesto: nuova.gesto, campo: 'dichiarazione_id' });
    });

    test('i gesti arrivati e non ancora nello stato tenuto: contati come quelli in coda, con i numeri e le versioni del server, senza «su questo computer»', () => {
        const sala = salaDiProva();
        const siVota = g('si_vota', { seduta_id: 8, punto_id: 10, numero: 1 });
        const p = proiettaLaSala(sala, [g('voto', { votazione_id: riferimento(siVota.gesto, 'votazione_id'), testa: 'p-4', voto: 'contrario' }, undefined, 0)],
            undefined, {}, [
                { voce: g('entra', { seduta_id: 8, persona: { anagrafica_id: PERSONE.rossi }, come: 'in_sala' }, undefined, 0), risposta: { versione: 1 } },
                { voce: siVota, risposta: { votazione_id: 99, versione: 1 } },
                { voce: g('voto', { votazione_id: riferimento(siVota.gesto, 'votazione_id'), testa: 'p-3', voto: 'favorevole' }, undefined, 0),
                    risposta: { votazione_id: 99, versione: 1, versione_votazione: 2 } },
                { voce: g('dichiarazione', { seduta_id: 8, punto_id: 40, anagrafica_id: PERSONE.romano, testo: 'A verbale.' }), risposta: { dichiarazione_id: 71 } },
            ]);
        const votazione = p.punti[0].votazioni[0] as typeof p.punti[0]['votazioni'][0] & { riferimento?: unknown };
        // Il numero vero dal server, non uno provvisorio; la versione della votazione è quella dell'ultima risposta (il voto in coda la cresce alla sua).
        expect(votazione).toMatchObject({ id: 99, versione: 2 });
        expect(votazione.riferimento).toBeUndefined();
        expect(votazione.voti.map((v) => [v.testa, v.voto])).toEqual([['p-3', 'favorevole'], ['p-4', 'contrario']]);
        expect(p.dichiarazioni.map((d) => d.id)).toEqual([71]);
        expect(p.versioni['anagrafica:1']).toBe(1);
        expect(p.presenze.some((x) => x.chiave === 'anagrafica:1' && x.uscita_il === null)).toBe(true);
        // Su questo computer c'è solo il voto ancora in coda.
        expect(p.in_coda).toEqual(['voto:99:p-4']);
    });

    test('il numero che il server non ridice si cerca nello stato: la votazione dello stesso punto e della stessa seduta', () => {
        const sala = salaDiProva();
        expect(cercaNelloStato(sala, g('si_vota', { seduta_id: 8, punto_id: 40 }), 'votazione_id')).toBe(12);
        expect(cercaNelloStato(sala, g('si_vota', { seduta_id: 8, punto_id: 10 }), 'votazione_id')).toBeUndefined();
        expect(cercaNelloStato(null, undefined, 'votazione_id')).toBeUndefined();
    });

    test('le frasi del lavoro ritrovato, come le scrive la bozza: quanti sono arrivati, quanti hanno due versioni, che cosa erano e a che ora', () => {
        const sala = salaDiProva();
        const voti = [
            g('voto', { votazione_id: 12, testa: 'p-3', voto: 'contrario' }, '2026-12-15T18:39:00Z', 0),
            g('voto', { votazione_id: 12, testa: 'p-4', voto: 'favorevole' }, '2026-12-15T18:40:00Z', 0),
            g('voto', { votazione_id: 12, testa: 'p-5', voto: 'favorevole' }, '2026-12-15T18:40:30Z', 0),
            g('dichiarazione', { seduta_id: 8, punto_id: 40, anagrafica_id: PERSONE.romano, testo: 'A verbale.' }, '2026-12-15T18:41:00Z'),
        ];
        const esito = (i: number, e: 'applicato' | 'in_conflitto') => ({ gesto: voti[i].gesto, tipo: voti[i].tipo, esito: e, messaggio: null, voce: voti[i],
            arrivato_il: '2026-12-15T19:31:00Z', locale: false });
        const r: Ritrovati = { quanti: 4, dal: '2026-12-15T18:39:00Z', al: '2026-12-15T18:41:00Z', voci: voti, finito: true, arrivati_alle: '2026-12-15T19:31:00Z',
            esiti: [esito(0, 'in_conflitto'), esito(1, 'applicato'), esito(2, 'applicato'), esito(3, 'applicato')] };
        const f = fraseDeiRitrovati(r, sala);
        expect(f.titolo).toBe('Ho ritrovato 4 gesti che non erano partiti: 3 sono arrivati e 1 ha due versioni.');
        expect(f.righe).toEqual([
            'Del 15/12/2026, dalle 19:39 alle 19:41: i voti di De Luca Gianni e di Lombardi Sara al punto 4 e la dichiarazione a verbale di Romano Ida al punto 4 sono arrivati alle 20:31.',
            'Il voto di Romano Ida al punto 4 aspetta la tua scelta.',
        ]);

        const inAttesa = fraseDeiRitrovati({ ...r, finito: false, esiti: [] }, sala);
        expect(inAttesa.titolo).toBe('Ho ritrovato 4 gesti che non erano partiti: partono da soli quando torna la rete.');
    });

    test('il dispositivo in parole', () => {
        expect(tipoDiDispositivo('Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 Safari/605.1.15')).toBe('computer');
        expect(tipoDiDispositivo('Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) Mobile/15E148')).toBe('telefono');
        expect(tipoDiDispositivo('Mozilla/5.0 (Linux; Android 14; Pixel 8) Mobile Safari/537.36')).toBe('telefono');
        expect(tipoDiDispositivo('Mozilla/5.0 (iPad; CPU OS 17_0 like Mac OS X)')).toBe('tablet');
        expect(tipoDiDispositivo('Mozilla/5.0 (Linux; Android 14; SM-X710) Safari/537.36')).toBe('tablet');
    });
});
