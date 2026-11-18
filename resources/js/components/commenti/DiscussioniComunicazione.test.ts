// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
// @vitest-environment jsdom

/**
 * Commenti alle comunicazioni: ciò che il componente delle discussioni manda al server (Coda 559).
 *
 * I test Pest chiamano le rotte da soli, con i campi giusti: non vedono che cosa manda la pagina. Qui si monta il
 * componente vero e si guarda la richiesta.
 *
 * - Il modulo di una discussione manda `corpo` e `discussione` (la chiave di quella discussione: `tutti` per la discussione
 *   unica dei palazzi, decisione 458, o `persona:<id>`) alla rotta `<porta>.comunicazioni.commenti.store` della comunicazione.
 * - Una discussione in cui non si può scrivere non ha il modulo.
 * - I pulsanti di un commento seguono le `azioni` del server, non chi è l'autore.
 */

import { beforeEach, describe, expect, test, vi } from 'vitest';
import { flushPromises, mount } from '@vue/test-utils';
import { router } from '@inertiajs/core';

vi.mock('laravel-vue-i18n', () => ({
    trans: (chiave: string) => chiave,
    transChoice: (chiave: string, numero: number) => `${chiave}#${numero}`,
    wTrans: (chiave: string) => ({ value: chiave }),
    isLoaded: () => true,
    loadLanguageAsync: async () => undefined,
}));

(globalThis as unknown as { route: unknown }).route = (nome: string, parametri?: unknown) =>
    `/${nome}/${JSON.stringify(parametri ?? null)}`;

const DiscussioniComunicazione = (await import('./DiscussioniComunicazione.vue')).default;

function commento(id: number, corpo: string, azioni: Partial<Record<'modifica' | 'elimina' | 'approva' | 'nascondi', boolean>> = {}) {
    return {
        id,
        corpo,
        stato: 'pubblicato',
        autore: { nome: 'Paolo Neri' },
        created_at: '2026-11-10T09:00:00.000Z',
        modificato: false,
        azioni: { modifica: false, elimina: false, approva: false, nascondi: false, ...azioni },
    };
}

/** La discussione unica di una comunicazione ai palazzi, come la manda il server (decisione 458). */
function unica(campi: Record<string, unknown> = {}) {
    return { chiave: 'tutti', tipo: 'tutti', titolo: '', puo_scrivere: true, commenti: [], ...campi };
}

function monta(discussioni: unknown[], porta: 'admin' | 'user' = 'user') {
    return mount(DiscussioniComunicazione, {
        props: {
            comunicazioneId: 7,
            porta,
            discussioni,
            config: { can_comment: true, commenti_moderati: false, puo_impostare: false, puo_moderare: false },
        },
        global: {
            mocks: { route: (globalThis as unknown as { route: unknown }).route },
        },
        attachTo: document.body,
    });
}

/**
 * Decisione 441: il modulo è quello delle segnalazioni, chiuso di serie nella riga tratteggiata. Per scrivere si apre
 * (prima era sempre aperto): il clic sulla riga mostra il campo e il modulo vero, quello che si invia.
 */
async function apriModulo(wrapper: ReturnType<typeof monta>, chiave: string) {
    const radice = wrapper.find(`[data-discussione="${chiave}"]`);
    expect(radice.exists(), `Manca il modulo della discussione ${chiave}`).toBe(true);
    await radice.find('button').trigger('click');
    const modulo = radice.find('form');
    expect(modulo.exists(), 'Il clic sulla riga non ha aperto il modulo').toBe(true);

    return modulo;
}

/** La richiesta mandata, che il componente usi `router.post` o un `useForm` che passa da `router.visit`. */
function richiestaMandata(post: ReturnType<typeof vi.spyOn>, visit: ReturnType<typeof vi.spyOn>): { url: string; dati: Record<string, unknown> } | null {
    if (post.mock.calls.length > 0) {
        const [url, dati] = post.mock.calls[0] as [string, Record<string, unknown>];
        return { url: String(url), dati: { ...dati } };
    }
    if (visit.mock.calls.length > 0) {
        const [url, opzioni] = visit.mock.calls[0] as [string, { method?: string; data?: Record<string, unknown> }];
        expect(String(opzioni?.method ?? '').toLowerCase()).toBe('post');
        return { url: String(url), dati: { ...(opzioni?.data ?? {}) } };
    }

    return null;
}

beforeEach(() => {
    vi.restoreAllMocks();
    document.body.innerHTML = '';
});

describe('Commenti alle comunicazioni: le discussioni (Coda 559)', () => {
    test('il modulo della discussione unica manda «corpo» e la sua chiave alla rotta della comunicazione, nella porta giusta, senza titolo', async () => {
        const post = vi.spyOn(router, 'post').mockImplementation(() => undefined as never);
        const visit = vi.spyOn(router, 'visit').mockImplementation(() => undefined as never);
        const wrapper = monta([unica()]);

        // Una comunicazione a più condomìni ha una discussione sola, senza il titolo di un condominio (decisione 458).
        expect(wrapper.find('h4').exists()).toBe(false);

        const modulo = await apriModulo(wrapper, 'tutti');
        await modulo.find('textarea[name="corpo"]').setValue('Il portone resta aperto?');
        await modulo.trigger('submit');
        await flushPromises();

        const richiesta = richiestaMandata(post, visit);
        expect(richiesta, 'Il modulo non ha mandato niente').not.toBeNull();
        expect(richiesta!.url).toContain('user.comunicazioni.commenti.store');
        expect(richiesta!.url).toContain('7');
        expect(richiesta!.dati).toMatchObject({ corpo: 'Il portone resta aperto?', discussione: 'tutti' });
    });

    test('dal pannello la richiesta va alla rotta del pannello', async () => {
        const post = vi.spyOn(router, 'post').mockImplementation(() => undefined as never);
        const visit = vi.spyOn(router, 'visit').mockImplementation(() => undefined as never);
        const wrapper = monta([{ chiave: 'persona:34', titolo: 'Lucia Verdi', puo_scrivere: true, commenti: [] }], 'admin');

        const modulo = await apriModulo(wrapper, 'persona:34');
        await modulo.find('textarea[name="corpo"]').setValue('Ci mandi la ricevuta');
        await modulo.trigger('submit');
        await flushPromises();

        const richiesta = richiestaMandata(post, visit);
        expect(richiesta!.url).toContain('admin.comunicazioni.commenti.store');
        expect(richiesta!.dati).toMatchObject({ corpo: 'Ci mandi la ricevuta', discussione: 'persona:34' });
    });

    test('una discussione in cui non si può scrivere non ha il modulo, e i suoi commenti si leggono', () => {
        const wrapper = monta([unica({ puo_scrivere: false, commenti: [commento(5, 'Scritto prima')] })]);

        expect(wrapper.find('[data-discussione="tutti"]').exists()).toBe(false);
        expect(wrapper.text()).toContain('Scritto prima');
    });

    test('i pulsanti di un commento seguono le azioni del server', () => {
        const wrapper = monta([
            unica({ commenti: [commento(5, 'Senza azioni'), commento(6, 'Con le azioni', { modifica: true, elimina: true })] }),
        ]);

        const pulsantiDi = (testo: string) => {
            const elementi = wrapper.findAll('[data-commento]');
            const elemento = elementi.find((e) => e.text().includes(testo));
            expect(elemento, `Manca il commento «${testo}» con l'attributo data-commento`).toBeDefined();

            return elemento!.findAll('button').length;
        };

        expect(pulsantiDi('Senza azioni')).toBe(0);
        expect(pulsantiDi('Con le azioni')).toBeGreaterThan(0);
    });

    test('decisione 441: il modulo nasce chiuso, si apre con il contatore, «Annulla» e il pulsante nero, e senza la scritta a 11 px', async () => {
        const wrapper = monta([unica()]);

        expect(wrapper.find('textarea').exists()).toBe(false);
        expect(wrapper.text()).toContain('commenti.scrivi_commento');

        const modulo = await apriModulo(wrapper, 'tutti');
        expect(modulo.text()).toContain('0/5000');
        expect(modulo.text()).toContain('commenti.cancel');
        expect(modulo.find('button[type="submit"]').classes()).toContain('bg-slate-900');
        expect(modulo.find('button[type="submit"]').classes()).toContain('dark:bg-slate-100');
        expect(wrapper.text()).not.toContain('commenti.invio_con_scorciatoia');
        expect(wrapper.html()).not.toContain('text-[11px]');
    });

    test('decisione 441: Ctrl+Invio e Cmd+Invio mandano il commento alla discussione giusta; oltre 5.000 caratteri no', async () => {
        const post = vi.spyOn(router, 'post').mockImplementation(() => undefined as never);
        const visit = vi.spyOn(router, 'visit').mockImplementation(() => undefined as never);
        const wrapper = monta([unica()]);

        const modulo = await apriModulo(wrapper, 'tutti');
        const campo = modulo.find('textarea[name="corpo"]');
        await campo.setValue('x'.repeat(5001));
        await campo.trigger('keydown', { key: 'Enter', ctrlKey: true });
        expect(richiestaMandata(post, visit), 'Oltre 5.000 caratteri non si invia').toBeNull();

        await campo.setValue('Va bene così');
        await campo.trigger('keydown', { key: 'Enter', metaKey: true });
        await flushPromises();
        const richiesta = richiestaMandata(post, visit);
        expect(richiesta!.url).toContain('user.comunicazioni.commenti.store');
        expect(richiesta!.dati).toMatchObject({ corpo: 'Va bene così', discussione: 'tutti' });
    });

    // Fase 1-bis della 1.12.0-beta.11: le due prove sopra non provavano Ctrl+Invio con un testo che parte, né il fuoco dopo il
    // clic sulla riga tratteggiata (la prova del fuoco passava da «Scrivi» della discussione chiusa).
    test('Ctrl+Invio, da solo, manda un commento valido', async () => {
        const post = vi.spyOn(router, 'post').mockImplementation(() => undefined as never);
        const visit = vi.spyOn(router, 'visit').mockImplementation(() => undefined as never);
        const wrapper = monta([unica()]);

        const modulo = await apriModulo(wrapper, 'tutti');
        const campo = modulo.find('textarea[name="corpo"]');
        await campo.setValue('Arrivo alle nove');
        await campo.trigger('keydown', { key: 'Enter', ctrlKey: true });
        await flushPromises();

        const richiesta = richiestaMandata(post, visit);
        expect(richiesta, 'Ctrl+Invio non ha mandato niente').not.toBeNull();
        expect(richiesta!.dati).toMatchObject({ corpo: 'Arrivo alle nove', discussione: 'tutti' });
    });

    test('il clic sulla riga tratteggiata apre il modulo con il fuoco nel campo', async () => {
        const wrapper = monta([unica()]);

        const modulo = await apriModulo(wrapper, 'tutti');
        await flushPromises();

        expect(document.activeElement).toBe(modulo.find('textarea[name="corpo"]').element);
    });
});
