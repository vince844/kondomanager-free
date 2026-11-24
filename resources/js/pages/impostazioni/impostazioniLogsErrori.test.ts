// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
// @vitest-environment jsdom

/**
 * La scheda «Errori» della pagina «Registri» (1.12.0-beta.14, decisione 698; bozza approvata docs/design/beta14_pagine_errore_grafica_bozza.html, scene
 * 7–9), con le props come le manda `LogsController`:
 * - il cartellino dei nuovi sulla scheda; una riga per errore con lo stato in un badge `rounded-md` con la scritta, il codice, chi ha avvisato;
 * - «Stato» e «Righe» sono menu vSelect, mai un `<select>` del browser;
 * - «Apri» chiede solo il dettaglio (visita parziale), con l'id nell'indirizzo; il codice intero nella ricerca chiede anche il dettaglio;
 * - la finestra del dettaglio: gli avvisi in cima con la nota, «Copia il rapporto» copia il testo del rapporto, «Scarica il rapporto» porta al file;
 * - il codice che non c'è dice dove cercarlo.
 */
import { afterEach, describe, expect, test, vi } from 'vitest';
import { flushPromises, mount, type VueWrapper } from '@vue/test-utils';
import vSelect from 'vue-select';

const get = vi.fn();
vi.mock('@inertiajs/vue3', async (importOriginal) => ({
    ...(await importOriginal<typeof import('@inertiajs/vue3')>()),
    Head: { template: '<span />' },
    router: { get: (...a: unknown[]) => get(...a) },
}));
(globalThis as Record<string, unknown>).route = (nome: string) => `/${nome}`;
(globalThis as unknown as { ResizeObserver: unknown }).ResizeObserver = class { observe() {} unobserve() {} disconnect() {} };

const Registri = (await import('./impostazioniLogs.vue')).default;

const RIGHE = [
    { id: 7, stato: 'avvisato', classe: 'QueryException', messaggio: 'SQLSTATE[42S22]: Column not found: 1054 Unknown column \'importo_residuo\'', codice: 'K7M2QX',
        metodo: 'GET', pagina: '/admin/gestionale/{condominio}/movimenti/incassi/{incasso}/ricevuta', azione: 'IncassoRateController@ricevuta', volte: 3,
        ultima_volta_il: '2026-11-22T09:42:00Z', avvisato_da: 'Elena Rossi' },
    { id: 8, stato: 'nuovo', classe: 'TransportException', messaggio: 'Expected response code "250" but got code "554"', codice: 'X2N6FK', metodo: null, pagina: null,
        azione: 'Lavoro in background: InviaComunicazione', volte: 7, ultima_volta_il: '2026-11-21T17:30:00Z', avvisato_da: null },
    { id: 9, stato: 'visto', classe: 'Error', messaggio: 'Call to a member function nome() on null', codice: 'J3KX8N', metodo: 'GET', pagina: '/admin/condomini/{condominio}',
        azione: 'X@y', volte: 1, ultima_volta_il: '2026-11-20T08:15:00Z', avvisato_da: null },
];

const DETTAGLIO = {
    id: 7, stato: 'avvisato', classe: 'Illuminate\\Database\\QueryException', messaggio: 'SQLSTATE[42S22]: Column not found', volte_in_tutto: 3,
    prima_volta: '20/11/2026 alle 18:05', ultima_volta: '22/11/2026 alle 10:42', impronta: '9F3C1A2E',
    volta: { id: 41, codice: 'K7M2QX', quando: '22/11/2026 alle 10:42', metodo: 'GET', pagina: '/admin/gestionale/{condominio}/movimenti/incassi/{incasso}/ricevuta',
        azione: 'IncassoRateController@ricevuta', file: 'app/Services/Gestionale/RicevutaService.php', riga: 214, causa: null,
        programma: 'KondoManager 1.12.0-beta.14 · PHP 8.4.23 · Laravel 13.40.0 · mysql', chi: 'un condòmino' },
    volte: [
        { id: 41, codice: 'K7M2QX', quando: '22/11/2026 alle 10:42', chi: 'un condòmino', avvisato: true },
        { id: 40, codice: '9PXH3D', quando: '21/11/2026 alle 17:10', chi: 'l\'amministratore', avvisato: false },
    ],
    avvisi: [{ codice: 'K7M2QX', nome: 'Elena Rossi', chi: 'un condòmino', condominio: 'Via Roma 12', quando: '22/11/2026 alle 10:43', nota: 'Scaricavo la ricevuta di novembre' }],
    traccia: ['#0  vendor/laravel/framework/x.php:838  Connection->run()', '    … altre 6 righe del framework'],
    rapporto: 'KondoManager · rapporto dell\'errore K7M2QX\n',
    rapporto_url: '/logs/errori/41/rapporto',
};

let pagina: VueWrapper | null = null;

function apri(altro: Record<string, unknown> = {}) {
    pagina = mount(Registri, {
        attachTo: document.body,
        props: {
            mailLogs: { data: [], total: 128, current_page: 1, last_page: 1, links: [] },
            filters: { search: '' },
            scheda: 'errori',
            erroriNuovi: 2,
            errori: { data: RIGHE, total: 3, current_page: 1, last_page: 1, links: [] },
            erroriFiltri: { cerca: '', stato: 'tutti', dal: '', al: '', per_page: 10 },
            dettaglio: null,
            codiceNonTrovato: null,
            ...altro,
        } as never,
        global: { stubs: { AppLayout: { template: '<div><slot /></div>' } } },
    });

    return pagina;
}

afterEach(() => {
    pagina?.unmount();
    pagina = null;
    document.body.innerHTML = '';
    get.mockReset();
    vi.useRealTimers();
});

describe('la scheda «Errori»', () => {
    test('la pagina si chiama «Registri»; la scheda «Errori» porta il cartellino dei nuovi', () => {
        apri();
        expect(pagina!.text()).toContain('Registri');
        expect(pagina!.find('[data-testid="errori-nuovi"]').text()).toBe('2 nuovi');
        expect(pagina!.find('[data-testid="scheda-errori"]').classes().join(' ')).toContain('bg-white');
    });

    test('la card «Il rapporto» dice ciò che è garantito: niente «dati personali»', () => {
        apri();
        const testo = pagina!.text().replace(/\s+/g, ' ');
        expect(testo).toContain('Senza password, senza i dati dei moduli e i valori delle query; i nomi scritti nei messaggi possono restare: rileggilo prima di mandarlo.');
        expect(testo).not.toContain('dati personali');
    });

    test('una riga per errore: lo stato in un badge rounded-md con la scritta, il codice, chi ha avvisato, dove è successo', () => {
        apri();
        const righe = pagina!.findAll('[data-testid="riga-errore"]');
        expect(righe).toHaveLength(3);
        expect(righe.map((r) => r.find('[data-testid="stato-errore"]').text())).toEqual(['Avvisato', 'Nuovo', 'Visto']);
        for (const r of righe) {
            const classi = r.find('[data-testid="stato-errore"]').classes();
            expect(classi).toContain('rounded-md');
            expect(classi).not.toContain('rounded-full');
        }
        expect(righe[0].text()).toContain('K7M2QX');
        expect(righe[0].text()).toContain('ti ha avvisato Elena Rossi');
        expect(righe[1].text()).toContain('Lavoro in background: InviaComunicazione');
    });

    test('«Stato» e «Righe» sono menu vSelect; niente <select> del browser', () => {
        apri();
        expect(pagina!.findAllComponents(vSelect).length).toBeGreaterThanOrEqual(2);
        expect(pagina!.html()).not.toContain('<select');
    });

    test('«Apri» chiede solo il dettaglio, con l\'id nell\'indirizzo, senza ricaricare la pagina', () => {
        apri();
        pagina!.findAll('[data-testid="apri-errore"]')[1].trigger('click');
        expect(get).toHaveBeenCalledTimes(1);
        const [, dati, opzioni] = get.mock.calls[0];
        expect(dati).toEqual({ scheda: 'errori', errore: 8 });
        expect(opzioni.only).toEqual(expect.arrayContaining(['dettaglio', 'erroriNuovi']));
        expect(opzioni.preserveState).toBe(true);
    });

    test('il codice intero nella ricerca chiede anche il dettaglio; una parola chiede solo l\'elenco', async () => {
        vi.useFakeTimers();
        apri();
        const campo = pagina!.find('[data-testid="errori-cerca"]');
        await campo.setValue('k7m2qx');
        vi.advanceTimersByTime(400);
        expect(get.mock.calls.at(-1)![2].only).toContain('dettaglio');
        expect(get.mock.calls.at(-1)![1]).toMatchObject({ scheda: 'errori', cerca: 'k7m2qx' });
        await campo.setValue('Unknown');
        vi.advanceTimersByTime(400);
        expect(get.mock.calls.at(-1)![2].only).not.toContain('dettaglio');
    });

    test('il codice che non c\'è dice perché e dove cercarlo', () => {
        apri({ codiceNonTrovato: 'T5VB2R', errori: { data: [], total: 0, current_page: 1, last_page: 1, links: [] } });
        const vuoto = pagina!.find('[data-testid="codice-non-trovato"]');
        expect(vuoto.text()).toContain('Nessun errore con il codice T5VB2R');
        expect(vuoto.text()).toContain('storage/logs/laravel.log');
    });
});

describe('la finestra del dettaglio', () => {
    test('aperta dall\'indirizzo: gli avvisi in cima con la nota, le volte, il programma; il rapporto si scarica', async () => {
        apri({ dettaglio: DETTAGLIO });
        await flushPromises();
        const finestra = document.body.querySelector('[data-testid="finestra-errore"]')!;
        expect(finestra).not.toBeNull();
        const testo = (finestra.textContent ?? '').replace(/\s+/g, ' ');
        expect(testo).toContain('Errore K7M2QX');
        expect(testo.indexOf('Gli avvisi')).toBeLessThan(testo.indexOf('Che cosa è successo'));
        expect(testo).toContain('Elena Rossi');
        expect(testo).toContain('«Scaricavo la ricevuta di novembre»');
        // Dice ciò che la ripulitura garantisce, non di più: i nomi scritti nei messaggi possono restare.
        expect(testo).toContain('Senza password, senza i dati dei moduli e i valori delle query; i nomi scritti nei messaggi possono restare: rileggilo prima di mandarlo.');
        expect(testo).not.toContain('dati personali');
        expect(finestra.querySelectorAll('[data-testid="volta"]')).toHaveLength(2);
        expect(finestra.querySelector('[data-testid="scarica-rapporto"]')!.getAttribute('href')).toBe('/logs/errori/41/rapporto');
    });

    test('«Copia il rapporto» copia il testo del rapporto e lo dice', async () => {
        const scrivi = vi.fn().mockResolvedValue(undefined);
        Object.defineProperty(window, 'isSecureContext', { value: true, configurable: true });
        Object.defineProperty(navigator, 'clipboard', { value: { writeText: scrivi }, configurable: true });
        apri({ dettaglio: DETTAGLIO });
        await flushPromises();
        const pulsante = document.body.querySelector('[data-testid="copia-rapporto"]') as HTMLButtonElement;
        pulsante.click();
        await flushPromises();
        expect(scrivi).toHaveBeenCalledWith(DETTAGLIO.rapporto);
        expect(pulsante.textContent).toContain('Rapporto copiato');
    });
});
