// @vitest-environment jsdom

/**
 * 1.11.0-beta.50, giro stretto sulle correzioni della Fase 1-bis — due pezzi di pagina che la verifica delle correzioni ha trovato senza
 * un vitest che li tenga (verbale `piano_esecutivo_beta50.md`, «Le correzioni della Fase 1-bis», «Restano senza test»). Erano giusti già
 * prima: i test fissano il comportamento, e cadono togliendo il pezzo (provato con una mutazione in una copia).
 *
 * - R5, decisione 83.2: «Annulla il conguaglio» dopo una fermata avvisa, non rifiuta. Lo storico dell'unità (`TitolaritaSheet.vue`) mostra
 *   gli avvisi che il server mette in `conguaglio.avvisi`, prima di confermare: dentro la conferma, prima del pulsante. Il caso è quello
 *   del server (`RevisioneBeta50FrasiTest`, caso H): la fine di Ines il 1/7 con «Scrivi» (coppia € 181,48 a Ugo: 36000 × 184/365 =
 *   18147,95 → 18148), poi la vendita di Ugo a Elsa il 1/10, ferma su € 90,74 (18148 × 92/184 = 9074, esatto).
 * - R10: nel cambio d'inquilino, la voce «Non scriverlo» del pannello «Cosa cambierà» (`AnteprimaPassaggio.vue`) dice i piani che il
 *   prospetto degli oneri accessori lascia fuori (`rate.prospetto_esclude`, generati da una versione precedente): lì chi entra rimborsa
 *   fra le parti, non con il prospetto.
 *
 * Il montaggio è quello di `TitolaritaSheetLocazione.test.ts` e di `AnteprimaPassaggioLocazione.test.ts`; i JSON sono quelli del server,
 * alleggeriti delle chiavi che le pagine non leggono.
 *
 * Che cosa NON copre: il testo degli avvisi della 83.2 e la frase della fermata, che arrivano dal server così come sono (lo coprono i
 * test PHP, e i rilievi G11 e G12 li cambiano); il messaggio dopo l'annullamento (G8); la richiesta che parte con la conferma; i piani che
 * il server mette in `prospetto_esclude` (il test PHP di R10); il modo scuro e le larghezze.
 */

import { describe, expect, test, vi } from 'vitest';
import { mount } from '@vue/test-utils';

vi.mock('@inertiajs/vue3', async (importOriginal) => ({
    ...(await importOriginal<typeof import('@inertiajs/vue3')>()),
    usePage: () => ({ props: { auth: { user: { roles: ['amministratore'], permissions: [] } } } }),
}));

import TitolaritaSheet from './TitolaritaSheet.vue';
import AnteprimaPassaggio from './AnteprimaPassaggio.vue';

(globalThis as any).route = (n: string) => `/${n}`;

// --- R5: l'avviso di «Annulla il conguaglio» -------------------------------------------------------------------------------------

/**
 * L'avviso che il server dà per il caso H (`FermataDopoScrivi::avvisiDelConguaglio`), con i saldi del Wallet legati alla vendita: la forma che
 * `Beta53AnnullaConRigheLegateTest.php` prova che il server manda.
 */
const AVVISO = 'La vendita del 1 ottobre 2026 si è fermata su questo conguaglio: € 90,74 dei giorni dal 1 ottobre 2026 sono rimasti a carico di Ugo sulla gestione Ordinaria 2026, da regolare a mano con Elsa. Annullato il conguaglio, quella fermata non vale più. I saldi del Wallet legati alla vendita del 1 ottobre 2026 sommano oggi € 90,74 a credito di Ugo e € 90,74 a debito di Elsa; la parte della sua fermata è € 0,00. L\'annullamento del conguaglio non li cambia: correggi dal Wallet i saldi che si modificano.';

/** La voce dello storico della fine di Ines con «Scrivi», come la dà `StoricoTitolarita`, con gli avvisi del conguaglio dati. */
function fine(avvisi: string[]): any {
    return {
        id: 9, tipo_passaggio: 'fine_locazione', sottotipo: null, decorrenza: '2026-07-01', decorrenza_a_parole: '1 luglio 2026', registrato_il: '12 ottobre 2026',
        uscente: 'Ines', entrante: null, estremi_titolo: null, copia_autentica_il: null, copia_autentica_a_parole: null,
        copia_autentica_attesa: false, documento_url: null, pertinenze: [],
        conguaglio: { stato: 'proposto', importo: 18148, importo_formattato: '€ 181,48', per_entrante: ['€ 181,48 a debito di Ugo'], applicato: false, nota: null, nota_annullamento: null, annullato_il: null, annullabile: true, avvisi },
        obbligati: ['Le rate già emesse a Ines restano sue.'], fermate: [], ordinaria: null, nota: null,
        annullato: false, annullato_il: null, annullato_da: null, nota_annullamento: null,
        annullabile: { si: false, motivo: 'Dopo questo passaggio ne è stato registrato un altro: si annulla per primo l\'ultimo registrato.', avvisi: [], effetti: [] },
    };
}

function montaLoStorico(p: any) {
    return mount(TitolaritaSheet, {
        props: {
            open: true, unita: 'Interno 1', condominioId: 18, immobileId: 24,
            storico: { passaggi: 2, periodi_chiusi: 0, righe: [], gruppi: [{ diritto: 'Proprietà', righe: [] }], subentri: [p] },
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

/** Apre la conferma di «Annulla il conguaglio» e restituisce il modulo della conferma. */
async function apriLaConferma(w: ReturnType<typeof montaLoStorico>) {
    const apri = w.findAll('button').find((b) => b.text().includes('annulla il conguaglio…'));
    if (!apri) throw new Error('Il comando «annulla il conguaglio…» non c\'è.');
    await apri.trigger('click');
    const conferma = w.find('form');
    if (!conferma.exists()) throw new Error('La conferma di «Annulla il conguaglio» non si è aperta.');

    return conferma;
}

describe('TitolaritaSheet — R5, l\'avviso di «Annulla il conguaglio» dopo una fermata (decisione 83.2)', () => {
    test('aperta la conferma, l\'avviso della vendita del 1/10 si legge prima del pulsante «Annulla il conguaglio»', async () => {
        const conferma = await apriLaConferma(montaLoStorico(fine([AVVISO])));

        // Controllo: la conferma è quella del conguaglio, con la sua frase e il suo pulsante.
        expect(conferma.text()).toContain('Toglie insieme le righe del conguaglio dai saldi della gestione');
        const pulsante = conferma.findAll('button[type="submit"]').find((b) => b.text().includes('Annulla il conguaglio'));
        expect(pulsante).toBeDefined();

        expect(conferma.text()).toContain(AVVISO);
        const testo = conferma.text();
        expect(testo.indexOf(AVVISO)).toBeLessThan(testo.indexOf('Annulla il conguaglio'));
    });

    test('con due fermate su quel conguaglio, gli avvisi sono due', async () => {
        const seconda = AVVISO.replace('La vendita del 1 ottobre 2026', 'La vendita del 1 novembre 2026').replace('dal 1 ottobre 2026', 'dal 1 novembre 2026');
        const conferma = await apriLaConferma(montaLoStorico(fine([AVVISO, seconda])));

        expect(conferma.text()).toContain(AVVISO);
        expect(conferma.text()).toContain(seconda);
    });

    test('la guardia — senza fermate la conferma non ha avvisi: nessuna riga in ambra', async () => {
        const conferma = await apriLaConferma(montaLoStorico(fine([])));

        expect(conferma.text()).toContain('Toglie insieme le righe del conguaglio dai saldi della gestione');
        expect(conferma.findAll('p.text-amber-800')).toHaveLength(0);
        expect(conferma.text()).not.toContain('si è fermata');
    });
});

// --- R10: la voce «Non scriverlo» con un piano che il prospetto lascia fuori --------------------------------------------------------

const emesse = [1, 2, 3, 4, 5, 6].map((rata) => ({
    rata, scadenza: `2026-0${rata}-05`, importo: 3000, importo_formattato: '€ 30,00', intestatario: 'Ines', piano: 'Preventivo 2026', natura: 'ordinaria',
}));

/**
 * Il cambio d'inquilino di Ines il 1/7, con Luca che entra, come lo manda il server: la coppia € 181,48 fra Ines e Luca. `esclusi`: i piani
 * che il prospetto lascia fuori (`rate.prospetto_esclude`).
 */
function cambio(esclusi?: string[]): any {
    return {
        nudi: null,
        obbligati: { frasi: ['Le rate già emesse a Ines restano sue.'], copia_autentica_mancante: false },
        invarianti: { frasi: ['Millesimi: invariati.'] },
        ordinaria: { applicabile: false, scelta: null, usufruttuario: null, nudo: null, voci: [], frasi: [], frasi_bloccate: [], frasi_altri_usufrutti: [], impronta: null, ereditata: null },
        riferimento: { uscente_fino_al: '2026-06-30', entrante_dal: '2026-07-01', frase: 'Ines risulterà inquilino fino al 30 giugno 2026 compreso.' },
        anagrafica: { frasi: ['Ines risulterà inquilino fino al 30 giugno 2026.', 'Luca inquilino dal 1 luglio 2026, al 100 %.'], pertinenze: [] },
        rate: {
            stato: 'calcolato', emesse, piani_distinti: 1, totale_emesso: 18000, totale_emesso_formattato: '€ 180,00',
            altre: { quote: 6, intestatari: ['Ugo'] }, morosita: null,
            conguaglio: {
                stato: 'calcolato', anagrafica_uscente_id: 2, anagrafica_entrante_id: 3, quote: [], per_gestione: [],
                coppie: [{ gestione_id: 1, gestione: 'Ordinaria 2026', immobile_id: 1, esercizio_id: 1, importo: 18148, importo_formattato: '€ 181,48', per_nudo: null, anagrafica_entrante_id: 3, entrante_nome: 'Luca' }],
                totale_entrante: 18148, totale_entrante_formattato: '€ 181,48', totale_entrante_assoluto_formattato: '€ 181,48',
                riassegnazione: [], pregressi: 0, non_risolte: [], frasi: [],
            },
            frasi: ['Le rate già emesse non si toccano.'],
            frasi_del_conguaglio: [],
            scelta_del_conguaglio: true,
            fermata_coinquilino: [],
            ...(esclusi ? { prospetto_esclude: esclusi } : {}),
        },
        cancello: { richiesto: true, motivi: ['6 quote di rate già emesse a Ines su questa unità'], informazioni: [], avvisi: [] },
    };
}

function montaIlPannello(dati: any) {
    return mount(AnteprimaPassaggio, { props: { dati, inCorso: false, errore: false, mancante: [], tipo: 'fine_locazione', senzaSuccessore: false, sceltaConguaglio: null } });
}

/** Il testo della voce «Non scriverlo». */
function voceNonScriverlo(w: ReturnType<typeof montaIlPannello>): string {
    const voce = w.findAll('label').find((l) => l.find('input[type="radio"][value="non_scrivere"]').exists());
    if (!voce) throw new Error('La voce «Non scriverlo» non c\'è.');

    return voce.text();
}

describe('AnteprimaPassaggio — R10, il cambio d\'inquilino con un piano che il prospetto degli oneri accessori lascia fuori', () => {
    test('la voce «Non scriverlo» dice il piano «Preventivo 2025», generato da una versione precedente, e che lì chi entra rimborsa fra le parti', () => {
        const testo = voceNonScriverlo(montaIlPannello(cambio(['Preventivo 2025'])));

        expect(testo).toContain('Nessuna riga in saldi: i giorni dopo l\'uscita restano nelle rate di chi esce.');
        expect(testo).toContain('Il prospetto degli oneri accessori lascia fuori il piano «Preventivo 2025», generato da una versione precedente: lì chi entra glieli rimborsa fra le parti.');
        expect(testo).not.toContain('glieli rimborsa con il prospetto degli oneri accessori');
    });

    test('con due piani lasciati fuori, la voce li dice tutti e due, al plurale', () => {
        const testo = voceNonScriverlo(montaIlPannello(cambio(['Preventivo 2025', 'Straordinaria tetto'])));

        expect(testo).toContain('lascia fuori i piani «Preventivo 2025», «Straordinaria tetto», generati da una versione precedente: lì chi entra glieli rimborsa fra le parti.');
    });

    test('la guardia — senza piani lasciati fuori la voce dice il rimborso con il prospetto, e non parla di versioni precedenti', () => {
        for (const dati of [cambio(), cambio([])]) {
            const testo = voceNonScriverlo(montaIlPannello(dati));
            expect(testo).toContain('Nessuna riga in saldi: i giorni dopo l\'uscita restano nelle rate di chi esce, e chi entra glieli rimborsa con il prospetto degli oneri accessori.');
            expect(testo).not.toContain('versione precedente');
        }
    });
});
