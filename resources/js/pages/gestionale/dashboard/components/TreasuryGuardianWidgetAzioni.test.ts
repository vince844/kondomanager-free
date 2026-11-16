// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
// @vitest-environment jsdom

/**
 * Le azioni veloci della tesoreria: «Paga» sulle fatture con la fattura già scelta; sulle persone «Registra l'incasso» con
 * la persona già scelta o «Compensa» con la rata che il credito copre (mai con l'intenzione di usare il credito: la pagina si apre con la proposta e non
 * scrive niente), e «Manda un sollecito» solo quando la regola lo suggerisce. A chi ha la diffida, un accordo, il fascicolo, la prossima azione è nei sei mesi:
 * il collegamento scende alla riga della persona nel riquadro. Con «Scelgo io la prossima azione» «Scrivi una lettera» per tutti. Il nome apre l'estratto
 * conto. I pulsanti hanno la stessa larghezza in ogni riga.
 */
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
import { fattura, persona, tesoreria } from './tesoreriaDiProva';

const LettereDialog = { name: 'LettereDialog', props: ['open', 'condominioId', 'persona', 'tipoIniziale', 'oggi'], template: '<div data-testid="lettere" />' };
const stubs = { LettereDialog, DaDoveViene: true, Popover: { template: '<div><slot /></div>' }, PopoverTrigger: { template: '<div><slot /></div>' },
    PopoverContent: { template: '<div><slot /></div>' } };
async function monta(altro: Record<string, unknown> = {}, props: Record<string, unknown> = {}) {
    const w = mount(TreasuryGuardianWidget, { props: { treasury: tesoreria(altro) as any, ...props }, global: { stubs } });
    await w.get('[data-testid="cassetto-persone"] button').trigger('click');

    return w;
}
const riga = (w: Awaited<ReturnType<typeof monta>>, n: number) => w.findAll('[data-testid="riga-persona"]')[n];
const pulsanti = (r: ReturnType<typeof riga>) => r.findAll('[data-testid^="azione-"]').map((b) => b.text().replace(/\s+/g, ' ').trim());

describe('«Paga» sulle fatture', () => {
    test('apre il pagamento con la fattura già scelta; la riga dice il fornitore, il numero, la scadenza e l\'importo', async () => {
        const w = await monta({ fattureInScadenza: [fattura(1), fattura(2, { scaduta: true, dataScadenza: '2026-11-02' })] });
        const righe = w.findAll('[data-testid="riga-fattura"]');
        expect(righe).toHaveLength(2);
        const testo = righe[1].text().replace(/\s+/g, ' ');
        expect(testo).toContain('Edil Rossi srl — n. 42/2026');
        expect(testo).toContain('scaduta il 02/11/2026');
        expect(testo).toContain('€ 502,00');
        expect(righe[0].get('a[data-testid="azione-paga"]').attributes('href')).toBe('/admin.gestionale.pagamenti-fornitori.create?condominio=7&fattura_id=101');
        expect(righe[1].get('[data-testid="scadenza"]').classes().join(' ')).toContain('text-red-600');
    });

    test('oltre 8 fatture la riga di fondo dice quante mancano e porta alle fatture da pagare', async () => {
        const w = await monta({ fattureInScadenza: [1, 2, 3, 4, 5].map((n) => fattura(n)), fattureQuante: 9, fattureMancano: { quante: 4, cifra: 230000 } });
        const fondo = w.get('[data-testid="fondo-fatture"]');
        expect(fondo.findAll(':scope > *').map((x) => x.text().trim())).toEqual(['Qui le 5 con la scadenza più vicina; mancano 4 fatture, € 2.300,00', 'Vedi le fatture da pagare →']);
        expect(fondo.get('a').attributes('href')).toBe('/admin.gestionale.fatture.index?condominio=7&stato_pagamento=aperta');
    });
});

describe('le azioni delle persone', () => {
    test('«Registra l\'incasso» con la persona già scelta, e «Manda un sollecito» quando la regola lo suggerisce, che apre le lettere con il sollecito', async () => {
        const w = await monta();
        const r = riga(w, 0);
        expect(pulsanti(r)).toEqual(['Registra l\'incasso', 'Manda un sollecito']);
        expect(r.get('a[data-testid="azione-incasso"]').attributes('href')).toBe('/admin.gestionale.movimenti-rate.create?condominio=7&prefill_anagrafica_id=1');
        await r.get('[data-testid="azione-sollecito"]').trigger('click');
        const lettere = w.getComponent(LettereDialog);
        expect(lettere.props('open')).toBe(true);
        expect(lettere.props('tipoIniziale')).toBe('sollecito');
        expect(lettere.props('persona')).toEqual({ id: 1, nome: 'Persona 1' });
        expect(lettere.props('oggi')).toBe('2026-11-15');
    });

    test('«Compensa» al posto di «Registra l\'incasso» con R2: la rata che il credito copre, mai l\'intenzione di usare il credito', async () => {
        const w = await monta({ morositaImpattanti: [persona(1, { creditoCents: 9000, passo: { caso: 'R2', passo: 'compensa', frase: 'Il credito di € 90,00 copre la rata.', frase_dei_fatti: 'x', note: [], rata_compensa: 77 } })] });
        const r = riga(w, 0);
        expect(pulsanti(r)).toEqual(['Compensa']);
        const href = r.get('a[data-testid="azione-incasso"]').attributes('href')!;
        expect(href).toBe('/admin.gestionale.movimenti-rate.create?condominio=7&prefill_anagrafica_id=1&prefill_rata_id=77');
        expect(href).not.toContain('intent_usa_credito');
        expect(r.text()).toContain('credito € 90,00');
    });

    test('a chi ha la diffida niente sollecito: la frase della regola e «La prossima azione è nei sei mesi ↓», verso la sua riga del riquadro', async () => {
        const w = await monta({ morositaImpattanti: [
            persona(1, { passo: { caso: 'R9', passo: 'fascicolo', frase: 'Diffida spedita il 03/10/2026: il termine del 18/10/2026 è passato.', frase_dei_fatti: 'x', note: [], rata_compensa: null } }),
            persona(2, { passo: { caso: 'R8', passo: null, frase: 'Diffida spedita il 03/11/2026: il termine è il 18/11/2026.', frase_dei_fatti: 'x', note: [], rata_compensa: null } }),
        ] }, { righeSeiMesi: { 1: 'sei-mesi-25' } });
        const prima = riga(w, 0);
        expect(pulsanti(prima)).toEqual(['Registra l\'incasso']);
        expect(prima.text()).toContain('Diffida spedita il 03/10/2026: il termine del 18/10/2026 è passato.');
        expect(prima.get('a[data-testid="nei-sei-mesi"]').attributes('href')).toBe('#sei-mesi-25');
        expect(prima.get('a[data-testid="nei-sei-mesi"]').text()).toBe('La prossima azione è nei sei mesi ↓');
        // Con il termine non passato la regola non suggerisce niente: la frase sola.
        const seconda = riga(w, 1);
        expect(pulsanti(seconda)).toEqual(['Registra l\'incasso']);
        expect(seconda.find('[data-testid="nei-sei-mesi"]').exists()).toBe(false);
        expect(seconda.text()).toContain('il termine è il 18/11/2026');
    });

    test('con «Scelgo io la prossima azione» «Scrivi una lettera» per tutti, che apre le lettere senza un tipo proposto', async () => {
        const w = await monta({ suggerisceAzione: false, morositaImpattanti: [persona(1, { passo: { caso: 'R15', passo: null, frase: 'Nessuna lettera.', frase_dei_fatti: 'Nessuna lettera.', note: [], rata_compensa: null } }), persona(2)] });
        expect(pulsanti(riga(w, 0))).toEqual(['Registra l\'incasso', 'Scrivi una lettera']);
        await riga(w, 0).get('[data-testid="azione-scrivi"]').trigger('click');
        expect(w.getComponent(LettereDialog).props('tipoIniziale')).toBeNull();
    });

    test('il nome apre l\'estratto conto; i pulsanti hanno la larghezza della loro colonna; il ritardo e le note in grigio', async () => {
        const w = await monta({ morositaImpattanti: [persona(1, { passo: { caso: 'R15', passo: 'sollecito', frase: 'Nessuna lettera.', frase_dei_fatti: 'x', note: ['Fuori dall\'accordo: € 100,00.'], rata_compensa: null } })] });
        const r = riga(w, 0);
        expect(r.get('a[data-testid="nome-persona"]').attributes('href')).toBe('/admin.gestionale.anagrafiche.estratto-conto?condominio=7&anagrafica=1');
        expect(r.get('a[data-testid="nome-persona"]').text()).toBe('Persona 1 — Int. 1');
        expect(r.text()).toContain('in ritardo di 31 giorni');
        expect(r.text()).toContain('Fuori dall\'accordo: € 100,00.');
        for (const b of r.findAll('[data-testid^="azione-"]')) {
            expect(b.classes()).toContain('w-full');
        }
    });
});
