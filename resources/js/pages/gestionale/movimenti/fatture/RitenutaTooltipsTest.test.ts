// @vitest-environment jsdom

/**
 * Due rischi distinti nello stesso pezzo di form, verificati insieme perché condividono lo
 * stesso markup (un'icona informativa dentro una `<label>`).
 *
 * **1) Il click sul tooltip non deve toccare il checkbox.** Cliccare QUALUNQUE elemento
 * dentro una `<label>` attiva per costruzione del browser il control a cui è associata —
 * un'icona informativa che, cliccata, spunta o toglie la spunta al checkbox non sarebbe
 * un'icona informativa, sarebbe un secondo modo (silenzioso) di cambiare il valore.
 * `@click.prevent` sull'icona è la difesa: per specifica HTML, un click con
 * `preventDefault()` chiamato sull'elemento cliccato annulla anche l'attivazione implicita
 * del control associato alla label.
 *
 * Dal 05/09/2026 le icone `.cursor-help` nello stesso form sono DUE — «Applica ritenuta
 * d'acconto» (ambra) e «Concorre alla base ritenuta» (grigia) — quindi i selettori qui sotto
 * distinguono le due per colore (`text-amber-400` vs `text-slate-300`), non per ordine nel
 * documento: un `wrapper.find('.cursor-help')` senza qualificatore becca sempre la prima,
 * e da quando ce n'è una seconda prima nel markup avrebbe silenziosamente smesso di testare
 * quella giusta.
 *
 * ⚠️ **I test sotto NON sono la prova — sono solo la controricevuta.** Provato con una
 * controprova (rimosso `@click.prevent` da entrambe le icone, rieseguito il file): zero
 * test diventano rossi. jsdom non riproduce l'attivazione implicita label→control per un
 * elemento generico al suo interno — è un comportamento del motore di rendering nativo, non
 * del DOM che jsdom emula. La prova vera è stata fatta nel browser reale: checkbox spuntato,
 * click sull'icona alle sue coordinate esatte (ricalcolate da `getBoundingClientRect()`),
 * checkbox riletto — rimasto spuntato, per entrambe le icone. Se questo markup cambia,
 * quella verifica va rifatta a video: questo file da solo non se ne accorgerebbe.
 *
 * **2) La percentuale mostrata deve essere quella del regime del fornitore.** Aggiunta lo
 * stesso giorno su segnalazione diretta di Vincenzo: il badge «Ritenuta» diceva che il
 * fornitore ne era soggetto ma non a quale percentuale. La fonte è
 * `REGIMI_RITENUTA_PREVIEW[tipo_ritenuta]`, la stessa tabella che governa il calcolo vero —
 * non un valore riscritto a mano qui, che potrebbe disallinearsi da quella.
 */

import { describe, expect, test, vi } from 'vitest';
import { mount } from '@vue/test-utils';

const axios = vi.hoisted(() => ({ get: vi.fn(async () => ({ data: [] })), post: vi.fn() }));
vi.mock('axios', () => ({ default: axios }));

vi.mock('@inertiajs/vue3', async (importOriginal) => ({
    ...(await importOriginal<typeof import('@inertiajs/vue3')>()),
    Head: { template: '<span />' },
    usePage: () => ({
        props: { auth: { user: { roles: ['amministratore'], permissions: [] } } },
    }),
}));

import FatturaRegisterNew from './FatturaRegisterNew.vue';
import FatturaRegisterEdit from './FatturaRegisterEdit.vue';

(globalThis as any).route = (n: string) => `/${n}`;

const stubsComuni = {
    GestionaleLayout: { template: '<div><slot /></div>' },
    PageHeaderGuide: { template: '<div><slot /></div>' },
    FatturaRegistrazioneGuide: true,
    WidgetDoubleLock: true,
    ModalSpesaImprevista: true,
    ModalOverrideBudget: true,
    ModalImportaXml: true,
    Head: { template: '<span />' },
    Link: { template: '<a><slot /></a>' },
    'v-select': true,
    MoneyInput: {
        name: 'MoneyInput',
        props: ['modelValue'],
        emits: ['update:modelValue'],
        template: '<input :value="modelValue" @input="$emit(\'update:modelValue\', $event.target.value)" />',
    },
};

const FORNITORE_APPALTO_4 = {
    id: 10, ragione_sociale: 'Mario Rossi Impianti s.r.l',
    soggetto_ritenuta: true, tipo_ritenuta: 'appalto_4', regime_forfetario: false,
};
const FORNITORE_AUTONOMO_20 = {
    id: 11, ragione_sociale: 'Studio Bianchi',
    soggetto_ritenuta: true, tipo_ritenuta: 'lavoro_autonomo_20', regime_forfetario: false,
};
const CONTO = { id: 55, nome: 'Pulizia scale', residuo_budget: 500_000, gia_versato_cents: 0, ultimi_movimenti: [] };
const BANCA = { id: 7, nome: 'Conto Corrente', saldo_attuale: 100_000 };

function renderNew(fornitori = [FORNITORE_APPALTO_4]) {
    return mount(FatturaRegisterNew, {
        props: {
            condominio: { id: 28, nome: 'Condominio Demo KM' },
            condomini: [{ id: 28, nome: 'Condominio Demo KM' }],
            esercizio: { id: 41, nome: '2026', stato: 'aperto' },
            esercizi: [{ id: 41, nome: '2026', stato: 'aperto' }],
            gestioni: [{ id: 35, nome: 'Gestione ordinaria 2026', tipo: 'ordinaria', esercizio_ids: [41] }],
            fornitori,
            conti: [CONTO],
            banche: [BANCA],
            immobili: [],
            debiti_patrimoniali: [],
            fatture_pregresse_registrate: [],
            fondi_riserva: [],
            capienza_rata_zero: 0,
            incassato_rata_zero: 0,
        },
        global: { stubs: stubsComuni, mocks: { route: (n: string) => `/${n}` } },
    });
}

function renderEdit(fornitore = FORNITORE_APPALTO_4) {
    return mount(FatturaRegisterEdit, {
        props: {
            condominio: { id: 28, nome: 'Condominio Demo KM' },
            condomini: [{ id: 28, nome: 'Condominio Demo KM' }],
            esercizio: { id: 41, nome: '2026', stato: 'aperto' },
            esercizi: [{ id: 41, nome: '2026', stato: 'aperto' }],
            gestioni: [{ id: 35, nome: 'Gestione ordinaria 2026', esercizi: [{ id: 41 }] }],
            fornitori: [fornitore],
            conti: [CONTO],
            banche: [BANCA],
            immobili: [],
            debiti_patrimoniali: [],
            fatture_pregresse_registrate: [],
            fondi_riserva: [],
            capienza_rata_zero: 0,
            incassato_rata_zero: 0,
            nota_storno_originale: null,
            fattura: {
                id: 91, fornitore_id: fornitore.id, fornitore,
                esercizio_id: 41, gestione_id: 35, tipo_documento: 'fattura', is_pregresso: false,
                numero_documento: 'FT-2026-0001', numero_protocollo: 'FTP-2026-00001',
                data_documento: '2026-09-05', data_scadenza: '2026-09-15',
                conto_corrente_id: BANCA.id, modalita_pagamento: 'bonifico', iban_fornitore: null,
                stato_approvazione: 'approvata',
                importo_imponibile: 100000, importo_iva: 22000, importo_ritenuta: 4000,
                totale_documento: 122000, netto_a_pagare: 118000,
                dati_extra: { fiscal: {}, competenza: null, override_budget: null },
                righe: [{
                    id: 300, descrizione: 'Appalto', conto_id: CONTO.id, immobile_id: null,
                    importo_imponibile: 100000, importo_iva: 22000, aliquota_iva: '22.00', concorre_base_ritenuta: true,
                }],
                documenti: [], coperture: [],
            },
        },
        global: { stubs: stubsComuni, mocks: { route: (n: string) => `/${n}` } },
    });
}

describe('FatturaRegisterNew — i tooltip di ritenuta non toccano i checkbox', () => {
    async function conFornitoreSelezionato() {
        const wrapper = renderNew();
        const vm = wrapper.vm as any;
        // Il riquadro ritenuta (e quindi entrambi i checkbox) compare solo dopo la scelta
        // del fornitore: FatturaRegisterNew parte senza fornitore_id, a differenza di Edit.
        vm.form.fornitore_id = FORNITORE_APPALTO_4.id;
        await wrapper.vm.$nextTick();
        return { wrapper, vm };
    }

    test('il valore di partenza è "applica" spuntato e "concorre" spuntato', async () => {
        const { vm } = await conFornitoreSelezionato();

        expect(vm.applicaRitenutaEffective).toBe(true);
        expect(vm.form.righe[0].concorre_base_ritenuta).toBe(true);
    });

    test('cliccare l\'icona di "Applica ritenuta d\'acconto" non spunta via il checkbox del pannello', async () => {
        const { wrapper, vm } = await conFornitoreSelezionato();

        const icona = wrapper.find('.cursor-help.text-amber-400');
        expect(icona.exists()).toBe(true, 'l\'icona del tooltip "Applica ritenuta" deve esistere nel DOM');

        await icona.trigger('click');

        expect(vm.applicaRitenutaEffective).toBe(true, 'il click sull\'icona ha disattivato "Applica ritenuta d\'acconto" tramite la label: @click.prevent non basta.');
    });

    test('cliccare l\'icona di "Concorre alla base ritenuta" non cambia lo stato del checkbox di riga', async () => {
        const { wrapper, vm } = await conFornitoreSelezionato();

        const icona = wrapper.find('.cursor-help.text-slate-300');
        expect(icona.exists()).toBe(true, 'l\'icona del tooltip "Concorre" deve esistere nel DOM');

        await icona.trigger('click');

        expect(vm.form.righe[0].concorre_base_ritenuta).toBe(true, 'il click sull\'icona ha attivato il checkbox tramite la label: @click.prevent non basta.');
    });

    test('il controesempio: cliccare DAVVERO i checkbox li cambia — prova che i test sopra non sono falsi positivi', async () => {
        const { wrapper, vm } = await conFornitoreSelezionato();

        const checkboxApplica = wrapper.find('input[type="checkbox"]');
        await checkboxApplica.setValue(false);
        expect(vm.applicaRitenutaEffective).toBe(false);

        // Riseleziono per riportare il pannello "concorre" visibile e testare quel checkbox.
        await checkboxApplica.setValue(true);
        const checkboxes = wrapper.findAll('input[type="checkbox"]');
        const checkboxRiga = checkboxes[checkboxes.length - 1];
        await checkboxRiga.setValue(false);
        expect(vm.form.righe[0].concorre_base_ritenuta).toBe(false);
    });
});

describe('FatturaRegisterEdit — stessa prova, stesso rischio (markup duplicato)', () => {
    test('cliccare l\'icona di "Applica ritenuta d\'acconto" non cambia il checkbox del pannello', async () => {
        const wrapper = renderEdit();
        const vm = wrapper.vm as any;

        const icona = wrapper.find('.cursor-help.text-amber-400');
        expect(icona.exists()).toBe(true);

        await icona.trigger('click');

        expect(vm.applicaRitenutaEffective).toBe(true, 'il click sull\'icona ha disattivato "Applica ritenuta d\'acconto" tramite la label anche in modifica.');
    });

    test('cliccare l\'icona di "Concorre alla base ritenuta" non cambia lo stato del checkbox', async () => {
        const wrapper = renderEdit();
        const vm = wrapper.vm as any;

        const icona = wrapper.find('.cursor-help.text-slate-300');
        expect(icona.exists()).toBe(true);

        await icona.trigger('click');

        expect(vm.form.righe[0].concorre_base_ritenuta).toBe(true, 'il click sull\'icona ha attivato il checkbox tramite la label anche in modifica.');
    });
});

describe('la percentuale di ritenuta mostrata segue il regime del fornitore', () => {
    test('FatturaRegisterNew: badge e checkbox mostrano 4% per un fornitore appalto_4', async () => {
        const wrapper = renderNew();
        const vm = wrapper.vm as any;
        vm.form.fornitore_id = FORNITORE_APPALTO_4.id;
        await wrapper.vm.$nextTick();

        expect(wrapper.text()).toContain('Ritenuta 4%');
        expect(wrapper.text()).toContain('Applica ritenuta d\'acconto 4%');
    });

    test('FatturaRegisterNew: badge e checkbox mostrano 20% per un fornitore lavoro_autonomo_20', async () => {
        const wrapper = renderNew([FORNITORE_AUTONOMO_20]);
        const vm = wrapper.vm as any;
        vm.form.fornitore_id = FORNITORE_AUTONOMO_20.id;
        await wrapper.vm.$nextTick();

        expect(wrapper.text()).toContain('Ritenuta 20%');
        expect(wrapper.text()).toContain('Applica ritenuta d\'acconto 20%');
    });

    test('FatturaRegisterEdit: badge e checkbox mostrano 4% per il fornitore già in fattura', () => {
        const wrapper = renderEdit(FORNITORE_APPALTO_4);

        expect(wrapper.text()).toContain('Ritenuta 4%');
        expect(wrapper.text()).toContain('Applica ritenuta d\'acconto 4%');
    });

    test('FatturaRegisterEdit: badge e checkbox mostrano 20% quando il fornitore è lavoro_autonomo_20', () => {
        const wrapper = renderEdit(FORNITORE_AUTONOMO_20);

        expect(wrapper.text()).toContain('Ritenuta 20%');
        expect(wrapper.text()).toContain('Applica ritenuta d\'acconto 20%');
    });
});
