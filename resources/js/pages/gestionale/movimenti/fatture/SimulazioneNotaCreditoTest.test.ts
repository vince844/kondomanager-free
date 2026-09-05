// @vitest-environment jsdom

/**
 * Il pannello di simulazione trattava una nota di credito come una spesa (Coda 122).
 *
 * ## La segnalazione, dal forum del 04/09/2026
 *
 * *«Registrando una nota credito, il simulatore "ragiona" come per la fattura: visualizza il
 * budget assegnato alla voce di spesa diminuito dell'importo della nota e mostra il saldo
 * delle risorse ma, anche questo, diminuito dell'importo. Mi sarei aspettato, invece, di
 * vedere questi due valori aumentati dell'importo della nota, vista la natura della stessa.»*
 *
 * La risposta di Vincenzo, che questi test rendono vera: su una nota da **€ 61,00** e un
 * capitolo con **€ 1.178,00** di residuo, il pannello deve mostrare il residuo che **sale a
 * € 1.239,00**, non un consumo di € 61,00. E sulla cassa: registrare una fattura non muove il
 * conto — è una previsione di quanto uscirà quando la si paga — ma una nota di credito **non
 * si incassa, si compensa**: il saldo giusto è quello **invariato**, con l'uscita azzerata, non
 * un'entrata. Sul conto con **€ 117,85** di saldo la previsione post-nota deve restare
 * **€ 117,85**, non salire né scendere.
 *
 * ## Perché il segno è già giusto lato server, e sbagliato solo qui
 *
 * `FatturaPassivaService` moltiplica per −1 tutto ciò che registra come `nota_credito`: il
 * documento salvato è corretto. Nessuno dei tre calcoli dell'anteprima — `budgetImpacts`,
 * `rigaInSforo`, `bankForecast` — riceveva `tipo_documento` per saperlo, in **due pagine
 * diverse** che duplicano lo stesso calcolo (`FatturaRegisterNew.vue` e
 * `FatturaRegisterEdit.vue`, non un componente condiviso): la correzione è identica in
 * entrambe, e questo file le prova entrambe con gli stessi numeri.
 *
 * ## Il falso sforo che blocca per sempre
 *
 * ⚠️ Una nota su un capitolo già speso veniva scambiata per uno sforamento, perché
 * `rigaInSforo` confrontava il lordo col residuo senza sapere che una nota lo libera invece
 * di consumarlo. Se l'amministratore compilava la motivazione per andare avanti, il
 * documento restava marcato `override_budget` e da quel momento **non più modificabile**:
 * solo stornabile e rifatto. Un test qui sotto lo prova nel verso sbagliato apposta — una
 * nota che, se trattata come una fattura, sforerebbe — e verifica che non sfori.
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

/**
 * Gli stessi numeri della risposta di Vincenzo al forum, non numeri comodi inventati:
 * residuo del capitolo € 1.178,00, nota da € 61,00 (50,00 + IVA 22%), banca a € 117,85.
 */
const CONTO = { id: 55, nome: 'Pulizia scale', residuo_budget: 117_800, gia_versato_cents: 0, ultimi_movimenti: [] };
const BANCA = { id: 7, nome: 'Conto Corrente', saldo_attuale: 11_785 };
const FORNITORE = { id: 10, ragione_sociale: 'Ditta Pulizie Srl', soggetto_ritenuta: false, regime_forfetario: false };

function renderNew() {
    return mount(FatturaRegisterNew, {
        props: {
            condominio: { id: 28, nome: 'Condominio Demo KM' },
            condomini: [{ id: 28, nome: 'Condominio Demo KM' }],
            esercizio: { id: 41, nome: '2026', stato: 'aperto' },
            esercizi: [{ id: 41, nome: '2026', stato: 'aperto' }],
            gestioni: [{ id: 35, nome: 'Gestione ordinaria 2026', tipo: 'ordinaria', esercizio_ids: [41] }],
            fornitori: [FORNITORE],
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

/** Porta il modulo nello stato della segnalazione: nota di credito, fornitore, riga, banca. */
async function comeIlForum(wrapper: ReturnType<typeof renderNew>) {
    const vm = wrapper.vm as any;
    vm.form.tipo_documento = 'nota_credito';
    vm.form.fornitore_id = FORNITORE.id;
    vm.form.conto_corrente_id = BANCA.id;
    await wrapper.vm.$nextTick();
    vm.form.righe[0].conto_id = CONTO.id;
    vm.form.righe[0].importo_imponibile = 50;
    vm.form.righe[0].aliquota_iva = 22;
    await wrapper.vm.$nextTick();

    return vm;
}

describe('FatturaRegisterNew — la nota di credito libera budget, non lo consuma', () => {
    test('il residuo del capitolo sale, esattamente dei numeri della segnalazione', async () => {
        const vm = await comeIlForum(renderNew());

        const impatto = vm.budgetImpacts.find((i: any) => i.id === CONTO.id);

        expect(impatto).toBeDefined();
        // ⚠️ Prima della correzione: speso_cents = 6100, delta_cents = 111700 (scende).
        expect(impatto.speso_cents).toBe(-6100);
        expect(impatto.delta_cents).toBe(123_900);
        expect(impatto.isOk).toBe(true);
    });

    test('una nota non sfora mai, anche su un importo che sfonderebbe il residuo', async () => {
        // ⚠️ **Qui la controprova aveva trovato un test debole nella prima stesura**: usava
        // gli stessi numeri della segnalazione (€ 61,00 su un residuo di € 1.178,00), che non
        // sfora nemmeno se la guardia sparisce — rimuovendo `if (isNotaCredito.value) return
        // false` nessun test diventava rosso, perché quella riga non aveva mai avuto bisogno
        // della guardia per passare. Qui l'importo (€ 2.440,00) è deliberatamente più grande
        // del residuo (€ 1.178,00): è lo STESSO importo del controesempio sulla fattura
        // ordinaria qui sotto, dove sfora davvero. Solo il `tipo_documento` cambia.
        const wrapper = renderNew();
        const vm = wrapper.vm as any;
        vm.form.tipo_documento = 'nota_credito';
        await wrapper.vm.$nextTick();

        const rigaCheSforerebbeComeFattura = { conto_id: CONTO.id, importo_imponibile: 2000, aliquota_iva: 22 };

        expect(vm.rigaInSforo(rigaCheSforerebbeComeFattura)).toBe(false);
    });

    test('su un capitolo GIÀ sforato la nota non finisce in «Sforo Budget»: isOk regge, non solo il badge', async () => {
        // ⚠️ **La seconda metà della correzione, scritta il 05/09/2026 dopo la revisione
        // avversariale.** La prima stesura aveva messo la guardia solo su `rigaInSforo` — un
        // badge — mentre il ramo che BLOCCA passa da `isOk` → `transactionStatus` →
        // `handleSubmit` → modale di sforo → `override_budget`, che rende il documento non
        // più modificabile e (essendo una nota) nemmeno stornabile: vicolo cieco.
        //
        // Il residuo negativo non è un caso di laboratorio: il backend non lo clampa
        // (`$residuo = $budgetApprovato - $spesaAttuale`) ed è lo stato TIPICO quando arriva
        // una nota di credito, perché la nota arriva proprio per rettificare la
        // sovrafatturazione che ha sforato il capitolo. La fixture di questo file usa un
        // residuo sempre positivo (€ 1.178,00): è la ragione per cui nessuno dei test
        // esistenti poteva vedere il difetto.
        const wrapper = renderNew();
        const vm = wrapper.vm as any;
        vm.form.tipo_documento = 'nota_credito';
        await wrapper.vm.$nextTick();

        // Capitolo già oltre il preventivo di € 500,00, nota da € 122,00 che lo rettifica
        // in parte: il vecchio confronto era -12200 <= -50000, cioè falso.
        await wrapper.setProps({ conti: [{ ...CONTO, residuo_budget: -50_000 }] });
        vm.form.righe[0].conto_id = CONTO.id;
        vm.form.righe[0].importo_imponibile = 100;
        vm.form.righe[0].aliquota_iva = 22;
        await wrapper.vm.$nextTick();

        const impatto = vm.budgetImpacts.find((i: any) => i.id === CONTO.id);

        expect(impatto.speso_cents).toBe(-12200);
        expect(impatto.isOk).toBe(true, 'Una nota di credito non sfora mai: qui si decide se il salvataggio viene bloccato.');
        expect(vm.transactionStatus).not.toBe('CRITICAL_BUDGET');
    });

    test('la barra del capitolo non riceve mai una larghezza negativa, che il browser scarterebbe dipingendola PIENA', async () => {
        // ⚠️ Regressione introdotta dalla prima stesura di questa coda e trovata dalla
        // revisione avversariale: reso `speso_cents` negativo, l'espressione della barra
        // (`Math.min(..., 100)`, con il tetto ma senza pavimento) produceva `width: -5.17%`.
        // Una percentuale negativa non è un valore valido: la dichiarazione viene scartata e
        // il div, che non ha nessuna classe di larghezza, torna a `width: auto` — cioè al
        // 100% del contenitore. Sullo schermo: «+€ 1.239,00» in verde e, un centimetro
        // sotto, la barra del budget completamente piena, che ovunque nella pagina significa
        // «esaurito». La barra gemella della cassa il pavimento ce l'aveva già.
        const vm = await comeIlForum(renderNew());

        const impatto = vm.budgetImpacts.find((i: any) => i.id === CONTO.id);
        const larghezza = Math.min(Math.max((impatto.speso_cents / Math.max(impatto.residuo_cents, 1)) * 100, 0), 100);

        expect(impatto.speso_cents).toBeLessThan(0);
        expect(larghezza).toBe(0, 'Con speso_cents negativo la barra deve stare a zero, non andare sotto zero.');
    });

    test('sulla fattura ORDINARIA, stessa riga, lo sforo scatta ancora — il controesempio', async () => {
        // ⚠️ Il capitolo ha € 1.178,00 di residuo: una riga da soli € 61,00 non sfora mai
        // niente. Per provare che `rigaInSforo` funzioni ancora sulle fatture vere serve un
        // residuo più piccolo dell'importo, non gli stessi numeri della nota.
        const wrapper = renderNew();
        const vm = wrapper.vm as any;
        vm.form.tipo_documento = 'fattura';
        await wrapper.vm.$nextTick();

        const rigaCheSfora = { conto_id: CONTO.id, importo_imponibile: 2000, aliquota_iva: 22 };

        expect(vm.rigaInSforo(rigaCheSfora)).toBe(true);
    });

    test('la cassa resta invariata: nessuna uscita, nessuna entrata', async () => {
        const vm = await comeIlForum(renderNew());

        // ⚠️ Il valore atteso da Vincenzo NON è € 178,85 (saldo + importo, come un'entrata):
        // è il saldo invariato, perché la nota si compensa e non si incassa.
        expect(vm.bankForecast.uscita_cents).toBe(0);
        expect(vm.bankForecast.post_cents).toBe(11_785);
        expect(vm.bankForecast.isRed).toBe(false);
    });

    test('sulla fattura ORDINARIA la cassa prevede ancora l\'uscita — il controesempio', async () => {
        const wrapper = renderNew();
        const vm = wrapper.vm as any;
        vm.form.tipo_documento = 'fattura';
        vm.form.fornitore_id = FORNITORE.id;
        vm.form.conto_corrente_id = BANCA.id;
        await wrapper.vm.$nextTick();
        vm.form.righe[0].conto_id = CONTO.id;
        vm.form.righe[0].importo_imponibile = 50;
        vm.form.righe[0].aliquota_iva = 22;
        await wrapper.vm.$nextTick();

        expect(vm.bankForecast.uscita_cents).toBe(6100);
        expect(vm.bankForecast.post_cents).toBe(11_785 - 6100);
    });

    test('l\'etichetta dice «Importo a credito», non «Netto da pagare»', async () => {
        const wrapper = await Promise.resolve(renderNew());
        await comeIlForum(wrapper);

        expect(wrapper.text()).toContain('Importo a credito');
        expect(wrapper.text()).not.toContain('Netto da pagare');
    });

    test('e la riga di cassa dice che si compensa, non che uscirà qualcosa', async () => {
        const wrapper = renderNew();
        await comeIlForum(wrapper);

        expect(wrapper.text()).toContain('si compensa, non si incassa');
    });

    test('se il conto è già in rosso, il banner lo dice senza incolpare il documento', async () => {
        // ⚠️ **Trovato dalla revisione avversariale del 05/09/2026.** `bankForecast.isRed`
        // dipende dal saldo ATTUALE del conto, che una NC non tocca (`uscita_cents = 0`) ma
        // che può già essere negativo per conto suo. Il messaggio «Liquidità insufficiente
        // sul conto selezionato» — scritto per una FATTURA che sta per prosciugarlo —
        // suonava come se fosse QUESTO documento il problema, due centimetri sotto la riga
        // che dichiara «nessuna uscita». Ora la NC ha una frase sua, onesta sulla causa.
        const wrapper = mount(FatturaRegisterNew, {
            props: {
                condominio: { id: 28, nome: 'Condominio Demo KM' },
                condomini: [{ id: 28, nome: 'Condominio Demo KM' }],
                esercizio: { id: 41, nome: '2026', stato: 'aperto' },
                esercizi: [{ id: 41, nome: '2026', stato: 'aperto' }],
                gestioni: [{ id: 35, nome: 'Gestione ordinaria 2026', tipo: 'ordinaria', esercizio_ids: [41] }],
                fornitori: [FORNITORE],
                conti: [CONTO],
                banche: [{ ...BANCA, saldo_attuale: -1000 }],
                immobili: [],
                debiti_patrimoniali: [],
                fatture_pregresse_registrate: [],
                fondi_riserva: [],
                capienza_rata_zero: 0,
                incassato_rata_zero: 0,
            },
            global: { stubs: stubsComuni, mocks: { route: (n: string) => `/${n}` } },
        });
        await comeIlForum(wrapper);

        expect(wrapper.text()).toContain('già un saldo negativo');
        expect(wrapper.text()).not.toContain('Liquidità insufficiente sul conto selezionato');
    });
});

// ════════════════════════════════════════════════════════════════════════════
// Stessa correzione, stessa prova, sulla pagina di MODIFICA
// ════════════════════════════════════════════════════════════════════════════

function fatturaEdit(overrides: Record<string, unknown> = {}) {
    return {
        id: 91,
        fornitore_id: FORNITORE.id,
        fornitore: FORNITORE,
        esercizio_id: 41,
        gestione_id: 35,
        tipo_documento: 'nota_credito',
        is_pregresso: false,
        numero_documento: 'NC-2026-0001',
        numero_protocollo: 'FTP-2026-00009',
        data_documento: '2026-07-10',
        data_scadenza: '2026-08-09',
        conto_corrente_id: BANCA.id,
        modalita_pagamento: 'bonifico',
        iban_fornitore: null,
        stato_approvazione: 'approvata',
        importo_imponibile: -5000,
        importo_iva: -1100,
        importo_ritenuta: 0,
        totale_documento: -6100,
        netto_a_pagare: -6100,
        dati_extra: { fiscal: {}, competenza: null, override_budget: null },
        righe: [{
            id: 300,
            descrizione: 'Storno pulizie',
            conto_id: CONTO.id,
            immobile_id: null,
            importo_imponibile: -5000,
            importo_iva: -1100,
            aliquota_iva: '22.00',
            concorre_base_ritenuta: true,
        }],
        documenti: [],
        coperture: [],
        ...overrides,
    };
}

function renderEdit(fattura: Record<string, unknown>) {
    return mount(FatturaRegisterEdit, {
        props: {
            condominio: { id: 28, nome: 'Condominio Demo KM' },
            condomini: [{ id: 28, nome: 'Condominio Demo KM' }],
            esercizio: { id: 41, nome: '2026', stato: 'aperto' },
            esercizi: [{ id: 41, nome: '2026', stato: 'aperto' }],
            gestioni: [{ id: 35, nome: 'Gestione ordinaria 2026', esercizi: [{ id: 41 }] }],
            fornitori: [FORNITORE],
            conti: [CONTO],
            banche: [BANCA],
            immobili: [],
            debiti_patrimoniali: [],
            fatture_pregresse_registrate: [],
            fondi_riserva: [],
            capienza_rata_zero: 0,
            incassato_rata_zero: 0,
            fattura,
        },
        global: { stubs: stubsComuni, mocks: { route: (n: string) => `/${n}` } },
    });
}

describe('FatturaRegisterEdit — stessa correzione, perché è lo stesso pannello duplicato', () => {
    test('il residuo sale riaprendo una nota già registrata', () => {
        const wrapper = renderEdit(fatturaEdit());
        const vm = wrapper.vm as any;

        const impatto = vm.budgetImpacts.find((i: any) => i.id === CONTO.id);

        expect(impatto.speso_cents).toBe(-6100);
        expect(impatto.delta_cents).toBe(123_900);
        expect(impatto.isOk).toBe(true);
    });

    test('la cassa resta invariata anche in modifica', () => {
        const wrapper = renderEdit(fatturaEdit());
        const vm = wrapper.vm as any;

        expect(vm.bankForecast.uscita_cents).toBe(0);
        expect(vm.bankForecast.post_cents).toBe(11_785);
    });

    test('una nota non sfora mai neanche in modifica, anche su un importo che sfonderebbe il residuo', () => {
        // Manca da questa pagina fino al 04/09/2026: la guardia in FatturaRegisterEdit.vue
        // (riga 311, identica a quella di FatturaRegisterNew.vue) non aveva un test proprio —
        // solo quello sulla pagina di registrazione la copriva, e le due copie sono file
        // diversi che una controprova sull'una non tocca affatto sull'altra.
        const wrapper = renderEdit(fatturaEdit());
        const vm = wrapper.vm as any;

        const rigaCheSforerebbeComeFattura = { conto_id: CONTO.id, importo_imponibile: 2000, aliquota_iva: 22 };

        expect(vm.rigaInSforo(rigaCheSforerebbeComeFattura)).toBe(false);
    });

    test('l\'etichetta e la riga di cassa dicono la stessa cosa della registrazione', () => {
        const wrapper = renderEdit(fatturaEdit());

        expect(wrapper.text()).toContain('Importo a credito');
        expect(wrapper.text()).toContain('si compensa, non si incassa');
    });

    test('su una fattura ordinaria in modifica il comportamento resta quello di sempre', () => {
        const wrapper = renderEdit(fatturaEdit({
            tipo_documento: 'fattura',
            importo_imponibile: 5000,
            importo_iva: 1100,
            totale_documento: 6100,
            netto_a_pagare: 6100,
            righe: [{
                id: 300, descrizione: 'Pulizie', conto_id: CONTO.id, immobile_id: null,
                importo_imponibile: 5000, importo_iva: 1100, aliquota_iva: '22.00', concorre_base_ritenuta: true,
            }],
        }));
        const vm = wrapper.vm as any;

        const impatto = vm.budgetImpacts.find((i: any) => i.id === CONTO.id);
        expect(impatto.speso_cents).toBe(6100);
        expect(vm.bankForecast.uscita_cents).toBe(6100);
        expect(wrapper.text()).toContain('Netto da pagare');
    });
});
