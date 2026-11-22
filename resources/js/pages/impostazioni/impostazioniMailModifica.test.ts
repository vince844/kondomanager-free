// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
// @vitest-environment jsdom

/**
 * «Modifica» c'è sempre su una casella impostata. Il pulsante della riga è quello giusto per lo stato («Prova l'invio» per una casella da provare,
 * «Riscrivi la password» per una prova non andata): quando non è «Modifica», accanto c'è anche «Modifica», che apre i campi senza portare a un campo
 * solo. Prima, con la posta «Da provare», l'unico modo di cambiare il server era premere «Prova l'invio». Una casella da impostare ha solo «Imposta …»,
 * una provata solo «Modifica»; aperta, solo «Chiudi».
 */
import { afterEach, beforeEach, describe, expect, test, vi } from 'vitest';
import { flushPromises, mount, type VueWrapper } from '@vue/test-utils';

const post = vi.fn();
const reload = vi.fn();
const togli = vi.fn();

vi.mock('@inertiajs/vue3', async (importOriginal) => ({
    ...(await importOriginal<typeof import('@inertiajs/vue3')>()),
    Head: { template: '<span />' },
    Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
    router: { reload, delete: togli },
    usePage: () => ({ props: { flash: {}, auth: { user: { email: 'andrea.costa@studiocosta.it' } } } }),
}));
vi.mock('laravel-vue-i18n', () => ({ trans: (chiave: string) => (chiave === 'impostazioni.header.mail_settings_title' ? 'Configurazione email' : chiave) }));
vi.mock('axios', () => ({ default: { post } }));
(globalThis as Record<string, unknown>).route = (nome: string) => `/${nome}`;
(globalThis as unknown as { ResizeObserver: unknown }).ResizeObserver = class { observe() {} unobserve() {} disconnect() {} };

const Pagina = (await import('./impostazioniMail.vue')).default;
const { annullaConferma } = await import('@/composables/useConfermaPassword');

const posta = (piu: Record<string, unknown> = {}) => ({
    stato: 'provata', parola: 'Provata', riga: 'segreteria@studiocosta.it · smtp.studiocosta.it, porta 587, TLS',
    adesso: 'Adesso: impostata, provata il 12/11 alle 09:30. Le email partono a nome di «Studio Costa, amministrazioni».',
    card: { valore: 'Provata', sotto: 'segreteria@studiocosta.it · il 12/11 alle 09:30' }, ultima_prova: null, sola_lettura: false, ...piu,
});
const pecDaImpostare = (piu: Record<string, unknown> = {}) => ({
    stato: 'da_impostare', parola: 'Da impostare', riga: null,
    adesso: 'Adesso: da impostare. Le convocazioni e le lettere per PEC non possono ancora partire dal programma.',
    card: { valore: 'Da impostare', sotto: 'le convocazioni e le lettere per PEC partono da qui' }, ultima_prova: null,
    valori: { indirizzo: null, server: null, porta: 465, cifratura: 'ssl', utente: null }, password_impostata: false,
    dalla_carta: 'andrea.costa@pec.studiocosta.it', ...piu,
});
const pecNonAndata = () => pecDaImpostare({
    stato: 'non_andata', parola: 'Prova non andata', riga: 'andrea.costa@pec.studiocosta.it · smtps.pec.ilgestore.it, porta 465, SSL',
    adesso: 'Adesso: impostata, ma l\'ultima prova, il 19/11 alle 10:02, non è andata: il gestore non ha accettato la password.',
    valori: { indirizzo: 'andrea.costa@pec.studiocosta.it', server: 'smtps.pec.ilgestore.it', porta: 465, cifratura: 'ssl', utente: 'andrea.costa@pec.studiocosta.it' },
    password_impostata: true,
    ultima_prova: { quando: 'il 19/11 alle 10:02', andata: false, destinatario: 'andrea.costa@pec.studiocosta.it', testo: '535 5.7.8 Error: authentication failed',
        motivo: { chiave: 'accesso', titolo: 'Il gestore non ha accettato l\'utente o la password.',
            fai: 'Riscrivi la password della casella PEC e riprova. Guarda anche l\'utente: di solito è l\'indirizzo PEC intero.', breve: '',
            azione: { campo: 'password', etichetta: 'Riscrivi la password' } } },
});
const INVII = { titolo: 'Gli invii di novembre', valore: '214 email', sotto: '2 non partite · nessuna PEC', url: '/logs' };
const SETTINGS = { mail_enabled: true, mail_driver: 'smtp', mail_host: 'smtp.studiocosta.it', mail_port: 587, mail_username: 'segreteria@studiocosta.it',
    mail_encryption: 'tls', mail_from_address: 'segreteria@studiocosta.it', mail_from_name: 'Studio Costa, amministrazioni' };

let w: VueWrapper | null = null;
beforeEach(() => { post.mockReset(); reload.mockClear(); annullaConferma(); });
afterEach(() => { w?.unmount(); w = null; document.body.innerHTML = ''; });

async function monta(caselle: { posta?: unknown; pec?: unknown } = {}, apri: string | null = null) {
    w = mount(Pagina, {
        attachTo: document.body,
        props: { settings: SETTINGS, password_set: true, caselle: { posta: caselle.posta ?? posta(), pec: caselle.pec ?? pecDaImpostare() }, invii: INVII, apri },
        global: { stubs: { AppLayout: { template: '<div><slot /></div>' }, PageHeaderGuide: true, MailSetupGuide: true } },
    });
    await flushPromises();

    return w;
}
/** Il testo come si legge: i pezzi dei singoli elementi separati da uno spazio (`textContent` li attacca). */
const parole = (el: Element | null | undefined) => {
    if (!el) return '';
    const pezzi: string[] = [];
    const nodi = document.createTreeWalker(el, NodeFilter.SHOW_TEXT);
    while (nodi.nextNode()) pezzi.push(nodi.currentNode.textContent ?? '');

    return pezzi.join(' ').replace(/\s+/g, ' ').trim();
};
const qui = (sel: string) => document.body.querySelector(sel);

describe('«Modifica» su una casella impostata', () => {
    test('la posta da provare: «Prova l\'invio» e accanto «Modifica», che apre i campi; aperta resta solo «Chiudi»', async () => {
        await monta({ posta: posta({ stato: 'da_provare', parola: 'Da provare' }) });
        expect(parole(qui('[data-testid="pulsante-posta"]'))).toBe('Prova l\'invio');
        const modifica = qui('[data-testid="modifica-posta"]') as HTMLButtonElement;
        expect(parole(modifica)).toBe('Modifica');
        modifica.click();
        await flushPromises();
        expect(qui('#posta-server')).not.toBeNull();
        expect(parole(qui('[data-testid="pulsante-posta"]'))).toBe('Chiudi');
        expect(qui('[data-testid="modifica-posta"]')).toBeNull();
    });

    test('la PEC con la prova non andata: l\'azione giusta e accanto «Modifica»', async () => {
        await monta({ pec: pecNonAndata() });
        expect(parole(qui('[data-testid="pulsante-pec"]'))).toBe('Riscrivi la password');
        const modifica = qui('[data-testid="modifica-pec"]') as HTMLButtonElement;
        expect(parole(modifica)).toBe('Modifica');
        modifica.click();
        await flushPromises();
        expect(qui('#pec-server')).not.toBeNull();
        expect(parole(qui('[data-testid="pulsante-pec"]'))).toBe('Chiudi');
    });

    test('una casella provata ha solo «Modifica», una da impostare solo «Imposta …»: nessun pulsante doppio', async () => {
        await monta();
        expect(parole(qui('[data-testid="pulsante-posta"]'))).toBe('Modifica');
        expect(qui('[data-testid="modifica-posta"]')).toBeNull();
        expect(parole(qui('[data-testid="pulsante-pec"]'))).toBe('Imposta la casella PEC');
        expect(qui('[data-testid="modifica-pec"]')).toBeNull();
    });
});
