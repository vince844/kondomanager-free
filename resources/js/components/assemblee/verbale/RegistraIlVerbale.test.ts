// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
// @vitest-environment jsdom

/**
 * «Registra il verbale sottoscritto»: si registra il testo firmato, e il registro va solo avanti.
 *
 * - Senza la bozza stampata la finestra chiede la conferma «Registro il testo di adesso», e la registrazione la porta (`senza_la_bozza`).
 * - Il testo cambiato dopo la stampa: la finestra lo dice in rosso, con la frase del server.
 * - Un numero più alto del proposto: «Salti i numeri 42–44?», e senza «Confermo il salto» non si registra; con la conferma la registrazione porta `salto`.
 */
import { afterEach, describe, expect, test, vi } from 'vitest';
import { flushPromises, mount, type VueWrapper } from '@vue/test-utils';
import { verbale } from './verbaleDiProva';
import RegistraIlVerbale from './RegistraIlVerbale.vue';
import type { VerbaleDellaPagina } from '@/types/verbale';

const inertia = vi.hoisted(() => ({ post: vi.fn() }));
vi.mock('@inertiajs/vue3', () => ({ router: inertia }));
(globalThis as unknown as { ResizeObserver: unknown }).ResizeObserver = class { observe() {} unobserve() {} disconnect() {} };

let w: VueWrapper | null = null;
afterEach(() => { w?.unmount(); w = null; document.body.innerHTML = ''; inertia.post.mockReset(); });

async function apri(v: VerbaleDellaPagina) {
    w = mount(RegistraIlVerbale, { attachTo: document.body, props: { open: true, verbale: v, rotta: '/registra', giornoDellaSeduta: '2026-12-15' } });
    await flushPromises();
    w.findComponent({ name: 'CampoFile' }).vm.$emit('update:modelValue', new File(['%PDF'], 'verbale_firmato.pdf', { type: 'application/pdf' }));
    await flushPromises();
    return w;
}
const pulsante = () => document.body.querySelector('[data-testid="conferma-registra"]') as HTMLButtonElement;
const clic = async (testid: string) => { (document.body.querySelector(`[data-testid="${testid}"]`) as HTMLElement).click(); await flushPromises(); };

describe('«Registra il verbale sottoscritto»', () => {
    const conRegistro = (piu: Partial<VerbaleDellaPagina> = {}) => verbale({ registro: { chiave: 'condominio', nome: 'Via Roma 12', scala_id: null, palazzina_id: null,
        partenza: { scelta: 'libro', ultimo: 41, del: '2026-10-22' }, numero_proposto: 42 } as VerbaleDellaPagina['registro'], ...piu });

    test('senza la bozza stampata chiede la conferma, e la registrazione la porta', async () => {
        await apri(conRegistro({ stampata: null }));
        expect(document.body.textContent).toContain('La bozza da firmare non è stata stampata');
        expect(pulsante().disabled).toBe(true);
        await clic('conferma-senza-bozza');
        expect(pulsante().disabled).toBe(false);
        pulsante().click();
        expect(inertia.post).toHaveBeenCalledWith('/registra', expect.objectContaining({ senza_la_bozza: 1 }), expect.anything());
    });

    test('il testo cambiato dopo la stampa: la finestra lo dice', async () => {
        await apri(conRegistro({ stampata: { versione: 3, impronta_testo: 'a', impronta: 'b', il: '2026-12-15T20:02:00Z', da: 1, da_nome: 'Andrea Costa',
            cambiata: 'Il testo è cambiato dopo la bozza stampata alle 21:02 (versione 3): ristampala e falla firmare, o ripristina la versione 3.' } }));
        expect(document.body.querySelector('[data-testid="testo-cambiato"]')?.textContent).toContain('Il testo è cambiato dopo la bozza stampata alle 21:02');
        expect(document.body.querySelector('[data-testid="senza-la-bozza"]')).toBeNull();
    });

    test('un numero più alto del proposto chiede la conferma del salto', async () => {
        await apri(conRegistro({ stampata: { versione: 3, impronta_testo: 'a', impronta: 'b', il: '2026-12-15T20:02:00Z', da: 1, da_nome: 'Andrea Costa' } }));
        const campo = document.body.querySelector('[data-testid="numero-nel-registro"]') as HTMLInputElement;
        campo.value = '45';
        campo.dispatchEvent(new Event('input'));
        await flushPromises();
        expect(document.body.querySelector('[data-testid="salto-del-numero"]')?.textContent).toContain('Salti i numeri 42–44?');
        expect(pulsante().disabled).toBe(true);
        await clic('conferma-salto');
        pulsante().click();
        expect(inertia.post).toHaveBeenCalledWith('/registra', expect.objectContaining({ numero: 45, salto: 1 }), expect.anything());
    });
});
