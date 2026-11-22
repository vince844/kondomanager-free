// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
// @vitest-environment jsdom

/**
 * L'uscita dal programma cancella il lavoro della sala tenuto nel browser (`lib/senzaRete/uscita.ts`):
 *
 * - **«Esci»** nel menu dell'utente: senza gesti non arrivati cancella tutto ed esce; con dei gesti della sala non arrivati prima lo chiede, e se si resta non
 *   esce e non cancella niente;
 * - **la pagina di accesso** (anche quella che si apre con la sessione scaduta) cancella le copie e lo stato della sala, e lascia i gesti in coda a chi li ha
 *   segnati.
 */
import { afterEach, describe, expect, test, vi } from 'vitest';
import { flushPromises, mount, shallowMount } from '@vue/test-utils';
import { reactive } from 'vue';

const inertia = vi.hoisted(() => ({ post: vi.fn(), flushAll: vi.fn() }));
const uscita = vi.hoisted(() => ({ conta: vi.fn(async () => 0), cancella: vi.fn(async () => undefined) }));
vi.mock('@inertiajs/vue3', () => ({
    router: inertia,
    Link: { template: '<a><slot /></a>' },
    Head: { template: '<div />' },
    useForm: (dati: Record<string, unknown>) => reactive({ ...dati, errors: {}, processing: false, post: vi.fn(), reset: vi.fn() }),
    usePage: () => ({ props: { auth: { user: null } }, url: '/login' }),
}));
vi.mock('laravel-vue-i18n', () => ({ trans: (chiave: string) => chiave }));
vi.mock('@/lib/senzaRete/uscita', () => ({ contaIlLavoroNonArrivato: uscita.conta, cancellaIlLavoroSenzaRete: uscita.cancella }));
(globalThis as Record<string, unknown>).route = (nome: string) => `/${nome}`;

const passa = { template: '<div><slot /></div>' };
const stubs = { DropdownMenuLabel: passa, DropdownMenuSeparator: passa, DropdownMenuGroup: passa, DropdownMenuItem: passa, UserInfo: passa };

async function esci() {
    const Menu = (await import('./UserMenuContent.vue')).default;
    const w = mount(Menu, { props: { user: { id: 5, name: 'Andrea Costa', email: 'a@example.com' } as never }, global: { stubs, mocks: { route: (n: string) => `/${n}` } } });
    const pulsante = w.findAll('button').at(-1)!;
    await pulsante.trigger('click');
    await flushPromises();

    return w;
}

afterEach(() => {
    inertia.post.mockClear();
    uscita.conta.mockReset();
    uscita.conta.mockImplementation(async () => 0);
    uscita.cancella.mockClear();
    vi.unstubAllGlobals();
});

describe('l\'uscita dal programma', () => {
    test('«Esci» senza gesti non arrivati: cancella il lavoro della sala, anche la coda, ed esce', async () => {
        await esci();
        expect(uscita.cancella).toHaveBeenCalledWith({ ancheLaCoda: true });
        expect(inertia.post).toHaveBeenCalledWith('/logout', {}, expect.anything());
    });

    test('«Esci» con dei gesti della sala non arrivati prima lo chiede: se si resta, niente si cancella e non si esce', async () => {
        uscita.conta.mockImplementation(async () => 3);
        const chiedi = vi.fn(() => false);
        vi.stubGlobal('confirm', chiedi);
        await esci();
        expect(chiedi.mock.calls[0][0]).toContain('3 gesti della sala non sono ancora arrivati al server');
        expect(uscita.cancella).not.toHaveBeenCalled();
        expect(inertia.post).not.toHaveBeenCalled();

        chiedi.mockReturnValue(true);
        await esci();
        expect(uscita.cancella).toHaveBeenCalledWith({ ancheLaCoda: true });
        expect(inertia.post).toHaveBeenCalled();
    });

    test('la pagina di accesso cancella le copie e lo stato della sala, e lascia i gesti in coda a chi li ha segnati', async () => {
        const Login = (await import('../pages/auth/Login.vue')).default;
        shallowMount(Login, { props: { canResetPassword: true }, global: { renderStubDefaultSlot: true, mocks: { route: (n: string) => `/${n}`, $page: { props: {} } } } });
        await flushPromises();
        expect(uscita.cancella).toHaveBeenCalledWith({ ancheLaCoda: false });
    });
});
