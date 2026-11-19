// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
// @vitest-environment jsdom

/**
 * Decisioni 493 e 494: le otto scelte sugli interessi degli eredi (la lista con gli interruttori, la riga «Adesso: …» che segue la scelta, la «i» con il
 * caso), la card della successione («Come le regole del condominio», «Cambia per questa successione», «Torna alle regole del condominio») e le funzioni
 * della pagina (le cifre scritte in «Cambia», le frasi, la riga «ereditato» di «Rate scadute»).
 *
 * Che cosa non copre: il salvataggio (il server, `InteressiSulDebitoEreditatoTest.php`); il riassunto della card (lo scrive il server con l'anteprima); il
 * tocco e il tema scuro (a video).
 */
import { afterEach, describe, expect, test, vi } from 'vitest';
import { flushPromises, mount, type VueWrapper } from '@vue/test-utils';
import {
    GUIDA_INTERESSI_EREDI, adessoDellaScelta, centesimiScritti, fraseDelleConseguenze, perchéDelMotivo, perchéNonVa, scelteDalleVoci, scelteDiverse, testoEreditatoInElenco,
    type VoceInteressiEredi,
} from '@/lib/gestionale/addebiti/interessiDegliEredi';

vi.mock('@/composables/permissions', () => ({ usePermission: () => ({ generateRoute: (nome: string) => nome }) }));
vi.mock('@inertiajs/vue3', () => ({ Link: { props: ['href'], template: '<a :href="href"><slot /></a>' } }));
(globalThis as unknown as { route: unknown }).route = (nome: string) => `/${nome}`;
(globalThis as unknown as { ResizeObserver: unknown }).ResizeObserver = class { observe() {} unobserve() {} disconnect() {} };

const Lista = (await import('./InteressiDegliEredi.vue')).default;
const Card = (await import('../immobili/InteressiDegliEredi.vue')).default;

const voce = (chiave: VoceInteressiEredi['chiave'], valore: boolean): VoceInteressiEredi => ({
    chiave, titolo: `Titolo ${chiave}`, valore, adesso: '', acceso: `Adesso: ${chiave} acceso.`, spento: `Adesso: ${chiave} spento.`, esempio: `Per esempio: ${chiave}.`, di_serie: valore,
});
const VOCI = [voce('interessi_sugli_interessi', false), voce('dal_decesso', true), voce('tolleranza_rata_nuova', false), voce('rata_per_rata', true),
    voce('versamento_prima', true), voce('anche_accordo', true), voce('buco_continuano', false), voce('interessi_defunto_agli_eredi', true)];

let w: VueWrapper | null = null;
afterEach(() => { w?.unmount(); w = null; document.body.innerHTML = ''; });

describe('le funzioni', () => {
    test('le cifre scritte in «Cambia»: solo in meno, da 1 centesimo alla proposta', () => {
        expect(centesimiScritti('0,30')).toBe(30);
        expect(centesimiScritti('€ 1,05')).toBe(105);
        expect(centesimiScritti('2.5')).toBe(250);
        expect(centesimiScritti('tre')).toBeNull();
        expect(perchéNonVa('0,30', 37)).toBeNull();
        expect(perchéNonVa('0,37', 37)).toBeNull();
        expect(perchéNonVa('0,38', 37)).toBe('Più della proposta no: è il massimo che le regole del condominio danno. Per un tasso diverso cambia la regola in «Recupero dei crediti».');
        expect(perchéNonVa('0', 37)).toBe('Per non chiedere niente, togli la riga.');
        expect(perchéNonVa('abc', 37)).toBe('Scrivi la cifra in euro, per esempio 0,30.');
        expect(perchéDelMotivo('', 1)).toBe('Scrivi perché cambi le cifre, in almeno 5 caratteri: resta scritto nell\'addebito.');
        expect(perchéDelMotivo('', 0)).toBeNull();
        expect(perchéDelMotivo('Accordo', 1)).toBeNull();
    });

    test('le frasi', () => {
        expect(fraseDelleConseguenze('2026-12-31', 37, 30)).toBe('I giorni fino al 31/12/2026 restano registrati: la prossima proposta riparte dal 01/01/2027, e i € 0,07 di '
            + 'differenza non si chiedono più. Se invece togli la riga, quei giorni tornano nella prossima proposta.');
        expect(testoEreditatoInElenco({ da: 'Ettore Fabbri', successione_del: '2026-11-17' })).toBe('da Ettore Fabbri, successione del 17/11/2026, non ancora in una rata');
        expect(adessoDellaScelta(VOCI[1], false)).toBe('Adesso: dal_decesso spento.');
        expect(scelteDiverse(scelteDalleVoci(VOCI), { ...scelteDalleVoci(VOCI), dal_decesso: false })).toBe(true);
        expect(GUIDA_INTERESSI_EREDI.map((g) => g.chiave)).toEqual(VOCI.map((v) => v.chiave));
    });
});

describe('la lista delle otto scelte', () => {
    test('un interruttore per scelta, con la riga «Adesso: …» che segue il valore e la «i» con il caso; cambiare emette tutte e otto', async () => {
        w = mount(Lista, { attachTo: document.body, props: { voci: VOCI, modelValue: scelteDalleVoci(VOCI), modificabile: true } });
        expect(w.findAll('[data-testid^="scelta-"]')).toHaveLength(8);
        expect(w.get('[data-testid="scelta-dal_decesso"] [data-testid="adesso"]').text()).toBe('Adesso: dal_decesso acceso.');
        expect(w.get('[data-testid="scelta-dal_decesso"] [data-testid="esempio"]').attributes('aria-label')).toBe('Per esempio: dal_decesso.');
        await w.get('[data-testid="interruttore-dal_decesso"]').trigger('click');
        const emesso = w.emitted('update:modelValue')![0][0] as Record<string, boolean>;
        expect(emesso).toEqual({ ...scelteDalleVoci(VOCI), dal_decesso: false });
        await w.setProps({ modelValue: emesso });
        expect(w.get('[data-testid="scelta-dal_decesso"] [data-testid="adesso"]').text()).toBe('Adesso: dal_decesso spento.');
    });

    test('senza il permesso gli interruttori sono spenti', () => {
        w = mount(Lista, { attachTo: document.body, props: { voci: VOCI, modelValue: scelteDalleVoci(VOCI), modificabile: false } });
        expect(w.get('[data-testid="interruttore-dal_decesso"]').attributes('disabled')).toBeDefined();
    });
});

describe('la card della successione', () => {
    test('di serie dice le regole del condominio con il collegamento; «Cambia per questa successione» apre gli otto interruttori; «Torna» le rimette', async () => {
        w = mount(Card, { attachTo: document.body, props: { condominioId: 3, frase: 'Elena e Marco pagano gli interessi sul debito di Ettore Fabbri dal 17/11/2026, solo sulle rate.', voci: VOCI, modelValue: null } });
        expect(w.get('[data-testid="riassunto-eredi"]').text()).toContain('Elena e Marco pagano');
        expect(w.get('[data-testid="come-il-condominio"]').text()).toContain('Come le regole del condominio, in');
        expect(w.get('[data-testid="vai-alle-regole"]').attributes('href')).toBe('/gestionale.recupero-crediti.index');
        expect(w.find('[data-testid="interessi-degli-eredi"]').exists()).toBe(false);
        await w.get('[data-testid="cambia-per-questa"]').trigger('click');
        expect(w.findAll('[data-testid^="scelta-"]')).toHaveLength(8);
        await w.get('[data-testid="interruttore-anche_accordo"]').trigger('click');
        const emesso = w.emitted('update:modelValue')![0][0] as Record<string, boolean>;
        expect(emesso.anche_accordo).toBe(false);
        await w.setProps({ modelValue: emesso });
        await flushPromises();
        expect(w.find('[data-testid="cambiate-per-questa"]').exists()).toBe(true);
        await w.get('[data-testid="torna-al-condominio"]').trigger('click');
        expect(w.emitted('update:modelValue')!.at(-1)![0]).toBeNull();
    });

    test('seconda prova della beta.12: la card manda solo le scelte cambiate rispetto al condominio, e dice quali; rimessa come il condominio, niente', async () => {
        const delCondominio = scelteDalleVoci(VOCI);
        w = mount(Card, { attachTo: document.body, props: { condominioId: 3, frase: 'Elena e Marco pagano.', voci: VOCI, modelValue: null, delCondominio } });
        await w.get('[data-testid="cambia-per-questa"]').trigger('click');
        await w.get('[data-testid="interruttore-dal_decesso"]').trigger('click');
        const emesso = w.emitted('update:modelValue')![0][0] as Record<string, boolean>;
        // Con il codice di prima: tutte e otto, e la successione restava ferma anche sulle scelte non toccate.
        expect(emesso).toEqual({ dal_decesso: false });
        await w.setProps({ modelValue: emesso });
        expect(w.get('[data-testid="cambiate-per-questa"]').text().replace(/\s+/g, ' ')).toBe('Cambiate per questa successione: «Titolo dal_decesso» spento. Le altre seguono le regole del condominio, in Recupero dei crediti.');
        // L'interruttore mostra il valore della successione sopra quello del condominio; rimesso com'era, la successione segue di nuovo il condominio.
        expect(w.get('[data-testid="interruttore-dal_decesso"]').attributes('data-state')).toBe('unchecked');
        await w.get('[data-testid="interruttore-dal_decesso"]').trigger('click');
        expect(w.emitted('update:modelValue')!.at(-1)![0]).toBeNull();
    });
});
