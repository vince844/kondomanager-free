// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
/**
 * I calcoli e le frasi dell'Action inbox, fuori dalla pagina e senza Chart.js: le frasi delle card, i giorni della riga, le schede, l'ordine, i gruppi
 * dell'elenco con «e altri N» e «Mostrati N di M», i filtri attivi, i dati e le opzioni dei due grafici, le descrizioni per chi non vede i grafici, la card
 * «Sei mesi» mentre i condomini arrivano.
 */
import { describe, expect, test } from 'vitest';
import {
    FASCE_DEL_GRAFICO, ORDINI, SCHEDE, aspettoDelTipo, datiEta, datiTipi, descrizioneEta, descrizioneTipi, descrizioneDellaPagina, filtriAttivi, opzioniEta, opzioniTipi,
    parametri, righeConIGruppi, testoCardSeiMesi, testoDellaData, testoEAltri, testoFuoriDalGrafico, testoMostrati, testoQuando, testoScaduti, testoSettimana,
    titoloDelGruppo,
    type Grafici, type RigaCompito,
} from './actionInbox';

const colori = { rosso: 'rgb(239, 68, 68)', rossoChiaro: 'rgb(248, 113, 113)', indaco: 'rgb(99, 102, 241)', indacoChiaro: 'rgb(129, 140, 248)', grigio: 'rgb(148, 163, 184)',
    testo: 'rgb(100, 116, 139)', griglia: 'rgb(226, 232, 240)', cifre: 'rgb(15, 23, 42)' };

const grafici: Grafici = {
    eta: [
        { chiave: 'oltre_90', etichetta: 'oltre 90 giorni fa', valore: 52, scaduti: true },
        { chiave: 'da_31_a_90', etichetta: 'da 31 a 90 giorni', valore: 47, scaduti: true },
        { chiave: 'ultimi_30', etichetta: 'ultimi 30 giorni', valore: 8, scaduti: true },
        { chiave: 'prossimi_7', etichetta: 'prossimi 7 giorni', valore: 0, scaduti: false },
        { chiave: 'fra_8_e_30', etichetta: 'fra 8 e 30 giorni', valore: 6, scaduti: false },
    ],
    tipi: [
        { tipo: 'controllo_incassi', etichetta: 'Verifica incassi', valore: 51 },
        { tipo: 'emissione_rata', etichetta: 'Emissione rate', valore: 33 },
        { tipo: 'versamento_ritenuta', etichetta: 'F24', valore: 10 },
    ],
    fuori: { bonifici: 0, oltre_30: 0 },
};

describe('le frasi delle card e della riga', () => {
    test('«Scaduti» dice il giorno del più vecchio; «Prossimi 7 giorni» quanti poi entro 30', () => {
        expect(testoScaduti({ scaduti: 107, piu_vecchio: '2025-12-29' })).toBe('il più vecchio del 29/12/2025');
        expect(testoScaduti({ scaduti: 0, piu_vecchio: null })).toBe('nessun compito scaduto');
        expect(testoSettimana({ entro_30: 6 })).toBe('poi 6 entro 30 giorni');
        expect(testoSettimana({ entro_30: 1 })).toBe('poi 1 entro 30 giorni');
        expect(testoSettimana({ entro_30: 0 })).toBe('nessuno fra 8 e 30 giorni');
    });

    test('i giorni della riga: «da 322 giorni», «da ieri», «oggi», «domani», «fra 12 giorni»', () => {
        expect(testoQuando(-322)).toBe('da 322 giorni');
        expect(testoQuando(-1)).toBe('da ieri');
        expect(testoQuando(0)).toBe('oggi');
        expect(testoQuando(1)).toBe('domani');
        expect(testoQuando(12)).toBe('fra 12 giorni');
    });

    test('la data di una segnalazione dice quando è stata aperta: non ha una scadenza', () => {
        expect(testoDellaData({ gruppo: 'segnalazioni', giorno: '2026-02-22' })).toBe('aperta il 22/02/2026');
        expect(testoDellaData({ gruppo: 'scaduti', giorno: '2025-12-29' })).toBe('29/12/2025');
    });

    test('la descrizione sotto il titolo segue il condominio scelto', () => {
        expect(descrizioneDellaPagina(null)).toBe('I compiti aperti di tutti i condomini.');
        expect(descrizioneDellaPagina('Via Verdi 12')).toBe('I compiti aperti di Via Verdi 12.');
    });

    test('ogni tipo ha l\'icona e il colore; un tipo che non c\'è ha quelli di serie', () => {
        expect(aspettoDelTipo('emissione_rata').icona).toBe('emissione');
        expect(aspettoDelTipo('controllo_incassi').icona).toBe('incassi');
        expect(aspettoDelTipo('qualcosa').icona).toBe('scadenza');
        // L'avviso di un errore (1.12.0-beta.14): l'icona dell'errore, in rosso.
        expect(aspettoDelTipo('errore_avvisato').icona).toBe('errore');
        expect(aspettoDelTipo('errore_avvisato').colore).toContain('rose');
        expect(aspettoDelTipo('qualcosa').colore).toContain('dark:');
    });
});

describe('le schede, l\'ordine e i gruppi dell\'elenco', () => {
    const riga = (id: number, gruppo: RigaCompito['gruppo']) => ({ id, gruppo }) as RigaCompito;

    test('le schede «Tutti / Scaduti / In arrivo» con il loro filtro per data; l\'ordine con i suoi nomi', () => {
        expect(SCHEDE.map((s) => [s.chiave, s.etichetta, s.quando])).toEqual([['tutti', 'Tutti', null], ['scaduti', 'Scaduti', 'scaduti'], ['in_arrivo', 'In arrivo', 'in_arrivo']]);
        expect(ORDINI.map((o) => o.etichetta)).toEqual(['I più vecchi prima', 'I più recenti prima', 'Per condominio']);
        expect(ORDINI[0].valore).toBe('vecchi');
    });

    test('la testata del gruppo davanti alla sua prima riga, con il numero del server', () => {
        const voci = righeConIGruppi([riga(2, 'scaduti'), riga(3, 'scaduti'), riga(5, 'segnalazioni'), riga(1, 'bonifici'), riga(4, 'avanti')],
            { scaduti: 107, segnalazioni: 7, bonifici: 1, avanti: 6 });

        expect(voci.map((v) => (v.tipo === 'gruppo' ? `${v.gruppo}:${v.numero}` : v.riga.id))).toEqual(['scaduti:107', 2, 3, 'segnalazioni:7', 5, 'bonifici:1', 1, 'avanti:6', 4]);
        expect(titoloDelGruppo('scaduti')).toBe('Scaduti');
        expect(titoloDelGruppo('segnalazioni')).toBe('Segnalazioni aperte');
        expect(titoloDelGruppo('bonifici')).toBe('Bonifici da verificare');
        expect(titoloDelGruppo('avanti')).toBe('Più avanti');
    });

    test('«e altri 100 scaduti», «e un altro scaduto»; «Mostrati 9 di 113»', () => {
        expect(testoEAltri('scaduti', 100)).toBe('e altri 100 scaduti');
        expect(testoEAltri('scaduti', 1)).toBe('e un altro scaduto');
        expect(testoEAltri('avanti', 4)).toBe('e altri 4 più avanti');
        expect(testoEAltri('bonifici', 3)).toBe('e altri 3 bonifici da verificare');
        expect(testoEAltri('bonifici', 1)).toBe('e un altro bonifico da verificare');
        expect(testoEAltri('segnalazioni', 4)).toBe('e altre 4 segnalazioni aperte');
        expect(testoEAltri('segnalazioni', 1)).toBe('e un\'altra segnalazione aperta');
        expect(testoMostrati(9, 113)).toBe('Mostrati 9 di 113');
        expect(testoMostrati(1, 1)).toBe('Mostrato 1 di 1');
    });

    test('i parametri dell\'indirizzo senza i vuoti e senza l\'ordine di serie', () => {
        expect(parametri({ quando: 'scaduti', tipo: null, cerca: null, ordine: 'vecchi', condominio_id: 4 })).toEqual({ quando: 'scaduti', condominio_id: 4 });
        expect(parametri({ quando: null, tipo: 'emissione_rata', cerca: 'rossi', ordine: 'recenti', condominio_id: null })).toEqual({ tipo: 'emissione_rata', cerca: 'rossi', ordine: 'recenti' });
    });
});

describe('i filtri attivi', () => {
    test('dicono che cosa filtra dentro la scheda, nelle parole delle barre e dei tipi; la scheda e la ricerca si vedono da sé', () => {
        expect(filtriAttivi({ quando: 'scaduti', tipo: 'emissione_rata', cerca: 'rossi' }, grafici)).toEqual([{ chiave: 'tipo', testo: 'Emissione rate' }]);
        // Il nome del tipo arriva dal server, anche quando il grafico non ha il tipo.
        expect(filtriAttivi({ quando: 'da_31_a_90', tipo: 'verifica_pagamento', tipo_nome: 'Bonifici segnalati', cerca: null }, grafici)).toEqual([
            { chiave: 'quando', testo: 'Da 31 a 90 giorni' }, { chiave: 'tipo', testo: 'Bonifici segnalati' },
        ]);
        expect(filtriAttivi({ quando: 'prossimi_7', tipo: 'segnalazione_guasto', tipo_nome: 'Segnalazioni', cerca: null }, grafici)).toEqual([
            { chiave: 'quando', testo: 'Prossimi 7 giorni' }, { chiave: 'tipo', testo: 'Segnalazioni' },
        ]);
        expect(filtriAttivi({ quando: null, tipo: null, cerca: null }, grafici)).toEqual([]);
    });
});

describe('il grafico «Da quanto aspettano»', () => {
    test('cinque barre, rosse gli scaduti e indaco quelli in arrivo; con una barra scelta le altre si attenuano', () => {
        expect(FASCE_DEL_GRAFICO).toEqual(['oltre_90', 'da_31_a_90', 'ultimi_30', 'prossimi_7', 'fra_8_e_30']);
        const d = datiEta(grafici, colori, null);
        // Le etichette su due righe, senza abbreviazioni.
        expect(d.labels).toEqual([['oltre 90', 'giorni fa'], ['da 31 a 90', 'giorni fa'], ['ultimi', '30 giorni'], ['prossimi', '7 giorni'], ['fra 8 e', '30 giorni']]);
        expect(d.datasets).toHaveLength(1);
        expect(d.datasets[0].data).toEqual([52, 47, 8, 0, 6]);
        expect(d.datasets[0].backgroundColor).toEqual([colori.rosso, colori.rosso, colori.rossoChiaro, colori.indaco, colori.indacoChiaro]);

        const scelta = datiEta(grafici, colori, 'da_31_a_90').datasets[0].backgroundColor as string[];
        expect(scelta[1]).toBe(colori.rosso);
        expect(scelta[0]).toMatch(/^rgba\(239, 68, 68, 0\.3/);
    });

    test('le opzioni: nessuna legenda, la cifra sopra ogni barra, cifre italiane, scritte di 12 px, un clic sceglie la barra', () => {
        let scelta: number | null = null;
        const o = opzioniEta(grafici, colori, (i) => { scelta = i; });
        expect(o.plugins.legend.display).toBe(false);
        expect(o.scales.y.ticks.font.size).toBeGreaterThanOrEqual(11);
        expect(o.scales.x.ticks.font.size).toBeGreaterThanOrEqual(11);
        expect(o.scales.x.ticks.maxRotation).toBe(0);
        expect(o.scales.x.ticks.autoSkip).toBe(false);
        expect(o.scales.y.ticks.callback(1200)).toBe('1.200');
        expect(o.plugins.tooltip.callbacks.label({ dataIndex: 1, raw: 47 })).toBe('47 compiti scaduti da 31 a 90 giorni');
        expect(o.plugins.tooltip.callbacks.label({ dataIndex: 3, raw: 1 })).toBe('1 compito nei prossimi 7 giorni');
        o.onClick({} as never, [{ index: 3 }] as never);
        expect(scelta).toBe(3);
        o.onClick({} as never, [] as never);
        expect(scelta).toBe(3);
    });

    test('la descrizione dice ogni barra, con le parole; fuori dal grafico i bonifici e i compiti più avanti', () => {
        expect(descrizioneEta(grafici)).toBe('Compiti per data: 52 scaduti da oltre 90 giorni, 47 da 31 a 90 giorni, 8 negli ultimi 30 giorni, nessuno nei prossimi 7 giorni, 6 fra 8 e 30 giorni.');
        expect(testoFuoriDalGrafico({ bonifici: 0, oltre_30: 0 })).toBe('');
        expect(testoFuoriDalGrafico({ segnalazioni: 7, bonifici: 3, oltre_30: 12 })).toBe('Non sono nel grafico: 7 segnalazioni aperte, 3 bonifici da verificare e 12 compiti fra più di 30 giorni.');
        expect(testoFuoriDalGrafico({ bonifici: 1, oltre_30: 0 })).toBe('Non è nel grafico: 1 bonifico da verificare.');
        expect(testoFuoriDalGrafico({ segnalazioni: 1, bonifici: 0, oltre_30: 4 })).toBe('Non sono nel grafico: 1 segnalazione aperta e 4 compiti fra più di 30 giorni.');
    });
});

describe('il grafico «Per tipo»', () => {
    test('le barre orizzontali grigie, i tipi con più compiti prima; la scelta e la descrizione', () => {
        const d = datiTipi(grafici, colori, null);
        expect(d.labels).toEqual(['Verifica incassi', 'Emissione rate', 'F24']);
        expect(d.datasets[0].data).toEqual([51, 33, 10]);
        expect(d.datasets[0].backgroundColor).toEqual([colori.grigio, colori.grigio, colori.grigio]);
        expect((datiTipi(grafici, colori, 'emissione_rata').datasets[0].backgroundColor as string[])[1]).toBe(colori.indaco);

        const o = opzioniTipi(grafici, colori, () => undefined);
        expect(o.indexAxis).toBe('y');
        expect(o.plugins.legend.display).toBe(false);
        expect(descrizioneTipi(grafici)).toBe('Compiti per tipo: Verifica incassi 51, Emissione rate 33, F24 10.');
    });
});

describe('la card «Sei mesi»', () => {
    test('mentre i condomini arrivano lo dice; poi le righe da seguire adesso e i rendiconti', () => {
        expect(testoCardSeiMesi({ adesso: 0, rendiconti: 0 }, { inCorso: true, fatti: 12, totale: 40 })).toBe('calcolo 12 su 40');
        expect(testoCardSeiMesi({ adesso: 0, rendiconti: 1 }, { inCorso: false, fatti: 40, totale: 40 })).toBe('rendiconto da portare');
        expect(testoCardSeiMesi({ adesso: 2, rendiconti: 3 }, { inCorso: false, fatti: 40, totale: 40 })).toBe('2 righe da seguire, 3 rendiconti da portare');
        expect(testoCardSeiMesi({ adesso: 1, rendiconti: 0 }, { inCorso: false, fatti: 40, totale: 40 })).toBe('riga da seguire adesso');
        expect(testoCardSeiMesi({ adesso: 0, rendiconti: 0 }, { inCorso: false, fatti: 40, totale: 40 })).toBe('niente entro 60 giorni');
    });
});

describe('il gruppo «Da moderare»', () => {
    test('il titolo del gruppo, «e altri N», la data «dal …»', () => {
        expect(titoloDelGruppo('da_moderare')).toBe('Da moderare');
        expect(testoEAltri('da_moderare', 1)).toBe('e un altro da moderare');
        expect(testoEAltri('da_moderare', 4)).toBe('e altri 4 da moderare');
        expect(testoDellaData({ gruppo: 'da_moderare', giorno: '2026-11-16' })).toBe('dal 16/11/2026');
    });

    test('le righe con le testate: il gruppo nuovo ha la sua, con il numero del server', () => {
        const r = (id: number, gruppo: string) => ({ id, gruppo }) as unknown as RigaCompito;
        const voci = righeConIGruppi([r(1, 'bonifici'), r(2, 'da_moderare'), r(3, 'da_moderare'), r(4, 'avanti')],
            { scaduti: 0, segnalazioni: 0, bonifici: 2, da_moderare: 5, avanti: 1 });

        expect(voci.filter((v) => v.tipo === 'gruppo').map((v) => (v as { titolo: string; numero: number }).titolo + ' ' + (v as { numero: number }).numero))
            .toEqual(['Bonifici da verificare 2', 'Da moderare 5', 'Più avanti 1']);
    });

    test('sotto il grafico dice anche i compiti da moderare, che non ci sono', () => {
        expect(testoFuoriDalGrafico({ segnalazioni: 8, bonifici: 0, da_moderare: 3, oltre_30: 0 })).toBe('Non sono nel grafico: 8 segnalazioni aperte e 3 compiti da moderare.');
        expect(testoFuoriDalGrafico({ bonifici: 0, da_moderare: 1, oltre_30: 0 })).toBe('Non è nel grafico: 1 compito da moderare.');
    });

    test('il tipo ha la sua icona', () => {
        expect(aspettoDelTipo('da_moderare').icona).toBe('commento');
    });
});
