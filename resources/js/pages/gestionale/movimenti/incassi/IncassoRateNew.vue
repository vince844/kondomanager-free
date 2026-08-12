<script setup lang="ts">
import { ref, watch, computed, onMounted, nextTick } from 'vue';
import { useForm, Head } from '@inertiajs/vue3';
import GestionaleLayout from '@/layouts/GestionaleLayout.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Badge } from '@/components/ui/badge';
import MoneyInput from '@/components/MoneyInput.vue';
import InputError from '@/components/InputError.vue';
import { Tooltip, TooltipContent, TooltipProvider, TooltipTrigger } from '@/components/ui/tooltip';
import { AlertCircle, BookOpen, CheckCircle2, RotateCcw,  User, Building, ArrowRight, FileText, Receipt, ArrowRightLeft, Info, Lock, Wallet } from 'lucide-vue-next';
import { useCurrencyFormatter } from '@/composables/useCurrencyFormatter';
import { usePermission } from "@/composables/permissions";
import { usePaymentDistribution } from '@/composables/usePaymentDistribution';
import PageHeaderGuide from '@/components/PageHeaderGuide.vue';
import IncassoRateGuide from '@/components/guides/IncassoRateGuide.vue';
import { useDebitiLoader } from '@/composables/useDebitiLoader';
import vSelect from 'vue-select';
import 'vue-select/dist/vue-select.css';
import type { Rata } from '@/types/gestionale/rata';
import type { Breadcrumb } from '@/components/PageHeaderGuide.vue';

const props = defineProps<{
    condominio: any;
    risorse: any[];
    condomini: any[];
    immobili: any[];
    gestioni: any[];
}>();

const { euro } = useCurrencyFormatter({ fromCents: false });
const { generateRoute } = usePermission();

const breadcrumbs = computed<Breadcrumb[]>(() => [
    { title: 'Dashboard', href: route('admin.gestionale.index', { condominio: props.condominio.id }) },
    { title: 'Incassi', href: route(generateRoute('gestionale.movimenti-rate.index'), { condominio: props.condominio.id }) },
    { title: 'Nuovo incasso' },
]);

const mostraGuida = ref(false);

/**
 * Le tre schede in testata, riscritte corte nella beta.49.
 *
 * Prima erano il blocco più lungo di tutto il gestionale — 624 caratteri contro una mediana di
 * 93 — e da sole occupavano **240 px sempre aperti** sopra la griglia: più della card che elenca
 * le rate, che ne aveva 286. Su un portatile si vedevano due rate.
 *
 * Ora sono 308 caratteri in tutto, allineati a `FatturaRegisterNew` (290), e la banda scende a
 * ~134 px. Il testo che è stato tolto non è andato perso: sta in `IncassoRateGuide`, che si apre
 * dal pulsante «Guida completa» e non costa un pixel.
 *
 * **La divisione del lavoro fra i due**, che è la regola per aggiornarli: la scheda dice *dove
 * sta una cosa* e *una sola sorpresa*, in una riga; la guida dice il perché e le conseguenze. Se
 * una scheda cresce oltre le due righe, quel testo appartiene alla guida.
 */
const pageGuides = [
    {
        title: 'Persona o immobile',
        description: 'Per immobile la riga somma tutti gli intestatari: il pagante lo scegli in «Intestatario della ricevuta».',
        icon: User,
        colorVariant: 'blue' as const
    },
    {
        title: 'Automatico o manuale',
        description: 'In automatico l\'importo parte dalla rata più vecchia; in manuale «Importo versato» segue le righe.',
        icon: ArrowRightLeft,
        colorVariant: 'amber' as const
    },
    {
        title: 'Il credito si applica a mano',
        description: 'Nessun credito viene usato finché non premi «Usa credito» sulla riga che lo porta. Il resto è nella guida.',
        icon: Wallet,
        colorVariant: 'emerald' as const
    },
];

const moneyOptions = ref({
    prefix: '€ ',
    suffix: '',
    thousands: '.',
    decimal: ',',
    precision: 2,
    allowBlank: false,
    masked: true
});

const {
    rawRateList,
    loadingRate,
    mode,
    isScaduta,
    priorityRataId,
    setPriorityRataId,
    getRateListByGestione,
    getTotaleDebito,
    getBilancioFinale,
    distributeGreedy,
    calculateExcess,
    creditoNecessario,
    onManualChange,
    resetAllocation: resetAllocationComposable,
    pagaTutto: pagaTuttoComposable,
    pagaScadute: pagaScaduteComposable
} = usePaymentDistribution();

const { fetchDebiti: fetchDebitiAPI } = useDebitiLoader();

const searchMode = ref<'persona' | 'immobile'>('persona');
const selectedImmobileId = ref<number | null>(null);

const form = useForm({
    pagante_id: null as number | null,
    cassa_id: null as number | null,
    gestione_id: null as number | null,
    data_pagamento: new Date().toISOString().substring(0, 10),
    importo_totale: '' as string | number,
    descrizione: '',
    dettaglio_pagamenti: [] as any[],
    eccedenza: 0,
    related_task_id: null as number | null,
});

const rateList = ref<Rata[]>([]);

const parseResiduoQuota = (value: string | number) => {
    if (typeof value === 'number') return value;
    if (!value) return 0;
    let str = String(value).replace('€', '').trim();
    if (str.includes(',')) {
        str = str.replace(/\./g, '');
        str = str.replace(',', '.');
    }
    return parseFloat(str.replace(/[^\d.-]/g, '')) || 0;
};

const hasSaldoMisto = (r: any) => {
    if (Math.abs(parseResiduoQuota(r.residuo)) < 0.01 && r.dettaglio_quote && r.dettaglio_quote.length > 1) {
        const hasDebito = r.dettaglio_quote.some((q: any) => parseResiduoQuota(q.residuo) > 0);
        const hasCredito = r.dettaglio_quote.some((q: any) => parseResiduoQuota(q.residuo) < 0);
        return hasDebito && hasCredito;
    }
    return false;
};

const isRataZero = (r: any) => {
    const desc = (r.descrizione || '').toLowerCase();
    return desc.includes('saldo') || desc.includes('rata 0') || desc.includes('pregresso');
};

/**
 * Il credito che una riga mette a disposizione **di chi sta incassando**, in euro.
 *
 * La regola è una sola e vale per ogni riga, mista o pura, e ricalca esattamente quella del
 * server (`CreditoService::compensabile`), applicata però alla sola fetta del pagante:
 *
 *   netto del pagante < 0  →  si può usare |netto|
 *   netto del pagante = 0  →  se dentro c'è del credito, si può usare quello (riga «mista»)
 *   netto del pagante > 0  →  niente: il credito interno è già assorbito dal suo debito
 *
 * **Perché si guarda sempre l'intestatario.** Cercando per unità immobiliare il server
 * aggrega le quote di tutti i comproprietari (`SituazioneDebitoriaController:39-45`): una riga
 * può portare il credito di una persona e il debito di un'altra. Offrire quel credito a chi
 * sta incassando porta, a seconda di quale quota il database ha inserito per prima, o a una
 * `RuntimeException` non catturata — pagina 500, distribuzione persa — o al credito di uno
 * speso sulla ricevuta di un altro senza che lo schermo lo dichiari.
 *
 * Prima questa guardia copriva le sole righe a saldo misto, e la riga a **credito puro** —
 * il percorso più comune — restava scoperta perché si usciva su `Math.abs(netto)` prima
 * ancora di guardare il pagante.
 *
 * Senza `dettaglio_quote` o senza pagante non si può attribuire niente, e allora non si
 * offre: sul denaro altrui il ripiego prudente è non fare, non indovinare. Il payload reale
 * porta sempre il dettaglio (`SituazioneDebitoriaController:235`).
 */
const creditoRiga = (r: any): number => {
    if (!form.pagante_id || !Array.isArray(r.dettaglio_quote) || r.dettaglio_quote.length === 0) {
        return 0;
    }

    const mie = r.dettaglio_quote.filter((q: any) => q.anagrafica_id === form.pagante_id);
    if (mie.length === 0) return 0;

    const nettoPagante = mie.reduce((s: number, q: any) => s + parseResiduoQuota(q.residuo), 0);

    if (nettoPagante < -0.001) return Math.abs(nettoPagante);

    if (Math.abs(nettoPagante) < 0.001) {
        return mie
            .filter((q: any) => parseResiduoQuota(q.residuo) < 0)
            .reduce((s: number, q: any) => s + Math.abs(parseResiduoQuota(q.residuo)), 0);
    }

    return 0;
};

const importoNumerico = computed(() => parseResiduoQuota(form.importo_totale));

const totalAllocato = computed(() => {
    return form.dettaglio_pagamenti.reduce((sum, p) => sum + p.importo, 0);
});

const totaleDebito = computed(() => getTotaleDebito(rateList.value));
const bilancioFinale = computed(() => getBilancioFinale(totaleDebito.value, importoNumerico.value));
const showOnlyOverdue = ref(false);
const intentUsaCredito = ref(false);

/** Acceso quando si clicca «Usa credito» ma il contante copre già tutto: un click che non fa
 *  niente va spiegato, o sembra che il programma si sia bloccato. */
const creditoSenzaScoperto = ref(false);

/** Le righe il cui credito l'amministratore ha esplicitamente tolto, per id. Vedi `toggleCredito`. */
const creditiRifiutati = ref(new Set<number>());

const hasSaldoPregressoInLista = computed(() => {
    // Stesso criterio del pulsante: la fascia verde «Situazione pregressa regolare» non deve
    // accendersi proprio dove c'è del credito da compensare.
    return rateList.value.some(r => isRataZero(r) || creditoRiga(r) > 0);
});

/**
 * C'è, fra i debiti caricati, almeno una riga da cui il credito si possa davvero prendere.
 * Attenzione: qui NON si può leggere `selezionata` — quella vive sui cloni prodotti da
 * `getRateListByGestione`, non su queste righe grezze. Vedi `creditoSelezionato`.
 * È la domanda a cui il banner della richiesta di compensazione deve rispondere prima di
 * dire all'amministratore cosa fare.
 *
 * Si legge `rawRateList` e non `rateList`: la seconda è **filtrata** per gestione e per
 * «mostra scadute». Bastava un filtro perché il banner dichiarasse «non risulta alcun credito
 * disponibile: la richiesta non può essere soddisfatta» mentre il credito c'era ed era solo
 * nascosto — e su quella frase una segnalazione legittima si chiude per errore.
 */
const creditoInLista = computed(() => rawRateList.value.some(r => creditoRiga(r) > 0));

/**
 * C'è del credito **effettivamente selezionato** in questo momento.
 *
 * Il banner deve leggere questo, non un flag che ricorda di aver fatto la selezione una
 * volta: la lista si ricostruisce a ogni cambio di gestione, filtro o modalità di ricerca —
 * `getRateListByGestione` clona le righe e `useDebitiLoader` le rimette tutte a
 * `selezionata: false` — e il credito resta in elenco mentre la selezione sparisce. Dedurre
 * l'azione dalla sola presenza del credito faceva dire al banner «è già stato selezionato»
 * davanti a zero importi da verificare.
 */
const creditoSelezionato = computed(() =>
    rateList.value.some(r => creditoRiga(r) > 0 && r.selezionata)
);

/**
 * Il credito è fra le righe che si vedono **adesso**. Diverso da `creditoInLista`, che guarda
 * tutto quello che è stato caricato: fra i due c'è il filtro per gestione e «mostra scadute».
 * Tenerli distinti serve a dire all'amministratore la cosa giusta — «cliccalo» quando la riga
 * c'è, «togli il filtro» quando esiste ma è nascosta, «non ne ha» solo quando è vero.
 */
const creditoVisibile = computed(() => rateList.value.some(r => creditoRiga(r) > 0));

/**
 * L'auto-selezione si fa **una volta sola per condòmino**, altrimenti riselezionerebbe da
 * sé una riga che l'amministratore ha appena deselezionato. Si azzera insieme alla
 * richiesta: vedi `dimenticaRichiestaCompensazione()`.
 */
const creditoAutoApplicato = ref(false);

/**
 * La richiesta di compensazione appartiene a **un** condòmino e a **una** schermata. Quando
 * si cambia persona, si cambia modo di ricerca o si è finito di registrare, quella richiesta
 * non descrive più ciò che si ha davanti — e un avviso che racconta la richiesta di Rossi
 * sopra la posizione contabile di Bianchi è peggio di nessun avviso.
 */
const dimenticaRichiestaCompensazione = () => {
    intentUsaCredito.value = false;
    creditoAutoApplicato.value = false;
};

// --- Spaccato e avviso cross-gestione ------------------------------------
// Il credito non è vincolato alla gestione in cui è nato: nulla nel backend
// lo impedisce (per scelta: potrebbe esserci un accordo condominiale che lo
// autorizza), ma l'amministratore deve poterlo vedere e confermarlo
// esplicitamente prima di procedere.

const spaccatoCreditoDisponibile = computed(() => {
    const mappa = new Map<number, { nome: string; importo: number }>();
    rateList.value.forEach(r => {
        // `creditoRiga` e non il residuo netto: altrimenti la striscia del credito non
        // elenca quello delle righe a saldo misto, e tre righe più sotto c'è un pulsante che
        // offre di spenderlo.
        const disponibile = creditoRiga(r);
        if (disponibile <= 0 || r.gestione_id == null) return;
        const prev = mappa.get(r.gestione_id);
        mappa.set(r.gestione_id, {
            nome: r.gestione,
            importo: (prev?.importo ?? 0) + disponibile,
        });
    });
    return Array.from(mappa.values());
});

const spaccatoCreditoUsato = computed(() => {
    const mappa = new Map<number, { nome: string; importo: number }>();
    form.dettaglio_pagamenti.forEach(p => {
        if (p.importo >= 0) return;
        const r = rateList.value.find(rate => rate.id === p.rata_id);
        if (!r || r.gestione_id == null) return;
        const prev = mappa.get(r.gestione_id);
        mappa.set(r.gestione_id, {
            nome: r.gestione,
            importo: (prev?.importo ?? 0) + Math.abs(p.importo),
        });
    });
    return Array.from(mappa.entries()).map(([gestione_id, v]) => ({ gestione_id, ...v }));
});

const gestioniDebitoPagate = computed(() => {
    const set = new Set<number>();
    form.dettaglio_pagamenti.forEach(p => {
        if (p.importo <= 0) return;
        const r = rateList.value.find(rate => rate.id === p.rata_id);
        if (r && r.gestione_id != null) set.add(r.gestione_id);
    });
    return set;
});

const isCrossGestione = computed(() => {
    return spaccatoCreditoUsato.value.some(g => !gestioniDebitoPagate.value.has(g.gestione_id));
});

const crossGestioneConfermato = ref(false);
watch(isCrossGestione, (val) => { if (!val) crossGestioneConfermato.value = false; });

const previewContabile = computed(() => {
    const pagamenti = form.dettaglio_pagamenti;
    const importo = importoNumerico.value;
    const eccedenza = form.eccedenza;

    if (pagamenti.length === 0 && importo <= 0) {
        return { hasData: false, righe: [], anticipo: 0, allocato_rate: 0, totale_versato: 0 };
    }

    const righePagate = pagamenti.map(p => {
        const r = rateList.value.find(rate => rate.id === p.rata_id);
        if (!r) return null;

        // Il verso lo dice l'IMPORTO, non il residuo della riga: un importo negativo è un
        // prelievo di credito, sempre. Dedurlo dal residuo sbagliava proprio sulle righe a
        // saldo misto, dove il netto vale zero: si prendeva il ramo debito e l'ultima
        // schermata prima della conferma scriveva «Resta da pagare» su una rata che sarebbe
        // stata lasciata a saldo zero. È l'unico punto in cui l'amministratore verifica
        // prima di scrivere in partita doppia.
        const isCredito = p.importo < 0;
        const baseRata = isCredito ? -creditoRiga(r) : parseResiduoQuota(r.residuo);

        let residuoDopoPagamento = 0;
        let status = '';

        if (isCredito) {
            residuoDopoPagamento = baseRata - p.importo;
            status = (residuoDopoPagamento >= -0.01) ? 'ESAURITO' : 'CREDITO_USATO';
        } else {
            residuoDopoPagamento = Math.max(0, baseRata - p.importo);
            status = (residuoDopoPagamento <= 0.01) ? 'SALDATA' : 'PARZIALE';
        }

        return {
            id: r.id,
            descrizione: r.descrizione,
            pagato: p.importo,
            status: status,
            residuo_futuro: residuoDopoPagamento,
            isCredito: isCredito
        };
    }).filter(Boolean) as any[];

    return {
        hasData: righePagate.length > 0 || importo > 0,
        totale_versato: importo,
        allocato_rate: totalAllocato.value,
        anticipo: eccedenza,
        righe: righePagate
    };
});

/**
 * Numero di serie della ricerca in corso.
 *
 * Serve perché `svuotaRicerca()` da sola non basta: azzera `rawRateList`, ma una richiesta
 * partita un istante prima per la persona precedente **non è annullata**, e quando risponde
 * riscrive l'elenco appena svuotato. Senza questo contatore la correzione trasformerebbe un
 * difetto permanente — l'elenco che non si svuota mai — in uno **intermittente**, che è la
 * versione peggiore: dipende dalla latenza, non si riproduce a comando, e chi lo segnala non
 * viene creduto.
 */
let ricercaCorrente = 0;

const fetchDebiti = async (params: { anagrafica_id?: number | null; immobile_id?: number | null }) => {
    const mia = ++ricercaCorrente;
    loadingRate.value = true;
    try {
        const result = await fetchDebitiAPI(
            (name: string, params: any) => route(generateRoute(name), params),
            props.condominio.id,
            params,
            isScaduta
        );

        // Una ricerca più recente ha già vinto: questa risposta descrive una persona o un'unità
        // che non è più a schermo, e assegnarla farebbe ricomparire da sola la lista svuotata.
        if (mia !== ricercaCorrente) return;

        rawRateList.value = result as Rata[];
    } finally {
        if (mia === ricercaCorrente) loadingRate.value = false;
    }
};

/**
 * Riporta la ricerca allo stato «nessuno selezionato».
 *
 * Nasce nella beta.49 dalla segnalazione «se cancelli l'anagrafica con la x, la card di destra
 * non si resetta». La causa: l'unica riga che sa svuotare l'elenco è `fetchDebiti`, e i due
 * watcher che la chiamano hanno una guardia sul valore non nullo (`&& newVal`). Con la x il
 * watcher parte, azzera l'allocazione e **ridistribuisce l'importo ancora digitato sulle righe
 * dell'ex pagante** — ma non ricarica niente, e `rawRateList` resta quella di prima.
 *
 * I casi rotti erano quattro, non uno, e il segnalato non era il peggiore:
 *
 * - **x sull'unità immobiliare**: il watcher era un no-op assoluto. `pagante_id` resta
 *   valorizzato, quindi «Conferma incasso» **resta acceso** e si può inviare un payload con le
 *   rate di un'unità che non è più a schermo.
 * - **x sull'intestatario** (modalità immobile): l'elenco deve restare — è dell'unità, non della
 *   persona — ma `creditiRifiutati` sopravviveva al cambio di intestatario, contro quanto
 *   dichiara il commento di `toggleCredito`.
 * - **x sul filtro gestione**: la lista viene riclonata e l'allocazione a schermo torna a zero,
 *   ma `syncForm()` vive dentro `runDistribution()`, che parte solo con importo maggiore di zero.
 *   Su una compensazione a solo credito il payload conservava le righe della lista precedente.
 *
 * ⚠️ Non azzera `pagante_id` né `selectedImmobileId`: la scelta di quale dei due togliere resta a
 * chi chiama, ed è ciò che permette di svuotare l'intestatario **senza** perdere l'elenco
 * dell'unità. Restano fuori anche cassa, data, causale, `related_task_id`, il filtro gestione,
 * «Mostra scadute» e `mode`: descrivono il movimento o una preferenza dell'operatore, non la
 * persona, e perderli a ogni x sarebbe un fastidio quotidiano.
 */
const svuotaRicerca = () => {
    ricercaCorrente++;
    dimenticaRichiestaCompensazione();
    setPriorityRataId(null);
    creditoSenzaScoperto.value = false;
    creditiRifiutati.value.clear();
    rawRateList.value = [];

    // L'importo si azzera per **sicurezza**, non per pulizia. Lasciandolo, in modalità immobile
    // togliere l'unità lascerebbe `pagante_id` valorizzato e `previewContabile.hasData` vero
    // (basta importo maggiore di zero): «Conferma incasso» acceso sopra un elenco vuoto.
    // Il prezzo è che si perde anche `prefill_importo` arrivato dal link della segnalazione —
    // riselezionando la stessa persona l'elenco torna, l'importo no.
    form.importo_totale = '';
    form.dettaglio_pagamenti = [];
    form.eccedenza = 0;
};

/**
 * «Usa credito» su una riga.
 *
 * La distribuzione del credito vive in **un posto solo**, il ramo automatico di
 * `runDistribution` (`spalmaCredito`): quando si clicca in modalità manuale la pagina passa in
 * automatica e lascia fare a quello.
 *
 * Perché non si applica il credito a mano qui. Ci abbiamo provato, e quel ciclo ha prodotto
 * difetti per tre revisioni di fila — sempre nuovi, sempre nati dall'interazione con l'altra
 * metà della pagina: impegnava il credito senza pagare nessun debito (il motore lo prelevava e
 * lo riaccreditava, giro a vuoto); impegnava più del dovuto con due click; lasciava i debiti
 * coperti quando lo si toglieva; e faceva registrare come contante, alla prima modifica a mano,
 * denaro che in cassa non era mai entrato. Il difetto non era in nessuna di quelle righe: era
 * nel fatto che la stessa logica esistesse in due posti, di cui uno dentro un gestore di click,
 * in una modalità il cui modello è «i numeri li scrive l'amministratore».
 *
 * Il totale versato non si perde — è `form.importo_totale` e resta dov'è. Cambia la
 * ripartizione fra le righe, che l'automatico rifà per urgenza; e il passaggio si vede, perché
 * l'indicatore auto/manuale è a schermo e si può tornare indietro.
 */
const toggleCredito = (rata: Rata) => {
    const attivo = !rata.selezionata;

    // Se il contante copre già tutti i debiti, quel credito non ha niente da fare. Commutare
    // in automatica cancellerebbe la ripartizione decisa a mano — per esempio dopo «Paga
    // scadute» — senza applicare un centesimo: si dice, e non si tocca niente.
    if (attivo && mode.value === 'manual'
        && creditoNecessario(rateList.value, importoNumerico.value, creditoRiga(rata)) <= 0) {
        creditoSenzaScoperto.value = true;
        return;
    }

    rata.selezionata = attivo;

    // Il rifiuto vive FUORI dalle righe.
    //
    // `getRateListByGestione` riclona da `rawRateList` a ogni cambio di gestione o del filtro
    // «mostra scadute»: un flag scritto sul clone sparirebbe lì, riaprendo l'auto-inclusione e
    // riapplicando da sé un credito che l'amministratore aveva tolto. Scriverlo sulla riga
    // grezza è peggio: `rawRateList` è osservata in profondità, e mutarla fa ricostruire la
    // lista e perdere ogni selezione.
    //
    // Un insieme di id a parte non ha nessuno dei due problemi. Si svuota al cambio di pagante,
    // dove è giusto che si svuoti: il rifiuto di un condòmino non riguarda un altro.
    if (attivo) creditiRifiutati.value.delete(rata.id);
    else creditiRifiutati.value.add(rata.id);
    if (!attivo) rata.da_pagare = 0;

    if (mode.value === 'manual') mode.value = 'auto';

    runDistribution();
};

const runDistribution = async () => {
    // Qualunque ridistribuzione rimette in discussione «non c'è nulla da compensare»: il banner
    // descrive uno stato, non un evento, e lasciarlo acceso dopo che l'amministratore ha
    // abbassato l'importo — cioè dopo aver fatto quello che il banner stesso suggerisce —
    // significa contraddirlo.
    creditoSenzaScoperto.value = false;

    await nextTick();

    // 1. Troviamo la rata bersaglio
    const targetRataId = priorityRataId.value;
    const rataTarget = rateList.value.find(r =>
        // Solo `rata_padre_id`: `prefill_rata_id` è una chiave di `rate`, mentre `r.id` è
        // l'id di una `rate_quote`. Confrontarli entrambi faceva sì che, nei condomini dove
        // i due intervalli di id si sovrappongono, il credito venisse puntato su una rata
        // diversa da quella promessa dal widget.
        (r.rata_padre_id === targetRataId) &&
        parseResiduoQuota(r.residuo) > 0
    );

    // 2. Individuiamo le righe di credito attive: qualsiasi riga con residuo
    //    negativo (saldo iniziale a credito, anticipo o strapagamento emerso).
    const isInboxMode = priorityRataId.value !== null && rataTarget !== undefined;
    // L'auto-inclusione della modalità «arrivo da un link» si ferma alla gestione della rata
    // bersaglio. Il credito di un'altra gestione il motore lo sa spendere, ma pretende una
    // spunta esplicita dell'amministratore — ed è la ragione per cui il consiglio lato server
    // non lo propone mai. Includerlo qui da soli significherebbe far fare alla schermata
    // esattamente ciò che il servizio si era vietato di suggerire, lasciando fra il click e
    // lo spostamento solo una casella che nessuno sa di dover leggere.
    // Una riga scelta a mano resta sempre inclusa: lì la decisione l'ha presa una persona.
    const righeCredito = rateList.value.filter(r => {
        if (creditoRiga(r) <= 0) return false;
        if (r.selezionata) return true;

        // L'auto-inclusione serve a precompilare quando si arriva da un link, non a
        // sovrascrivere una decisione presa. Senza `creditoRifiutato` il click che toglie il
        // credito veniva annullato da `spalmaCredito` e il pulsante tornava verde da solo:
        // nella pagina non c'era modo di non usarlo.
        return isInboxMode && !creditiRifiutati.value.has(r.id) && r.gestione_id === rataTarget!.gestione_id;
    });
    const creditoDisponibile = righeCredito.reduce((s, r) => s + creditoRiga(r), 0);

    // Ripartisce il credito effettivamente usato sulle righe di credito attive
    const spalmaCredito = (importoEuro: number) => {
        let restoCents = Math.round(importoEuro * 100);
        righeCredito.forEach(r => {
            const capienzaCents = Math.round(creditoRiga(r) * 100);
            const usatoCents = Math.min(restoCents, capienzaCents);
            r.da_pagare = usatoCents > 0 ? -(usatoCents / 100) : 0;
            // Selezionata solo se qualcosa è stato davvero impegnato: il pulsante verde
            // «Credito applicato» su un'allocazione di zero dichiara un'operazione che non è
            // avvenuta, e intanto «Conferma incasso» resta spento senza spiegare perché.
            r.selezionata = usatoCents > 0;
            restoCents -= usatoCents;
        });
    };

    if (mode.value === 'auto') {
        rateList.value.forEach(r => {
            if (parseResiduoQuota(r.residuo) > 0) r.da_pagare = 0;
        });

        if (rataTarget && righeCredito.length > 0) {
            const debitoRata = parseResiduoQuota(rataTarget.residuo);
            const creditoDaUsare = Math.min(creditoDisponibile, debitoRata);

            spalmaCredito(creditoDaUsare);

            rataTarget.da_pagare = creditoDaUsare + importoNumerico.value;
            rataTarget.selezionata = rataTarget.da_pagare > 0;

            form.eccedenza = Math.max(0, (creditoDaUsare + importoNumerico.value) - debitoRata);

            if (form.eccedenza > 0) {
                rataTarget.da_pagare = debitoRata;
            }
        } else {
            // Il credito si impegna PRIMA di distribuire, e solo per la parte che il contante
            // lascia scoperta. Prima il budget era `contante + creditoDisponibile` e
            // l'eccedenza era l'avanzo del greedy: con 300 di credito, zero contante e 100 di
            // debito avanzavano 200, che finivano nel campo eccedenza come **anticipo** —
            // mentre sono credito che resta dov'è, mai entrato in cassa. Il server rifiutava
            // poi la registrazione, perché l'identità `importo_totale = somma + eccedenza`
            // non tornava. Vedi `creditoNecessario` e `usePaymentDistribution.test.ts`.
            const creditoEffettivoUsato = righeCredito.length > 0
                ? creditoNecessario(rateList.value, importoNumerico.value, creditoDisponibile)
                : 0;

            form.eccedenza = distributeGreedy(rateList.value, importoNumerico.value + creditoEffettivoUsato);

            if (righeCredito.length > 0) {
                spalmaCredito(creditoEffettivoUsato);
            }
        }
    } else {
        // Il **solo** contante: le righe di credito portano un `da_pagare` negativo e sono
        // quindi già scontate dentro l'allocato che `calculateExcess` sottrae. Qui prima si
        // sommava anche il credito, e l'eccedenza usciva `contante + 2·credito − allocato`.
        // Non era un numero storto e basta: la guardia del server rifiutava, il controller
        // non catturava, e l'amministratore si prendeva un 500 perdendo la compilazione.
        form.eccedenza = calculateExcess(rateList.value, importoNumerico.value);
    }

    rateList.value = [...rateList.value];
    syncForm();
};

const distributeAuto = () => {
    runDistribution();
};

const handleManualChange = (rata: any, val: string | number) => {
    // 🟢 FIX: Ignoriamo l'evento se siamo in auto per evitare race conditions al mount
    if (mode.value === 'auto') return;

    onManualChange(rata, val.toString());

    // TUTTE le righe, comprese quelle a credito, che portano un `da_pagare` negativo.
    //
    // `importo_totale` è il CONTANTE che entra in cassa. Sommando le sole righe a residuo
    // positivo ci finiva dentro anche la parte coperta dal credito, che in cassa non è mai
    // entrata: dopo aver applicato un credito bastava ritoccare a mano una riga per registrare
    // un incasso di denaro mai ricevuto. E il controllo del server non lo intercettava — la
    // riga a credito è negativa, la differenza usciva come anticipo, l'identità
    // `importo_totale = somma righe + eccedenza` tornava. Quadrava, ed era falso.
    //
    // Sommando anche i negativi resta il netto: quanto l'amministratore ha davvero in mano.
    // In manuale non esistono righe a credito impegnate — `toggleMode` le rilascia entrando —
    // quindi questa somma è già il contante e non serve nessuna correzione di segno. Ci era
    // stato un ciclo che riduceva il credito quando il netto scendeva sotto zero: è stato tolto
    // insieme alla causa, perché girando a ogni tasto consumava il credito in modo irreversibile.
    const nuovoTotaleVersato = rateList.value.reduce(
        (sum, r) => sum + (parseResiduoQuota(r.da_pagare) || 0),
        0,
    );

    form.importo_totale = nuovoTotaleVersato;
    calculateExcessOnly();
    syncForm();
};

const calculateExcessOnly = () => { form.eccedenza = calculateExcess(rateList.value, importoNumerico.value); };
const toggleMode = () => {
    mode.value = mode.value === 'auto' ? 'manual' : 'auto';

    if (mode.value === 'auto') {
        distributeAuto();
        return;
    }

    // Passando in MANUALE il credito impegnato viene rilasciato.
    //
    // I due meccanismi di allocazione — automatico con il credito, manuale riga per riga — non
    // compongono: finché una riga a credito resta impegnata mentre si edita a mano, ogni
    // combinazione produce un numero sbagliato. Il campo importo è `:lazy="false"`, quindi
    // `handleManualChange` gira a OGNI TASTO: un valore transitorio più basso del credito
    // impegnato lo consumava per sempre, e ridigitando la cifra giusta il credito non tornava —
    // il contante dichiarato finiva per includere denaro mai ricevuto, con l'identità del
    // server che tornava lo stesso.
    //
    // Chi vuole il credito torna in automatica e lo riapplica: una strada sola, come per la
    // sua distribuzione.
    rateList.value.forEach(r => {
        if ((parseResiduoQuota(r.da_pagare) || 0) < 0) {
            r.da_pagare = 0;
            r.selezionata = false;
        }
    });

    // E si ridistribuisce il SOLO contante. Rilasciare il credito senza rilasciare ciò che
    // copriva era mezzo lavoro: l'importo restava scritto sulla riga a debito, e con zero
    // contante la pagina mostrava «Allocato: 150,00» con «Conferma incasso» attivo su un
    // payload che il server rifiuta — l'identità `importo_totale = somma righe + eccedenza`
    // non torna. Quello che resta allocato dev'essere solo quello che il contante copre.
    form.eccedenza = distributeGreedy(rateList.value, importoNumerico.value);
    syncForm();
};

const resetAllocation = () => {
    form.importo_totale = '';
    resetAllocationComposable(rateList.value);
    rateList.value = [...rateList.value];
    calculateExcessOnly();
    syncForm();
};

const pagaTutto = () => {
    const somma = pagaTuttoComposable(rateList.value);
    form.importo_totale = somma;
    calculateExcessOnly();
    syncForm();
};

const pagaScadute = async () => {
    mode.value = 'manual';
    const somma = pagaScaduteComposable(rateList.value);
    form.importo_totale = somma;
    rateList.value = [...rateList.value];
    await nextTick();
    calculateExcessOnly();
    syncForm();
};

const syncForm = () => {
    // 🟢 FIX: Formattazione perfetta a 2 decimali per evitare errori 500
    form.dettaglio_pagamenti = rateList.value
        .filter(r => typeof r.da_pagare === 'number' && r.da_pagare !== 0)
        .map(r => ({
            rata_id: r.id,
            importo: parseFloat(Number(r.da_pagare).toFixed(2))
        }));
};

const toggleSearchMode = (newMode: 'persona' | 'immobile') => {
    if (searchMode.value !== newMode) {
        searchMode.value = newMode;

        // Passa per `svuotaRicerca()` invece di ripetere l'elenco a mano: questo blocco aveva
        // già lo stesso buco parziale — azzerava l'importo ma **non** `dettaglio_pagamenti`,
        // quindi il payload sopravviveva al cambio di modalità.
        svuotaRicerca();
        selectedImmobileId.value = null;
        form.pagante_id = null;
    }
};

/**
 * Tutti i motivi per cui l'incasso è stato rifiutato, per la fascia sopra «Conferma incasso».
 *
 * ## Perché serve una fascia, e non bastano gli errori accanto ai campi
 *
 * Questa schermata mostrava `InputError` **solo** per `cassa_id` e `data_pagamento`. Tutto il
 * resto — la guardia di quadratura della beta.43, quella sul debito altrui della beta.48, le tre
 * sulle compensazioni a credito — tornava dal server, finiva in `form.errors` e **non compariva
 * da nessuna parte**: il pulsante sembrava semplicemente non funzionare.
 *
 * È costato mezz'ora di diagnosi con gli strumenti di sviluppo aperti, l'11/08/2026, a chi aveva
 * scritto la guardia il giorno prima. Un amministratore non ha quegli strumenti: per lui il
 * gestionale è rotto e l'incasso non si registra, senza una riga di spiegazione.
 *
 * ⚠️ Si mostra **tutto** `form.errors`, non un elenco scelto di chiavi. Un elenco scelto è
 * esattamente il meccanismo che ha prodotto il silenzio: era giusto quando è stato scritto e ha
 * smesso di esserlo alla prima guardia nuova. Qui una guardia nuova è visibile da subito, senza
 * che nessuno si ricordi di aggiungerla. Il prezzo è che i due campi con errore in linea lo
 * mostrano anche nel riepilogo: una ripetizione, contro il rischio di un rifiuto muto.
 */
const erroriIncasso = computed<string[]>(() =>
    Object.values(form.errors).filter((m): m is string => typeof m === 'string' && m.length > 0)
);

const submit = () => {
    // 🟢 FIX: Clean total prima dell'invio
    const cleanTotal = parseFloat(Number(importoNumerico.value).toFixed(2));

    const payload = form.transform((data) => ({
        ...data,
        importo_totale: cleanTotal
    }));

    payload.post(route(generateRoute('gestionale.movimenti-rate.store'), props.condominio.id), {
        preserveScroll: true,
        onSuccess: () => {
            // `svuotaRicerca()` anche qui: i crediti rifiutati del condòmino appena incassato
            // restavano validi per il successivo, se gli id delle righe coincidevano.
            svuotaRicerca();
            form.reset();
            mode.value = 'auto';
            searchMode.value = 'persona';
            selectedImmobileId.value = null;
        }
    });
};

let paganteIniziale: number | null = null;
watch(() => form.pagante_id, (newVal) => {
    if (paganteIniziale !== null && newVal !== paganteIniziale) {
        // La richiesta arrivata dal portale vale per il condòmino di quella segnalazione: se
        // l'amministratore cambia persona, l'avviso deve spegnersi invece di seguirlo.
        dimenticaRichiestaCompensazione();

        // E con l'avviso va spenta anche la **rata bersaglio**, che è l'altra metà di quella
        // segnalazione. `prefill_rata_id` è l'id della rata PADRE, condominiale: la lista di un
        // altro condòmino contiene quasi sempre una quota della stessa rata, quindi senza questa
        // riga `isInboxMode` resterebbe acceso e includerebbe da sé il credito del **nuovo**
        // intestatario su quella gestione, senza un clic — proprio mentre il banner che lo
        // avrebbe spiegato è appena stato spento.
        setPriorityRataId(null);

        // E soprattutto va buttata via l'allocazione: era costruita per un'altra persona.
        // Senza questo, cercando per immobile, restava una riga a importo negativo dentro
        // `form.dettaglio_pagamenti` che l'interfaccia non mostrava più — il pulsante per
        // deselezionarla sparisce insieme al credito dell'intestatario precedente — e alla
        // conferma il server o rispondeva 500 o spendeva il credito di uno per la ricevuta
        // di un altro. Ripulire è l'unica lettura onesta: la distribuzione era di qualcun altro.
        // Azzerate le righe a mano e NON con `resetAllocation` del composable: quella, come
        // prima istruzione, commuta `mode` in 'manual'. Cambiare condòmino avrebbe quindi
        // spento la distribuzione automatica senza dirlo, e da lì digitare un importo non
        // avrebbe più allocato niente.
        rateList.value.forEach(r => { r.da_pagare = 0; r.selezionata = false; });
        rateList.value = [...rateList.value];

        // E si ridistribuisce: azzerare e basta lasciava la pagina con l'importo digitato, le
        // rate in elenco e nessuna riga allocata, con «Conferma incasso» spento e nessun
        // messaggio. In ricerca per persona non si vedeva perché `fetchDebiti` ricarica la
        // lista e il watcher ridistribuisce da sé; in ricerca per immobile quel caricamento non
        // avviene, e la distribuzione restava spenta in silenzio.
        runDistribution();
    }

    // Solo i valori veri aggiornano il riferimento. Prima ci finiva anche `null`, e questo
    // **disarmava il blocco qui sopra per la selezione successiva**: dopo una x, il nominativo
    // scelto dopo veniva trattato come il primo della schermata — quello che arriva dal link
    // precompilato — e saltava del tutto il reset dell'allocazione.
    if (newVal !== null && newVal !== undefined) paganteIniziale = newVal;

    if (searchMode.value !== 'persona') return;

    if (newVal) {
        fetchDebiti({ anagrafica_id: newVal });
    } else {
        // Il caso segnalato: la x sull'anagrafica. Cercando per persona l'elenco È della
        // persona, quindi togliendola non resta niente da mostrare.
        svuotaRicerca();
    }
});

watch(selectedImmobileId, (newVal) => {
    if (searchMode.value !== 'immobile') return;

    if (newVal) {
        fetchDebiti({ immobile_id: newVal });
    } else {
        // Simmetrico, ed era il caso più grave: qui il watcher non faceva assolutamente nulla,
        // e siccome l'intestatario è un altro campo `pagante_id` restava valorizzato — con
        // «Conferma incasso» acceso sopra le rate di un'unità non più selezionata.
        svuotaRicerca();
        form.pagante_id = null;
    }
});
watch(importoNumerico, () => { if (rateList.value.length > 0) runDistribution(); });

watch([rawRateList, () => form.gestione_id, showOnlyOverdue], async () => {
    let list = getRateListByGestione(form.gestione_id);

    if (showOnlyOverdue.value) {
        list = list.filter(r => isScaduta(r.data_scadenza || r.scadenza_human) || isRataZero(r));
    }

    if (intentUsaCredito.value && !creditoAutoApplicato.value) {
        const rataCredito = list.find(r => creditoRiga(r) > 0);
        if (rataCredito) {
            rataCredito.selezionata = true;
            creditoAutoApplicato.value = true;
        }
    }

    rateList.value = list;

    await nextTick();
    await nextTick();
    // 🟢 FIX: Triggeriamo solo se c'è un motivo valido per evitare reset non voluti
    if (importoNumerico.value > 0 || priorityRataId.value) {
        runDistribution();
    } else {
        // La lista è appena stata riclonata da `rawRateList`, quindi a schermo ogni allocazione
        // è tornata a zero: il payload deve dire la stessa cosa. `syncForm()` viveva solo dentro
        // `runDistribution()`, che qui non parte — così cambiare il filtro «Gestione» durante una
        // compensazione a solo credito (importo versato zero) lasciava dentro
        // `dettaglio_pagamenti` le righe della lista precedente, mentre la tabella le negava.
        syncForm();
    }
}, { deep: true, immediate: true });

onMounted(async () => {
    const params = new URLSearchParams(window.location.search);
    const taskId = params.get('related_task_id');
    const prefillAnagrafica = params.get('prefill_anagrafica_id');
    const prefillImporto = params.get('prefill_importo');
    const prefillDesc = params.get('prefill_descrizione');
    const prefillRataId = params.get('prefill_rata_id');

    if (params.get('intent_usa_credito') === 'true') {
        intentUsaCredito.value = true;
    }

    if (taskId) form.related_task_id = parseInt(taskId);
    if (prefillDesc) form.descrizione = prefillDesc;
    if (prefillRataId) setPriorityRataId(parseInt(prefillRataId)); else setPriorityRataId(null);
    if (prefillImporto) form.importo_totale = parseFloat(prefillImporto);
    if (prefillAnagrafica) form.pagante_id = parseInt(prefillAnagrafica);
});
</script>

<template>
    <Head title="Nuovo incasso rate" />

    <GestionaleLayout>
        <!--
            Altezza agganciata al viewport, non a un numero fisso.

            Prima era `h-[calc(100vh-40px)] min-h-[950px]`, e quel pavimento era il difetto: sotto
            i 990 px di schermo vinceva lui, la pagina veniva inchiodata a 950 px e cominciava a
            scorrere. Su un portatile si finiva con **tre barre di scorrimento annidate** — il
            documento, la colonna sinistra e l'elenco rate — per ottenere meno spazio utile di
            quello disponibile. E un monitor da 1080p mostrava esattamente quanto un portatile,
            perché nessuno dei due poteva superare i 950 px.

            **`h` e non `min-h`, ed è un vincolo tecnico prima che estetico.** Le due colonne
            usano `lg:h-full`, cioè `height: 100%`: una percentuale contro un genitore ad altezza
            **indefinita** si risolve in `auto`. Con `min-h` le colonne smettevano quindi di
            essere limitate e crescevano a contenuto — misurato con 12 rate: la card delle rate
            arrivava a **1084 px**, la pagina a 1555, e l'elenco **non scorreva più**. Cioè
            l'opposto dell'obiettivo: invece di una finestra scorrevole sulle rate, un lenzuolo.

            Con l'altezza definita l'elenco torna a scorrere dentro la sua card, che è il
            comportamento voluto: si vedono le prime rate e si scorre lì dentro, senza muovere il
            resto della pagina. Il prezzo è che quando il **modulo** a sinistra non ci sta viene
            ritagliato e scorre lui — in ricerca per immobile, che ha un campo in più. È il male
            minore: un modulo si scorre, un elenco di rate lungo due schermi no.

            I 126 px sottratti sono misurati a video, non stimati: 64 di barra applicativa, 16 di
            spaziatura di `main`, 45 di piè di pagina, 1 di margine. Il prefisso `lg:` limita il
            vincolo al layout a due colonne;
            sotto, dove le colonne si impilano, la pagina torna a scorrere normalmente ed è giusto
            così.
        -->
        <div class="px-6 py-3 w-full flex flex-col gap-3 lg:min-h-[calc(100vh-126px)]">

            <div class="shrink-0">
                <!--
                    Senza `page-subtitle`. Diceva «registra il pagamento delle rate condominiali
                    con distribuzione automatica o manuale»: la prima metà la dice il titolo, la
                    seconda è il titolo della scheda accanto. Valeva 31 px — mezza riga di rata —
                    per ripetere due cose già scritte a dieci centimetri di distanza.
                -->
                <PageHeaderGuide
                    page-title="Nuovo incasso rate"
                    :guides="pageGuides"
                    :breadcrumbs="(breadcrumbs as any)"
                    :back-url="route(generateRoute('gestionale.movimenti-rate.index'), { condominio: props.condominio.id })"
                    back-text="Indietro"
                >
                    <template #actions>
                        <!--
                            «Guida completa» e non «Guida»: è il nome che usa
                            `FatturaRegisterNew`, la pagina di riferimento, e distingue questo
                            pannello dalle tre schede qui sotto — che sono anch'esse una guida,
                            ma di un'altra scala.
                        -->
                        <Button variant="outline" class="h-9 gap-2 font-medium shadow-sm" @click="mostraGuida = true">
                            <BookOpen class="h-4 w-4" /> Guida completa
                        </Button>
                    </template>
                </PageHeaderGuide>
            </div>

            <IncassoRateGuide v-model:open="mostraGuida" />

            <div class="grid grid-cols-1 lg:grid-cols-12 gap-3 flex-1 min-h-0">

                <!--
                    La colonna sinistra **detta l'altezza della riga**: niente `h-full`, niente
                    ritaglio. Il modulo è alto quanto serve e non scorre mai per conto suo.
                -->
                <div class="lg:col-span-4 flex flex-col bg-white rounded-xl border shadow-sm overflow-hidden">
                    <!--
                        `lg:overflow-y-auto` e non `overflow-y-auto`: da lg in su resta la valvola
                        Nessun `overflow` qui: il corpo del modulo è alto quanto il suo contenuto
                        e non ha una barra propria. L'obiettivo non era nascondere la barra —
                        era non averne bisogno.
                    -->
                    <div class="p-3 space-y-2">

                        <!--
                            L'etichetta «Cerca debiti per anagrafica o immobile» era ridondante due
                            volte: la dicono già il selettore Persona/Immobile qui sotto e
                            l'etichetta del campo successivo. Valeva 27 px, mezza riga di rata.
                        -->
                        <div>
                            <div class="grid grid-cols-2 gap-1 bg-slate-100 p-1 rounded-lg">
                                <button @click="toggleSearchMode('persona')" class="flex items-center justify-center py-1 text-xs font-medium rounded-md transition-all" :class="searchMode === 'persona' ? 'bg-white text-primary shadow-sm font-bold' : 'text-slate-500 hover:text-slate-700'">
                                    <User class="w-3.5 h-3.5 mr-1.5"/> Persona
                                </button>
                                <button @click="toggleSearchMode('immobile')" class="flex items-center justify-center py-1 text-xs font-medium rounded-md transition-all" :class="searchMode === 'immobile' ? 'bg-white text-primary shadow-sm font-bold' : 'text-slate-500 hover:text-slate-700'">
                                    <Building class="w-3.5 h-3.5 mr-1.5"/> Immobile
                                </button>
                            </div>

                            <div class="mt-2">
                                <Label class="text-[11px] uppercase text-slate-500 font-bold tracking-wider mb-1 block">
                                    {{ searchMode === 'persona' ? 'Seleziona anagrafica' : 'Seleziona unità immobiliare' }}
                                </Label>

                                <v-select
                                    v-if="searchMode === 'persona'"
                                    :options="condomini"
                                    v-model="form.pagante_id"
                                    label="nome"
                                    :reduce="(c: any) => c.id"
                                    class="w-full bg-white text-sm"
                                    placeholder="Cerca per nome o cognome..."
                                >
                                    <template #option="{ nome, indirizzo, codice_fiscale }">
                                        <div class="flex flex-col py-0.5">
                                            <span class="font-medium text-sm text-slate-800">{{ nome }}</span>
                                            <span class="text-[11px] text-slate-400 truncate">{{ indirizzo || codice_fiscale || 'Nessun dettaglio aggiuntivo' }}</span>
                                        </div>
                                    </template>
                                </v-select>

                                <div v-else class="space-y-2">
                                    <v-select
                                        :options="immobili"
                                        v-model="selectedImmobileId"
                                        :getOptionLabel="(i: any) => `Int. ${i.interno}${i.descrizione ? ' - ' + i.descrizione : ''}`"
                                        :reduce="(i: any) => i.id"
                                        class="w-full bg-white text-sm"
                                        placeholder="Cerca per interno o descrizione..."
                                    >
                                        <template #option="{ interno, descrizione, nome }">
                                            <div class="flex flex-col py-0.5">
                                                <span class="font-medium text-sm text-slate-800">
                                                    Interno {{ interno }}
                                                    <span v-if="descrizione" class="text-slate-500 font-normal">- {{ descrizione }}</span>
                                                </span>
                                                <span class="text-[11px] text-slate-400 truncate">{{ nome || 'Unità immobiliare' }}</span>
                                            </div>
                                        </template>
                                    </v-select>

                                    <div>
                                        <Label class="text-[11px] uppercase text-slate-500 font-bold tracking-wider mb-1 block">Intestatario della ricevuta</Label>
                                        <v-select
                                            :options="condomini"
                                            v-model="form.pagante_id"
                                            label="nome"
                                            :reduce="(c: any) => c.id"
                                            class="w-full bg-white text-sm"
                                            placeholder="A chi intesterai il pagamento?"
                                        >
                                            <template #option="{ nome, indirizzo, codice_fiscale }">
                                                <div class="flex flex-col py-0.5">
                                                    <span class="font-medium text-sm text-slate-800">{{ nome }}</span>
                                                    <span class="text-[11px] text-slate-400 truncate">{{ indirizzo || codice_fiscale || 'Nessun dettaglio aggiuntivo' }}</span>
                                                </div>
                                            </template>
                                        </v-select>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!--
                            Senza `space-y-3`: l'etichetta ha già il suo `mb-1`, come tutte le
                            altre della colonna. I 12 px in più qui erano un'eccezione non voluta,
                            non l'enfasi sul campo principale — quella la fanno `h-10` e
                            `text-lg`, che restano.
                        -->
                        <div>
                            <Label class="text-[11px] uppercase text-slate-500 font-bold tracking-wider mb-1 block">Importo versato</Label>
                            <MoneyInput
                                id="importo_totale"
                                v-model="form.importo_totale"
                                :money-options="moneyOptions"
                                :lazy="false"
                                class="h-9 text-base font-bold shadow-sm focus:ring-2 focus:ring-primary/20 border-slate-200 w-full rounded-md border bg-background px-3 py-2"
                                placeholder="0,00"
                            />
                        </div>

                        <div class="space-y-2">
                            <div>
                                <Label class="text-[11px] uppercase text-slate-500 font-bold tracking-wider mb-1 block">Risorsa finanziaria</Label>
                                <v-select
                                    :options="risorse"
                                    v-model="form.cassa_id"
                                    label="nome"
                                    :reduce="(c: any) => c.id"
                                    class="w-full bg-white text-sm shadow-sm"
                                    @update:modelValue="form.clearErrors('cassa_id')"
                                    placeholder="Dove versi i soldi?"
                                >
                                    <template #option="{ nome, tipo, conto_corrente }">
                                        <div class="flex flex-col py-0.5">
                                            <div class="flex items-center justify-between gap-2">
                                                <span class="font-bold text-sm text-slate-800">{{ nome }}</span>
                                                <span class="text-[9px] uppercase font-black px-1.5 py-0.5 rounded bg-slate-100 text-slate-500 border border-slate-200">{{ tipo }}</span>
                                            </div>
                                            <span v-if="conto_corrente?.iban" class="text-[10px] text-slate-400 mt-0.5">{{ conto_corrente.iban }}</span>
                                        </div>
                                    </template>
                                </v-select>
                                <InputError :message="form.errors.cassa_id" class="mt-1" />
                            </div>

                            <div class="grid grid-cols-2 gap-3">
                                <div>
                                    <Label class="text-[11px] uppercase text-slate-500 font-bold tracking-wider mb-1 block">Data versamento</Label>
                                    <Input type="date" v-model="form.data_pagamento" class="h-9 text-sm border-slate-200 shadow-sm"/>
                                    <InputError :message="form.errors.data_pagamento" class="mt-1" />
                                </div>
                                <div>
                                    <Label class="text-[11px] uppercase text-slate-500 font-bold tracking-wider mb-1 block">Causale</Label>
                                    <div class="relative">
                                        <FileText class="w-3.5 h-3.5 absolute left-2.5 top-1/2 -translate-y-1/2 text-slate-400"/>
                                        <Input placeholder="Es. Bonifico" v-model="form.descrizione" class="pl-8 h-9 text-sm shadow-sm border-slate-200"/>
                                    </div>
                                </div>
                            </div>

                            <div>
                                <Label class="text-[11px] uppercase text-slate-500 font-bold tracking-wider mb-1 block">Gestione (filtro)</Label>
                                <v-select
                                    :options="gestioni"
                                    v-model="form.gestione_id"
                                    label="nome"
                                    :reduce="(g: any) => g.id"
                                    class="w-full bg-slate-50 text-sm focus-within:bg-white transition-colors"
                                    placeholder="Tutte (automatica)"
                                >
                                    <template #option="{ nome, tipo }">
                                        <div class="flex items-center justify-between py-0.5">
                                            <span class="text-sm text-slate-700">{{ nome }}</span>
                                            <span v-if="tipo" class="text-[9px] uppercase font-bold px-1.5 py-0.5 rounded bg-slate-200 text-slate-500">{{ tipo }}</span>
                                        </div>
                                    </template>
                                </v-select>
                            </div>
                        </div>
                    </div>

                    <div class="p-3 bg-slate-50 border-t border-slate-200 shrink-0">
                        <div v-if="erroriIncasso.length" class="mb-2 rounded-lg border border-red-200 bg-red-50 p-3">
                            <div class="flex items-center gap-2 mb-1.5">
                                <AlertCircle class="w-4 h-4 text-red-600 shrink-0" />
                                <span class="text-xs font-bold text-red-800 uppercase tracking-wider">Incasso non registrato</span>
                            </div>
                            <ul class="space-y-1.5 pl-6">
                                <li v-for="(errore, i) in erroriIncasso" :key="i" class="text-xs text-red-700 leading-relaxed list-disc">
                                    {{ errore }}
                                </li>
                            </ul>
                        </div>
                        <div class="flex justify-between items-center text-xs mb-2 px-1">
                            <span class="text-slate-500 uppercase tracking-wider font-semibold">Totale allocato:</span>
                            <span class="font-bold text-slate-800 text-sm">{{ euro(totalAllocato) }}</span>
                        </div>
                        <Button
                            @click="submit"
                            :disabled="form.processing || !previewContabile.hasData || !form.pagante_id || (isCrossGestione && !crossGestioneConfermato)"
                            class="w-full h-11 bg-emerald-600 hover:bg-emerald-500 text-white font-bold shadow-md transition-all text-sm"
                        >
                            <CheckCircle2 class="w-4 h-4 mr-2" /> Conferma incasso
                        </Button>
                    </div>
                </div>

                <!--
                    ## Come si ottengono insieme le due cose che sembravano escludersi
                    #
                    # Servono un modulo che non scorre **e** un elenco rate che scorre dentro la
                    # sua card. Con le regole normali del CSS sono incompatibili: se la riga ha
                    # altezza definita l'elenco scorre ma il modulo viene ritagliato; se ha
                    # altezza automatica il modulo respira ma `h-full` si risolve in `auto` e
                    # l'elenco diventa un lenzuolo da 1084 px (misurato, con 12 rate).
                    #
                    # La via d'uscita: la colonna sinistra **detta** l'altezza della riga, questa
                    # la **riempie senza contribuirvi**. `lg:absolute inset-0` la toglie dal
                    # calcolo dell'altezza e le dà come riferimento la cella `relative`, che è
                    # alta quanto il modulo. Da lì `flex-1 min-h-0` sulla card delle rate torna a
                    # funzionare e l'elenco scorre al suo interno.
                    #
                    # Sotto `lg` niente di tutto questo: le colonne si impilano e scorre la
                    # pagina, che è il comportamento giusto su uno schermo stretto.
                -->
                <div class="lg:col-span-8 lg:relative">
                    <div class="flex flex-col gap-3 lg:absolute lg:inset-0 lg:overflow-hidden">

                    <div class="bg-white rounded-xl border shadow-sm flex flex-col flex-1 min-h-0 overflow-hidden">
                        <div class="p-3 border-b bg-gray-50 flex justify-between items-center shrink-0">
                            <div class="flex items-center gap-3">
                                <h3 class="font-semibold text-gray-900 text-sm">Ripartizione debito</h3>
                                <Badge v-if="rateList.length" variant="secondary" class="bg-white border text-gray-600 text-[10px]">{{ rateList.length }} Rate</Badge>
                            </div>
                            <div v-if="rateList.length" class="flex items-center gap-2 text-xs">
                                <span class="text-gray-500">Debito Totale:</span>
                                <span class="font-bold text-gray-900 mr-2">{{ euro(totaleDebito) }}</span>
                                <ArrowRight class="w-3 h-3 text-gray-300" />
                                <div class="flex items-center gap-1 px-2 py-0.5 rounded border transition-colors shadow-sm" :class="bilancioFinale.class">
                                    <span class="font-medium">{{ bilancioFinale.label }}</span>
                                    <span class="font-bold">{{ euro(bilancioFinale.value) }}</span>
                                </div>
                            </div>
                        </div>

                        <div class="px-3 py-2 border-b bg-gray-50/50 flex justify-between items-center shrink-0">
                            <div class="flex items-center gap-4">
                                <div class="flex items-center bg-white border rounded-md px-1 py-0.5 h-7 shadow-sm cursor-pointer select-none" @click="toggleMode">
                                    <div class="px-2 py-0.5 rounded text-[10px] font-bold transition-all uppercase" :class="mode === 'auto' ? 'bg-primary text-white shadow-sm' : 'text-gray-400 hover:text-gray-600'">Automatico</div>
                                    <div class="px-2 py-0.5 rounded text-[10px] font-bold transition-all uppercase" :class="mode === 'manual' ? 'bg-primary text-white shadow-sm' : 'text-gray-400 hover:text-gray-600'">Manuale</div>
                                </div>

                                <div class="flex items-center gap-2 ml-2 border-l pl-4">
                                    <input
                                        type="checkbox"
                                        id="toggle-scadute"
                                        v-model="showOnlyOverdue"
                                        class="w-3.5 h-3.5 text-primary border-gray-300 rounded focus:ring-primary cursor-pointer"
                                    >
                                    <label for="toggle-scadute" class="text-[10px] font-bold uppercase text-slate-500 cursor-pointer select-none">
                                        Mostra scadute
                                    </label>
                                </div>
                            </div>
                            <div class="flex items-center gap-2" v-if="rateList.length">
                                <Button size="sm" variant="ghost" @click="resetAllocation" class="h-7 text-xs text-gray-500 hover:text-red-600 hover:bg-red-50 px-2"><RotateCcw class="w-3 h-3 mr-1"/> Resetta</Button>
                                <div class="h-3 w-px bg-gray-300 mx-1"></div>
                                <Button size="sm" variant="outline" @click="pagaScadute" class="h-7 text-xs bg-white border-gray-200 text-gray-600">Paga scadute</Button>
                                <Button size="sm" variant="outline" @click="pagaTutto" class="h-7 text-xs bg-white border-gray-200 text-gray-600">Paga tutto</Button>
                            </div>
                        </div>

                        <div class="flex-1 overflow-y-auto custom-scrollbar">

                            <div v-if="intentUsaCredito" class="bg-amber-50 border-b border-amber-200 px-4 py-3 flex items-start gap-3 text-amber-800 shrink-0">
                                <div class="p-1 bg-amber-100 rounded-full mt-0.5 shrink-0">
                                    <AlertCircle class="w-4 h-4 text-amber-600" />
                                </div>
                                <div>
                                    <span class="text-xs font-bold block mb-0.5">Richiesta di compensazione</span>

                                    <!-- Tre stati, letti dalla selezione VERA e non da un flag che
                                         ricorda di averla fatta una volta: la lista si ricostruisce
                                         a ogni cambio di filtro e la selezione si azzera. -->
                                    <span v-if="creditoSelezionato" class="text-[11px] leading-snug block opacity-90">
                                        Il condòmino ha chiesto di saldare questo importo con il suo credito pregresso.
                                        Il credito disponibile è già stato <strong>selezionato</strong> qui sotto: verifica gli
                                        importi e conferma l'operazione.
                                    </span>
                                    <span v-else-if="creditoVisibile" class="text-[11px] leading-snug block opacity-90">
                                        Il condòmino ha chiesto di saldare questo importo con il suo credito pregresso.
                                        Clicca <strong>«Usa credito»</strong> sulla riga che lo porta, poi verifica gli importi
                                        e conferma.
                                    </span>
                                    <span v-else-if="creditoInLista" class="text-[11px] leading-snug block opacity-90">
                                        Il condòmino ha chiesto di saldare questo importo con il suo credito pregresso.
                                        Il credito <strong>c'è</strong>, ma i filtri attivi ne nascondono la riga: togli il
                                        filtro per gestione o «Mostra scadute» per vederla.
                                    </span>
                                    <span v-else-if="!loadingRate" class="text-[11px] leading-snug block opacity-90">
                                        Il condòmino ha chiesto di saldare questo importo con il suo credito pregresso, ma
                                        <strong>non risulta alcun credito disponibile</strong> per lui: la richiesta non può
                                        essere soddisfatta così com'è.
                                    </span>
                                    <span v-else class="text-[11px] leading-snug block opacity-90">
                                        Il condòmino ha chiesto di saldare questo importo con il suo credito pregresso.
                                        Sto caricando la sua situazione…
                                    </span>
                                </div>
                            </div>

                            <div v-if="creditoSenzaScoperto" class="bg-slate-50 border-b border-slate-200 px-4 py-3 flex items-start gap-3 text-slate-700 shrink-0">
                                <div class="p-1 bg-slate-100 rounded-full mt-0.5 shrink-0">
                                    <Info class="w-4 h-4 text-slate-500" />
                                </div>
                                <div>
                                    <span class="text-xs font-bold block mb-0.5">Nulla da compensare</span>
                                    <span class="text-[11px] leading-snug block opacity-90">
                                        L'importo che stai incassando copre già tutte le rate in elenco: il credito
                                        resta dov'è. Abbassa l'importo versato se vuoi usarlo al suo posto.
                                    </span>
                                </div>
                            </div>

                            <div v-if="rateList.length > 0 && !hasSaldoPregressoInLista" class="bg-emerald-50/80 border-b border-emerald-100 px-3 py-2 flex items-center text-emerald-700 shrink-0">
                                <CheckCircle2 class="w-4 h-4 mr-2 text-emerald-500" />
                                <span class="text-[11px] font-medium">Situazione pregressa regolare. Procedi con l'incasso delle rate ordinarie.</span>
                            </div>

                            <div v-if="spaccatoCreditoDisponibile.length > 0" class="bg-blue-50/80 px-3 py-2 flex items-center gap-2 text-blue-700 shrink-0 flex-wrap" :class="isCrossGestione ? 'border-b border-blue-100/0' : 'border-b border-blue-100'">
                                <Wallet class="w-4 h-4 text-blue-500 shrink-0" />
                                <span class="text-[11px] font-medium">Credito disponibile:</span>
                                <span v-for="(g, idx) in spaccatoCreditoDisponibile" :key="idx" class="text-[11px] font-bold">
                                    {{ g.nome }}: {{ euro(g.importo) }}<span v-if="idx < spaccatoCreditoDisponibile.length - 1">,</span>
                                </span>
                            </div>

                            <div v-if="isCrossGestione" class="bg-amber-50 border-b border-amber-200 px-4 py-3 flex items-start gap-3 text-amber-800 shrink-0">
                                <div class="p-1 bg-amber-100 rounded-full mt-0.5 shrink-0">
                                    <AlertCircle class="w-4 h-4 text-amber-600" />
                                </div>
                                <div class="flex-1">
                                    <span class="text-xs font-bold block mb-1">Attenzione: stai attraversando gestioni diverse</span>
                                    <span class="text-[11px] leading-snug block opacity-90 mb-2">
                                        Il credito qui sopra non appartiene alla stessa gestione della rata che stai saldando. Non è impedito, ma verifica che sia la scelta corretta prima di confermare.
                                    </span>
                                    <label class="flex items-center gap-2 text-[11px] font-bold cursor-pointer select-none">
                                        <input type="checkbox" v-model="crossGestioneConfermato" class="w-3.5 h-3.5 text-amber-600 border-amber-300 rounded focus:ring-amber-500 cursor-pointer" />
                                        Confermo l'utilizzo di credito da una gestione diversa
                                    </label>
                                </div>
                            </div>

                            <table v-if="rateList.length" class="w-full text-sm border-collapse">
                                <thead class="bg-white sticky top-0 z-10 shadow-sm text-[10px] uppercase text-gray-400 font-bold tracking-wider">
                                    <tr>
                                        <th class="p-3 pl-4 text-left bg-gray-50/95 backdrop-blur border-b border-gray-100">Scadenza</th>
                                        <th class="p-3 text-left bg-gray-50/95 backdrop-blur border-b border-gray-100">Rata / Intestatario</th>
                                        <th class="p-3 text-right bg-gray-50/95 backdrop-blur border-b border-gray-100">Residuo</th>
                                        <th class="p-3 pr-4 text-right bg-gray-50/95 backdrop-blur border-b border-gray-100 w-[140px]">Importo</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-50">
                                    <tr v-for="r in rateList" :key="r.id" class="transition-colors group" :class="[r.da_pagare > 0 ? 'bg-emerald-50/20' : 'hover:bg-gray-50', parseResiduoQuota(r.residuo) < 0 ? 'bg-blue-50/30' : '']">
                                       <td class="p-3 pl-4 align-top">
                                            <div class="flex flex-col">
                                                <span class="text-xs font-medium text-gray-600">{{ r.scadenza_human }}</span>

                                                <div class="mt-1 flex flex-col gap-1">

                                                    <span v-if="parseResiduoQuota(r.residuo) < 0"
                                                        class="inline-flex items-center text-[9px] font-bold text-blue-600 bg-blue-50 border border-blue-200 px-1.5 py-0.5 rounded w-fit uppercase tracking-tighter"
                                                        title="I crediti sono automaticamente attivi e pronti per la compensazione">
                                                        <CheckCircle2 class="w-2.5 h-2.5 mr-1" /> Credito Attivo
                                                    </span>

                                                    <template v-else>
                                                        <span v-if="!r.is_emitted || Number(r.is_emitted) === 0"
                                                            class="inline-flex items-center text-[9px] font-bold text-amber-600 bg-amber-50 border border-amber-200 px-1.5 py-0.5 rounded w-fit uppercase tracking-tighter"
                                                            title="Questa rata non ha ancora generato una scrittura contabile">
                                                            <AlertCircle class="w-2.5 h-2.5 mr-1" /> No emissione
                                                        </span>

                                                        <span v-else-if="!r.is_published || Number(r.is_published) === 0"
                                                            class="inline-flex items-center text-[9px] font-bold text-indigo-600 bg-indigo-50 border border-indigo-200 px-1.5 py-0.5 rounded w-fit uppercase tracking-tighter"
                                                            title="Rata emessa contabilmente ma attualmente nascosta ai condòmini">
                                                            <Lock class="w-2.5 h-2.5 mr-1" /> Silenziosa
                                                        </span>

                                                        <span v-else
                                                            class="inline-flex items-center text-[9px] font-bold text-emerald-600 bg-emerald-50 border border-emerald-100 px-1.5 py-0.5 rounded w-fit uppercase tracking-tighter">
                                                            <CheckCircle2 class="w-2.5 h-2.5 mr-1" /> Emessa
                                                        </span>
                                                    </template>

                                                    <span v-if="r.scaduta && parseResiduoQuota(r.residuo) > 0"
                                                        class="text-[9px] text-red-500 font-bold uppercase flex items-center bg-red-50 border border-red-100 w-fit px-1.5 py-0.5 rounded tracking-tighter">
                                                        <AlertCircle class="w-2.5 h-2.5 mr-1"/> Scaduta
                                                    </span>
                                                </div>

                                            </div>
                                        </td>
                                        <td class="p-3 align-top">
                                            <div class="text-xs font-bold text-gray-800 mb-0.5">{{ r.descrizione }}</div>

                                            <div class="text-[11px] text-blue-600 font-medium flex items-center gap-1 max-w-[200px]" :title="r.intestatari_full || r.intestatario">
                                                <User class="w-3 h-3 opacity-70 shrink-0"/>
                                                <span class="truncate cursor-help">{{ r.intestatario }}</span>
                                            </div>

                                            <div class="text-[10px] text-gray-400 mt-0.5 flex flex-wrap items-center gap-1">
                                                <span>{{ r.gestione }}</span>
                                                <div v-if="r.dettaglio_quote && r.dettaglio_quote.length > 0">
                                                    <TooltipProvider :delayDuration="0">
                                                        <Tooltip>
                                                            <TooltipTrigger as-child>
                                                                <div class="ml-1 inline-flex items-center cursor-help"><Info class="w-3 h-3 text-blue-400" /></div>
                                                            </TooltipTrigger>

                                                            <TooltipContent side="bottom" class="bg-slate-900 border-slate-700 text-slate-200 p-4 w-80 shadow-2xl rounded-lg z-[100]">
                                                                <div class="text-[10px] font-bold text-slate-400 mb-3 uppercase tracking-wider border-b border-slate-700 pb-1 text-center">
                                                                    <span v-if="parseResiduoQuota(r.residuo) < 0">Dettaglio Credito</span>
                                                                    <span v-else>Analisi Debito</span>
                                                                </div>
                                                                <ul class="space-y-4">
                                                                    <li v-for="(dett, idx) in r.dettaglio_quote" :key="idx" class="text-[11px]">
                                                                        <div class="font-bold text-white mb-1.5 pb-0.5 border-b border-slate-700/50 flex items-center justify-between">
                                                                            <div class="flex items-center gap-1.5">
                                                                                <Building class="w-3 h-3 text-slate-500"/>
                                                                                <span>{{ dett.unita }}</span>
                                                                            </div>

                                                                            <div class="text-[10px] text-blue-300 flex items-center justify-end gap-1 max-w-[150px]" :title="dett.anagrafica">
                                                                                <User class="w-2.5 h-2.5 opacity-70 shrink-0"/>
                                                                                <span class="truncate">{{ dett.anagrafica }}</span>

                                                                                <span v-if="dett.ruolo" class="ml-0.5 bg-blue-950 text-blue-200 text-[8px] font-black px-1.5 py-0.5 rounded border border-blue-800 leading-none shrink-0" :title="dett.ruolo === 'P' ? 'Proprietario' : (dett.ruolo === 'I' ? 'Inquilino' : 'Usufruttuario')">
                                                                                    {{ dett.ruolo }}
                                                                                </span>
                                                                            </div>
                                                                        </div>

                                                                        <div>
                                                                            <div class="flex justify-between items-center pl-2 mb-1">
                                                                                <span class="text-slate-400">Quota rata:</span>
                                                                                <span class="font-medium text-slate-200">+ {{ euro(Math.abs(dett.componente_spesa)) }}</span>
                                                                            </div>
                                                                            <div class="flex justify-between items-center pl-2 pt-1 border-t border-slate-800">
                                                                                <span class="text-[10px] text-slate-500 font-bold uppercase">Totale:</span>
                                                                                <span class="font-bold" :class="parseResiduoQuota(dett.residuo) < 0 ? 'text-emerald-500' : 'text-orange-400'">{{ euro(parseResiduoQuota(dett.residuo)) }}</span>
                                                                            </div>
                                                                        </div>
                                                                    </li>
                                                                </ul>
                                                                <div class="border-t-2 border-slate-600 pt-2 mt-3 flex justify-between items-center bg-slate-800/50 p-2 rounded -mx-2">
                                                                    <span class="text-xs font-bold text-white uppercase tracking-wide">Netto Rata:</span>
                                                                    <div class="text-right">
                                                                        <span class="font-bold text-sm" :class="parseResiduoQuota(r.residuo) < 0 ? 'text-emerald-400' : 'text-white'">
                                                                            {{ euro(r.residuo) }}
                                                                        </span>
                                                                    </div>
                                                                </div>
                                                            </TooltipContent>
                                                        </Tooltip>
                                                    </TooltipProvider>
                                                </div>
                                                <span v-else>• {{ r.unita }}</span>
                                            </div>
                                        </td>

                                        <td class="p-3 text-right align-top">
                                            <template v-if="hasSaldoMisto(r)">
                                                <div class="flex flex-col items-end">
                                                    <span class="text-sm font-bold text-red-600">{{ euro(r.residuo) }}</span>
                                                    <div class="mt-1 flex flex-col items-end gap-1">
                                                        <span class="inline-flex items-center px-1.5 py-0.5 rounded text-[9px] font-bold bg-red-50 text-red-600 border border-red-200 shadow-sm" title="Attenzione: questa voce contiene un debito e un credito che si annullano a vicenda. Il credito non è perso: resta spendibile sulle altre rate.">
                                                            <AlertCircle class="w-3 h-3 mr-1" /> SALDO MISTO
                                                        </span>
                                                        <span class="text-[9px] text-slate-500 font-medium">Seleziona l'anagrafica</span>
                                                    </div>
                                                </div>
                                            </template>

                                            <template v-else>
                                                <div v-if="parseResiduoQuota(r.residuo) < 0" class="flex flex-col items-end pt-1">
                                                    <span class="text-sm font-bold text-emerald-600">{{ euro(r.residuo) }}</span>
                                                    <span class="inline-flex items-center px-1.5 py-0.5 rounded text-[9px] font-bold bg-emerald-50 text-emerald-600 border border-emerald-200 mt-1 uppercase">
                                                        {{ isRataZero(r) ? 'CREDITO PREGRESSO' : 'CREDITO' }}
                                                    </span>
                                                </div>

                                                <div v-else class="flex flex-col items-end pt-1">
                                                    <span class="text-sm" :class="isRataZero(r) ? 'font-bold text-red-600' : 'font-semibold text-slate-700'">
                                                        {{ euro(r.residuo) }}
                                                    </span>

                                                    <div v-if="isRataZero(r) && parseResiduoQuota(r.residuo) > 0" class="mt-1">
                                                        <span class="inline-flex items-center px-1.5 py-0.5 rounded text-[9px] font-bold bg-red-50 text-red-600 border border-red-200">
                                                            DEBITO PREGRESSO
                                                        </span>
                                                    </div>

                                                    <div v-if="parseResiduoQuota(r.residuo) > 0 && parseResiduoQuota(r.residuo) < r.importo_totale && !isRataZero(r)" class="mt-1">
                                                        <span class="inline-flex items-center px-1.5 py-0.5 rounded text-[9px] font-bold bg-amber-50 text-amber-600 border border-amber-200" :title="'Importo originale: ' + euro(r.importo_totale)">
                                                            <RotateCcw class="w-2.5 h-2.5 mr-1" /> PARZIALE
                                                        </span>
                                                    </div>
                                                </div>
                                            </template>
                                        </td>
                                        <td class="p-3 pr-4 text-right align-top w-[140px]">
                                            <MoneyInput
                                                v-if="parseResiduoQuota(r.residuo) > 0"
                                                :id="'rata_' + r.id"
                                                :key="'input_' + r.id + '_' + mode + '_' + r.da_pagare"
                                                v-model="r.da_pagare"
                                                @update:modelValue="handleManualChange(r, $event)"
                                                :disabled="mode === 'auto'"
                                                :money-options="moneyOptions"
                                                :lazy="false"
                                                class="text-right font-bold h-8 text-sm transition-all rounded-md border px-2 py-1 w-full outline-none focus:ring-2 focus:ring-primary/20"
                                                :class="[
                                                    r.da_pagare > 0 ? 'border-emerald-500 bg-white ring-1 ring-emerald-500/20 text-emerald-700' : 'border-slate-200 bg-transparent hover:border-slate-300 text-slate-800',
                                                    mode === 'auto' ? 'opacity-70 cursor-not-allowed bg-slate-50' : 'bg-white'
                                                ]"
                                                placeholder="0,00"
                                            />

                                            <div v-else-if="creditoRiga(r) > 0" class="flex justify-end h-8 items-center">
                                                <Button
                                                    size="sm"
                                                    variant="outline"
                                                    @click="toggleCredito(r)"
                                                    class="h-7 px-2 text-[10px] font-bold tracking-tight uppercase transition-all"
                                                    :class="r.selezionata ? 'bg-emerald-100 text-emerald-700 border-emerald-300 hover:bg-emerald-200' : 'bg-white text-blue-600 border-blue-200 hover:bg-blue-50'"
                                                >
                                                    <CheckCircle2 v-if="r.selezionata" class="w-3.5 h-3.5 mr-1" />
                                                    <ArrowRightLeft v-else class="w-3.5 h-3.5 mr-1" />
                                                    {{ r.selezionata ? 'Credito applicato' : 'Usa credito' }}
                                                </Button>
                                            </div>

                                            <div v-else class="text-xs text-slate-400 italic flex items-center justify-end h-8 opacity-80 pr-2">N/D</div>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                            <div v-else class="flex flex-col items-center justify-center h-full text-slate-400 space-y-4 py-12 bg-slate-50/30">
                                <div class="p-4 bg-white rounded-full shadow-sm border border-slate-100"><ArrowRightLeft class="w-8 h-8 opacity-20" /></div>
                                <div class="text-center">
                                    <p class="font-medium text-sm text-slate-500">Nessuna rata visualizzata</p>
                                    <p class="text-xs text-slate-400 mt-1">Seleziona un condomino o un immobile per iniziare</p>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!--
                        L'anteprima occupa spazio solo quando ha qualcosa da dire.

                        Prima erano 200 px fissi, dovuti sempre — anche mentre il riquadro diceva
                        soltanto «inserisci un importo per vedere l'anteprima». Cioè per tutta la
                        prima metà del lavoro, quando l'unica cosa che conta è scorrere le rate,
                        il 40% della colonna destra era tenuto da un pannello vuoto.

                        Ora ha due stati. **Vuoto**: resta la sola barra del titolo, e i ~140 px
                        risparmiati vanno all'elenco rate proprio quando servono. **Con dati**:
                        `lg:h-[30%]` la fa crescere insieme alla colonna invece di ritagliarne una
                        fetta fissa — su un monitor grande arriva oltre i 200 px di prima, su un
                        portatile scende senza schiacciare le rate. Il pavimento a 140 px tiene
                        leggibili l'intestazione e le prime voci.
                    -->
                    <div
                        class="bg-slate-900 text-white rounded-xl border border-slate-700 shadow-sm flex flex-col shrink-0 overflow-hidden transition-all"
                        :class="previewContabile.hasData ? 'h-[200px] lg:h-[30%] lg:min-h-[140px]' : 'h-auto'"
                    >
                        <div class="p-3 border-b border-slate-700 flex justify-between items-center bg-slate-800/50 shrink-0">
                            <h3 class="font-semibold text-sm flex items-center">
                                <Receipt class="w-4 h-4 mr-2 text-emerald-400"/> Anteprima registrazione
                            </h3>
                            <div class="flex gap-4 text-xs">
                                <div class="flex items-center gap-1.5">
                                    <span class="text-slate-400">Allocato:</span>
                                    <span class="font-bold text-emerald-400">{{ euro(totalAllocato) }}</span>
                                </div>
                                <div v-if="form.eccedenza > 0" class="flex items-center gap-1.5">
                                    <span class="text-slate-400">Eccedenza:</span>
                                    <span class="font-bold text-blue-400">{{ euro(form.eccedenza) }}</span>
                                </div>
                            </div>
                        </div>

                        <!--
                            `v-if` e non `v-show`: da vuoto il corpo non deve occupare nemmeno il
                            proprio padding. Il messaggio «inserisci un importo» scompare con lui —
                            non serviva: la barra del titolo resta, e dice già che quello è il
                            posto dove comparirà l'anteprima.
                        -->
                        <div v-if="previewContabile.hasData" class="flex-1 overflow-y-auto p-4 custom-scrollbar-dark">
                            <div class="space-y-2">
                                <div v-for="riga in previewContabile.righe" :key="riga.id" class="flex justify-between items-start text-xs border-b border-slate-800 pb-2 last:border-0 last:pb-0">
                                    <div class="flex-1 mr-4">
                                        <div class="font-medium text-slate-200">{{ riga.descrizione }}</div>

                                        <div v-if="riga.status === 'PARZIALE'" class="mt-0.5 text-amber-500 text-[10px] font-bold">
                                            Resta da pagare: {{ euro(riga.residuo_futuro) }}
                                        </div>
                                        <div v-else-if="riga.status === 'CREDITO_USATO'" class="mt-0.5 text-blue-400 text-[10px] font-bold">
                                            Credito rimanente nel salvadanaio: {{ euro(riga.residuo_futuro) }}
                                        </div>
                                    </div>

                                    <div class="text-right shrink-0">
                                        <div class="font-bold" :class="riga.isCredito ? 'text-blue-400' : 'text-white'">
                                            {{ euro(riga.pagato) }}
                                        </div>

                                        <span v-if="riga.status === 'SALDATA'" class="text-[9px] text-emerald-500 uppercase font-bold tracking-wider">Saldata</span>
                                        <span v-else-if="riga.status === 'ESAURITO'" class="text-[9px] text-slate-400 uppercase font-bold tracking-wider">Esaurito</span>
                                        <span v-else-if="riga.status === 'CREDITO_USATO'" class="text-[9px] text-blue-400 uppercase font-bold tracking-wider">Usato parzialmente</span>
                                        <span v-else class="text-[9px] text-amber-500 uppercase font-bold tracking-wider">Parziale</span>
                                    </div>
                                </div>

                                <div v-if="previewContabile.anticipo > 0" class="flex justify-between items-center pt-2 text-xs border-t border-slate-700 mt-2">
                                    <div class="text-blue-400 font-medium">Anticipo / Eccedenza</div>
                                    <div class="font-bold text-blue-400">+ {{ euro(previewContabile.anticipo) }}</div>
                                </div>
                            </div>

                        </div>
                    </div>

                    </div>
                </div>
            </div>
        </div>
    </GestionaleLayout>
</template>

<style scoped>
:deep(.style-chooser .vs__dropdown-toggle) {
    border-color: #e2e8f0;
    padding: 2px 0;
    border-radius: 6px;
    background-color: white;
    min-height: 36px;
}
:deep(.style-chooser .vs__selected) { font-size: 0.8rem; }

.custom-scrollbar::-webkit-scrollbar { width: 5px; }
.custom-scrollbar::-webkit-scrollbar-track { background: transparent; }
.custom-scrollbar::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 10px; }
.custom-scrollbar::-webkit-scrollbar-thumb:hover { background: #94a3b8; }

.custom-scrollbar-dark::-webkit-scrollbar { width: 5px; }
.custom-scrollbar-dark::-webkit-scrollbar-track { background: #1e293b; }
.custom-scrollbar-dark::-webkit-scrollbar-thumb { background: #475569; border-radius: 10px; }
.custom-scrollbar-dark::-webkit-scrollbar-thumb:hover { background: #64748b; }
</style>
