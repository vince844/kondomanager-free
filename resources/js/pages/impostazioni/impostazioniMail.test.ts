// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
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
 *
 * Dalla 1.12.0-beta.13 (decisione 572) la posta è una delle caselle della pagina, `CasellaDellaPosta.vue`: le stesse prove, sul componente.
 */

import { beforeEach, describe, expect, test, vi } from 'vitest';
import { reactive } from 'vue';
import { flushPromises, shallowMount } from '@vue/test-utils';

const visit = vi.fn();
const reload = vi.fn();
const post = vi.fn();

vi.mock('@inertiajs/vue3', () => ({
    Head: { template: '<div />' },
    router: { visit, reload },
    usePage: () => ({ props: { flash: {} } }),
    useForm: (dati: Record<string, unknown>) =>
        reactive({ ...dati, errors: {}, processing: false, post: vi.fn(), reset: vi.fn(), data: () => ({ ...dati }) }),
}));

vi.mock('laravel-vue-i18n', () => ({ trans: (chiave: string) => chiave }));
vi.mock('axios', () => ({ default: { post } }));

(globalThis as Record<string, unknown>).route = (nome: string) => `/${nome}`;

const Posta = (await import('@/components/impostazioni/caselle/CasellaDellaPosta.vue')).default;
const { annullaConferma, confermaPasswordAperta, confermaRiuscita } = await import('@/composables/useConfermaPassword');

type Stato = {
    inProva: boolean;
    destinatario: string;
    esito: { andata: boolean; motivo: { titolo: string } | null } | null;
    provaLInvio: () => Promise<void>;
    form: { data: () => Record<string, unknown> };
};

const CASELLA = { stato: 'provata', parola: 'Provata', adesso: 'Adesso: impostata.', riga: null, card: { valore: 'Provata', sotto: '' }, ultima_prova: null,
    sola_lettura: false };

function monta(settings: Record<string, unknown> = { mail_driver: 'smtp' }, passwordSet = false) {
    const casella = shallowMount(Posta, { props: { settings, passwordSet, casella: CASELLA, aperta: true } });

    return casella.vm as unknown as Stato;
}

describe('la prova della posta', () => {
    beforeEach(() => annullaConferma());

    test('Coda 404 — la prova con il 423 apre la finestra della conferma, senza lasciare la pagina e senza errore', async () => {
        visit.mockClear();
        post.mockReset();
        post.mockRejectedValueOnce({ response: { status: 423, data: { message: 'Password confirmation required.', conferma_password: true } } });
        const stato = monta();
        stato.destinatario = 'prova@example.com';

        await stato.provaLInvio();

        expect(confermaPasswordAperta.value).toBe(true);
        expect(visit).not.toHaveBeenCalled();
        expect(stato.esito).toBeNull();
        // Il pulsante non resta bloccato sotto la finestra.
        expect(stato.inProva).toBe(false);
    });

    test('Coda 404 — dopo la conferma la prova riparte con gli stessi dati, anche se il modulo nel frattempo è cambiato', async () => {
        post.mockReset();
        post.mockRejectedValueOnce({ response: { status: 423, data: { message: 'Password confirmation required.', conferma_password: true } } });
        post.mockResolvedValueOnce({ data: { success: true } });
        const stato = monta({ mail_driver: 'smtp', mail_host: 'smtp.esempio.it' });
        stato.destinatario = 'prova@esempio.it';
        await stato.provaLInvio();

        stato.destinatario = 'altra@esempio.it';
        confermaRiuscita();
        await flushPromises();

        expect(post).toHaveBeenCalledTimes(2);
        expect(post.mock.calls[1]).toEqual(post.mock.calls[0]);
        expect(post.mock.calls[1][1]).toMatchObject({ mail_host: 'smtp.esempio.it', test_email: 'prova@esempio.it' });
        expect(stato.esito?.andata).toBe(true);
        expect(confermaPasswordAperta.value).toBe(false);
    });

    test('Coda 404 — con «Annulla» la prova non riparte e la pagina resta com\'era', async () => {
        post.mockReset();
        post.mockRejectedValueOnce({ response: { status: 423, data: { message: 'Password confirmation required.', conferma_password: true } } });
        const stato = monta();
        stato.destinatario = 'prova@example.com';
        await stato.provaLInvio();

        annullaConferma();
        confermaRiuscita();
        await flushPromises();

        expect(post).toHaveBeenCalledTimes(1);
        expect(stato.esito).toBeNull();
    });

    test('Coda 261 — un errore vero del modulo resta un errore della pagina', async () => {
        visit.mockClear();
        post.mockRejectedValueOnce({ response: { status: 422, data: { message: 'connessione rifiutata' } } });
        const stato = monta();
        stato.destinatario = 'prova@example.com';

        await stato.provaLInvio();

        expect(visit).not.toHaveBeenCalled();
        expect(stato.esito?.andata).toBe(false);
        expect(stato.esito?.motivo?.titolo).toBe('connessione rifiutata');
    });

    test('Coda 311 — il limite delle prove si dice con parole della pagina', async () => {
        visit.mockClear();
        post.mockRejectedValueOnce({ response: { status: 429, data: { message: 'Too Many Attempts.' } } });
        const stato = monta();
        stato.destinatario = 'prova@example.com';

        await stato.provaLInvio();

        expect(visit).not.toHaveBeenCalled();
        expect(stato.esito?.andata).toBe(false);
        expect(stato.esito?.motivo?.titolo).toBe('Troppe prove in poco tempo.');
    });

    test('Coda 311 — la password da riscrivere arriva al riquadro dell\'errore con il messaggio del server', async () => {
        post.mockRejectedValueOnce({ response: { status: 422, data: { success: false, message: 'Riscrivi la password SMTP.' } } });
        const stato = monta();
        stato.destinatario = 'prova@example.com';

        await stato.provaLInvio();

        expect(stato.esito?.andata).toBe(false);
        expect(stato.esito?.motivo?.titolo).toBe('Riscrivi la password SMTP.');
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
        stato.destinatario = 'prova@esempio.it';

        expect(stato.form.data()).toEqual({ ...ricevute, mail_password: '' });

        await stato.provaLInvio();

        expect(post).toHaveBeenCalledWith('/admin.settings.mail.test', { ...ricevute, mail_password: '', test_email: 'prova@esempio.it' });
        expect(stato.esito?.andata).toBe(true);
    });

    test('dopo la prova la riga «Adesso» e le card si ricaricano dal server, senza ricaricare la pagina', async () => {
        reload.mockClear();
        post.mockReset();
        post.mockResolvedValueOnce({ data: { success: true } });
        const stato = monta();
        stato.destinatario = 'prova@esempio.it';

        await stato.provaLInvio();

        expect(reload).toHaveBeenCalledWith({ only: ['caselle', 'invii'] });
    });
});
