// @vitest-environment jsdom

/**
 * La pagina del piano rate: la card dello scaduto, la colonna per riga e il badge della cella con i dati del server (`emessa`,
 * `scaduta`), e la data di emissione proposta nel giorno di chi usa la pagina.
 *
 * Il caso è quello di Rossi alle 11:00 del 15/12/2026: rate da € 100,00; la 1 (14/12) emessa e scaduta, la 2 (15/12) e la 3
 * (16/12) emesse e non scadute (il giorno della scadenza non è scaduto), la 4 (10/12) in bozza, la rata 0 a credito. Fino alla
 * 1.12.0-beta.2 la card «Da Incassare» diceva € 300,00 (la bozza e il giorno stesso dentro); lo scaduto è € 100,00.
 */
process.env.TZ = 'Europe/Rome';

import { afterEach, describe, expect, test, vi } from 'vitest';
import { flushPromises, mount, type VueWrapper } from '@vue/test-utils';

vi.mock('@inertiajs/vue3', async (importOriginal) => ({
    ...(await importOriginal<typeof import('@inertiajs/vue3')>()),
    Head: { template: '<span />' },
    Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
    router: { delete: vi.fn(), post: vi.fn(), get: vi.fn(), visit: vi.fn(), reload: vi.fn() },
    usePage: () => ({ url: '/admin/gestionale/7/esercizi/1/piani-rate/5', props: { flash: {}, auth: { user: { roles: ['amministratore'], permissions: [] } } } }),
}));
vi.mock('@/composables/permissions', () => ({
    usePermission: () => ({
        generatePath: (percorso: string, parametri: Record<string, number> = {}) => '/admin/' + percorso.replace(/:(\w+)/g, (_, nome: string) => String(parametri[nome])),
        generateRoute: (nome: string) => nome,
        hasPermission: () => true,
        hasRole: () => true,
    }),
}));
vi.mock('laravel-vue-i18n', () => ({ trans: (c: string) => c }));
(globalThis as unknown as { route: unknown }).route = (nome: string, parametri?: Record<string, unknown>) => `/${nome}?${JSON.stringify(parametri ?? {})}`;

const PianiRateShow = (await import('@/pages/gestionale/pianiRate/PianiRateShow.vue')).default;

const rata = (numero: number, scadenza: string, emessa: boolean, scaduta: boolean, importo = 10000, extra: Record<string, unknown> = {}) => ({
    numero, scadenza, emessa, scaduta, importo, importo_pagato: 0, residuo: Math.max(0, importo), stato: importo < 0 ? 'credito' : 'da_pagare', data_pagamento: null, dettaglio_quote: [], ...extra,
});

const ROSSI = {
    anagrafica: { id: 11, nome: 'Rossi Mario', indirizzo: 'Via Roma 1' },
    saldo_iniziale: -3000,
    rate: [
        rata(0, '2026-01-05', false, true, -3000),
        rata(1, '2026-12-14', true, true),
        rata(2, '2026-12-15', true, false),
        rata(3, '2026-12-16', true, false),
        rata(4, '2026-12-10', false, true),
    ],
};
const RATE_PURE = [0, 1, 2, 3, 4].map((n) => ({ id: 100 + n, numero_rata: n, is_emessa: n >= 1 && n <= 3, is_published: true }));

let pagina: VueWrapper | null = null;

async function monta(altriStub: Record<string, unknown> = {}) {
    pagina = mount(PianiRateShow, {
        props: {
            condominio: { id: 7, nome: 'Condominio Demo' } as never,
            esercizio: { id: 1, nome: '2026', stato: 'aperto' } as never,
            pianoRate: { id: 5, nome: 'Preventivo 2026', stato: 'approvato', tipo: 'ordinario', numero_rate: 4, totale_capitoli: 40000, budget_movements: [], capitoli: [] },
            quotePerAnagrafica: [ROSSI], quotePerImmobile: [], ratePure: RATE_PURE, needsMigration: false, sources: [], destinations: [], copertura: null, fermo_del_piano: null,
        },
        global: {
            stubs: {
                GestionaleLayout: { template: '<div><slot /></div>' }, VueDatePicker: true, PianoRateSpaccatoSheet: true, ModalSpostaSpesa: true, BudgetHistoryPopover: true,
                DestinatariWarning: true, DestinatariWarningDefunto: true, ScopertoWarning: true, ...altriStub,
            },
            mocks: { route: (n: string, p?: Record<string, unknown>) => `/${n}?${JSON.stringify(p ?? {})}` },
        },
    });
    await flushPromises();

    return pagina;
}

const pulito = (testo: string) => testo.replace(/\s+/g, ' ').trim();

afterEach(() => {
    pagina?.unmount();
    pagina = null;
    vi.useRealTimers();
});

describe('lo scaduto della pagina del piano viene dal server', () => {
    test('la card dice che cosa conta e vale € 100,00', async () => {
        await monta();
        // La card: il blocco più interno che ha il titolo e la cifra.
        const card = pagina!.findAll('div').filter((d) => d.text().startsWith('Scaduto da incassare') && d.text().includes('€')).at(-1);

        expect(card, 'la card «Scaduto da incassare»').toBeTruthy();
        expect(pulito(card!.text())).toContain('100,00');
        expect(pulito(card!.text())).not.toContain('300,00');
    });

    test('il tooltip della card dice quando una rata è scaduta e quali crediti toglie: quelli dentro la rata, non quelli di altre rate', async () => {
        const conLeSueParole = { template: '<div><slot /></div>' };
        await monta({ TooltipProvider: conLeSueParole, Tooltip: conLeSueParole, TooltipTrigger: conLeSueParole, TooltipContent: { template: '<div data-tooltip><slot /></div>' } });
        const testi = pagina!.findAll('[data-tooltip]').map((t) => pulito(t.text()));
        const card = testi.find((t) => t.includes('Le rate emesse con la scadenza passata'));

        expect(card, 'il tooltip della card').toBeTruthy();
        expect(card).toContain('Una rata è scaduta dal giorno dopo la sua scadenza.');
        expect(card).toContain('Non sottrae i crediti di altre rate; dentro una rata, sì.');
        expect(card).not.toContain('Il giorno della scadenza non conta');
    });

    test('la colonna della riga di Rossi vale € 100,00, e solo la cella della rata 1 dice «Scaduta»', async () => {
        await monta();
        const riga = pagina!.findAll('tbody tr').find((tr) => tr.text().includes('Rossi Mario'));
        // La cella della colonna dello scaduto è l'ultima con lo sfondo rosso tenue della colonna.
        const cella = riga!.findAll('td').filter((td) => td.classes().includes('bg-red-50/10')).at(-1);

        expect((pagina!.vm as any).currentData[0].scaduteRiga).toBe(10000);
        expect(pulito(cella!.text())).toBe('€ 100,00');
        expect((pagina!.vm as any).getRataStyle(ROSSI.rate[1]).label).toBe('Scaduta');
        expect((pagina!.vm as any).getRataStyle(ROSSI.rate[2]).label).toBe('In attesa');
        expect((pagina!.vm as any).getRataStyle(ROSSI.rate[4]).label).toBe('In attesa');
    });
});

describe('la data di emissione proposta', () => {
    test('alle 00:30 del 01/01/2027 a Roma propone il 01/01/2027, non il 31/12/2026', async () => {
        vi.useFakeTimers({ toFake: ['Date'] });
        vi.setSystemTime(new Date('2026-12-31T23:30:00Z'));
        await monta();

        expect((pagina!.vm as any).formEmissione.data_emissione).toBe('2027-01-01');
    });
});
