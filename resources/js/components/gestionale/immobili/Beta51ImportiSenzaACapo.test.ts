// @vitest-environment jsdom

/**
 * 1.11.0-beta.51, Coda 255 (locale) — dove il pannello, il cancello e lo storico compongono una frase con una cifra, il simbolo e la
 * cifra stanno nello stesso elemento che non va a capo: «€» in fondo a una riga e «127,04» in cima alla seguente non si leggono. Il testo che
 * il server manda non cambia (141 file di test PHP fissano «€ 1…»): cambia come lo si mostra, con un componente che avvolge ogni importo in
 * uno span `whitespace-nowrap` e lascia fuori il punto finale (`FraseConImporti.vue`, nessun `v-html`).
 *
 * Il controllo vero è un test DOM, non un grep: si montano il pannello «Cosa cambierà» (`AnteprimaPassaggio.vue`)
 * e lo storico (`TitolaritaSheet.vue`) con un JSON in cui ogni frase contiene «€ 127,04», e si vieta ogni nodo di testo con un importo che non
 * stia da solo in un elemento `whitespace-nowrap`. Le frasi del cancello di `PassaggioNew.vue` sono nell'altro file
 * (`PassaggioNewImportiSenzaACapo.test.ts`). Le celle di tabella con il solo importo sono fuori dal controllo (non sono frasi); una cella con
 * una frase dentro (la riga «intero piano … − bozze …») no.
 *
 * Per ogni componente: (1) il testo non cambia, parola per parola; (2) nessun importo fuori dal suo span nowrap; (3) lo span contiene la sola
 * cifra, senza il punto finale. Le note scritte dall'amministratore (nota del conguaglio, nota di annullamento) non si avvolgono: nel JSON non
 * hanno importi, e il test sull'avvolgimento di ciò che l'amministratore scrive è del componente (`FraseConImporti.test.ts`).
 *
 * Che cosa NON copre: le stampe e il PDF (la regola riguarda solo le pagine); il modo scuro e le larghezze; l'estratto di `MoneyHelper::format`.
 */

import { describe, expect, test } from 'vitest';
import { mount } from '@vue/test-utils';

import AnteprimaPassaggio from './AnteprimaPassaggio.vue';
import TitolaritaSheet from './TitolaritaSheet.vue';

/** Un importo come lo scrive `MoneyHelper::format`: «€ 127,04», «€ 1.127,04». */
const IMPORTO = /€\s?-?\d{1,3}(?:\.\d{3})*,\d{2}/;
const SOLO_IMPORTO = /^\s*€\s?-?\d{1,3}(?:\.\d{3})*,\d{2}\s*$/;

/**
 * I nodi di testo con un importo che non stanno da soli in un elemento `whitespace-nowrap`. Una cella di tabella con il solo importo non conta
 * (non è una frase); una cella con una frase dentro sì.
 */
function importiAScapito(radice: Element): string[] {
    const fuori: string[] = [];
    const camminatore = document.createTreeWalker(radice, NodeFilter.SHOW_TEXT);
    for (let nodo = camminatore.nextNode(); nodo; nodo = camminatore.nextNode()) {
        const testo = nodo.nodeValue ?? '';
        if (!IMPORTO.test(testo)) continue;
        const elemento = (nodo as Text).parentElement as HTMLElement;
        const solo = SOLO_IMPORTO.test(testo);
        if (solo && elemento.closest('td, th')) continue;
        if (solo && elemento.classList.contains('whitespace-nowrap')) continue;
        fuori.push(testo.trim().slice(0, 100));
    }

    return fuori;
}

/** Gli span nowrap che contengono un importo, con il loro testo. */
function spanDegliImporti(radice: Element): string[] {
    return Array.from(radice.querySelectorAll('.whitespace-nowrap')).map((e) => (e.textContent ?? '').trim()).filter((t) => IMPORTO.test(t));
}

const FRASE = 'Il conguaglio dà € 127,04 a Elsa e € 1.127,04 a Ugo, che restano a loro carico.';

// --- Il pannello «Cosa cambierà» ---------------------------------------------------------------------------------------------------------

const emesse = [1, 2].map((rata) => ({
    rata, scadenza: `2026-0${rata}-05`, importo: 3000, importo_formattato: '€ 30,00', intestatario: 'Ines Moretti', piano: 'Preventivo 2026', natura: 'ordinaria',
}));

/** La riga per gestione con le bozze che passano: la riga «intero piano … − bozze …» del pannello. */
const perGestione = {
    gestione_id: 1, gestione: 'Ordinaria 2026', immobile_id: 1, immobile_nome: 'Interno 1', natura: 'ordinaria', gradino: ['gestione'],
    periodo: [{ dal: '2026-01-01', al: '2026-12-31' }], voce_per_voce: false, quote: 12, quota_pura: 120000, pregressi: 0,
    giorni_uscente: 120, giorni_entrante: 245, giorni_periodo: 365, importo: 548, importo_formattato: '€ 127,04',
    importo_lordo: 80548, importo_lordo_formattato: '€ 127,04', bozze_passate: 8, bozze_passate_importo: 80000, bozze_passate_formattato: '€ 127,04',
    non_risolte: 0, non_separabili: 0, mai_passate: 0, escluse: 0, esercizio_id: 1,
};

/** L'anteprima di una vendita in cui ogni frase contiene almeno un importo («€ 127,04»). */
function anteprimaConImporti(): any {
    return {
        nudi: null,
        obbligati: { frasi: [FRASE], copia_autentica_mancante: false },
        invarianti: { frasi: [FRASE] },
        ordinaria: { applicabile: false, scelta: null, usufruttuario: null, nudo: null, voci: [], frasi: [], frasi_bloccate: [], frasi_altri_usufrutti: [], impronta: null, ereditata: null },
        riferimento: { uscente_fino_al: '2026-08-31', entrante_dal: '2026-09-01', frase: FRASE },
        anagrafica: { frasi: [FRASE], pertinenze: [] },
        rate: {
            stato: 'calcolato', emesse, piani_distinti: 1, totale_emesso: 6000, totale_emesso_formattato: '€ 60,00',
            altre: { quote: 6, intestatari: ['Ugo Rinaldi'] }, morosita: null,
            conguaglio: {
                stato: 'calcolato', anagrafica_uscente_id: 3, anagrafica_entrante_id: 4, quote: [], per_gestione: [perGestione],
                coppie: [{ gestione_id: 1, gestione: 'Ordinaria 2026', immobile_id: 1, esercizio_id: 1, importo: 12704, importo_formattato: '€ 127,04', per_nudo: null, anagrafica_entrante_id: 4, entrante_nome: 'Anna Neri' }],
                totale_entrante: 12704, totale_entrante_formattato: '€ 127,04', totale_entrante_assoluto_formattato: '€ 127,04',
                riassegnazione: [], pregressi: 0, non_risolte: [], frasi: [],
            },
            frasi: [FRASE],
            frasi_del_conguaglio: [FRASE],
            scelta_del_conguaglio: false,
            fermata_coinquilino: [],
            prospetto_esclude: [],
        },
        cancello: { richiesto: false, motivi: [], informazioni: [], avvisi: [] },
    };
}

function montaIlPannello() {
    return mount(AnteprimaPassaggio, { props: { dati: anteprimaConImporti(), inCorso: false, errore: false, mancante: [], tipo: 'vendita', senzaSuccessore: false, sceltaConguaglio: null } });
}

describe('AnteprimaPassaggio — gli importi delle frasi non vanno a capo (Coda 255)', () => {
    test('controllo — il pannello mostra le frasi e la riga «intero piano … − bozze …» con il dataset di prova', () => {
        const w = montaIlPannello();

        expect(w.text()).toContain(FRASE);
        expect(w.text()).toContain('intero piano € 127,04 − bozze € 127,04');
    });

    test('il testo delle frasi non cambia: wrapper.text() ha la frase intera, parola per parola, con il punto', () => {
        const testo = montaIlPannello().text();

        expect(testo).toContain(FRASE);
        // La frase compare nelle cinque sezioni (anagrafica, rate, calcolo del conguaglio, obbligati, invarianti).
        expect(testo.split(FRASE).length - 1).toBeGreaterThanOrEqual(5);
    });

    test('ogni importo di una frase sta da solo in uno span whitespace-nowrap: nessun nodo di testo con un importo fuori', () => {
        const fuori = importiAScapito(montaIlPannello().element);

        expect(fuori).toEqual([]);
    });

    test('lo span nowrap contiene la sola cifra: «€ 127,04» e «€ 1.127,04» senza il punto o la virgola che seguono', () => {
        const span = spanDegliImporti(montaIlPannello().element);

        expect(span).toContain('€ 127,04');
        expect(span).toContain('€ 1.127,04');
        expect(span.filter((t) => /[.,;:)]$/.test(t))).toEqual([]);
    });

    test('nessun v-html: un testo con un tag fra le frasi resta testo', () => {
        const dati = anteprimaConImporti();
        dati.rate.frasi = ['Il conguaglio dà € 127,04 e <b>questo non è un tag</b>.'];
        const w = mount(AnteprimaPassaggio, { props: { dati, inCorso: false, errore: false, mancante: [], tipo: 'vendita', senzaSuccessore: false, sceltaConguaglio: null } });

        expect(w.find('b').exists()).toBe(false);
        expect(w.text()).toContain('<b>questo non è un tag</b>');
    });
});

// --- Lo storico ----------------------------------------------------------------------------------------------------------------------------

/** Una voce dello storico in cui ogni frase contiene un importo («€ 127,04»); `si` dice se il passaggio si può annullare. */
function voceConImporti(si = true): any {
    return {
        id: 9, tipo_passaggio: 'fine_locazione', sottotipo: null, decorrenza: '2026-07-01', decorrenza_a_parole: '1 luglio 2026', registrato_il: '12 ottobre 2026',
        uscente: 'Ines', entrante: null, estremi_titolo: null, copia_autentica_il: null, copia_autentica_a_parole: null, copia_autentica_attesa: false, documento_url: null, pertinenze: [],
        conguaglio: { stato: 'proposto', importo: 12704, importo_formattato: '€ 127,04', per_entrante: [], applicato: false, nota: null, nota_annullamento: null, annullato_il: null, annullabile: true, avvisi: [FRASE] },
        arretrato: { frase: FRASE },
        obbligati: [FRASE],
        fermate: [FRASE],
        ordinaria: null, nota: null,
        annullato: false, annullato_il: null, annullato_da: null, nota_annullamento: null,
        annullabile: si ? { si: true, motivo: null, avvisi: [FRASE], effetti: [FRASE] } : { si: false, motivo: FRASE, avvisi: [], effetti: [] },
    };
}

function montaLoStorico(voce: any) {
    return mount(TitolaritaSheet, {
        props: {
            open: true, unita: 'Interno 1', condominioId: 18, immobileId: 24,
            storico: { passaggi: 1, periodi_chiusi: 0, righe: [], gruppi: [{ diritto: 'Proprietà', righe: [] }], subentri: [voce] },
        },
        global: {
            stubs: {
                Sheet: { template: '<div><slot /></div>' }, SheetContent: { template: '<div><slot /></div>' }, SheetHeader: { template: '<div><slot /></div>' },
                SheetTitle: { template: '<div><slot /></div>' }, SheetDescription: { template: '<div><slot /></div>' },
                Accordion: { template: '<div><slot /></div>' }, AccordionItem: { template: '<div><slot /></div>' },
                AccordionTrigger: { template: '<div><slot /></div>' }, AccordionContent: { template: '<div><slot /></div>' },
                BadgeRuolo: true,
                Input: { props: ['modelValue'], emits: ['update:modelValue'], template: '<input :value="modelValue" @input="$emit(\'update:modelValue\', $event.target.value)" />' },
            },
            mocks: { route: (n: string) => `/${n}` },
        },
    });
}

/** Il bottone con quel testo (parte del testo), cliccato. */
async function clicca(w: ReturnType<typeof montaLoStorico>, parte: string) {
    const bottone = w.findAll('button').find((b) => b.text().includes(parte));
    if (!bottone) throw new Error(`Il bottone «${parte}» non c'è.`);
    await bottone.trigger('click');
}

describe('TitolaritaSheet — gli importi delle frasi dello storico non vanno a capo (Coda 255)', () => {
    test('controllo — lo storico mostra le frasi del dataset di prova', () => {
        const testo = montaLoStorico(voceConImporti()).text();

        expect(testo).toContain(FRASE);
        expect(testo).toContain('€ 127,04 a debito di chi paga al posto dell\'inquilino');
    });

    test('la riga del conguaglio, le fermate, l\'arretrato e chi resta obbligato: ogni importo sta da solo in uno span nowrap', () => {
        const w = montaLoStorico(voceConImporti());
        expect(importiAScapito(w.element)).toEqual([]);
    });

    test('con il modulo dell\'annullamento del conguaglio aperto, l\'avviso ha gli importi nello span nowrap', async () => {
        const w = montaLoStorico(voceConImporti());
        await clicca(w, 'annulla il conguaglio');

        expect(w.text()).toContain(FRASE);
        expect(importiAScapito(w.element)).toEqual([]);
    });

    test('con il modulo dell\'annullamento del passaggio aperto, gli effetti e gli avvisi hanno gli importi nello span nowrap', async () => {
        const w = montaLoStorico(voceConImporti());
        await clicca(w, 'Annulla il passaggio…');

        expect(w.text()).toContain(FRASE);
        expect(importiAScapito(w.element)).toEqual([]);
    });

    test('il perché di un annullamento non consentito, aperto, ha gli importi nello span nowrap', async () => {
        const w = montaLoStorico(voceConImporti(false));
        await clicca(w, 'non consentito: perché?');

        expect(w.text()).toContain(FRASE);
        expect(importiAScapito(w.element)).toEqual([]);
    });

    test('lo span nowrap contiene la sola cifra (senza il punto finale)', () => {
        const span = spanDegliImporti(montaLoStorico(voceConImporti()).element);

        expect(span).toContain('€ 127,04');
        expect(span.filter((t) => /[.,;:)]$/.test(t))).toEqual([]);
    });
});
