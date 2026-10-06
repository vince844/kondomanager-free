// @vitest-environment jsdom

/**
 * Il modulo della password del primo accesso si manda all'URL firmato da cui è stato aperto.
 *
 * Il server (1.11.0-beta.45, Coda 222) accetta il salvataggio solo con la stessa firma del link
 * mandato per email, e la passa alla pagina nella prop `azione`. Se il modulo tornasse a mandare a
 * `route('password.create')`, che non porta la firma, ogni primo accesso risponderebbe 403 e la
 * suite PHP resterebbe verde: per questo la parte Vue ha un test suo.
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

const NewUserCreatePassword = (await import('./NewUserCreatePassword.vue')).default;

const stubs = {
    AuthLayout: { template: '<div><slot /></div>' },
    InputError: { template: '<div />' },
    Label: { template: '<label><slot /></label>' },
    Input: { template: '<input />' },
    Button: { template: '<button type="submit"><slot /></button>' },
    LoaderCircle: { template: '<span />' },
};

describe('NewUserCreatePassword', () => {
    test('il salvataggio va all\'URL firmato ricevuto dal server, non a una rotta senza firma', async () => {
        const azione = '/password/new?expires=1791323610&hash=abc&id=85&signature=59fb0c37';
        const wrapper = mount(NewUserCreatePassword, {
            props: { email: 'nuovo@example.com', azione },
            global: { stubs },
        });

        await wrapper.get('form').trigger('submit');

        expect(post).toHaveBeenCalledTimes(1);
        expect(post.mock.calls[0][0]).toBe(azione);
    });
});
