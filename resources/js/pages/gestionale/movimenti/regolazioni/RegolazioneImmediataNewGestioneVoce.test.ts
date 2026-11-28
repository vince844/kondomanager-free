// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
// @vitest-environment jsdom

/**
 * La regolazione immediata con due gestioni: il menu della voce offre le voci della gestione scelta, e la data fuori dal periodo della gestione
 * arriva come avviso del server che chi registra conferma («Confermo la data e registro»).
 */

import { afterEach, describe, expect, test, vi } from 'vitest';
import { flushPromises, mount, type VueWrapper } from '@vue/test-utils';

vi.mock('@inertiajs/vue3', async (importOriginal) => ({
    ...(await importOriginal<typeof import('@inertiajs/vue3')>()),
    Head: { template: '<span />' },
    usePage: () => ({ props: { flash: {}, auth: { user: { roles: ['amministratore'], permissions: [] } } } }),
}));
vi.mock('@/composables/permissions', () => ({
    usePermission: () => ({ generatePath: (p: string) => `/${p}`, generateRoute: (n: string) => n, hasPermission: () => true, hasRole: () => true }),
}));
(globalThis as unknown as { route: unknown }).route = (nome: string) => `/${nome}`;

const RegolazioneImmediataNew = (await import('@/pages/gestionale/movimenti/regolazioni/RegolazioneImmediataNew.vue')).default;

const stubs = {
    GestionaleLayout: { template: '<div><slot /></div>' },
    PageHeaderGuide: { template: '<div><slot /></div>' },
    RegolazioneImmediataGuide: true,
    MoneyInput: { name: 'MoneyInput', props: ['modelValue'], template: '<input :value="modelValue" />' },
};

let pagina: VueWrapper | null = null;

async function monta(precompilata: Record<string, unknown> | null = null) {
    pagina = mount(RegolazioneImmediataNew, {
        attachTo: document.body,
        props: {
            condominio: { id: 28, nome: 'Aurora' }, condomini: [], esercizi: [{ id: 41, nome: '2026' }],
            gestioni: [
                { id: 35, nome: 'Gestione ordinaria', tipo: 'ordinaria', esercizio_ids: [41] },
                { id: 36, nome: 'Riscaldamento', tipo: 'ordinaria', esercizio_ids: [41] },
            ],
            capitoli: [
                { id: 55, nome: 'Pulizie', parent_nome: null, label: 'Gestione ordinaria · Pulizie', gestione_id: 35 },
                { id: 56, nome: 'Manutenzione caldaia', parent_nome: null, label: 'Riscaldamento · Manutenzione caldaia', gestione_id: 36 },
            ],
            casse: [{ id: 3, nome: 'Banca principale', tipo: 'banca' }], fornitori: [], prossimo_protocollo: 'RIM-2026-00001',
            precompilata,
        } as any,
        global: { stubs, mocks: { route: (n: string) => `/${n}` } },
    });
    await flushPromises();
}

afterEach(() => { pagina?.unmount(); pagina = null; document.body.innerHTML = ''; });

describe('la gestione e la voce della regolazione', () => {
    test('il menu della voce offre solo le voci della gestione scelta; cambiando gestione la voce dell\'altra si toglie', async () => {
        await monta();
        const vm = pagina!.vm as any;
        vm.form.gestione_id = 36;
        await flushPromises();
        expect(vm.capitoliDisponibili.map((c: { id: number }) => c.id)).toEqual([56]);

        vm.form.conto_id = 56;
        vm.form.gestione_id = 35;
        await flushPromises();
        expect(vm.capitoliDisponibili.map((c: { id: number }) => c.id)).toEqual([55]);
        expect(vm.form.conto_id).toBeNull();
    });

    test('la voce precompilata sceglie la sua gestione', async () => {
        await monta({ cassa_id: 3, esercizio_id: 41, conto_id: 56, data_operazione: '2026-11-02', importo: '85.00', causale: 'Bruciatore' });
        expect((pagina!.vm as any).form.gestione_id).toBe(36);
    });

    test('l\'avviso della data si mostra con il pulsante di conferma; cambiando la data l\'avviso si toglie', async () => {
        await monta();
        const vm = pagina!.vm as any;
        vm.form.setError('avviso_data', 'La data è fuori dalla stagione: confermi? La gestione «Riscaldamento» in questo esercizio va dal 15/10/2026 al 31/12/2026.');
        await flushPromises();
        expect(pagina!.find('[data-test="avviso-data"]').text()).toContain('La data è fuori dalla stagione: confermi?');
        expect(pagina!.find('[data-test="conferma-data"]').text()).toBe('Confermo la data e registro');

        vm.form.data_operazione = '2026-10-20';
        await flushPromises();
        expect(pagina!.find('[data-test="avviso-data"]').exists()).toBe(false);
        expect(vm.form.conferma_avviso_data).toBe(false);
    });
});
