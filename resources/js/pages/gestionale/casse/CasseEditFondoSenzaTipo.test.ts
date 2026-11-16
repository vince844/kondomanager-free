// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
// @vitest-environment jsdom

/**
 * La pagina di un fondo senza tipo: la destinazione d'uso resta vuota e dice «Tipo non indicato: conta come vincolato», con le parole del server, come il resto
 * del programma; mai «generico» da solo. Il fondo è «bloccato» e la riga sotto dice che per salvare il tipo va scelto. Un fondo con il tipo è come prima.
 * Nella pagina niente scritte sotto i 12 pixel.
 */
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { afterEach, describe, expect, test, vi } from 'vitest';
import { flushPromises, mount, type VueWrapper } from '@vue/test-utils';

vi.mock('@inertiajs/vue3', async (importOriginal) => ({
    ...(await importOriginal<typeof import('@inertiajs/vue3')>()),
    Head: { template: '<span />' },
    Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
    usePage: () => ({ props: { flash: {}, auth: { user: { roles: [], permissions: ['Accesso pannello amministratore'] } } } }),
}));
(globalThis as unknown as { route: unknown }).route = (nome: string) => `/${nome}`;
(globalThis as unknown as { ResizeObserver: unknown }).ResizeObserver = class { observe() {} unobserve() {} disconnect() {} };

const CasseEdit = (await import('./CasseEdit.vue')).default;

const passa = { template: '<div><slot /></div>' };
let w: VueWrapper | null = null;
afterEach(() => { w?.unmount(); w = null; document.body.innerHTML = ''; });

const fondo = (altro: Record<string, unknown>) => ({ data: { id: 6, nome: 'Fondo di riserva', tipo: 'fondo', descrizione: null, note: null, attiva: true, has_movements: true,
    saldo_iniziale_bloccato: true, saldo_iniziale: '600,00', vincolo_descrizione: null, is_override_assemblea: false, motivazione_override: null, conto_corrente: null, ...altro } });

async function monta(cassa: Record<string, unknown>) {
    w = mount(CasseEdit, {
        attachTo: document.body,
        props: { condominio: { id: 7, nome: 'Via Verdi 12' }, condomini: [], cassa } as never,
        global: {
            mocks: { route: (globalThis as unknown as { route: unknown }).route },
            stubs: { GestionaleLayout: passa, StrutturaLayout: passa, PageHeaderGuide: true, CondominioDropdown: true },
        },
    });
    await flushPromises();
}
const testo = () => (document.body.textContent ?? '').replace(/\s+/g, ' ');

describe('la pagina di un fondo senza tipo', () => {
    test('la destinazione d\'uso è vuota e dice «Tipo non indicato: conta come vincolato»; il fondo è bloccato; per salvare il tipo va scelto', async () => {
        await monta(fondo({ sottotipo_fondo: null, tipo_non_indicato: 'tipo non indicato: conta come vincolato' }));
        const campo = document.body.querySelector('#sottotipo_fondo input') as HTMLInputElement | null;
        expect(campo?.getAttribute('placeholder')).toBe('Tipo non indicato: conta come vincolato');
        expect(document.body.querySelector('#sottotipo_fondo .vs__selected')).toBeNull();
        expect(testo()).toContain('Fondo bloccato');
        expect(testo()).not.toContain('Utilizzabile per imprevisti');
        expect((document.body.querySelector('[data-testid="tipo-non-indicato"]')?.textContent ?? '').replace(/\s+/g, ' ').trim())
            .toBe('Tipo non indicato: conta come vincolato, come nello Stato patrimoniale e nella tesoreria. Per salvare scegli la destinazione d\'uso: «Fondo cassa '
                + 'generico» lo rende libero da usare, gli altri tipi restano vincolati.');
    });

    test('un fondo con il tipo è come prima: il tipo scelto, nessuna riga', async () => {
        await monta(fondo({ sottotipo_fondo: 'vincolato_lavori', tipo_non_indicato: null, vincolo_descrizione: 'facciata' }));
        expect(document.body.querySelector('#sottotipo_fondo .vs__selected')?.textContent?.trim()).toBe('Fondo straordinario vincolato (es. Lavori)');
        expect(document.body.querySelector('[data-testid="tipo-non-indicato"]')).toBeNull();
    });

    test('nella pagina niente scritte sotto i 12 pixel', () => {
        const sorgente = readFileSync(resolve(process.cwd(), 'resources/js/pages/gestionale/casse/CasseEdit.vue'), 'utf8');
        expect(sorgente).not.toMatch(/text-\[(8|9|10|11)px\]/);
    });
});
