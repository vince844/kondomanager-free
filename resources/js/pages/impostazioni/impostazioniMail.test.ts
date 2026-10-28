// @vitest-environment jsdom

/**
 * Coda 261 e 404 — la prova della posta, a conferma della password scaduta, apre la finestra della
 * conferma invece di mostrare un errore di connessione, e dopo la conferma rifà la stessa prova.
 *
 * Salvare e provare la posta chiede la password ridigitata da non più di quindici minuti. Il
 * salvataggio è un modulo Inertia; la prova è una chiamata `axios`, che riceve un 423 in JSON. Senza questo
 * passaggio la pagina lo mostrava come un errore del server di posta («Password confirmation required.»), che
 * non lo è. Con la Coda 261 la pagina lasciava il posto alla schermata della conferma e il modulo intero (server,
 * utente, password, indirizzo di prova) si perdeva; ora la finestra si apre sopra il modulo e la prova riparte
 * con gli stessi dati.
 */

import { beforeEach, describe, expect, test, vi } from 'vitest';
import { reactive } from 'vue';
import { flushPromises, shallowMount } from '@vue/test-utils';

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
const { annullaConferma, confermaPasswordAperta, confermaRiuscita } = await import('@/composables/useConfermaPassword');

type Stato = {
    isTesting: boolean;
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
    beforeEach(() => annullaConferma());

    test('Coda 404 — la prova con il 423 apre la finestra della conferma, senza lasciare la pagina e senza errore', async () => {
        visit.mockClear();
        post.mockReset();
        post.mockRejectedValueOnce({ response: { status: 423, data: { message: 'Password confirmation required.', conferma_password: true } } });
        const stato = monta();
        stato.testEmail = 'prova@example.com';

        await stato.runTest();

        expect(confermaPasswordAperta.value).toBe(true);
        expect(visit).not.toHaveBeenCalled();
        expect(stato.testStatus).toBeNull();
        expect(stato.errorMessage).toBe('');
        // Il pulsante non resta bloccato sotto la finestra.
        expect(stato.isTesting).toBe(false);
    });

    test('Coda 404 — dopo la conferma la prova riparte con gli stessi dati, anche se il modulo nel frattempo è cambiato', async () => {
        post.mockReset();
        post.mockRejectedValueOnce({ response: { status: 423, data: { message: 'Password confirmation required.', conferma_password: true } } });
        post.mockResolvedValueOnce({ data: { success: true } });
        const stato = monta({ mail_driver: 'smtp', mail_host: 'smtp.esempio.it' });
        stato.testEmail = 'prova@esempio.it';
        await stato.runTest();

        stato.testEmail = 'altra@esempio.it';
        confermaRiuscita();
        await flushPromises();

        expect(post).toHaveBeenCalledTimes(2);
        expect(post.mock.calls[1]).toEqual(post.mock.calls[0]);
        expect(post.mock.calls[1][1]).toMatchObject({ mail_host: 'smtp.esempio.it', test_email: 'prova@esempio.it' });
        expect(stato.testStatus).toBe('success');
        expect(confermaPasswordAperta.value).toBe(false);
    });

    test('Coda 404 — con «Annulla» la prova non riparte e la pagina resta com\'era', async () => {
        post.mockReset();
        post.mockRejectedValueOnce({ response: { status: 423, data: { message: 'Password confirmation required.', conferma_password: true } } });
        const stato = monta();
        stato.testEmail = 'prova@example.com';
        await stato.runTest();

        annullaConferma();
        confermaRiuscita();
        await flushPromises();

        expect(post).toHaveBeenCalledTimes(1);
        expect(stato.testStatus).toBeNull();
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
