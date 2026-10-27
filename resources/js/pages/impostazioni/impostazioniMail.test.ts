// @vitest-environment jsdom

/**
 * Coda 261 — la prova della posta, a conferma della password scaduta, porta alla pagina della
 * conferma invece di mostrare un errore di connessione.
 *
 * Salvare e provare la posta chiede la password ridigitata da non più di quindici minuti. Il
 * salvataggio è un modulo Inertia, e il server lo rimanda da solo alla conferma; la prova è una
 * chiamata `axios`, che riceve un 423 in JSON. Senza questo passaggio la pagina lo mostrava come un
 * errore del server di posta («Password confirmation required.»), che non lo è.
 */

import { describe, expect, test, vi } from 'vitest';
import { reactive } from 'vue';
import { shallowMount } from '@vue/test-utils';

const visit = vi.fn();
const post = vi.fn();

vi.mock('@inertiajs/vue3', () => ({
    Head: { template: '<div />' },
    router: { visit },
    usePage: () => ({ props: { flash: {} } }),
    useForm: (dati: Record<string, unknown>) =>
        reactive({ ...dati, errors: {}, processing: false, post: vi.fn(), reset: vi.fn(), data: () => ({ ...dati }) }),
}));

vi.mock('laravel-vue-i18n', () => ({ trans: (chiave: string) => chiave }));
vi.mock('axios', () => ({ default: { post } }));

(globalThis as Record<string, unknown>).route = (nome: string) => `/${nome}`;

const PaginaPosta = (await import('./impostazioniMail.vue')).default;

type Stato = {
    testEmail: string;
    testStatus: string | null;
    errorMessage: string;
    runTest: () => Promise<void>;
    form: { data: () => Record<string, unknown> };
};

function monta(settings: Record<string, unknown> = { mail_driver: 'smtp' }, passwordSet = false) {
    const pagina = shallowMount(PaginaPosta, { props: { settings, password_set: passwordSet } });

    return (pagina.vm as unknown as { $: { setupState: Stato } }).$.setupState;
}

describe('impostazioniMail', () => {
    test('Coda 261 — la prova con il 423 porta alla conferma della password, senza errore', async () => {
        visit.mockClear();
        post.mockRejectedValueOnce({ response: { status: 423, data: { message: 'Password confirmation required.' } } });
        const stato = monta();
        stato.testEmail = 'prova@example.com';

        await stato.runTest();

        expect(visit).toHaveBeenCalledWith('/password.confirm');
        expect(stato.testStatus).toBeNull();
        expect(stato.errorMessage).toBe('');
    });

    test('Coda 261 — un errore vero del server di posta resta un errore della pagina', async () => {
        visit.mockClear();
        post.mockRejectedValueOnce({ response: { status: 422, data: { message: 'connessione rifiutata' } } });
        const stato = monta();
        stato.testEmail = 'prova@example.com';

        await stato.runTest();

        expect(visit).not.toHaveBeenCalled();
        expect(stato.testStatus).toBe('error');
        expect(stato.errorMessage).toBe('connessione rifiutata');
    });

    test('Coda 311 — il limite delle prove si dice nella lingua della pagina', async () => {
        visit.mockClear();
        post.mockRejectedValueOnce({ response: { status: 429, data: { message: 'Too Many Attempts.' } } });
        const stato = monta();
        stato.testEmail = 'prova@example.com';

        await stato.runTest();

        expect(visit).not.toHaveBeenCalled();
        expect(stato.testStatus).toBe('error');
        expect(stato.errorMessage).toBe('impostazioni.error_test_mail_too_many');
    });

    test('Coda 311 — la password da riscrivere arriva al riquadro dell\'errore con il messaggio del server', async () => {
        post.mockRejectedValueOnce({ response: { status: 422, data: { success: false, message: 'Riscrivi la password SMTP.' } } });
        const stato = monta();
        stato.testEmail = 'prova@example.com';

        await stato.runTest();

        expect(stato.testStatus).toBe('error');
        expect(stato.errorMessage).toBe('Riscrivi la password SMTP.');
    });

    test('Coda 311 — aperta e provata senza toccare niente, il corpo è uguale ai dati ricevuti', async () => {
        // Le impostazioni come le manda il server, senza la password; valori scomodi.
        const ricevute = {
            mail_enabled: false,
            mail_driver: 'smtp',
            mail_host: 'Smtp.Esempio.IT',
            mail_port: 465,
            mail_username: 'utente+posta@esempio.it',
            mail_encryption: 'null',
            mail_from_address: 'amministrazione@esempio.it',
            mail_from_name: "Studio D'Angelo – Condominî",
        };
        post.mockReset();
        post.mockResolvedValueOnce({ data: { success: true } });
        const stato = monta(ricevute, true);
        stato.testEmail = 'prova@esempio.it';

        expect(stato.form.data()).toEqual({ ...ricevute, mail_password: '' });

        await stato.runTest();

        expect(post).toHaveBeenCalledWith('/admin.settings.mail.test', { ...ricevute, mail_password: '', test_email: 'prova@esempio.it' });
        expect(stato.testStatus).toBe('success');
    });
});
