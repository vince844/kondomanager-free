// @vitest-environment jsdom

/**
 * Coda 464 (1) e Coda 465 — la pagina «Audit & Logs»: le pagine del registro delle email si sfogliano, e la scheda «Attività Sistema» non c'è finché
 * non ha dati.
 *
 * Coda 464 (1). I pulsanti della paginazione si disegnavano con `Link` senza che la pagina lo importasse: Vue lo cercava fra le proprietà dell'istanza,
 * non lo trovava, e il pulsante restava un `<button>` con un `href` che non porta da nessuna parte. Chi aveva più di dieci invii nel registro vedeva i
 * numeri di pagina e non poteva usarli. Le etichette di «precedente» e «successiva» arrivano nella lingua dell'applicazione e con le entità HTML
 * («&laquo; Precedente», «Successiva &raquo;»: `tests/Feature/Impostazioni/Beta1_112Giro3PreesistentiLogsTest.php`): la pagina le sostituisce con
 * «<» e «>» dalla posizione, non dalla parola inglese. Sulla prima pagina «precedente» non ha un indirizzo e non è un collegamento (un `<a href="#">`
 * ricaricherebbe la pagina a ogni clic).
 *
 * Coda 465. Il controller mandava `activityLogs` come stringa vuota (la query è commentata) e la scheda leggeva `activityLogs.data.length`: aprendola il
 * render lanciava un `TypeError`. La scheda non c'è più, e il controller non manda più la proprietà: la pagina si disegna con le sole props che il
 * controller manda adesso.
 *
 * La pagina è quella vera, con il layout finto; `Link` è quello di Inertia.
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

/** I link come li costruisce il paginatore con la lingua italiana, sulla prima di due pagine. */
const LINK_PRIMA_PAGINA = [
    { url: null, label: '&laquo; Precedente', active: false },
    { url: 'http://localhost/logs?mail_page=1', label: '1', active: true },
    { url: 'http://localhost/logs?mail_page=2', label: '2', active: false },
    { url: 'http://localhost/logs?mail_page=2', label: 'Successiva &raquo;', active: false },
];
/** E sulla seconda, l'ultima: «successiva» non ha un indirizzo. */
const LINK_ULTIMA_PAGINA = [
    { url: 'http://localhost/logs?mail_page=1', label: '&laquo; Precedente', active: false },
    { url: 'http://localhost/logs?mail_page=1', label: '1', active: false },
    { url: 'http://localhost/logs?mail_page=2', label: '2', active: true },
    { url: null, label: 'Successiva &raquo;', active: false },
];

const righe = (quante: number) => Array.from({ length: quante }, (_, i) => ({
    id: i + 1, recipient: `destinatario${i + 1}@studio.example`, subject: `Avviso ${i + 1}`, mailer: 'smtp', status: 'sent', error_message: null, sent_at: '2026-11-03T10:00:00Z',
}));

let pagina: VueWrapper | null = null;

/** Le props che `LogsController` manda: il registro delle email e la ricerca. Niente `activityLogs`. */
function apri(links: typeof LINK_PRIMA_PAGINA, paginaCorrente = 1) {
    pagina = mount(Registri, {
        props: {
            mailLogs: { data: righe(paginaCorrente === 1 ? 10 : 5), total: 15, current_page: paginaCorrente, last_page: 2, links },
            filters: { search: '' },
        } as never,
        global: {
            stubs: {
                AppLayout: { template: '<div><slot /></div>' },
                PageHeaderGuide: { template: '<div><div>{{ pageTitle }}</div><div>{{ pageSubtitle }}</div><slot /></div>', props: ['pageTitle', 'pageSubtitle'] },
            },
        },
    });

    return pagina;
}

afterEach(() => {
    pagina?.unmount();
    pagina = null;
});

describe('le pagine del registro delle email si sfogliano (Coda 464)', () => {
    test('la pagina successiva è un collegamento vero verso la seconda pagina del registro', () => {
        apri(LINK_PRIMA_PAGINA);

        const verso2 = pagina!.findAll('a[href="http://localhost/logs?mail_page=2"]');

        // Il numero «2» e la freccia «successiva».
        expect(verso2).toHaveLength(2);
        expect(verso2.map((a) => a.text())).toEqual(['2', '>']);
    });

    test('i pulsanti con un indirizzo sono tre collegamenti, e nessuno è un pulsante con un href', () => {
        apri(LINK_PRIMA_PAGINA);

        expect(pagina!.findAll('a')).toHaveLength(3);
        expect(pagina!.findAll('button[href]')).toHaveLength(0);
    });

    test('«precedente» e «successiva» si riconoscono dalla posizione: le etichette italiane diventano «<» e «>», senza entità né parole', () => {
        apri(LINK_PRIMA_PAGINA);

        const pulsanti = pagina!.findAll('[data-slot="button"]');
        const testi = pulsanti.slice(-4).map((p) => p.text());

        expect(testi).toEqual(['<', '1', '2', '>']);
        expect(pagina!.text()).not.toContain('Precedente');
        expect(pagina!.text()).not.toContain('Successiva');
        expect(pagina!.text()).not.toContain('&laquo;');
    });

    test('con le etichette di un\'altra lingua (inglese) «precedente» e «successiva» si riconoscono lo stesso dalla posizione', () => {
        apri([
            { url: null, label: '&laquo; Previous', active: false },
            { url: 'http://localhost/logs?mail_page=1', label: '1', active: true },
            { url: 'http://localhost/logs?mail_page=2', label: '2', active: false },
            { url: 'http://localhost/logs?mail_page=2', label: 'Next &raquo;', active: false },
        ]);

        const testi = pagina!.findAll('[data-slot="button"]').slice(-4).map((p) => p.text());

        expect(testi).toEqual(['<', '1', '2', '>']);
        expect(pagina!.text()).not.toContain('Previous');
        expect(pagina!.text()).not.toContain('Next');
    });

    test('il sottotitolo dice che cosa mostra la pagina: il registro delle email, non più l\'attività di sistema (Coda 465)', () => {
        apri(LINK_PRIMA_PAGINA);

        expect(pagina!.text()).not.toContain('attività di sistema');
        expect(pagina!.text()).toContain('Il registro delle email inviate dal programma');
    });

    test('sulla prima pagina «precedente» non è un collegamento: è un pulsante spento', () => {
        apri(LINK_PRIMA_PAGINA);

        const precedente = pagina!.findAll('[data-slot="button"]').find((p) => p.text() === '<')!;

        expect(precedente.element.tagName).toBe('BUTTON');
        expect(precedente.attributes('disabled')).toBeDefined();
        expect(precedente.attributes('href')).toBeUndefined();
    });

    test('sull\'ultima pagina «successiva» è il pulsante spento, e «precedente» porta alla prima', () => {
        apri(LINK_ULTIMA_PAGINA, 2);

        const pulsanti = pagina!.findAll('[data-slot="button"]').slice(-4);
        const [precedente, , , successiva] = pulsanti;

        expect(precedente.element.tagName).toBe('A');
        expect(precedente.attributes('href')).toBe('http://localhost/logs?mail_page=1');
        expect(successiva.element.tagName).toBe('BUTTON');
        expect(successiva.attributes('disabled')).toBeDefined();
    });

    test('controprova: la pagina attiva è colorata e le altre no, come prima', () => {
        apri(LINK_PRIMA_PAGINA);

        const pulsanti = pagina!.findAll('[data-slot="button"]').slice(-4);

        expect(pulsanti[1].classes().join(' ')).toContain('bg-primary');
        expect(pulsanti[2].classes().join(' ')).not.toContain('bg-primary');
    });

    test('controprova: con una pagina sola (due link) la paginazione non compare', () => {
        pagina = mount(Registri, {
            props: {
                mailLogs: { data: righe(3), total: 3, current_page: 1, last_page: 1, links: [
                    { url: null, label: '&laquo; Precedente', active: false },
                    { url: 'http://localhost/logs?mail_page=1', label: '1', active: true },
                    { url: null, label: 'Successiva &raquo;', active: false },
                ] },
                filters: { search: '' },
            } as never,
            global: {
            stubs: {
                AppLayout: { template: '<div><slot /></div>' },
                PageHeaderGuide: { template: '<div><div>{{ pageTitle }}</div><div>{{ pageSubtitle }}</div><slot /></div>', props: ['pageTitle', 'pageSubtitle'] },
            },
        },
        });

        expect(pagina.text()).not.toContain('Pag. 1 di 1');
        expect(pagina.findAll('a')).toHaveLength(0);
    });
});

describe('la scheda «Attività Sistema» non c\'è finché non ha dati (Coda 465)', () => {
    test('la pagina si disegna con le props che il controller manda adesso: la scheda dei registri delle email e le sue righe', () => {
        apri(LINK_PRIMA_PAGINA);

        const testo = pagina!.text();

        expect(testo).toContain('Audit & Logs');
        expect(testo).toContain('Email Logs');
        expect(testo).toContain('destinatario1@studio.example');
        // Il contatore accanto a «Email Logs» legge il totale del registro delle email.
        expect(pagina!.findAll('nav button')[0].text()).toContain('15');
    });

    test('non c\'è nessun pulsante «Attività Sistema», né il suo contatore, né la sua tabella', () => {
        apri(LINK_PRIMA_PAGINA);

        expect(pagina!.text()).not.toContain('Attività Sistema');
        expect(pagina!.text()).not.toContain('Nessuna attività registrata');
        expect(pagina!.findAll('nav button')).toHaveLength(1);
    });

    test('la prop «activityLogs» non è più fra quelle della pagina', () => {
        apri(LINK_PRIMA_PAGINA);

        const dichiarate = Object.keys((Registri as unknown as { props?: Record<string, unknown> }).props ?? {});

        expect(dichiarate).not.toContain('activityLogs');
    });
});
