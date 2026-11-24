// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * 1.12.0-beta.14, tappa 7 (decisione 705) — le piccole funzioni dell'insieme in `passaggi.ts`: la somma delle quote al centesimo di punto (la richiesta pretende
 * che chi entra porti tutta la quota di chi esce), la frase dei ruoli diversi con la strada, e il riconoscimento della risposta dell'anteprima.
 */
import { describe, expect, test } from 'vitest';

import { eAnteprimaGruppo, erroreRuoliDiversi, sommaQuoteCentesimi, sommaQuoteComeStringa } from './passaggi';

describe('passaggi.ts — l\'insieme', () => {
    test('la somma delle quote è in centesimi di punto interi: niente virgola mobile', () => {
        expect(sommaQuoteCentesimi([50, 50])).toBe(10000);
        expect(sommaQuoteCentesimi([33.34, 33.33])).toBe(6667);
        expect(sommaQuoteCentesimi(['33.33', 33.33, '33.34'])).toBe(10000);
        // 0,1 + 0,2 in virgola mobile dà 0,30000000000000004.
        expect(sommaQuoteCentesimi([0.1, 0.2])).toBe(30);
    });

    test('la quota come la vuole il server: con il punto, senza zeri inutili', () => {
        expect(sommaQuoteComeStringa([50, 50])).toBe('100');
        expect(sommaQuoteComeStringa([33.34, 33.33])).toBe('66.67');
        expect(sommaQuoteComeStringa([12.5, 12.5])).toBe('25');
    });

    test('i ruoli diversi: la frase con la strada, senza il genere; lo stesso ruolo non dice niente', () => {
        expect(erroreRuoliDiversi([{ nome: 'Cecilia Angeli', tipologia: 'proprietario' }, { nome: 'Marta Acciaioli', tipologia: 'nuda_proprietario' }]))
            .toBe('Chi esce insieme deve avere lo stesso ruolo: Cecilia Angeli ha la proprietà piena, Marta Acciaioli la nuda proprietà. Registra due passaggi, uno per persona.');
        // Con tre persone, il numero come lo scrive il server (`AnteprimaPassaggioRequest::controllaLInsieme`).
        expect(erroreRuoliDiversi([{ nome: 'Cecilia Angeli', tipologia: 'proprietario' }, { nome: 'Marta Acciaioli', tipologia: 'nuda_proprietario' }, { nome: 'Tina Angeli', tipologia: 'proprietario' }]))
            .toBe('Chi esce insieme deve avere lo stesso ruolo: Cecilia Angeli ha la proprietà piena, Marta Acciaioli la nuda proprietà, Tina Angeli la proprietà piena. Registra tre passaggi, uno per persona.');
        expect(erroreRuoliDiversi([{ nome: 'A', tipologia: 'proprietario' }, { nome: 'B', tipologia: 'proprietario' }])).toBeNull();
        expect(erroreRuoliDiversi([{ nome: 'A', tipologia: 'proprietario' }])).toBeNull();
        expect(erroreRuoliDiversi([])).toBeNull();
    });

    test('la risposta dell\'anteprima è un insieme solo con `insieme: true`', () => {
        expect(eAnteprimaGruppo({ insieme: true } as never)).toBe(true);
        expect(eAnteprimaGruppo({ nudi: null } as never)).toBe(false);
        expect(eAnteprimaGruppo(null)).toBe(false);
    });
});
