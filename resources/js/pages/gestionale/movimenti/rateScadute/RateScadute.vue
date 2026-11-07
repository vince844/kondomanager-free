<script setup lang="ts">
/**
 * La pagina «Rate scadute» del condominio: una riga per persona con lo scaduto (lordo: il credito accanto, mai tolto), le fasce, il ritardo
 * più vecchio in parole, la data più vicina dei sei mesi con la dispensa se c'è; aperta, la riga mostra le rate con le due date. La vista
 * «In scadenza nei prossimi N giorni» è la stessa pagina. I filtri stanno nell'indirizzo e non si ricordano, come nelle altre pagine; la
 * scelta delle righe è una lista o «tutte le N dei filtri», che il server rilegge per la stampa.
 */
import { computed, ref, watch } from 'vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import GestionaleLayout from '@/layouts/GestionaleLayout.vue';
import MovimentiLayout from '@/layouts/gestionale/MovimentiLayout.vue';
import PageHeaderGuide from '@/components/PageHeaderGuide.vue';
import Alert from '@/components/Alert.vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { usePermission } from '@/composables/permissions';
import { AlertTriangle, ChevronDown, ChevronRight, Mail, Printer, X } from 'lucide-vue-next';
import axios from 'axios';
import LettereDialog from '@/components/gestionale/lettere/LettereDialog.vue';
import AccordoRientroDialog from '@/components/gestionale/accordi/AccordoRientroDialog.vue';
import { testoParteInAccordo, testoSegnoAccordo, type AccordoRientro, type ParteInAccordo } from '@/lib/gestionale/debiti/accordiRientro';
import { CANALI_SPEDIZIONE, testoRiprova, testoUltimaLettera, type UltimaLettera } from '@/lib/gestionale/lettere/lettere';
import { frasePerErrore } from '@/lib/gestionale/incassi/ricevute';
import { alterna, quanteSelezionate, selezionaPagina, selezioneVuota, tutteDeiFiltri, type Selezione } from '@/lib/gestionale/incassi/ricevute';
import {
    FRASE_DATA_PASSATA, FRASE_RISERVATEZZA, NOTA_PREGRESSO_NELLA_RATA, RIGA_TESORERIA, euroTesto, giorno, testoData, testoDispensa, testoGiorniDellaRata,
    testoNonEmesse, testoSeiMesiDellaPersona, testoTotale, titoloVista, type NonEmesse, type SeiMesiDellaRata,
} from '@/lib/gestionale/debiti/rateScadute';
import type { Flash } from '@/types/flash';

interface Rata {
    rata_id: number; numero_rata: number; piano: string; unita: string | null; scadenza: string; residuo: number; giorni_ritardo: number | null;
    pregresso: { origine: string; etichetta: string } | null; pregresso_nella_rata: boolean; sei_mesi: SeiMesiDellaRata | null;
    accordo?: ParteInAccordo | null;
}
interface Riga {
    anagrafica_id: number; nome: string; unita: string[]; importo: number; credito: number | null; rate: Rata[];
    fasce?: { '1_30': number; '31_90': number; oltre_90: number }; ritardo_testo?: string; prima_scadenza?: string;
    sei_mesi?: { data: string | null; dispensa: any; mancano: number }; con_pregresso?: boolean; origine_non_dichiarata?: boolean;
    ultima_lettera?: UltimaLettera | null; lettera_in_sospeso?: UltimaLettera | null; accordi?: AccordoRientro[];
}
interface Filtri {
    alla: string; vista: string; giorni: number; cerca: string | null; anagrafica_id: number | null; fascia: string | null; minimo: number | null;
    gestione_id: number | null; sei_mesi_entro: number | null; con_pregresso: boolean; ordine: string;
    senza_lettere_da?: number | null; mai_scritto?: boolean; da_spedire?: boolean; con_accordo?: boolean;
}

const props = defineProps<{
    condominio: { id: number; nome: string }; condomini: any; esercizio: any;
    righe: { data: Riga[]; meta: { current_page: number; last_page: number; per_page: number; total: number; from: number | null; to: number | null } };
    totale: number; persone_totali: number; filtri: Filtri; oggi: string; non_emesse: NonEmesse | null;
    gestioni: Array<{ id: number; nome: string }>; persona_della_porta: string | null; testo_norma: string; frase_festivo?: string; frase_sei_mesi?: string;
    lettere_da_riprovare?: number; lettere_in_coda_da_tempo?: number;
}>();

const { generateRoute, generatePath } = usePermission();
const page = usePage<{ flash?: { message?: Flash }; paginazione?: { consentite?: number[] } }>();
const flashMessage = computed(() => page.props.flash?.message);

const inScadenza = computed(() => props.filtri.vista === 'in_scadenza');
const allaDiOggi = computed(() => props.filtri.alla === props.oggi);
const titolo = computed(() => titoloVista(props.filtri.vista, props.filtri.giorni));

// ── I filtri ────────────────────────────────────────────────────────────
const f = ref({
    alla: props.filtri.alla,
    giorni: String(props.filtri.giorni),
    cerca: props.filtri.cerca ?? '',
    fascia: props.filtri.fascia ?? '',
    minimo: props.filtri.minimo ? String(props.filtri.minimo / 100).replace('.', ',') : '',
    gestione_id: props.filtri.gestione_id ? String(props.filtri.gestione_id) : '',
    sei_mesi_entro: props.filtri.sei_mesi_entro !== null ? String(props.filtri.sei_mesi_entro) : '',
    con_pregresso: props.filtri.con_pregresso,
    ordine: props.filtri.ordine,
    senza_lettere_da: props.filtri.senza_lettere_da ? String(props.filtri.senza_lettere_da) : '',
    mai_scritto: !!props.filtri.mai_scritto,
    da_spedire: !!props.filtri.da_spedire,
    con_accordo: !!props.filtri.con_accordo,
});
const parametri = (vista = props.filtri.vista) => ({
    condominio: props.condominio.id,
    vista,
    alla: f.value.alla && f.value.alla !== props.oggi ? f.value.alla : undefined,
    giorni: vista === 'in_scadenza' ? f.value.giorni : undefined,
    cerca: f.value.cerca || undefined,
    anagrafica_id: props.filtri.anagrafica_id ?? undefined,
    fascia: f.value.fascia || undefined,
    minimo: f.value.minimo || undefined,
    gestione_id: f.value.gestione_id || undefined,
    sei_mesi_entro: f.value.sei_mesi_entro || undefined,
    con_pregresso: f.value.con_pregresso ? 1 : undefined,
    ordine: f.value.ordine !== 'scaduto' ? f.value.ordine : undefined,
    senza_lettere_da: f.value.senza_lettere_da || undefined,
    mai_scritto: f.value.mai_scritto ? 1 : undefined,
    da_spedire: f.value.da_spedire ? 1 : undefined,
    con_accordo: f.value.con_accordo ? 1 : undefined,
    per_page: props.righe.meta.per_page,
});
function applica(paginaN?: number, vista?: string) {
    router.get(route(generateRoute('gestionale.rate-scadute.index'), { ...parametri(vista), page: paginaN }), {}, { preserveState: true, preserveScroll: true, replace: true });
}
// Le righe per pagina, come nelle altre tabelle del programma: i valori del server, con dentro quello in uso; la scelta torna alla pagina 1 e il
// server la ricorda per chi lavora (`PaginaElenco`).
const righePerPagina = computed<number[]>(() => {
    const consentite = page.props.paginazione?.consentite ?? [10, 15, 20, 30, 40, 50, 100];
    const inUso = props.righe.meta.per_page;

    return consentite.includes(inUso) ? consentite : [...consentite, inUso].sort((a, b) => a - b);
});
function cambiaRighePerPagina(valore: unknown) {
    const n = Number(valore);
    if (!Number.isInteger(n) || n === props.righe.meta.per_page) {
        return;
    }
    router.get(route(generateRoute('gestionale.rate-scadute.index'), { ...parametri(), per_page: n }), {}, { preserveState: true, preserveScroll: true, replace: true });
}
let attesa: ReturnType<typeof setTimeout> | null = null;
watch(f, () => {
    selezione.value = selezioneVuota();
    if (attesa) clearTimeout(attesa);
    attesa = setTimeout(() => applica(), 350);
}, { deep: true });
const cambiaVista = (vista: string) => { selezione.value = selezioneVuota(); applica(undefined, vista); };
const togliPersona = () => router.get(route(generateRoute('gestionale.rate-scadute.index'), { ...parametri(), anagrafica_id: undefined }));

// ── La selezione e la stampa ────────────────────────────────────────────
const selezione = ref<Selezione>(selezioneVuota());
const idsPagina = computed(() => props.righe.data.map((r) => r.anagrafica_id));
const quante = computed(() => quanteSelezionate(selezione.value, props.persone_totali));
const spuntata = (id: number) => selezione.value.tutte || selezione.value.ids.includes(id);
const paginaSpuntata = computed(() => idsPagina.value.length > 0 && idsPagina.value.every(spuntata));
function indirizzoStampa(): string {
    const { per_page: _p, ...resto } = parametri();
    const scelta = quante.value > 0 && !selezione.value.tutte ? { persone: selezione.value.ids } : { tutte: 1 };

    return route(generateRoute('gestionale.rate-scadute.stampa'), { ...resto, ...scelta });
}
const stampa = () => window.open(indirizzoStampa(), '_blank', 'noopener');

// ── Le lettere ──────────────────────────────────────────────────────────
const finestraLettere = ref(false);
const personaDellaLettera = ref<{ id: number; nome: string } | null>(null);
const selezioneDelleLettere = ref<Selezione | null>(null);
/** I filtri come li legge il server per «tutte le N dei filtri». */
const filtriDelServer = () => {
    const { condominio: _c, per_page: _p, ...resto } = parametri();

    return resto;
};
const preparaLettere = () => {
    personaDellaLettera.value = null;
    selezioneDelleLettere.value = quante.value > 0 && !selezione.value.tutte ? { ...selezione.value } : { tutte: true, ids: [] };
    finestraLettere.value = true;
};
const scriviA = (r: Riga) => {
    personaDellaLettera.value = { id: r.anagrafica_id, nome: r.nome };
    selezioneDelleLettere.value = null;
    finestraLettere.value = true;
};
const ricaricaDopoLeLettere = () => router.reload({ only: ['righe', 'lettere_da_riprovare', 'lettere_in_coda_da_tempo'] });

// ── L'accordo di rientro, dalla riga ────────────────────────────────────
const finestraAccordo = ref(false);
const personaDellAccordo = ref<{ id: number; nome: string } | null>(null);
const apriAccordo = (r: Riga) => {
    personaDellAccordo.value = { id: r.anagrafica_id, nome: r.nome };
    finestraAccordo.value = true;
};
const ricaricaDopoLAccordo = () => router.reload({ only: ['righe', 'totale', 'persone_totali'] });
const esitoRiprova = ref<string | null>(null);
async function riprovaLettere() {
    try {
        const r = (await axios.post(route(generateRoute('gestionale.lettere.riprova'), { condominio: props.condominio.id }))).data;
        esitoRiprova.value = testoRiprova(r);
        ricaricaDopoLeLettere();
    } catch (e) {
        esitoRiprova.value = frasePerErrore(e, 'Le lettere non sono state rifatte. Riprova.');
    }
}

// ── La spedizione in blocco, il giorno dopo la stampa ───────────────────
// Con il filtro «Stampate, spedizione da registrare»: le lettere da spedire delle persone scelte in questa pagina, con lo stesso canale e la
// stessa data e il numero di ognuna (la rotta è quella del registro). Le persone delle altre pagine si vedono con più righe per pagina.
const pannelloSpedizione = ref(false);
const spedizione = ref({ canale: '', data: props.oggi, nota: '', numeri: {} as Record<number, string> });
const esitoSpedizione = ref<string | null>(null);
const daSpedire = computed(() => props.righe.data.filter((r) => spuntata(r.anagrafica_id))
    .flatMap((r) => ((r.ultima_lettera as (UltimaLettera & { invii?: number[] }) | null | undefined)?.invii ?? []).map((invio) => ({ invio, nome: r.nome }))));
const altrePagine = computed(() => (selezione.value.tutte && props.persone_totali > props.righe.data.length ? props.persone_totali - props.righe.data.length : 0));
const apriSpedizione = () => {
    spedizione.value = { canale: '', data: props.oggi, nota: '', numeri: {} };
    esitoSpedizione.value = null;
    pannelloSpedizione.value = true;
};
async function registraSpedizione() {
    try {
        const r = (await axios.post(route(generateRoute('gestionale.lettere.spedizione'), { condominio: props.condominio.id }), {
            invii: daSpedire.value.map((d) => d.invio), canale: spedizione.value.canale, data: spedizione.value.data, nota: spedizione.value.nota || null,
            numeri: spedizione.value.numeri,
        })).data;
        esitoSpedizione.value = r.spedite === 1 ? 'Spedizione registrata per 1 lettera.' : `Spedizione registrata per ${r.spedite} lettere.`;
        pannelloSpedizione.value = false;
        selezione.value = selezioneVuota();
        ricaricaDopoLeLettere();
    } catch (e) {
        esitoSpedizione.value = frasePerErrore(e, 'La spedizione non è stata registrata. Riprova.');
    }
}

// ── Le righe aperte ─────────────────────────────────────────────────────
const aperte = ref<Set<number>>(new Set());
const apriChiudi = (id: number) => {
    const s = new Set(aperte.value);
    s.has(id) ? s.delete(id) : s.add(id);
    aperte.value = s;
};

const estrattoConto = (id: number) => route(generateRoute('gestionale.anagrafiche.estratto-conto'), { condominio: props.condominio.id, anagrafica: id });
const indirizzoEsercizi = computed(() => route(generateRoute('gestionale.esercizi.index'), { condominio: props.condominio.id }));
// «Dichiarala dai saldi»: la pagina dei saldi apre «Dichiara l'origine» con i saldi di quella persona già scelti.
const indirizzoSaldi = (persona: number) => route(generateRoute('gestionale.saldi.index'), { condominio: props.condominio.id, origine_di: persona });
const indirizzoDispense = computed(() => route(generateRoute('gestionale.dispense.index'), { condominio: props.condominio.id }));
const indirizzoPiani = computed(() => props.esercizio
    ? route(generateRoute('gestionale.esercizi.piani-rate.index'), { condominio: props.condominio.id, esercizio: props.esercizio.id })
    : indirizzoEsercizi.value);
const frasNonEmesse = computed(() => testoNonEmesse(props.non_emesse));
const mancaEsercizio = (r: Rata) => r.sei_mesi?.dalla_scadenza.stato === 'manca_esercizio' || r.sei_mesi?.dalla_competenza.stato === 'manca_esercizio';

const breadcrumbs = computed(() => [
    { title: 'Gestionale', href: generatePath('gestionale/:condominio', { condominio: props.condominio.id }) },
    { title: 'Movimenti', href: route(generateRoute('gestionale.movimenti-rate.index'), { condominio: props.condominio.id }) },
    { title: titolo.value },
]);
</script>

<template>
    <Head :title="titolo" />
    <GestionaleLayout>
        <div class="px-6 py-8 space-y-4">
            <PageHeaderGuide
                :page-title="titolo"
                page-subtitle="Chi deve ancora pagare, persona per persona: lo scaduto, le fasce, il ritardo e le date dei sei mesi."
                :guides="[]"
                :breadcrumbs="(breadcrumbs as any)"
                :condominio="(props.condominio as any)"
                :condomini="(props.condomini as any)"
            />
            <MovimentiLayout>
                <div class="space-y-3">
                    <p data-testid="riga-tesoreria" class="text-xs text-slate-500 dark:text-slate-400">{{ RIGA_TESORERIA }}</p>

                    <div v-if="flashMessage">
                        <Alert :message="flashMessage.message" :type="flashMessage.type" />
                    </div>

                    <!-- La vista -->
                    <div class="flex flex-wrap items-center gap-2 text-sm">
                        <Button size="sm" :variant="inScadenza ? 'outline' : 'default'" data-testid="vista-scadute" @click="cambiaVista('scadute')">Rate scadute</Button>
                        <Button size="sm" :variant="inScadenza ? 'default' : 'outline'" data-testid="vista-in-scadenza" @click="cambiaVista('in_scadenza')">
                            In scadenza nei prossimi {{ filtri.giorni }} giorni
                        </Button>
                        <label v-if="inScadenza" class="flex items-center gap-1 text-xs">
                            giorni <Input v-model="f.giorni" type="number" min="1" max="366" class="h-8 w-20 text-xs" />
                        </label>
                    </div>

                    <p v-if="persona_della_porta" class="flex flex-wrap items-center gap-2 text-xs">
                        Solo {{ persona_della_porta }}.
                        <Button size="sm" variant="ghost" class="h-7 text-xs" @click="togliPersona"><X class="w-3.5 h-3.5 mr-1" /> Tutte le persone</Button>
                    </p>

                    <!-- I filtri -->
                    <div class="flex flex-wrap items-end gap-3 rounded-xl border bg-white dark:bg-slate-900 p-3 text-xs">
                        <label class="space-y-1">
                            <span class="block text-slate-500 dark:text-slate-400">Elenco al</span>
                            <Input v-model="f.alla" type="date" :max="oggi" class="h-8 w-[150px] text-xs" data-testid="filtro-alla" />
                        </label>
                        <label class="space-y-1">
                            <span class="block text-slate-500 dark:text-slate-400">Persona</span>
                            <Input v-model="f.cerca" placeholder="Cerca per nome" class="h-8 w-[180px] text-xs" />
                        </label>
                        <label v-if="!inScadenza" class="space-y-1">
                            <span class="block text-slate-500 dark:text-slate-400">Fascia</span>
                            <select v-model="f.fascia" class="h-8 rounded-md border px-2 bg-transparent">
                                <option value="">Tutte</option>
                                <option value="1_30">Ha qualcosa a 1–30 giorni</option>
                                <option value="31_90">Ha qualcosa a 31–90 giorni</option>
                                <option value="oltre_90">Ha qualcosa oltre 90 giorni</option>
                            </select>
                        </label>
                        <label class="space-y-1">
                            <span class="block text-slate-500 dark:text-slate-400">Importo minimo (€)</span>
                            <Input v-model="f.minimo" inputmode="decimal" placeholder="0,00" class="h-8 w-[110px] text-xs" />
                        </label>
                        <label class="space-y-1">
                            <span class="block text-slate-500 dark:text-slate-400">Gestione</span>
                            <select v-model="f.gestione_id" class="h-8 rounded-md border px-2 bg-transparent">
                                <option value="">Tutte</option>
                                <option v-for="g in gestioni" :key="g.id" :value="String(g.id)">{{ g.nome }}</option>
                            </select>
                        </label>
                        <label v-if="!inScadenza" class="space-y-1">
                            <span class="block text-slate-500 dark:text-slate-400">Data dei sei mesi entro (giorni)</span>
                            <Input v-model="f.sei_mesi_entro" type="number" min="0" class="h-8 w-[110px] text-xs" />
                        </label>
                        <label v-if="!inScadenza" class="flex items-center gap-2 h-8">
                            <Checkbox :model-value="f.con_pregresso" @update:model-value="(v: any) => (f.con_pregresso = !!v)" /> Con pregresso
                        </label>
                        <label class="space-y-1">
                            <span class="block text-slate-500 dark:text-slate-400">Nessuna lettera negli ultimi (giorni)</span>
                            <Input v-model="f.senza_lettere_da" type="number" min="1" class="h-8 w-[110px] text-xs" data-testid="filtro-senza-lettere" />
                        </label>
                        <label class="flex items-center gap-2 h-8" data-testid="filtro-mai-scritto">
                            <Checkbox :model-value="f.mai_scritto" @update:model-value="(v: any) => (f.mai_scritto = !!v)" /> Mai scritto (contano le lettere partite o spedite)
                        </label>
                        <label class="flex items-center gap-2 h-8" data-testid="filtro-da-spedire">
                            <Checkbox :model-value="f.da_spedire" @update:model-value="(v: any) => (f.da_spedire = !!v)" /> Stampate o scaricate, spedizione da registrare
                        </label>
                        <label class="flex items-center gap-2 h-8" data-testid="filtro-con-accordo">
                            <Checkbox :model-value="f.con_accordo" @update:model-value="(v: any) => (f.con_accordo = !!v)" /> Con accordo di rientro
                        </label>
                        <label v-if="!inScadenza" class="space-y-1">
                            <span class="block text-slate-500 dark:text-slate-400">Ordine</span>
                            <select v-model="f.ordine" class="h-8 rounded-md border px-2 bg-transparent">
                                <option value="scaduto">Per scaduto, dal più alto</option>
                                <option value="sei_mesi">Per data dei sei mesi, dalla più vicina</option>
                            </select>
                        </label>
                    </div>

                    <p v-if="(lettere_da_riprovare ?? 0) > 0" data-testid="lettere-da-riprovare" class="flex flex-wrap items-center gap-2 rounded-lg border border-amber-200 dark:border-amber-900 bg-amber-50 dark:bg-amber-950/40 p-2 text-xs text-amber-900 dark:text-amber-100">
                        {{ lettere_da_riprovare === 1 ? '1 lettera per email non è partita.' : `${lettere_da_riprovare} lettere per email non sono partite.` }}
                        <Button size="sm" variant="outline" class="h-7 text-xs" @click="riprovaLettere">{{ lettere_da_riprovare === 1 ? 'Rifalla con le cifre di oggi' : 'Rifalle con le cifre di oggi' }}</Button>
                    </p>
                    <p v-if="(lettere_in_coda_da_tempo ?? 0) > 0" data-testid="lettere-in-coda" class="text-xs text-amber-900 dark:text-amber-100">
                        {{ lettere_in_coda_da_tempo === 1 ? '1 lettera per email è in coda da più di un\'ora: non è ancora partita.' : `${lettere_in_coda_da_tempo} lettere per email sono in coda da più di un'ora: non sono ancora partite.` }}
                        Di solito vuol dire che sul server non gira il servizio che manda le email in coda: chi gestisce l'installazione controlla che sia attivo
                        (SCHEDULE_QUEUE_WORKER nel file .env, o un processo queue:work).
                    </p>
                    <p v-if="esitoRiprova" class="text-xs">{{ esitoRiprova }}</p>
                    <p v-if="esitoSpedizione" class="text-xs" data-testid="esito-spedizione">{{ esitoSpedizione }}</p>
                    <p v-if="!allaDiOggi" data-testid="frase-data-passata" class="text-xs text-slate-600 dark:text-slate-300">{{ FRASE_DATA_PASSATA }}</p>
                    <p v-if="frasNonEmesse" data-testid="riga-non-emesse" class="flex flex-wrap items-start gap-2 rounded-lg border border-amber-200 dark:border-amber-900 bg-amber-50 dark:bg-amber-950/40 p-2 text-xs text-amber-900 dark:text-amber-100">
                        <AlertTriangle class="w-4 h-4 shrink-0" />
                        <span>{{ frasNonEmesse }} <Link :href="indirizzoPiani" class="underline">Vai ai piani rate</Link></span>
                    </p>

                    <!-- Il totale, la scelta e la stampa -->
                    <div class="flex flex-wrap items-center justify-between gap-2 text-sm">
                        <div class="flex flex-wrap items-center gap-2">
                            <span data-testid="totale">{{ testoTotale({ persone: persone_totali, totale, vista: filtri.vista }) }}</span>
                            <span v-if="quante > 0" class="font-medium">Selezionate: {{ quante }}.</span>
                            <Button v-if="!selezione.tutte && persone_totali > 1" size="sm" variant="ghost" class="h-7 text-xs" @click="selezione = tutteDeiFiltri(selezione)">
                                Seleziona tutte le {{ persone_totali }} dei filtri
                            </Button>
                            <Button v-if="quante > 0" size="sm" variant="ghost" class="h-7 text-xs" @click="selezione = selezioneVuota()"><X class="w-3.5 h-3.5 mr-1" /> Togli la scelta</Button>
                        </div>
                        <div class="flex flex-wrap gap-2">
                            <Button size="sm" :disabled="persone_totali === 0" data-testid="prepara-lettere" @click="preparaLettere">
                                <Mail class="w-4 h-4 mr-2" /> {{ quante > 0 && !selezione.tutte ? (quante === 1 ? 'Prepara la lettera alla selezionata' : 'Prepara le lettere alle selezionate')
                                    : (persone_totali === 1 ? 'Prepara la lettera' : `Prepara le lettere a tutte le ${persone_totali}`) }}
                            </Button>
                            <Button size="sm" variant="outline" :disabled="persone_totali === 0" data-testid="stampa" @click="stampa">
                                <Printer class="w-4 h-4 mr-2" /> {{ quante > 0 && !selezione.tutte ? 'Stampa le selezionate' : 'Stampa l\'elenco' }}
                            </Button>
                            <Button v-if="filtri.da_spedire" size="sm" variant="outline" :disabled="daSpedire.length === 0" data-testid="spedizione-in-blocco" @click="apriSpedizione">
                                Registra la spedizione
                            </Button>
                            <Link v-if="!inScadenza" :href="indirizzoDispense" class="inline-flex items-center h-8 px-3 rounded-md border text-xs">Dispense dei sei mesi</Link>
                        </div>
                    </div>

                    <div v-if="pannelloSpedizione" class="space-y-2 rounded-lg border p-2 text-xs" data-testid="pannello-spedizione">
                        <p class="font-medium">Registra la spedizione {{ daSpedire.length === 1 ? 'della lettera' : `delle ${daSpedire.length} lettere` }}: lo stesso canale e la stessa data, il numero di ognuna</p>
                        <p v-if="altrePagine > 0">Sono {{ persone_totali }} persone: in questa pagina ne vedi {{ righe.data.length }}. Per registrarle tutte insieme scegli più righe per pagina; o registra queste, e poi le altre.</p>
                        <div class="flex flex-wrap gap-2">
                            <select v-model="spedizione.canale" class="h-8 rounded-md border px-2 bg-transparent">
                                <option value="" disabled>Come</option>
                                <option v-for="(nome, c) in CANALI_SPEDIZIONE" :key="c" :value="c">{{ nome }}</option>
                            </select>
                            <Input v-model="spedizione.data" type="date" :max="oggi" class="h-8 w-[150px] text-xs" />
                            <Input v-model="spedizione.nota" placeholder="Nota" class="h-8 w-[200px] text-xs" />
                        </div>
                        <div v-for="d in daSpedire" :key="d.invio" class="flex items-center gap-2">
                            <span class="w-40 truncate">{{ d.nome }}</span>
                            <Input v-model="spedizione.numeri[d.invio]" placeholder="Numero o estremi" class="h-7 w-[200px] text-xs" :data-testid="`numero-${d.invio}`" />
                        </div>
                        <div class="flex gap-2">
                            <Button size="sm" variant="outline" :disabled="!spedizione.canale || daSpedire.length === 0" data-testid="registra-spedizione-blocco" @click="registraSpedizione">Registra la spedizione</Button>
                            <Button size="sm" variant="ghost" @click="pannelloSpedizione = false">Annulla</Button>
                        </div>
                    </div>

                    <!-- La tabella -->
                    <div class="overflow-x-auto rounded-xl border bg-white dark:bg-slate-900">
                        <table class="w-full text-xs">
                            <thead class="bg-slate-50 dark:bg-slate-800/60 text-slate-500 dark:text-slate-400">
                                <tr>
                                    <th class="p-2 w-8"><Checkbox :model-value="paginaSpuntata" :disabled="selezione.tutte" @update:model-value="selezione = selezionaPagina(selezione, idsPagina)" /></th>
                                    <th class="p-2 w-6"></th>
                                    <th class="p-2 text-left">Persona</th>
                                    <th class="p-2 text-left">Unità</th>
                                    <th class="p-2 text-right">{{ inScadenza ? 'In scadenza' : 'Scaduto' }}</th>
                                    <template v-if="!inScadenza">
                                        <th class="p-2 text-right">1–30 giorni</th>
                                        <th class="p-2 text-right">31–90 giorni</th>
                                        <th class="p-2 text-right">oltre 90 giorni</th>
                                        <th class="p-2 text-left">Ritardo</th>
                                        <th class="p-2 text-left">Data dei sei mesi più vicina</th>
                                    </template>
                                    <th v-else class="p-2 text-left">Prima scadenza</th>
                                    <th v-if="allaDiOggi" class="p-2 text-right">Credito</th>
                                    <th class="p-2 text-left">Ultima lettera</th>
                                </tr>
                            </thead>
                            <tbody>
                                <template v-for="r in righe.data" :key="r.anagrafica_id">
                                    <tr class="border-t" data-testid="riga-persona">
                                        <td class="p-2"><Checkbox :model-value="spuntata(r.anagrafica_id)" @update:model-value="selezione = alterna(selezione, r.anagrafica_id)" /></td>
                                        <td class="p-2">
                                            <button type="button" :aria-label="aperte.has(r.anagrafica_id) ? 'Chiudi le rate' : 'Apri le rate'" @click="apriChiudi(r.anagrafica_id)">
                                                <ChevronDown v-if="aperte.has(r.anagrafica_id)" class="w-4 h-4" /><ChevronRight v-else class="w-4 h-4" />
                                            </button>
                                        </td>
                                        <td class="p-2">
                                            <Link :href="estrattoConto(r.anagrafica_id)" class="font-medium hover:underline">{{ r.nome }}</Link>
                                            <span v-if="r.origine_non_dichiarata" class="block text-[11px] text-amber-700 dark:text-amber-400">
                                                pregresso con origine non dichiarata — <Link :href="indirizzoSaldi(r.anagrafica_id)" class="underline">dichiarala dai saldi</Link>
                                            </span>
                                            <span v-for="a in r.accordi ?? []" :key="a.id" class="block text-[11px] text-sky-800 dark:text-sky-300" data-testid="segno-accordo">
                                                {{ testoSegnoAccordo(a) }}
                                            </span>
                                        </td>
                                        <td class="p-2">{{ r.unita.join(', ') }}</td>
                                        <td class="p-2 text-right font-semibold whitespace-nowrap">{{ euroTesto(r.importo) }}</td>
                                        <template v-if="!inScadenza && r.fasce">
                                            <td class="p-2 text-right whitespace-nowrap">{{ r.fasce['1_30'] ? euroTesto(r.fasce['1_30']) : '' }}</td>
                                            <td class="p-2 text-right whitespace-nowrap">{{ r.fasce['31_90'] ? euroTesto(r.fasce['31_90']) : '' }}</td>
                                            <td class="p-2 text-right whitespace-nowrap">{{ r.fasce.oltre_90 ? euroTesto(r.fasce.oltre_90) : '' }}</td>
                                            <td class="p-2">{{ r.ritardo_testo }}</td>
                                            <td class="p-2">{{ r.sei_mesi ? testoSeiMesiDellaPersona(r.sei_mesi) : '' }}</td>
                                        </template>
                                        <td v-else class="p-2">{{ giorno(r.prima_scadenza) }}</td>
                                        <td v-if="allaDiOggi" class="p-2 text-right whitespace-nowrap text-sky-700 dark:text-sky-400">{{ (r.credito ?? 0) > 0 ? euroTesto(r.credito ?? 0) : '' }}</td>
                                        <td class="p-2" data-testid="ultima-lettera">
                                            {{ testoUltimaLettera(r.ultima_lettera) }}
                                            <span v-if="r.lettera_in_sospeso" class="block text-[11px] text-amber-700 dark:text-amber-400">poi: {{ testoUltimaLettera(r.lettera_in_sospeso) }}</span>
                                            <button type="button" class="block text-[11px] underline" :data-testid="`scrivi-${r.anagrafica_id}`" @click="scriviA(r)">Prepara una lettera</button>
                                            <button v-if="!inScadenza" type="button" class="block text-[11px] underline" :data-testid="`accordo-${r.anagrafica_id}`" @click="apriAccordo(r)">
                                                {{ (r.accordi ?? []).length ? 'Accordo di rientro' : 'Registra un accordo di rientro' }}
                                            </button>
                                        </td>
                                    </tr>
                                    <tr v-if="aperte.has(r.anagrafica_id)" class="bg-slate-50/60 dark:bg-slate-800/30">
                                        <td></td>
                                        <td :colspan="allaDiOggi ? 12 : 11" class="p-2">
                                            <table class="w-full text-[11px]">
                                                <thead class="text-slate-500">
                                                    <tr>
                                                        <th class="p-1 text-left">Unità</th><th class="p-1 text-left">Piano</th><th class="p-1 text-left">Rata</th>
                                                        <th class="p-1 text-left">Scadenza</th><th class="p-1 text-right">Residuo</th><th class="p-1 text-left">Ritardo</th>
                                                        <template v-if="!inScadenza"><th class="p-1 text-left">Dalla scadenza</th><th class="p-1 text-left">Dalla competenza</th></template>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <tr v-for="rata in r.rate" :key="`${rata.rata_id}-${rata.unita}`" class="border-t align-top">
                                                        <td class="p-1">{{ rata.unita }}</td>
                                                        <td class="p-1">{{ rata.piano }}</td>
                                                        <td class="p-1">
                                                            {{ rata.numero_rata === 0 ? 'Rata 0 (pregresso)' : `Rata ${rata.numero_rata}` }}
                                                            <span v-if="rata.pregresso_nella_rata" class="block text-slate-500">{{ NOTA_PREGRESSO_NELLA_RATA }}</span>
                                                        </td>
                                                        <td class="p-1">{{ giorno(rata.scadenza) }}</td>
                                                        <td class="p-1 text-right whitespace-nowrap">
                                                            {{ euroTesto(rata.residuo) }}
                                                            <span v-if="rata.accordo" class="block text-left whitespace-normal text-sky-800 dark:text-sky-300" data-testid="parte-in-accordo">{{ testoParteInAccordo(rata.accordo) }}</span>
                                                        </td>
                                                        <td class="p-1">{{ inScadenza ? '' : testoGiorniDellaRata(rata) }}</td>
                                                        <template v-if="!inScadenza && rata.sei_mesi">
                                                            <td class="p-1" :class="{ 'font-semibold': rata.sei_mesi.piu_vicina && rata.sei_mesi.piu_vicina === rata.sei_mesi.dalla_scadenza.data }">
                                                                {{ testoData(rata.sei_mesi.dalla_scadenza, rata.scadenza) }}
                                                            </td>
                                                            <td class="p-1" :class="{ 'font-semibold': !rata.sei_mesi.coincidono && rata.sei_mesi.piu_vicina && rata.sei_mesi.piu_vicina === rata.sei_mesi.dalla_competenza.data && !rata.sei_mesi.dalla_competenza.prima_della_scadenza }">
                                                                {{ rata.sei_mesi.coincidono ? 'la stessa' : testoData(rata.sei_mesi.dalla_competenza, rata.scadenza, rata.sei_mesi.dalla_scadenza) }}
                                                            </td>
                                                        </template>
                                                    </tr>
                                                </tbody>
                                            </table>
                                            <p v-if="r.rate.some(mancaEsercizio)" class="mt-1 text-[11px]">
                                                <Link :href="indirizzoEsercizi" data-testid="collegamento-esercizi" class="underline">Vai all'elenco degli esercizi</Link>
                                            </p>
                                            <p v-if="r.sei_mesi?.dispensa" class="mt-1 text-[11px]">{{ testoDispensa(r.sei_mesi.dispensa) }}</p>
                                        </td>
                                    </tr>
                                </template>
                                <tr v-if="!righe.data.length">
                                    <td colspan="13" class="p-6 text-center text-slate-500">{{ inScadenza ? 'Nessuna rata in scadenza con questi filtri.' : 'Nessuna persona con rate scadute con questi filtri.' }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <div v-if="righe.data.length" class="flex flex-wrap items-center justify-end gap-2 text-sm">
                        <span class="text-xs text-slate-500 dark:text-slate-400">Righe per pagina</span>
                        <Select :model-value="righe.meta.per_page" @update:model-value="cambiaRighePerPagina">
                            <SelectTrigger class="h-8 w-[70px]">
                                <SelectValue :placeholder="`${righe.meta.per_page}`" />
                            </SelectTrigger>
                            <SelectContent side="top">
                                <SelectItem v-for="n in righePerPagina" :key="n" :value="n">{{ n }}</SelectItem>
                            </SelectContent>
                        </Select>
                        <template v-if="righe.meta.last_page > 1">
                            <Button size="sm" variant="outline" :disabled="righe.meta.current_page <= 1" @click="applica(righe.meta.current_page - 1)">Precedente</Button>
                            <span>Pagina {{ righe.meta.current_page }} di {{ righe.meta.last_page }}</span>
                            <Button size="sm" variant="outline" :disabled="righe.meta.current_page >= righe.meta.last_page" @click="applica(righe.meta.current_page + 1)">Successiva</Button>
                        </template>
                    </div>

                    <details v-if="!inScadenza" class="text-[11px] text-slate-500 dark:text-slate-400">
                        <summary class="cursor-pointer">Le due date dei sei mesi</summary>
                        <p class="mt-1">
                            Per ogni rata scaduta: dalla fine dell'esercizio che contiene la sua scadenza, e dalla fine dell'esercizio di competenza del piano
                            (per una gestione straordinaria, quello della delibera), più sei mesi. La più vicina senza una dispensa è in evidenza; una data che viene prima della
                            scadenza della rata non lo è. {{ frase_sei_mesi }} {{ frase_festivo }}
                        </p>
                        <p class="mt-1 italic">{{ testo_norma }}</p>
                    </details>
                    <p class="text-[11px] text-slate-500 dark:text-slate-400">{{ FRASE_RISERVATEZZA }}</p>
                </div>
                <LettereDialog v-model:open="finestraLettere" :condominio-id="condominio.id" :persona="personaDellaLettera" :selezione="selezioneDelleLettere"
                    :filtri="filtriDelServer()" :vista="filtri.vista" :giorni="filtri.giorni" :oggi="oggi" @fatto="ricaricaDopoLeLettere" />
                <AccordoRientroDialog v-model:open="finestraAccordo" :condominio-id="condominio.id" :persona="personaDellAccordo" :oggi="oggi" @fatto="ricaricaDopoLAccordo" />
            </MovimentiLayout>
        </div>
    </GestionaleLayout>
</template>
