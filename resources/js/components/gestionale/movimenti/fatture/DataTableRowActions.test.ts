// @vitest-environment jsdom

/**
 * «Registra pagamento» restava offerto su una nota di credito nata da uno storno, e adesso
 * porta a un vicolo cieco muto (Coda 124, revisione avversariale del 05/09/2026).
 *
 * `is_stornata` sta sull'ORIGINALE, non sulla nota che lo storno genera: quella resta
 * `stato_pagamento='aperta'` e passava tutti i controlli di `isPagabile`. Da quando la nota
 * non è più compensabile automaticamente (Coda 124), il form a cui questa voce porta non la
 * trova più fra le pendenze — e non dice niente, resta solo vuoto.
 */

import { describe, expect, test, vi } from 'vitest';
import { mount } from '@vue/test-utils';

vi.mock('@inertiajs/vue3', async (importOriginal) => ({
    ...(await importOriginal<typeof import('@inertiajs/vue3')>()),
    router: { visit: vi.fn() },
    usePage: () => ({
        props: { auth: { user: { roles: ['amministratore'], permissions: [] } } },
    }),
}));

import DataTableRowActions from './DataTableRowActions.vue';

(globalThis as any).route = (n: string) => `/${n}`;

const FATTURA_BASE = {
    id: 1,
    numero_documento: 'FT-2026-0001',
    stato_pagamento: 'aperta',
    stato_approvazione: 'approvata',
    is_pregresso: false,
    dati_extra: {},
};

function renderRowActions(fattura: Record<string, unknown>) {
    return mount(DataTableRowActions, {
        props: { fattura, condominioId: 18 },
        global: {
            stubs: {
                DropdownMenu: { template: '<div><slot /></div>' },
                DropdownMenuContent: { template: '<div><slot /></div>' },
                DropdownMenuTrigger: { template: '<div><slot /></div>' },
                DropdownMenuLabel: { template: '<div><slot /></div>' },
                DropdownMenuItem: { template: '<div><slot /></div>' },
                DropdownMenuSeparator: true,
                ConfirmDialog: true,
                Button: { template: '<button><slot /></button>' },
            },
            mocks: { route: (n: string) => `/${n}` },
        },
    });
}

describe('DataTableRowActions — la nota da storno non è più pagabile', () => {
    test('una fattura ordinaria e aperta resta pagabile — il controesempio', () => {
        const wrapper = renderRowActions(FATTURA_BASE);

        expect((wrapper.vm as any).isPagabile).toBe(true);
    });

    test('una nota di credito nata da uno storno non è pagabile, anche se sembra aperta', () => {
        const wrapper = renderRowActions({
            ...FATTURA_BASE,
            netto_a_pagare: -12200,
            dati_extra: { nota_storno: 'Storno automatico a compensazione della fattura ID: 1' },
        });

        expect((wrapper.vm as any).isPagabile).toBe(false);
    });

    test('la voce "Registra pagamento" non compare nel menu della nota da storno', () => {
        const wrapper = renderRowActions({
            ...FATTURA_BASE,
            netto_a_pagare: -12200,
            dati_extra: { nota_storno: 'Storno automatico a compensazione della fattura ID: 1' },
        });

        expect(wrapper.text()).not.toContain('Registra pagamento');
    });

    test('una nota di credito GENUINA (non da storno) resta pagabile come sempre', () => {
        // Il controesempio che tiene stretta la correzione: se il criterio fosse troppo
        // largo (es. "qualunque nota di credito"), il netting vero smetterebbe di offrirsi.
        const wrapper = renderRowActions({
            ...FATTURA_BASE,
            netto_a_pagare: -12200,
            dati_extra: {},
        });

        expect((wrapper.vm as any).isPagabile).toBe(true);
    });
});

describe('Coda 133 — anche le note da storno GIÀ a database', () => {
    // ⚠️ Le note generate prima della Coda 124 non hanno `dati_extra.nota_storno`: il server le
    // riconosce dal legame inverso e lo dichiara con `e_nata_da_storno`. Senza questo test il
    // componente poteva tornare a leggere la sola chiave senza che nulla diventasse rosso.
    test('una nota storica, senza chiave ma col flag del server, non è pagabile', () => {
        const wrapper = renderRowActions({
            tipo_documento: 'nota_credito',
            stato_pagamento: 'aperta',
            stato_approvazione: 'approvata',
            dati_extra: {},                 // nessuna chiave `nota_storno`
            e_nata_da_storno: true,         // ...ma il server l'ha riconosciuta
        });

        expect(wrapper.text()).not.toContain('Registra pagamento');
    });
});
