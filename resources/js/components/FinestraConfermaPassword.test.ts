// @vitest-environment jsdom

/**
 * Coda 404 — la finestra che chiede la password ridigitata sulla stessa pagina, e rifà da sola la richiesta.
 *
 * Le azioni sensibili (la 2FA, la posta, i backup, i ruoli, gli utenti) rispondono 423 a conferma scaduta. La
 * finestra è montata una volta alla radice dell'applicazione: manda la password in JSON a `POST /confirm-password`,
 * e con il 200 chiude e fa ripartire la richiesta che era stata fermata. Si monta la finestra vera (`Dialog` di
 * reka-ui), con la tastiera e i clic che usa l'amministratore.
 *
 * - password sbagliata (422): l'errore sotto il campo, la finestra resta aperta, il fuoco torna sul campo, la
 *   richiesta non riparte;
 * - password giusta (200): la finestra si chiude, il campo è vuoto, la richiesta riparte una volta sola;
 * - limite delle prove (429): il messaggio del limite, nella lingua della pagina;
 * - «Annulla», la X e Esc: chiudono, la richiesta non riparte, la password non resta nella finestra.
 */

import { afterEach, beforeEach, describe, expect, test, vi } from 'vitest';
import { flushPromises, mount, type VueWrapper } from '@vue/test-utils';

const post = vi.fn();

vi.mock('axios', () => ({ default: { post } }));
vi.mock('laravel-vue-i18n', () => ({
    trans: (chiave: string) => chiave,
    transChoice: (chiave: string, quanti: number) => `${chiave} ${quanti}`,
}));

(globalThis as Record<string, unknown>).route = (nome: string) => `/${nome}`;

const { annullaConferma, confermaPasswordAperta, richiediConferma } = await import('@/composables/useConfermaPassword');
const FinestraConfermaPassword = (await import('./FinestraConfermaPassword.vue')).default;

let finestra: VueWrapper | null = null;

const dialogo = () => document.body.querySelector('[role="dialog"]') as HTMLElement | null;
const campo = () => document.body.querySelector('input[type="password"]') as HTMLInputElement;
const pulsante = (testo: string) =>
    [...document.body.querySelectorAll('button')].find((b) => b.textContent?.trim() === testo) as HTMLButtonElement | undefined;

async function apri(riprova: () => unknown) {
    richiediConferma(riprova);
    await flushPromises();
}

async function scrivi(password: string) {
    campo().value = password;
    campo().dispatchEvent(new Event('input', { bubbles: true }));
    await flushPromises();
}

async function conferma() {
    // Come un clic vero: il fuoco passa dal campo al pulsante.
    pulsante('auth.confirm_password_dialog.confirm')!.focus();
    pulsante('auth.confirm_password_dialog.confirm')!.click();
    await flushPromises();
}

beforeEach(() => {
    post.mockReset();
    annullaConferma();
    finestra = mount(FinestraConfermaPassword, { attachTo: document.body });
});

afterEach(() => {
    finestra?.unmount();
    finestra = null;
    document.body.innerHTML = '';
});

describe('FinestraConfermaPassword', () => {
    test('Coda 404 — chiusa finché nessuna richiesta riceve il 423, aperta quando una lo riceve', async () => {
        await flushPromises();
        expect(dialogo()).toBeNull();

        await apri(vi.fn());

        expect(dialogo()).not.toBeNull();
        expect(campo()).not.toBeNull();
    });

    test('Coda 404 — la password giusta (200) chiude la finestra, svuota il campo e fa ripartire la richiesta una volta sola', async () => {
        const riprova = vi.fn();
        post.mockResolvedValueOnce({ status: 200, data: { confermata: true } });
        await apri(riprova);
        await scrivi('la-mia-password');

        await conferma();

        expect(post).toHaveBeenCalledTimes(1);
        expect(post).toHaveBeenCalledWith('/password.confirm', { password: 'la-mia-password' }, { headers: { Accept: 'application/json' } });
        expect(riprova).toHaveBeenCalledTimes(1);
        expect(dialogo()).toBeNull();
        expect(confermaPasswordAperta.value).toBe(false);

        // Riaperta per un'altra richiesta, la password di prima non c'è più.
        await apri(vi.fn());
        expect(campo().value).toBe('');
    });

    test('Coda 404 — la password sbagliata (422) mette l\'errore sotto il campo, tiene aperta la finestra e non rifà la richiesta', async () => {
        const riprova = vi.fn();
        post.mockRejectedValueOnce({ response: { status: 422, headers: {}, data: { errors: { password: ['La password non è corretta.'] } } } });
        await apri(riprova);
        await scrivi('sbagliata');

        await conferma();

        expect(dialogo()).not.toBeNull();
        expect(dialogo()!.textContent).toContain('La password non è corretta.');
        expect(riprova).not.toHaveBeenCalled();
        // Il fuoco torna sul campo, che si può riscrivere subito; la password sbagliata non resta.
        expect(document.activeElement).toBe(campo());
        expect(campo().value).toBe('');
    });

    test('Coda 404 — dopo una password sbagliata la giusta fa ripartire la richiesta, e l\'errore sparisce', async () => {
        const riprova = vi.fn();
        post.mockRejectedValueOnce({ response: { status: 422, headers: {}, data: { errors: { password: ['La password non è corretta.'] } } } });
        post.mockResolvedValueOnce({ status: 200, data: { confermata: true } });
        await apri(riprova);
        await scrivi('sbagliata');
        await conferma();

        await scrivi('giusta');
        await conferma();

        expect(post).toHaveBeenLastCalledWith('/password.confirm', { password: 'giusta' }, { headers: { Accept: 'application/json' } });
        expect(riprova).toHaveBeenCalledTimes(1);
        expect(dialogo()).toBeNull();
    });

    test('Coda 404 — il limite delle prove (429) si dice nella finestra, con i minuti da aspettare', async () => {
        const riprova = vi.fn();
        post.mockRejectedValueOnce({ response: { status: 429, headers: { 'retry-after': '125' }, data: { message: 'Too Many Attempts.' } } });
        await apri(riprova);
        await scrivi('una-password');

        await conferma();

        expect(dialogo()!.textContent).toContain('auth.confirm_password_dialog.too_many 3');
        expect(dialogo()!.textContent).not.toContain('Too Many Attempts.');
        expect(riprova).not.toHaveBeenCalled();
    });

    test('Coda 404 — il limite senza i secondi da aspettare non promette un tempo', async () => {
        post.mockRejectedValueOnce({ response: { status: 429, headers: {}, data: {} } });
        await apri(vi.fn());
        await scrivi('una-password');

        await conferma();

        expect(dialogo()!.textContent).toContain('auth.confirm_password_dialog.too_many_later');
    });

    test('Coda 404 — un altro errore dice una frase generica e lascia la finestra aperta', async () => {
        const riprova = vi.fn();
        post.mockRejectedValueOnce(new Error('Network Error'));
        await apri(riprova);
        await scrivi('una-password');

        await conferma();

        expect(dialogo()!.textContent).toContain('auth.confirm_password_dialog.error_generic');
        expect(riprova).not.toHaveBeenCalled();
    });

    test.each([401, 419])('Coda 404 — con la sessione finita (%i) la finestra dice di ricaricare la pagina, non di riprovare, e lascia il modulo com\'è', async (stato) => {
        const riprova = vi.fn();
        const annullata = vi.fn();
        post.mockRejectedValueOnce({ response: { status: stato, headers: {}, data: { message: 'Unauthenticated.' } } });
        richiediConferma(riprova, annullata);
        await flushPromises();
        await scrivi('una-password');

        await conferma();

        // Con la sessione finita riprovare non può riuscire: la frase dice la strada, e non è quella generica.
        expect(dialogo()).not.toBeNull();
        expect(dialogo()!.textContent).toContain('auth.confirm_password_dialog.session_ended');
        expect(dialogo()!.textContent).not.toContain('auth.confirm_password_dialog.error_generic');
        expect(riprova).not.toHaveBeenCalled();
        // Niente ricarica da sola: la pagina sotto non è stata toccata, e «Annulla» la lascia com'è.
        expect(annullata).not.toHaveBeenCalled();
        expect(campo().value).toBe('');

        pulsante('auth.confirm_password_dialog.cancel')!.click();
        await flushPromises();

        expect(dialogo()).toBeNull();
        expect(annullata).toHaveBeenCalledTimes(1);
        expect(riprova).not.toHaveBeenCalled();
    });

    test.each([403, 500])('Coda 404 — dopo l\'avviso della sessione scaduta, un errore %i torna alla frase generica', async (stato) => {
        post.mockRejectedValueOnce({ response: { status: 419, headers: {}, data: {} } });
        post.mockRejectedValueOnce({ response: { status: stato, headers: {}, data: {} } });
        await apri(vi.fn());
        await scrivi('una-password');
        await conferma();
        expect(dialogo()!.textContent).toContain('auth.confirm_password_dialog.session_ended');

        await scrivi('una-password');
        await conferma();

        expect(dialogo()!.textContent).toContain('auth.confirm_password_dialog.error_generic');
        expect(dialogo()!.textContent).not.toContain('auth.confirm_password_dialog.session_ended');
    });

    test('Coda 404 — «Annulla» chiude, non rifà la richiesta e non lascia la password nella finestra', async () => {
        const riprova = vi.fn();
        await apri(riprova);
        await scrivi('mezza-password');

        pulsante('auth.confirm_password_dialog.cancel')!.click();
        await flushPromises();

        expect(dialogo()).toBeNull();
        expect(post).not.toHaveBeenCalled();
        expect(riprova).not.toHaveBeenCalled();

        await apri(vi.fn());
        expect(campo().value).toBe('');
    });

    test('Coda 404 — Esc chiude come «Annulla»: la richiesta non riparte', async () => {
        const riprova = vi.fn();
        await apri(riprova);

        document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }));
        await flushPromises();

        expect(dialogo()).toBeNull();
        expect(riprova).not.toHaveBeenCalled();
    });

    test('Coda 404 — mentre la password viene controllata il pulsante non manda una seconda volta', async () => {
        let finisci!: (valore: unknown) => void;
        post.mockReturnValueOnce(new Promise((risolvi) => (finisci = risolvi)));
        const riprova = vi.fn();
        await apri(riprova);
        await scrivi('una-password');

        pulsante('auth.confirm_password_dialog.confirm')!.click();
        pulsante('auth.confirm_password_dialog.confirm')!.click();
        await flushPromises();
        expect(post).toHaveBeenCalledTimes(1);

        finisci({ status: 200, data: { confermata: true } });
        await flushPromises();

        expect(riprova).toHaveBeenCalledTimes(1);
    });

    test('Coda 404 — la richiesta che ricevendo il 423 riapre la finestra non la lascia chiusa', async () => {
        // La richiesta rifatta riceve di nuovo il 423 (la conferma non è stata registrata): la finestra si riapre.
        const riprova = vi.fn(() => richiediConferma(riprova));
        post.mockResolvedValue({ status: 200, data: { confermata: true } });
        await apri(riprova);
        await scrivi('una-password');

        await conferma();

        expect(riprova).toHaveBeenCalledTimes(1);
        expect(dialogo()).not.toBeNull();
        expect(campo().value).toBe('');
    });
});
