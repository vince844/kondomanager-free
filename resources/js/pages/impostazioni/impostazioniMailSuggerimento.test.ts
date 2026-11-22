// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
// @vitest-environment jsdom

/**
 * Coda 121 (decisione 188.4) — sotto il motivo della prova della posta fallita, il suggerimento che il server manda a parte.
 *
 * Quando la connessione al server di posta è rifiutata e la porta non è la 587, la risposta della prova ha, oltre al motivo della libreria (`message`),
 * una frase (`suggerimento`: `tests/Feature/Impostazioni/Beta1_112Giro3PreesistentiPostaTest.php`). Dalla 1.12.0-beta.13 (decisione 572) la prova non
 * andata dice prima il motivo in parole semplici (`motivo`), poi il suggerimento, e sotto, piccolo, il testo del server. Con le altre prove fallite (un
 * tempo scaduto, la 587, la password da riscrivere, il limite delle prove) la risposta non ha la chiave e la casella non scrive niente di più; una prova
 * rifatta non porta con sé il suggerimento della precedente.
 *
 * La casella è quella vera, con il modulo di Inertia vero; si stubba la riga «i»; la richiesta è finta.
 */

import { afterEach, beforeEach, describe, expect, test, vi } from 'vitest';
import { flushPromises, mount, type VueWrapper } from '@vue/test-utils';

const post = vi.fn();

vi.mock('@inertiajs/vue3', async (importOriginal) => ({
    ...(await importOriginal<typeof import('@inertiajs/vue3')>()),
    Head: { template: '<span />' },
    router: { reload: vi.fn() },
    usePage: () => ({ props: { flash: {} } }),
}));
vi.mock('laravel-vue-i18n', () => ({ trans: (chiave: string) => chiave }));
vi.mock('axios', () => ({ default: { post } }));
(globalThis as Record<string, unknown>).route = (nome: string) => `/${nome}`;

const Posta = (await import('@/components/impostazioni/caselle/CasellaDellaPosta.vue')).default;
const { annullaConferma } = await import('@/composables/useConfermaPassword');

const MOTIVO = 'Connection could not be established with host "mail.your-server.de:465": stream_socket_client(): Unable to connect to ssl://mail.your-server.de:465 (Connection refused)';
const SUGGERIMENTO = 'La connessione alla porta 465 è stata rifiutata: o il server di posta non la accetta su quella porta, o le connessioni in uscita su quella porta sono bloccate dal tuo server o dal suo provider (alcuni provider lo fanno per la 25 e la 465). Prova la 587 con la crittografia TLS.';
const RIFIUTATA = { chiave: 'rifiutata', titolo: 'Il server non accetta la connessione su questa porta.', fai: 'Di solito 587 con TLS, oppure 465 con SSL.', breve: '',
    azione: { campo: 'porta', etichetta: 'Cambia la porta' } };
const CASELLA = { stato: 'provata', parola: 'Provata', adesso: 'Adesso: impostata.', riga: null, card: { valore: 'Provata', sotto: '' }, ultima_prova: null,
    sola_lettura: false };

let pagina: VueWrapper | null = null;

function apri() {
    pagina = mount(Posta, {
        attachTo: document.body,
        props: { settings: { mail_enabled: true, mail_driver: 'smtp', mail_host: 'mail.your-server.de', mail_port: 465, mail_encryption: 'ssl' }, passwordSet: true,
            casella: CASELLA, aperta: true },
        global: { stubs: { EtichettaConEsempio: { props: ['testo'], template: '<span>{{ testo }}</span>' } } },
    });

    return pagina;
}

/** Scrive l'indirizzo della prova e preme «Prova l'invio»: la richiesta che la casella manda e il riquadro che compare. */
async function provaLaPosta() {
    await pagina!.find('#posta-prova').setValue('prova@studio.example');
    await pagina!.find('[data-testid="prova-posta"]').trigger('click');
    await flushPromises();
}

const riquadroDellErrore = () => pagina!.find('[data-testid="prova-non-andata"]');
const suggerimentoMostrato = () => pagina!.find('[data-suggerimento-prova]');
const pulito = (testo: string) => testo.replace(/\s+/g, ' ').trim();

beforeEach(() => {
    post.mockReset();
    annullaConferma();
});

afterEach(() => {
    pagina?.unmount();
    pagina = null;
    document.body.innerHTML = '';
});

describe('il suggerimento sotto la prova della posta fallita (Coda 121)', () => {
    test('la connessione rifiutata: il motivo in parole, il suggerimento sotto, il testo del server in fondo', async () => {
        post.mockRejectedValueOnce({ response: { status: 500, data: { success: false, message: MOTIVO, suggerimento: SUGGERIMENTO, motivo: RIFIUTATA } } });
        apri();

        await provaLaPosta();

        const riquadro = riquadroDellErrore();
        expect(riquadro.exists()).toBe(true);
        expect(pulito(riquadro.find('[data-testid="motivo-titolo"]').text())).toBe('Il server non accetta la connessione su questa porta.');
        expect(pulito(suggerimentoMostrato().text())).toBe(SUGGERIMENTO);
        expect(pulito(riquadro.find('[data-testid="testo-del-server"]').text())).toContain(MOTIVO);
        // Il suggerimento sta sotto il motivo, nello stesso riquadro, e sopra il testo del server.
        expect(riquadro.element.contains(suggerimentoMostrato().element)).toBe(true);
        expect(riquadro.text().indexOf('La connessione alla porta 465')).toBeLessThan(riquadro.text().indexOf(MOTIVO));
        expect(riquadro.find('[data-testid="motivo-azione"]').text()).toContain('Cambia la porta');
    });

    test('un\'altra prova fallita (nessuna chiave «suggerimento»): il testo del server si legge, e nessun suggerimento', async () => {
        post.mockRejectedValueOnce({ response: { status: 500, data: { success: false, message: 'Connection to "mail.your-server.de:465" timed out.',
            motivo: { ...RIFIUTATA, chiave: 'tempo', titolo: 'Il server non ha risposto in tempo.' } } } });
        apri();

        await provaLaPosta();

        expect(pulito(riquadroDellErrore().text())).toContain('Connection to "mail.your-server.de:465" timed out.');
        expect(suggerimentoMostrato().exists()).toBe(false);
    });

    test('il limite delle prove (429) e la password da riscrivere (422) non hanno il suggerimento', async () => {
        post.mockRejectedValueOnce({ response: { status: 429, data: { message: 'Too Many Attempts.' } } });
        apri();
        await provaLaPosta();
        expect(pulito(riquadroDellErrore().text())).toContain('Troppe prove in poco tempo.');
        expect(suggerimentoMostrato().exists()).toBe(false);

        post.mockRejectedValueOnce({ response: { status: 422, data: { success: false, message: 'Riscrivi la password SMTP.' } } });
        await provaLaPosta();
        expect(pulito(riquadroDellErrore().text())).toContain('Riscrivi la password SMTP.');
        expect(suggerimentoMostrato().exists()).toBe(false);
    });

    test('una prova rifatta non porta con sé il suggerimento della precedente: né se fallisce in un altro modo, né se riesce', async () => {
        post.mockRejectedValueOnce({ response: { status: 500, data: { success: false, message: MOTIVO, suggerimento: SUGGERIMENTO, motivo: RIFIUTATA } } });
        apri();
        await provaLaPosta();
        expect(suggerimentoMostrato().exists()).toBe(true);

        post.mockRejectedValueOnce({ response: { status: 500, data: { success: false, message: 'Expected response code "250" but got code "535".',
            motivo: { ...RIFIUTATA, chiave: 'accesso', titolo: 'Il server non ha accettato l\'utente o la password.' } } } });
        await provaLaPosta();
        expect(suggerimentoMostrato().exists()).toBe(false);

        post.mockRejectedValueOnce({ response: { status: 500, data: { success: false, message: MOTIVO, suggerimento: SUGGERIMENTO, motivo: RIFIUTATA } } });
        await provaLaPosta();
        expect(suggerimentoMostrato().exists()).toBe(true);

        post.mockResolvedValueOnce({ data: { success: true } });
        await provaLaPosta();
        expect(suggerimentoMostrato().exists()).toBe(false);
        expect(riquadroDellErrore().exists()).toBe(false);
    });

    test('controprova: la prova riuscita dice quando è partita e verso chi, e nessun suggerimento', async () => {
        post.mockResolvedValueOnce({ data: { success: true } });
        apri();

        await provaLaPosta();

        expect(pulito(pagina!.find('[data-testid="prova-andata"]').text())).toMatch(/^La prova è partita il \d\d\/\d\d alle \d\d:\d\d verso prova@studio\.example\.$/);
        expect(suggerimentoMostrato().exists()).toBe(false);
    });
});
