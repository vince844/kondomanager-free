// @vitest-environment jsdom

/**
 * 1.11.0-beta.48 — il pannello «Cosa cambierà» della successione, montato (rilievi R1, R2 e R5 della lente testi della Fase 1-bis di
 * `docs/piano_esecutivo_beta48.md`).
 *
 * Il verbale della Fase 1 diceva che il pannello «non ha test di componente: si guardano a video». La revisione ha trovato tre difetti
 * che vivono solo lì, e che un montaggio con jsdom e un JSON di anteprima coglie senza il browser:
 * - R1: finché la scelta sul conguaglio manca, il pannello descrive come esito quello di «Scrivi» — il riquadro «conguaglio proposto»,
 *   «€ 394,52 resta a nome di Ugo» — cioè la preselezione che la decisione 72 toglie. Prima della scelta il riquadro dice «conguaglio da
 *   scegliere» e la frase dell'arretrato è `frase_da_scegliere`, con le due cifre («€ 400,00 senza conguaglio, € 394,52 se scrivi il
 *   conguaglio»; con le quattro rate pagate «€ 0,00 senza conguaglio, € 5,48 a credito se scrivi il conguaglio»).
 * - R2: con «Non scriverlo» il pannello diceva ancora che il conguaglio è proposto e divideva i giorni per quota («€ 268,47 a Bruno…»),
 *   cifre false per la scelta fatta (Bruno e Carla hanno € 0,00). Il server ora manda le frasi del calcolo per quota in una chiave sua,
 *   `rate.frasi_del_conguaglio`: il pannello le mostra prima della scelta e con «Scrivi», non con «Non scriverlo».
 * - R5: le tabelle del pannello scrivevano «a Anna» (`a {{ c.entrante_nome }}`, `a {{ e.nome }}`): la preposizione va con il nome
 *   (`aNome` in `preposizione.ts`).
 *
 * Il JSON è quello dell'anteprima vera del caso di Fresco: lo ha dato una sonda PHP (`ruAnteprima` sullo scenario `prima_rata`, le
 * rate 1–4 emesse, Ugo muore il 1° maggio 2026, eredi Anna 33,34 %, Bruno e Carla 33,33 %, Anna erede di riferimento, arretrato a nome
 * del defunto), poi alleggerito (`conguaglio.quote` vuoto: il pannello non lo legge) e portato alla forma voluta dalla Fase 1-bis —
 * `rate.frasi` senza il calcolo per quota, che sta in `rate.frasi_del_conguaglio`, la frase d'apertura al condizionale («Se scrivi
 * il conguaglio…»), «ad Anna» nelle frasi, `arretrato.frase_da_scegliere`. Le chiavi nuove non sono ancora nei tipi di `passaggi.ts`: il
 * fixture è `any`, come quello del pannello dello storico.
 *
 * Le cifre, rifatte a mano. I giorni: 1 gennaio–30 aprile 31 + 28 + 31 + 30 = 120 a Ugo; 1 maggio–31 dicembre 31 + 30 + 31 + 31 + 30 +
 * 31 + 30 + 31 = 245 agli eredi. € 1.200,00 × 245 / 365 = € 805,48 (80548 centesimi); per quota 80548 × 33,34 % = 26854,70,
 * 80548 × 33,33 % = 26846,65: a 80546 mancano 2 centesimi, che vanno ai resti maggiori, quindi Anna 26855 (€ 268,55), Bruno 26847
 * (€ 268,47), Carla 26846 (€ 268,46), somma 80548. Le otto bozze (€ 800,00) passano ad Anna: la sua coppia è 26855 − 80000 = −53145,
 * € 531,45 a credito; Bruno € 268,47 e Carla € 268,46 a debito; la somma −53145 + 26847 + 26846 = +548, € 5,48, è il credito di Ugo
 * (4 × € 100,00 = € 400,00 emessi, meno i suoi € 1.200,00 × 120 / 365 = € 394,52). Senza conguaglio Ugo resta con € 400,00 e Anna
 * con € 800,00. L'arretrato agli eredi: 39452 × 33,34 % = 13153,30 → 13153 (€ 131,53); 39452 × 33,33 % = 13149,35 → 13149 (€ 131,49);
 * Carla per differenza 39452 − 13153 − 13149 = 13150 (€ 131,50); somma 39452.
 *
 * Presidia (rossi oggi, tutti per la ragione del difetto): il riquadro «conguaglio da scegliere» al posto di «conguaglio proposto»
 * prima della scelta; la frase dell'arretrato con le due cifre, nel caso di riferimento, con le rate pagate e nel legato; le frasi del
 * calcolo per quota mostrate prima della scelta e con «Scrivi» (oggi il pannello ignora la chiave); «ad Anna» nelle tabelle delle coppie
 * e dell'arretrato agli eredi, «ad Àlex» per un nome con l'accento. Le tabelle si leggono cella per cella e si cerca la preposizione col
 * nome (`/\bad Anna\b/`): che la cella contenga altro, oltre a «ad Anna», il progetto non lo fissa.
 *
 * I controlli, verdi oggi, che devono restarlo: con «Non scriverlo» il riquadro «conguaglio non scritto», «Non scritte: nessuna riga in
 * saldi», la frase senza conguaglio (€ 400,00) e le frasi del calcolo per quota che NON si vedono; con «Scrivi» il riquadro «conguaglio
 * proposto» e la frase con il conguaglio (€ 394,52); le due voci senza nessuna già scelta e «Scegli se scrivere il conguaglio.»; con le
 * rate pagate e «Non scriverlo» nessun riquadro dell'arretrato (non c'è un credito); la vendita, che resta con «conguaglio proposto»
 * e con la casella «Le parti hanno regolato il conguaglio fra loro».
 *
 * Cosa NON copre: il server (le frasi del pannello, `frase_da_scegliere`, `frasi_del_conguaglio`: lo provano i test PHP della .48);
 * il modulo `PassaggioNew.vue` che incrocia il pannello con la conferma spenta; la quantità di cifre negative formattate dal
 * server («€ -4,38» nella riga per gestione: è del server); il bottone di registrazione.
 */

import { describe, expect, test } from 'vitest';
import { mount } from '@vue/test-utils';
import { defineComponent, h, ref } from 'vue';

import AnteprimaPassaggio from './AnteprimaPassaggio.vue';

type Scelta = 'scrivi' | 'non_scrivere' | null;

/** Le due cifre del cancello e del pannello prima della scelta: ciò che resta a nome di Ugo senza e con il conguaglio. */
const DA_SCEGLIERE = '€ 400,00 senza conguaglio, € 394,52 se scrivi il conguaglio';
const DA_SCEGLIERE_CREDITO = '€ 0,00 senza conguaglio, € 5,48 a credito se scrivi il conguaglio';

/** Le righe di saldo per persona del caso di riferimento: Anna (che riceve le bozze) a credito, Bruno e Carla a debito. */
function coppie(riferimento: string) {
    const base = { gestione_id: 1, gestione: 'Ordinaria 2026', immobile_id: 1, esercizio_id: 1, per_nudo: null };

    return [
        { ...base, importo: -53145, importo_formattato: '€ 531,45 a credito', anagrafica_entrante_id: 3, entrante_nome: riferimento },
        { ...base, importo: 26847, importo_formattato: '€ 268,47', anagrafica_entrante_id: 4, entrante_nome: 'Bruno' },
        { ...base, importo: 26846, importo_formattato: '€ 268,46', anagrafica_entrante_id: 5, entrante_nome: 'Carla' },
    ];
}

/** La riga per gestione: 120 giorni a Ugo, 245 agli eredi, le otto bozze (€ 800,00) già a chi entra; importo 80548 − 80000 = 548. */
const perGestione = {
    gestione_id: 1, gestione: 'Ordinaria 2026', immobile_id: 1, immobile_nome: 'Interno 1', natura: 'ordinaria', gradino: ['gestione'],
    periodo: [{ dal: '2026-01-01', al: '2026-12-31' }], voce_per_voce: false, quote: 12, quota_pura: 120000, pregressi: 0,
    giorni_uscente: 120, giorni_entrante: 245, giorni_periodo: 365, importo: 548, importo_formattato: '€ 5,48',
    importo_lordo: 80548, importo_lordo_formattato: '€ 805,48', bozze_passate: 8, bozze_passate_importo: 80000, bozze_passate_formattato: '€ 800,00',
    non_risolte: 0, non_separabili: 0, mai_passate: 0, escluse: 0, esercizio_id: 1,
};

const emesse = [1, 2, 3, 4].map((rata) => ({
    rata, scadenza: `2026-0${rata}-05`, importo: 10000, importo_formattato: '€ 100,00', intestatario: 'Venditore Ugo', piano: 'Preventivo 2026', natura: 'ordinaria',
}));

/**
 * L'anteprima del caso di Fresco, come la manda il server dopo la Fase 1-bis. `riferimento` è l'erede di riferimento (Anna).
 * `stato` sceglie la variante: `defunto` (arretrato a nome del defunto, la scelta si chiede), `pagate` (le quattro rate emesse sono
 * pagate: senza conguaglio non resta niente, con il conguaglio un credito di € 5,48), `eredi` (arretrato agli eredi: la scelta non c'è).
 */
function anteprimaFresco(stato: 'defunto' | 'pagate' | 'eredi' = 'defunto', riferimento = 'Anna'): any {
    const R = riferimento;
    const arretrato = {
        defunto: {
            scelta: 'defunto', legato: false, resta: 39452, resta_formattato: '€ 394,52', resta_senza_conguaglio: 40000, resta_senza_conguaglio_formattato: '€ 400,00',
            eredi: [], righe: [], totale: 0,
            frase: '€ 394,52 resta a nome di Venditore Ugo («eredi di Venditore Ugo»): ne rispondono gli eredi, ogni erede per la sua quota (art. 754 c.c.).',
            frase_senza_conguaglio: '€ 400,00 resta a nome di Venditore Ugo («eredi di Venditore Ugo»): ne rispondono gli eredi, ogni erede per la sua quota (art. 754 c.c.).',
            frase_da_scegliere: `L'arretrato di Venditore Ugo resta a suo nome («eredi di Venditore Ugo»): ${DA_SCEGLIERE}; ne rispondono gli eredi, ogni erede per la sua quota (art. 754 c.c.).`,
        },
        // Le quattro rate emesse pagate: senza conguaglio la posizione di Ugo è 40000 − 40000 = 0; con il conguaglio la sua coppia, 548, è un credito.
        pagate: {
            scelta: 'defunto', legato: false, resta: -548, resta_formattato: '€ -5,48', resta_senza_conguaglio: 0, resta_senza_conguaglio_formattato: '€ 0,00',
            eredi: [], righe: [], totale: 0,
            frase: '€ 5,48 a credito resta a nome di Venditore Ugo («eredi di Venditore Ugo»): è un credito dell\'eredità, che lo studio regola con gli eredi.',
            frase_senza_conguaglio: null,
            frase_da_scegliere: `La posizione di Venditore Ugo resta a suo nome («eredi di Venditore Ugo»): ${DA_SCEGLIERE_CREDITO}; il credito è dell'eredità, che lo studio regola con gli eredi.`,
        },
        // Agli eredi la quota dell'arretrato al netto del conguaglio (€ 394,52): 13153 + 13149 + 13150 = 39452.
        eredi: {
            scelta: 'eredi', legato: false, resta: 0, resta_formattato: '€ 0,00',
            eredi: [
                { anagrafica_id: 3, nome: R, quota: 33.34, importo: 13153, importo_formattato: '€ 131,53' },
                { anagrafica_id: 4, nome: 'Bruno', quota: 33.33, importo: 13149, importo_formattato: '€ 131,49' },
                { anagrafica_id: 5, nome: 'Carla', quota: 33.33, importo: 13150, importo_formattato: '€ 131,50' },
            ],
            righe: [{ gestione_id: 1, gestione: 'Ordinaria 2026', immobile_id: 1, esercizio_id: 1, per_erede: { 3: 13153, 4: 13149, 5: 13150 } }],
            totale: 39452,
            frase: `L'arretrato di Venditore Ugo al netto del conguaglio, € 394,52, passa agli eredi per quota (art. 754 c.c.): € 131,53 ${R.startsWith('A') ? 'ad' : 'a'} ${R}, € 131,49 a Bruno, € 131,50 a Carla. Con il conguaglio, ogni erede paga la sua quota di ciò che Venditore Ugo ha lasciato aperto, € 1.200,00, e la posizione di Venditore Ugo si chiude.`,
        },
    }[stato];

    const ad = R.startsWith('A') || R.startsWith('À') ? 'ad' : 'a';
    const apertura = stato === 'eredi'
        ? 'Il conguaglio fra Venditore Ugo e Anna (33,34 %), Bruno (33,33 %) e Carla (33,33 %) è proposto come righe di saldo che sommano a zero, sulla gestione di ciascun piano.'
        : 'Se scrivi il conguaglio fra Venditore Ugo e Anna (33,34 %), Bruno (33,33 %) e Carla (33,33 %), le righe di saldo sommano a zero, sulla gestione di ciascun piano: la quota ordinaria dal 1 maggio 2026 va agli eredi, divisa per quota.';

    return {
        nudi: null,
        riferimento: {
            uscente_fino_al: '2026-04-30', entrante_dal: '2026-05-01',
            frase: `Venditore Ugo risulterà titolare fino al 30 aprile 2026 compreso, il giorno prima del decesso. ${R} (33,34 %), Bruno (33,33 %) e Carla (33,33 %) dal 1 maggio 2026.`,
        },
        anagrafica: {
            frasi: [
                'Venditore Ugo risulterà titolare fino al 30 aprile 2026, il giorno prima del decesso.',
                `Dal 1 maggio 2026, come proprietario, ${R} (33,34 %), Bruno (33,33 %) e Carla (33,33 %): ogni erede per la sua quota, in comunione.`,
            ],
            pertinenze: [],
        },
        rate: {
            stato: 'calcolato',
            emesse,
            piani_distinti: 1,
            totale_emesso: 40000,
            totale_emesso_formattato: '€ 400,00',
            altre: { quote: 0, intestatari: [] },
            morosita: stato === 'pagate' ? null : { importo: 40000, importo_formattato: '€ 400,00', intestatario: 'Venditore Ugo' },
            conguaglio: {
                stato: 'calcolato', anagrafica_uscente_id: 1, anagrafica_entrante_id: 3, quote: [],
                per_gestione: [perGestione],
                coppie: coppie(R),
                totale_entrante: 548, totale_entrante_formattato: '€ 5,48', totale_entrante_assoluto_formattato: '€ 5,48',
                riassegnazione: [{ piano_rate_id: 1, piano: 'Preventivo 2026', n: 8, quote: 8, dal: '2026-05-05', al: '2026-12-05', preventivo: 80000, preventivo_formattato: '€ 800,00', pregresso: 0 }],
                pregressi: 0, non_risolte: [], frasi: [],
            },
            arretrato,
            frasi: [
                `Le rate già emesse non si toccano. ${apertura}`,
                `Le 8 rate in bozza del piano «Preventivo 2026» con scadenza dal 5 maggio 2026 al 5 dicembre 2026 passano ${ad} ${R}: cambia l'intestatario, non l'importo (€ 800,00 di preventivo, che da ora paga a suo nome).`,
                `Sulla gestione Ordinaria 2026: la parte degli eredi sull'intero piano è € 805,48; € 800,00 sono già nelle rate in bozza che passano ${ad} ${R}; la divisione per erede è più sotto — la quota ordinaria (€ 1.200,00 su 12 quote) è divisa in proporzione ai giorni di competenza: 120 a Venditore Ugo, 245 ${ad} ${R}, Bruno e Carla.`,
                stato === 'pagate'
                    ? 'Le 4 quote già emesse a Venditore Ugo (€ 400,00) sono tutte pagate: non c\'è niente da lasciare a suo nome.'
                    : 'Venditore Ugo ha € 400,00 scaduti e non pagati: restano a suo nome, e ne rispondono gli eredi.',
            ],
            // Il calcolo per quota vale solo se il conguaglio si scrive: 805,48 → 268,55 + 268,47 + 268,46.
            frasi_del_conguaglio: [
                `Sulla gestione Ordinaria 2026 la parte degli eredi, € 805,48 per i giorni dal decesso, si divide per quota: € 268,55 ${ad} ${R} (33,34 %), € 268,47 a Bruno (33,33 %), € 268,46 a Carla (33,33 %). Le rate in bozza (€ 800,00) vanno ${ad} ${R}, che le paga con quelle: la sua parte nel conguaglio scende di altrettanto.`,
            ],
        },
        obbligati: {
            frasi: [
                'Dei contributi maturati fino al 30 aprile 2026 rispondono gli eredi di Venditore Ugo, ogni erede in proporzione della sua quota ereditaria (art. 754 c.c.).',
                'Il programma lascia quelli non pagati a nome di Venditore Ugo («eredi di Venditore Ugo»): chi versa al suo posto si registra con «Versato da».',
            ],
            copia_autentica_mancante: false,
        },
        invarianti: { frasi: ['Millesimi: invariati.', 'Tabelle millesimali: invariate.'] },
        ordinaria: { applicabile: false, scelta: null, usufruttuario: null, nudo: null, voci: [], frasi: [], frasi_bloccate: [], frasi_altri_usufrutti: [], impronta: null, ereditata: null },
        cancello: {
            richiesto: true,
            motivi: ['4 quote di rate già emesse a Venditore Ugo su questa unità'],
            informazioni: [`l'arretrato di Venditore Ugo resta a suo nome («eredi di Venditore Ugo»): ${stato === 'pagate' ? DA_SCEGLIERE_CREDITO : DA_SCEGLIERE}`],
            avvisi: [],
        },
    };
}

/** Il legato: Leo è il solo legatario (nessun erede), una sola coppia, l'arretrato resta per forza a nome del defunto. */
function anteprimaLegato(): any {
    const dati = anteprimaFresco('defunto');
    dati.rate.conguaglio.coppie = [{ gestione_id: 1, gestione: 'Ordinaria 2026', immobile_id: 1, esercizio_id: 1, importo: 548, importo_formattato: '€ 5,48', per_nudo: null, anagrafica_entrante_id: 3, entrante_nome: 'Leo' }];
    dati.rate.arretrato = {
        ...dati.rate.arretrato, legato: true,
        frase: '€ 394,52 resta a nome di Venditore Ugo («eredi di Venditore Ugo»), perché chi riceve l\'unità per legato non eredita il patrimonio: ne rispondono gli eredi, ogni erede per la sua quota (art. 754 c.c.).',
        frase_senza_conguaglio: '€ 400,00 resta a nome di Venditore Ugo («eredi di Venditore Ugo»), perché chi riceve l\'unità per legato non eredita il patrimonio: ne rispondono gli eredi, ogni erede per la sua quota (art. 754 c.c.).',
        frase_da_scegliere: `L'arretrato di Venditore Ugo resta a suo nome («eredi di Venditore Ugo»), perché chi riceve l'unità per legato non eredita il patrimonio: ${DA_SCEGLIERE}; ne rispondono gli eredi.`,
    };
    dati.rate.frasi = [
        'Le rate già emesse non si toccano. Se scrivi il conguaglio fra Venditore Ugo e Leo, le righe di saldo sommano a zero, sulla gestione di ciascun piano: la quota ordinaria dal 1 maggio 2026 va a chi riceve l\'unità per legato, divisa per quota.',
        'Le 8 rate in bozza del piano «Preventivo 2026» con scadenza dal 5 maggio 2026 al 5 dicembre 2026 passano a Leo: cambia l\'intestatario, non l\'importo (€ 800,00 di preventivo, che da ora paga a suo nome).',
        'Venditore Ugo ha € 400,00 scaduti e non pagati: restano a suo nome, e ne rispondono gli eredi.',
    ];
    // Il legato ha un legatario solo: il calcolo è la coppia di Ugo e di Leo (80548 − 80000 = 548).
    dati.rate.frasi_del_conguaglio = ['Sulla gestione Ordinaria 2026: credito € 5,48 a Venditore Ugo, debito € 5,48 a Leo (la parte di Leo sull\'intero piano è € 805,48, di cui € 800,00 con le rate in bozza che passano a suo nome).'];

    return dati;
}

/** La vendita da Ugo a Elsa del 1° maggio: una sola coppia, nessun arretrato, la casella e la nota (decisione 73.3). */
function anteprimaVendita(): any {
    const dati = anteprimaFresco('defunto');
    dati.rate.arretrato = null;
    dati.rate.conguaglio.coppie = [{ gestione_id: 1, gestione: 'Ordinaria 2026', immobile_id: 1, esercizio_id: 1, importo: 548, importo_formattato: '€ 5,48', per_nudo: null, anagrafica_entrante_id: 3, entrante_nome: 'Acquirente Elsa' }];
    dati.rate.frasi = [
        'Le rate già emesse non si toccano. Il conguaglio fra Venditore Ugo e Acquirente Elsa è proposto come due righe di saldo che sommano a zero, sulla gestione di ciascun piano: la quota ordinaria è divisa in proporzione ai giorni.',
        'Venditore Ugo ha € 400,00 scaduti e non pagati. Restano suoi: il conguaglio si calcola sulla competenza, non sui pagamenti.',
    ];
    delete dati.rate.frasi_del_conguaglio;

    return dati;
}

/** Il pannello montato con la scelta data (il modello `sceltaConguaglio` è del modulo che ci sta sopra). */
function monta(dati: any, scelta: Scelta = null, tipo = 'successione') {
    return mount(AnteprimaPassaggio, { props: { dati, inCorso: false, errore: false, mancante: [], tipo, sceltaConguaglio: scelta } });
}

/** Un genitore che tiene la scelta come il modulo: le voci del pannello la cambiano davvero e il pannello si ridisegna. */
function montaInterattivo(dati: any, tipo = 'successione') {
    const scelta = ref<Scelta>(null);
    const Genitore = defineComponent({
        setup: () => () => h(AnteprimaPassaggio, {
            dati, inCorso: false, errore: false, mancante: [], tipo,
            sceltaConguaglio: scelta.value, 'onUpdate:sceltaConguaglio': (v: Scelta) => { scelta.value = v; },
        }),
    });

    return { w: mount(Genitore), scelta };
}

/** Il testo di ogni cella delle tabelle del pannello, una per una: un nome in una cella non è un pezzo di una frase del server. */
const celle = (w: ReturnType<typeof monta>) => w.findAll('td').map((td) => td.text());

/** Le celle che nominano `nome` con la preposizione data («a» o «ad»), senza fissare il resto della cella. */
const celleCon = (c: string[], preposizione: 'a' | 'ad', nome: string) => c.filter((x) => new RegExp(`\\b${preposizione} ${nome}`, 'u').test(x));

// Le stringhe che distinguono le frasi del server: la prima è solo del calcolo per quota, la seconda solo della sua conseguenza per Anna.
const PER_QUOTA = 'si divide per quota: € 268,55 ad Anna (33,34 %), € 268,47 a Bruno (33,33 %), € 268,46 a Carla (33,33 %)';
const BOZZE_AD_ANNA = 'Le rate in bozza (€ 800,00) vanno ad Anna, che le paga con quelle';
const CON_CONGUAGLIO = '€ 394,52 resta a nome di Venditore Ugo';
const SENZA_CONGUAGLIO = '€ 400,00 resta a nome di Venditore Ugo';

describe('AnteprimaPassaggio — la successione prima della scelta sul conguaglio (beta.48, R1 della Fase 1-bis)', () => {
    test('il riquadro del blocco «Rate già emesse» dice «conguaglio da scegliere»: nessuna voce è già scelta', () => {
        expect(monta(anteprimaFresco()).text()).toContain('conguaglio da scegliere');
    });

    test('il riquadro non dice «conguaglio proposto»: è la proposta di partenza che la decisione 72 toglie', () => {
        expect(monta(anteprimaFresco()).text()).not.toContain('conguaglio proposto');
    });

    test('sotto la scelta dell\'arretrato ci sono le due cifre: € 400,00 senza conguaglio, € 394,52 se scrivi il conguaglio', () => {
        expect(monta(anteprimaFresco()).text()).toContain(DA_SCEGLIERE);
    });

    test('non dice «€ 394,52 resta a nome di Venditore Ugo»: è la cifra di «Scrivi», e senza conguaglio la cifra è € 400,00', () => {
        expect(monta(anteprimaFresco()).text()).not.toContain(CON_CONGUAGLIO);
    });

    test('il calcolo per quota si vede: serve a scegliere («si divide per quota: € 268,55 ad Anna…»; «Le rate in bozza vanno ad Anna»)', () => {
        const testo = monta(anteprimaFresco()).text();
        expect(testo).toContain(PER_QUOTA);
        expect(testo).toContain(BOZZE_AD_ANNA);
    });

    // CONTROLLO (verde oggi): le due voci ci sono e nessuna è scelta; la conferma resta ferma finché la scelta manca.
    test('controllo: le due voci non hanno nessuna scelta già fatta, e il pannello dice «Scegli se scrivere il conguaglio.»', () => {
        const w = monta(anteprimaFresco());
        const radio = w.findAll('input[type="radio"]');
        expect(radio.map((r) => (r.element as HTMLInputElement).value)).toEqual(['scrivi', 'non_scrivere']);
        expect(radio.map((r) => (r.element as HTMLInputElement).checked)).toEqual([false, false]);
        expect(w.text()).toContain('Scrivi il conguaglio');
        expect(w.text()).toContain('Non scriverlo: la posizione resta com\'è');
        expect(w.text()).toContain('Scegli se scrivere il conguaglio.');
    });

    // CONTROLLO (verde oggi): né «regolato fra le parti» (la casella di prima) né «non scritto» (una scelta che non c'è ancora).
    test('controllo: non dice «regolato fra le parti» né «conguaglio non scritto»', () => {
        const testo = monta(anteprimaFresco()).text();
        expect(testo).not.toContain('regolato fra le parti');
        expect(testo).not.toContain('conguaglio non scritto');
    });

    // CONTROLLO (verde oggi): il piede della tabella descrive le coppie calcolate (€ 5,48 = −531,45 + 268,47 + 268,46), non un esito.
    test('controllo: il piede della tabella resta «Somma delle coppie degli eredi» con € 5,48', () => {
        const testo = monta(anteprimaFresco()).text();
        expect(testo).toContain('Somma delle coppie degli eredi');
        expect(testo).toContain('€ 5,48');
    });
});

describe('AnteprimaPassaggio — la successione con le rate emesse pagate (un credito che esiste solo con «Scrivi», R1)', () => {
    test('prima della scelta il pannello dice le due cifre, € 0,00 senza conguaglio e € 5,48 a credito se scrivi il conguaglio', () => {
        expect(monta(anteprimaFresco('pagate')).text()).toContain(DA_SCEGLIERE_CREDITO);
    });

    test('prima della scelta non afferma il credito dell\'eredità come se fosse già deciso: «€ 5,48 a credito resta a nome di Venditore Ugo»', () => {
        expect(monta(anteprimaFresco('pagate')).text()).not.toContain('€ 5,48 a credito resta a nome di Venditore Ugo');
    });

    // CONTROLLO (verde oggi): con «Non scriverlo» il credito non esiste e il riquadro dell'arretrato sparisce (la frase senza conguaglio è nulla).
    test('controllo: con «Non scriverlo» non c\'è nessun riquadro dell\'arretrato, perché non c\'è nessun credito', () => {
        const testo = monta(anteprimaFresco('pagate'), 'non_scrivere').text();
        expect(testo).not.toContain('Arretrato del defunto');
        expect(testo).not.toContain('€ 5,48 a credito resta a nome di Venditore Ugo');
    });

    // CONTROLLO (verde oggi): con «Scrivi» il credito dell'eredità si dice.
    test('controllo: con «Scrivi» il credito dell\'eredità si dice: «€ 5,48 a credito resta a nome di Venditore Ugo»', () => {
        expect(monta(anteprimaFresco('pagate'), 'scrivi').text()).toContain('€ 5,48 a credito resta a nome di Venditore Ugo');
    });
});

describe('AnteprimaPassaggio — la successione con «Scrivi il conguaglio» (beta.48, R2 della Fase 1-bis)', () => {
    test('il calcolo per quota si vede: € 268,55 ad Anna, € 268,47 a Bruno, € 268,46 a Carla, e le bozze ad Anna', () => {
        const testo = monta(anteprimaFresco(), 'scrivi').text();
        expect(testo).toContain(PER_QUOTA);
        expect(testo).toContain(BOZZE_AD_ANNA);
    });

    // CONTROLLO (verde oggi): lo stato di «Scrivi» è quello di sempre, la proposta realizzata.
    test('controllo: il riquadro dice «conguaglio proposto», e non «conguaglio da scegliere» né «conguaglio non scritto»', () => {
        const testo = monta(anteprimaFresco(), 'scrivi').text();
        expect(testo).toContain('conguaglio proposto');
        expect(testo).not.toContain('conguaglio da scegliere');
        expect(testo).not.toContain('conguaglio non scritto');
    });

    // CONTROLLO (verde oggi): con «Scrivi» a nome di Ugo resta € 394,52, la frase con il conguaglio, e non le due cifre.
    test('controllo: la frase dell\'arretrato è quella con il conguaglio, € 394,52, e non quella con le due cifre', () => {
        const testo = monta(anteprimaFresco(), 'scrivi').text();
        expect(testo).toContain(CON_CONGUAGLIO);
        expect(testo).not.toContain(DA_SCEGLIERE);
    });

    // CONTROLLO (verde oggi): la voce scelta è «Scrivi», e non c'è più il richiamo a scegliere.
    test('controllo: la voce «Scrivi il conguaglio» è quella scelta e non c\'è più «Scegli se scrivere il conguaglio.»', () => {
        const w = monta(anteprimaFresco(), 'scrivi');
        expect(w.findAll('input[type="radio"]').map((r) => (r.element as HTMLInputElement).checked)).toEqual([true, false]);
        expect(w.text()).not.toContain('Scegli se scrivere il conguaglio.');
    });
});

describe('AnteprimaPassaggio — la successione con «Non scriverlo» (beta.48, R2 della Fase 1-bis)', () => {
    // CONTROLLO (verde oggi): con «Non scriverlo» Bruno e Carla hanno € 0,00 e Anna € 800,00 su questo piano: le cifre per quota sono false, e non si vedono.
    test('controllo: il calcolo per quota non si vede (€ 268,47 a Bruno non è vero: Bruno non ha niente da pagare)', () => {
        const testo = monta(anteprimaFresco(), 'non_scrivere').text();
        expect(testo).not.toContain(PER_QUOTA);
        expect(testo).not.toContain(BOZZE_AD_ANNA);
    });

    // CONTROLLO (verde oggi): il riquadro e il piede dicono che non si scrive; la tabella delle coppie resta, senza la somma.
    test('controllo: il riquadro dice «conguaglio non scritto» e il piede «Non scritte: nessuna riga in saldi», non «regolato fra le parti»', () => {
        const testo = monta(anteprimaFresco(), 'non_scrivere').text();
        expect(testo).toContain('conguaglio non scritto');
        expect(testo).toContain('Non scritte: nessuna riga in saldi');
        expect(testo).not.toContain('regolato fra le parti');
        expect(testo).not.toContain('conguaglio proposto');
        expect(testo).not.toContain('conguaglio da scegliere');
    });

    // CONTROLLO (verde oggi): a nome di Ugo restano € 400,00, e Anna paga le bozze da sola.
    test('controllo: la frase dell\'arretrato è quella senza conguaglio, € 400,00, e non le due cifre', () => {
        const testo = monta(anteprimaFresco(), 'non_scrivere').text();
        expect(testo).toContain(SENZA_CONGUAGLIO);
        expect(testo).not.toContain(CON_CONGUAGLIO);
        expect(testo).not.toContain(DA_SCEGLIERE);
    });

    test('controllo: le rate in bozza passano all\'erede di riferimento, che le paga tutte, e la nota è facoltativa', () => {
        const w = monta(anteprimaFresco(), 'non_scrivere');
        expect(w.text()).toContain('senza righe in saldi, su questo piano gli altri eredi non hanno niente da pagare');
        expect(w.findAll('input').some((i) => (i.attributes('placeholder') ?? '').startsWith('Nota (facoltativa)'))).toBe(true);
        expect(w.text()).not.toContain('Scegli se scrivere il conguaglio.');
    });
});

describe('AnteprimaPassaggio — le voci cambiano il pannello (beta.48, R1 e R2 della Fase 1-bis)', () => {
    test('dal «da scegliere» a «Non scriverlo» e a «Scrivi»: il riquadro e le frasi seguono la voce scelta', async () => {
        const { w, scelta } = montaInterattivo(anteprimaFresco());
        const [scrivi, nonScrivere] = w.findAll('input[type="radio"]');

        // Prima: nessuna voce scelta.
        expect(w.text()).toContain('conguaglio da scegliere');
        expect(w.text()).toContain(PER_QUOTA);

        // «Non scriverlo»: il riquadro cambia, le cifre per quota spariscono, l'arretrato è € 400,00.
        await nonScrivere.setValue(true);
        expect(scelta.value).toBe('non_scrivere');
        expect(w.text()).toContain('conguaglio non scritto');
        expect(w.text()).toContain(SENZA_CONGUAGLIO);
        expect(w.text()).not.toContain('conguaglio da scegliere');
        expect(w.text()).not.toContain(PER_QUOTA);

        // «Scrivi»: il riquadro è «proposto», le cifre per quota tornano, l'arretrato è € 394,52.
        await scrivi.setValue(true);
        expect(scelta.value).toBe('scrivi');
        expect(w.text()).toContain('conguaglio proposto');
        expect(w.text()).toContain(CON_CONGUAGLIO);
        expect(w.text()).toContain(PER_QUOTA);
        expect(w.text()).not.toContain('conguaglio non scritto');
    });
});

describe('AnteprimaPassaggio — il legato (beta.48, R1 e R2 della Fase 1-bis)', () => {
    const LEGATO_CALCOLO = 'credito € 5,48 a Venditore Ugo, debito € 5,48 a Leo';

    test('prima della scelta il riquadro dice «conguaglio da scegliere» e l\'arretrato ha le due cifre', () => {
        const testo = monta(anteprimaLegato()).text();
        expect(testo).toContain('conguaglio da scegliere');
        expect(testo).toContain(DA_SCEGLIERE);
    });

    test('prima della scelta il calcolo di Ugo e di Leo si vede: serve a scegliere', () => {
        expect(monta(anteprimaLegato()).text()).toContain(LEGATO_CALCOLO);
    });

    test('con «Scrivi» il calcolo di Ugo e di Leo si vede', () => {
        expect(monta(anteprimaLegato(), 'scrivi').text()).toContain(LEGATO_CALCOLO);
    });

    // CONTROLLO (verde oggi): con «Non scriverlo» il credito e il debito di € 5,48 non si scrivono, e non si vedono come fatti.
    test('controllo: con «Non scriverlo» il calcolo di Ugo e di Leo non si vede', () => {
        expect(monta(anteprimaLegato(), 'non_scrivere').text()).not.toContain(LEGATO_CALCOLO);
    });

    // CONTROLLO (verde oggi): il legato con «Non scriverlo» ha il suo stato e la frase senza conguaglio.
    test('controllo: con «Non scriverlo» il riquadro dice «conguaglio non scritto» e a nome di Ugo restano € 400,00', () => {
        const testo = monta(anteprimaLegato(), 'non_scrivere').text();
        expect(testo).toContain('conguaglio non scritto');
        expect(testo).toContain(SENZA_CONGUAGLIO);
    });
});

describe('AnteprimaPassaggio — «ad» davanti ai nomi nelle tabelle (beta.48, R5 della Fase 1-bis)', () => {
    test('la tabella delle coppie nomina «ad Anna», «a Bruno» e «a Carla»', () => {
        const c = celle(monta(anteprimaFresco()));
        expect(celleCon(c, 'ad', 'Anna')).toHaveLength(1);
        expect(celleCon(c, 'a', 'Bruno')).toHaveLength(1);
        expect(celleCon(c, 'a', 'Carla')).toHaveLength(1);
    });

    test('la tabella delle coppie non nomina «a Anna»', () => {
        expect(celleCon(celle(monta(anteprimaFresco())), 'a', 'Anna')).toEqual([]);
    });

    test('con l\'arretrato agli eredi anche la tabella dell\'arretrato dice «ad Anna»: due celle, quella delle coppie e quella dell\'arretrato', () => {
        const c = celle(monta(anteprimaFresco('eredi')));
        expect(celleCon(c, 'ad', 'Anna')).toHaveLength(2);
        expect(celleCon(c, 'a', 'Anna')).toEqual([]);
    });

    // CONTROLLO (verde oggi): le persone che non cominciano per A restano con «a».
    test('controllo: con l\'arretrato agli eredi Bruno e Carla restano «a Bruno» e «a Carla» nelle due tabelle', () => {
        const c = celle(monta(anteprimaFresco('eredi')));
        expect(celleCon(c, 'a', 'Bruno')).toHaveLength(2);
        expect(celleCon(c, 'a', 'Carla')).toHaveLength(2);
    });

    test('un nome con la A accentata, «Àlex», è «ad Àlex»', () => {
        const c = celle(monta(anteprimaFresco('defunto', 'Àlex')));
        expect(celleCon(c, 'ad', 'Àlex')).toHaveLength(1);
        expect(celleCon(c, 'a', 'Àlex')).toEqual([]);
    });

    // CONTROLLO (verde oggi): le cifre delle tabelle sono quelle del server, il segno compreso.
    test('controllo: le cifre delle coppie sono quelle del server: «€ 531,45 a credito» per Anna, € 268,47 e € 268,46 per gli altri', () => {
        const c = celle(monta(anteprimaFresco()));
        expect(c).toContain('€ 531,45 a credito');
        expect(c).toContain('€ 268,47');
        expect(c).toContain('€ 268,46');
    });
});

describe('AnteprimaPassaggio — la vendita resta com\'è (beta.48, decisione 73.3)', () => {
    // CONTROLLO (verde oggi): la vendita ha la casella e la nota obbligatoria, non la scelta; il riquadro resta «proposto».
    test('controllo: il riquadro dice «conguaglio proposto» e non «conguaglio da scegliere»', () => {
        const testo = monta(anteprimaVendita(), null, 'vendita').text();
        expect(testo).toContain('conguaglio proposto');
        expect(testo).not.toContain('conguaglio da scegliere');
    });

    test('controllo: c\'è la casella «Le parti hanno regolato il conguaglio fra loro», non le due voci della successione', () => {
        const w = monta(anteprimaVendita(), null, 'vendita');
        expect(w.text()).toContain('Le parti hanno regolato il conguaglio fra loro');
        expect(w.text()).not.toContain('Scrivi il conguaglio');
        expect(w.text()).not.toContain('Non scriverlo');
        expect(w.findAll('input[type="radio"]')).toHaveLength(0);
    });

    test('controllo: la frase «è proposto come due righe di saldo» resta nella vendita', () => {
        expect(monta(anteprimaVendita(), null, 'vendita').text()).toContain('è proposto come due righe di saldo');
    });
});

/**
 * Giro stretto sulle correzioni della Fase 1-bis (beta.48), rilievi bassi della lente testi:
 * - AMM-1: `aNome` valeva per ogni tipo, e nella vendita il piede diceva «Emesso ad Aldo Venditore» accanto alle frasi del server con
 *   «a Aldo Venditore»; il verbale dice «ad» nella successione, la vendita resta com'è.
 * - AMM-3: nel legato a due le voci della scelta dicevano «una coppia per ogni erede», «all'erede di riferimento», «gli altri eredi».
 * - AMM-5: «Non scriverlo» diceva «quelle in bozza passano a chi entra», falso dove una parte delle bozze resta al defunto (il decesso al
 *   1° settembre: le bozze 5–8 scadono prima e restano a Ugo); le bozze che passano sono quelle dell'elenco del pannello.
 */
describe('AnteprimaPassaggio — giro stretto sulle correzioni (beta.48)', () => {
    /** Il legato a Leo e Ada al 50 %, Ada di riferimento: due coppie, l'arretrato a nome del defunto. */
    function anteprimaLegatoADue(): any {
        const dati = anteprimaLegato();
        const base = { gestione_id: 1, gestione: 'Ordinaria 2026', immobile_id: 1, esercizio_id: 1, per_nudo: null };
        // 80548 per metà = 40274: Ada (riferimento, le otto bozze) 40274 − 80000 = −39726; Leo 40274.
        dati.rate.conguaglio.coppie = [
            { ...base, importo: 40274, importo_formattato: '€ 402,74', anagrafica_entrante_id: 3, entrante_nome: 'Leo' },
            { ...base, importo: -39726, importo_formattato: '€ 397,26 a credito', anagrafica_entrante_id: 4, entrante_nome: 'Ada' },
        ];

        return dati;
    }

    test('AMM-1: nella vendita il piede della tabella resta «Emesso a Aldo Venditore», come le frasi del server', () => {
        const dati = anteprimaVendita();
        dati.rate.emesse = dati.rate.emesse.map((r: any) => ({ ...r, intestatario: 'Aldo Venditore' }));
        const testo = monta(dati, null, 'vendita').text();
        expect(testo).toContain('Emesso a Aldo Venditore');
        expect(testo).not.toContain('Emesso ad Aldo');
    });

    test('AMM-1: nella successione il piede dice «Emesso ad Aldo Venditore»', () => {
        const dati = anteprimaFresco();
        dati.rate.emesse = dati.rate.emesse.map((r: any) => ({ ...r, intestatario: 'Aldo Venditore' }));
        expect(monta(dati).text()).toContain('Emesso ad Aldo Venditore');
    });

    test('AMM-1: fuori dalla successione la tabella delle coppie resta con «a» (l\'estinzione dell\'usufrutto con due nudi)', () => {
        const dati = anteprimaVendita();
        const base = { gestione_id: 1, gestione: 'Ordinaria 2026', immobile_id: 1, esercizio_id: 1, per_nudo: null };
        dati.rate.conguaglio.coppie = [
            { ...base, importo: 274, importo_formattato: '€ 2,74', anagrafica_entrante_id: 3, entrante_nome: 'Alba Nuda' },
            { ...base, importo: 274, importo_formattato: '€ 2,74', anagrafica_entrante_id: 4, entrante_nome: 'Bruno Nudo' },
        ];
        const c = celle(monta(dati, null, 'usufrutto'));
        expect(celleCon(c, 'a', 'Alba Nuda')).toHaveLength(1);
        expect(celleCon(c, 'ad', 'Alba Nuda')).toEqual([]);
    });

    test('AMM-3: nel legato a due le voci della scelta non chiamano «eredi» chi riceve l\'unità', () => {
        const prima = monta(anteprimaLegatoADue()).text();
        const nonScritto = monta(anteprimaLegatoADue(), 'non_scrivere').text();
        expect(prima).toContain('una coppia per ognuno');
        expect(prima).not.toContain('una coppia per ogni erede');
        expect(nonScritto).not.toContain('erede di riferimento');
        expect(nonScritto).not.toContain('gli altri eredi');
        expect(nonScritto).toContain('a quello di riferimento');
    });

    // CONTROLLO: nella successione le stesse voci parlano di eredi.
    test('AMM-3, controllo: nella successione le voci dicono ancora «una coppia per ogni erede» e «l\'erede di riferimento»', () => {
        expect(monta(anteprimaFresco()).text()).toContain('una coppia per ogni erede');
        expect(monta(anteprimaFresco(), 'non_scrivere').text()).toContain('erede di riferimento');
    });

    test('AMM-5: «Non scriverlo» dice che passano le bozze dell\'elenco, non tutte le bozze', () => {
        const testo = monta(anteprimaFresco(), 'non_scrivere').text();
        expect(testo).toContain('le rate in bozza dell\'elenco qui sopra passano');
        expect(testo).not.toContain('quelle in bozza passano');
    });
});

/**
 * Verifica a video della .48 (10/10/2026): nella colonna stretta del pannello «€ 531,45 a credito» non ci stava (la cella è larga 91
 * pixel, il testo ne chiede 104) e si leggeva «€ 531,45 a cred»; «a credito» va a capo, sotto la cifra, in un elemento suo. E nel legato
 * il piede della tabella diceva «Somma delle coppie degli eredi».
 */
describe('AnteprimaPassaggio — verifica a video (beta.48)', () => {
    /** La cella della tabella che contiene la cifra data. */
    const cella = (w: ReturnType<typeof monta>, cifra: string) => w.findAll('td').find((td) => td.text().includes(cifra));

    test('la coppia a credito mette «a credito» su una riga sua, sotto la cifra, e la cella dice ancora «€ 531,45 a credito»', () => {
        const td = cella(monta(anteprimaFresco()), '€ 531,45');
        expect(td?.text()).toBe('€ 531,45 a credito');
        expect(td?.find('span.block').exists()).toBe(true);
        expect(td?.find('span.block').text()).toBe('a credito');
    });

    test('una coppia a debito resta una riga sola, senza l\'elemento «a credito»', () => {
        const td = cella(monta(anteprimaFresco()), '€ 268,47');
        expect(td?.text()).toBe('€ 268,47');
        expect(td?.find('span.block').exists()).toBe(false);
    });

    test('nel legato il piede della tabella dice «Somma delle coppie», senza «degli eredi»', () => {
        const dati = anteprimaLegato();
        const base = { gestione_id: 1, gestione: 'Ordinaria 2026', immobile_id: 1, esercizio_id: 1, per_nudo: null };
        dati.rate.conguaglio.coppie = [
            { ...base, importo: 40274, importo_formattato: '€ 402,74', anagrafica_entrante_id: 3, entrante_nome: 'Leo' },
            { ...base, importo: -39726, importo_formattato: '€ 397,26 a credito', anagrafica_entrante_id: 4, entrante_nome: 'Ada' },
        ];
        const testo = monta(dati).text();
        expect(testo).toContain('Somma delle coppie');
        expect(testo).not.toContain('Somma delle coppie degli eredi');
    });
});
