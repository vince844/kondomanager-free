// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * La sala di Via Roma 12 per le prove delle pagine della sala, come la manda il server (`LetturaDellaSala::perLaPagina()`): 26 teste, 1.000 millesimi, la
 * prima convocazione di lunedì 14/12/2026 deserta e la seconda di martedì 15/12 alle 18:30. Le persone della bozza approvata: Rossi Mario (Int. 3 e box 21),
 * Bianchi Giorgio (Int. 2) con le deleghe di Costa, Fontana e De Luca, i Russo comproprietari dell'Int. 5, Neri Carla usufruttuaria e Neri Paolo nudo
 * proprietario dell'Int. 9, Colombo Rita con il conduttore Ferri Luca all'Int. 12, Marino Luigi, Romano Ida, Esposito Luca. Non è un test: lo importano i
 * test accanto.
 */
import type {
    DelegaDellaSala, FotografiaDellaSala, PresenzaDellaSala, PuntoDellaSala, SalaDellAssemblea, TestaDellaFotografia, VotazioneDellaSala,
} from '@/types/assemblee';

const M = (millesimi: number) => Math.round(millesimi * 100000);

/** Le persone: id → nome. */
export const PERSONE: Record<number, string> = {
    1: 'Rossi Mario', 2: 'Bianchi Giorgio', 3: 'Costa Teresa', 4: 'Fontana Laura', 5: 'De Luca Gianni', 6: 'Russo Anna', 7: 'Russo Marco', 8: 'Neri Carla',
    9: 'Neri Paolo', 10: 'Colombo Rita', 11: 'Ferri Luca', 12: 'Marino Luigi', 13: 'Romano Ida', 14: 'Esposito Luca', 15: 'Greco Silvio',
};

/** Le teste della costituzione: le persone della bozza più dieci condòmini, fino a 1.000,00. */
export function teste(): TestaDellaFotografia[] {
    const t: TestaDellaFotografia[] = [
        { chiave: 'p-1', nome: 'Rossi Mario', persone: [1], unita: [103, 121], millesimi: M(98.3) },
        { chiave: 'p-2', nome: 'Bianchi Giorgio', persone: [2], unita: [102], millesimi: M(41.3) },
        { chiave: 'p-3', nome: 'Costa Teresa', persone: [3], unita: [107], millesimi: M(62.1) },
        { chiave: 'p-4', nome: 'Fontana Laura', persone: [4], unita: [108], millesimi: M(58.2) },
        { chiave: 'p-5', nome: 'De Luca Gianni', persone: [5], unita: [113], millesimi: M(5.4) },
        { chiave: 'p-6-7', nome: 'Russo Anna e Russo Marco', persone: [6, 7], unita: [105], millesimi: M(51.8) },
        { chiave: 'p-8-9', nome: 'Neri Carla e Neri Paolo', persone: [8, 9], unita: [109], millesimi: M(40.5) },
        { chiave: 'p-10', nome: 'Colombo Rita', persone: [10], unita: [112], millesimi: M(59) },
        { chiave: 'p-12', nome: 'Marino Luigi', persone: [12], unita: [110], millesimi: M(39.6) },
        { chiave: 'p-13', nome: 'Romano Ida', persone: [13], unita: [111], millesimi: M(9.2) },
        { chiave: 'p-14', nome: 'Esposito Luca', persone: [14], unita: [116], millesimi: M(55.6) },
        { chiave: 'p-15', nome: 'Greco Silvio', persone: [15], unita: [120], millesimi: M(30) },
    ];
    let resto = 100000000 - t.reduce((n, x) => n + x.millesimi, 0);
    for (let i = 30; i < 44; i++) {
        const quota = i === 43 ? resto : Math.floor(resto / (44 - i));
        resto -= quota;
        t.push({ chiave: `p-${i}`, nome: `Condomino ${i}`, persone: [i], unita: [100 + i], millesimi: quota });
    }

    return t;
}

export function fotografia(piu: Partial<FotografiaDellaSala> = {}): FotografiaDellaSala {
    const tt = teste();
    const persone: Record<string, { nome: string }> = {};
    for (const [id, nome] of Object.entries(PERSONE)) persone[id] = { nome };
    for (let i = 30; i < 44; i++) persone[String(i)] = { nome: `Condomino ${i}` };
    const unita: FotografiaDellaSala['unita'] = {};
    for (const x of tt) {
        for (const u of x.unita) {
            unita[String(u)] = { nome: u === 121 ? 'box 21' : `Int. ${u - 100}`, millesimi: u === 121 ? M(10) : x.millesimi, scala_id: null, palazzina_id: null,
                titolari: x.persone.map((p) => ({ anagrafica_id: p, ruolo: u === 109 ? (p === 8 ? 'usufruttuario' : 'nuda_proprietario') : 'proprietario', quota: null })) };
        }
    }
    unita['112'].titolari.push({ anagrafica_id: 11, ruolo: 'inquilino', quota: null });

    return {
        assemblea_id: 9, seduta_id: 2, giorno: '2026-12-15', convocazione: 'seconda', provvisoria: false, teste_caso_misto: 'una_testa', tabella: { id: 1, nome: 'Proprietà' },
        persone, unita, costituzione: { totale_teste: 26, totale_valore: 100000000, teste: tt },
        punti: [
            { punto_id: 41, numero: 1, maggioranza: 'ordinaria', amministrazione: 'ordinaria', conduttore: null, perimetro: null, tabella: null, millesimi: {}, totale_teste: 26,
                totale_valore: 100000000, teste: tt, ruoli: {}, usufrutto: [] },
            { punto_id: 44, numero: 4, maggioranza: 'meta_del_valore', amministrazione: 'straordinaria', conduttore: null, perimetro: null, tabella: null, millesimi: {},
                totale_teste: 26, totale_valore: 100000000, teste: tt.map((x) => (x.chiave === 'p-8-9' ? { ...x, chiave: 'p-9', nome: 'Neri Paolo', persone: [9] } : x)),
                ruoli: { 109: 'nudo_proprietario' }, usufrutto: [109] },
            { punto_id: 47, numero: 7, maggioranza: 'meta_del_valore', amministrazione: 'straordinaria', conduttore: null, perimetro: 'Solo la scala B', tabella: { id: 2,
                nome: 'Ascensore B' }, millesimi: {}, totale_teste: 10, totale_valore: 100000000, teste: [], ruoli: {}, usufrutto: [] },
            { punto_id: 48, numero: 8, maggioranza: 'senza_voto', amministrazione: 'ordinaria', conduttore: null, perimetro: null, tabella: null, millesimi: {}, totale_teste: 26,
                totale_valore: 100000000, teste: tt, ruoli: {}, usufrutto: [] },
        ],
        avvisi: [],
        ...piu,
    };
}

export const presenza = (id: number, entrata: string, piu: Partial<PresenzaDellaSala> = {}): PresenzaDellaSala => ({
    id, chiave: `anagrafica:${id}`, anagrafica_id: id, nome: PERSONE[id] ?? `Condomino ${id}`, come: 'in_sala', entrata_il: entrata, uscita_il: null, caduto: false,
    rappresenta: null, motivo_collegamento: null, ...piu,
});

export const delega = (id: number, delegante: number, delegato: number | null, nomeDelegato: string, piu: Partial<DelegaDellaSala> = {}): DelegaDellaSala => ({
    id, delegante_anagrafica_id: delegante, delegante_nome: PERSONE[delegante] ?? `Condomino ${delegante}`, delegato_anagrafica_id: delegato, delegato_nome: nomeDelegato,
    chiave_delegato: delegato !== null ? `anagrafica:${delegato}` : `delega:${id}`, unita: [], punti: null, arrivata_come: 'carta_prima', arrivata_parola: 'Su carta, prima',
    ricevuta_il: '2026-12-01', stato: 'valida', motivo: null, quinto: '3 condòmini su 5 · 125,70 su 200,00', da_decidere: false, documento_id: null, ...piu,
});

export function punto(id: number, numero: number, testo: string, piu: Partial<PuntoDellaSala> = {}): PuntoDellaSala {
    return {
        id, numero, testo, materia: 'altro', maggioranza: 'ordinaria', maggioranza_parola: 'Ordinaria', maggioranza_libera: null, amministrazione: 'ordinaria',
        conduttore: null, ambito: 'condominio', perimetro: null, votazioni: [], esito: null, ...piu,
    };
}

/** La votazione del punto 4 in corso: dodici intervenuti alle 19:38, nove favorevoli, Marino in conflitto. */
export function votazioneDelPortone(piu: Partial<VotazioneDellaSala> = {}): VotazioneDellaSala {
    const intervenute = teste().slice(0, 12).map((t) => ({ ...(t.chiave === 'p-8-9' ? { ...t, chiave: 'p-9', nome: 'Neri Paolo', persone: [9] } : t),
        chi: { anagrafica_id: t.persone[0], nome: t.chiave === 'p-8-9' ? 'Neri Paolo' : t.nome.split(' e ')[0], ruolo: t.chiave === 'p-8-9' ? 'nudo_proprietario' as const
            : 'proprietario' as const } }));

    return {
        id: 404, seduta_id: 2, punto_id: 44, numero: 1, oggetto: null, maggioranza: 'meta_del_valore', votata_il: '2026-12-15T18:38:00Z', versione: 3,
        intervenuti: { teste: intervenute, numero: 12, valore: intervenute.reduce((n, t) => n + t.millesimi, 0), totale_teste: 26, totale_valore: 100000000,
            convocazione: 'seconda', perimetro: null },
        scelte: null,
        conteggi: { favorevoli: { teste: 1, valore: M(98.3), nomi: [{ nome: 'Rossi Mario', millesimi: M(98.3) }] }, contrari: { teste: 0, valore: 0, nomi: [] },
            astenuti: { teste: 0, valore: 0, nomi: [] }, non_votato: { teste: 11, valore: 0, nomi: [] }, conflitto: null,
            frase: 'Respinta: 1 favorevoli su 12 intervenuti, 98,30 millesimi; servono la maggioranza degli intervenuti e 500,00 millesimi (art. 1136 c. 4 c.c.).' },
        esito_proposto: 'respinta', esito: null, motivo: null, confermata_il: null, conflitto_determinante: false, delibera_id: null, nota: null, nota_non_votato: null,
        voti: [{ testa: 'p-1', anagrafica_id: 1, nome: 'Rossi Mario', ruolo: 'proprietario', millesimi: M(98.3), voto: 'favorevole', decisione: null, in_conflitto: false,
            nota_conflitto: null, versione: 1 }],
        ...piu,
    };
}

/** La sala: la seconda aperta alle 18:42, undici presenti (tre per delega a Bianchi Giorgio). */
export function salaAperta(piu: Partial<SalaDellAssemblea> = {}): SalaDellAssemblea {
    const tt = teste();
    const presenze = [
        presenza(2, '2026-12-15T17:31:00Z'), presenza(1, '2026-12-15T17:35:00Z'), presenza(6, '2026-12-15T17:38:00Z', { rappresenta: [105] }),
        presenza(10, '2026-12-15T17:40:00Z'), presenza(12, '2026-12-15T17:41:00Z'), presenza(13, '2026-12-15T17:42:00Z'), presenza(14, '2026-12-15T18:20:00Z'),
        presenza(30, '2026-12-15T17:43:00Z'),
    ];
    const deleghe = [delega(1, 3, 2, 'Bianchi Giorgio'), delega(2, 4, 2, 'Bianchi Giorgio'), delega(3, 5, 2, 'Bianchi Giorgio')];
    const presenti = [1, 2, 3, 4, 5, 6, 10, 12, 13, 14, 30];

    return {
        assemblea: { id: 9, nome: 'Assemblea ordinaria del 15/12/2026', titolo: 'Assemblea ordinaria di Via Roma 12', stato: 'in_corso', stato_parola: 'In corso',
            modalita: 'presenza', piattaforma: null, consenso_videoconferenza_il: null, luogo: 'Sala condominiale, Via Roma 12' },
        ora_del_server: '2026-12-15T18:45:00Z',
        sedute: [
            { id: 1, convocazione: 'prima', numero: 1, giorno: '2026-12-14', ora: '08:00', aperta_il: null, chiusa_il: null, esito: 'deserta', presidente: null, segretario: null,
                dal_foglio: false },
            { id: 2, convocazione: 'seconda', numero: 1, giorno: '2026-12-15', ora: '18:30', aperta_il: '2026-12-15T17:42:00Z', chiusa_il: null, esito: null,
                presidente: { anagrafica_id: 13, nome: 'Romano Ida' }, segretario: { anagrafica_id: null, nome: 'L\'amministratore' }, dal_foglio: false },
        ],
        seduta_id: 2,
        da_aprire: null,
        fotografia: fotografia(),
        impronta: 'abc',
        teste: tt.map((t) => ({ ...t, presente: t.persone.some((p) => presenti.includes(p)) ? ([3, 4, 5].includes(t.persone[0]) ? 'Bianchi Giorgio, per delega'
            : t.nome.split(' e ')[0]) : null, come: t.persone.some((p) => presenti.includes(p)) ? ([3, 4, 5].includes(t.persone[0]) ? 'per_delega' : 'di_persona') : null })),
        barra: { costituita: true, servono_teste: 9, servono_valore: 33333334, mancano_teste: 0, mancano_valore: 0, barra: '11 su 26 · 512,57 su 1.000,00',
            servono: 'servono 9 e 333,34', mancano: null, presenti },
        presenze,
        versioni: Object.fromEntries(presenze.map((p) => [p.chiave, 1])),
        deleghe,
        punti: [
            punto(41, 1, 'Approvazione del rendiconto 2025 e dello stato di riparto', { materia: 'rendiconto', esito: 'approvata',
                votazioni: [votazioneDelPortone({ id: 401, punto_id: 41, maggioranza: 'ordinaria', esito: 'approvata', esito_proposto: 'approvata',
                    confermata_il: '2026-12-15T18:05:00Z', delibera_id: 77, votata_il: '2026-12-15T18:05:00Z' })] }),
            punto(44, 4, 'Sostituzione del portone d\'ingresso: preventivi, impresa, spesa di € 14.500,00', { materia: 'lavori', maggioranza: 'meta_del_valore',
                maggioranza_parola: 'Metà del valore', amministrazione: 'straordinaria' }),
            punto(47, 7, 'Sostituzione delle funi dell\'ascensore della scala B, € 8.200,00', { materia: 'lavori', maggioranza: 'meta_del_valore',
                maggioranza_parola: 'Metà del valore', amministrazione: 'straordinaria', ambito: 'scala',
                perimetro: { nome: 'Solo la scala B', costituzione: { costituita: false, servono_teste: 4, servono_valore: 33333334, mancano_teste: 1, mancano_valore: 4333334,
                    barra: '3 su 10 · 290,00 su 1.000,00', servono: 'servono 4 e 333,34', mancano: 'manca 1 condòmino e 43,34 millesimi' },
                avviso: 'Solo la scala B: 3 su 10 · 290,00 su 1.000,00; servono 4 e 333,34. Il conto della parte non basta: decide il presidente, e il verbale lo scrive.' } }),
            punto(48, 8, 'Varie ed eventuali', { materia: 'varie', maggioranza: 'senza_voto', maggioranza_parola: 'Senza voto' }),
        ],
        dichiarazioni: [],
        controllo: null,
        conflitti: [],
        impostazioni: { delega_oltre_il_quinto: 'rifiuta', voto_discorde_comproprietari: 'astenuti', teste_caso_misto: 'una_testa' },
        puo_gestire: true,
        rotte: { gesti: '/sala/gesti', stato: '/sala/stato', pagina: '/sala', assemblea: '/assemblea', deleghe: '/deleghe', foglio: '/sala/foglio', prospetto: '/sala/prospetto',
            senza_rete: '/sala/senza-rete' },
        ...piu,
    };
}

/** La sala prima dell'apertura della seconda: la prima deserta, la seconda si apre oggi; il controllo trova Bianchi Anna non convocata. */
export function salaDaAprire(piu: Partial<SalaDellAssemblea> = {}): SalaDellAssemblea {
    const aperta = salaAperta();

    return {
        ...aperta,
        assemblea: { ...aperta.assemblea, stato: 'convocata', stato_parola: 'Convocata' },
        sedute: aperta.sedute.map((s) => (s.id === 2 ? { ...s, aperta_il: null, presidente: null, segretario: null } : s)),
        seduta_id: null,
        da_aprire: { seduta_id: 2, convocazione: 'seconda', numero: 1, si_apre: true, perche: null },
        fotografia: fotografia({ provvisoria: true }),
        teste: aperta.teste.map((t) => ({ ...t, presente: null, come: null })),
        barra: { costituita: false, servono_teste: 9, servono_valore: 33333334, mancano_teste: 9, mancano_valore: 33333334, barra: '0 su 26 · 0,00 su 1.000,00',
            servono: 'servono 9 e 333,34', mancano: 'mancano 9 condòmini e 333,34 millesimi', presenti: [] },
        presenze: [],
        versioni: {},
        punti: aperta.punti.map((p) => ({ ...p, votazioni: [], esito: null, perimetro: null })),
        controllo: { problemi: [{ tipo: 'non_convocato', anagrafica_id: 16, nome: 'Bianchi Anna', frase: 'Bianchi Anna (Int. 14) non risulta fra i convocati con un avviso che vale.' }],
            da_guardare: [], serve_la_scelta: true, frase: 'Prima di aprire: una persona non risulta convocata. Decide il presidente.' },
        ...piu,
    };
}

/**
 * La sala chiusa: la seconda tenuta e chiusa alle 18:42 (nello stesso minuto dell'apertura, dal foglio), tutti usciti con la chiusura; la barra dice la costituzione
 * vera (11 su 26, 512,57 millesimi, costituita), le presenze dell'ultima seduta stanno in `ultima_seduta`, il punto 1 è approvato e l'8 è «Trattato».
 */
export function salaChiusa(piu: Partial<SalaDellAssemblea> = {}): SalaDellAssemblea {
    const aperta = salaAperta();
    const uscita = '2026-12-15T17:42:50Z';
    const presenze = aperta.presenze.map((p) => ({ ...p, uscita_il: uscita }));

    return {
        ...aperta,
        assemblea: { ...aperta.assemblea, stato: 'chiusa', stato_parola: 'Chiusa' },
        sedute: aperta.sedute.map((s) => (s.id === 2 ? { ...s, chiusa_il: uscita, esito: 'tenuta' } : s)),
        seduta_id: null,
        presenze: [],
        versioni: {},
        ultima_seduta: { seduta_id: 2, presenze },
        chiusura: { alle: '18:42', convocazione: 'seconda', costituita: true, teste: 11, totale_teste: 26, valore: 51257000, totale_valore: 100000000 },
        punti: aperta.punti.map((p) => (p.id === 48 ? { ...p, esito: 'senza_voto' as const } : p)),
        rotte: { ...aperta.rotte, verbale: '/verbale?da=sala', registro: '/registro?da_assemblea=9' },
        ...piu,
    };
}
