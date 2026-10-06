// @vitest-environment jsdom

/**
 * Il modulo della registrazione da invito si manda all'URL firmato da cui è stato aperto.
 *
 * Il server (1.11.0-beta.45, Coda 223) accetta il salvataggio solo con la stessa firma del link
 * dell'invito, e la passa alla pagina nella prop `azione`. Se il modulo tornasse a mandare a
 * `route('invito.register.store')`, ogni registrazione da invito risponderebbe 403 e la suite PHP
 * resterebbe verde: per questo la parte Vue ha un test suo.
 */

import { describe, expect, test, vi } from 'vitest';
import { reactive } from 'vue';
import { mount } from '@vue/test-utils';

const post = vi.fn();

vi.mock('@inertiajs/vue3', () => ({
    Head: { template: '<div />' },
    useForm: (dati: Record<string, unknown>) =>
        reactive({ ...dati, errors: {}, processing: false, post, reset: vi.fn() }),
}));

vi.mock('laravel-vue-i18n', () => ({ trans: (chiave: string) => chiave }));

(globalThis as Record<string, unknown>).route = (nome: string) => `/${nome}`;

const RegisterFromInvite = (await import('./RegisterFromInvite.vue')).default;

const stubs = {
    AuthLayout: { template: '<div><slot /></div>' },
    InputError: { template: '<div />' },
    TextLink: { template: '<a><slot /></a>' },
    Label: { template: '<label><slot /></label>' },
    Input: { template: '<input />' },
    Button: { template: '<button type="submit"><slot /></button>' },
    LoaderCircle: { template: '<span />' },
};

describe('RegisterFromInvite', () => {
    test('il salvataggio va all\'URL firmato ricevuto dal server, non a una rotta senza firma', async () => {
        const azione = '/invito/register?expires=1791323610&id=27&signature=94fe0835';
        const wrapper = mount(RegisterFromInvite, {
            props: { email: 'invitato@example.com', azione },
            global: { stubs, mocks: { route: (nome: string) => `/${nome}` } },
        });

        await wrapper.get('form').trigger('submit');

        expect(post).toHaveBeenCalledTimes(1);
        expect(post.mock.calls[0][0]).toBe(azione);
    });
});
