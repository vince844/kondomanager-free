// @vitest-environment jsdom

/**
 * 1.11.0-beta.50 — lo storico dell'unità («Chi ha avuto questa unità») per la fine di una locazione: la fermata dopo «Scrivi il
 * conguaglio» e quanto ne resta dopo un inizio, dal registro (`fermate`, decisione 77, punto 3), e la strada «annulla il passaggio e
 * registralo di nuovo con «Non scriverlo»» accanto ad «annulla il conguaglio» anche nella fine locazione, non solo nella successione.
 * Lo stesso montaggio di `TitolaritaSheet.test.ts`.
 */

import { describe, expect, test, vi } from 'vitest';
import { mount } from '@vue/test-utils';

vi.mock('@inertiajs/vue3', async (importOriginal) => ({
    ...(await importOriginal<typeof import('@inertiajs/vue3')>()),
    usePage: () => ({ props: { auth: { user: { roles: ['amministratore'], permissions: [] } } } }),
}));

import TitolaritaSheet from './TitolaritaSheet.vue';

(globalThis as any).route = (n: string) => `/${n}`;

function passaggio(extra: Record<string, unknown> = {}): any {
    return {
        id: 9, tipo_passaggio: 'fine_locazione', sottotipo: null, decorrenza: '2026-07-01', decorrenza_a_parole: '1 luglio 2026', registrato_il: '11 ottobre 2026',
        uscente: 'Ines', entrante: null, estremi_titolo: null, copia_autentica_il: null, copia_autentica_a_parole: null,
        copia_autentica_attesa: false, documento_url: null, pertinenze: [],
        conguaglio: { stato: 'proposto', importo: 18148, importo_formattato: '€ 181,48', applicato: false, nota: null, nota_annullamento: null, annullato_il: null },
        obbligati: ['Le rate già emesse a Ines restano sue.'], ordinaria: null, nota: null,
        annullato: false, annullato_il: null, annullato_da: null, nota_annullamento: null,
        annullabile: { si: true, motivo: null, avvisi: [], effetti: [] },
        ...extra,
    };
}

function monta(p: any) {
    return mount(TitolaritaSheet, {
        props: {
            open: true, unita: 'Interno 1', condominioId: 18, immobileId: 24,
            storico: { passaggi: 1, periodi_chiusi: 0, righe: [], gruppi: [{ diritto: 'Proprietà', righe: [] }], subentri: [p] },
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

const FERMATA = 'Il conguaglio scritto alla fine locazione di Ines del 1 luglio 2026 non è passato con questo passaggio: € 90,74 dei giorni dal 1 ottobre 2026 sono rimasti a carico di Ugo sulla gestione Ordinaria 2026 (Interno 1), da regolare a mano con Elsa.';

describe('TitolaritaSheet — la fine di una locazione (1.11.0-beta.50)', () => {
    test('la fermata dal registro si legge nello storico del passaggio', () => {
        const w = monta(passaggio({ tipo_passaggio: 'vendita', uscente: 'Ugo', entrante: 'Elsa', fermate: [FERMATA] }));
        expect(w.text()).toContain(FERMATA);
    });

    test('accanto ad «annulla il conguaglio» della fine locazione c\'è l\'altra strada: annullare il passaggio e registrarlo con «Non scriverlo»', () => {
        expect(monta(passaggio()).text()).toContain('Se invece il conguaglio non andava scritto, annulla il passaggio e registralo di nuovo con «Non scriverlo».');
        // Controllo: nella vendita la strada non c'è (la vendita tiene la casella).
        expect(monta(passaggio({ tipo_passaggio: 'vendita' })).text()).not.toContain('registralo di nuovo con «Non scriverlo»');
    });
});

/**
 * 1.11.0-beta.55 — «Annulla il conguaglio» si offre solo dove la rotta lo accetta. Se una successione con l'arretrato agli eredi ha preso fra le sue fonti una riga della
 * coppia, il server (`StoricoTitolarita`, la stessa regola di `AnnullaConguaglioAction`) manda `annullabile: false` e il perché con la strada (`motivo`): la scheda lo
 * mostra al posto del comando, e non ha avvisi da seguire. Senza `motivo` (l'arretrato agli eredi del conguaglio stesso) resta la frase di sempre.
 */
describe('TitolaritaSheet — il conguaglio che una successione ha letto (1.11.0-beta.55)', () => {
    const MOTIVO = 'Una riga di questo conguaglio è fra le cifre da cui è calcolato l\'arretrato di Ugo, passato agli eredi con la successione del 15 ottobre 2026: annullarlo lascerebbe '
        + 'sbagliate le righe degli eredi. Annulla prima la successione dallo storico dell\'unità, poi questo conguaglio, e registra di nuovo la successione.';
    const conguaglio = (extra: Record<string, unknown> = {}) => ({
        stato: 'proposto', importo: 18148, importo_formattato: '€ 181,48', applicato: false, nota: null, nota_annullamento: null, annullato_il: null, annullabile: true, avvisi: [], ...extra,
    });
    const comandi = (w: ReturnType<typeof monta>) => w.findAll('button').filter((b) => /annulla il conguaglio/i.test(b.text()));

    test('dice il perché e la strada al posto del comando', () => {
        const w = monta(passaggio({ conguaglio: conguaglio({ annullabile: false, motivo: MOTIVO }) }));

        expect(w.text()).toContain(MOTIVO);
        expect(comandi(w)).toHaveLength(0);
        expect(w.text()).not.toContain('Le parti hanno regolato diversamente');
        // Non è l'arretrato agli eredi del conguaglio stesso: la frase di quel caso non c'entra.
        expect(w.text()).not.toContain('Per cambiare la scelta annulla il passaggio e registralo di nuovo.');
    });

    test('controllo — con l\'arretrato agli eredi del conguaglio stesso resta la frase di sempre', () => {
        const w = monta(passaggio({ conguaglio: conguaglio({ annullabile: false }) }));

        expect(w.text()).toContain('Per cambiare la scelta annulla il passaggio e registralo di nuovo.');
        expect(comandi(w)).toHaveLength(0);
    });

    test('controllo — dove il conguaglio si annulla il comando c\'è, e il perché non si dice', () => {
        const w = monta(passaggio({ conguaglio: conguaglio() }));

        expect(comandi(w)).toHaveLength(1);
        expect(w.text()).not.toContain('Annulla prima la successione');
    });
});

/**
 * 1.11.0-beta.55 — la fine di una locazione con «Non scriverlo»: nei saldi non c'è niente, e lo storico dice le cifre che le parti si regolano fra loro (il server le manda in
 * `fra_le_parti`, dal registro): € 60,66 a credito di Ines e a debito di Dora. Con «Scrivi» le cifre sono nei saldi, e la frase non c'è.
 */
describe('TitolaritaSheet — le cifre che «Non scriverlo» lascia fra le parti (1.11.0-beta.55)', () => {
    const FRA_LE_PARTI = 'Le parti regolano fra loro € 60,66 a credito di Ines e € 60,66 a debito di Dora, sulla gestione Ordinaria 2026.';
    const nonScritto = (extra: Record<string, unknown> = {}) => ({
        stato: 'non_scritto', importo: 0, importo_formattato: '€ 0,00', per_entrante: [], applicato: false, nota: null, nota_annullamento: null, annullato_il: null, annullabile: true, avvisi: [], ...extra,
    });

    test('lo storico dice «nessuna riga in saldi» e, accanto, le cifre che le parti si regolano fra loro', () => {
        const testo = monta(passaggio({ conguaglio: nonScritto({ fra_le_parti: FRA_LE_PARTI }) })).text();

        expect(testo).toContain('Conguaglio non scritto: nessuna riga in saldi, la posizione resta com\'era.');
        expect(testo).toContain(FRA_LE_PARTI);
    });

    test('con la nota facoltativa la nota viene prima delle cifre', () => {
        const testo = monta(passaggio({ conguaglio: nonScritto({ nota: 'Dora rimborsa a Ines a fine anno', fra_le_parti: FRA_LE_PARTI }) })).text();

        expect(testo).toContain('la posizione resta com\'era («Dora rimborsa a Ines a fine anno»). ' + FRA_LE_PARTI);
    });

    test('controllo — senza le cifre (la successione, o un registro che non le ha) la frase non c\'è', () => {
        const testo = monta(passaggio({ conguaglio: nonScritto({ fra_le_parti: null }) })).text();

        expect(testo).toContain('Conguaglio non scritto: nessuna riga in saldi, la posizione resta com\'era.');
        expect(testo).not.toContain('Le parti regolano fra loro');
        expect(testo).not.toMatch(/\bnull\b/);
    });
});
