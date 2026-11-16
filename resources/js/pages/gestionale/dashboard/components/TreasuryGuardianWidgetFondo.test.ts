// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
// @vitest-environment jsdom

/**
 * La tesoreria a 30 giorni montata: le quattro cifre su due righe, lo stato con la sua scritta, i due cassetti come «Sei mesi per agire» (con la freccia,
 * senza scorrimento) e il fondo della lista delle persone: «Scaduto in tutto · N persone» con la cifra, la barra
 * delle tre fasce in proporzione, le tre fasce sotto in tre colonne (a € 0,00 spente, oltre 90 giorni in rosso), la riga di fondo con quante persone mancano e
 * la loro cifra a sinistra e «Vedi tutte le rate scadute →» a destra. Fino a 8 persone tutte, mai «e altri 1». Nessuna scritta sotto i 12 px (11 solo per le
 * note piccole), nessuna pillola, nessun «passo» a video.
 */
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { describe, expect, test, vi } from 'vitest';
import { mount } from '@vue/test-utils';

vi.mock('@inertiajs/vue3', async (importOriginal) => ({
    ...(await importOriginal<typeof import('@inertiajs/vue3')>()),
    Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
    router: { visit: vi.fn(), reload: vi.fn() },
}));
vi.mock('@/composables/permissions', () => ({ usePermission: () => ({ generateRoute: (nome: string) => `admin.${nome}`, hasPermission: () => true }) }));
(globalThis as any).route = (nome: string, p?: Record<string, unknown>) => `/${nome}${p ? '?' + new URLSearchParams(p as Record<string, string>).toString() : ''}`;

import TreasuryGuardianWidget from './TreasuryGuardianWidget.vue';
import { persona, tesoreria } from './tesoreriaDiProva';

const stubs = { LettereDialog: true, DaDoveViene: true, Popover: { template: '<div><slot /></div>' }, PopoverTrigger: { template: '<div><slot /></div>' },
    PopoverContent: { template: '<div><slot /></div>' } };
const monta = (altro: Record<string, unknown> = {}) => mount(TreasuryGuardianWidget, { props: { treasury: tesoreria(altro) as any }, global: { stubs } });
const pulito = (t: string) => t.replace(/\s+/g, ' ').trim();
const sorgente = readFileSync(resolve(process.cwd(), 'resources/js/pages/gestionale/dashboard/components/TreasuryGuardianWidget.vue'), 'utf8');

describe('le quattro cifre e lo stato', () => {
    test('«Disponibile oggi» con «si può usare», «Entrate attese» «se arrivano», «Uscite previste», «Fra 30 giorni»', () => {
        const w = monta();
        const cifre = w.findAll('[data-testid="cifra-tesoreria"]').map((c) => pulito(c.text()));
        expect(cifre).toHaveLength(4);
        expect(cifre[0]).toContain('Disponibile oggi');
        expect(cifre[0]).toContain('€ 4.480,38');
        expect(cifre[0]).toContain('si può usare');
        expect(cifre[1]).toContain('Entrate attese');
        expect(cifre[1]).toContain('+€ 1.240,00');
        expect(cifre[1]).toContain('se arrivano');
        expect(cifre[2]).toContain('Uscite previste');
        expect(cifre[2]).toContain('−€ 5.000,00');
        expect(cifre[3]).toContain('Fra 30 giorni');
        expect(cifre[3]).toContain('€ 280,38');
        expect(cifre[3]).toContain('«Senza le rate», come il grafico');
    });

    test('«Fra 30 giorni» segue lo scenario scelto nel saldo previsto; il semaforo no', () => {
        const cifra = (w: ReturnType<typeof monta>) => pulito(w.findAll('[data-testid="cifra-tesoreria"]')[3].text());
        const stato = (w: ReturnType<typeof monta>) => pulito(w.get('[data-testid="stato-tesoreria"]').text());
        const senza = monta();
        const con = mount(TreasuryGuardianWidget, { props: { treasury: tesoreria() as any, scenario: 'con_rate' }, global: { stubs } });
        const tutti = mount(TreasuryGuardianWidget, { props: { treasury: tesoreria() as any, scenario: 'tutti' }, global: { stubs } });
        expect(cifra(senza)).toContain('€ 280,38');
        expect(cifra(con)).toContain('€ 520,38');
        expect(cifra(con)).toContain('«Con le rate in scadenza», come il grafico');
        expect(cifra(tutti)).toContain('€ 1.520,38');
        expect(cifra(tutti)).toContain('«Se pagano tutti», come il grafico');
        expect([stato(senza), stato(con), stato(tutti)]).toEqual(Array(3).fill('Coperte solo con le entrate attese'));
        expect(pulito(tutti.get('[data-testid="avviso-tesoreria"]').text())).toContain('Sotto zero dal 18/11/2026');
    });

    test('«Fra 30 giorni» sotto zero è rosso, a zero è grigio', () => {
        // L'ultimo giorno del saldo previsto è la cifra «Fra 30 giorni».
        const conIlSaldo = (saldo: number) => {
            const sp = tesoreria().saldoPrevisto as { giorni: Array<{ saldo: number }> };

            return { ...sp, giorni: sp.giorni.map((g) => ({ ...g, saldo })), finaleCents: saldo };
        };
        const rosso = monta({ scenarioPessimisticoCents: -51962, saldoPrevisto: conIlSaldo(-51962) });
        expect(rosso.findAll('[data-testid="cifra-tesoreria"]')[3].find('[data-testid="valore"]').classes().join(' ')).toContain('text-red-600');
        const zero = monta({ scenarioPessimisticoCents: 0, saldoPrevisto: conIlSaldo(0) });
        expect(zero.findAll('[data-testid="cifra-tesoreria"]')[3].find('[data-testid="valore"]').classes().join(' ')).toContain('text-slate-400');
    });

    test('lo stato è un badge con la scritta, e «sotto zero dal …» è la stessa data del grafico', () => {
        const w = monta();
        const stato = w.get('[data-testid="stato-tesoreria"]');
        expect(stato.text()).toContain('Coperte solo con le entrate attese');
        expect(stato.classes()).toContain('rounded-md');
        expect(pulito(w.get('[data-testid="avviso-tesoreria"]').text())).toContain('Sotto zero dal 18/11/2026');
    });
});

describe('i movimenti già registrati con una data nei 30 giorni', () => {
    test('sono nel saldo previsto, e la frase sotto le cifre lo dice con la loro cifra; senza, niente', () => {
        expect(pulito(monta().get('[data-testid="registrati-nel-flusso"]').text()))
            .toBe('Nel saldo previsto ci sono anche 2 movimenti già registrati con una data nei prossimi 30 giorni: € 300,00.');
        const senza = monta({ disponibile: { ...(tesoreria().disponibile as object), futuri: { quanti: 0, cifra: 0, primo: null, nel_flusso: {}, nel_flusso_quanti: 0, nel_flusso_cifra: 0 } } });
        expect(senza.find('[data-testid="registrati-nel-flusso"]').exists()).toBe(false);
    });
});

describe('i due cassetti', () => {
    test('«Fatture in scadenza · 3 · € …» aperto con il semaforo non verde; «Rate scadute dei condòmini · 3 persone · € …» chiuso; si aprono con la freccia', async () => {
        const w = monta();
        const fatture = w.get('[data-testid="cassetto-fatture"]');
        const persone = w.get('[data-testid="cassetto-persone"]');
        expect(pulito(fatture.get('button').text())).toBe('Fatture in scadenza · 3 · € 1.506,00');
        expect(pulito(persone.get('button').text())).toBe('Rate scadute dei condòmini · 3 persone · € 2.400,00');
        expect(fatture.get('button').attributes('aria-expanded')).toBe('true');
        expect(persone.get('button').attributes('aria-expanded')).toBe('false');
        expect(persone.findAll('[data-testid="riga-persona"]')).toHaveLength(0);
        await persone.get('button').trigger('click');
        expect(persone.findAll('[data-testid="riga-persona"]')).toHaveLength(3);
    });

    test('con il semaforo verde il cassetto delle fatture non c\'è, quello delle persone sì', () => {
        const w = monta({ livello: 'verde', giornoScopertoPrevisto: null });
        expect(w.find('[data-testid="cassetto-fatture"]').exists()).toBe(false);
        expect(w.find('[data-testid="cassetto-persone"]').exists()).toBe(true);
    });
});

describe('il fondo della lista delle persone', () => {
    const otto = Array.from({ length: 8 }, (_, i) => persona(i + 1));

    test('8 persone: tutte e 8, e la riga di fondo ha solo «Vedi tutte le rate scadute →»', async () => {
        const w = monta({ morositaImpattanti: otto, morositaPersone: 8, scadutoInTutto: 360000, scadutoPerFasce: { '1_30': 0, '31_90': 360000, oltre_90: 0 } });
        await w.get('[data-testid="cassetto-persone"] button').trigger('click');
        expect(w.findAll('[data-testid="riga-persona"]')).toHaveLength(8);
        const fondo = w.get('[data-testid="fondo-persone"]');
        expect(pulito(fondo.text())).toBe('Vedi tutte le rate scadute →');
        expect(pulito(w.text())).not.toMatch(/e altr[ie] \d/);
    });

    test('oltre 8: le 5 mostrate, «Qui le 5 con lo scaduto più alto; mancano 4 persone, € 415,20» e il collegamento', async () => {
        const w = monta({ morositaImpattanti: otto.slice(0, 5), morositaAltri: 4, morositaAltriCents: 41520, morositaPersone: 9 });
        await w.get('[data-testid="cassetto-persone"] button').trigger('click');
        const fondo = w.get('[data-testid="fondo-persone"]').findAll(':scope > *').map((x) => pulito(x.text()));
        expect(fondo).toEqual(['Qui le 5 con lo scaduto più alto; mancano 4 persone, € 415,20', 'Vedi tutte le rate scadute →']);
        expect(w.get('[data-testid="fondo-persone"] a').attributes('href')).toBe('/admin.gestionale.rate-scadute.index?condominio=7');
    });

    test('«Scaduto in tutto · N persone», la barra in proporzione, le fasce in tre colonne: a zero spente, oltre 90 giorni in rosso', async () => {
        const w = monta({ morositaPersone: 6, scadutoInTutto: 124000, scadutoPerFasce: { '1_30': 24000, '31_90': 0, oltre_90: 100000 } });
        await w.get('[data-testid="cassetto-persone"] button').trigger('click');
        expect(w.get('[data-testid="scaduto-in-tutto"]').findAll(':scope > span').map((x) => pulito(x.text()))).toEqual(['Scaduto in tutto · 6 persone', '€ 1.240,00']);
        const barra = w.findAll('[data-testid="barra-fasce"] > div');
        expect(barra.map((b) => b.attributes('style'))).toEqual(['width: 19.35%;', 'width: 0%;', 'width: 80.65%;']);
        const fasce = w.findAll('[data-testid="fascia"]');
        expect(fasce.map((f) => pulito(f.text()))).toEqual(['1–30 giorni € 240,00', '31–90 giorni € 0,00', 'oltre 90 giorni € 1.000,00']);
        expect(fasce[1].classes().join(' ')).toContain('text-slate-400');
        expect(fasce[2].classes().join(' ')).toContain('text-red-600');
    });
});

describe('le regole del disegno', () => {
    test('nessuna scritta sotto gli 11 px, nessuna pillola, nessuno scorrimento dentro, nessun «passo» a video', () => {
        expect(sorgente).not.toMatch(/text-\[(9|10)px\]/);
        expect(sorgente).not.toContain('rounded-full px');
        expect(sorgente).not.toMatch(/overflow-y-auto|max-h-\[/);
        const w = monta();
        expect(pulito(w.text()).toLowerCase()).not.toMatch(/\bpass[oi]\b/);
    });
});
