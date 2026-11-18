<!--
SPDX-License-Identifier: AGPL-3.0-or-later
Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
-->
<script setup lang="ts">
/**
 * L'Action inbox: i compiti aperti dello studio, di tutti i condomini o di quello scelto (tutta la pagina segue il condominio scelto).
 *
 * In alto l'intestazione («Guida» con il cassetto, il selettore dei condomini con la ricerca, «Dashboard», alti uguali) e le sei card filtro. Sotto, a due
 * terzi, l'elenco dei compiti: le schede «Tutti / Scaduti / In arrivo» con il loro numero, la ricerca e l'ordine; con «Tutti» l'anteprima di ogni gruppo
 * (fino a 8 righe tutte, oltre le prime 5 e «e altri N», che apre la scheda), nelle schede 15 alla volta con «Mostra altri 15» e «Mostrati N di M». A destra,
 * dall'alto: «Da quanto aspettano», «Per tipo», «Sei mesi per agire» (con il cassetto, chiuso di serie), «Dove si accumulano».
 *
 * Tutti i numeri arrivano dal server (`ActionInboxController`): le card, i grafici, le schede e i gruppi, con la regola di «scaduto» di `FasceDeiCompiti`.
 * Ogni filtro (una card, una barra, una scheda, la ricerca, l'ordine, il condominio) è nell'indirizzo. Un filtro dell'elenco rilegge solo l'elenco (le
 * schede, i gruppi, l'anteprima), con la pagina dov'è e un segno di caricamento accanto a «Compiti»; il condominio rilegge la pagina. La casella si legge con
 * «Visualizza scadenze in agenda»; le risposte a un compito chiedono «Modifica scadenza in agenda»: senza, né il menu «⋯» né «Segna come fatto».
 */
import AppLayout from '@/layouts/AppLayout.vue';
import { Head, InfiniteScroll, Link, router, usePage } from '@inertiajs/vue3';
import { computed, defineAsyncComponent, reactive, ref, watch } from 'vue';
import vSelect from 'vue-select';
import 'vue-select/dist/vue-select.css';
import {
    ArrowLeft, Banknote, Building2, CalendarClock, ChevronsUpDown, ClipboardCheck, Hourglass, Inbox, Loader, Search, TriangleAlert, Wrench, X,
} from 'lucide-vue-next';
import { usePermission } from '@/composables/permissions';
import { Permission } from '@/enums/Permission';
import { useGiroFlashDelleVisiteIntere } from '@/composables/useGiroFlash';
import { useSeiMesiDelloStudio } from '@/composables/useSeiMesiDelloStudio';
import Alert from '@/components/Alert.vue';
import { Dialog, DialogClose, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import PulsanteGuida from '@/components/PulsanteGuida.vue';
import SceltaCondominio from '@/components/SceltaCondominio.vue';
import ActionInboxGuide from '@/components/guides/ActionInboxGuide.vue';
import CardDellInbox from '@/components/inbox/CardDellInbox.vue';
import RigaDelCompito from '@/components/inbox/RigaDelCompito.vue';
import RisposteDelCompito from '@/components/inbox/RisposteDelCompito.vue';
import SeiMesiCassetto from '@/components/gestionale/seiMesi/SeiMesiCassetto.vue';
import SeiMesiColonna from '@/components/gestionale/seiMesi/SeiMesiColonna.vue';
import type { VoceDelCompito } from '@/lib/inbox/risposteDelCompito';
import {
    DESCRIZIONE_ETA, ETICHETTA_RICERCA, ORDINI, SCHEDE, SEGNAPOSTO_RICERCA, SOPRATITOLO, TITOLO, descrizioneDellaPagina, descrizioneEta, descrizioneTipi, filtriAttivi, parametri, righeConIGruppi,
    numero, schedaDellaFascia, testoAltriTipi, testoCardSeiMesi, testoEAltri, testoFuoriDalGrafico, testoMostraAltri, testoMostrati, testoScaduti, testoSettimana, titoloDelGruppo,
    type Conteggi, type Dove, type Filtri, type Grafici, type Gruppi, type Gruppo, type GruppoInAnteprima, type RigaCompito, type Schede,
} from '@/lib/inbox/actionInbox';
import { FRASE_NORMA_CARD, contoSeiMesi, type CondominioBreve } from '@/lib/gestionale/debiti/seiMesiDelloStudio';
import type { Flash } from '@/types/flash';

// I grafici con Chart.js si caricano solo qui, a parte.
const GraficoDellInbox = defineAsyncComponent(() => import('@/components/inbox/GraficoDellInbox.vue'));

const props = defineProps<{
    tasks: { data: RigaCompito[]; total?: number };
    counts: Conteggi & { segnalazioni_urgenti?: number };
    grafici: Grafici;
    dove: Dove[];
    schede: Schede;
    gruppi: Gruppi;
    modo: 'anteprima' | 'elenco';
    anteprima: GruppoInAnteprima[];
    filtri: Filtri;
    condomini: CondominioBreve[];
    seiMesiCondomini: CondominioBreve[];
    /** Il giorno dell'amministratore, per «Ricordamelo» (da domani). */
    oggi?: string;
}>();

const { hasPermission } = usePermission();
const puoChiudere = computed(() => hasPermission([Permission.EDIT_EVENTS]));
// Le finestre delle risposte, una sola per la pagina: le aprono il menu «⋯» e «Segna come fatto» di ogni riga.
const risposte = ref<InstanceType<typeof RisposteDelCompito> | null>(null);
const rispondi = (voce: VoceDelCompito, riga: RigaCompito) => risposte.value?.apri(voce, riga);
// Il messaggio dopo una risposta, in alto, una volta: «Mostra altri 15» non lo rimette.
const page = usePage<{ flash: { message?: Flash } }>();
const flashMessage = computed(() => page.props.flash?.message);
const giroFlash = useGiroFlashDelleVisiteIntere();

const mostraGuida = ref(false);
const cassettoAperto = ref(false);
// «Per tipo»: al più 8 barre nel riquadro (la regola delle 8); oltre, «e altri N tipi» apre una finestra con tutti i tipi, e un clic su un tipo filtra.
const BARRE_DEI_TIPI = 8;
const graficiNelRiquadro = computed<Grafici>(() => (props.grafici.tipi.length > BARRE_DEI_TIPI
    ? { ...props.grafici, tipi: props.grafici.tipi.slice(0, BARRE_DEI_TIPI) } : props.grafici));
const altriTipi = computed(() => Math.max(0, props.grafici.tipi.length - BARRE_DEI_TIPI));
const finestraTipi = ref(false);
function scegliTipo(tipo: string) {
    finestraTipi.value = false;
    alterna('tipo', tipo);
}

// I sei mesi per agire: una fila sola per la colonna, la card e il cassetto.
const seiMesi = reactive(useSeiMesiDelloStudio(() => props.seiMesiCondomini));
const contoDeiSeiMesi = computed(() => contoSeiMesi(seiMesi.esiti));

const nomeDelCondominio = computed(() => (props.filtri.condominio_id ? props.condomini.find((c) => c.id === props.filtri.condominio_id)?.nome ?? null : null));
const scheda = computed(() => props.filtri.scheda ?? 'tutti');
const attivi = computed(() => filtriAttivi(props.filtri, props.grafici));
const aperti = computed(() => props.schede?.tutti ?? props.counts.all);

/**
 * Le prop dell'elenco. Le card, i grafici, «Dove si accumulano» e i sei mesi seguono solo il condominio: una scheda, una barra, una card filtro, la ricerca o
 * l'ordine rileggono solo queste, e l'elenco da capo.
 */
const PROP_DELL_ELENCO = ['tasks', 'schede', 'gruppi', 'modo', 'anteprima', 'filtri'];
/** L'elenco che si sta rileggendo: il segno di caricamento accanto a «Compiti». */
const elencoInArrivo = ref(false);
/**
 * Rilegge con i filtri cambiati: tutti i filtri sono nell'indirizzo. La pagina resta dov'è (`preserveScroll`, `preserveState`), senza la barra di avanzamento in
 * cima; se il condominio non cambia, solo l'elenco.
 */
function vai(cambia: Partial<Filtri>) {
    const nuovi = { ...props.filtri, ...cambia };
    const soloLElenco = (nuovi.condominio_id ?? null) === (props.filtri.condominio_id ?? null);
    router.get(route('admin.inbox'), parametri(nuovi), {
        preserveState: true,
        preserveScroll: true,
        showProgress: false,
        ...(soloLElenco ? { only: PROP_DELL_ELENCO, reset: ['tasks'] } : {}),
        onStart: () => { elencoInArrivo.value = true; },
        onFinish: () => { elencoInArrivo.value = false; },
    });
}
const alterna = <K extends 'quando' | 'tipo'>(chiave: K, valore: string) => vai({ [chiave]: props.filtri[chiave] === valore ? null : valore } as Partial<Filtri>);
/** Una barra del grafico per età: scelta, restringe la sua scheda; scelta di nuovo, torna alla scheda. */
const scegliFascia = (fascia: string) => vai({ quando: props.filtri.quando === fascia ? schedaDellaFascia(fascia) : fascia });
const togliFiltro = (chiave: 'quando' | 'tipo') => vai(chiave === 'quando' ? { quando: schedaDellaFascia(props.filtri.quando ?? '') } : { tipo: null });

// La ricerca: si manda dopo una pausa nella scrittura, o subito con Invio.
const cerca = ref(props.filtri.cerca ?? '');
watch(() => props.filtri.cerca, (c) => { if ((c ?? '') !== cerca.value.trim()) cerca.value = c ?? ''; });
let attesa: ReturnType<typeof setTimeout> | null = null;
function cercaSubito() {
    if (attesa) clearTimeout(attesa);
    attesa = null;
    if ((props.filtri.cerca ?? '') !== cerca.value.trim()) vai({ cerca: cerca.value.trim() || null });
}
function cercaDopo() {
    if (attesa) clearTimeout(attesa);
    attesa = setTimeout(cercaSubito, 400);
}

/** «e altri N»: la scheda del gruppo; per le segnalazioni, i bonifici e ciò che è da moderare, che non hanno una scheda, il loro tipo. */
const apriGruppo = (g: Gruppo) => vai(g === 'scaduti' ? { quando: 'scaduti' } : g === 'avanti' ? { quando: 'in_arrivo' }
    : { tipo: g === 'segnalazioni' ? 'segnalazione_guasto' : g === 'da_moderare' ? 'da_moderare' : 'verifica_pagamento' });
/** Il pallino del gruppo: rosso gli scaduti, arancio le segnalazioni, azzurro i bonifici, indaco gli altri. */
const PALLINI: Record<Gruppo, string> = { scaduti: 'bg-red-500', segnalazioni: 'bg-orange-500', bonifici: 'bg-sky-500', da_moderare: 'bg-amber-500', avanti: 'bg-indigo-500' };

const vociDellElenco = computed(() => righeConIGruppi(props.tasks?.data ?? [], props.gruppi));
const mostrateInAnteprima = computed(() => props.anteprima.reduce((n, g) => n + g.righe.length, 0));
const totaleElenco = computed(() => props.tasks?.total ?? props.gruppi.scaduti + (props.gruppi.segnalazioni ?? 0) + props.gruppi.bonifici + (props.gruppi.da_moderare ?? 0) + props.gruppi.avanti);
const vuoto = computed(() => (props.modo === 'anteprima' ? props.anteprima.length === 0 : (props.tasks?.data ?? []).length === 0));
const massimoDove = computed(() => Math.max(1, ...props.dove.map((d) => d.valore)));

const fraseSegnalazioni = computed(() => {
    const urgenti = props.counts.segnalazioni_urgenti ?? 0;

    return urgenti > 0 ? `di cui ${urgenti} ${urgenti === 1 ? 'urgente' : 'urgenti'}` : 'guasti aperti';
});

/** Il valore di una voce del menu dell'ordine, per l'indirizzo. */
const valoreDellOrdine = (o: { valore: string }) => o.valore;

// Le colonne fisse dell'elenco da xl: le stesse in `RigaDelCompito.vue`.
const COLONNE = 'xl:grid-cols-[2.25rem_minmax(0,1fr)_10rem_7.5rem_11.5rem_2rem]';
const PULSANTE = 'inline-flex h-8 items-center gap-1.5 rounded-lg border border-slate-200 bg-white px-3 shadow-sm dark:border-slate-700 dark:bg-slate-900';
</script>

<template>
    <Head :title="TITOLO" />

    <AppLayout>
        <div class="mx-auto max-w-[1440px] space-y-5 px-4 py-7 sm:px-6">
            <div v-if="flashMessage">
                <Alert :key="giroFlash" :message="flashMessage.message" :type="flashMessage.type" />
            </div>

            <!-- L'intestazione: il titolo con il numero dei compiti aperti, la descrizione; «Guida», il condominio, «Dashboard», alti uguali. -->
            <div class="flex flex-col justify-between gap-3 md:flex-row md:items-end">
                <div class="min-w-0">
                    <p class="text-[11px] font-bold uppercase tracking-widest text-slate-400">{{ SOPRATITOLO }}</p>
                    <div class="mt-0.5 flex items-center gap-2.5">
                        <h1 class="text-2xl font-bold tracking-tight text-slate-900 dark:text-white">{{ TITOLO }}</h1>
                        <span data-testid="numero-aperti" class="rounded-md border px-1.5 py-0.5 text-xs font-bold tabular-nums"
                            :class="counts.all > 0 ? 'border-red-200 bg-red-50 text-red-700 dark:border-red-900 dark:bg-red-950/40 dark:text-red-300'
                                : 'border-slate-200 bg-slate-50 text-slate-500 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-400'">{{ numero(counts.all) }}</span>
                    </div>
                    <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">{{ descrizioneDellaPagina(nomeDelCondominio) }}</p>
                </div>
                <div class="flex flex-wrap items-center gap-2 sm:flex-nowrap">
                    <PulsanteGuida data-testid="pulsante-guida" @click="mostraGuida = true" />
                    <SceltaCondominio :condomini="condomini" :scelto="filtri.condominio_id" tutti="Tutti i condomini"
                        @scegli="(id) => vai({ condominio_id: id === null ? null : Number(id) })">
                        <template #default>
                            <button type="button" data-testid="filtro-condominio" :class="PULSANTE"
                                class="min-w-[15rem] max-w-full text-sm font-medium text-slate-700 hover:border-slate-300 dark:text-slate-200">
                                <Search class="h-3.5 w-3.5 shrink-0 text-slate-400" />
                                <span class="truncate">{{ nomeDelCondominio ?? 'Tutti i condomini' }}</span>
                                <ChevronsUpDown class="ml-auto h-3.5 w-3.5 shrink-0 text-slate-400" />
                            </button>
                        </template>
                    </SceltaCondominio>
                    <Link :href="route('admin.dashboard')" data-testid="torna-dashboard" :class="PULSANTE"
                        class="text-xs font-bold text-slate-600 hover:bg-slate-50 dark:text-slate-300 dark:hover:bg-slate-800">
                        <ArrowLeft class="h-3.5 w-3.5" /> Dashboard
                    </Link>
                </div>
            </div>

            <!-- Le sei card: un clic filtra l'elenco; «Sei mesi» apre il cassetto. Su una riga sola dai grandi schermi. -->
            <div class="grid grid-cols-2 gap-3 lg:grid-cols-3 xl:grid-cols-6">
                <CardDellInbox data-testid="card-scaduti" etichetta="Scaduti" :icona="TriangleAlert" :numero="counts.scaduti" :frase="testoScaduti(counts)"
                    piede="Azione richiesta" rossa :scelta="filtri.quando === 'scaduti'" @scegli="alterna('quando', 'scaduti')" />
                <CardDellInbox data-testid="card-settimana" etichetta="Prossimi 7 giorni" :icona="CalendarClock" :numero="counts.settimana" :frase="testoSettimana(counts)"
                    piede="Questa settimana" :scelta="filtri.quando === 'prossimi_7'" @scegli="alterna('quando', 'prossimi_7')" />
                <CardDellInbox data-testid="card-bonifici" etichetta="Bonifici segnalati" :icona="Banknote" :numero="counts.bonifici" frase="dai condòmini, da verificare"
                    piede="Dal portale" :scelta="filtri.tipo === 'verifica_pagamento'" @scegli="alterna('tipo', 'verifica_pagamento')" />
                <CardDellInbox data-testid="card-segnalazioni" etichetta="Segnalazioni" :icona="Wrench" :numero="counts.segnalazioni" :frase="fraseSegnalazioni"
                    piede="Dal portale" :scelta="filtri.tipo === 'segnalazione_guasto'" @scegli="alterna('tipo', 'segnalazione_guasto')" />
                <CardDellInbox data-testid="card-sei-mesi" etichetta="Sei mesi" :icona="Hourglass" :numero="contoDeiSeiMesi.adesso + contoDeiSeiMesi.rendiconti"
                    :frase="testoCardSeiMesi(contoDeiSeiMesi, seiMesi)" :piede="FRASE_NORMA_CARD" rossa @scegli="cassettoAperto = true" />
                <CardDellInbox data-testid="card-da-moderare" etichetta="Da moderare" :icona="ClipboardCheck" :numero="counts.da_moderare ?? 0" frase="da approvare"
                    piede="Dal portale e dallo studio" ambra :scelta="filtri.tipo === 'da_moderare'" @scegli="alterna('tipo', 'da_moderare')" />
            </div>

            <div class="grid grid-cols-1 items-start gap-3 lg:grid-cols-3">
                <!-- L'elenco dei compiti, a due terzi. -->
                <section class="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm dark:border-slate-800 dark:bg-slate-900 lg:col-span-2"
                    :aria-busy="elencoInArrivo ? 'true' : 'false'" data-testid="sezione-elenco">
                    <div class="flex items-center justify-between gap-3 px-5 pt-4">
                        <h2 class="flex items-center gap-2 text-base font-bold text-slate-900 dark:text-white">Compiti
                            <Loader v-if="elencoInArrivo" class="h-4 w-4 animate-spin text-slate-400" data-testid="elenco-in-caricamento" aria-label="Carico l'elenco" /></h2>
                        <span data-testid="aperti-elenco" class="text-xs font-semibold tabular-nums text-slate-600 dark:text-slate-300">{{ numero(aperti) }} aperti</span>
                    </div>

                    <!-- Le schede, la ricerca, l'ordine. -->
                    <div class="flex flex-wrap items-center gap-2 border-b border-slate-100 px-5 py-3 dark:border-slate-800">
                        <div class="inline-flex rounded-lg bg-slate-100 p-1 text-xs dark:bg-slate-800" role="group" aria-label="Le schede dell'elenco">
                            <button v-for="s in SCHEDE" :key="s.chiave" type="button" :data-testid="`scheda-${s.chiave}`" :aria-pressed="scheda === s.chiave ? 'true' : 'false'"
                                class="rounded-md px-3 py-1.5 whitespace-nowrap"
                                :class="scheda === s.chiave ? 'bg-white font-bold text-slate-900 shadow-sm dark:bg-slate-900 dark:text-white' : 'text-slate-600 hover:text-slate-900 dark:text-slate-300 dark:hover:text-white'"
                                @click="vai({ quando: s.quando })">
                                {{ s.etichetta }}
                                <span class="tabular-nums" :class="s.chiave === 'scaduti' && schede.scaduti > 0 ? 'font-semibold text-red-600 dark:text-red-400' : 'text-slate-500 dark:text-slate-400'">
                                    {{ numero(schede[s.chiave]) }}
                                </span>
                            </button>
                        </div>
                        <label class="flex h-9 min-w-[12rem] flex-1 items-center gap-2 rounded-md border border-slate-200 bg-white px-3 text-sm dark:border-slate-700 dark:bg-slate-900">
                            <Search class="h-4 w-4 shrink-0 text-slate-400" />
                            <input v-model="cerca" type="search" data-testid="cerca" :placeholder="SEGNAPOSTO_RICERCA" :aria-label="ETICHETTA_RICERCA"
                                class="h-full min-w-0 flex-1 border-0 bg-transparent p-0 text-sm text-slate-800 placeholder:text-slate-400 focus:outline-none focus:ring-0 dark:text-slate-100"
                                @input="cercaDopo" @keydown.enter.prevent="cercaSubito" />
                        </label>
                        <div class="menu-inbox w-full sm:w-48" data-testid="ordine">
                            <v-select :model-value="filtri.ordine" :options="ORDINI" label="etichetta" :reduce="valoreDellOrdine" :searchable="false"
                                :clearable="false" @update:model-value="(v: string) => vai({ ordine: v })" />
                        </div>
                    </div>

                    <!-- I filtri attivi dentro la scheda: la barra scelta e il tipo, ognuno si toglie da sé. -->
                    <div v-if="attivi.length" class="flex flex-wrap items-center gap-2 border-b border-slate-100 px-5 py-2 dark:border-slate-800">
                        <span class="text-xs text-slate-500 dark:text-slate-400">Filtrati per</span>
                        <button v-for="f in attivi" :key="f.chiave" type="button" data-testid="filtro-attivo"
                            class="inline-flex items-center gap-1 rounded-md border border-indigo-200 bg-indigo-50 px-2 py-0.5 text-xs font-semibold text-indigo-700 hover:bg-indigo-100 dark:border-indigo-900 dark:bg-indigo-950/40 dark:text-indigo-300"
                            :aria-label="`Togli il filtro ${f.testo}`" @click="togliFiltro(f.chiave)">
                            {{ f.testo }} <X class="h-3 w-3" />
                        </button>
                        <button type="button" data-testid="togli-filtri" class="text-xs font-bold text-slate-500 hover:underline dark:text-slate-400"
                            @click="vai({ quando: null, tipo: null, cerca: null })">Togli i filtri</button>
                    </div>

                    <div data-testid="intestazione-elenco"
                        class="hidden gap-3 whitespace-nowrap border-b border-slate-100 bg-slate-50/60 px-5 py-2 text-[11px] font-bold uppercase tracking-widest text-slate-400 dark:border-slate-800 dark:bg-slate-800/40 xl:grid"
                        :class="COLONNE">
                        <span></span><span>Compito</span><span>Condominio</span><span>Scadenza</span><span>Prossima azione</span><span></span>
                    </div>

                    <div v-if="vuoto" class="flex flex-col items-center px-8 py-14 text-center">
                        <Inbox class="mb-3 h-8 w-8 text-slate-300 dark:text-slate-600" />
                        <p class="text-sm font-semibold text-slate-800 dark:text-slate-100">Nessun compito qui.</p>
                        <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">{{ attivi.length || filtri.cerca ? 'Togli un filtro o cambia la ricerca.' : 'Niente da fare, per adesso.' }}</p>
                    </div>

                    <!-- «Tutti» con più gruppi: l'anteprima di ogni gruppo, con «e altri N» che apre la sua scheda. -->
                    <template v-else-if="modo === 'anteprima'">
                        <template v-for="g in anteprima" :key="g.gruppo">
                            <div data-testid="gruppo" class="flex items-center gap-2 border-b border-slate-100 px-5 py-2 dark:border-slate-800"
                                :class="g.gruppo === 'scaduti' ? 'bg-red-50/50 dark:bg-red-950/20' : 'bg-slate-50/60 dark:bg-slate-800/40'">
                                <span class="h-1.5 w-1.5 rounded-full" :class="PALLINI[g.gruppo]"></span>
                                <p class="text-[11px] font-bold uppercase tracking-widest" :class="g.gruppo === 'scaduti' ? 'text-red-700 dark:text-red-400' : 'text-slate-500 dark:text-slate-400'">{{ titoloDelGruppo(g.gruppo) }}</p> <span class="text-xs font-bold tabular-nums" :class="g.gruppo === 'scaduti' ? 'text-red-600 dark:text-red-400' : 'text-slate-500'">{{ numero(g.numero) }}</span>
                            </div>
                            <RigaDelCompito v-for="r in g.righe" :key="r.id" :riga="r" :puo-chiudere="puoChiudere" @rispondi="(v) => rispondi(v, r)" />
                            <button v-if="g.numero > g.righe.length" type="button" data-testid="e-altri"
                                class="flex w-full items-center gap-2 border-b border-slate-100 px-5 py-2.5 text-left text-xs text-slate-500 hover:bg-slate-50 hover:text-indigo-700 dark:border-slate-800 dark:text-slate-400 dark:hover:bg-slate-800/40 dark:hover:text-indigo-300"
                                @click="apriGruppo(g.gruppo)">
                                <Loader class="h-3.5 w-3.5" /> {{ testoEAltri(g.gruppo, g.numero - g.righe.length) }}
                            </button>
                        </template>
                        <div class="px-5 py-3.5 text-xs text-slate-500 dark:text-slate-400" data-testid="mostrati">{{ testoMostrati(mostrateInAnteprima, totaleElenco) }}</div>
                    </template>

                    <!-- Le schede, e «Tutti» con un gruppo solo: 15 alla volta, con «Mostra altri 15». -->
                    <InfiniteScroll v-else data="tasks" manual preserve-url>
                        <template v-for="v in vociDellElenco" :key="v.tipo === 'gruppo' ? `g-${v.gruppo}` : v.riga.id">
                            <div v-if="v.tipo === 'gruppo'" data-testid="gruppo" class="flex items-center gap-2 border-b border-slate-100 px-5 py-2 dark:border-slate-800"
                                :class="v.gruppo === 'scaduti' ? 'bg-red-50/50 dark:bg-red-950/20' : 'bg-slate-50/60 dark:bg-slate-800/40'">
                                <span class="h-1.5 w-1.5 rounded-full" :class="PALLINI[v.gruppo]"></span>
                                <p class="text-[11px] font-bold uppercase tracking-widest" :class="v.gruppo === 'scaduti' ? 'text-red-700 dark:text-red-400' : 'text-slate-500 dark:text-slate-400'">{{ v.titolo }}</p> <span class="text-xs font-bold tabular-nums" :class="v.gruppo === 'scaduti' ? 'text-red-600 dark:text-red-400' : 'text-slate-500'">{{ numero(v.numero) }}</span>
                            </div>
                            <RigaDelCompito v-else :riga="v.riga" :puo-chiudere="puoChiudere" @rispondi="(voce) => rispondi(voce, v.riga)" />
                        </template>
                        <template #next="{ fetch, hasMore, loading }">
                            <div class="flex flex-wrap items-center justify-between gap-3 px-5 py-3.5">
                                <span class="text-xs text-slate-500 dark:text-slate-400" data-testid="mostrati">{{ testoMostrati(tasks.data.length, totaleElenco) }}</span>
                                <button v-if="hasMore" type="button" data-testid="mostra-altri" :disabled="loading"
                                    class="h-9 rounded-md border border-indigo-200 bg-indigo-50 px-4 text-xs font-bold text-indigo-700 hover:bg-indigo-100 disabled:opacity-60 dark:border-indigo-900 dark:bg-indigo-950/40 dark:text-indigo-300"
                                    @click="fetch">
                                    {{ loading ? 'Arrivano…' : testoMostraAltri(tasks.data.length, totaleElenco) }}
                                </button>
                                <span class="hidden w-24 sm:block"></span>
                            </div>
                        </template>
                    </InfiniteScroll>
                </section>

                <!-- La colonna di destra: i due grafici, i sei mesi, dove si accumulano. -->
                <div class="space-y-3">
                    <section class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-800 dark:bg-slate-900">
                        <div class="flex items-start justify-between gap-3">
                            <div>
                                <h2 class="text-sm font-bold text-slate-900 dark:text-white">Da quanto aspettano</h2>
                                <p class="mt-0.5 text-xs text-slate-500 dark:text-slate-400">{{ DESCRIZIONE_ETA }}</p>
                            </div>
                            <div data-testid="chiave-eta" class="flex items-center gap-3 whitespace-nowrap text-xs text-slate-500 dark:text-slate-400">
                                <span class="flex items-center gap-1.5"><span class="h-2.5 w-2.5 rounded-sm bg-red-500"></span>scaduti</span> <span class="flex items-center gap-1.5"><span class="h-2.5 w-2.5 rounded-sm bg-indigo-500"></span>in arrivo</span>
                            </div>
                        </div>
                        <div class="relative mt-3 h-48" role="img" :aria-label="descrizioneEta(grafici)" data-testid="grafico-eta">
                            <GraficoDellInbox grafico="eta" :grafici="grafici" :scelta="filtri.quando" @scegli="scegliFascia" />
                        </div>
                        <p v-if="testoFuoriDalGrafico(grafici.fuori)" class="mt-2 text-xs text-slate-500 dark:text-slate-400">{{ testoFuoriDalGrafico(grafici.fuori) }}</p>
                    </section>

                    <section class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-800 dark:bg-slate-900">
                        <h2 class="text-sm font-bold text-slate-900 dark:text-white">Per tipo</h2>
                        <p class="mt-0.5 text-xs text-slate-500 dark:text-slate-400">Un clic su un tipo filtra.</p>
                        <div class="relative mt-3" :style="{ height: `${Math.max(3, graficiNelRiquadro.tipi.length) * 1.75 + 1}rem` }" role="img"
                            :aria-label="descrizioneTipi(grafici)" data-testid="grafico-tipi">
                            <GraficoDellInbox grafico="tipi" :grafici="graficiNelRiquadro" :scelta="filtri.tipo" @scegli="(t) => alterna('tipo', t)" />
                        </div>
                        <button v-if="altriTipi" type="button" data-testid="altri-tipi" class="mt-2 text-xs font-bold text-indigo-600 hover:underline dark:text-indigo-400"
                            @click="finestraTipi = true">{{ testoAltriTipi(altriTipi) }}</button>
                    </section>

                    <SeiMesiColonna :stato="seiMesi" :condominio="nomeDelCondominio" @apri-elenco="cassettoAperto = true" />

                    <section class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-800 dark:bg-slate-900">
                        <h2 class="text-sm font-bold text-slate-900 dark:text-white">Dove si accumulano</h2>
                        <p class="mt-0.5 text-xs text-slate-500 dark:text-slate-400">I condomini con più compiti aperti. Un clic filtra.</p>
                        <div class="mt-3 space-y-1">
                            <component :is="d.condominio_id ? 'button' : 'div'" v-for="d in dove" :key="d.condominio_id ?? 'generale'" data-testid="dove"
                                :type="d.condominio_id ? 'button' : undefined"
                                class="grid w-full grid-cols-[minmax(0,1fr)_6rem_2rem] items-center gap-3 rounded-md px-1 py-1 text-left text-sm"
                                :class="d.condominio_id ? 'hover:bg-slate-50 dark:hover:bg-slate-800/50' : ''"
                                :title="d.condominio_id ? undefined : 'I compiti senza un condominio'"
                                @click="d.condominio_id && vai({ condominio_id: d.condominio_id })">
                                <span class="flex min-w-0 items-center gap-1.5 truncate text-slate-700 dark:text-slate-200">
                                    <Building2 v-if="d.condominio_id" class="h-3.5 w-3.5 shrink-0 text-slate-400" />{{ d.nome }}
                                </span> <span class="h-2 overflow-hidden rounded-sm bg-slate-100 dark:bg-slate-800">
                                    <span class="block h-full rounded-sm bg-indigo-400 dark:bg-indigo-500" :style="{ width: `${Math.round((d.valore / massimoDove) * 100)}%` }"></span>
                                </span> <span class="text-right font-semibold tabular-nums text-slate-900 dark:text-white">{{ numero(d.valore) }}</span>
                            </component>
                            <p v-if="!dove.length" class="text-xs text-slate-500 dark:text-slate-400">Nessun compito aperto.</p>
                            <button v-if="filtri.condominio_id" type="button" class="pt-1 text-xs font-bold text-indigo-600 hover:underline dark:text-indigo-400"
                                @click="vai({ condominio_id: null })">Tutti i condomini</button>
                        </div>
                    </section>
                </div>
            </div>
        </div>

        <SeiMesiCassetto v-model:open="cassettoAperto" :stato="seiMesi" :condominio="nomeDelCondominio" @riprova="seiMesi.riprova" />
        <ActionInboxGuide v-model:open="mostraGuida" />

        <!-- Tutti i tipi aperti, quando sono più delle 8 barre del riquadro «Per tipo»: un clic su un tipo filtra l'elenco, come una barra. -->
        <Dialog v-model:open="finestraTipi">
            <DialogContent :show-close-button="false" class="max-h-[92dvh] gap-0 overflow-y-auto p-0 sm:max-w-[480px]" data-testid="finestra-tipi">
                <DialogHeader class="space-y-0 border-b border-slate-100 px-5 py-4 text-left dark:border-slate-800">
                    <div class="flex items-start justify-between gap-3">
                        <DialogTitle class="text-lg font-bold text-slate-900 dark:text-white">Per tipo</DialogTitle>
                        <DialogClose class="inline-flex shrink-0 items-center gap-1 rounded-md px-2 py-1 text-xs font-bold text-slate-500 hover:bg-slate-100 hover:text-slate-800 dark:text-slate-400 dark:hover:bg-slate-800 dark:hover:text-slate-200">
                            <X class="h-4 w-4" /> Chiudi
                        </DialogClose>
                    </div>
                    <DialogDescription class="mt-2 text-sm text-slate-600 dark:text-slate-300">Tutti i tipi dei compiti aperti, dal più numeroso. Un clic su un tipo filtra l'elenco.</DialogDescription>
                </DialogHeader>
                <div class="grid gap-1 px-3 py-3">
                    <button v-for="t in grafici.tipi" :key="t.tipo" type="button" data-testid="voce-tipo" :aria-pressed="filtri.tipo === t.tipo"
                        class="grid grid-cols-[minmax(0,1fr)_5rem] items-center gap-3 rounded-md px-2 py-1.5 text-left text-sm hover:bg-slate-50 dark:hover:bg-slate-800"
                        :class="filtri.tipo === t.tipo ? 'bg-indigo-50 font-semibold text-indigo-700 dark:bg-indigo-950/40 dark:text-indigo-300' : 'text-slate-700 dark:text-slate-200'"
                        @click="scegliTipo(t.tipo)">
                        <span class="truncate">{{ t.etichetta }}</span> <span class="text-right font-semibold tabular-nums">{{ numero(t.valore) }}</span>
                    </button>
                </div>
            </DialogContent>
        </Dialog>
        <RisposteDelCompito ref="risposte" :oggi="oggi ?? ''" />
    </AppLayout>
</template>

<style>
/* Il menu dell'ordine: alto come la ricerca accanto, con il testo del riquadro, e leggibile nel tema scuro. Solo qui. */
.menu-inbox .v-select {
    --vs-font-size: 0.875rem;
    --vs-line-height: 1.25;
    --vs-border-radius: 0.375rem;
    --vs-border-color: var(--input);
    --vs-controls-color: rgb(100 116 139);
    background: transparent;
}
.menu-inbox .vs__dropdown-toggle {
    min-height: 2.25rem;
    padding: 0;
    align-items: center;
}
.menu-inbox .vs__selected-options {
    align-items: center;
    padding-left: 6px;
}
.menu-inbox .vs__selected,
.menu-inbox .vs__search {
    margin: 0 !important;
    padding-top: 0 !important;
    padding-bottom: 0 !important;
}
.menu-inbox .vs__actions {
    padding: 0 8px 0 4px;
}
.menu-inbox .vs__open-indicator {
    transform: scale(0.75);
}
.menu-inbox .vs--open .vs__open-indicator {
    transform: rotate(180deg) scale(0.75);
}
.dark .menu-inbox .v-select {
    --vs-dropdown-bg: rgb(15 23 42);
    --vs-dropdown-color: rgb(226 232 240);
    --vs-dropdown-option-color: rgb(226 232 240);
    --vs-selected-color: rgb(226 232 240);
    --vs-search-input-color: rgb(226 232 240);
    --vs-controls-color: rgb(148 163 184);
    --vs-border-color: rgb(51 65 85);
}
</style>
