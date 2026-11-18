// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
/**
 * Le frasi degli interessi e delle spese per il ritardo: i tratti detti per esteso, gli interessi esatti con quattro decimali, il totale delle sole righe
 * tenute, ciò che si dice accanto alla persona, gli avvisi dalla tabella del server, la regola del recupero in una riga.
 */
import { describe, expect, test } from 'vitest';
import {
    FRASE_CALCOLO, avvisiDi, causaleDiSerie, euroEsatti, testoAccanto, testoCausa, testoDellaRegola, testoFinoAllAccordo, testoGiaRegistrati, testoTratto, totaleTenuto,
    type PersonaInteressi,
} from './accessori';

const tratto = { dal: '2026-04-01', al: '2026-04-30', giorni: 30, capitale: 30000, tasso: '1,60 %', fonte: 'tasso legale', divisore: 365, esatti: 3945, causa: 'scadenza' };
const riga = (chiave: string, interessi: number, fermo: string | null = null) => ({
    chiave, rata_id: 1, immobile_id: 1, unita: 'U1', rata: 'Rata 1 — Preventivo 2026', piano: 'Preventivo 2026', gestione_id: 2, gestione: 'Ordinaria 2026',
    scadenza: '2026-03-31', dal: '2026-04-01', gia_registrati_al: null, tratti: [tratto], interessi, fermo,
});
const neri: PersonaInteressi = { anagrafica_id: 1, nome: 'Neri', righe: [riga('1|1|1', 92), riga('1|2|1', 20), riga('1|3|1', 0, 'Manca il tasso legale del 2018.')], totale: 112,
    crediti_sulle_rate: 0, anticipi: 0, quote_di_inquilino: 0 };

describe('la proposta', () => {
    test('un tratto per esteso, con gli interessi esatti a quattro decimali', () => {
        expect(testoTratto(tratto)).toBe('dal 01/04/2026 al 30/04/2026, 30 giorni su € 300,00 al 1,60 % (tasso legale), su 365');
        expect(testoTratto({ ...tratto, giorni: 1 })).toContain(', 1 giorno su ');
        expect(euroEsatti(3945)).toBe('€ 0,3945');
        expect(euroEsatti(5260)).toBe('€ 0,5260');
        expect(testoCausa('versamento')).toBe('dopo un versamento');
        expect(testoCausa('storno')).toBe('dopo un versamento tornato indietro');
    });

    test('il totale conta solo le righe tenute, mai quelle ferme', () => {
        expect(totaleTenuto([neri], new Set(['1|1|1', '1|2|1', '1|3|1']))).toBe(112);
        expect(totaleTenuto([neri], new Set(['1|2|1']))).toBe(20);
        expect(totaleTenuto([neri], new Set())).toBe(0);
    });

    test('accanto alla persona: i crediti che coprono e le quote di inquilini, che non si propongono', () => {
        expect(testoAccanto(neri)).toEqual([]);
        expect(testoAccanto({ ...neri, crediti_sulle_rate: 30000 })).toEqual(['Alla data ha € 300,00 di crediti e anticipi: coprono prima le rate più vecchie, e lì gli interessi non ci sono.']);
        expect(testoAccanto({ ...neri, quote_di_inquilino: 9000 })[0]).toBe('Quote di inquilini scadute: € 90,00. Gli interessi non si propongono: se vuoi, addebitali al proprietario con «Spese del ritardo».');
        expect(testoGiaRegistrati('2026-06-29')).toBe('Interessi già registrati fino al 29/06/2026: la proposta riparte dal giorno dopo.');
        expect(testoGiaRegistrati(null)).toBeNull();
    });

    test('l\'accordo di rientro: la proposta lascia fuori i giorni dalla data dell\'accordo, quelli prima restano (decisione 438)', () => {
        expect(testoAccanto({ ...neri, in_accordo: 20000 })).toEqual(['€ 200,00 sono in un accordo di rientro in corso: la proposta lascia fuori i giorni dalla data '
            + 'dell\'accordo; i giorni prima dell\'accordo restano nella proposta.']);
        // La riga della rata coperta dice fin dove arriva: il giorno prima dell'accordo.
        expect(testoFinoAllAccordo({ al: '2026-07-14', accordo_dal: '2026-07-15' })).toBe('Fino al 14/07/2026: dal 15/07/2026 la rata è in un accordo di rientro, e i giorni '
            + 'dell\'accordo restano fuori finché l\'accordo non è decaduto e il calendario non è finito, o è versato tutto.');
        expect(testoFinoAllAccordo({ al: '2026-07-20', accordo_dal: null })).toBeNull();
        expect(testoFinoAllAccordo({})).toBeNull();
    });

    test('una rata coperta in parte, con gli interessi che continuano sulla parte fuori dall\'accordo (decisione 450): la riga arriva alla data, e lo dice', () => {
        expect(testoFinoAllAccordo({ al: '2026-07-20', accordo_dal: '2026-07-15', accordo_in_parte: true })).toBe('Dal 15/07/2026 una parte della rata è in un accordo '
            + 'di rientro: sulla parte fuori dall\'accordo gli interessi continuano, e la parte nell\'accordo resta fuori finché l\'accordo non è decaduto e il calendario '
            + 'non è finito, o è versato tutto.');
        expect(testoCausa('accordo')).toBe('dal giorno dell\'accordo, solo la parte fuori dall\'accordo');
    });
});

describe('le spese e le regole', () => {
    test('gli avvisi vengono dalla tabella del server, per tipo e base; senza una scelta nessuno', () => {
        const avvisi = { legali_giudiziali: { nessuna: ['Le spese della causa si addebitano con la condanna alle spese: controlla la base.'], provvedimento: [] } };
        expect(avvisiDi(avvisi, 'legali_giudiziali', 'nessuna')).toEqual(['Le spese della causa si addebitano con la condanna alle spese: controlla la base.']);
        expect(avvisiDi(avvisi, 'legali_giudiziali', 'provvedimento')).toEqual([]);
        expect(avvisiDi(avvisi, '', 'nessuna')).toEqual([]);
        expect(causaleDiSerie('sollecito', [{ valore: 'sollecito', nome: 'Spese di sollecito' }])).toBe('Spese di sollecito');
    });

    test('una regola del recupero in una riga', () => {
        expect(testoDellaRegola({ giorni_decorrenza: 0, giorni_tolleranza: null, termine_lettere_giorni: null }))
            .toBe('Interessi dal giorno dopo la scadenza · tolleranza prima di sollecitare non scritta · termine delle lettere quello della finestra.');
        expect(testoDellaRegola({ giorni_decorrenza: 10, giorni_tolleranza: 15, termine_lettere_giorni: 1 }))
            .toBe('Interessi dal giorno dopo la scadenza più 10 giorni · tolleranza prima di sollecitare 15 giorni · termine di serie delle lettere 1 giorno.');
    });
});

describe('come si contano i giorni', () => {
    test('dal giorno dopo la scadenza, o dopo l\'emissione se la rata è emessa più tardi: prima di essere chiesta la rata non è in ritardo', () => {
        expect(FRASE_CALCOLO).toContain('I giorni vanno dal giorno dopo la scadenza, più quelli che le regole del condominio concedono (o dal giorno dopo l\'emissione, '
            + 'se la rata è emessa più tardi), alla data della proposta compresa; un versamento conta dal giorno dopo.');
    });
});
