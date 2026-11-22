// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * I conti piccoli delle pagine della sala, sul posto: l'ora di Roma (anche al cambio dell'ora legale), la barra e i conteggi del voto sulla sala vista dal
 * dispositivo, la versione della votazione da cui parte la conferma, la votazione di un gesto del voto (anche aperta senza rete), il quinto del delegato
 * mentre si segna la delega.
 *
 * Che cosa non copre: le pagine (i loro vitest); la sala vista dal dispositivo (`resources/js/lib/senzaRete/sala.test.ts`); i conti stessi
 * (`resources/js/lib/assemblee/sala/conti.test.ts`, con il file dei casi in comune).
 */
import { describe, expect, test } from 'vitest';
import type { DelegaDellaSala, FotografiaDellaSala, GestoDellaSala, PresenzaDellaSala, VotazioneDellaSala } from '@/types/assemblee';
import {
    baseDellaVotazione, barraSulPosto, conteggiSulPosto, giornoDellaSeduta, istanteDiRoma, laVotazioneDelGesto, oraDiRoma, quintoDelDelegato,
} from './sala';
import { proiettaLaSala } from '@/lib/senzaRete/sala';
import { salaAperta } from './salaDiProva';

const M = (millesimi: number) => Math.round(millesimi * 100000);

/** Ventisei teste: Rossi (98,30), Bianchi Giorgio (41,30), Costa (62,10), Fontana (58,20), De Luca (5,40), più ventuno da 33,08… fino a 1.000. */
function fotografia(): FotografiaDellaSala {
    const teste = [
        { chiave: 'p-1', nome: 'Rossi Mario', persone: [1], unita: [101], millesimi: M(98.3) },
        { chiave: 'p-2', nome: 'Bianchi Giorgio', persone: [2], unita: [102], millesimi: M(41.3) },
        { chiave: 'p-3', nome: 'Costa Teresa', persone: [3], unita: [103], millesimi: M(62.1) },
        { chiave: 'p-4', nome: 'Fontana Laura', persone: [4], unita: [104], millesimi: M(58.2) },
        { chiave: 'p-5', nome: 'De Luca Gianni', persone: [5], unita: [105], millesimi: M(5.4) },
    ];
    let resto = 100000000 - teste.reduce((n, t) => n + t.millesimi, 0);
    for (let i = 6; i <= 26; i++) {
        const quota = i === 26 ? resto : Math.floor(resto / (27 - i));
        resto -= quota;
        teste.push({ chiave: `p-${i}`, nome: `Condomino ${i}`, persone: [i], unita: [100 + i], millesimi: quota });
    }
    const persone: Record<string, { nome: string }> = {};
    for (const t of teste) persone[String(t.persone[0])] = { nome: t.nome };

    return {
        assemblea_id: 9, seduta_id: 2, giorno: '2026-12-15', convocazione: 'seconda', provvisoria: false, teste_caso_misto: 'una_testa', tabella: null, persone,
        unita: {}, costituzione: { totale_teste: 26, totale_valore: 100000000, teste }, punti: [], avvisi: [],
    };
}
const presenza = (id: number, entrata: string, uscita: string | null = null): PresenzaDellaSala => ({
    id, chiave: `anagrafica:${id}`, anagrafica_id: id, nome: `Persona ${id}`, come: 'in_sala', entrata_il: entrata, uscita_il: uscita, caduto: false, rappresenta: null,
    motivo_collegamento: null,
});
const gesto = <T extends GestoDellaSala['tipo']>(tipo: T, dati: GestoDellaSala<T>['dati'], base: number | null = null): GestoDellaSala =>
    ({ gesto: `${tipo}-${Math.random()}`, tipo, dati, base, orologio: '2026-12-15T18:41:00Z', segnato_il: '2026-12-15T18:41:00Z' }) as GestoDellaSala;
const delega = (id: number, delegante: number, delegato: number | null, nome: string, stato: DelegaDellaSala['stato'] = 'valida'): DelegaDellaSala => ({
    id, delegante_anagrafica_id: delegante, delegante_nome: `Persona ${delegante}`, delegato_anagrafica_id: delegato, delegato_nome: nome,
    chiave_delegato: delegato !== null ? `anagrafica:${delegato}` : `delega:${id}`, unita: [], punti: null, arrivata_come: 'carta_prima', arrivata_parola: 'su carta',
    ricevuta_il: '2026-12-01', stato, motivo: null, quinto: null, da_decidere: false, documento_id: null,
});

describe('l\'ora di Roma', () => {
    test('d\'inverno e d\'estate, e al cambio dell\'ora', () => {
        expect(istanteDiRoma('2026-12-15', '19:05')).toBe('2026-12-15T18:05:00.000Z');
        expect(istanteDiRoma('2026-07-01', '19:05')).toBe('2026-07-01T17:05:00.000Z');
        expect(istanteDiRoma('2026-03-29', '03:30')).toBe('2026-03-29T01:30:00.000Z');
        expect(oraDiRoma('2026-12-15T18:05:00Z')).toBe('19:05');
        expect(oraDiRoma('2026-07-01T17:05:00Z')).toBe('19:05');
        expect(oraDiRoma(null)).toBe('');
        expect(giornoDellaSeduta('2026-12-17')).toBe('giovedì 17/12/2026');
    });
});

describe('la barra sulla sala vista dal dispositivo', () => {
    test('chi entra senza rete conta, chi esce no: la barra conta sul posto', () => {
        const presenze = [presenza(1, '2026-12-15T17:35:00Z'), presenza(2, '2026-12-15T17:31:00Z')];
        expect(barraSulPosto(fotografia(), presenze, []).barra).toBe('2 su 26 · 139,60 su 1.000,00');
        const dopo = [{ ...presenze[0], uscita_il: '2026-12-15T17:41:00Z' }, presenze[1], presenza(3, '2026-12-15T17:41:00Z'), presenza(99, '2026-12-15T17:41:00Z')];
        expect(barraSulPosto(fotografia(), dopo, []).barra).toBe('2 su 26 · 103,40 su 1.000,00');
    });

    test('il delegato presente porta chi lo ha delegato; una delega rifiutata no', () => {
        const presenze = [presenza(2, '2026-12-15T17:31:00Z')];
        const deleghe = [delega(1, 3, 2, 'Bianchi Giorgio'), delega(2, 4, 2, 'Bianchi Giorgio'), delega(3, 5, 2, 'Bianchi Giorgio', 'rifiutata')];
        expect(barraSulPosto(fotografia(), presenze, deleghe).barra).toBe('3 su 26 · 161,60 su 1.000,00');
    });
});

/** Il punto 4: dodici intervenuti, maggioranza «Metà del valore». */
function votazione(): VotazioneDellaSala {
    const teste = fotografia().costituzione.teste.slice(0, 12).map((t) => ({ ...t, chi: { anagrafica_id: t.persone[0], nome: t.nome, ruolo: 'proprietario' as const } }));
    return {
        id: 40, seduta_id: 2, punto_id: 4, numero: 1, oggetto: null, maggioranza: 'meta_del_valore', votata_il: '2026-12-15T18:40:00Z', versione: 2,
        intervenuti: { teste, numero: 12, valore: teste.reduce((n, t) => n + t.millesimi, 0), totale_teste: 26, totale_valore: 100000000, convocazione: 'seconda',
            perimetro: null },
        scelte: null, conteggi: null, esito_proposto: null, esito: null, motivo: null, confermata_il: null, conflitto_determinante: false, delibera_id: null,
        nota: null, nota_non_votato: null,
        voti: [{ testa: 'p-1', anagrafica_id: 1, nome: 'Rossi Mario', ruolo: 'proprietario', millesimi: M(98.3), voto: 'favorevole', decisione: null, in_conflitto: false,
            nota_conflitto: null, versione: 1 }],
    };
}

describe('i voti sul posto', () => {
    test('i conteggi e l\'esito si rifanno dai voti; la conferma parte dalla versione del server più i gesti in coda che la cambiano', () => {
        const v = votazione();
        const voti = [...v.voti, ...v.intervenuti.teste.slice(1).map((t) => ({ testa: t.chiave, anagrafica_id: t.persone[0], nome: t.nome, ruolo: 'proprietario' as const,
            millesimi: t.millesimi, voto: t.chiave === 'p-3' ? 'contrario' as const : 'favorevole' as const, decisione: null, in_conflitto: false, nota_conflitto: null, versione: 1 }))];
        const c = conteggiSulPosto(v, voti, true);
        expect(c.favorevoli.teste).toBe(11);
        expect(c.contrari.teste).toBe(1);
        expect(c.non_votato.teste).toBe(0);
        expect(c.esito).toBe('respinta');
        const coda = [
            gesto('tutti_favorevoli', { votazione_id: 40 }),
            gesto('voto', { votazione_id: 40, testa: 'p-3', voto: 'contrario' }, 1),
            gesto('voto', { votazione_id: 41, testa: 'p-4', voto: 'contrario' }, 0),
            gesto('nota', { votazione_id: 40, su: 'voto', testo: 'La nota non cambia la versione.' }),
        ];
        expect(baseDellaVotazione(v, coda)).toBe(4);
    });

    test('un voto segnato senza rete dice la seduta, il punto e il numero: conta per la versione della votazione arrivata intanto al server', () => {
        const v = votazione();
        const coda = [gesto('voto', { seduta_id: 2, punto_id: 4, numero: 1, testa: 'p-3', voto: 'contrario' }, 1), gesto('conflitto', { seduta_id: 2, punto_id: 5, numero: 1,
            testa: 'p-3', in_conflitto: true })];
        expect(baseDellaVotazione(v, coda)).toBe(3);
    });

    test('la votazione nei gesti: il numero del server, o per una votazione aperta senza rete la seduta, il punto, il numero e il gesto che l\'ha aperta', () => {
        const sala = salaAperta();
        const punto = sala.punti.find((p) => p.votazioni.length === 0 && p.maggioranza !== 'senza_voto')!;
        const siVota = { gesto: 'apre-il-voto', ambito: 'sala:9', ordine: 0, sessione: 's', tipo: 'si_vota', dati: { seduta_id: sala.seduta_id, punto_id: punto.id }, base: null,
            orologio: '2026-12-15T18:40:00Z', segnato_il: '2026-12-15T18:40:00Z' };
        const nata = proiettaLaSala(sala, [siVota]).punti.find((p) => p.id === punto.id)!.votazioni[0];
        expect(laVotazioneDelGesto(nata)).toEqual({ seduta_id: sala.seduta_id, punto_id: punto.id, numero: 1, aperta_dal_gesto: 'apre-il-voto' });
        expect(laVotazioneDelGesto(votazione())).toEqual({ votazione_id: 40 });
    });

    test('chi non ha votato resta contato a parte; il voto discorde conta astenuto', () => {
        const v = votazione();
        const voti = [...v.voti, { testa: 'p-2', anagrafica_id: 2, nome: 'Bianchi Giorgio', ruolo: 'proprietario' as const, millesimi: M(41.3), voto: 'discorde' as const,
            decisione: null, in_conflitto: false, nota_conflitto: null, versione: 1 }];
        const c = conteggiSulPosto(v, voti, true);
        expect(c.non_votato.teste).toBe(10);
        expect(c.astenuti.teste).toBe(1);
    });
});

describe('il quinto mentre si segna la delega', () => {
    test('Bianchi Giorgio con Costa, Fontana e De Luca: 3 su 5 e 125,70; con Rossi oltre il valore', () => {
        const deleghe = [delega(1, 3, 2, 'Bianchi Giorgio'), delega(2, 4, 2, 'Bianchi Giorgio'), delega(3, 5, 2, 'Bianchi Giorgio'),
            delega(4, 6, null, 'Ricci Stefano')];
        expect(quintoDelDelegato(fotografia(), deleghe, 3, { anagrafica_id: 2 }).frase).toBe('3 condòmini su 5 · 125,70 su 200,00');
        const conRossi = quintoDelDelegato(fotografia(), deleghe, 1, { anagrafica_id: 2 });
        expect(conRossi.frase).toBe('4 condòmini su 5 · 224,00 su 200,00');
        expect(conRossi.oltre_valore).toBe(true);
        // Un delegato fuori dall'anagrafica si riconosce dal nome, scritto come capita.
        expect(quintoDelDelegato(fotografia(), deleghe, 7, { nome: '  ricci   STEFANO ' }).frase).toMatch(/^2 condòmini su 5 · /);
    });
});
