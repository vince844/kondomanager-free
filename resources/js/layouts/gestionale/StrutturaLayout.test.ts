// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
// @vitest-environment jsdom

/**
 * Le schede dell'area «Condominio»: «Impostazioni» dopo «Recupero dei crediti», con la sua icona e il suo indirizzo. Dalla 1.12.0-beta.13 in fondo c'è
 * «Registro delle modifiche», solo per chi ha «Vede il registro delle modifiche» e non per i ruoli del portale; per gli altri «Impostazioni» resta l'ultima.
 * Le nove voci stanno su una riga, e la nona nella riga si chiama «Registro»: la barra non va a capo, come quella dei Movimenti.
 *
 * Che cosa non copre: la larghezza vera della riga (la prova a video).
 */
import { describe, expect, test, vi } from 'vitest';
import { shallowMount } from '@vue/test-utils';

vi.mock('@inertiajs/vue3', async (importOriginal) => ({
    ...(await importOriginal<typeof import('@inertiajs/vue3')>()),
    usePage: () => ({ props: { condominio: { id: 16, nome: 'Via Verdi 12' } } }),
}));
const chi = { permessi: [] as string[], ruoli: [] as string[] };
vi.mock('@/composables/permissions', () => ({
    usePermission: () => ({
        generatePath: (p: string, a: Record<string, number> = {}) => '/admin/' + p.replace(/:(\w+)/g, (_, n: string) => String(a[n])),
        hasPermission: (p: string[]) => p.some((x) => chi.permessi.includes(x)), hasRole: (r: string[]) => r.some((x) => chi.ruoli.includes(x)),
    }),
}));

const Layout = (await import('./StrutturaLayout.vue')).default;

describe('le schede del condominio', () => {
    test('«Impostazioni» in fondo, con l\'icona e l\'indirizzo del condominio', () => {
        const voci = shallowMount(Layout).findComponent({ name: 'NavSezione' }).props('items') as Array<{ title: string; href: string; icon?: unknown }>;
        expect(voci.map((v) => v.title)).toEqual(['Dettagli', 'Palazzine', 'Scale', 'Risorse e fondi', 'Saldi Iniziali', 'Già versato', 'Recupero dei crediti', 'Impostazioni']);
        expect(voci.at(-1)!.href).toBe('/admin/gestionale/16/impostazioni');
        expect(voci.every((v) => !!v.icon)).toBe(true);
    });

    test('con «Vede il registro delle modifiche», «Registro delle modifiche» in fondo; per un condòmino no, anche con il permesso', () => {
        chi.permessi = ['Vede il registro delle modifiche'];
        chi.ruoli = ['amministratore'];
        const voci = shallowMount(Layout).findComponent({ name: 'NavSezione' }).props('items') as Array<{ title: string; href: string; icon?: unknown }>;
        expect(voci.map((v) => v.title).slice(-2)).toEqual(['Impostazioni', 'Registro delle modifiche']);
        expect(voci.at(-1)!.href).toBe('/admin/gestionale/16/registro-modifiche');
        expect(!!voci.at(-1)!.icon).toBe(true);

        chi.ruoli = ['utente'];
        const portale = shallowMount(Layout).findComponent({ name: 'NavSezione' }).props('items') as Array<{ title: string }>;
        expect(portale.at(-1)!.title).toBe('Impostazioni');
        chi.permessi = [];
        chi.ruoli = [];
    });

    test('su una riga, e la nona si chiama «Registro» nella riga, con il nome intero al passaggio del mouse e nella tendina', () => {
        chi.permessi = ['Vede il registro delle modifiche'];
        chi.ruoli = ['amministratore'];
        const barra = shallowMount(Layout).findComponent({ name: 'NavSezione' });
        expect(barra.props('unaRiga')).toBe(true);
        const voci = barra.props('items') as Array<{ title: string; breve?: string }>;
        expect(voci.at(-1)).toMatchObject({ title: 'Registro delle modifiche', breve: 'Registro' });
        expect(voci.slice(0, -1).every((v) => v.breve === undefined)).toBe(true);
        chi.permessi = [];
        chi.ruoli = [];
    });
});
