// @vitest-environment jsdom

/**
 * La schermata che passa l'aggiornamento al ponte (`public/km-update.php`).
 *
 * La schermata dice soltanto ciò che succede. Nessun codice mette il gestionale in
 * manutenzione durante l'aggiornamento (il ponte, `SystemUpgradeController`, `SystemFinalizer` e
 * `UpdateService` non lo fanno), quindi la frase non la promette; dice invece la cosa che conta per
 * chi aspetta: l'aggiornamento richiede qualche minuto e la pagina non va chiusa, perché il ponte
 * lavora nella stessa richiesta che la pagina tiene aperta.
 *
 * Il componente si monta davvero, con i timer finti: il modulo parte da solo dopo 1,5 secondi, e qui
 * non deve partire.
 */

import { afterEach, beforeEach, describe, expect, test, vi } from 'vitest';
import { mount } from '@vue/test-utils';
import Launch from './Launch.vue';

const props = {
    actionUrl: 'https://condominio.example.it/km-update.php',
    token: 'a'.repeat(64),
    version: '1.11.0',
};

beforeEach(() => {
    vi.useFakeTimers();
});

afterEach(() => {
    vi.useRealTimers();
});

describe('la schermata di avvio dell aggiornamento', () => {
    test('non promette una modalità manutenzione', () => {
        const wrapper = mount(Launch, { props });

        expect(wrapper.text()).not.toContain('manutenzione');

        wrapper.unmount();
    });

    test('dice che l aggiornamento richiede qualche minuto e che la pagina non va chiusa', () => {
        const wrapper = mount(Launch, { props });

        expect(wrapper.text()).toContain("L'aggiornamento richiede qualche minuto: non chiudere la pagina.");
        expect(wrapper.text()).toContain('Avvio Aggiornamento v1.11.0');

        wrapper.unmount();
    });

    test('il modulo porta al ponte il token ricevuto, e nient altro di nuovo', () => {
        const wrapper = mount(Launch, { props });

        const form = wrapper.find('form');
        expect(form.attributes('action')).toBe(props.actionUrl);
        expect(form.attributes('method')).toBe('POST');

        const campi = Object.fromEntries(
            form.findAll('input[type="hidden"]').map((campo) => [campo.attributes('name'), (campo.element as HTMLInputElement).value]),
        );
        expect(campi).toEqual({ token: props.token, mode: 'update' });

        wrapper.unmount();
    });
});
