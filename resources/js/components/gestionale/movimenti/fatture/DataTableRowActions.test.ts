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

describe('DataTableRowActions — lo storno e i piani rate (1.11.0-beta.35, R1 della Fase 1-bis)', () => {
    const MOTIVO = 'La fattura è nel piano rate «Facciata 2026», in bozza: dopo lo storno le sue rate la chiederebbero ancora. Elimina prima il piano, poi storna la fattura; le altre fatture del piano, se ce ne sono, tornano disponibili per un piano nuovo.';

    test('con il motivo del server lo storno non si offre: la voce dice «non consentito», è cliccabile e apre la finestra con il perché del server', async () => {
        const wrapper = mount(DataTableRowActions, {
            props: { fattura: { ...FATTURA_BASE, netto_a_pagare: 100000, motivo_blocco_storno: MOTIVO }, condominioId: 18 },
            global: {
                stubs: {
                    DropdownMenu: { template: '<div><slot /></div>' }, DropdownMenuContent: { template: '<div><slot /></div>' },
                    DropdownMenuTrigger: { template: '<div><slot /></div>' }, DropdownMenuLabel: { template: '<div><slot /></div>' },
                    // La voce stub inoltra `disabled` e il clic: una voce rimessa `disabled` farebbe fallire il test.
                    DropdownMenuItem: { props: { disabled: Boolean }, emits: ['click'], template: '<div role="menuitem" :data-disabled="disabled ? \'\' : undefined" @click="!disabled && $emit(\'click\')"><slot /></div>' },
                    DropdownMenuSeparator: true, ConfirmDialog: true, teleport: true,
                    Button: { template: '<button><slot /></button>' },
                },
                mocks: { route: (n: string) => `/${n}` },
            },
        });

        expect((wrapper.vm as any).puoStornare).toBe(false);
        const voce = wrapper.findAll('[role="menuitem"]').find(v => v.text().includes('Storna — non consentito'))!;
        expect(voce.attributes('data-disabled')).toBeUndefined();

        await voce.trigger('click');

        // Prima il motivo stava in un `title` su una voce disattivata, con `pointer-events: none`: non si leggeva mai.
        expect(wrapper.text()).toContain('Storno non consentito');
        expect(wrapper.text()).toContain(MOTIVO);
    });

    test('senza motivo lo storno resta offerto — il controesempio', () => {
        const wrapper = renderRowActions({ ...FATTURA_BASE, netto_a_pagare: 100000, motivo_blocco_storno: null });

        expect((wrapper.vm as any).puoStornare).toBe(true);
        expect(wrapper.text()).not.toContain('non consentito');
    });

    test('con un piano che ha già incassato la conferma dello storno avvisa che le rate restano', () => {
        const AVVISO = 'La fattura è nel piano rate «Facciata 2026», che ha già incassato rate. Lo storno annulla la fattura in contabilità, ma le rate del piano restano e continuano a chiederla.';
        const wrapper = mount(DataTableRowActions, {
            props: { fattura: { ...FATTURA_BASE, netto_a_pagare: 100000, avviso_storno: AVVISO }, condominioId: 18 },
            global: {
                stubs: {
                    DropdownMenu: { template: '<div><slot /></div>' }, DropdownMenuContent: { template: '<div><slot /></div>' },
                    DropdownMenuTrigger: { template: '<div><slot /></div>' }, DropdownMenuLabel: { template: '<div><slot /></div>' },
                    DropdownMenuItem: { template: '<div><slot /></div>' }, DropdownMenuSeparator: true,
                    // Le finestre stanno in un <Teleport to="body">: senza questo stub il loro contenuto non è nel wrapper.
                    teleport: true,
                    ConfirmDialog: { props: ['title'], template: '<section :data-titolo="title"><slot /></section>' },
                    Button: { template: '<button><slot /></button>' },
                },
                mocks: { route: (n: string) => `/${n}` },
            },
        });

        expect(wrapper.find('section[data-titolo="Storno contabile"]').text()).toContain(AVVISO);
    });
});


describe('DataTableRowActions — le voci «non consentito» si leggono al clic (Coda 162, 1.11.0-beta.35)', () => {
    // La voce vera ha `pointer-events: none` quando è `disabled`: il motivo in un `title` non si leggeva mai. Lo stub
    // inoltra `disabled` come Boolean e blocca il clic, come la voce vera: rimetterla `disabled` fa fallire il test.
    const monta = (fattura: Record<string, unknown>) => mount(DataTableRowActions, {
        props: { fattura: { ...FATTURA_BASE, ...fattura }, condominioId: 18 },
        global: {
            stubs: {
                DropdownMenu: { template: '<div><slot /></div>' }, DropdownMenuContent: { template: '<div><slot /></div>' },
                DropdownMenuTrigger: { template: '<div><slot /></div>' }, DropdownMenuLabel: { template: '<div><slot /></div>' },
                DropdownMenuItem: { props: { disabled: Boolean }, emits: ['click'], template: '<div role="menuitem" :data-disabled="disabled ? \'\' : undefined" @click="!disabled && $emit(\'click\')"><slot /></div>' },
                DropdownMenuSeparator: true, ConfirmDialog: true, teleport: true,
                Button: { template: '<button><slot /></button>' },
            },
            mocks: { route: (n: string) => `/${n}` },
        },
    });
    const voce = (wrapper: ReturnType<typeof monta>, testo: string) => wrapper.findAll('[role="menuitem"]').find(v => v.text().includes(testo))!;

    test('«Elimina — non consentito» apre la finestra con il motivo del server', async () => {
        const MOTIVO = 'La fattura è nel piano rate «Tetto», che è approvato (art. 1135 c.c.). Riporta il piano in bozza per poterla eliminare.';
        const wrapper = monta({ netto_a_pagare: 100000, motivo_blocco_eliminazione: MOTIVO });

        await voce(wrapper, 'Elimina — non consentito').trigger('click');

        expect(wrapper.text()).toContain('Eliminazione non consentita');
        expect(wrapper.text()).toContain(MOTIVO);
    });

    test('«Storna — prima i pagamenti» apre la finestra con la via', async () => {
        const wrapper = monta({ netto_a_pagare: 100000, stato_pagamento: 'pagata' });

        await voce(wrapper, 'Storna — prima i pagamenti').trigger('click');

        expect(wrapper.text()).toContain('Storno non consentito');
        expect(wrapper.text()).toContain('storna prima il pagamento dalla sezione Pagamenti fornitori');
    });
});
