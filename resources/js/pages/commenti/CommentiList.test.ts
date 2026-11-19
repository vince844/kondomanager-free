// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
// @vitest-environment jsdom

/**
 * La pagina «Commenti» del pannello (Coda 657, decisione 442): la stessa pagina degli allegati, con i commenti.
 *
 * - La disposizione: il riquadro dei filtri, la tabella con le colonne nell'ordine della bozza, i pulsanti in blocco che si
 *   accendono con una casella; niente «Scarica .zip».
 * - Ogni voce del menu «…» c'è solo se la riga dice che chi guarda può.
 * - Il contratto con il server: che cosa manda e a quale rotta per ogni azione, con le visite parziali (`only`) dei filtri.
 */

import { beforeEach, describe, expect, test, vi } from 'vitest';
import { mount } from '@vue/test-utils';
import { router } from '@inertiajs/core';

const pagina = vi.hoisted(() => ({
    url: '/admin/commenti',
    props: {
        auth: { user: { id: 1, name: 'Amministratore', roles: ['amministratore'], permissions: ['Accesso pannello amministratore'] } },
        flash: {},
    } as Record<string, unknown>,
}));

vi.mock('@inertiajs/vue3', async (importOriginal) => ({
    ...(await importOriginal<typeof import('@inertiajs/vue3')>()),
    Head: { template: '<div />' },
    Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
    usePage: () => pagina,
}));

vi.mock('laravel-vue-i18n', () => ({
    trans: (chiave: string) => chiave,
    transChoice: (chiave: string, numero: number) => `${chiave}#${numero}`,
    wTrans: (chiave: string) => ({ value: chiave }),
    isLoaded: () => true,
    loadLanguageAsync: async () => undefined,
}));

const chiamateRoute: Array<{ nome: string; parametri?: unknown }> = [];
const routeMock = (nome: string, parametri?: unknown) => {
    chiamateRoute.push({ nome, parametri });
    if (parametri && typeof parametri === 'object') {
        const qs = new URLSearchParams(
            Object.entries(parametri as Record<string, unknown>)
                .filter(([, v]) => v !== undefined && v !== null && v !== '')
                .map(([k, v]) => [k, String(v)])
        ).toString();
        return `/${nome.replace(/\./g, '/')}${qs ? '?' + qs : ''}`;
    }
    if (parametri !== undefined && parametri !== null) {
        return `/${nome.replace(/\./g, '/')}/${parametri}`;
    }
    return `/${nome.replace(/\./g, '/')}`;
};
(globalThis as unknown as { route: unknown }).route = routeMock;

const CommentiList = (await import('./CommentiList.vue')).default;

const riga = (id: number, extra: Record<string, unknown> = {}) => ({
    id,
    testo: `Il testo del commento ${id}`,
    tipo: 'segnalazioni' as const,
    elemento: 'Il cancello non chiude',
    url_elemento: '/admin/segnalazioni/5',
    url_commenti: '/admin/segnalazioni/5#commenti',
    condominio: 'Condominio Aurora',
    autore: 'Mario Rossi',
    creato_il: '2026-11-17T10:00:00.000Z',
    modificato: false,
    stato: 'in_attesa' as const,
    puo_rispondere: true,
    rispondi: { url: '/admin/segnalazioni/5/commenti', dati: {} },
    puo_modificare: true,
    puo_approvare: true,
    puo_nascondere: false,
    puo_mostrare: false,
    puo_eliminare: true,
    ...extra,
});

function montaPagina(righe = [riga(10), riga(11, { stato: 'pubblicato', puo_approvare: false, puo_nascondere: true })], filtriOverride = {}) {
    return mount(CommentiList, {
        props: {
            commenti: { data: righe, current_page: 2, last_page: 3, per_page: 10, total: 25, links: [] },
            filtri: { stato: '', tipo: '', condominio_id: null, autore: '', dal: '', al: '', ...filtriOverride },
            condomini: [{ id: 1, nome: 'Condominio Aurora' }],
        },
        global: { stubs: { AppLayout: { template: '<div><slot /></div>' } } },
    });
}

type Vm = {
    approvaSingolo: (r: unknown) => void;
    occultamento: { chiedi: (r: unknown) => void; conferma: (f: (r: never) => void) => void };
    eliminazione: { chiedi: (r: unknown) => void; conferma: (f: (r: never) => void) => void };
    confermaNascondiSingolo: (r: never) => void;
    confermaEliminaSingolo: (r: never) => void;
    apriRisposta: (r: unknown) => void;
    apriModifica: (r: unknown) => void;
    salvaModifica: () => void;
    formModifica: { corpo: string };
    applicaFiltri: () => void;
    cambiaPerPage: (n: number) => void;
    vaiAPagina: (n: number) => void;
    selezionati: number[];
    statoFiltro: Record<string, string>;
    approvaInBlocco: () => void;
    confermaNascondiInBlocco: () => void;
    mostraDiNuovoSingolo: (r: unknown) => void;
    mostraDiNuovoInBlocco: () => void;
    confermaEliminaInBlocco: () => void;
    apertaRisposta: boolean;
    apertaModifica: boolean;
};

beforeEach(() => {
    chiamateRoute.length = 0;
    vi.spyOn(router, 'post').mockImplementation(() => undefined as never);
    vi.spyOn(router, 'get').mockImplementation(() => undefined as never);
    vi.spyOn(router, 'delete').mockImplementation(() => undefined as never);
    vi.spyOn(router, 'visit').mockImplementation(() => undefined as never);
    vi.mocked(router.post).mockClear();
    vi.mocked(router.get).mockClear();
    vi.mocked(router.delete).mockClear();
    vi.mocked(router.visit).mockClear();
});

describe('CommentiList — la disposizione, quella della pagina degli allegati', () => {
    test('il riquadro dei filtri ha Stato, Tipo, Condominio, Autore, Dal, Al e, con un filtro acceso, «Azzera filtri»; niente «Scarica .zip»', () => {
        const senza = montaPagina();
        const testo = senza.text();
        for (const chiave of ['status', 'type', 'condominio', 'author', 'date_from', 'date_to']) {
            expect(testo).toContain(`commenti_pagina.filters.${chiave}`);
        }
        expect(testo).not.toContain('commenti_pagina.filters.reset');
        expect(senza.find('a[href*="zip"]').exists()).toBe(false);
        expect(testo).not.toMatch(/zip/i);

        expect(montaPagina(undefined, { stato: 'nascosto' }).text()).toContain('commenti_pagina.filters.reset');
    });

    test('la tabella ha le colonne nell\'ordine della bozza: casella, Commento, Elemento, Condominio, Autore, Data, Stato, Azioni', () => {
        const teste = montaPagina().findAll('thead th');

        expect(teste).toHaveLength(8);
        expect(teste[0].find('[role="checkbox"], button').exists()).toBe(true);
        expect(teste.slice(1).map((t) => t.text().replace(/\s+/g, ' ').trim())).toEqual([
            'commenti_pagina.table.comment',
            'commenti_pagina.table.element',
            'commenti_pagina.table.condominio',
            'commenti_pagina.table.author',
            'commenti_pagina.table.date',
            'commenti_pagina.table.status',
            'commenti_pagina.table.actions',
        ]);
    });

    test('ogni riga dice il testo, l\'elemento col collegamento, il condominio, l\'autore e lo stato; nessuna scritta sotto i 12 px', () => {
        const w = montaPagina();
        const prima = w.findAll('tbody tr')[0];

        expect(prima.text()).toContain('Il testo del commento 10');
        expect(prima.text()).toContain('Il cancello non chiude');
        expect(prima.find('a[href="/admin/segnalazioni/5"]').exists()).toBe(true);
        expect(prima.text()).toContain('Condominio Aurora');
        expect(prima.text()).toContain('Mario Rossi');
        expect(prima.text()).toContain('commenti_pagina.pending_badge');
        expect(w.findAll('tbody tr')[1].text()).toContain('commenti_pagina.published_badge');

        // Il titolo della pagina è il componente comune delle intestazioni (lo stesso degli allegati): qui si guarda il riquadro.
        const html = w.find('section').html();
        expect(html).not.toMatch(/text-\[(?:[0-9]|10|11)px\]/);
        expect(html).not.toMatch(/border-l-/);
    });

    test('una discussione a persone ha il condominio nella colonna e «discussione con …» sotto il titolo (decisione 479)', () => {
        const w = montaPagina([
            riga(20, { tipo: 'comunicazioni', elemento: 'Verifica dei contatori', condominio: 'Condominio Borea', discussione_con: 'Lucia Verdi' }),
            riga(21, { discussione_con: null }),
        ]);
        const [persone, segnalazione] = w.findAll('tbody tr');

        expect(persone.get('[data-discussione-con]').text()).toBe('commenti_pagina.discussione_con');
        expect(persone.text()).toContain('Condominio Borea');
        expect(segnalazione.find('[data-discussione-con]').exists()).toBe(false);
    });

    test('un commento nascosto ha il suo badge, senza bordi tondi a pillola', () => {
        const w = montaPagina([riga(12, { stato: 'nascosto', puo_approvare: false })]);

        expect(w.text()).toContain('commenti_pagina.hidden_badge');
        expect(w.html()).not.toContain('rounded-full');
    });

    test('i pulsanti in blocco si accendono con una casella e dipendono da ciò che le righe scelte consentono', async () => {
        const w = montaPagina();
        expect(w.find('[data-barra-blocco]').exists()).toBe(false);

        (w.vm as unknown as Vm).selezionati = [10];
        await w.vm.$nextTick();
        expect(w.find('[data-azione-blocco="approva"]').exists()).toBe(true);
        expect(w.find('[data-azione-blocco="elimina"]').exists()).toBe(true);
        expect(w.find('[data-azione-blocco="nascondi"]').exists()).toBe(false);

        (w.vm as unknown as Vm).selezionati = [10, 11];
        await w.vm.$nextTick();
        expect(w.find('[data-azione-blocco="approva"]').exists()).toBe(true);
        expect(w.find('[data-azione-blocco="nascondi"]').exists()).toBe(true);
    });

    test('senza commenti dice che non ce ne sono; con un filtro acceso, che nessuno corrisponde', () => {
        expect(montaPagina([]).text()).toContain('commenti_pagina.empty');
        expect(montaPagina([], { stato: 'nascosto' }).text()).toContain('commenti_pagina.empty_filtered_title');
    });
});

describe('CommentiList — il contratto con il server', () => {
    test('i filtri e la paginazione sono visite parziali che tengono lo stato e lo scorrimento, e chiedono solo l\'elenco', () => {
        const w = montaPagina();
        const vm = w.vm as unknown as Vm;
        vm.statoFiltro.stato = 'nascosto';
        vm.statoFiltro.tipo = 'comunicazioni';
        vm.applicaFiltri();

        expect(vi.mocked(router.get)).toHaveBeenLastCalledWith(
            '/admin/commenti/index',
            { stato: 'nascosto', tipo: 'comunicazioni', per_page: 10, page: 1 },
            { preserveState: true, preserveScroll: true, only: ['commenti', 'filtri'] }
        );
        expect(chiamateRoute.some((c) => c.nome === 'admin.commenti.index')).toBe(true);

        vm.vaiAPagina(3);
        expect(vi.mocked(router.get).mock.lastCall?.[1]).toMatchObject({ page: 3, per_page: 10 });
        expect(vi.mocked(router.get).mock.lastCall?.[2]).toMatchObject({ preserveState: true, preserveScroll: true, only: ['commenti', 'filtri'] });

        vm.cambiaPerPage(25);
        expect(vi.mocked(router.get).mock.lastCall?.[1]).toMatchObject({ per_page: 25, page: 1 });
    });

    test('«Approva» sulla riga usa la rotta della scheda e aggiorna solo l\'elenco', () => {
        const w = montaPagina();
        (w.vm as unknown as Vm).approvaSingolo(riga(10));

        expect(vi.mocked(router.post)).toHaveBeenCalledWith(
            '/admin/commenti/approva/10',
            {},
            expect.objectContaining({ preserveScroll: true, only: ['commenti', 'flash'] })
        );
        expect(chiamateRoute.some((c) => c.nome === 'admin.commenti.approva' && c.parametri === 10)).toBe(true);
    });

    test('«Nascondi» e «Elimina» chiedono conferma, e poi usano le rotte della scheda', () => {
        const w = montaPagina();
        const vm = w.vm as unknown as Vm;
        const r = riga(11, { stato: 'pubblicato' });

        vm.occultamento.chiedi(r);
        expect(vi.mocked(router.post)).not.toHaveBeenCalled();
        vm.occultamento.conferma(vm.confermaNascondiSingolo);
        expect(vi.mocked(router.post)).toHaveBeenCalledWith(
            '/admin/commenti/modera/11',
            {},
            expect.objectContaining({ preserveScroll: true, only: ['commenti', 'flash'] })
        );

        vm.eliminazione.chiedi(r);
        expect(vi.mocked(router.delete)).not.toHaveBeenCalled();
        vm.eliminazione.conferma(vm.confermaEliminaSingolo);
        expect(vi.mocked(router.delete)).toHaveBeenCalledWith(
            '/admin/commenti/destroy/11',
            expect.objectContaining({ preserveScroll: true, only: ['commenti', 'flash'] })
        );
    });

    test('«Modifica» manda il testo con PATCH alla rotta della scheda', () => {
        const w = montaPagina();
        const vm = w.vm as unknown as Vm;

        vm.apriModifica(riga(10));
        expect(vm.apertaModifica).toBe(true);
        expect(vm.formModifica.corpo).toBe('Il testo del commento 10');
        vm.formModifica.corpo = 'Il testo corretto';
        vm.salvaModifica();

        expect(vi.mocked(router.visit)).toHaveBeenCalledWith(
            '/admin/commenti/update/10',
            expect.objectContaining({ method: 'patch', preserveScroll: true, only: ['commenti', 'flash'] })
        );
    });

    test('«Rispondi» apre la finestra col modulo dei commenti già aperto, che scrive alla rotta e nella discussione della riga', async () => {
        const w = montaPagina([riga(20, { tipo: 'comunicazioni', rispondi: { url: '/admin/comunicazioni/9/commenti', dati: { discussione: 'tutti' } } })]);
        const vm = w.vm as unknown as Vm;

        vm.apriRisposta(riga(20, { tipo: 'comunicazioni', rispondi: { url: '/admin/comunicazioni/9/commenti', dati: { discussione: 'tutti' } } }));
        await w.vm.$nextTick();

        expect(vm.apertaRisposta).toBe(true);
        const modulo = w.findComponent({ name: 'FormCommento' });
        expect(modulo.exists()).toBe(true);
        expect(modulo.props()).toMatchObject({
            url: '/admin/comunicazioni/9/commenti',
            dati: { discussione: 'tutti' },
            apertoDiSerie: true,
            canComment: true,
            canCreate: true,
            ricarica: ['commenti', 'flash'],
        });

        await modulo.vm.$emit('inviato');
        expect(vm.apertaRisposta).toBe(false);
    });

    test('le azioni in blocco mandano gli id alla loro rotta, e solo dopo la conferma per «Nascondi» e «Elimina»', () => {
        const w = montaPagina();
        const vm = w.vm as unknown as Vm;
        vm.selezionati = [10, 11];

        vm.approvaInBlocco();
        expect(vi.mocked(router.post)).toHaveBeenLastCalledWith(
            '/admin/commenti/approva-in-blocco',
            { ids: [10, 11] },
            expect.objectContaining({ preserveScroll: true, only: ['commenti', 'flash'] })
        );

        vm.confermaNascondiInBlocco();
        expect(vi.mocked(router.post)).toHaveBeenLastCalledWith('/admin/commenti/nascondi-in-blocco', { ids: [10, 11] }, expect.anything());

        vm.confermaEliminaInBlocco();
        expect(vi.mocked(router.post)).toHaveBeenLastCalledWith('/admin/commenti/elimina-in-blocco', { ids: [10, 11] }, expect.anything());
    });
});

describe('CommentiList — il filtro per elemento (da «Modera» dell\'Action inbox)', () => {
    test('con un elemento scelto c\'è l\'etichetta col suo nome; si toglie da sé e la visita parziale non lo manda più', async () => {
        const p = montaPagina(undefined, { stato: 'in_attesa', tipo: 'segnalazioni', elemento: 9, elemento_nome: 'Ascensore fermo' });
        const etichetta = p.get('[data-testid="filtro-elemento"]');

        expect(etichetta.text()).toContain('commenti_pagina.filters.element');
        expect(p.text()).toContain('commenti_pagina.filters.reset');
        (p.vm as unknown as Vm).applicaFiltri();
        expect(vi.mocked(router.get).mock.calls.at(-1)?.[1]).toMatchObject({ stato: 'in_attesa', tipo: 'segnalazioni', elemento: 9 });

        await etichetta.get('button').trigger('click');
        expect(vi.mocked(router.get).mock.calls.at(-1)?.[1]).not.toHaveProperty('elemento');
        expect(montaPagina().find('[data-testid="filtro-elemento"]').exists()).toBe(false);
    });
});

/**
 * Fase 1-bis della 1.12.0-beta.11: i rilievi sulla pagina.
 *
 * - Il filtro «elemento» è una coppia con il tipo: cambiando «Tipo» l'etichetta si toglie (il server vuole il tipo con l'elemento).
 * - I menu sono `v-select` (decisione 392), con lo stile di vue-select importato nella pagina.
 * - «Scritto il» arriva dal server nel giorno e nell'ora di Roma: la pagina la scrive senza passare dall'orologio del browser.
 * - «1 selezionato», con il singolare.
 * - La finestra «Modifica» la chiude solo la risposta della sua riga; l'errore del campo si toglie scrivendo.
 * - I pulsanti in blocco: «Approva selezionati» non c'è se nessuna riga scelta si può approvare; «Elimina selezionati» chiede conferma.
 */
type VmCorrezioni = Vm & {
    formModifica: { corpo: string; errors: Record<string, string>; setError: (campo: string, messaggio: string) => void };
};

/** Sceglie una voce di un `v-select` come la sceglie chi usa la pagina: il fuoco apre il menu, il clic sceglie. */
async function scegli(w: ReturnType<typeof montaPagina>, testid: string, testo: string) {
    const menu = w.get(`[data-testid="${testid}"] .v-select`);
    await menu.get('.vs__search').trigger('focus');
    const voce = menu.findAll('.vs__dropdown-option').find((o) => o.text().trim() === testo);
    expect(voce, `Nel menu ${testid} manca la voce «${testo}»`).toBeDefined();
    await voce!.trigger('click');
}

describe('CommentiList — il filtro «elemento» va con il tipo', () => {
    test('cambiando «Tipo» dal menu l\'etichetta dell\'elemento si toglie, e la visita non manda più l\'elemento', async () => {
        const w = montaPagina([riga(10)], { stato: 'in_attesa', tipo: 'comunicazioni', elemento: 7, elemento_nome: 'Avviso di giugno' });
        expect(w.find('[data-testid="filtro-elemento"]').exists()).toBe(true);

        await scegli(w, 'filtro-tipo', 'commenti_pagina.filters.type_segnalazioni');

        const parametri = vi.mocked(router.get).mock.lastCall?.[1] as Record<string, unknown>;
        expect(parametri).toMatchObject({ stato: 'in_attesa', tipo: 'segnalazioni' });
        expect(parametri).not.toHaveProperty('elemento');
        expect(w.find('[data-testid="filtro-elemento"]').exists()).toBe(false);
    });

    test('anche portando «Tipo» a «Tutti» l\'elemento non resta da solo', async () => {
        const w = montaPagina([riga(10)], { stato: 'in_attesa', tipo: 'comunicazioni', elemento: 7, elemento_nome: 'Avviso di giugno' });
        (w.vm as unknown as Vm).statoFiltro.tipo = 'tutti';
        (w.vm as unknown as Vm).applicaFiltri();

        expect(vi.mocked(router.get).mock.lastCall?.[1]).not.toHaveProperty('elemento');
    });
});

describe('CommentiList — i menu con vSelect (decisione 392)', () => {
    test('Stato, Tipo, Condominio e le righe per pagina sono v-select, nessun Select di shadcn', async () => {
        const w = montaPagina();

        expect(w.findAllComponents({ name: 'SelectTrigger' })).toHaveLength(0);
        for (const testid of ['filtro-stato', 'filtro-tipo', 'filtro-condominio', 'righe-per-pagina']) {
            expect(w.find(`[data-testid="${testid}"] .v-select`).exists(), `Manca il v-select ${testid}`).toBe(true);
        }

        await scegli(w, 'filtro-stato', 'commenti_pagina.filters.status_hidden');
        expect(vi.mocked(router.get).mock.lastCall?.[1]).toMatchObject({ stato: 'nascosto', page: 1 });

        await scegli(w, 'filtro-condominio', 'Condominio Aurora');
        expect(vi.mocked(router.get).mock.lastCall?.[1]).toMatchObject({ stato: 'nascosto', condominio_id: 1, page: 1 });

        await scegli(w, 'righe-per-pagina', '25');
        expect(vi.mocked(router.get).mock.lastCall?.[1]).toMatchObject({ per_page: 25, page: 1 });
    });

    test('la pagina importa lo stile di vue-select da sé, senza contare sui vicini', async () => {
        const { readFileSync } = await import('node:fs');
        const { join } = await import('node:path');
        const sorgente = readFileSync(join(__dirname, 'CommentiList.vue'), 'utf-8');

        expect(sorgente).toContain("import 'vue-select/dist/vue-select.css';");
        expect(sorgente).not.toContain("@/components/ui/select'");
    });
});

describe('CommentiList — le parole e le date', () => {
    test('«Scritto il» è il giorno e l\'ora che manda il server, scritti all\'italiana senza l\'orologio del browser', () => {
        const locale = vi.spyOn(Date.prototype, 'toLocaleDateString').mockReturnValue('ORA DEL BROWSER');
        const localeString = vi.spyOn(Date.prototype, 'toLocaleString').mockReturnValue('ORA DEL BROWSER');
        const w = montaPagina([riga(10, { creato_il: '2026-11-18 00:30' })]);

        const testo = w.get('tbody tr').text();
        expect(testo).toContain('18/11/2026 00:30');
        expect(testo).not.toContain('ORA DEL BROWSER');
        locale.mockRestore();
        localeString.mockRestore();
    });

    test('la barra in blocco conta con il singolare e il plurale', async () => {
        const w = montaPagina();
        const vm = w.vm as unknown as Vm;

        vm.selezionati = [10];
        await w.vm.$nextTick();
        expect(w.get('[data-barra-blocco]').text()).toContain('commenti_pagina.actions.selected#1');

        vm.selezionati = [10, 11];
        await w.vm.$nextTick();
        expect(w.get('[data-barra-blocco]').text()).toContain('commenti_pagina.actions.selected#2');
    });
});

describe('CommentiList — la finestra «Modifica»', () => {
    test('la risposta del salvataggio di una riga non chiude la finestra riaperta su un\'altra, e il testo scritto resta', () => {
        const w = montaPagina();
        const vm = w.vm as unknown as VmCorrezioni;

        vm.apriModifica(riga(10));
        vm.formModifica.corpo = 'Correzione della riga 10';
        vm.salvaModifica();
        const opzioni = vi.mocked(router.visit).mock.calls[0][1] as { onSuccess?: (p: unknown) => unknown };

        // Mentre la richiesta viaggia: Esc, «Modifica» sulla riga 11, e si comincia a scrivere.
        vm.apertaModifica = false;
        vm.apriModifica(riga(11, { stato: 'pubblicato' }));
        vm.formModifica.corpo = 'Sto correggendo la riga 11';

        opzioni.onSuccess?.({ component: 'commenti/CommentiList', props: { errors: {} }, url: '/admin/commenti', version: null });

        expect(vm.apertaModifica).toBe(true);
        expect(vm.formModifica.corpo).toBe('Sto correggendo la riga 11');
    });

    test('la risposta della sua riga la chiude', () => {
        const w = montaPagina();
        const vm = w.vm as unknown as VmCorrezioni;

        vm.apriModifica(riga(10));
        vm.formModifica.corpo = 'Correzione della riga 10';
        vm.salvaModifica();
        const opzioni = vi.mocked(router.visit).mock.calls[0][1] as { onSuccess?: (p: unknown) => unknown };
        opzioni.onSuccess?.({ component: 'commenti/CommentiList', props: { errors: {} }, url: '/admin/commenti', version: null });

        expect(vm.apertaModifica).toBe(false);
    });

    test('l\'errore del campo si toglie appena si scrive', async () => {
        const w = montaPagina();
        const vm = w.vm as unknown as VmCorrezioni;

        vm.apriModifica(riga(10));
        vm.formModifica.setError('corpo', 'Il testo è troppo lungo.');
        expect(vm.formModifica.errors.corpo).toBe('Il testo è troppo lungo.');

        vm.formModifica.corpo = 'Più corto';
        await w.vm.$nextTick();
        expect(vm.formModifica.errors.corpo).toBeUndefined();
    });
});

describe('CommentiList — i pulsanti in blocco, dal lato che conta', () => {
    test('scelte solo righe che non si possono approvare, «Approva selezionati» non c\'è e non manda niente', async () => {
        const w = montaPagina();
        const vm = w.vm as unknown as Vm;

        vm.selezionati = [11];
        await w.vm.$nextTick();
        expect(w.find('[data-azione-blocco="approva"]').exists()).toBe(false);
        vm.approvaInBlocco();
        expect(vi.mocked(router.post)).not.toHaveBeenCalled();
    });

    test('il clic su «Elimina selezionati» chiede conferma: parte solo dalla conferma', async () => {
        const w = montaPagina(undefined);
        const vm = w.vm as unknown as Vm;
        vm.selezionati = [10, 11];
        await w.vm.$nextTick();

        await w.get('[data-azione-blocco="elimina"]').trigger('click');
        expect(vi.mocked(router.post)).not.toHaveBeenCalled();

        const conferma = document.body.querySelector('[data-conferma-blocco="elimina"]') as HTMLElement | null;
        expect(conferma, 'La conferma non si è aperta').not.toBeNull();
        conferma!.click();
        expect(vi.mocked(router.post)).toHaveBeenLastCalledWith('/admin/commenti/elimina-in-blocco', { ids: [10, 11] }, expect.anything());
    });
});

describe('CommentiList — «Mostra di nuovo» (decisione 456)', () => {
    const nascosta = (id: number, extra: Record<string, unknown> = {}) =>
        riga(id, { stato: 'nascosto', puo_approvare: false, puo_nascondere: false, puo_mostrare: true, ...extra });

    function montaConMenuAperto(righe: ReturnType<typeof riga>[]) {
        const passa = { template: '<div><slot /></div>' };
        return mount(CommentiList, {
            props: {
                commenti: { data: righe, current_page: 1, last_page: 1, per_page: 10, total: righe.length, links: [] },
                filtri: { stato: '', tipo: '', condominio_id: null, autore: '', dal: '', al: '' },
                condomini: [{ id: 1, nome: 'Condominio Aurora' }],
            },
            global: {
                stubs: {
                    AppLayout: passa,
                    DropdownMenu: passa,
                    DropdownMenuTrigger: passa,
                    DropdownMenuContent: passa,
                    DropdownMenuItem: { template: '<div v-bind="$attrs"><slot /></div>' },
                },
            },
        });
    }

    test('«Mostra di nuovo selezionati» c\'è solo se una riga scelta si può mostrare di nuovo', async () => {
        const w = montaPagina([riga(10), nascosta(12)]);

        (w.vm as unknown as Vm).selezionati = [10];
        await w.vm.$nextTick();
        expect(w.find('[data-azione-blocco="mostra"]').exists()).toBe(false);

        (w.vm as unknown as Vm).selezionati = [10, 12];
        await w.vm.$nextTick();
        const pulsante = w.get('[data-azione-blocco="mostra"]');
        expect(pulsante.text()).toContain('commenti_pagina.actions.show_again_selected');
    });

    test('il pulsante in blocco manda gli id a «mostra-in-blocco», senza conferma', async () => {
        const w = montaPagina([nascosta(12), nascosta(13)]);
        (w.vm as unknown as Vm).selezionati = [12, 13];
        await w.vm.$nextTick();

        await w.get('[data-azione-blocco="mostra"]').trigger('click');

        expect(vi.mocked(router.post)).toHaveBeenLastCalledWith(
            '/admin/commenti/mostra-in-blocco',
            { ids: [12, 13] },
            expect.objectContaining({ preserveScroll: true, only: ['commenti', 'flash'] })
        );
    });

    test('nel menu della riga «Mostra di nuovo» c\'è solo con puo_mostrare, e usa la rotta della scheda', async () => {
        const w = montaConMenuAperto([riga(10), nascosta(12)]);

        const voci = w.findAll('[data-azione-riga="mostra"]');
        expect(voci).toHaveLength(1);
        expect(voci[0].text()).toContain('commenti_pagina.actions.show_again');

        await voci[0].trigger('click');
        expect(vi.mocked(router.post)).toHaveBeenCalledWith(
            '/admin/commenti/mostra/12',
            {},
            expect.objectContaining({ preserveScroll: true, only: ['commenti', 'flash'] })
        );
    });
});
