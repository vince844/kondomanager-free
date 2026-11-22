// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
// @vitest-environment jsdom

/**
 * La pagina del registro delle modifiche (scene 2 e 5 della bozza, §14 del progetto della 1.12.0-beta.13).
 *
 * - Le tre card: il mese con quante modifiche e da chi, l'integrità, la conservazione (lo slot `conservazione`).
 * - I filtri sono `v-select` e **non ricaricano la pagina**: una visita parziale di `righe`, `riepilogo` e `filtri`, con `preserveState` e `preserveScroll`;
 *   un filtro cambiato torna alla prima pagina e tiene le righe per pagina.
 * - La tabella a colonne fisse con le intestazioni della bozza; un dato solo con il valore di prima barrato; «Il programma» con il suo badge; la riga
 *   annullata da un ripristino con il badge; «Storia» apre il cassetto della scheda, «Apri» è un collegamento.
 * - **Le pagine delle altre tabelle** al posto di «Mostra altre»: il conto delle righe, «Righe per pagina» (20, 50, 100), «Pagina 2 di 16» e le quattro
 *   frecce; una freccia o le righe per pagina chiedono le sole righe (una visita parziale); al telefono il conto sopra, la pagina e le frecce sotto.
 * - Il menu «Registro» accanto a «Guida» (decisione 570): «Verifica l'integrità» e, nel condominio e solo all'amministratore, «Sospendi la cancellazione»
 *   («Togli la sospensione» se è in corso). «Stampa» è un pulsante accanto al menu (`FinestraDellaStampa.test.ts`). Nei test il menu si monta aperto: le sue parti diventano semplici contenitori, e una voce cliccata
 *   manda `select` come quella vera.
 * - «Verifica l'integrità»: le due frasi della bozza; con una riga che non torna, «Scarica il rapporto».
 * - La pagina del condominio passa il condominio al menu, che monta la sospensione; le due pagine montano la conservazione; nessuna pagina usa `v-html`.
 * - La guida e la frase in fondo alla pagina dicono la finestra della stampa e il periodo dal … al ….
 *
 * Che cosa non copre: le righe e i filtri del server (`tests/Feature/RegistroModifiche/PagineDelRegistroTest.php`).
 */
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { afterEach, describe, expect, test, vi } from 'vitest';
import { flushPromises, mount, type VueWrapper } from '@vue/test-utils';
import { nextTick } from 'vue';

const routerGet = vi.fn();
const routerReload = vi.fn();
const axiosGet = vi.fn();
const axiosPost = vi.fn();
vi.mock('axios', () => ({ default: { get: (...a: unknown[]) => axiosGet(...a), post: (...a: unknown[]) => axiosPost(...a) } }));
vi.mock('@inertiajs/vue3', async (importOriginal) => ({
    ...(await importOriginal<typeof import('@inertiajs/vue3')>()),
    InfiniteScroll: { template: '<div><slot /><slot name="next" :fetch="() => {}" :has-more="true" :loading="false" /></div>' },
    router: { get: (...a: unknown[]) => routerGet(...a), reload: (...a: unknown[]) => routerReload(...a), visit: vi.fn() },
}));
vi.mock('@/composables/permissions', () => ({ usePermission: () => ({ generateRoute: (nome: string) => nome }) }));
(globalThis as unknown as { route: unknown }).route = (nome: string) => `/${nome}`;
(globalThis as unknown as { ResizeObserver: unknown }).ResizeObserver = class { observe() {} unobserve() {} disconnect() {} };

const Registro = (await import('./RegistroDelleModifiche.vue')).default;
const Pulsanti = (await import('./PulsantiDelRegistro.vue')).default;

const contenitore = { template: '<div><slot /></div>' };
const voce = { emits: ['select'], template: '<div role="menuitem" @click="$emit(\'select\', $event)"><slot /></div>' };
const MENU = { DropdownMenu: contenitore, DropdownMenuTrigger: contenitore, DropdownMenuContent: contenitore, DropdownMenuItem: voce };
/** Il menu «Registro» montato aperto. */
const montaPulsanti = (props: Record<string, unknown>) => mount(Pulsanti, { attachTo: document.body, props: { stampa: stampaDiProva(), ...props } as never,
    global: { stubs: MENU } });

const riga = (id: number, extra: Record<string, unknown> = {}) => ({
    id, avvenuta_il: '16/12/2026 09:12', registrata_il: '16/12/2026 09:12',
    autore: { tipo: 'persona', nome: 'Marco Galli', ruolo: 'collaboratore', origine: 'pagina', dettaglio: null },
    area: 'persone', azione: 'modificata', oggetto: { tipo: 'anagrafica', id: 7, nome: 'Anagrafica di Ida Romano' }, scheda: { tipo: 'anagrafica', id: 7 },
    riepilogo: null, perche: null, rimando: null, oscurata: false, annullata_dal_ripristino_del: null, fuori_dal_programma: false, nota: null, apri: null,
    storia: { tipo: 'anagrafica', id: 7 },
    azione_parola: 'Modificata', area_parola: 'Persone e fornitori', condomini: ['Via Roma 12'],
    campi: [{ chiave: 'telefono', etichetta: 'Telefono', prima: '333 120 4455', dopo: '347 880 1290', segreto: false }],
    cambiato: 'Telefono: 333 120 4455 → 347 880 1290',
    ...extra,
});
const scelta = (valore: string, nome: string) => ({ valore, nome });
/** Ciò che serve alla finestra della stampa (le sue prove sono in `FinestraDellaStampa.test.ts`). */
const stampaDiProva = () => ({ indirizzo: '/stampa', conto: '/conto', filtri: { chi: null, area: null, azione: null, mese: '2026-12', cerca: null, condominio: null },
    scelte: { chi: [scelta('', 'Tutti')], aree: [scelta('', 'Tutto')], azioni: [scelta('', 'Tutto')], mesi: [scelta('2026-12', 'Dicembre 2026'), scelta('tutti', 'Tutti i mesi')],
        condomini: [] }, esercizio: null, delloStudio: true, luogo: 'Tutti i condomini e lo studio' });
const props = (extra: Record<string, unknown> = {}) => ({
    righe: {
        data: [
            riga(3, { autore: { tipo: 'programma', nome: 'Il programma', ruolo: null, origine: 'pianificato', dettaglio: 'pubblicazione' }, azione: 'gesto',
                oggetto: { tipo: 'comunicazione', id: 9, nome: 'Comunicazione «Lavori al portone»' }, campi: [], cambiato: 'Pubblicata in bacheca',
                perche: 'Programmata da Andrea Costa per il 09/12/2026 alle 08:00', apri: '/admin/comunicazioni/9', storia: null }),
            riga(2),
            riga(1, { campi: [{ chiave: 'email', etichetta: 'Email', prima: 'a', dopo: 'b', segreto: false }, { chiave: 'pec', etichetta: 'PEC', prima: null, dopo: 'c', segreto: false }],
                cambiato: 'Email e PEC (2 dati)', annullata_dal_ripristino_del: '15/12/2026' }),
        ],
        current_page: 2,
        last_page: 16,
        per_page: 20,
        total: 312,
    },
    riepilogo: { mese: { nome: 'Dicembre 2026', modifiche: 312, frase: 'da 2 persone e dal programma' },
        integrita: { stato: 'integro', parola: 'Integro', frase: '4.812 righe dal 01/11/2026 · verificato il 16/12 alle 10:10' } },
    scelte: {
        chi: [scelta('', 'Tutti'), scelta('2', 'Marco Galli'), scelta('programma', 'Il programma')],
        aree: [scelta('', 'Tutto'), scelta('persone', 'Persone e fornitori')],
        azioni: [scelta('', 'Tutto'), scelta('modificata', 'Modificata')],
        mesi: [scelta('2026-12', 'Dicembre 2026'), scelta('tutti', 'Tutti i mesi')],
        condomini: [],
    },
    filtri: { chi: null, area: null, azione: null, mese: '2026-12', cerca: null, condominio: null },
    delloStudio: false,
    ...extra,
});

let w: VueWrapper | null = null;
afterEach(() => { w?.unmount(); w = null; routerGet.mockReset(); routerReload.mockReset(); axiosGet.mockReset(); axiosPost.mockReset(); document.body.innerHTML = ''; });
const pulito = (t: string | null | undefined) => (t ?? '').replace(/\s+/g, ' ').trim();
const corpo = () => document.body;
/** Il testo come si legge a video: i pezzi dei vari elementi separati da uno spazio (jsdom non mette gli spazi che a video fa il `gap`). */
const parole = (el: Element | null | undefined) => {
    if (!el) return '';
    const cammino = document.createTreeWalker(el, NodeFilter.SHOW_TEXT);
    const pezzi: string[] = [];
    while (cammino.nextNode()) {
        const t = (cammino.currentNode.textContent ?? '').trim();
        if (t) pezzi.push(t);
    }

    return pezzi.join(' ').replace(/\s+/g, ' ');
};
const menu = (testid: string) => corpo().querySelector(`[data-testid="${testid}"] .v-select`) as HTMLElement;
const nelMenu = async (testid: string, testo: string) => {
    menu(testid).querySelector('.vs__search')!.dispatchEvent(new FocusEvent('focus'));
    await flushPromises();
    ([...menu(testid).querySelectorAll('.vs__dropdown-option')].find((o) => pulito(o.textContent) === testo) as HTMLElement).click();
    await flushPromises();
};

describe('la pagina del registro', () => {
    test('le tre card della bozza; la conservazione è lo slot', async () => {
        w = mount(Registro, { attachTo: document.body, props: props() as never, slots: { conservazione: '<div data-testid="nello-slot">10 anni</div>' } });
        await flushPromises();
        expect(parole(corpo().querySelector('[data-testid="card-mese"]'))).toBe('Dicembre 2026 312 modifiche da 2 persone e dal programma');
        expect(parole(corpo().querySelector('[data-testid="card-integrita"]'))).toBe('Integrità Integro 4.812 righe dal 01/11/2026 · verificato il 16/12 alle 10:10');
        expect(corpo().querySelector('[data-testid="card-conservazione"] [data-testid="nello-slot"]')).not.toBeNull();
    });

    test('i filtri sono menu `v-select` e non ricaricano la pagina: una visita parziale delle righe, del riepilogo e dei filtri', async () => {
        w = mount(Registro, { attachTo: document.body, props: props() as never });
        await flushPromises();
        expect([...corpo().querySelectorAll('[data-testid="filtri"] .v-select')]).toHaveLength(4);
        expect(corpo().querySelector('[data-testid="filtri"] select')).toBeNull();

        await nelMenu('filtro-chi', 'Il programma');
        expect(routerGet).toHaveBeenCalledTimes(1);
        const [, parametri, opzioni] = routerGet.mock.calls[0];
        expect(parametri).toEqual({ chi: 'programma', mese: '2026-12' });
        expect(opzioni).toMatchObject({ only: ['righe', 'riepilogo', 'filtri'], preserveState: true, preserveScroll: true, replace: true });
        expect(opzioni.reset).toBeUndefined();
    });

    test('un filtro cambiato torna alla prima pagina e tiene le righe per pagina scelte', async () => {
        w = mount(Registro, { attachTo: document.body, props: props({ righe: { ...props().righe, per_page: 50, last_page: 7 } }) as never });
        await flushPromises();
        await nelMenu('filtro-area', 'Persone e fornitori');
        expect(routerGet.mock.calls[0][1]).toEqual({ area: 'persone', mese: '2026-12', per_page: 50 });
    });

    test('la pagina dello studio ha anche il condominio fra i filtri', async () => {
        w = mount(Registro, { attachTo: document.body, props: props({ delloStudio: true, scelte: { ...props().scelte, condomini: [scelta('', 'Tutti i condomini'), scelta('4', 'Via Roma 12')] } }) as never });
        await flushPromises();
        expect([...corpo().querySelectorAll('[data-testid="filtri"] .v-select')]).toHaveLength(5);
        await nelMenu('filtro-condominio', 'Via Roma 12');
        expect(routerGet.mock.calls[0][1]).toEqual({ mese: '2026-12', condominio: '4' });
    });

    test('la tabella a colonne fisse con le intestazioni della bozza, il programma, il valore di prima barrato, la riga annullata, «Storia» e «Apri»', async () => {
        w = mount(Registro, { attachTo: document.body, props: props() as never });
        await flushPromises();
        const tabella = corpo().querySelector('[data-testid="tabella-registro"]') as HTMLTableElement;
        expect(tabella.className).toContain('table-fixed');
        expect([...tabella.querySelectorAll('th')].map((t) => pulito(t.textContent))).toEqual(['Quando', 'Chi', 'Su che cosa', 'Che cosa è cambiato', 'Azioni']);
        const righe = [...tabella.querySelectorAll('[data-testid="riga-registro"]')];
        expect(pulito(righe[0].children[1].textContent)).toBe('Il programma');
        expect(righe[0].children[1].querySelector('span')!.className).toContain('rounded-md');
        expect(parole(righe[0].children[3])).toBe('Pubblicata in bacheca Perché: Programmata da Andrea Costa per il 09/12/2026 alle 08:00');
        expect((righe[0].querySelector('[data-testid="apri-riga"]') as HTMLAnchorElement).getAttribute('href')).toBe('/admin/comunicazioni/9');
        expect(righe[1].children[3].querySelector('.line-through')?.textContent).toBe('333 120 4455');
        expect(pulito(righe[2].querySelector('[data-testid="annullata"]')?.textContent)).toBe('Annullata dal ripristino del 15/12/2026');
        expect(pulito(righe[2].children[3].textContent)).toContain('Email e PEC (2 dati)');

        axiosGet.mockResolvedValue({ data: { titolo: 'Storia di Ida Romano', sottotitolo: 'Anagrafica', totale: 0, pagina: 1, altre: 0, righe: [], oscuramento: null } });
        (righe[1].querySelector('[data-testid="storia-riga"]') as HTMLButtonElement).click();
        await flushPromises();
        expect(axiosGet.mock.calls[0][0]).toBe('/registro-modifiche.storia');
        expect(corpo().querySelector('[data-testid="cassetto-storia"]')).not.toBeNull();
    });

    test('nessuna riga con questi filtri: la pagina lo dice', async () => {
        w = mount(Registro, { attachTo: document.body, props: props({ righe: { data: [], total: 0, current_page: 1, last_page: 1, per_page: 20 } }) as never });
        await nextTick();
        expect(pulito(corpo().querySelector('[data-testid="nessuna-riga"]')?.textContent)).toBe('Nessuna riga con questi filtri. Prova un altro periodo, o «Tutti i mesi».');
        expect(corpo().querySelector('[data-testid="paginazione"]')).toBeNull();
    });
});

describe('le pagine, come nelle altre tabelle', () => {
    const freccia = (nome: string) => corpo().querySelector(`[data-testid="pagina-${nome}"]`) as HTMLButtonElement;

    test('il conto delle righe, «Righe per pagina» con 20, 50 e 100, «Pagina 2 di 16» e le quattro frecce; niente più «Mostra altre»', async () => {
        w = mount(Registro, { attachTo: document.body, props: props() as never });
        await flushPromises();
        const pagine = corpo().querySelector('[data-testid="paginazione"]')!;
        expect(pulito(pagine.querySelector('[data-testid="conto-righe"]')?.textContent)).toBe('312 righe');
        expect(pulito(pagine.querySelector('[data-testid="righe-per-pagina"]')?.textContent)).toContain('Righe per pagina');
        expect(pulito(menu('righe-per-pagina').querySelector('.vs__selected')?.textContent)).toBe('20');
        menu('righe-per-pagina').querySelector('.vs__search')!.dispatchEvent(new FocusEvent('focus'));
        await flushPromises();
        expect([...menu('righe-per-pagina').querySelectorAll('.vs__dropdown-option')].map((o) => pulito(o.textContent))).toEqual(['20', '50', '100']);
        expect(pulito(pagine.querySelector('[data-testid="pagina-di"]')?.textContent)).toBe('Pagina 2 di 16');
        expect(['prima', 'precedente', 'successiva', 'ultima'].map((n) => pulito(freccia(n)?.textContent)))
            .toEqual(['Vai alla prima pagina', 'Vai alla pagina precedente', 'Vai alla pagina successiva', 'Vai all\'ultima pagina']);
        expect(['prima', 'precedente', 'successiva', 'ultima'].map((n) => freccia(n).disabled)).toEqual([false, false, false, false]);
        expect(corpo().querySelector('[data-testid="altre-righe"]')).toBeNull();
        expect(corpo().textContent).not.toContain('Mostra altre');
    });

    test('una freccia chiede l\'altra pagina con una visita parziale delle sole righe; le righe per pagina tornano alla prima', async () => {
        w = mount(Registro, { attachTo: document.body, props: props() as never });
        await flushPromises();
        const opzioni = { only: ['righe'], preserveState: true, preserveScroll: true, replace: true };
        freccia('successiva').click();
        freccia('ultima').click();
        freccia('prima').click();
        freccia('precedente').click();
        expect(routerGet.mock.calls.map((c) => c[1])).toEqual([{ mese: '2026-12', page: 3 }, { mese: '2026-12', page: 16 }, { mese: '2026-12', page: 1 },
            { mese: '2026-12', page: 1 }]);
        for (const c of routerGet.mock.calls) {
            expect(c[2]).toMatchObject(opzioni);
        }
        routerGet.mockReset();
        await nelMenu('righe-per-pagina', '50');
        expect(routerGet.mock.calls[0][1]).toEqual({ mese: '2026-12', per_page: 50 });
        expect(routerGet.mock.calls[0][2]).toMatchObject(opzioni);
    });

    test('alla prima pagina le frecce indietro sono spente, all\'ultima quelle avanti', async () => {
        w = mount(Registro, { attachTo: document.body, props: props({ righe: { ...props().righe, current_page: 1 } }) as never });
        await flushPromises();
        expect(['prima', 'precedente', 'successiva', 'ultima'].map((n) => freccia(n).disabled)).toEqual([true, true, false, false]);
        w.unmount();
        w = mount(Registro, { attachTo: document.body, props: props({ righe: { ...props().righe, current_page: 16 } }) as never });
        await flushPromises();
        expect(['prima', 'precedente', 'successiva', 'ultima'].map((n) => freccia(n).disabled)).toEqual([false, false, true, true]);
    });

    test('al telefono il conto sopra, «Pagina 2 di 16» e le frecce sotto, a tutta riga', async () => {
        w = mount(Registro, { attachTo: document.body, props: props() as never });
        await flushPromises();
        const pagine = corpo().querySelector('[data-testid="paginazione"]')!;
        expect(pagine.className).toContain('flex-wrap');
        const sotto = pagine.querySelector('[data-testid="pagina-e-frecce"]')!;
        expect(sotto.className).toContain('basis-full');
        expect(sotto.className).toContain('sm:basis-auto');
        expect(sotto.contains(pagine.querySelector('[data-testid="pagina-di"]'))).toBe(true);
        expect(sotto.contains(freccia('ultima'))).toBe(true);
        expect(sotto.contains(pagine.querySelector('[data-testid="conto-righe"]'))).toBe(false);
    });
});

describe('la riga senza la firma del programma', () => {
    test('ha il badge rosso «Aggiunta fuori dal programma» e lo dice anche a parole, nella tabella e al telefono; le altre no', async () => {
        const conLaFinta = props();
        conLaFinta.righe.data[1] = riga(2, { fuori_dal_programma: true });
        w = mount(Registro, { attachTo: document.body, props: conLaFinta as never });
        await nextTick();
        const badge = corpo().querySelectorAll('[data-testid="fuori-dal-programma"]');
        expect(badge).toHaveLength(2);
        expect(pulito(badge[0].textContent)).toBe('Aggiunta fuori dal programma');
        expect(badge[0].className).toContain('bg-rose-50');
        expect(badge[0].className).toContain('text-rose-800');
        expect(pulito(corpo().querySelector('[data-testid="fuori-dal-programma-frase"]')?.textContent))
            .toBe('Questa riga non ha la firma del programma: è stata aggiunta o cambiata fuori dal programma, per esempio direttamente nel database.');
        const righe = corpo().querySelectorAll('[data-testid="tabella-registro"] tbody tr');
        expect(righe[0].querySelector('[data-testid="fuori-dal-programma"]')).toBeNull();
    });

    test('il ruolo di chi ha fatto la modifica in un badge grigio, sotto il nome (prova a video, 19/11)', async () => {
        w = mount(Registro, { attachTo: document.body, props: props({ righe: { data: [riga(2)], total: 1 } }) as never });
        await nextTick();
        const ruolo = corpo().querySelector('[data-testid="tabella-registro"] [data-testid="ruolo"]')!;
        expect(pulito(ruolo.textContent)).toBe('collaboratore');
        expect(ruolo.className).toContain('rounded-md');
        expect(ruolo.className).toContain('bg-slate-50');
    });

    test('un dato solo: il nome del dato sopra, piccolo; sotto il valore di prima barrato e «→ valore nuovo», che va a capo intero (prova a video, 19/11)', async () => {
        w = mount(Registro, { attachTo: document.body, props: props({ righe: { data: [riga(2)], total: 1 } }) as never });
        await nextTick();
        for (const dove of ['[data-testid="tabella-registro"]', '[data-testid="elenco-registro"]']) {
            const zona = corpo().querySelector(dove)!;
            expect(pulito(zona.querySelector('[data-testid="etichetta-dato"]')?.textContent)).toBe('Telefono');
            expect(zona.querySelector('[data-testid="etichetta-dato"]')!.className).toContain('text-xs');
            const pezzi = [...zona.querySelector('[data-testid="valore-dato"]')!.children].map((e) => pulito(e.textContent));
            expect(pezzi).toEqual(['333 120 4455', '→ 347 880 1290']);
            expect(zona.querySelector('[data-testid="valore-dato"]')!.className).toContain('flex-wrap');
        }
    });

    test('nella tabella il badge sta su una riga sua sotto il valore, non attaccato al valore, e la frase sotto il badge', async () => {
        w = mount(Registro, { attachTo: document.body, props: props({ righe: { data: [riga(9, { fuori_dal_programma: true, annullata_dal_ripristino_del: '15/12/2026' })], total: 1 } }) as never });
        await nextTick();
        const tabella = corpo().querySelector('[data-testid="tabella-registro"]')!;
        const segni = tabella.querySelector('[data-testid="segni-della-riga"]')!;
        expect(segni.tagName).toBe('DIV');
        expect(segni.querySelector('[data-testid="fuori-dal-programma"]')).not.toBeNull();
        expect(segni.querySelector('[data-testid="annullata"]')).not.toBeNull();
        expect(segni.querySelector('[data-testid="fuori-dal-programma-frase"]')).not.toBeNull();
        expect(segni.previousElementSibling?.textContent).toContain('347 880 1290');
    });
});

describe('«Verifica l\'integrità» e «Stampa»', () => {
    test('integro: la frase verde della bozza, e la card si rilegge', async () => {
        axiosPost.mockResolvedValue({ data: { integro: true, titolo: 'Il registro è integro.', frase: 'Il registro è integro. 4.812 righe dal 01/11/2026; nessuna è stata toccata fuori dal programma.', verificata_il: '' } });
        w = montaPulsanti({});
        (corpo().querySelector('[data-testid="verifica-integrita"]') as HTMLButtonElement).click();
        await flushPromises();
        expect(axiosPost.mock.calls[0][0]).toBe('/registro-modifiche.verifica');
        expect(parole(corpo().querySelector('[data-testid="esito-integro"]')))
            .toBe('Il registro è integro. 4.812 righe dal 01/11/2026; nessuna è stata toccata fuori dal programma.');
        expect(routerReload).toHaveBeenCalledWith({ only: ['riepilogo'] });
    });

    test('una riga non torna: la frase rossa e «Scarica il rapporto»', async () => {
        axiosPost.mockResolvedValue({ data: { integro: false, titolo: 'Una riga non torna.',
            frase: 'Una riga non torna. Quella del 10/12/2026 alle 11:03 (regole del recupero dei crediti) è stata cambiata fuori dal programma, per esempio direttamente nel database. Le altre righe tornano.', verificata_il: '' } });
        w = montaPulsanti({});
        (corpo().querySelector('[data-testid="verifica-integrita"]') as HTMLButtonElement).click();
        await flushPromises();
        const esito = corpo().querySelector('[data-testid="esito-non-torna"]')!;
        expect(parole(esito)).toContain('Una riga non torna. Quella del 10/12/2026 alle 11:03 (regole del recupero dei crediti) è stata cambiata fuori dal programma');
        expect(pulito(esito.textContent)).toContain('Scarica il rapporto e chiedi a chi gestisce il server.');
        expect(esito.querySelector('[data-testid="scarica-rapporto"]')!.getAttribute('href')).toBe('/registro-modifiche.verifica.rapporto');
    });

    test('la finestra e la guida dicono che cosa la verifica vede e che cosa no: chi ha anche la chiave del server può riscrivere tutto', async () => {
        axiosPost.mockResolvedValue({ data: { integro: true, titolo: 'Il registro è integro.', frase: 'Il registro è integro.', verificata_il: '' } });
        w = montaPulsanti({});
        (corpo().querySelector('[data-testid="verifica-integrita"]') as HTMLButtonElement).click();
        await flushPromises();
        const finestra = pulito(corpo().querySelector('[data-testid="finestra-verifica"]')?.textContent);
        expect(finestra).toContain('Ogni riga nasce con la firma del programma e porta l\'impronta di quella prima: così una riga cambiata, aggiunta o tolta fuori dal programma si vede.');
        expect(finestra).toContain('La catena rende visibile una modifica fatta fuori dal programma; non la impedisce. Chi ha anche la chiave del server può riscrivere tutto');
        const guida = pulito(readFileSync(resolve(process.cwd(), 'resources/js/components/guides/RegistroModificheGuide.vue'), 'utf8'));
        expect(guida).toContain('non vede ciò che fa chi ha anche la chiave del server, che può riscrivere tutto. La catena rende visibile una modifica fatta fuori dal programma; non la impedisce.');
    });
});

describe('il menu «Registro» (decisione 570)', () => {
    const NON_SOSPESA = { sospesa: false, dal: null, motivo: null, da: null, puo_cambiare: true };
    const voci = () => [...corpo().querySelectorAll('[role="menuitem"]')].map((v) => pulito(v.textContent));

    test('il pulsante «Registro»; nello studio una voce, «Verifica l\'integrità» («Stampa» è un pulsante accanto)', async () => {
        w = montaPulsanti({});
        await flushPromises();
        expect(pulito(corpo().querySelector('[data-testid="menu-registro"]')?.textContent)).toBe('Registro');
        expect(voci()).toEqual(['Verifica l\'integrità']);
        expect(axiosGet).not.toHaveBeenCalled();
    });

    test('nel condominio, all\'amministratore, la terza voce «Sospendi la cancellazione» apre la finestra di oggi', async () => {
        axiosGet.mockResolvedValue({ data: NON_SOSPESA });
        w = montaPulsanti({ condominioId: 4, condominioNome: 'Via Roma 12' });
        await flushPromises();
        expect(voci()).toEqual(['Verifica l\'integrità', 'Sospendi la cancellazione']);
        expect(corpo().querySelector('[data-testid="sospendi"]')).toBeNull();
        (corpo().querySelector('[data-testid="voce-sospensione"]') as HTMLElement).click();
        await flushPromises();
        expect(pulito(corpo().textContent)).toContain('Le righe del registro di Via Roma 12 restano oltre la durata finché non togli la sospensione');
    });

    test('con la sospensione in corso la voce dice «Togli la sospensione»; a chi non è l\'amministratore la voce non c\'è', async () => {
        axiosGet.mockResolvedValue({ data: { sospesa: true, dal: '12/03/2035', motivo: 'causa Rossi', da: 'Andrea Costa', puo_cambiare: true } });
        w = montaPulsanti({ condominioId: 4 });
        await flushPromises();
        expect(voci().at(-1)).toBe('Togli la sospensione');
        w.unmount();
        w = null;
        document.body.innerHTML = '';

        axiosGet.mockResolvedValue({ data: { sospesa: true, dal: '12/03/2035', motivo: 'causa Rossi', da: 'Andrea Costa', puo_cambiare: false } });
        w = montaPulsanti({ condominioId: 4 });
        await flushPromises();
        expect(voci()).toEqual(['Verifica l\'integrità']);
    });
});

describe('le pagine del registro', () => {
    const leggi = (f: string) => readFileSync(resolve(process.cwd(), f), 'utf8');
    test('la pagina del condominio passa il condominio al menu, che monta la sospensione; le due pagine la conservazione; nessuna pagina del registro usa v-html', () => {
        const condominio = leggi('resources/js/pages/gestionale/registroModifiche/RegistroModifiche.vue');
        expect(condominio).toMatch(/<PulsantiDelRegistro[^>]*:condominio-id="props\.condominio\.id"/);
        expect(leggi('resources/js/components/registroModifiche/PulsantiDelRegistro.vue')).toContain('<SospensioneDellaCancellazione');
        expect(condominio).toContain('<ConservazioneDelRegistro');
        expect(leggi('resources/js/pages/impostazioni/registroModifiche.vue')).toContain('<ConservazioneDelRegistro');
        for (const f of ['resources/js/pages/gestionale/registroModifiche/RegistroModifiche.vue', 'resources/js/pages/impostazioni/registroModifiche.vue',
            'resources/js/components/registroModifiche/RegistroDelleModifiche.vue', 'resources/js/components/registroModifiche/StoriaCassetto.vue',
            'resources/js/components/registroModifiche/RipristinaFinestra.vue', 'resources/js/components/registroModifiche/PulsantiDelRegistro.vue']) {
            expect(leggi(f)).not.toContain('v-html');
        }
    });

    test('le tre card della guida in testa alle due pagine, come nelle altre schede (prova a video, 19/11)', async () => {
        const { cardDelRegistro } = await import('@/lib/registroModifiche/cardDellaGuida');
        for (const delloStudio of [false, true]) {
            const card = cardDelRegistro(delloStudio);
            expect(card.map((c) => c.title)).toEqual(['Che cosa tiene', 'La «Storia» e «Ripristina»', 'Nessuno la può cambiare']);
            expect(card.map((c) => c.colorVariant)).toEqual(['blue', 'emerald', 'amber']);
            expect(card.every((c) => !!c.icon && c.description.length > 0)).toBe(true);
        }
        expect(cardDelRegistro(false)[0].description).toContain('di questo condominio');
        expect(cardDelRegistro(true)[0].description).toContain('in tutti i condomini e nello studio');
        expect(leggi('resources/js/pages/gestionale/registroModifiche/RegistroModifiche.vue')).toContain(':guides="cardDelRegistro(false)"');
        expect(leggi('resources/js/pages/impostazioni/registroModifiche.vue')).toContain(':guides="cardDelRegistro(true)"');
    });

    test('la guida e la frase in fondo alla pagina dicono la finestra della stampa, il periodo dal … al … e le pagine', () => {
        const guida = pulito(leggi('resources/js/components/guides/RegistroModificheGuide.vue'));
        for (const frase of ['«Stampa», accanto a «Registro», apre la finestra', 'dal … al …', '«Questo mese»', '«L\'esercizio» (solo nel registro del condominio)',
            '«Dall\'inizio»', '«Scegli le date»', 'Stamperai 134 righe, dal 01/01/2026 al 31/12/2026', 'la riga arancio', '«Stampa il PDF» resta spento',
            'un esercizio alla volta', '«Righe per pagina» (20, 50 o 100)']) {
            expect(guida, frase).toContain(frase);
        }
        expect(guida).not.toContain('Mostra altre');
        const pagina = pulito(leggi('resources/js/components/registroModifiche/RegistroDelleModifiche.vue'));
        expect(pagina).toContain('«Stampa» apre la finestra per scegliere il periodo dal … al … e i filtri, e dice quante righe usciranno prima del PDF');
    });

    test('le due pagine danno alla stampa i loro indirizzi e i filtri: il condominio con l\'esercizio, lo studio senza', () => {
        const condominio = leggi('resources/js/pages/gestionale/registroModifiche/RegistroModifiche.vue');
        expect(condominio).toContain('generateRoute(\'gestionale.registro-modifiche.stampa.conto\')');
        expect(condominio).toMatch(/<PulsantiDelRegistro[^>]*:stampa="stampa"/);
        expect(condominio).toContain('esercizio: periodoDellEsercizio(props.esercizio)');
        const studio = leggi('resources/js/pages/impostazioni/registroModifiche.vue');
        expect(studio).toContain('route(\'impostazioni.registro-modifiche.stampa.conto\')');
        expect(studio).toMatch(/<PulsantiDelRegistro[^>]*:stampa="stampa"/);
        expect(studio).toContain('esercizio: null');
    });

    test('la voce in fondo al sottomenu della struttura, e la card in Impostazioni', () => {
        const struttura = leggi('resources/js/layouts/gestionale/StrutturaLayout.vue');
        expect(struttura.indexOf('Registro delle modifiche')).toBeGreaterThan(struttura.indexOf('\'Impostazioni\''));
        expect(struttura).toContain('Permission.VIEW_REGISTRO_MODIFICHE');
        const impostazioni = leggi('resources/js/pages/impostazioni/impostazioni.vue');
        expect(impostazioni).toContain('href: "/impostazioni/registro-modifiche"');
        expect(impostazioni).toContain('Permission.VIEW_REGISTRO_MODIFICHE');
    });
});
