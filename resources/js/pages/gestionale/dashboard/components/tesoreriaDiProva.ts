// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
/**
 * La tesoreria d'esempio per le prove dei componenti della dashboard: la forma che manda il server (`TreasuryStatus::toArray()`), con cifre fatte a mano.
 * Via Verdi 12 il 15/11/2026: disponibile € 4.480,38; uscite € 2.300,00; entrate attese € 1.240,00 (€ 1.000,00 di rate già scadute, € 240,00 che scadono il
 * 30/11); sotto zero dal 18/11.
 *
 * `saldoDellEsempio()` e `recuperoDellEsempio()`: il condominio dell'esempio della guida, con le azioni consigliate; `datiDelleAzioniDellEsempio()` i fatti
 * delle altre sei.
 */
type Qualsiasi = Record<string, unknown>;

export const persona = (n: number, altro: Qualsiasi = {}): Qualsiasi => ({
    anagraficaId: n, condomino: `Persona ${n}`, immobile: `Int. ${n}`, importoCents: 10000 * (10 - n), giorniRitardo: 30 + n, ritardoTesto: `in ritardo di ${30 + n} giorni`,
    anzianitaNonNota: false, creditoCents: 0, route: 'admin.gestionale.anagrafiche.estratto-conto', routeParams: { condominio: 7, anagrafica: n },
    passo: { caso: 'R15', passo: 'sollecito', frase: 'Nessuna lettera.', frase_dei_fatti: 'Nessuna lettera.', note: [], rata_compensa: null },
    ...altro,
});

export const fattura = (n: number, altro: Qualsiasi = {}): Qualsiasi => ({
    id: 100 + n, fornitore: 'Edil Rossi srl', numero: `${40 + n}/2026`, dataScadenza: `2026-11-${String(15 + n).padStart(2, '0')}`, scaduta: false, importoCents: 50000 + n * 100,
    ...altro,
});

/** Il giorno «AAAA-MM-GG» a i giorni dal 15/11/2026, contato in UTC. */
const giornoDal15 = (i: number) => {
    const d = new Date(Date.UTC(2026, 10, 15 + i));

    return `${d.getUTCFullYear()}-${String(d.getUTCMonth() + 1).padStart(2, '0')}-${String(d.getUTCDate()).padStart(2, '0')}`;
};

const giorni = () => Array.from({ length: 31 }, (_, i) => {
    const giorno = giornoDal15(i);
    const saldo = i < 3 ? 448038 : i < 12 ? -51962 : 28038;

    return { giorno, saldo, con_entrate: saldo + 100000 + (i >= 15 ? 24000 : 0), uscite: i === 3 ? 500000 : 0, quante_uscite: i === 3 ? 1 : 0,
        voci_uscite: i === 3 ? [{ voce: 'Edil Rossi srl — n. 45/2026', importo: 500000 }] : [],
        fatture: i === 3 ? [{ id: 103, fornitore: 'Edil Rossi srl', numero: '45/2026', importo: 500000 }] : [],
        entrate: i === 0 ? 100000 : i === 15 ? 24000 : 0, quante_entrate: i === 0 ? 3 : i === 15 ? 2 : 0, rate_in_scadenza: i === 15 ? 24000 : 0,
        quante_rate_in_scadenza: i === 15 ? 2 : 0, registrati: i === 12 ? 80000 : 0, quanti_registrati: i === 12 ? 1 : 0,
        voci_registrati: i === 12 ? [{ voce: 'Incasso', importo: 80000 }] : [] };
});

/** Le persone del recupero: le rate scadute più alte prima, quante bastano. */
export const recupero = (altro: Qualsiasi = {}): Qualsiasi => ({
    persone: [{ anagraficaId: 1, nome: 'Persona 1', interno: 'Int. 1', cifra: 90000, lettera: null }], servono: 1, servonoCents: 90000, quante: 3, cifra: 100000,
    ...altro,
});

const fatturaDellEsempio = (id: number, fornitore: string, importo: number) => ({ id, fornitore, numero: `${id}/2026`, importo });

/**
 * Il saldo previsto dell'esempio della guida, con le azioni consigliate: oggi € 1.462,03; il 20/11 tre fatture per € 3.830,80 (sotto zero di € 2.368,77); il 30/11 € 2.400,00 di
 * rate; € 6.592,97 di rate già scadute. Quanto basta € 2.368,77; due azioni consigliate.
 */
export const saldoDellEsempio = (): Qualsiasi => {
    const fatture = [fatturaDellEsempio(1, 'Vetreria Rapida Demo', 183000), fatturaDellEsempio(2, 'Idraulica Urgenze Demo', 170800), fatturaDellEsempio(3, 'Pulizie Aurora Demo', 29280)];
    const giorniDellEsempio = Array.from({ length: 31 }, (_, i) => {
        const saldo = i < 5 ? 146203 : -236877;
        const rate = i >= 15 ? 240000 : 0;

        return { giorno: giornoDal15(i), saldo, con_entrate: saldo + 659297 + rate, uscite: i === 5 ? 383080 : 0, quante_uscite: i === 5 ? 3 : 0,
            voci_uscite: i === 5 ? fatture.map((f) => ({ voce: `${f.fornitore} — n. ${f.numero}`, importo: f.importo })) : [], fatture: i === 5 ? fatture : [],
            entrate: i === 0 ? 659297 : i === 15 ? 240000 : 0, quante_entrate: i === 0 ? 6 : i === 15 ? 6 : 0, rate_in_scadenza: i === 15 ? 240000 : 0,
            quante_rate_in_scadenza: i === 15 ? 6 : 0, registrati: 0, quanti_registrati: 0, voci_registrati: [] };
    });

    return { giorni: giorniDellEsempio, primoSottoZero: '2026-11-20', minimoCents: -236877, finaleCents: -236877, finaleConEntrateCents: 662420, rateScaduteCents: 659297 };
};

export const recuperoDellEsempio = (): Qualsiasi => ({
    persone: [{ anagraficaId: 4, nome: 'Giulia Esposito', interno: 'Int. 4', cifra: 397410, lettera: null }], servono: 1, servonoCents: 397410, quante: 6, cifra: 659297,
});

/**
 * I fatti delle altre azioni consigliate, con il saldo dell'esempio: le ritenute del 16/11 (€ 300,00), due bonifici segnalati (€ 350,00), la rata 6 del
 * «Preventivo 2026» da emettere (€ 1.000,00, il 30/11), la banca a −€ 300,00 e i contanti a € 500,00, il fondo facciata (€ 3.000,00) e il TFR (che non si
 * propone mai), un deficit ereditato (€ 500,00). Con `rateScaduteCents: 0` nel saldo anche il piano straordinario: otto azioni.
 */
export const datiDelleAzioniDellEsempio = (altro: Qualsiasi = {}): Qualsiasi => ({
    rateDaEmettere: [{ pianoId: 9, piano: 'Preventivo 2026', esercizioId: 25, quante: 1, cifra: 100000, rate: [{ rataId: 61, numero: 6, scadenza: '2026-11-30', cifra: 100000 }] }],
    bonifici: { quanti: 2, cifra: 35000 },
    ritenute: { scadenze: [{ giorno: '2026-11-16', cifra: 30000 }] },
    deficit: { quanti: 1, cifra: 50000 },
    esercizioId: 25,
    conti: [{ cassaId: 1, nome: 'Banca Intesa', tipo: 'banca', saldo: -30000, attiva: true }, { cassaId: 2, nome: 'Cassa contanti', tipo: 'contanti', saldo: 50000, attiva: true }],
    fondi: [{ cassaId: 6, nome: 'Fondo facciata', saldo: 300000, sottotipo: 'vincolato_lavori', vincolo: 'facciata' }, { cassaId: 8, nome: 'TFR portiere', saldo: 900000, sottotipo: 'tfr', vincolo: null }],
    ...altro,
});

export const disponibile = (altro: Qualsiasi = {}): Qualsiasi => ({
    cifra: 448038, totale: 548038, solo_conti_correnti: false,
    righe: [
        { voce: 'Banca Intesa', tipo: 'banca', saldo: 412038, cassa_id: 1, conto_contabile_id: 11, attiva: true, etichetta: null },
        { voce: 'Cassa contanti', tipo: 'contanti', saldo: 5000, cassa_id: 2, conto_contabile_id: 12, attiva: true, etichetta: null },
        { voce: 'PayPal', tipo: 'virtuale', saldo: 8000, cassa_id: 3, conto_contabile_id: 13, attiva: true, etichetta: null },
        { voce: '1020 Banca Posta', tipo: 'senza_cassa', saldo: 3000, cassa_id: null, conto_contabile_id: 14, attiva: true, etichetta: null },
        { voce: 'Fondo spese generiche', tipo: 'fondo', saldo: 20000, cassa_id: 5, conto_contabile_id: 15, attiva: true, etichetta: { testo: 'generico, si può usare', tono: 'libero' }, si_puo_usare: true },
        { voce: 'Fondo di riserva', tipo: 'fondo', saldo: 100000, cassa_id: 6, conto_contabile_id: 16, attiva: true, etichetta: { testo: 'vincolato ai lavori: facciata', tono: 'vincolato' }, si_puo_usare: false },
    ],
    tolte: [{ voce: 'Fondo di riserva', saldo: 100000, cassa_id: 6, etichetta: { testo: 'vincolato ai lavori: facciata', tono: 'vincolato' } }],
    futuri: { quanti: 2, cifra: 30000, primo: '2026-11-20', nel_flusso: {}, nel_flusso_quanti: 2, nel_flusso_cifra: 30000 },
    banca: [{ cassa_id: 1, cassa: 'Banca Intesa', data: '2025-12-31', saldo: 470000, esercizio: 'Esercizio 2025' }],
    esercizio_id: 25,
    ...altro,
});

export const tesoreria = (altro: Qualsiasi = {}): Qualsiasi => ({
    condominioId: 7, gestioneId: null, liquiditaTotaleCents: 548038, liquiditaVincolataCents: 100000, liquiditaDisponibileCents: 448038,
    uscitePredittiveCents: 500000, incassiAttesiCents: 124000, incassiScadutiCents: 100000, incassiInScadenzaCents: 24000, debitiPregressiScadutiCents: 0,
    scenarioPessimisticoCents: 28038, scenarioOttimisticoCents: 152038, giornoScopertoPrevisto: '2026-11-18', scopertoMaxCents: -51962, livello: 'giallo',
    fattureInScadenza: [fattura(1), fattura(2), fattura(3)], fattureQuante: 3, fattureMancano: null,
    morositaImpattanti: [persona(1), persona(2), persona(3)], morositaAltri: 0, morositaAltriCents: 0, morositaPersone: 3, scadutoInTutto: 240000,
    scadutoPerFasce: { '1_30': 0, '31_90': 240000, oltre_90: 0 },
    elencoRateScadute: { route: 'admin.gestionale.rate-scadute.index', routeParams: { condominio: 7 } },
    azioniSuggerite: [], fattureSenzaScadenza: [], hasFondoRiserva: true,
    disponibile: disponibile(),
    saldoPrevisto: { giorni: giorni(), primoSottoZero: '2026-11-18', minimoCents: -51962, finaleCents: 28038, finaleConEntrateCents: 152038, rateScaduteCents: 100000 },
    recupero: recupero(),
    suggerisceAzione: true, oggi: '2026-11-15',
    ...altro,
});
