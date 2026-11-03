// @vitest-environment jsdom

/**
 * 1.11.0-beta.51, §6.3 — il pannello «Cosa cambierà» quando il conguaglio che si scrive prenderebbe un piano da ricalcolare prima.
 *
 * Il server non toglie il pannello: risponde con il conguaglio proposto e con l'avviso `rate.piano_da_ricalcolare` («Ricalcola prima il
 * piano «…», poi registra questo passaggio…»). La via d'uscita sta nello stesso pannello e deve restare raggiungibile: nella vendita e
 * nell'usufrutto la casella «Le parti hanno regolato il conguaglio fra loro» (con la sua ragione), nella successione, nel legato e nella
 * fine di una locazione le due voci «Scrivi il conguaglio» e «Non scriverlo». Senza, l'unica uscita sarebbe ripristinare il piano, anche
 * quando le parti hanno già regolato fra loro.
 *
 * Presidia: l'avviso si vede, sta sopra il conguaglio (prima della casella o delle voci), e ci sono casella e voci, cliccabili, che
 * portano la scelta al modulo; senza avviso il pannello non ne ha il riquadro; con «Non scriverlo» l'avviso non c'è più (lo toglie il
 * server) e il pannello è quello di sempre. I JSON sono quelli dell'anteprima vera, alleggeriti delle chiavi che il pannello non legge:
 * la vendita Ugo → Elsa del 1/6 sul piano «Preventivo 2026» stantio e fermo (la coppia sulla base di prima è −178 / +178, € 1,78).
 *
 * Cosa NON copre: il server (l'avviso, la registrazione che si rifiuta: `Beta51PianoRatePassaggioAnnullatoTest`); il modulo
 * `PassaggioNew.vue` (il pulsante spento, la rinuncia che parte con l'anteprima: `PassaggioNewPianoDaRicalcolare.test.ts`).
 */

import { describe, expect, test } from 'vitest';
import { mount, type VueWrapper } from '@vue/test-utils';
import { defineComponent, h, ref } from 'vue';

import AnteprimaPassaggio from './AnteprimaPassaggio.vue';

type Scelta = 'scrivi' | 'non_scrivere' | null;

const AVVISO = 'Ricalcola prima il piano «Preventivo 2026», poi registra questo passaggio: le sue quote sono state calcolate con la titolarità del passaggio di Ugo su Interno A, dal 1 maggio 2026, che poi è stato annullato, e il conguaglio di questo passaggio le prenderebbe così come sono, intestate a chi non è più titolare. Oggi il ricalcolo si rifiuta perché il piano ha un incasso su una sua quota. Per procedere: annulla quel movimento; ricalcola il piano; registra di nuovo quel movimento.';
const CASELLA = 'Le parti hanno regolato il conguaglio fra loro';

const perGestione = {
    gestione_id: 1, gestione: 'Ordinaria 2026', immobile_id: 1, immobile_nome: 'Interno A', natura: 'ordinaria', gradino: ['gestione'],
    periodo: [{ dal: '2026-01-01', al: '2026-12-31' }], voce_per_voce: false, quote: 12, quota_pura: 60000, pregressi: 0,
    giorni_uscente: 151, giorni_entrante: 214, giorni_periodo: 365, importo: 178, importo_formattato: '€ 1,78',
    importo_lordo: 35178, importo_lordo_formattato: '€ 351,78', bozze_passate: 7, bozze_passate_importo: 35000, bozze_passate_formattato: '€ 350,00',
    non_risolte: 0, non_separabili: 0, mai_passate: 0, escluse: 0, esercizio_id: 1,
};

/** Il pannello come lo manda il server: la coppia è proposta, l'avviso c'è o no. */
function pannello(avviso: string | null, scelta: boolean, arretrato: unknown = null): any {
    return {
        nudi: null,
        riferimento: { uscente_fino_al: '2026-05-31', entrante_dal: '2026-06-01', frase: 'Elsa risulterà proprietaria dal 1 giugno 2026.' },
        anagrafica: { frasi: ['Ugo risulterà proprietario fino al 31 maggio 2026.'], pertinenze: [] },
        rate: {
            stato: 'calcolato', emesse: [], piani_distinti: 1, totale_emesso: 0, totale_emesso_formattato: '€ 0,00', altre: { quote: 0, intestatari: [] }, morosita: null,
            conguaglio: {
                stato: 'calcolato', anagrafica_uscente_id: 1, anagrafica_entrante_id: 2, quote: [], per_gestione: [perGestione],
                coppie: [{ gestione_id: 1, gestione: 'Ordinaria 2026', immobile_id: 1, esercizio_id: 1, importo: 178, importo_formattato: '€ 1,78', anagrafica_entrante_id: 2, entrante_nome: 'Elsa' }],
                totale_entrante: 178, totale_entrante_formattato: '€ 1,78', totale_entrante_assoluto_formattato: '€ 1,78', riassegnazione: [], pregressi: 0, non_risolte: [], frasi: [],
            },
            piano_da_ricalcolare: avviso, arretrato,
            frasi: ['Le rate già emesse non si toccano.'], frasi_del_conguaglio: [], scelta_del_conguaglio: scelta, fermata_coinquilino: [],
        },
        obbligati: { frasi: ['Ugo resta obbligato per le rate emesse.'], copia_autentica_mancante: false },
        invarianti: { frasi: ['Millesimi: invariati.'] },
        cancello: { richiesto: false, motivi: [], informazioni: [], avvisi: [] },
        ordinaria: { applicabile: false, scelta: null, usufruttuario: null, nudo: null, voci: [], frasi: [], frasi_bloccate: [], frasi_altri_usufrutti: [], impronta: null, ereditata: null },
    };
}

const ARRETRATO_AL_DEFUNTO = {
    scelta: 'defunto', legato: false, resta: 0, resta_formattato: '€ 0,00', eredi: [], totale: 0, frase: 'A nome di Ugo («eredi di Ugo») resta € 0,00.',
};

function monta(dati: any, tipo: string, props: Record<string, unknown> = {}) {
    return mount(AnteprimaPassaggio, { props: { dati, inCorso: false, errore: false, mancante: [], tipo, sceltaConguaglio: null, ...props } });
}

const radio = (w: ReturnType<typeof monta>) => w.findAll('input[type="radio"]');
const casella = (w: ReturnType<typeof monta>) => w.findAll('button[role="checkbox"]');

/** L'avviso nel DOM: il riquadro con il testo del server. Vale per il pannello montato da solo e per quello dentro un genitore. */
const riquadroAvviso = (w: Pick<VueWrapper, 'find'>) => w.find('[data-testid="piano-da-ricalcolare"]');

describe('AnteprimaPassaggio — l\'avviso «ricalcola prima il piano» e la via d\'uscita', () => {
    test('vendita: l\'avviso si vede con il testo del server, e la casella della rinuncia c\'è, sotto l\'avviso', () => {
        const w = monta(pannello(AVVISO, false), 'vendita');

        expect(riquadroAvviso(w).exists()).toBe(true);
        expect(riquadroAvviso(w).text()).toContain(AVVISO);
        expect(w.text()).toContain(CASELLA);
        expect(casella(w)).toHaveLength(1);
        // L'avviso sta sopra il conguaglio: viene prima della casella nel documento.
        const html = w.html();
        expect(html.indexOf('data-testid="piano-da-ricalcolare"')).toBeLessThan(html.indexOf(CASELLA));
        expect(html.indexOf('data-testid="piano-da-ricalcolare"')).toBeLessThan(html.indexOf('Debito a chi entra'));
    });

    test('vendita: la casella è cliccabile, porta la rinuncia al modulo e chiede la ragione', async () => {
        const rinuncia = ref(false);
        const Genitore = defineComponent({
            setup: () => () => h(AnteprimaPassaggio, {
                dati: pannello(AVVISO, false), inCorso: false, errore: false, mancante: [], tipo: 'vendita',
                rinuncia: rinuncia.value, 'onUpdate:rinuncia': (v: boolean) => { rinuncia.value = v; },
            }),
        });
        const w = mount(Genitore);

        expect(w.find('input[placeholder^="Es: regolato"]').exists()).toBe(false);
        await w.find('button[role="checkbox"]').trigger('click');
        expect(rinuncia.value).toBe(true);
        expect(w.find('input[placeholder^="Es: regolato"]').exists()).toBe(true);
        expect(w.text()).toContain('regolato fra le parti');
    });

    test('successione con l\'arretrato a nome del defunto: l\'avviso c\'è e le due voci anche, con «Non scriverlo» cliccabile', async () => {
        const scelta = ref<Scelta>('scrivi');
        const Genitore = defineComponent({
            setup: () => () => h(AnteprimaPassaggio, {
                dati: pannello(AVVISO, true, ARRETRATO_AL_DEFUNTO), inCorso: false, errore: false, mancante: [], tipo: 'successione',
                sceltaConguaglio: scelta.value, 'onUpdate:sceltaConguaglio': (v: Scelta) => { scelta.value = v; },
            }),
        });
        const w = mount(Genitore);

        expect(riquadroAvviso(w).text()).toContain(AVVISO);
        expect(w.findAll('input[type="radio"]').map((r) => (r.element as HTMLInputElement).value)).toEqual(['scrivi', 'non_scrivere']);
        expect((w.findAll('input[type="radio"]')[0].element as HTMLInputElement).checked).toBe(true);
        // Le voci stanno sotto l'avviso.
        expect(w.html().indexOf('data-testid="piano-da-ricalcolare"')).toBeLessThan(w.html().indexOf('Non scriverlo'));

        await w.findAll('input[type="radio"]')[1].setValue(true);
        expect(scelta.value).toBe('non_scrivere');
    });

    test('fine di una locazione: l\'avviso c\'è e le due voci anche, con «Non scriverlo» cliccabile', async () => {
        const scelta = ref<Scelta>('scrivi');
        const Genitore = defineComponent({
            setup: () => () => h(AnteprimaPassaggio, {
                dati: pannello(AVVISO, true), inCorso: false, errore: false, mancante: [], tipo: 'fine_locazione', senzaSuccessore: false,
                sceltaConguaglio: scelta.value, 'onUpdate:sceltaConguaglio': (v: Scelta) => { scelta.value = v; },
            }),
        });
        const w = mount(Genitore);

        expect(riquadroAvviso(w).text()).toContain(AVVISO);
        expect(w.findAll('input[type="radio"]')).toHaveLength(2);
        await w.findAll('input[type="radio"]')[1].setValue(true);
        expect(scelta.value).toBe('non_scrivere');
    });

    test('senza avviso il riquadro non c\'è: il pannello è quello di sempre, con la casella', () => {
        const w = monta(pannello(null, false), 'vendita');

        expect(riquadroAvviso(w).exists()).toBe(false);
        expect(w.text()).not.toContain('Ricalcola prima il piano');
        expect(w.text()).toContain(CASELLA);
    });

    test('con «Non scriverlo» il server non manda più l\'avviso: il pannello dice «conguaglio non scritto», senza riquadro', () => {
        const w = monta(pannello(null, true), 'fine_locazione', { sceltaConguaglio: 'non_scrivere', senzaSuccessore: false });

        expect(riquadroAvviso(w).exists()).toBe(false);
        expect(w.text()).toContain('conguaglio non scritto');
    });

    test('il riquadro è un avviso per chi usa un lettore di schermo, e dice che la registrazione è ferma', () => {
        const riquadro = riquadroAvviso(monta(pannello(AVVISO, false), 'vendita'));

        expect(riquadro.attributes('role')).toBe('alert');
        expect(riquadro.text()).toContain('Finché non ricalcoli il piano o scegli di non scrivere il conguaglio, il passaggio non si registra.');
    });
});
