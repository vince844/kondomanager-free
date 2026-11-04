// @vitest-environment jsdom

/**
 * Coda 121 (decisione 188.4) — sotto il motivo della prova della posta fallita, il suggerimento che il server manda a parte.
 *
 * Quando la connessione al server di posta è rifiutata e la porta non è la 587, la risposta della prova ha, oltre al motivo della libreria (`message`),
 * una frase (`suggerimento`: `tests/Feature/Impostazioni/Beta1_112Giro3PreesistentiPostaTest.php`). La pagina la scrive sotto il motivo, nel riquadro
 * dell'errore. Con le altre prove fallite (un tempo scaduto, la 587, la password da riscrivere, il limite delle prove) la risposta non ha la chiave e la
 * pagina non scrive niente di più; una prova rifatta non porta con sé il suggerimento della precedente.
 *
 * La pagina è quella vera, con il modulo di Inertia vero; si stubbano il layout, la guida in testa e la guida di configurazione; la richiesta è finta.
 */

import { afterEach, beforeEach, describe, expect, test, vi } from 'vitest';
import { flushPromises, mount, type VueWrapper } from '@vue/test-utils';

const post = vi.fn();

vi.mock('@inertiajs/vue3', async (importOriginal) => ({
    ...(await importOriginal<typeof import('@inertiajs/vue3')>()),
    Head: { template: '<span />' },
    usePage: () => ({ props: { flash: {} } }),
}));
vi.mock('laravel-vue-i18n', () => ({ trans: (chiave: string) => chiave }));
vi.mock('axios', () => ({ default: { post } }));
(globalThis as Record<string, unknown>).route = (nome: string) => `/${nome}`;

const PaginaPosta = (await import('./impostazioniMail.vue')).default;
const { annullaConferma } = await import('@/composables/useConfermaPassword');

const MOTIVO = 'Connection could not be established with host "mail.your-server.de:465": stream_socket_client(): Unable to connect to ssl://mail.your-server.de:465 (Connection refused)';
const SUGGERIMENTO = 'La connessione alla porta 465 è stata rifiutata: o il server di posta non la accetta su quella porta, o le connessioni in uscita su quella porta sono bloccate dal tuo server o dal suo provider (alcuni provider lo fanno per la 25 e la 465). Prova la 587 con la crittografia TLS.';

let pagina: VueWrapper | null = null;

function apri() {
    pagina = mount(PaginaPosta, {
        props: { settings: { mail_enabled: true, mail_driver: 'smtp', mail_host: 'mail.your-server.de', mail_port: 465, mail_encryption: 'ssl' }, password_set: true },
        global: { stubs: { AppLayout: { template: '<div><slot /></div>' }, PageHeaderGuide: true, MailSetupGuide: true } },
    });

    return pagina;
}

/** Scrive l'indirizzo della prova e preme «Invia prova»: la richiesta che la pagina manda e il riquadro che compare. */
async function provaLaPosta() {
    await pagina!.find('input[placeholder="impostazioni.placeholder.test_recipient"]').setValue('prova@studio.example');
    const pulsante = pagina!.findAll('button').find((b) => b.text().includes('impostazioni.label.send_test'))!;
    await pulsante.trigger('click');
    await flushPromises();
}

const riquadroDellErrore = () => pagina!.findAll('div').find((d) => d.text().includes('impostazioni.dialogs.test_error_title') && d.classes().includes('border-rose-200'));
const suggerimentoMostrato = () => pagina!.find('[data-suggerimento-prova]');
const pulito = (testo: string) => testo.replace(/\s+/g, ' ').trim();

beforeEach(() => {
    post.mockReset();
    annullaConferma();
});

afterEach(() => {
    pagina?.unmount();
    pagina = null;
});

describe('il suggerimento sotto la prova della posta fallita (Coda 121)', () => {
    test('la connessione rifiutata: sotto il motivo della libreria compare il suggerimento', async () => {
        post.mockRejectedValueOnce({ response: { status: 500, data: { success: false, message: MOTIVO, suggerimento: SUGGERIMENTO } } });
        apri();

        await provaLaPosta();

        const riquadro = riquadroDellErrore()!;
        expect(riquadro).toBeDefined();
        expect(pulito(riquadro.text())).toContain(MOTIVO);
        expect(pulito(suggerimentoMostrato().text())).toBe(SUGGERIMENTO);
        // Il suggerimento sta sotto il motivo, nello stesso riquadro.
        expect(riquadro.element.contains(suggerimentoMostrato().element)).toBe(true);
        expect(riquadro.text().indexOf(MOTIVO)).toBeLessThan(riquadro.text().indexOf('La connessione alla porta 465'));
    });

    test('un\'altra prova fallita (nessuna chiave «suggerimento»): il motivo si legge, e nessun suggerimento', async () => {
        post.mockRejectedValueOnce({ response: { status: 500, data: { success: false, message: 'Connection to "mail.your-server.de:465" timed out.' } } });
        apri();

        await provaLaPosta();

        expect(pulito(riquadroDellErrore()!.text())).toContain('Connection to "mail.your-server.de:465" timed out.');
        expect(suggerimentoMostrato().exists()).toBe(false);
    });

    test('il limite delle prove (429) e la password da riscrivere (422) non hanno il suggerimento', async () => {
        post.mockRejectedValueOnce({ response: { status: 429, data: { message: 'Too Many Attempts.' } } });
        apri();
        await provaLaPosta();
        expect(pulito(riquadroDellErrore()!.text())).toContain('impostazioni.error_test_mail_too_many');
        expect(suggerimentoMostrato().exists()).toBe(false);

        post.mockRejectedValueOnce({ response: { status: 422, data: { success: false, message: 'Riscrivi la password SMTP.' } } });
        await provaLaPosta();
        expect(pulito(riquadroDellErrore()!.text())).toContain('Riscrivi la password SMTP.');
        expect(suggerimentoMostrato().exists()).toBe(false);
    });

    test('una prova rifatta non porta con sé il suggerimento della precedente: né se fallisce in un altro modo, né se riesce', async () => {
        post.mockRejectedValueOnce({ response: { status: 500, data: { success: false, message: MOTIVO, suggerimento: SUGGERIMENTO } } });
        apri();
        await provaLaPosta();
        expect(suggerimentoMostrato().exists()).toBe(true);

        post.mockRejectedValueOnce({ response: { status: 500, data: { success: false, message: 'Expected response code "250" but got code "535".' } } });
        await provaLaPosta();
        expect(suggerimentoMostrato().exists()).toBe(false);

        post.mockRejectedValueOnce({ response: { status: 500, data: { success: false, message: MOTIVO, suggerimento: SUGGERIMENTO } } });
        await provaLaPosta();
        expect(suggerimentoMostrato().exists()).toBe(true);

        post.mockResolvedValueOnce({ data: { success: true } });
        await provaLaPosta();
        expect(suggerimentoMostrato().exists()).toBe(false);
        expect(riquadroDellErrore()).toBeUndefined();
    });

    test('controprova: la prova riuscita scrive il messaggio di riuscita e nessun suggerimento', async () => {
        post.mockResolvedValueOnce({ data: { success: true } });
        apri();

        await provaLaPosta();

        expect(pagina!.text()).toContain('impostazioni.dialogs.test_success_message');
        expect(suggerimentoMostrato().exists()).toBe(false);
    });
});
