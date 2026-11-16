// @vitest-environment jsdom

/**
 * La barra dell'elenco degli incassi: i filtri a sinistra, i tre pulsanti a destra («Ricevute», «Altro incasso», «Registra incasso»).
 *
 * - Il cartellino accanto a «Ricevute» dice solo quante ricevute sono da emettere; il giorno da cui le conta sta nel suggerimento, che si apre
 *   passando sopra il pulsante o arrivandoci con il tasto Tab. Senza ricevute da emettere non c'è né il cartellino né il suggerimento.
 * - I pulsanti stanno in un gruppo che non va a capo e non si stringe; a stringersi e ad andare a capo sono i filtri.
 *
 * Che cosa NON copre: il conteggio (lo fa il server, `ElencoRicevute::senzaRicevuta()`, nei test PHP della pagina delle ricevute) e l'aspetto
 * vero alle varie larghezze, che si guarda nel browser.
 */

import { afterEach, describe, expect, test, vi } from 'vitest';
import { flushPromises, mount, type VueWrapper } from '@vue/test-utils';

const condivise = vi.hoisted(() => ({ props: {} as Record<string, unknown> }));
vi.mock('@inertiajs/vue3', () => ({
    usePage: () => condivise,
    Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
}));
vi.mock('@/composables/permissions', () => ({ usePermission: () => ({ generateRoute: (nome: string) => nome }) }));
vi.mock('@/composables/useTabellaServer', () => ({ useTabellaServer: () => ({ filtra: vi.fn() }) }));
// Il suggerimento vero si posiziona con un `ResizeObserver`, che jsdom non ha.
(globalThis as unknown as { ResizeObserver: unknown }).ResizeObserver = class { observe() {} unobserve() {} disconnect() {} };
const route = (nome: string) => `/${nome}`;
(globalThis as unknown as { route: unknown }).route = route;

const Barra = (await import('./DataTableToolbar.vue')).default;

// Il suggerimento si apre al passaggio del mouse: qui le sue parti rendono il contenuto, così il testo si legge senza aprirlo.
const passa = { template: '<div><slot /></div>' };
const stubs = {
    TooltipProvider: passa, Tooltip: passa, TooltipTrigger: passa,
    TooltipContent: { template: '<div data-suggerimento><slot /></div>' },
    Select: true, SelectTrigger: true, SelectValue: true, SelectContent: true, SelectItem: true,
};

let w: VueWrapper | null = null;
function monta(mancanti: { numero: number; dal: string } | undefined) {
    condivise.props = { condominio: { id: 3 }, stati: ['registrata', 'annullata'], filters: {}, ricevute_mancanti: mancanti };
    w = mount(Barra, { props: { table: {} as never }, global: { stubs, mocks: { route } } });

    return w;
}

afterEach(() => {
    w?.unmount();
    w = null;
    document.body.innerHTML = '';
});

describe('il cartellino delle ricevute da emettere', () => {
    test('dice «N da emettere»; il giorno da cui conta sta nel suggerimento, non nel pulsante', () => {
        const b = monta({ numero: 1, dal: '2026-10-01' });
        const cartellino = b.get('[data-testid="ricevute-da-emettere"]');
        expect(cartellino.text()).toBe('1 da emettere');
        expect(cartellino.classes()).toContain('whitespace-nowrap');

        const pulsante = b.get('[data-azione="ricevute"]');
        expect(pulsante.text().replace(/\s+/g, ' ')).toBe('Ricevute 1 da emettere');
        expect(pulsante.text()).not.toContain('01/10/2026');
        expect(b.text()).not.toContain('senza ricevuta');

        expect(b.get('[data-suggerimento]').text()).toBe('Ricevute mai emesse dei versamenti dal 01/10/2026 a oggi. La pagina «Ricevute» si apre su queste.');
    });

    test('con dodici il cartellino dice «12 da emettere»', () => {
        expect(monta({ numero: 12, dal: '2026-09-01' }).get('[data-testid="ricevute-da-emettere"]').text()).toBe('12 da emettere');
        expect(w!.get('[data-suggerimento]').text()).toContain('dal 01/09/2026 a oggi');
    });

    test('senza ricevute da emettere non c\'è il cartellino, e nemmeno il suggerimento', () => {
        for (const mancanti of [{ numero: 0, dal: '2026-10-01' }, undefined]) {
            const b = monta(mancanti);
            expect(b.find('[data-testid="ricevute-da-emettere"]').exists()).toBe(false);
            expect(b.find('[data-suggerimento]').exists()).toBe(false);
            expect(b.get('[data-azione="ricevute"]').text().trim()).toBe('Ricevute');
            w!.unmount();
            w = null;
        }
    });
});

describe('il suggerimento vero', () => {
    test('arrivando sul pulsante con il tasto Tab il suggerimento si apre e dice il giorno; senza ricevute da emettere no', async () => {
        const conMenu = { Select: true, SelectTrigger: true, SelectValue: true, SelectContent: true, SelectItem: true };
        condivise.props = { condominio: { id: 3 }, stati: [], filters: {}, ricevute_mancanti: { numero: 2, dal: '2026-10-01' } };
        w = mount(Barra, { attachTo: document.body, props: { table: {} as never }, global: { stubs: conMenu, mocks: { route } } });
        const pulsante = w.get('[data-azione="ricevute"]');
        expect(pulsante.element.tagName).toBe('A');
        await pulsante.trigger('focus');
        await flushPromises();
        expect(pulsante.attributes('data-state')).not.toBe('closed');
        expect(document.body.textContent).toContain('Ricevute mai emesse dei versamenti dal 01/10/2026 a oggi.');
        w.unmount();
        document.body.innerHTML = '';

        condivise.props = { ...condivise.props, ricevute_mancanti: { numero: 0, dal: '2026-10-01' } };
        w = mount(Barra, { attachTo: document.body, props: { table: {} as never }, global: { stubs: conMenu, mocks: { route } } });
        await w.get('[data-azione="ricevute"]').trigger('focus');
        await flushPromises();
        expect(document.body.textContent).not.toContain('mai emesse');
    });
});

describe('i pulsanti non vanno a capo', () => {
    test('i tre pulsanti stanno in un gruppo che non va a capo e non si stringe; i filtri sì', () => {
        const b = monta({ numero: 1, dal: '2026-10-01' });
        const azioni = b.get('[data-testid="azioni-incassi"]');
        expect(azioni.findAll('a').map((a) => a.text().replace(/\s+/g, ' ').trim())).toEqual(['Ricevute 1 da emettere', 'Altro incasso', 'Registra incasso']);
        // Sul telefono i tre non ci stanno in una riga: da 640 px in su sì, e lì non vanno a capo.
        expect(azioni.classes()).toEqual(expect.arrayContaining(['shrink-0', 'sm:flex-nowrap']));

        const filtri = b.get('[data-testid="filtri-incassi"]');
        expect(filtri.classes()).toEqual(expect.arrayContaining(['flex-wrap', 'min-w-0', 'lg:flex-1']));
        expect(filtri.find('[data-testid="azioni-incassi"]').exists()).toBe(false);

        // Dal portatile in su filtri e pulsanti sono sulla stessa riga, i pulsanti in alto a destra.
        const riga = b.get('[data-testid="barra-incassi"]');
        expect(riga.classes()).toEqual(expect.arrayContaining(['lg:flex-row', 'lg:justify-between', 'lg:items-start']));
        expect(riga.classes()).not.toContain('flex-wrap');
    });
});
