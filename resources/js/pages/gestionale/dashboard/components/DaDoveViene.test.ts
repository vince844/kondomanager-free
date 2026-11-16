// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
// @vitest-environment jsdom

/**
 * «Da dove viene» della cifra «Disponibile oggi»: tutto il denaro voce per voce con il suo saldo, i fondi con il loro
 * tipo in un badge `rounded-md`, «Tutto il denaro», i fondi vincolati come righe tolte («vincolato, non disponibile»), la cifra della casella; ciò che non è
 * nella cifra (i movimenti dopo oggi); la frase sull'estratto conto; il saldo della banca scritto nella nota; «Vedi i movimenti →» per ogni cassa.
 */
import { describe, expect, test, vi } from 'vitest';
import { mount } from '@vue/test-utils';

vi.mock('@inertiajs/vue3', async (importOriginal) => ({
    ...(await importOriginal<typeof import('@inertiajs/vue3')>()),
    Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
}));
vi.mock('@/composables/permissions', () => ({ usePermission: () => ({ generateRoute: (nome: string) => `admin.${nome}`, hasPermission: () => true }) }));
(globalThis as any).route = (nome: string, p?: Record<string, unknown>) => `/${nome}${p ? '?' + new URLSearchParams(p as Record<string, string>).toString() : ''}`;

import DaDoveViene from './DaDoveViene.vue';
import { disponibile } from './tesoreriaDiProva';

const monta = (altro: Record<string, unknown> = {}) => mount(DaDoveViene, { props: { disponibile: disponibile(altro) as any, condominioId: 7 } });
const pulito = (t: string) => t.replace(/\s+/g, ' ').trim();

describe('«Da dove viene»', () => {
    test('le righe di tutto il denaro, «Tutto il denaro», le righe tolte, la cifra della casella', () => {
        const w = monta();
        expect(pulito(w.get('h4').text())).toBe('Disponibile oggi: da dove viene');
        // Ogni riga: il nome, il tipo del fondo (se c'è), «Vedi i movimenti →» e, a destra, la cifra.
        const righe = w.findAll('[data-testid="riga-denaro"]').map((r) => [pulito(r.get('p').text()), r.find('[data-testid="tipo-fondo"]').exists() ? pulito(r.get('[data-testid="tipo-fondo"]').text()) : '',
            r.find('a').exists() ? r.get('a').text() : '', pulito(r.findAll('span').at(-1)!.text())]);
        expect(righe).toEqual([
            ['Banca Intesa (conto corrente)', '', 'Vedi i movimenti →', '€ 4.120,38'],
            ['Cassa contanti', '', 'Vedi i movimenti →', '€ 50,00'],
            ['PayPal (cassa virtuale)', '', 'Vedi i movimenti →', '€ 80,00'],
            ['1020 Banca Posta (conto senza cassa)', '', 'Vedi i movimenti →', '€ 30,00'],
            ['Fondo spese generiche', 'generico, si può usare', 'Vedi i movimenti →', '€ 200,00'],
            ['Fondo di riserva', 'vincolato ai lavori: facciata', 'Vedi i movimenti →', '€ 1.000,00'],
        ]);
        const dueColonne = (sel: string) => w.get(sel).findAll(':scope > span').map((x) => pulito(x.text()));
        expect(dueColonne('[data-testid="tutto-il-denaro"]')).toEqual(['Tutto il denaro', '€ 5.480,38']);
        expect(w.findAll('[data-testid="riga-tolta"]').map((r) => r.findAll(':scope > span').map((x) => pulito(x.text())))).toEqual([['Fondo di riserva vincolato, non disponibile', '−€ 1.000,00']]);
        expect(w.get('[data-testid="riga-tolta"]').classes().join(' ')).toContain('text-rose-700');
        expect(dueColonne('[data-testid="disponibile-oggi"]')).toEqual(['Disponibile oggi', '€ 4.480,38']);
        expect(w.text()).toContain('I fondi sono parti del conto corrente: l\'estratto della banca li comprende.');
    });

    test('i tipi dei fondi sono badge con gli angoli `rounded-md`', () => {
        const badge = monta().findAll('[data-testid="tipo-fondo"]');
        expect(badge).toHaveLength(2);
        for (const b of badge) {
            expect(b.classes()).toContain('rounded-md');
            expect(b.classes()).not.toContain('rounded-full');
        }
    });

    test('fuori dalla cifra, la frase sull\'estratto conto e il saldo della nota', () => {
        const testo = pulito(monta().text());
        expect(testo).toContain('2 movimenti con una data dopo oggi, € 300,00 (il primo il 20/11/2026): non sono nella cifra di oggi; entrano nel saldo previsto al loro giorno.');
        expect(testo).toContain('Secondo le registrazioni fino a oggi: non è il saldo dell\'estratto conto della banca.');
        expect(testo).toContain('Nella nota del rendiconto «Esercizio 2025» hai scritto il saldo dell\'estratto conto di «Banca Intesa» al 31/12/2025: € 4.700,00.');
        const senza = pulito(monta({ futuri: { quanti: 0, cifra: 0, primo: null, nel_flusso: {}, nel_flusso_quanti: 0, nel_flusso_cifra: 0 }, banca: [] }).text());
        expect(senza).not.toContain('dopo oggi');
        expect(senza).not.toContain('Nella nota');
    });

    test('«Vedi i movimenti →»: il mastrino del conto nell\'esercizio di oggi; senza esercizio, la pagina della cassa', () => {
        const link = monta().findAll('[data-testid="riga-denaro"] a').map((a) => a.attributes('href'));
        expect(link[0]).toBe('/admin.gestionale.esercizi.conti.movimenti?condominio=7&esercizio=25&contoContabile=11');
        const senzaEsercizio = monta({ esercizio_id: null }).findAll('[data-testid="riga-denaro"]');
        expect(senzaEsercizio[0].get('a').attributes('href')).toBe('/admin.gestionale.casse.show?condominio=7&cassa=1');
        // Un conto senza cassa, senza esercizio, non ha dove andare.
        expect(senzaEsercizio[3].find('a').exists()).toBe(false);
    });

    test('una cassa disattivata lo dice nel nome', () => {
        const w = monta({ righe: [{ voce: 'Vecchio conto', tipo: 'banca', saldo: 12000, cassa_id: 9, conto_contabile_id: 19, attiva: false, etichetta: null }], tolte: [], cifra: 12000, totale: 12000 });
        expect(pulito(w.get('[data-testid="riga-denaro"] p').text())).toBe('Vecchio conto (conto corrente, disattivata)');
    });
});
