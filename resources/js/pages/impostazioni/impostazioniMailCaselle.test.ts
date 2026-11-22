// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
// @vitest-environment jsdom

/**
 * «Configurazione email», l'elenco delle caselle dello studio (decisione 572, bozza approvata `design/impostazioni_posta_pec_bozza.html`), con lo studio di
 * Andrea Costa della bozza.
 *
 * - Le tre card in alto (la posta, la PEC, gli invii del mese con «Apri lo storico»), le due righe con lo stato a parole e a colori, «Serve per» e «Adesso».
 * - La PEC da impostare: che cosa serve, che cosa fa il programma, che cosa resta fuori, l'indirizzo della carta dello studio; «Imposta la casella PEC» apre
 *   la casella con l'indirizzo proposto e l'utente uguale; la cifratura è un `v-select` con SSL e TLS soli; la password non c'è mai, solo «Da impostare».
 * - La prova della PEC va di serie alla stessa casella e passa dalla sua rotta; la prova non andata dice il motivo, l'azione giusta e il testo del server.
 * - Dove la posta la prepara l'installazione, la riga della posta non si apre. Con `apri: 'pec'` la PEC è aperta.
 * - Nei sorgenti nuovi: nessun `<select>` del browser, nessun `v-html`, niente scritte sotto i 12 pixel, nessun badge a pillola.
 *
 * Che cosa non copre: il server (`tests/Feature/Impostazioni/CaselleDelloStudioTest.php`); l'aspetto a video, nel tema scuro e a 375 pixel.
 */
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
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

describe('la pagina delle caselle dello studio', () => {
    test('le tre card della bozza, con «Apri lo storico»', async () => {
        await monta();
        expect(parole(qui('[data-testid="card-posta"]'))).toBe('La posta dello studio Provata segreteria@studiocosta.it · il 12/11 alle 09:30');
        expect(parole(qui('[data-testid="card-pec"]'))).toBe('La casella PEC Da impostare le convocazioni e le lettere per PEC partono da qui');
        expect(parole(qui('[data-testid="card-invii"]'))).toBe('Gli invii di novembre 214 email 2 non partite · nessuna PEC · Apri lo storico');
        expect(qui('[data-testid="card-invii"] a')?.getAttribute('href')).toBe('/logs');
    });

    test('due righe, una per casella, con lo stato a parole e a colori, «Serve per» e «Adesso»', async () => {
        await monta();
        const stati = [...document.body.querySelectorAll('[data-testid="stato-casella"]')];
        expect(stati.map(parole)).toEqual(['Provata', 'Da impostare']);
        expect(stati[0].className).toContain('emerald');
        expect(stati[1].className).toContain('amber');
        expect(stati.every((s) => s.className.includes('rounded-md') && !s.className.includes('rounded-full'))).toBe(true);
        expect(parole(qui('[data-testid="casella-posta"]'))).toContain('Serve per le email normali: avvisi di rata, solleciti, ricevute, comunicazioni, inviti al portale.');
        // 1.12.0-beta.13: la casella PEC serve anche alle lettere a chi è in ritardo mandate per PEC.
        expect(parole(qui('[data-testid="casella-pec"]'))).toContain('Serve per le convocazioni delle assemblee e le lettere a chi è in ritardo che scegli di mandare '
            + 'per PEC (sollecito, diffida, messa in mora). Le email normali non partono mai da qui.');
        expect([...document.body.querySelectorAll('[data-testid="adesso-casella"]')].map(parole)).toEqual([
            'Adesso: impostata, provata il 12/11 alle 09:30. Le email partono a nome di «Studio Costa, amministrazioni».',
            'Adesso: da impostare. Le convocazioni e le lettere per PEC non possono ancora partire dal programma.',
        ]);
    });

    test('la PEC da impostare dice che cosa serve, che cosa fa il programma, che cosa resta fuori, e l\'indirizzo della carta dello studio', async () => {
        await monta();
        const blocchi = parole(qui('[data-testid="pec-da-impostare"]'));
        expect(blocchi).toContain('Che cosa ti serve');
        expect(blocchi).toContain('Che cosa fa il programma');
        expect(blocchi).toContain('La ricevuta di consegna arriva nella casella PEC, dal tuo gestore: il programma non legge quella casella.');
        expect(parole(qui('[data-testid="pec-dalla-carta"]'))).toBe('L\'indirizzo c\'è già nella carta dello studio: andrea.costa@pec.studiocosta.it (Impostazioni › Stampe). '
            + 'Si propone da solo quando apri la casella.');
        expect(parole(qui('[data-testid="pulsante-pec"]'))).toBe('Imposta la casella PEC');
    });

    test('«Imposta la casella PEC» apre la casella: l\'indirizzo dalla carta, l\'utente e la prova uguali, la cifratura SSL o TLS, la password mai', async () => {
        await monta();
        (qui('[data-testid="pulsante-pec"]') as HTMLButtonElement).click();
        await flushPromises();

        expect((qui('#pec-indirizzo') as HTMLInputElement).value).toBe('andrea.costa@pec.studiocosta.it');
        expect(parole(qui('[data-testid="pec-indirizzo-nota"]'))).toBe('Preso dalla carta dello studio (Impostazioni › Stampe). È il mittente delle convocazioni e delle lettere per PEC.');
        expect((qui('#pec-utente') as HTMLInputElement).value).toBe('andrea.costa@pec.studiocosta.it');
        expect((qui('#pec-prova') as HTMLInputElement).value).toBe('andrea.costa@pec.studiocosta.it');
        expect((qui('#pec-porta') as HTMLInputElement).value).toBe('465');
        expect((qui('#pec-password') as HTMLInputElement).value).toBe('');
        expect(parole(qui('[data-testid="stato-password-pec"]'))).toBe('Da impostare');
        expect(parole(qui('[data-testid="pulsante-pec"]'))).toBe('Chiudi');

        // La cifratura è un v-select con due voci: «Nessuna» non c'è.
        const menu = qui('[data-testid="cifratura-pec"]')!;
        expect(menu.classList.contains('v-select')).toBe(true);
        menu.querySelector('.vs__search')!.dispatchEvent(new FocusEvent('focus'));
        await flushPromises();
        expect([...menu.querySelectorAll('.vs__dropdown-option')].map(parole)).toEqual(['SSL con la porta 465', 'TLS con la porta 587']);
        expect(qui('[data-testid="modulo-pec"] select')).toBeNull();

        // Cambiato l'indirizzo, l'utente e l'indirizzo della prova lo seguono.
        const indirizzo = w!.find('#pec-indirizzo');
        await indirizzo.setValue('studio@pec.studiocosta.it');
        expect((qui('#pec-utente') as HTMLInputElement).value).toBe('studio@pec.studiocosta.it');
        expect((qui('#pec-prova') as HTMLInputElement).value).toBe('studio@pec.studiocosta.it');
    });

    test('la prova della PEC va di serie alla stessa casella, passa dalla sua rotta e dice dove guardare le ricevute', async () => {
        post.mockResolvedValueOnce({ data: { success: true, prova: { quando: 'il 19/11 alle 10:14', andata: true, destinatario: 'andrea.costa@pec.studiocosta.it' } } });
        await monta({}, 'pec');
        await w!.find('#pec-server').setValue('smtps.pec.ilgestore.it');
        await w!.find('#pec-password').setValue('segreto-pec');
        await w!.find('[data-testid="prova-pec"]').trigger('click');
        await flushPromises();

        expect(post).toHaveBeenCalledWith('/admin.settings.pec.prova', {
            indirizzo: 'andrea.costa@pec.studiocosta.it', server: 'smtps.pec.ilgestore.it', porta: 465, cifratura: 'ssl', utente: 'andrea.costa@pec.studiocosta.it',
            password: 'segreto-pec', test_email: 'andrea.costa@pec.studiocosta.it',
        });
        expect(parole(qui('[data-testid="prova-andata"]'))).toBe('La prova è partita il 19/11 alle 10:14 e il gestore l\'ha accettata. Nella casella PEC arrivano '
            + 'la ricevuta di accettazione e, poco dopo, quella di consegna: guardale lì.');
        expect(reload).toHaveBeenCalledWith({ only: ['caselle', 'invii'] });
    });

    test('la prova non andata (scena 3): il motivo in parole, che cosa fare, «Riscrivi la password» porta al campo, il testo del server sotto', async () => {
        await monta({ pec: pecNonAndata() });
        const stato = qui('[data-testid="casella-pec"] [data-testid="stato-casella"]')!;
        expect(parole(stato)).toBe('Prova non andata');
        expect(stato.className).toContain('rose');
        const pulsante = qui('[data-testid="pulsante-pec"]') as HTMLButtonElement;
        expect(parole(pulsante)).toBe('Riscrivi la password');

        pulsante.click();
        await flushPromises();
        const nota = qui('[data-testid="modulo-pec"] [data-testid="prova-non-andata"]')!;
        expect(parole(nota.querySelector('[data-testid="motivo-titolo"]'))).toBe('Il gestore non ha accettato l\'utente o la password.');
        expect(parole(nota.querySelector('[data-testid="motivo-fai"]'))).toBe('Riscrivi la password della casella PEC e riprova. Guarda anche l\'utente: di solito è '
            + 'l\'indirizzo PEC intero.');
        expect(parole(nota.querySelector('[data-testid="testo-del-server"]'))).toBe('Il testo del server, per chi ti assiste: 535 5.7.8 Error: authentication failed');
        expect(document.activeElement?.id).toBe('pec-password');
        expect(parole(qui('[data-testid="stato-password-pec"]'))).toBe('Impostata Quella salvata non è stata accettata: scrivine una nuova.');
    });

    test('con la password salvata e un altro server, la casella chiede di riscriverla prima di provare', async () => {
        await monta({ pec: pecDaImpostare({ stato: 'provata', parola: 'Provata', password_impostata: true,
            valori: { indirizzo: 'a@pec.it', server: 'smtps.pec.ilgestore.it', porta: 465, cifratura: 'ssl', utente: 'a@pec.it' } }) }, 'pec');
        expect(qui('[data-testid="password-da-riscrivere"]')).toBeNull();
        await w!.find('#pec-server').setValue('smtps.altro.it');
        expect(parole(qui('[data-testid="password-da-riscrivere"]'))).toBe('Hai cambiato server, porta o cifratura: riscrivi la password.');

        // Salvata con la password nuova, la casella salvata è la nuova: l'avviso sparisce.
        await w!.setProps({ caselle: { posta: posta(), pec: pecDaImpostare({ stato: 'da_provare', parola: 'Da provare', password_impostata: true,
            valori: { indirizzo: 'a@pec.it', server: 'smtps.altro.it', porta: 465, cifratura: 'ssl', utente: 'a@pec.it' } }) } });
        expect(qui('[data-testid="password-da-riscrivere"]')).toBeNull();
    });

    test('dove la posta la prepara l\'installazione, la riga della posta non si apre e lo dice', async () => {
        await monta({ posta: posta({ stato: 'gia_pronta', parola: 'Già pronta', sola_lettura: true, riga: null,
            adesso: 'Adesso: la posta la prepara chi gestisce questa installazione. Le email partono da lì: qui non c\'è niente da impostare.' }) });
        expect(qui('[data-testid="pulsante-posta"]')).toBeNull();
        expect(qui('[data-testid="modulo-posta"]')).toBeNull();
        expect(parole(qui('[data-testid="casella-posta"] [data-testid="adesso-casella"]'))).toContain('qui non c\'è niente da impostare');
        expect(qui('[data-testid="pulsante-pec"]')).not.toBeNull();
    });

    test('la posta aperta: «Cifratura» è un v-select con TLS, SSL e Nessuna, le parole degli standard, la password mai', async () => {
        await monta({}, 'posta');
        const modulo = qui('[data-testid="modulo-posta"]')!;
        expect(modulo.querySelector('select')).toBeNull();
        expect(qui('[data-testid="cifratura-posta"]')!.classList.contains('v-select')).toBe(true);
        const etichette = parole(modulo);
        for (const p of ['Manda la posta da questa casella', 'Come si manda', 'Server di posta in uscita', 'Porta', 'Utente', 'Password', 'Cifratura',
            'Indirizzo del mittente', 'Nome del mittente', 'Salva la posta']) expect(etichette).toContain(p);
        expect(etichette).not.toContain('Username');
        expect(etichette).not.toContain('Crittografia');
        expect((qui('#posta-password') as HTMLInputElement).value).toBe('');
        expect((qui('#posta-prova') as HTMLInputElement).value).toBe('andrea.costa@studiocosta.it');
        expect(parole(qui('[data-testid="stato-password-posta"]'))).toBe('Impostata Non si rilegge mai: per cambiarla, scrivine una nuova.');
    });
});

describe('i sorgenti delle caselle', () => {
    const FILE = ['resources/js/pages/impostazioni/impostazioniMail.vue', 'resources/js/components/impostazioni/caselle/CasellaDellaPosta.vue',
        'resources/js/components/impostazioni/caselle/CasellaPecDelloStudio.vue', 'resources/js/components/impostazioni/caselle/RigaDellaCasella.vue',
        'resources/js/components/impostazioni/caselle/EsitoDellaProva.vue', 'resources/js/components/impostazioni/caselle/EtichettaConEsempio.vue',
        'resources/js/components/guides/MailSetupGuide.vue'];

    test('niente v-html, niente scritte sotto i 12 pixel, nessun badge a pillola, nessun bordo spesso a sinistra', () => {
        for (const f of FILE) {
            const s = readFileSync(resolve(process.cwd(), f), 'utf8');
            expect(s, f).not.toContain('v-html');
            expect(s, f).not.toMatch(/text-\[(9|10|11)px\]/);
            expect(s, f).not.toMatch(/border-l-[2-9]/);
            expect(s, f).not.toMatch(/rounded-full[^"]*(badge|px-2 py-0\.5)/);
        }
    });
});

describe('«Togli la casella PEC», il consiglio della posta, lo storico (decisione 577)', () => {
    const pecProvata = () => pecDaImpostare({
        stato: 'provata', parola: 'Provata', riga: 'andrea.costa@pec.studiocosta.it · smtps.pec.ilgestore.it, porta 465, SSL',
        adesso: 'Adesso: impostata, provata il 19/11 alle 10:14.', password_impostata: true,
        valori: { indirizzo: 'andrea.costa@pec.studiocosta.it', server: 'smtps.pec.ilgestore.it', porta: 465, cifratura: 'ssl', utente: 'andrea.costa@pec.studiocosta.it' },
    });

    test('con la casella impostata «Togli la casella PEC» chiede conferma, poi la toglie con la sua rotta', async () => {
        togli.mockClear();
        await monta({ pec: pecProvata() });
        await w!.find('[data-testid="pulsante-pec"]').trigger('click');
        await w!.find('[data-testid="togli-pec"]').trigger('click');
        await flushPromises();
        expect(document.body.textContent).toContain('Togliere la casella PEC dello studio?');
        expect(document.body.textContent).toContain('La casella torna «Da impostare»: le convocazioni e le lettere per PEC non partono, e «Manda le PEC» lo dice');
        expect(togli).not.toHaveBeenCalled();
        const conferma = [...document.body.querySelectorAll('button')].filter((b) => (b.textContent ?? '').trim() === 'Togli la casella PEC').at(-1)!;
        conferma.click();
        await flushPromises();
        expect(togli).toHaveBeenCalledWith('/admin.settings.pec.destroy', expect.objectContaining({ preserveScroll: true }));
    });

    test('da impostare non c\'è niente da togliere', async () => {
        await monta({ pec: pecDaImpostare() }, 'pec');
        expect(w!.find('[data-testid="togli-pec"]').exists()).toBe(false);
    });

    test('la posta mai provata ha il consiglio, non un allarme', async () => {
        await monta({ posta: posta({ stato: 'da_provare', parola: 'Da provare', consiglio: 'Fai una prova quando puoi: gli invii continuano.' }) });
        expect(w!.find('[data-testid="consiglio-casella"]').text()).toBe('Fai una prova quando puoi: gli invii continuano.');
    });

    test('la prova non andata della PEC rimanda al nome vero della pagina dei registri', () => {
        const sorgente = readFileSync(resolve(process.cwd(), 'resources/js/components/impostazioni/caselle/CasellaPecDelloStudio.vue'), 'utf8').replace(/\s+/g, ' ');
        expect(sorgente).toContain('in «Audit & Logs di Sistema», scheda «Email Logs»');
        expect(sorgente).not.toContain('Impostazioni › Log');
        expect(sorgente).toContain('e il programma ne legge il giorno e l\'ora');
    });
});
