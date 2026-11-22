// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

import type { SalaDellAssemblea, TestaDellaFotografia, VotazioneDellaSala } from '@/types/assemblee';
import type { VoceInCoda } from './deposito';

/**
 * La sala di Via Roma 12 per le prove (non entra nel programma: la importano solo i test): 26 condòmini e 1.000 millesimi, la seconda convocazione aperta
 * martedì 15/12/2026, il punto 1 (ordinario) e il punto 4 (il portone, metà del valore) con la votazione del punto 4 aperta alle 19:30 e nessun voto. Le
 * persone hanno il numero dell'anagrafica: Rossi 1, Bianchi Giorgio 2, Romano Ida 3, De Luca Gianni 4, Lombardi Sara 5, i venti «Condomino» dal 30 al 49.
 */
export const PERSONE: Record<string, number> = { rossi: 1, bianchi: 2, romano: 3, deluca: 4, lombardi: 5 };

const nomi: Record<number, string> = { 1: 'Rossi Mario', 2: 'Bianchi Giorgio', 3: 'Romano Ida', 4: 'De Luca Gianni', 5: 'Lombardi Sara' };
const millesimi: Record<number, number> = { 1: 6500000, 2: 4130000, 3: 920000, 4: 540000, 5: 1920000 };

export function salaDiProva(): SalaDellAssemblea {
    for (let i = 30; i < 50; i++) {
        nomi[i] = 'Condomino ' + i;
        millesimi[i] = 2775000;
    }
    // Il resto dei 1.000 millesimi va a un'unità di Rossi, perché il totale faccia 100.000.000.
    const resto = 100000000 - Object.values(millesimi).reduce((a, b) => a + b, 0);
    millesimi[1] += resto;
    const persone = Object.keys(nomi).map(Number);
    const teste: TestaDellaFotografia[] = persone.map((p) => ({ chiave: `p-${p}`, nome: nomi[p], persone: [p], unita: [100 + p], millesimi: millesimi[p] }));
    const unita = Object.fromEntries(persone.map((p) => [String(100 + p), { nome: `Int. ${p}`, millesimi: millesimi[p], titolari: [{ anagrafica_id: p, ruolo: 'proprietario' as const, quota: null }],
        scala_id: null, palazzina_id: null }]));
    const voto4: VotazioneDellaSala = { id: 12, seduta_id: 8, punto_id: 40, numero: 1, oggetto: null, maggioranza: 'meta_del_valore', votata_il: '2026-12-15T18:30:00Z', versione: 1,
        intervenuti: { teste: [3, 4, 5].map((p) => ({ ...teste.find((t) => t.persone[0] === p)!, chi: { anagrafica_id: p, nome: nomi[p], ruolo: 'proprietario' as const } })),
            numero: 3, valore: 920000 + 540000 + 1920000, totale_teste: 26, totale_valore: 100000000, convocazione: 'seconda', perimetro: null },
        scelte: null, conteggi: null, esito_proposto: null, esito: null, motivo: null, confermata_il: null, conflitto_determinante: false, delibera_id: null, voti: [] };

    return {
        assemblea: { id: 7, nome: 'Ordinaria del 15/12/2026', titolo: 'Assemblea ordinaria di Via Roma 12', stato: 'in_corso', stato_parola: 'In corso', modalita: 'presenza',
            piattaforma: null, consenso_videoconferenza_il: null, luogo: 'sala condominiale' },
        ora_del_server: '2026-12-15T19:09:00Z',
        sedute: [{ id: 8, convocazione: 'seconda', numero: 1, giorno: '2026-12-15', ora: '18:30', aperta_il: '2026-12-15T17:42:00Z', chiusa_il: null, esito: null,
            presidente: { anagrafica_id: 1, nome: 'Rossi Mario' }, segretario: { anagrafica_id: null, nome: 'L\'amministratore' }, dal_foglio: false }],
        seduta_id: 8,
        da_aprire: null,
        fotografia: { assemblea_id: 7, seduta_id: 8, giorno: '2026-12-15', convocazione: 'seconda', provvisoria: false, teste_caso_misto: 'una_testa', tabella: { id: 1, nome: 'Proprietà' },
            persone: Object.fromEntries(persone.map((p) => [String(p), { nome: nomi[p] }])), unita,
            costituzione: { totale_teste: 26, totale_valore: 100000000, teste },
            punti: [1, 4].map((n) => ({ punto_id: n * 10, numero: n, maggioranza: n === 4 ? 'meta_del_valore' : 'ordinaria', amministrazione: n === 4 ? 'straordinaria' : 'ordinaria',
                conduttore: null, perimetro: null, tabella: null, millesimi: {}, totale_teste: 26, totale_valore: 100000000, teste, ruoli: {}, usufrutto: [] })) as any,
            avvisi: [] },
        impronta: 'abc',
        teste: teste.map((t) => ({ ...t, presente: null, come: null })),
        barra: null,
        presenze: [3, 4, 5].map((p, i) => ({ id: i + 1, chiave: `anagrafica:${p}`, anagrafica_id: p, nome: nomi[p], come: 'in_sala' as const, entrata_il: '2026-12-15T17:45:00Z',
            uscita_il: null, caduto: false, rappresenta: null, motivo_collegamento: null })),
        versioni: { 'anagrafica:3': 1, 'anagrafica:4': 1, 'anagrafica:5': 1 },
        deleghe: [],
        punti: [
            { id: 10, numero: 1, testo: 'Approvazione del rendiconto 2025', materia: 'rendiconto', maggioranza: 'ordinaria', maggioranza_parola: 'Ordinaria', maggioranza_libera: null,
                amministrazione: 'ordinaria', conduttore: null, ambito: null, perimetro: null, votazioni: [], esito: null },
            { id: 40, numero: 4, testo: 'Sostituzione del portone d\'ingresso', materia: 'lavori', maggioranza: 'meta_del_valore', maggioranza_parola: 'Metà del valore',
                maggioranza_libera: null, amministrazione: 'straordinaria', conduttore: null, ambito: null, perimetro: null, votazioni: [voto4], esito: null },
        ] as any,
        dichiarazioni: [],
        controllo: null,
        conflitti: [],
        impostazioni: { delega_oltre_il_quinto: 'rifiuta', voto_discorde_comproprietari: 'astenuti', teste_caso_misto: 'una_testa' },
        puo_gestire: true,
        rotte: { gesti: '/admin/gestionale/5/assemblee/7/sala/gesti', stato: '/admin/gestionale/5/assemblee/7/sala/stato', pagina: '/admin/gestionale/5/assemblee/7/sala',
            assemblea: '/admin/gestionale/5/assemblee/7', deleghe: '/admin/gestionale/5/assemblee/7/deleghe' },
    } as SalaDellAssemblea;
}

let contatore = 0;

/** Un gesto in coda, alle ore date (Roma è UTC+1 a dicembre). */
export function gestoDiProva(tipo: string, dati: Record<string, unknown>, alle = '2026-12-15T18:40:00Z', base: number | null = null, sessione = 'prima'): VoceInCoda {
    contatore += 1;

    return { gesto: `00000000-0000-4000-8000-${String(contatore).padStart(12, '0')}`, ambito: 'sala:7', ordine: contatore, sessione, tipo, dati, base, orologio: alle,
        segnato_il: alle };
}
