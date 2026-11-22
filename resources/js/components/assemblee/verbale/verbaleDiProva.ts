// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * Il verbale di Via Roma 12 per le prove delle pagine (non è una prova): l'assemblea del 15/12/2026, la seconda convocazione aperta alle 18:42 e chiusa alle
 * 20:55; il punto 4 (il portone) con la riga della sala e la discussione; le prop della pagina «Il verbale» in corso, da generare, in bozza e registrata, e
 * quella del registro dei verbali.
 */
import { datiDellaSala, documento, paragrafo } from '@/lib/assemblee/verbale/struttura';
import type { DestinatarioDelVerbale, PaginaDelRegistroDeiVerbali, PaginaDelVerbale, VerbaleDellaPagina } from '@/types/verbale';

const BASE = 'http://km.test/admin/gestionale/3/assemblee/9';
export const ROTTE = {
    pagina: `${BASE}/il-verbale`, stato: `${BASE}/il-verbale/stato`, gesti: `${BASE}/il-verbale/gesti`, genera: `${BASE}/il-verbale/genera`, sala: `${BASE}/sala`,
    assemblea: BASE, registro: 'http://km.test/admin/gestionale/3/assemblee-registro?registro=condominio', aggiorna_sala: `${BASE}/il-verbale/7/aggiorna-sala`,
    bozza: `${BASE}/il-verbale/7/bozza`, registra: `${BASE}/il-verbale/7/registra`, copia: `${BASE}/il-verbale/7/copia`, annulla: `${BASE}/il-verbale/7/annulla`,
    libro: `${BASE}/il-verbale/7/libro`, libro_pagina: `${BASE}/il-verbale/7/libro`, scrivo: `${BASE}/il-verbale/7/scrivo`, chiedi: `${BASE}/il-verbale/7/chiedi`,
    rispondi: `${BASE}/il-verbale/7/rispondi`, togli: `${BASE}/il-verbale/7/destinatari/togli`, aggiungi: `${BASE}/il-verbale/7/destinatari/aggiungi`,
    pec: `${BASE}/il-verbale/7/pec`, lettere: `${BASE}/il-verbale/7/lettere`, spedizione: `${BASE}/il-verbale/7/spedizione`, prova: `${BASE}/il-verbale/7/prova`,
};

export const PUNTI: VerbaleDellaPagina['punti'] = [
    { punto_id: 43, numero: 3, testo: 'Ratifica della fattura n. 45 di Idraulica Bianchi', parte: 'punto:43', votazioni: [{ id: 403, esito: 'approvata',
        riga: 'Approvata · 11 favorevoli per 494,25 millesimi, 0 contrari, 0 astenuti' }], dichiarazioni: [] },
    { punto_id: 44, numero: 4, testo: 'Sostituzione del portone d\'ingresso', parte: 'punto:44', votazioni: [{ id: 404, esito: 'approvata',
        riga: 'Approvata · 9 favorevoli per 506,40 millesimi, 2 contrari, 1 astenuto, 1 non ha votato' }],
        dichiarazioni: [{ id: 81, nome: 'Romano Ida', testo: 'Contraria perché i preventivi sono arrivati il giorno prima dell\'assemblea.', punto_id: 44 }] },
    { punto_id: 46, numero: 6, testo: 'Conferma dell\'amministratore', parte: 'punto:46', votazioni: [], dichiarazioni: [] },
];

export const BLOCCO_DEL_VOTO = datiDellaSala('voto:404', ['Favorevoli: 9 condòmini per 496,40 millesimi (…).', 'Contrari: Ida Romano (9,20), Gianni De Luca per delega a Giorgio Bianchi (5,40).']);
export const INTERO = documento([paragrafo('Il giorno 15 dicembre 2026, alle ore 18:42, si è riunita in seconda convocazione l\'assemblea ordinaria.'),
    datiDellaSala('costituzione:2', ['Sono presenti, di persona o per delega, 11 condòmini su 26, per 484,60 millesimi su 1.000, come dall\'elenco in calce.']),
    paragrafo('4. Sostituzione del portone d\'ingresso'), BLOCCO_DEL_VOTO]);

const parte = (d: ReturnType<typeof documento>, revisione = 3) => ({ documento: d, revisione, impronta: 'x', caratteri: 40, dispositivo: null, scritta_il: null });

export function verbale(piu: Partial<VerbaleDellaPagina> = {}): VerbaleDellaPagina {
    return {
        id: 7, seduta_id: 2, stato: 'bozza', stato_parola: 'Bozza',
        registro: { chiave: 'condominio', nome: 'Via Roma 12', scala_id: null, palazzina_id: null, partenza: null, numero_proposto: null },
        numero: null, modello: { id: 5, nome: 'Verbale ordinario dello studio' }, generato_il: '2026-12-15T20:02:00Z', sala_cambiata: null,
        cifre: [{ chiave: 'voto:404', punto: 4, votazione_id: 404, occorrenza: 1, nel_testo: '496,40', adesso: '506,40',
            frase: 'Nel punto 4 il verbale dice «496,40», i voti registrati dicono 506,40.', rimetti: '506,40', vai_al_voto: null }],
        stampata: null, sottoscritto_il: null, registrato_il: null, registrato_da: null, copia: null, annullato: null, sostituisce: null, nel_libro_il: null,
        parti: { intero: parte(INTERO), 'punto:44': parte(documento([paragrafo('L\'amministratore illustra i tre preventivi ricevuti.')]), 2) },
        punti: PUNTI,
        versioni: [{ id: 31, parte: 'intero', numero: 1, perche: 'generato dal modello «Verbale ordinario dello studio»', impronta: 'a', dispositivo: null,
            segnata_il: '2026-12-15T20:02:00Z', arrivata_il: '2026-12-15T20:02:00Z', chi: 'Andrea Costa', in_attesa: false, attesa: null, scelta: null, scelta_il: null }],
        in_attesa: [], chi_scrive: null, trasmissione: null, termini: null,
        ...piu,
    };
}

export function persona(piu: Partial<DestinatarioDelVerbale>): DestinatarioDelVerbale {
    return { anagrafica_id: 20, nome: 'Galli Franco', unita: 'Int. 20', gruppo: 'mai_entrati', perche: 'assente a tutti i punti', punti_assente: [3, 4, 6], voti: [],
        proposto: true, riceve: true, tolto: null, aggiunto: null, convocato: true,
        mezzo: { mezzo: 'pec', parola: 'PEC', dalla_convocazione: true, indirizzo: 'galli@pec.it', perche: null }, invio: null,
        termini: [{ punti: [3, 4, 6], perche: 'assente', dal: null, fino_al: null, da_consegnare: true }], ...piu };
}

export function registrato(piu: Partial<VerbaleDellaPagina> = {}): VerbaleDellaPagina {
    return verbale({
        stato: 'registrato', stato_parola: 'Registrato', numero: 42, cifre: [], sottoscritto_il: '2026-12-15', registrato_il: '2026-12-15T20:33:00Z',
        registrato_da: 'Andrea Costa', copia: { id: 88, nome: 'verbale_15_12_2026_firmato.pdf', peso: 2516582, url: `${BASE}/il-verbale/7/copia`, sostituita_il: null },
        trasmissione: { videoconferenza: false, punti: [{ punto_id: 44, numero: 4, testo: 'Il portone', votazione_id: 404, votata_il: '2026-12-15T18:44:00Z', delibera_id: 77,
            esito: 'approvata', deliberata_il: '2026-12-15' }],
        persone: [
            persona({ invio: { id: 501, canale: 'pec', mezzo: 'pec', stato: 'inviata', esito: 'consegnata', esito_parola: 'Consegnata', esito_il: '2026-12-15T20:40:00Z',
                ricevuta_il: '2026-12-15', spedito: true, errore: null, creato_il: '2026-12-15T20:38:00Z' },
                termini: [{ punti: [3, 4, 6], perche: 'assente', dal: '2026-12-15', fino_al: '2027-01-14', da_consegnare: false }] }),
            persona({ anagrafica_id: 3, nome: 'Rossi Mario', unita: 'Int. 3, box 21', gruppo: 'fuori', perche: 'uscito alle 19:30: assente ai punti 4 e 6', punti_assente: [4, 6],
                voti: { 3: 'favorevole' }, mezzo: { mezzo: 'raccomandata', parola: 'Raccomandata', dalla_convocazione: true, indirizzo: 'Via Roma 12', perche: null },
                termini: [{ punti: [4, 6], perche: 'assente', dal: null, fino_al: null, da_consegnare: true }] }),
            persona({ anagrafica_id: 13, nome: 'Romano Ida', gruppo: 'cera', riceve: false, proposto: false, perche: 'c\'era a tutti i punti', punti_assente: [],
                voti: { 4: 'contrario' }, termini: [{ punti: [4], perche: 'contrario', dal: '2026-12-15', fino_al: '2027-01-14', da_consegnare: false }] }),
        ], quanti: 2, spediti: 1, consegnati: 1, per_mezzo: { raccomandata: 1 } },
        termini: { delibere: [{ delibera_id: 77, punto: 4, esito: 'approvata', deliberata_il: '2026-12-15', chi: [{ anagrafica_id: 13, nome: 'Romano Ida', perche: 'contrario',
            dal: '2026-12-15', fino_al: '2027-01-14' }] }], per_tutti: null },
        ...piu,
    });
}

export function pagina(v: VerbaleDellaPagina | null, piu: Partial<PaginaDelVerbale> = {}): PaginaDelVerbale {
    return {
        assemblea: { id: 9, nome: 'Assemblea ordinaria del 15/12/2026', titolo: 'Assemblea ordinaria di Via Roma 12', stato: 'chiusa', stato_parola: 'Chiusa',
            modalita: 'presenza', tipo: 'ordinaria' },
        ora_del_server: '2026-12-15T20:14:00Z',
        riunioni: [{ seduta_id: 2, giorno: '2026-12-15', convocazione: 'seconda', numero: 1, aperta_il: '2026-12-15T17:42:00Z', chiusa_il: '2026-12-15T19:55:00Z',
            esito: 'tenuta', verbale: v ? { id: v.id, stato: v.stato, numero: v.numero } : null }],
        seduta_id: 2, punti: PUNTI, verbale: v,
        modelli: { modelli: [{ id: 5, nome: 'Verbale ordinario dello studio', gruppo: 'tutti', ultimo_usato: true, cambiato_da: 'Andrea Costa', cambiato_il: '2026-10-20' },
            { id: null, nome: 'Testo del programma', gruppo: 'programma', ultimo_usato: false, cambiato_da: null, cambiato_il: null }], proposto: null, ultimo_usato: 5 },
        puo: { gestire: true, registrare: true },
        rotte: ROTTE,
        ...piu,
    };
}

export function registroDiProva(piu: Partial<PaginaDelRegistroDeiVerbali> = {}): PaginaDelRegistroDeiVerbali {
    const perimetro = { chiave: 'condominio', nome: 'Via Roma 12', scala_id: null, palazzina_id: null };
    return {
        perimetro, registri: [{ ...perimetro, partenza: null, prossimo_numero: 1 }, { chiave: 'scala:5', nome: 'Scala B', scala_id: 5, palazzina_id: null, partenza: null,
            prossimo_numero: 1 }],
        partenza: null, prossimo_numero: 1, anni: [2026], anno: null,
        righe: [
            { tipo: 'verbale', id: 7, numero: 42, giorno: '2026-12-15', assemblea: { id: 9, nome: 'Assemblea ordinaria', parola: 'Ordinaria, seconda convocazione', perimetro: null },
                stato: 'registrato', parola: 'Sottoscritto', contenuto: '5 delibere · 1 dichiarazione', nel_libro_il: null },
            { tipo: 'annotazione', id: 1, numero: null, giorno: '2026-12-14', assemblea: { id: 9, nome: 'Assemblea ordinaria', parola: 'Ordinaria, prima convocazione', perimetro: null },
                stato: 'annotazione', parola: 'Mancata costituzione', contenuto: 'mancata costituzione', nel_libro_il: '2026-12-16' },
        ],
        nomine: [{ id: 2, evento: 'nomina', evento_parola: 'Nomina', amministratore: 'Andrea Costa', dal: '2026-12-15', da_dove: 'verbale', estremi: 'verbale n. 42, punto 6',
            decreto: null, delibera_id: 77, verbale: { id: 7, numero: 42 }, documento: null, nota: null, stato: 'proposta', stato_parola: 'Proposta', annullata_motivo: null }],
        senza_la_nomina_in_corso: true, amministratore_di_oggi: 'Andrea Costa', puo: { registrare: true },
        rotte: { partenza: 'http://km.test/admin/gestionale/3/assemblee-registro/partenza', stampa: 'http://km.test/admin/gestionale/3/assemblee-registro/stampa?registro=condominio',
            libro: 'http://km.test/admin/gestionale/3/assemblee-registro/libro', nomine: 'http://km.test/admin/gestionale/3/assemblee-registro/nomine' },
        ...piu,
    };
}
