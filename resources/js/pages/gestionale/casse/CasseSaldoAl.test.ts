// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
// @vitest-environment jsdom

/**
 * Il campo «Saldo al» del modulo della cassa (Coda 546): compilato con il primo giorno dell'esercizio aperto, con quel giorno come minimo del calendario.
 * Nella modifica c'è solo finché il saldo di apertura non è registrato in contabilità. Il campo non ha scritte sotto i 12 pixel.
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
const CasseNew = (await import('./CasseNew.vue')).default;

const passa = { template: '<div><slot /></div>' };
let w: VueWrapper | null = null;
afterEach(() => { w?.unmount(); w = null; document.body.innerHTML = ''; });

async function monta(componente: unknown, props: Record<string, unknown>) {
    w = mount(componente as never, {
        attachTo: document.body,
        props: { condominio: { id: 7, nome: 'Via Verdi 12' }, condomini: [], ...props } as never,
        global: {
            mocks: { route: (globalThis as unknown as { route: unknown }).route },
            stubs: { GestionaleLayout: passa, StrutturaLayout: passa, PageHeaderGuide: true, CondominioDropdown: true },
        },
    });
    await flushPromises();
}
/** Scrive il saldo di apertura nel campo dell'importo (che è «lazy»: il valore passa con il cambio). */
async function scriviSaldo(valore: string) {
    const input = document.body.querySelector('#saldo_iniziale') as HTMLInputElement;
    input.value = valore;
    input.dispatchEvent(new Event('input', { bubbles: true }));
    input.dispatchEvent(new Event('change', { bubbles: true }));
    input.dispatchEvent(new Event('blur', { bubbles: true }));
    await flushPromises();
}
const campo = () => document.body.querySelector('#saldo_al') as HTMLInputElement | null;
const cassa = (altro: Record<string, unknown>) => ({ data: { id: 6, nome: 'Cassa', tipo: 'contanti', descrizione: null, note: null, attiva: true, has_movements: false,
    saldo_iniziale_bloccato: false, saldo_iniziale: '', vincolo_descrizione: null, is_override_assemblea: false, motivazione_override: null, conto_corrente: null, ...altro } });

describe('«Saldo al» nel modulo della cassa', () => {
    test('nella cassa nuova è compilato con il primo giorno dell\'esercizio aperto, che è anche il minimo del calendario', async () => {
        await monta(CasseNew, { saldo_al_predefinito: '2027-01-01', saldo_al_minimo: '2027-01-01' });
        await scriviSaldo('1.000,00');
        expect(campo()?.type).toBe('date');
        expect(campo()?.value).toBe('2027-01-01');
        expect(campo()?.getAttribute('min')).toBe('2027-01-01');
        expect((document.body.textContent ?? '').replace(/\s+/g, ' ')).toContain('Non può cadere in un esercizio chiuso');
    });

    test('nella modifica c\'è finché il saldo non è registrato in contabilità, poi sparisce', async () => {
        await monta(CasseEdit, { cassa: cassa({ saldo_iniziale: '1.000,00' }), saldo_al_predefinito: '2027-01-01', saldo_al_minimo: '2027-01-01' });
        expect(campo()?.value).toBe('2027-01-01');
        w!.unmount();
        document.body.innerHTML = '';
        await monta(CasseEdit, { cassa: cassa({ saldo_iniziale_bloccato: true, saldo_iniziale: '1.000,00' }), saldo_al_predefinito: '2027-01-01', saldo_al_minimo: '2027-01-01' });
        expect(campo()).toBeNull();
    });

    test('con il saldo vuoto o a zero il campo non c\'è (l\'azione lo ignora), e compare appena c\'è un saldo', async () => {
        await monta(CasseNew, { saldo_al_predefinito: '2027-01-01', saldo_al_minimo: '2027-01-01' });
        expect(campo()).toBeNull();
        await scriviSaldo('0,00');
        expect(campo()).toBeNull();
        await scriviSaldo('-500,00');
        expect(campo()?.value).toBe('2027-01-01');
        w!.unmount();
        document.body.innerHTML = '';
        await monta(CasseEdit, { cassa: cassa({ saldo_iniziale: '' }), saldo_al_predefinito: '2027-01-01', saldo_al_minimo: '2027-01-01' });
        expect(campo()).toBeNull();
        await scriviSaldo('250,00');
        expect(campo()?.value).toBe('2027-01-01');
    });

    test('il campo non ha scritte sotto i 12 pixel', () => {
        for (const file of ['CasseNew.vue', 'CasseEdit.vue']) {
            const sorgente = readFileSync(resolve(process.cwd(), 'resources/js/pages/gestionale/casse', file), 'utf8');
            const blocco = sorgente.slice(sorgente.indexOf('<Label for="saldo_al"'), sorgente.indexOf('Non può cadere in un esercizio chiuso'));
            expect(blocco.length).toBeGreaterThan(100);
            expect(blocco).not.toMatch(/text-\[(?:[0-9]|1[01])px\]/);
        }
    });
});
