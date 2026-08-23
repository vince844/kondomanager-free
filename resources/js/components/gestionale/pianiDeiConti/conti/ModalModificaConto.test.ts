// @vitest-environment jsdom

/**
 * Il modulo «Modifica voce» non deve proporre lo speso al posto del preventivo.
 *
 * ## Il difetto
 *
 * `ContoResource` espone `importo` come stringa formattata a partire dal valore che
 * `PianoContiController` ha portato al maggiore fra preventivo e spesa — il *fabbisogno*. Nella
 * barra di copertura è il numero giusto; dentro un campo che al salvataggio **riscrive**
 * `conti.importo` è una bomba a orologeria.
 *
 * Il giro, misurato: «€ 6.000,00» → `MoneyInput` normalizza a «6000.00» → `MoneyHelper::toCents()`
 * riconosce una stringa numerica e produce 600000 → a database. Su una voce con preventivo
 * € 5.000,00 e speso € 6.000,00 bastava aprire «Modifica» per cambiare una nota e salvare
 * perché il preventivo deliberato diventasse € 6.000,00, senza un avviso.
 *
 * Nessuna delle due guardie del controller lo intercettava: il soft lock scatta quando il valore
 * *scende*, e l'hard lock esiste solo con rate approvate — dove però produceva il difetto
 * speculare, rifiutando con «l'importo è bloccato da rate già approvate o emesse» anche chi
 * l'importo non l'aveva toccato.
 *
 * ## Perché il test sta qui e non in PHP
 *
 * Il controller fa quello che gli si chiede: persiste il numero che riceve. Un test PHP
 * sull'update passerebbe identico prima e dopo la correzione. Il difetto vive per intero
 * nell'inizializzazione del campo, cioè in questo componente.
 */

import { beforeEach, describe, expect, test, vi } from 'vitest';
import { mount } from '@vue/test-utils';
import { reactive } from 'vue';

vi.mock('@inertiajs/vue3', () => ({
    useForm: (dati: Record<string, unknown>) =>
        reactive({
            ...dati,
            errors: {},
            processing: false,
            clearErrors: vi.fn(),
            reset: vi.fn(),
            put: vi.fn(),
            post: vi.fn(),
        }),
}));

vi.mock('laravel-vue-i18n', () => ({ trans: (chiave: string) => chiave }));

// `vue-select` è importato dentro l'SFC, non registrato globalmente: uno stub per nome non lo
// intercetta. Si sostituisce il modulo, che è anche il modo di non trascinare in questo test le
// sue computed sulle opzioni — qui non c'entrano nulla.
vi.mock('vue-select', () => ({ default: { name: 'VSelectFinto', template: '<div />' } }));

vi.mock('@/composables/useCapitoliConti', () => ({
    useCapitoliConti: () => ({
        capitoli: { value: [] },
        isLoading: { value: false },
        fetchCapitoliConti: vi.fn(),
        reset: vi.fn(),
    }),
}));

const ModalModificaConto = (await import('./ModalModificaConto.vue')).default;

const voceInSforo = {
    id: 7,
    nome: 'Pulizia scale',
    tipo: 'spesa',
    parent_id: 3,
    is_capitolo: false,
    // Il gonfiaggio: `importo` e `importo_raw` valgono già lo speso...
    importo: '€ 6.000,00',
    importo_raw: 600000,
    // ...mentre il preventivo deliberato resta qui.
    budget_originale_raw: 500000,
    speso_raw: 600000,
    tabelle_millesimali: [],
    sottoconti: [],
};

const monta = (conto: Record<string, unknown>) =>
    mount(ModalModificaConto, {
        props: {
            show: true,
            conto,
            condominioId: 1,
            esercizioId: 1,
            pianoContoId: 1,
            tabelle: [],
            fornitori: [],
        },
        global: {
            stubs: {},
        },
        attachTo: document.body,
    });

/**
 * Il valore che l'utente legge nel campo importo, mascherato da `MoneyInput`.
 *
 * Si cerca in `document.body` e non nel wrapper: il contenuto del dialogo viene teletrasportato
 * fuori dall'albero del componente, quindi `wrapper.find` non lo vedrebbe.
 */
const valoreCampoImporto = (): string | undefined =>
    (document.querySelector('input#importo') as HTMLInputElement | null)?.value;

describe('inizializzazione del campo importo', () => {
    beforeEach(() => {
        document.body.innerHTML = '';
    });

    test('su una voce in sforo propone il preventivo deliberato, non lo speso', async () => {
        const wrapper = monta(voceInSforo);
        await wrapper.vm.$nextTick();
        await new Promise((r) => setTimeout(r, 0));

        expect(valoreCampoImporto()).toBe('5.000,00');
        expect(valoreCampoImporto()).not.toBe('6.000,00');
    });

    test('su una voce mai andata in sforo il valore proposto non cambia', async () => {
        // `budget_originale_raw` coincide con `importo_raw` quando non c'è stato sforo:
        // la correzione si vede solo dove prima era sbagliata.
        const wrapper = monta({
            ...voceInSforo,
            importo: '€ 5.000,00',
            importo_raw: 500000,
            budget_originale_raw: 500000,
            speso_raw: 200000,
        });
        await wrapper.vm.$nextTick();
        await new Promise((r) => setTimeout(r, 0));

        expect(valoreCampoImporto()).toBe('5.000,00');
    });

    test('su una voce senza budget_originale_raw ripiega su importo_raw', async () => {
        // Il terzo livello del piano dei conti non entra nella mappa dei budget originali, e
        // nemmeno nel gonfiaggio: lì `importo_raw` è già il preventivo vero.
        const { budget_originale_raw: _escluso, ...senzaBudgetOriginale } = voceInSforo;
        const wrapper = monta({ ...senzaBudgetOriginale, importo: '€ 900,00', importo_raw: 90000 });
        await wrapper.vm.$nextTick();
        await new Promise((r) => setTimeout(r, 0));

        expect(valoreCampoImporto()).toBe('900,00');
    });
});
