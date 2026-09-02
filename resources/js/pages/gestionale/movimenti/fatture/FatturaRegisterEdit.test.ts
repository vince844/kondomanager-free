// @vitest-environment jsdom

/**
 * La schermata di MODIFICA di una fattura passiva, sul solo segno degli importi.
 *
 * Il difetto: su una **nota di credito** il form si precaricava con gli importi letti dal
 * database *tali e quali*, e a database una NC è salvata già negativa —
 * `FatturaPassivaService` applica `$moltiplicatore = -1` sia alle righe (`:696`) sia alla
 * testata (`:736-739`). Ma `aggiornaFattura()` si aspetta in ingresso il **valore
 * assoluto** e riapplica il moltiplicatore per conto suo (`:682` e `:696`): rimandandogli
 * i negativi, `-1000 × 100 × (-1)` torna `+100000` e la nota di credito si trasforma in
 * un costo.
 *
 * Bastava aprire e salvare senza toccare niente. Nessuna guardia lo impediva: una NC
 * aperta passa `motivoBloccoModifica()`, e `UpdateFatturaRequest` valida
 * `righe.*.importo_imponibile` come `required|numeric` — senza `min:0`.
 *
 * Perché il test monta il componente: il segno si perde nell'inizializzazione di
 * `useForm({...})`, dentro il `<script setup>`. È lo stesso motivo della beta.36 con le
 * commissioni bancarie, ed è il caso previsto dalla nota in `vitest.config.ts`.
 *
 * Il contratto lato server — che il service voglia valori assoluti e possieda lui il
 * segno — è fissato da `tests/Feature/Gestionale/NotaCreditoModificaSegnoTest.php`.
 * I due file vanno letti insieme.
 */

import { describe, expect, test, vi } from 'vitest';
import { mount } from '@vue/test-utils';

// ⚠️ Il controllo duplicati (decisione D4, 1.11.0-beta.13) chiama `useFattureSimili`, che fa
// un vero `axios.get` **al montaggio** (`watchDebounced(..., { immediate: true })`, apposta:
// su una fattura già esistente serve controllare subito, non solo al primo tocco). Senza
// questo mock il test partiva davvero in rete dentro jsdom — nessuna asserzione lo notava,
// perché il composable inghiotte l'errore e mette `simili` a `[]`, ma è esattamente il rischio
// misurato dalla Fase 0.2 della beta.13 su questo stesso file: una richiesta reale, lenta e
// non deterministica, nascosta dentro un test che sembra passare.
const axios = vi.hoisted(() => ({ get: vi.fn(async () => ({ data: [] })) }));
vi.mock('axios', () => ({ default: axios }));

vi.mock('@inertiajs/vue3', async (importOriginal) => ({
    ...(await importOriginal<typeof import('@inertiajs/vue3')>()),
    Head: { template: '<span />' },
    usePage: () => ({
        props: { auth: { user: { roles: ['amministratore'], permissions: [] } } },
    }),
}));

import FatturaRegisterEdit from './FatturaRegisterEdit.vue';

(globalThis as any).route = (name: string) => `/${name}`;

const stubs = {
    GestionaleLayout: { template: '<div><slot /></div>' },
    PageHeaderGuide: { template: '<div><slot /></div>' },
    Head: { template: '<span />' },
    Link: { template: '<a><slot /></a>' },
    'v-select': true,
    MoneyInput: {
        name: 'MoneyInput',
        props: ['modelValue'],
        template: '<input :value="modelValue" />',
    },
};

const CONTO = { id: 55, nome: 'Pulizie', codice: 'A.1', conto_contabile_id: 141 };

/**
 * Una nota di credito da 1.000,00 € + IVA 22%, com'è fatta a database: tutto negativo.
 */
function notaCredito(overrides: Record<string, unknown> = {}) {
    return {
        id: 91,
        fornitore_id: 10,
        fornitore: { id: 10, ragione_sociale: 'Mario Rossi Impianti', soggetto_ritenuta: false, regime_forfetario: false },
        esercizio_id: 41,
        gestione_id: 35,
        tipo_documento: 'nota_credito',
        is_pregresso: false,
        numero_documento: 'NC-2026-0001',
        numero_protocollo: 'FTP-2026-00009',
        data_documento: '2026-07-10',
        data_scadenza: '2026-08-09',
        conto_corrente_id: null,
        modalita_pagamento: 'bonifico',
        iban_fornitore: null,
        stato_approvazione: 'approvata',
        importo_imponibile: -100_000,
        importo_iva: -22_000,
        importo_ritenuta: 0,
        totale_documento: -122_000,
        netto_a_pagare: -122_000,
        dati_extra: { fiscal: {}, competenza: null, override_budget: null },
        righe: [{
            id: 300,
            descrizione: 'Storno pulizie',
            conto_id: CONTO.id,
            immobile_id: null,
            importo_imponibile: -100_000,
            importo_iva: -22_000,
            aliquota_iva: '22.00',
            concorre_base_ritenuta: true,
        }],
        documenti: [],
        coperture: [],
        ...overrides,
    };
}

/** La stessa fattura, ma ordinaria: a database è positiva. */
function fatturaOrdinaria() {
    const f = notaCredito({
        tipo_documento: 'fattura',
        numero_documento: 'FT-2026-0044',
        importo_imponibile: 100_000,
        importo_iva: 22_000,
        totale_documento: 122_000,
        netto_a_pagare: 122_000,
    });
    f.righe = [{ ...f.righe[0], importo_imponibile: 100_000, importo_iva: 22_000, descrizione: 'Pulizie' }];
    return f;
}

function render(fattura: Record<string, unknown>) {
    return mount(FatturaRegisterEdit, {
        props: {
            condominio: { id: 28, nome: 'Condominio Demo KM' },
            condomini: [{ id: 28, nome: 'Condominio Demo KM' }],
            esercizio: { id: 41, nome: '2026', stato: 'aperto' },
            esercizi: [{ id: 41, nome: '2026', stato: 'aperto' }],
            gestioni: [{ id: 35, nome: 'Gestione ordinaria 2026', esercizi: [{ id: 41 }] }],
            fornitori: [fattura.fornitore],
            conti: [CONTO],
            banche: [],
            immobili: [],
            debiti_patrimoniali: [],
            fatture_pregresse_registrate: [],
            fondi_riserva: [],
            capienza_rata_zero: 0,
            incassato_rata_zero: 0,
            fattura,
        },
        global: { stubs, mocks: { route: (n: string) => `/${n}` } },
    });
}

/**
 * L'importo della prima riga, letto dove lo legge l'amministratore: la casella.
 */
const importoPrimaRiga = (wrapper: ReturnType<typeof render>) =>
    wrapper.findAllComponents({ name: 'MoneyInput' })[0].props('modelValue');

describe('riapertura in modifica di una nota di credito', () => {
    /**
     * L'invariante: il form si digita in valore assoluto, il segno lo mette il server.
     * Col difetto la casella riceveva −1000 e il salvataggio ribaltava la nota in costo.
     */
    test('la riga si precarica in valore assoluto, non negativa', () => {
        const wrapper = render(notaCredito());

        expect(importoPrimaRiga(wrapper)).toBe(1000);
    });

    test('anche con più righe nessuna arriva col segno meno', () => {
        const nc = notaCredito();
        nc.righe = [
            { ...nc.righe[0] },
            { ...nc.righe[0], id: 301, descrizione: 'Storno giardino', importo_imponibile: -32_050, importo_iva: -7_051 },
        ];

        const wrapper = render(nc);
        const importi = wrapper.findAllComponents({ name: 'MoneyInput' })
            .slice(0, 2)
            .map((c) => c.props('modelValue') as number);

        expect(importi).toEqual([1000, 320.5]);
    });
});

describe('riapertura in modifica di una fattura ordinaria', () => {
    /**
     * Il controllo gemello: la correzione non deve toccare il caso normale, che a
     * database è già positivo e non ha mai avuto il problema.
     */
    test('la riga si precarica com era, positiva', () => {
        const wrapper = render(fatturaOrdinaria());

        expect(importoPrimaRiga(wrapper)).toBe(1000);
    });
});

describe('il controllo duplicati (D4) si ricalcola quando cambia il tipo documento', () => {
    /**
     * ⚠️ **Trovato dalla revisione avversariale della beta.13.** `tipo_documento` viaggia
     * nel payload della ricerca (`cercaFattureSimili({..., tipoDocumento: form.tipo_documento})`)
     * ma non era fra le fonti del `watchDebounced`: cambiare il toggle Fattura <-> Nota di
     * Credito non riesaminava il banner, che restava quello dell'ultimo tipo controllato —
     * proprio dopo A2 (beta.13), che ha reso il livello standard sensibile al segno, cioè al
     * tipo documento. In `FatturaRegisterNew.vue` la fonte c'era già.
     */
    test('passare da fattura a nota di credito rilancia la ricerca coi dati aggiornati', async () => {
        vi.useFakeTimers();
        try {
            const wrapper = render(fatturaOrdinaria());

            // La chiamata immediata al montaggio (immediate: true).
            await vi.advanceTimersByTimeAsync(450);
            axios.get.mockClear();

            const bottoneNC = wrapper.findAll('button').find((b) => b.text().includes('Nota Credito'));
            expect(bottoneNC).toBeTruthy();
            await bottoneNC!.trigger('click');

            await vi.advanceTimersByTimeAsync(450);

            expect(axios.get).toHaveBeenCalled();
            const [, config] = axios.get.mock.calls.at(-1)!;
            expect((config as { params: Record<string, unknown> }).params.tipo_documento).toBe('nota_credito');
        } finally {
            vi.useRealTimers();
        }
    });
});
