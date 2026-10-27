// @vitest-environment jsdom

/**
 * Coda 261 — nel profilo la password attuale si chiede solo quando l'email cambia.
 *
 * Il modulo del profilo manda nome ed email insieme. Il server (`ProfileUpdateRequest`) chiede la
 * password attuale solo se l'email è diversa da quella dell'account: la pagina mostra il campo alla
 * stessa condizione, così chi corregge il nome non vede una richiesta che non gli serve, e chi
 * cambia l'email la vede prima di salvare. Il modulo aperto e mandato senza toccare niente manda i
 * dati ricevuti.
 */

import { describe, expect, test, vi } from 'vitest';
import { reactive } from 'vue';
import { mount } from '@vue/test-utils';

const patch = vi.fn();
const utente = { name: "Dell'Òrto Niccolò", email: 'niccolo+condominio@example.com', email_verified_at: '2026-10-01' };

vi.mock('@inertiajs/vue3', () => ({
    Head: { template: '<div />' },
    Link: { template: '<a><slot /></a>' },
    usePage: () => ({ props: { auth: { user: utente } } }),
    useForm: (dati: Record<string, unknown>) =>
        reactive({ ...dati, errors: {}, processing: false, recentlySuccessful: false, patch, reset: vi.fn() }),
}));

vi.mock('laravel-vue-i18n', () => ({ trans: (chiave: string) => chiave }));

(globalThis as Record<string, unknown>).route = (nome: string) => `/${nome}`;

const Profile = (await import('./Profile.vue')).default;

const stubs = {
    AppLayout: { template: '<div><slot /></div>' },
    SettingsLayout: { template: '<div><slot /></div>' },
    HeadingSmall: { template: '<div />' },
    DeleteUser: { template: '<div />' },
    TransitionRoot: { template: '<div><slot /></div>' },
    InputError: { template: '<div />' },
    Label: { template: '<label><slot /></label>' },
    Button: { template: '<button type="submit"><slot /></button>' },
    Input: {
        props: ['modelValue'],
        emits: ['update:modelValue'],
        template: '<input :value="modelValue" @input="$emit(\'update:modelValue\', $event.target.value)" />',
    },
};

function monta() {
    return mount(Profile, { props: { mustVerifyEmail: true }, global: { stubs } });
}

describe('Profile', () => {
    test('Coda 261 — senza cambiare l\'email la password attuale non si chiede', () => {
        expect(monta().find('#current_password').exists()).toBe(false);
    });

    test('Coda 261 — cambiando l\'email compare la password attuale', async () => {
        const pagina = monta();

        await pagina.get('#email').setValue('altra@example.com');

        expect(pagina.find('#current_password').exists()).toBe(true);
    });

    test('Coda 261 — rimettendo l\'email di prima la richiesta sparisce', async () => {
        const pagina = monta();

        await pagina.get('#email').setValue('altra@example.com');
        await pagina.get('#email').setValue(utente.email);

        expect(pagina.find('#current_password').exists()).toBe(false);
    });

    test('Coda 261 — aperto e mandato senza toccare niente, il modulo manda i dati ricevuti', async () => {
        patch.mockClear();
        const pagina = monta();

        await pagina.get('form').trigger('submit');

        expect(patch).toHaveBeenCalledTimes(1);
        expect(patch.mock.calls[0][0]).toBe('/profile.update');
        const modulo = (pagina.vm as unknown as { $: { setupState: { form: Record<string, unknown> } } }).$.setupState.form;
        expect(modulo.name).toBe(utente.name);
        expect(modulo.email).toBe(utente.email);
    });
});
