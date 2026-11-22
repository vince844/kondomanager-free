// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
// @vitest-environment jsdom

/**
 * «Storia» nell'intestazione delle pagine (1.12.0-beta.13, §8 del progetto della tappa 1, scena 1 della bozza): con la prop `storia` accanto a «Guida» compare
 * «Storia», alto `h-8` come «Guida» (standard 4), e il clic apre il cassetto della scheda; senza la prop, o senza il permesso «Vede la storia delle schede»,
 * o per un condòmino o un fornitore, non c'è. Le pagine dell'elenco del §8 la passano (sotto, la guardia sui file delle pagine).
 */
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { afterEach, describe, expect, test, vi } from 'vitest';
import { flushPromises, mount, type VueWrapper } from '@vue/test-utils';

const permessi = { lista: ['Vede la storia delle schede'] as string[], ruoli: ['collaboratore'] as string[] };
const axiosGet = vi.fn();
vi.mock('axios', () => ({ default: { get: (...a: unknown[]) => axiosGet(...a), post: vi.fn() } }));
vi.mock('@inertiajs/vue3', async (importOriginal) => ({
    ...(await importOriginal<typeof import('@inertiajs/vue3')>()),
    Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
    usePage: () => ({ url: '/admin/anagrafiche/7', props: {} }),
    router: { visit: vi.fn() },
}));
vi.mock('@/composables/permissions', () => ({ usePermission: () => ({
    generatePath: (p: string) => `/${p}`, generateRoute: (n: string) => n,
    hasPermission: (p: string[]) => p.some((x) => permessi.lista.includes(x)), hasRole: (r: string[]) => r.some((x) => permessi.ruoli.includes(x)),
}) }));
(globalThis as unknown as { route: unknown }).route = (nome: string, p?: Record<string, unknown>) => `/${nome}/${p?.tipo}/${p?.id ?? ''}`;
(globalThis as unknown as { ResizeObserver: unknown }).ResizeObserver = class { observe() {} unobserve() {} disconnect() {} };

const PageHeaderGuide = (await import('./PageHeaderGuide.vue')).default;

let w: VueWrapper | null = null;
afterEach(() => { w?.unmount(); w = null; axiosGet.mockReset(); document.body.innerHTML = ''; permessi.lista = ['Vede la storia delle schede']; permessi.ruoli = ['collaboratore']; });
const monta = (altro: Record<string, unknown> = {}) => {
    w = mount(PageHeaderGuide, { attachTo: document.body, props: { pageTitle: 'Modifica anagrafica', guides: [], hasTextGuide: true, textGuideTitle: 'Guida', ...altro } as any });

    return w;
};

describe('«Storia» accanto a «Guida»', () => {
    test('con la prop c\'è, subito dopo «Guida», alto come «Guida»; il clic apre il cassetto della scheda', async () => {
        axiosGet.mockResolvedValue({ data: { titolo: 'Storia di Ida Romano', sottotitolo: 'Anagrafica', totale: 0, pagina: 1, altre: 0, righe: [], oscuramento: null } });
        monta({ storia: { tipo: 'anagrafica', id: 7 } });
        const fila = w!.get('[data-testid="pulsanti-intestazione"]');
        const testi = fila.findAll('button').map((b) => b.text().trim());
        expect(testi.indexOf('Storia')).toBe(testi.indexOf('Guida') + 1);
        const storia = w!.get('[data-testid="pulsante-storia"]');
        expect(storia.classes()).toContain('h-8');
        expect(fila.findAll('button').find((b) => b.text().trim() === 'Guida')!.classes()).toContain('h-8');

        await storia.trigger('click');
        await flushPromises();
        expect(axiosGet).toHaveBeenCalledWith('/registro-modifiche.storia/anagrafica/7');
        expect(document.body.querySelector('[data-testid="cassetto-storia"]')).not.toBeNull();
        expect(document.body.querySelector('[data-testid="storia-vuota"]')?.textContent).toContain('Nel registro non c\'è ancora una modifica di questa scheda.');
    });

    test('le «Storie» dello studio (un utente, le impostazioni) solo per l\'amministratore', () => {
        monta({ storia: { tipo: 'impostazioni_generali' } });
        expect(w!.find('[data-testid="pulsante-storia"]').exists()).toBe(false);
        w!.unmount();
        permessi.ruoli = ['amministratore'];
        monta({ storia: { tipo: 'impostazioni_generali' } });
        expect(w!.find('[data-testid="pulsante-storia"]').exists()).toBe(true);
    });

    test('senza la prop, senza il permesso, o per un condòmino e un fornitore, non c\'è', () => {
        monta();
        expect(w!.find('[data-testid="pulsante-storia"]').exists()).toBe(false);
        w!.unmount();
        permessi.lista = [];
        monta({ storia: { tipo: 'anagrafica', id: 7 } });
        expect(w!.find('[data-testid="pulsante-storia"]').exists()).toBe(false);
        w!.unmount();
        permessi.lista = ['Vede la storia delle schede'];
        permessi.ruoli = ['utente'];
        monta({ storia: { tipo: 'anagrafica', id: 7 } });
        expect(w!.find('[data-testid="pulsante-storia"]').exists()).toBe(false);
    });
});

/** Le pagine del §8 e di P4 (e la scheda figlia nella storia della madre, P22) passano la prop `storia` con il tipo giusto. */
const PAGINE: Array<[string, string]> = [
    ['resources/js/pages/anagrafiche/AnagraficheView.vue', 'anagrafica'],
    ['resources/js/pages/anagrafiche/AnagraficheEdit.vue', 'anagrafica'],
    ['resources/js/pages/fornitori/FornitoriView.vue', 'fornitore'],
    ['resources/js/pages/fornitori/FornitoriEdit.vue', 'fornitore'],
    ['resources/js/pages/buildings/BuildingsEdit.vue', 'condominio'],
    ['resources/js/pages/gestionale/immobili/ImmobiliView.vue', 'immobile'],
    ['resources/js/pages/gestionale/immobili/ImmobiliEdit.vue', 'immobile'],
    ['resources/js/pages/gestionale/tabelle/TabelleEdit.vue', 'tabella'],
    ['resources/js/pages/gestionale/tabelle/quote/QuoteList.vue', 'tabella'],
    ['resources/js/pages/gestionale/pianiRate/PianiRateShow.vue', 'piano_rate'],
    ['resources/js/pages/comunicazioni/ComunicazioniView.vue', 'comunicazione'],
    ['resources/js/pages/comunicazioni/ComunicazioniEdit.vue', 'comunicazione'],
    ['resources/js/pages/segnalazioni/SegnalazioniView.vue', 'segnalazione'],
    ['resources/js/pages/segnalazioni/SegnalazioniEdit.vue', 'segnalazione'],
    ['resources/js/pages/documenti/DocumentiEdit.vue', 'documento'],
    ['resources/js/pages/gestionale/struttura/ImpostazioniCondominio.vue', 'impostazioni_condominio'],
    ['resources/js/pages/gestionale/struttura/RegoleRecupero.vue', 'regole_recupero'],
    ['resources/js/pages/gestionale/gestioni/GestioniEdit.vue', 'gestione'],
    ['resources/js/pages/gestionale/esercizi/EserciziEdit.vue', 'esercizio'],
    ['resources/js/pages/gestionale/movimenti/fatture/FatturaShow.vue', 'fattura_passiva'],
    ['resources/js/pages/gestionale/movimenti/fatture/FatturaRegisterEdit.vue', 'fattura_passiva'],
    ['resources/js/pages/utenti/ModificaUtente.vue', 'utente'],
    ['resources/js/pages/ruoli/ModificaRuolo.vue', 'ruolo'],
    ['resources/js/pages/impostazioni/impostazioniGenerali.vue', 'impostazioni_generali'],
    ['resources/js/pages/impostazioni/impostazioniStampe.vue', 'impostazioni_stampe'],
    ['resources/js/pages/impostazioni/impostazioniMail.vue', 'impostazioni_posta'],
    ['resources/js/pages/impostazioni/impostazioniBackups.vue', 'impostazioni_backup'],
    ['resources/js/pages/gestionale/saldi/SaldiList.vue', 'saldi'],
    ['resources/js/pages/gestionale/casse/CasseList.vue', 'casse'],
    ['resources/js/pages/gestionale/casse/CasseEdit.vue', 'cassa'],
    ['resources/js/pages/gestionale/contributi/ContributiEdit.vue', 'gestione'],
    ['resources/js/pages/gestionale/pianiDeiConti/PianiDeiContiEdit.vue', 'piano_conti'],
    ['resources/js/pages/fornitori/categorie/CategorieList.vue', 'categorie_fornitori'],
    ['resources/js/pages/gestionale/palazzine/PalazzineList.vue', 'condominio'],
    ['resources/js/pages/gestionale/scale/ScaleList.vue', 'condominio'],
    ['resources/js/pages/documenti/categories/CategorieList.vue', 'documenti'],
];

describe('le pagine con «Storia»', () => {
    test.each(PAGINE)('%s ha «Storia» con il tipo «%s»', (file, tipo) => {
        const sorgente = readFileSync(resolve(process.cwd(), file), 'utf8');
        const conLaProp = new RegExp(`:storia="[^"]*tipo:\\s*'${tipo}'`).test(sorgente);
        const nellIntestazione = new RegExp(`<StoriaDellaScheda[^>]*tipo="${tipo}"`).test(sorgente);
        expect(conLaProp || nellIntestazione).toBe(true);
    });
});
