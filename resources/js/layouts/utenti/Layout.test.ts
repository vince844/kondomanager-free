// @vitest-environment jsdom

/**
 * La barra della sezione «Utenti e accessi»: la voce «Ruoli» solo per l'amministratore (Coda 258, decisione
 * 134.1).
 *
 * Le pagine dei ruoli le gestisce solo l'amministratore, e il server le rifiuta agli altri con il perché: la
 * barra non offre a chi non è amministratore una voce che porta a un rifiuto.
 *
 * Le traduzioni non sono caricate: `trans()` restituisce la chiave.
 */

import { describe, expect, test, vi } from 'vitest';
import { shallowMount } from '@vue/test-utils';

const chi = vi.hoisted(() => ({ ruoli: [] as string[] }));

vi.mock('@inertiajs/vue3', async (importOriginal) => ({
    ...(await importOriginal<typeof import('@inertiajs/vue3')>()),
    usePage: () => ({ props: { auth: { user: { roles: chi.ruoli, permissions: [] } } } }),
}));
vi.mock('laravel-vue-i18n', () => ({ trans: (chiave: string) => chiave }));

const Layout = (await import('./Layout.vue')).default;

function vociDellaBarra(ruoli: string[]): string[] {
    chi.ruoli = ruoli;
    const barra = shallowMount(Layout).findComponent({ name: 'NavSezione' });

    return (barra.props('items') as { href: string }[]).map((voce) => voce.href);
}

describe('Layout degli utenti — la voce «Ruoli» (Coda 258, decisione 134.1)', () => {
    test('l\'amministratore vede le quattro voci', () => {
        expect(vociDellaBarra(['amministratore'])).toEqual(['/utenti', '/ruoli', '/permessi', '/inviti']);
    });

    test('il collaboratore e un ruolo su misura non vedono «Ruoli»', () => {
        expect(vociDellaBarra(['collaboratore'])).toEqual(['/utenti', '/permessi', '/inviti']);
        expect(vociDellaBarra(['Segreteria'])).toEqual(['/utenti', '/permessi', '/inviti']);
    });
});
