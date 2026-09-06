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

    /**
     * Fase 1-bis della beta.18, rilievo 2 — **il punto cieco del test qui sopra.**
     *
     * Il commento del gemello dice «non ha mai avuto il problema», e fino alla beta.17 era vero:
     * una fattura ordinaria non poteva contenere una riga negativa, perché non si registrava
     * affatto. Dalla beta.18 può — è lo storno «Oneri di sistema» che ogni bolletta gas porta
     * dentro il documento — e su quella riga l'`abs()` incondizionato dell'idratazione mentiva.
     *
     * Il costo non era estetico: la casella mostrava +€ 10,00, qualunque salvataggio rispediva
     * la riga positiva, `UpdateFatturaRequest` la accettava (il `min:0` vale solo sulle note di
     * credito) e `aggiornaFattura()` ricostruiva tutto dalle righe. La bolletta si gonfiava **in
     * silenzio** da € 109,80 a € 134,20 e il capitolo si caricava di € 24,40 mai spesi, con la
     * scrittura che continuava a quadrare.
     */
    test('una riga negativa si precarica NEGATIVA: il segno appartiene alla riga, non al documento', () => {
        const f = fatturaOrdinaria();
        f.righe = [
            { ...(f.righe as any[])[0], importo_imponibile: 100_000, importo_iva: 22_000, descrizione: 'Quota fissa' },
            {
                ...(f.righe as any[])[0],
                id: 902,
                importo_imponibile: -10_000,
                importo_iva: -2_200,
                descrizione: 'Spesa per Oneri di sistema',
            },
        ];

        const wrapper = render(f);
        const caselle = wrapper.findAllComponents({ name: 'MoneyInput' });

        expect(caselle.length).toBeGreaterThan(1);
        expect(caselle[0].props('modelValue')).toBe(1000);
        expect(caselle[1].props('modelValue')).toBe(-100);
    });
});

// ⛔ **Qui c'era il blocco «il controllo duplicati (D4) si ricalcola quando cambia il tipo
// documento», tolto il 05/09/2026.**
//
// Verificava che cliccare il toggle Fattura/Nota di Credito rilanciasse la ricerca duplicati
// (trovato dalla revisione avversariale della beta.13). Quel toggle non esiste più: la
// revisione avversariale della beta.17 ha trovato che `tipo_documento` è immutabile lato
// server in modifica (`UpdateFatturaRequest` non lo accetta, `aggiornaFattura()` lo rilegge
// sempre dal database) — ma il toggle restava cliccabile e pilotava tutta la simulazione di
// budget/cassa di Coda 122, disinnescando una presa d'atto sforo con un click che sul
// salvataggio non aveva alcun effetto. Reso non interattivo (vedi il template, «Toggle
// Fattura / Nota Credito — SOLO VISUALIZZAZIONE in modifica»).
//
// La entry corrispondente nel `watchDebounced` di `() => form.tipo_documento` è rimasta nel
// codice ma non scatta più: `form.tipo_documento` non cambia mai durante una sessione di
// modifica, quindi non è un test che sarebbe rimasto verde per il motivo sbagliato — è uno
// scenario che l'interfaccia non permette più di raggiungere. Tenerlo avrebbe voluto dire
// simulare un cambio di `form.tipo_documento` via JS invece che via un gesto dell'utente:
// esattamente la «replica» che la beta.16 aveva già imparato a non scrivere.

/**
 * ⚠️ **La pagina di modifica mostrava un totale diverso da quello che avrebbe salvato.**
 *
 * Trovato dalla revisione avversariale della beta.19 (06/09/2026), su un difetto che la beta.19
 * stessa aveva creato. Alla registrazione l'imposta dichiarata dai `DatiRiepilogo` viene
 * distribuita fra le righe e conservata in `dati_extra.fiscal.riepiloghi_dichiarati`;
 * `aggiornaFattura()` la rilegge da lì, e infatti riaprire e salvare **non** sgonfiava la
 * fattura (provato in `tests/Feature/Gestionale/ImpostaDaRiepilogoTest.php`). Ma il modulo di
 * modifica non passava quei riepiloghi a `calcolaTotali()` — il campo non esisteva proprio in
 * questa pagina — e l'anteprima ricadeva sul calcolo per riga.
 *
 * Il risultato era la peggiore forma di disaccordo: elenco, dettaglio e database dicevano
 * € 100,15, la schermata da cui si stava per salvare diceva € 100,14, e il salvataggio dava
 * torto alla schermata. Anche la correzione del totale di riga (`lordoRigaRegistratoCents`)
 * restava **inerte** qui, perché leggeva un `iva_righe_cents` costruito con la formula sbagliata.
 */
describe("modifica di una fattura importata — l'anteprima dice quanto verrà salvato", () => {
    /** Il file 06 dei collaudi come lo si ritrova a database dopo la registrazione. */
    function bollettaImportata() {
        const f = fatturaOrdinaria();
        f.numero_documento = '26G-00011672';
        f.importo_imponibile = 9_009;
        f.importo_iva = 1_006;
        f.totale_documento = 10_015;
        f.netto_a_pagare = 10_015;
        f.dati_extra = {
            fiscal: {
                riepiloghi_dichiarati: [
                    { aliquota_iva: 22, natura: null, imponibile: 45.74, imposta: 10.06 },
                    { aliquota_iva: 0, natura: 'N2.2', imponibile: 44.35, imposta: 0 },
                ],
            },
            competenza: null,
            override_budget: null,
        };
        f.righe = [
            { id: 401, descrizione: 'Altre partite ed oneri', conto_id: CONTO.id, immobile_id: null,
                importo_imponibile: 4_435, importo_iva: 0, aliquota_iva: '0.00', concorre_base_ritenuta: true },
            { id: 402, descrizione: 'Spesa per il trasporto e la gestione del contatore', conto_id: CONTO.id, immobile_id: null,
                importo_imponibile: 4_061, importo_iva: 893, aliquota_iva: '22.00', concorre_base_ritenuta: true },
            { id: 403, descrizione: 'Spesa per la materia gas naturale', conto_id: CONTO.id, immobile_id: null,
                importo_imponibile: 693, importo_iva: 153, aliquota_iva: '22.00', concorre_base_ritenuta: true },
            { id: 404, descrizione: 'Spesa per Oneri di sistema', conto_id: CONTO.id, immobile_id: null,
                importo_imponibile: -180, importo_iva: -40, aliquota_iva: '22.00', concorre_base_ritenuta: true },
        ];
        return f;
    }

    test("riprende l'imposta dichiarata da dati_extra invece di ricalcolarla riga per riga", () => {
        const vm = render(bollettaImportata()).vm as any;

        // Senza i riepiloghi questa somma farebbe 1005, e il totale 10014.
        expect(vm.totali.iva_cents).toBe(1006);
        expect(vm.totali.totale_documento_cents).toBe(10_015);
    });

    test('la riga che riceve il centesimo di compensazione mostra il valore registrato', () => {
        const vm = render(bollettaImportata()).vm as any;

        // 693 + 153 = 846. Col calcolo per riga sarebbe 693 + 152 = 845.
        expect(vm.totali.iva_righe_cents).toEqual([0, 893, 153, -40]);
        expect(vm.lordoRigaRegistratoCents(2, vm.form.righe[2])).toBe(846);
    });

    test('una fattura senza riepiloghi dichiarati continua a calcolare per riga', () => {
        // Non regressione: la fattura digitata a mano non ha `dati_extra.fiscal.riepiloghi_dichiarati`,
        // e lì l'anteprima deve restare esattamente quella di sempre.
        const vm = render(fatturaOrdinaria()).vm as any;

        expect(vm.totali.iva_cents).toBe(22_000);
        expect(vm.totali.totale_documento_cents).toBe(122_000);
    });
});

/**
 * ⚠️ **Riaprire una nota di credito le ribaltava le righe positive.**
 *
 * Trovato dalla Fase 1-bis della beta.19 (lenti «segno» e «persistenza»). Il round-trip usava
 * `Math.abs()` sulle righe di una nota di credito: corretto finché sono tutte negative, sbagliato
 * appena una è positiva — ed è positiva la riga che storna una riga **negativa** della fattura
 * originale (−1 × −1,80 = +1,80). `abs()` la lasciava positiva, il server rimoltiplicava per −1,
 * e la nota si gonfiava senza che nessun campo a schermo cambiasse.
 *
 * La correzione precedente aveva già affrontato questo difetto e l'aveva risolto a metà: aveva
 * reso l'`abs()` condizionale al tipo documento invece di sostituirlo con la moltiplicazione per
 * il segno, che è l'inverso esatto di quello che fa il servizio.
 */
describe('round-trip di una nota di credito con una riga positiva', () => {
    function notaConRigaPositiva() {
        const f = notaCredito();
        f.importo_imponibile = -9_009;
        f.importo_iva = -1_006;
        f.totale_documento = -10_015;
        f.netto_a_pagare = -10_015;
        // Una nota nata dallo storno eredita i riepiloghi della fattura che annulla: le
        // magnitudini sono positive, come li manda il controller.
        f.dati_extra = {
            fiscal: {
                riepiloghi_dichiarati: [
                    { aliquota_iva: 22, natura: null, imponibile: 45.74, imposta: 10.06 },
                    { aliquota_iva: 0, natura: 'N2.2', imponibile: 44.35, imposta: 0 },
                ],
            },
            competenza: null,
            override_budget: null,
        };
        f.righe = [
            { id: 501, descrizione: '[STORNO] Altre partite ed oneri', conto_id: CONTO.id, immobile_id: null,
                importo_imponibile: -4_435, importo_iva: 0, aliquota_iva: '0.00', concorre_base_ritenuta: true },
            { id: 502, descrizione: '[STORNO] Trasporto', conto_id: CONTO.id, immobile_id: null,
                importo_imponibile: -4_061, importo_iva: -893, aliquota_iva: '22.00', concorre_base_ritenuta: true },
            { id: 503, descrizione: '[STORNO] Materia gas', conto_id: CONTO.id, immobile_id: null,
                importo_imponibile: -693, importo_iva: -153, aliquota_iva: '22.00', concorre_base_ritenuta: true },
            // ⚠️ Questa è POSITIVA: storna la riga negativa «Oneri di sistema» della fattura.
            { id: 504, descrizione: '[STORNO] Oneri di sistema', conto_id: CONTO.id, immobile_id: null,
                importo_imponibile: 180, importo_iva: 40, aliquota_iva: '22.00', concorre_base_ritenuta: true },
        ];
        return f;
    }

    test('la riga positiva resta negativa nel modulo, così il servizio la riporta positiva', () => {
        const vm = render(notaConRigaPositiva()).vm as any;

        // Il modulo mostra le magnitudini che, moltiplicate per −1 dal servizio, ridanno i valori
        // salvati. Con `abs()` l'ultima sarebbe stata +1,80 e il salvataggio l'avrebbe messa a −1,80.
        expect(vm.form.righe.map((r: any) => r.importo_imponibile))
            .toEqual([44.35, 40.61, 6.93, -1.80]);
    });

    test('il totale mostrato è quello salvato, non uno gonfiato di due volte la riga', () => {
        const vm = render(notaConRigaPositiva()).vm as any;

        // Con `abs()` l'imponibile diventava 93,69 e il totale −€ 104,54: € 4,39 di credito
        // verso il fornitore che nessuno ha chiesto.
        expect(vm.totali.imponibile_cents).toBe(9_009);
        expect(vm.totali.totale_documento_cents).toBe(10_015);
    });
});
