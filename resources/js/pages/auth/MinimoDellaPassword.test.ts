// @vitest-environment jsdom

/**
 * Coda 262 — accanto al campo della password nuova la pagina dice la lunghezza minima.
 *
 * Le pagine con una password nuova sono cinque: il primo accesso e la registrazione da invito hanno già il
 * loro test (`NewUserCreatePassword.test.ts`, `RegisterFromInvite.test.ts`); qui le altre tre, la
 * registrazione pubblica, il link di «password dimenticata» e il cambio dal profilo. Che il numero della
 * frase sia quello della regola lo prova `PasswordDiDieciCaratteriTest.php`, lingua per lingua.
 *
 * Le traduzioni non sono caricate: `trans()` restituisce la chiave.
 */

import { describe, expect, test, vi } from 'vitest';
import { reactive } from 'vue';
import { shallowMount } from '@vue/test-utils';

vi.mock('@inertiajs/vue3', () => ({
    Head: { template: '<div />' },
    useForm: (dati: Record<string, unknown>) =>
        reactive({ ...dati, errors: {}, processing: false, recentlySuccessful: false, post: vi.fn(), put: vi.fn(), reset: vi.fn() }),
}));
vi.mock('laravel-vue-i18n', () => ({ trans: (chiave: string) => chiave }));

(globalThis as Record<string, unknown>).route = (nome: string) => `/${nome}`;

const pagine = {
    'la registrazione pubblica': async () => (await import('./Register.vue')).default,
    'il link di «password dimenticata»': async () => (await import('./ResetPassword.vue')).default,
    'il cambio dal profilo': async () => (await import('../settings/Password.vue')).default,
};

describe('Coda 262 — la lunghezza minima accanto alla password nuova', () => {
    test.each(Object.entries(pagine))('%s la dice', async (_nome, carica) => {
        const pagina = shallowMount(await carica(), {
            props: { token: 'gettone', email: 'chi@example.com' },
            global: { renderStubDefaultSlot: true, mocks: { route: (n: string) => `/${n}` } },
        });

        expect(pagina.text()).toContain('auth.hint.password_min');
    });
});
