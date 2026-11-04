// @vitest-environment jsdom

/**
 * Coda 121 (punto 1, decisione 184) — il registro delle email dice perché un invio è fallito.
 *
 * Il motivo di un invio fallito (`mail_logs.error_message`: «Connection refused», «Validate your sender or authenticate your domain»…) arrivava
 * alla pagina dei registri, nelle props di ogni riga, e la tabella non lo scriveva da nessuna parte: chi cerca il motivo trovava un pallino
 * rosso. Ora la riga fallita lo scrive sotto il destinatario. Le righe riuscite e quelle solo registrate (`logged`, senza driver) non scrivono
 * niente di più, e una riga fallita senza motivo (registrata da una versione più vecchia) resta com'era.
 *
 * Che il motivo sia scritto nel registro delle email e arrivi alla pagina lo prova `tests/Feature/Impostazioni/Beta1_112TestiPostaTest.php`;
 * qui la pagina è quella vera, con il layout finto e le props come le manda `LogsController`.
 */

import { afterEach, describe, expect, test, vi } from 'vitest';
import { mount, type VueWrapper } from '@vue/test-utils';

vi.mock('@inertiajs/vue3', async (importOriginal) => ({
    ...(await importOriginal<typeof import('@inertiajs/vue3')>()),
    Head: { template: '<span />' },
    router: { get: vi.fn() },
}));
(globalThis as Record<string, unknown>).route = (nome: string) => `/${nome}`;

const Registri = (await import('./impostazioniLogs.vue')).default;

const MOTIVO = 'Connection could not be established with host "mail.your-server.de:465": stream_socket_client(): Unable to connect to ssl://mail.your-server.de:465 (Connection refused)';

const RIGHE = [
    { id: 1, recipient: 'riuscita@studio.example', subject: 'Sollecito', mailer: 'smtp', status: 'sent', error_message: null, sent_at: '2026-11-03T10:00:00Z' },
    { id: 2, recipient: 'registrata@studio.example', subject: 'Avviso', mailer: 'log', status: 'logged', error_message: null, sent_at: '2026-11-03T09:00:00Z' },
    { id: 3, recipient: 'fallita@studio.example', subject: 'Test Connessione (Fallito)', mailer: 'smtp', status: 'failed', error_message: MOTIVO, sent_at: '2026-11-03T08:00:00Z' },
    { id: 4, recipient: 'vecchia@studio.example', subject: 'Avviso', mailer: 'smtp', status: 'failed', error_message: null, sent_at: '2026-10-01T08:00:00Z' },
];

let pagina: VueWrapper | null = null;

function apri(righe = RIGHE) {
    pagina = mount(Registri, {
        props: {
            mailLogs: { data: righe, total: righe.length, current_page: 1, last_page: 1, links: [] },
            filters: { search: '' },
        },
        global: { stubs: { AppLayout: { template: '<div><slot /></div>' } } },
    });

    return pagina;
}

const pulisci = (t: string | null | undefined) => (t ?? '').replace(/\s+/g, ' ').trim();
const trDi = (destinatario: string) => {
    const tr = pagina!.findAll('tbody tr').find((r) => r.text().includes(destinatario));
    if (!tr) throw new Error(`nessuna riga per ${destinatario}`);
    return tr;
};
/** Il testo della riga, una cella dopo l'altra (e le righe dentro la cella una dopo l'altra). */
const riga = (destinatario: string) => pulisci(trDi(destinatario).findAll('td').map((td) => td.element.innerHTML.replace(/<[^>]+>/g, ' ')).join(' '));

afterEach(() => {
    pagina?.unmount();
    pagina = null;
});

describe('il registro delle email scrive il motivo degli invii falliti (Coda 121)', () => {
    test('la riga fallita scrive il motivo sotto il destinatario', () => {
        apri();
        expect(riga('fallita@studio.example')).toContain(`fallita@studio.example ${MOTIVO}`);
    });

    test('il testo completo del motivo è anche nel titolo, per quando la cella lo spezza', () => {
        apri();
        const motivo = trDi('fallita@studio.example').find('[data-motivo-invio]');
        expect(motivo.exists()).toBe(true);
        expect(motivo.attributes('title')).toBe(MOTIVO);
    });

    test('la riga riuscita e quella solo registrata non scrivono altro che il destinatario, l\'oggetto, il driver e la data', () => {
        apri();
        expect(trDi('riuscita@studio.example').find('[data-motivo-invio]').exists()).toBe(false);
        expect(trDi('registrata@studio.example').find('[data-motivo-invio]').exists()).toBe(false);
        // Il motivo c'è una volta sola, nella riga che lo ha.
        expect(pagina!.text().split(MOTIVO).length - 1).toBe(1);
    });

    test('controprova: una riga fallita senza motivo (registrata da una versione più vecchia) non scrive niente di più', () => {
        apri();
        // Il destinatario, l'oggetto, il driver e la data (nel formato italiano della pagina).
        expect(riga('vecchia@studio.example')).toMatch(/^vecchia@studio\.example Avviso smtp \d{2}\/\d{2}\/\d{4}/);
        expect(trDi('vecchia@studio.example').find('[data-motivo-invio]').exists()).toBe(false);
    });
});
