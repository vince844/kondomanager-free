// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
// @vitest-environment jsdom

/**
 * «Manda le PEC» e la casella PEC dello studio (decisione 572, proposta 11).
 *
 * - Senza la casella il pulsante è spento e dice «Prima imposta la casella PEC dello studio»; a chi può aprire le impostazioni la frase porta alla pagina
 *   delle caselle, con la PEC aperta.
 * - Con l'ultima prova della casella non andata il pulsante resta acceso: al clic la finestra dice quando e perché, e le PEC partono solo con la conferma.
 * - Senza avviso il clic manda subito, come prima.
 *
 * Che cosa non copre: le frasi, che arrivano dal server (`CasellaPecNellaConvocazioneTest.php`).
 */
import { afterEach, beforeEach, describe, expect, test, vi } from 'vitest';
import { flushPromises, mount, type VueWrapper } from '@vue/test-utils';

const inertia = vi.hoisted(() => ({ patch: vi.fn(), post: vi.fn(), reload: vi.fn() }));
vi.mock('@inertiajs/vue3', () => ({ router: inertia, Link: { props: ['href'], template: '<a :href="href"><slot /></a>' } }));
vi.mock('axios', () => ({ default: { post: vi.fn() } }));
(globalThis as unknown as { ResizeObserver: unknown }).ResizeObserver = class { observe() {} unobserve() {} disconnect() {} };

const { schedaDellaBozza } = await import('./viaRoma12');
const Scheda = (await import('./SchedaConvocazione.vue')).default;

const AVVISO = 'L\'ultima prova della casella PEC dello studio, il 19/11 alle 10:02, non è andata: il gestore non ha accettato la password. Se mandi lo stesso, '
    + 'le PEC che il gestore non accetta restano da mandare, con il motivo.';

let w: VueWrapper | null = null;
beforeEach(() => inertia.post.mockClear());
afterEach(() => { w?.unmount(); w = null; document.body.innerHTML = ''; });

async function monta(pec: Record<string, unknown>) {
    w = mount(Scheda, { attachTo: document.body, props: { convocazione: schedaDellaBozza({ pec: { quante: 11, ...pec } } as never) as never } });
    await flushPromises();

    return w;
}
const pulsante = (etichetta: string) => [...document.body.querySelectorAll('button')].find((b) => (b.textContent ?? '').replace(/\s+/g, ' ').trim() === etichetta);

describe('«Manda le PEC» senza la casella', () => {
    test('la frase porta alla pagina delle caselle con la PEC aperta', async () => {
        await monta({ attivo: false, perche: 'Prima imposta la casella PEC dello studio.', dove: '/impostazioni/mail?apri=pec', avviso: null });
        const link = w!.find('[data-testid="perche-pec"] a');
        expect(link.exists()).toBe(true);
        expect(link.attributes('href')).toBe('/impostazioni/mail?apri=pec');
        expect(link.text()).toBe('Prima imposta la casella PEC dello studio.');
        expect(pulsante('Manda le PEC (11)')!.hasAttribute('disabled')).toBe(true);
    });
});

describe('«Manda le PEC» con l\'ultima prova non andata', () => {
    test('il pulsante resta; al clic la finestra lo dice, e le PEC partono solo con la conferma', async () => {
        await monta({ attivo: true, perche: null, dove: null, avviso: AVVISO });
        pulsante('Manda le PEC (11)')!.click();
        await flushPromises();
        expect(inertia.post).not.toHaveBeenCalled();
        expect((document.body.textContent ?? '').replace(/\s+/g, ' ')).toContain(AVVISO);
        expect(document.body.textContent).toContain('L\'ultima prova della casella PEC non è andata');

        const conferma = [...document.body.querySelectorAll('[role="alertdialog"] button')].find((b) => (b.textContent ?? '').trim() === 'Manda le PEC (11)')!;
        conferma.click();
        await flushPromises();
        expect(inertia.post).toHaveBeenCalledTimes(1);
        expect(inertia.post.mock.calls[0][0]).toBe('/a/1/convocazione/pec');
    });

    test('con «Annulla» non parte niente', async () => {
        await monta({ attivo: true, perche: null, dove: null, avviso: AVVISO });
        pulsante('Manda le PEC (11)')!.click();
        await flushPromises();
        [...document.body.querySelectorAll('[role="alertdialog"] button')].find((b) => (b.textContent ?? '').trim() === 'Annulla')!.click();
        await flushPromises();
        expect(inertia.post).not.toHaveBeenCalled();
    });

    test('senza avviso il clic manda subito, come prima', async () => {
        await monta({ attivo: true, perche: null, dove: null, avviso: null });
        pulsante('Manda le PEC (11)')!.click();
        await flushPromises();
        expect(inertia.post).toHaveBeenCalledTimes(1);
        expect(document.body.querySelector('[role="alertdialog"]')).toBeNull();
    });
});
