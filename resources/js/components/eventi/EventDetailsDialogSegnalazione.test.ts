// @vitest-environment jsdom

/**
 * La rata segnalata dal portale dopo la chiusura della segnalazione: se il versamento è andato, tutto o in parte, su altre rate, la finestra della rata lo
 * dice con le rate e le cifre (la frase del server, `meta.esito_segnalazione`); la rata tornata da versare ha di nuovo «Ho pagato», non «In attesa di
 * conferma…». Una rata ancora in verifica resta come prima.
 */
import { afterEach, describe, expect, test, vi } from 'vitest';
import { flushPromises, mount, type VueWrapper } from '@vue/test-utils';

vi.mock('@inertiajs/vue3', async (importOriginal) => ({
    ...(await importOriginal<typeof import('@inertiajs/vue3')>()),
    router: { post: vi.fn() },
}));
(globalThis as unknown as { route: unknown }).route = (name: string) => `/${name}`;
(globalThis as unknown as { ResizeObserver: unknown }).ResizeObserver = class { observe() {} unobserve() {} disconnect() {} };

const EventDetailsDialog = (await import('@/components/eventi/EventDetailsDialog.vue')).default;

const ESITO = 'Il tuo versamento del 10/03/2026, € 150,00, è andato su altre rate: rata 1 di «Preventivo 2026», € 100,00; rata 2 di «Preventivo 2026», € 50,00. '
    + 'Questa rata resta da versare: quando l\'hai pagata, puoi segnalarlo di nuovo.';

function rata(meta: Record<string, unknown>) {
    return {
        id: 3, title: 'Scadenza rata 3 - Preventivo 2026', start_time: '2026-03-05T00:00:00', visibility: 'private', description: 'La rata 3.',
        meta: { type: 'scadenza_rata_condomino', is_emitted: true, numero_rata: 3, gestione: 'Ordinaria', condominio_nome: 'Condominio di prova',
            dettaglio_quote: [{ descrizione: 'Int. A', importo: 10000, componente_spesa: 10000, componente_saldo: 0 }], importo_originale: 10000, ...meta },
    };
}

let finestra: VueWrapper | null = null;
afterEach(() => { finestra?.unmount(); finestra = null; document.body.innerHTML = ''; });
async function monta(e: unknown) {
    finestra = mount(EventDetailsDialog, { props: { isOpen: true, evento: e }, attachTo: document.body });
    await flushPromises();
    await new Promise((r) => setTimeout(r, 30));
    await flushPromises();
}
const testo = () => (document.body.textContent ?? '').replace(/\s+/g, ' ').trim();

describe('la rata dopo la chiusura della segnalazione', () => {
    test('tornata da versare: la frase di dove è andato il versamento, e di nuovo «Ho pagato»', async () => {
        await monta(rata({ status: 'pending', importo_pagato: 0, importo_restante: 10000, esito_segnalazione: ESITO }));
        expect(testo()).toContain(ESITO);
        expect(document.body.querySelector('[data-testid="esito-segnalazione"]')).not.toBeNull();
        expect(testo()).not.toContain('Pagamento in verifica');
        expect(testo()).toContain('Ho pagato');
    });

    test('in parte pagata: la frase c\'è lo stesso', async () => {
        const esito = 'Il tuo versamento del 10/03/2026, € 150,00, ha pagato € 50,00 di questa rata; il resto è andato su altre rate: rata 1 di «Preventivo 2026», € 100,00.';
        await monta(rata({ status: 'partial', importo_pagato: 5000, importo_versato: 5000, importo_restante: 5000, esito_segnalazione: esito }));
        expect(testo()).toContain(esito);
    });

    test('ancora in verifica: come prima, senza la frase', async () => {
        await monta(rata({ status: 'reported', importo_pagato: 0, importo_restante: 10000 }));
        expect(testo()).toContain('Pagamento in verifica');
        expect(document.body.querySelector('[data-testid="esito-segnalazione"]')).toBeNull();
    });
});
