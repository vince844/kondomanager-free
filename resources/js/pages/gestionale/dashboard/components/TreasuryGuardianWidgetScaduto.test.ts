// @vitest-environment jsdom

/**
 * La tesoreria a 30 giorni con lo scaduto dei condòmini: le entrate attese divise nel riquadro della «i» in rate già scadute e in scadenza; le persone con
 * rate scadute (fino a 8 tutte, oltre le prime 5 e quante mancano con la loro cifra; con il collegamento al loro estratto conto) e lo scaduto per fasce; le
 * fatture dei fornitori scadute da prima si chiamano per quello che sono. Parole: «scaduto», «in ritardo di N giorni».
 */
import { describe, expect, test, vi } from 'vitest';
import { mount } from '@vue/test-utils';

vi.mock('@inertiajs/vue3', async (importOriginal) => ({
    ...(await importOriginal<typeof import('@inertiajs/vue3')>()),
    Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
    router: { visit: vi.fn(), reload: vi.fn() },
}));
vi.mock('@/composables/permissions', () => ({ usePermission: () => ({ generateRoute: (nome: string) => `admin.${nome}`, hasPermission: () => true }) }));
(globalThis as any).route = (nome: string, p?: Record<string, unknown>) => `/${nome}/${Object.values(p ?? {}).join('/')}`;

import TreasuryGuardianWidget from './TreasuryGuardianWidget.vue';
import { persona as personaDiProva, tesoreria } from './tesoreriaDiProva';

const stubs = { LettereDialog: true, DaDoveViene: true, Popover: { template: '<div><slot /></div>' }, PopoverTrigger: { template: '<div><slot /></div>' },
    PopoverContent: { template: '<div><slot /></div>' }, TooltipProvider: { template: '<div><slot /></div>' }, Tooltip: { template: '<div><slot /></div>' },
    TooltipTrigger: { template: '<span><slot /></span>' }, TooltipContent: { template: '<div data-testid="tooltip"><slot /></div>' } };
const persona = (n: number, importo: number, giorni: number, altro: Record<string, unknown> = {}) =>
    personaDiProva(n, { importoCents: importo, giorniRitardo: giorni, ritardoTesto: `in ritardo di ${giorni} ${giorni === 1 ? 'giorno' : 'giorni'}`, ...altro });
async function monta(altro: Record<string, unknown>) {
    const w = mount(TreasuryGuardianWidget, { props: { treasury: tesoreria({ livello: 'verde', giornoScopertoPrevisto: null, incassiScadutiCents: 15000, incassiInScadenzaCents: 10000,
        incassiAttesiCents: 25000, ...altro }) as any }, global: { stubs } });
    const persone = w.find('[data-testid="cassetto-persone"] button');
    if (persone.exists()) await persone.trigger('click');

    return w;
}
const pulito = (t: string) => t.replace(/\s+/g, ' ').trim();

describe('le entrate attese, divise', () => {
    test('le rate già scadute e quelle in scadenza entro 30 giorni, ognuna con la sua cifra, al netto del credito', async () => {
        const testo = pulito((await monta({})).text());
        expect(testo).toContain('Le rate dei condòmini già scadute (€ 150,00) e quelle che scadono nei 30 giorni (€ 100,00), al netto del credito di ognuno nella stessa gestione.');
        expect(testo).toContain('+€ 250,00');
        expect(testo).not.toContain('Rate in Arr.');
    });
});

describe('le persone con rate scadute', () => {
    test('ognuna con l\'unità, lo scaduto, i giorni di ritardo e il collegamento al suo estratto conto; poi quante mancano e le fasce', async () => {
        const w = await monta({
            morositaImpattanti: [1, 2, 3, 4, 5].map((n) => persona(n, n === 1 ? 15000 : 5000, n === 1 ? 74 : 1)),
            morositaPersone: 8, morositaAltri: 3, morositaAltriCents: 9000, scadutoInTutto: 44000,
            scadutoPerFasce: { '1_30': 26000, '31_90': 16000, oltre_90: 2000 },
        });
        const testo = pulito(w.text());
        expect(testo).toContain('Persona 1 — Int. 1');
        expect(testo).toContain('€ 150,00');
        expect(testo).toContain('in ritardo di 74 giorni');
        expect(testo).toContain('in ritardo di 1 giorno');
        expect(w.findAll('a').map((a) => a.attributes('href'))).toContain('/admin.gestionale.anagrafiche.estratto-conto/7/1');
        expect(testo).toContain('Qui le 5 con lo scaduto più alto; mancano 3 persone, € 90,00');
        expect(w.findAll('[data-testid="fascia"]').map((f) => pulito(f.text()))).toEqual(['1–30 giorni € 260,00', '31–90 giorni € 160,00', 'oltre 90 giorni € 20,00']);
        expect(testo.toLowerCase()).not.toMatch(/moros|esigibil|diffidat|ingiungibil/);
    });

    test('senza persone con rate scadute il cassetto non c\'è', async () => {
        const w = await monta({ morositaImpattanti: [], morositaPersone: 0, scadutoInTutto: 0, scadutoPerFasce: { '1_30': 0, '31_90': 0, oltre_90: 0 } });
        expect(w.find('[data-testid="cassetto-persone"]').exists()).toBe(false);
        expect(pulito(w.text())).not.toContain('in ritardo di');
    });
});

describe('le fatture dei fornitori scadute da prima', () => {
    test('si chiamano «Fatture pregresse non pagate»', async () => {
        const testo = pulito((await monta({ debitiPregressiScadutiCents: 150000 })).text());
        expect(testo).toContain('Fatture pregresse non pagate');
        expect(testo).not.toContain('Pregressi non pagati');
    });
});

describe('le due cifre dello scaduto non si contraddicono', () => {
    test('le entrate attese dicono che sono al netto del credito; il cassetto dice che lo scaduto è lordo e mostra il credito di ognuno', async () => {
        const w = await monta({
            incassiScadutiCents: 0, morositaPersone: 2,
            morositaImpattanti: [persona(1, 20000, 74, { creditoCents: 20000 }), persona(2, 5000, 1, { creditoCents: 0 })],
        });
        const testo = pulito(w.text());
        expect(testo).toContain('al netto del credito di ognuno nella stessa gestione');
        expect(testo).toContain('Lo scaduto di ognuno, senza togliere il suo credito');
        expect(testo).toContain('credito € 200,00');
        // Chi non ha credito non ha la riga.
        expect(testo.match(/credito € /g)).toHaveLength(1);
    });
});
