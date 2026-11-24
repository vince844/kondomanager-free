// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * Le parole della scheda «Errori» (1.12.0-beta.14, decisione 698): lo stato con la scritta e il suo colore, dove è successo, quante volte, la data di Roma,
 * il codice cercato con lo stesso alfabeto del server, il cartellino dei nuovi.
 */
import { describe, expect, test } from 'vitest';
import { badgeDelloStato, codiceDaTesto, dataBreve, doveESuccesso, etichettaNuovi, testoVolte } from './errori';

describe('la scheda «Errori»', () => {
    test('lo stato si dice con la scritta, e ha il suo colore anche nel tema scuro', () => {
        expect(badgeDelloStato('avvisato').testo).toBe('Avvisato');
        expect(badgeDelloStato('nuovo').testo).toBe('Nuovo');
        expect(badgeDelloStato('visto').testo).toBe('Visto');
        for (const s of ['avvisato', 'nuovo', 'visto'] as const) expect(badgeDelloStato(s).classi).toContain('dark:');
        expect(badgeDelloStato('avvisato').classi).toContain('amber');
        expect(badgeDelloStato('nuovo').classi).toContain('rose');
    });

    test('dove è successo: la pagina con il metodo, o il lavoro in background', () => {
        expect(doveESuccesso({ metodo: 'GET', pagina: '/admin/x/{condominio}', azione: 'X@y' })).toEqual({ metodo: 'GET', testo: '/admin/x/{condominio}' });
        expect(doveESuccesso({ metodo: null, pagina: null, azione: 'Lavoro in background: InviaComunicazione' }))
            .toEqual({ metodo: null, testo: 'Lavoro in background: InviaComunicazione' });
    });

    test('quante volte, all\'italiana', () => {
        expect(testoVolte(1)).toBe('1 volta');
        expect(testoVolte(1234)).toBe('1.234 volte');
    });

    test('la data è quella di Roma: le 23:30 UTC del 21/11 sono le 00:30 del 22/11', () => {
        expect(dataBreve('2026-11-21T23:30:00Z')).toBe('22/11 00:30');
        expect(dataBreve(null)).toBe('—');
    });

    test('il codice cercato: minuscole, spazi e trattino non contano; i caratteri che si confondono non sono un codice', () => {
        expect(codiceDaTesto('k7m2qx')).toBe('K7M2QX');
        expect(codiceDaTesto(' K7M-2QX ')).toBe('K7M2QX');
        expect(codiceDaTesto('K7M2Q0')).toBeNull();
        expect(codiceDaTesto('Unknown')).toBeNull();
    });

    test('il cartellino dei nuovi: niente con zero', () => {
        expect(etichettaNuovi(0)).toBeNull();
        expect(etichettaNuovi(1)).toBe('1 nuovo');
        expect(etichettaNuovi(2)).toBe('2 nuovi');
    });
});
