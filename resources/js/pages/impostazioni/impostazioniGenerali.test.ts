// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
// @vitest-environment jsdom

/**
 * Le impostazioni generali: il ruolo predefinito dei nuovi utenti lo cambia solo un amministratore (Coda 258,
 * decisione 134.1).
 *
 * Chi gestisce le impostazioni generali con un ruolo su misura salva il resto del modulo; la tendina del ruolo
 * della registrazione gli si mostra senza farsi cambiare, con la nota che dice perché. Il server lo dice alla
 * pagina con `ruolo_registrazione_modificabile`. Aperta e mandata senza toccare niente, la pagina rimanda il
 * ruolo com'era.
 *
 * In più: le tre card in testa prendono titoli e frasi dai file di lingua, e i quattro menu della pagina (lingua, condominio predefinito, ruolo, righe per
 * pagina) sono `v-select` come nel resto del programma, con lo stesso comportamento.
 *
 * Le traduzioni non sono caricate: `trans()` restituisce la chiave.
 */

import { afterEach, beforeEach, describe, expect, test, vi } from 'vitest';
import { flushPromises, mount, shallowMount, type VueWrapper } from '@vue/test-utils';
import { router } from '@inertiajs/core';
import vSelect from 'vue-select';

const pagina = vi.hoisted(() => ({ props: {} as Record<string, unknown> }));

vi.mock('@inertiajs/vue3', async (importOriginal) => ({
    ...(await importOriginal<typeof import('@inertiajs/vue3')>()),
    Head: { template: '<div />' },
    Link: { template: '<a><slot /></a>' },
    usePage: () => pagina,
}));
vi.mock('laravel-vue-i18n', () => ({
    trans: (chiave: string) => chiave,
    wTrans: (chiave: string) => ({ value: chiave }),
    isLoaded: () => true,
    loadLanguageAsync: async () => undefined,
}));
(globalThis as Record<string, unknown>).route = (nome: string) => `/${nome}`;

const ImpostazioniGenerali = (await import('./impostazioniGenerali.vue')).default;

function monta(modificabile: boolean, altro: Record<string, unknown> = {}) {
    pagina.props = datiDellaPagina(modificabile, altro);

    return shallowMount(ImpostazioniGenerali, {
        global: { renderStubDefaultSlot: true, mocks: { route: (n: string) => `/${n}` } },
    });
}

function datiDellaPagina(modificabile: boolean, altro: Record<string, unknown> = {}) {
    return {
        flash: {},
        can_register: true,
        language: 'it',
        app_name: 'Condominio «Aurora»',
        open_condominio_on_login: false,
        default_condominio_id: null,
        condomini: [],
        default_user_role: 'utente',
        commenti_segnalazioni_moderati_di_serie: false,
        commenti_comunicazioni_moderati_di_serie: true,
        default_per_page: 30,
        per_page_disponibili: [10, 30, 50],
        roles: [{ id: 1, name: 'utente' }, { id: 2, name: 'amministratore' }],
        ruolo_registrazione_modificabile: modificabile,
        ...altro,
    };
}

/** La tendina del ruolo della registrazione: l'unica il cui valore è il ruolo. */
function tendinaDelRuolo(wrapper: ReturnType<typeof monta>) {
    return wrapper.findAllComponents(vSelect).find((tendina) => tendina.props('modelValue') === 'utente')!;
}

beforeEach(() => {
    vi.spyOn(router, 'post').mockImplementation(() => undefined as never);
    vi.mocked(router.post).mockClear();
});

describe('impostazioniGenerali — il ruolo della registrazione (Coda 258, decisione 134.1)', () => {
    test('a chi non è amministratore la tendina non si fa cambiare, e la nota dice perché', () => {
        const wrapper = monta(false);

        expect(tendinaDelRuolo(wrapper).props('disabled')).toBe(true);
        expect(wrapper.find('[data-test="ruolo-registrazione-bloccato"]').text())
            .toBe('impostazioni.ruolo_registrazione.solo_amministratore');
    });

    test('all\'amministratore la tendina si fa cambiare, senza la nota', () => {
        const wrapper = monta(true);

        expect(tendinaDelRuolo(wrapper).props('disabled')).toBeFalsy();
        expect(wrapper.find('[data-test="ruolo-registrazione-bloccato"]').exists()).toBe(false);
    });

    test('aperta e mandata senza toccare niente, rimanda il ruolo com\'era', () => {
        const wrapper = monta(false);

        (wrapper.vm as unknown as { $: { setupState: { submit: () => void } } }).$.setupState.submit();

        const chiamata = vi.mocked(router.post).mock.calls.at(-1)!;
        expect(chiamata[0]).toBe('/impostazioni.generali.store');
        expect(chiamata[1]).toMatchObject({ default_user_role: 'utente', app_name: 'Condominio «Aurora»', default_per_page: 30 });
    });
});

describe('impostazioniGenerali — la prossima azione non è più qui', () => {
    test('la scelta fra «Il programma suggerisce la prossima azione» e «Scelgo io» è del condominio: la pagina non ha la riga, e il modulo non la manda', () => {
        const wrapper = monta(true);

        expect(wrapper.find('[data-test="passo-proposto"]').exists()).toBe(false);
        expect(wrapper.text()).not.toContain('Il programma propone il passo');
        (wrapper.vm as unknown as { $: { setupState: { submit: () => void } } }).$.setupState.submit();
        expect(Object.keys(vi.mocked(router.post).mock.calls.at(-1)![1] as Record<string, unknown>)).not.toContain('passo_proposto');
    });
});

const mandato = (w: ReturnType<typeof monta>) => {
    (w.vm as unknown as { $: { setupState: { submit: () => void } } }).$.setupState.submit();

    return vi.mocked(router.post).mock.calls.at(-1)![1] as Record<string, unknown>;
};

describe('impostazioniGenerali — le tre card in testa', () => {
    test('titoli e frasi vengono dai file di lingua', () => {
        const w = monta(true);
        const card = (w.findComponent({ name: 'PageHeaderGuide' }).props('guides') as { title: string; description: string }[]);

        expect(card.map((c) => [c.title, c.description])).toEqual(
            ['program_access', 'reports_communications', 'security'].map((k) => [`impostazioni.guides.${k}_title`, `impostazioni.guides.${k}_desc`]),
        );
    });

    test('la pagina apre la guida a cassetto', async () => {
        const w = monta(true);
        const guida = () => w.findComponent({ name: 'ImpostazioniGeneraliGuide' });
        expect(guida().props('open')).toBe(false);

        w.findComponent({ name: 'PageHeaderGuide' }).vm.$emit('open-text-guide');
        await w.vm.$nextTick();

        expect(guida().props('open')).toBe(true);
    });
});

describe('impostazioniGenerali — i quattro menu sono v-select', () => {
    test('con l\'apertura del condominio accesa sono quattro, nessuno si svuota o cerca fuori dal condominio, e le scelte sono quelle di prima', async () => {
        const w = monta(true, { open_condominio_on_login: true, default_condominio_id: 3, condomini: [{ id: 3, nome: 'Aurora' }, { id: 5, nome: 'Belvedere' }] });
        await flushPromises();
        const menu = w.findAllComponents(vSelect);

        expect(menu).toHaveLength(4);
        expect(menu.map((m) => m.props('clearable'))).toEqual([false, false, false, false]);
        expect(menu.map((m) => m.props('searchable'))).toEqual([false, true, false, false]);
        const [lingua, condominio, ruolo, righe] = menu;
        expect(lingua.props('modelValue')).toBe('it');
        expect((lingua.props('options') as { valore: string }[]).map((o) => o.valore)).toEqual(['it', 'en', 'pt', 'es']);
        expect(condominio.props('modelValue')).toBe('3');
        expect((condominio.props('options') as { id: string; nome: string }[]).map((o) => [o.id, o.nome])).toEqual([['3', 'Aurora'], ['5', 'Belvedere']]);
        expect((ruolo.props('options') as { nome: string }[]).map((o) => o.nome)).toEqual(['utente', 'amministratore']);
        expect(righe.props('modelValue')).toBe(30);
        expect(righe.props('options')).toEqual([10, 30, 50]);
    });

    test('ciò che si sceglie parte col salvataggio: la lingua, il condominio come testo, il ruolo, le righe come numero', async () => {
        const w = monta(true, { open_condominio_on_login: true, default_condominio_id: null, condomini: [{ id: 5, nome: 'Belvedere' }] });
        await flushPromises();
        const [lingua, condominio, ruolo, righe] = w.findAllComponents(vSelect);
        // Il menu è un segnaposto: si manda ciò che manderebbe scegliendo quella voce (con la sua `reduce`, se l'ha).
        const scegli = (m: typeof lingua, opzione: unknown) => {
            const riduci = m.props('reduce') as ((o: unknown) => unknown);
            m.vm.$emit('update:modelValue', typeof opzione === 'object' ? riduci(opzione) : opzione);
        };

        expect(condominio.props('modelValue')).toBe('');
        scegli(lingua, (lingua.props('options') as unknown[])[1]);
        scegli(condominio, (condominio.props('options') as unknown[])[0]);
        scegli(ruolo, (ruolo.props('options') as unknown[])[1]);
        scegli(righe, 50);
        await w.vm.$nextTick();

        expect(mandato(w)).toMatchObject({ language: 'en', default_condominio_id: '5', default_user_role: 'amministratore', default_per_page: 50 });
    });

    test('con l\'apertura del condominio spenta il menu del condominio non c\'è', async () => {
        const w = monta(true);
        await flushPromises();

        expect(w.findAllComponents(vSelect)).toHaveLength(3);
    });
});

describe('impostazioniGenerali — i menu a video', () => {
    let w: VueWrapper | null = null;
    afterEach(() => { w?.unmount(); w = null; document.body.innerHTML = ''; });

    const montaTutto = async (altro: Record<string, unknown> = {}) => {
        pagina.props = datiDellaPagina(true, altro);
        w = mount(ImpostazioniGenerali, {
            attachTo: document.body,
            global: {
                stubs: { AppLayout: { template: '<div><slot /></div>' }, Head: true, PageHeaderGuide: true, ImpostazioniGeneraliGuide: true, Switch: true },
                mocks: { route: (n: string) => `/${n}` },
            },
        });
        await flushPromises();
    };

    test('chiuso, il ruolo si legge com\'è scritto; aperto, le scelte con l\'iniziale maiuscola, come prima; e la riga rossa dell\'errore sul condominio', async () => {
        await montaTutto();
        const ruolo = w!.findAllComponents(vSelect).find((m) => m.props('modelValue') === 'utente')!;
        const scelto = ruolo.get('.vs__selected');
        expect(scelto.text()).toBe('utente');
        expect(scelto.find('.capitalize').exists()).toBe(false);

        await ruolo.get('input').trigger('focus');
        await flushPromises();
        const voci = ruolo.findAll('.vs__dropdown-option span');
        expect(voci.map((v) => [v.text(), v.classes().includes('capitalize')])).toEqual([['utente', true], ['amministratore', true]]);
    });

    test('i menu stanno nel riquadro dei campi, alti come i campi accanto, e il condominio con un errore ha il bordo rosso', async () => {
        await montaTutto({ open_condominio_on_login: true, default_condominio_id: null, condomini: [{ id: 5, nome: 'Belvedere' }] });
        const menu = w!.findAllComponents(vSelect);
        expect(menu.every((m) => m.element.parentElement!.classList.contains('menu-campo') && m.element.parentElement!.classList.contains('menu-impostazioni'))).toBe(true);
        expect(menu[1].find('input').attributes('placeholder')).toBe('impostazioni.placeholder.select_building');

        (w!.vm as unknown as { $: { setupState: { form: { setError: (c: string, m: string) => void } } } }).$.setupState.form.setError('default_condominio_id', 'Devi selezionare un condominio.');
        await flushPromises();
        expect(menu[1].element.parentElement!.classList.contains('menu-errore')).toBe(true);
        expect(w!.text()).toContain('Devi selezionare un condominio.');
    });
});
