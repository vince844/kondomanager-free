<script setup lang="ts">
/**
 * Una delega F24: il prospetto da trascrivere, e cosa sta versando.
 *
 * **Non è il modello ministeriale** — quello si scarica come PDF dal pulsante «Modello F24»,
 * lo compone il server ed è il foglio che si consegna allo sportello. Questa pagina è il
 * documento che l'amministratore ha sotto gli occhi mentre compila l'F24 nell'home banking o
 * su F24 online, dove i campi si **digitano**: per questo la tabella ricalca nomi e ordine
 * della sezione Erario del modello, così la trascrizione è uno a uno e non c'è niente da
 * interpretare.
 *
 * Sotto il prospetto c'è la parte che nessun altro software mostra: **quali pagamenti** e
 * **a quale fornitore** corrisponde ogni riga. È la risposta alla domanda che arriva sei
 * mesi dopo — «questi 800 € di cosa erano?» — e senza quel legame si ricostruisce a mano.
 */
import { computed, onMounted, ref } from 'vue';
import { Head, router, useForm, usePage } from '@inertiajs/vue3';
import GestionaleLayout from '@/layouts/GestionaleLayout.vue';
import PageHeaderGuide from '@/components/PageHeaderGuide.vue';
import Alert from '@/components/Alert.vue';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuLabel,
    DropdownMenuSeparator, DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import {
    AlertOctagon, ArrowLeft, ArrowRight, Banknote, Check, ChevronDown, Copy, FileText, Info,
    Landmark, Lock, Printer, RotateCcw, Users,
} from 'lucide-vue-next';
import { usePermission } from '@/composables/permissions';
import { useCurrencyFormatter } from '@/composables/useCurrencyFormatter';
import { descrizioneMotivo, righeProspetto, testoScadenza, totaleDebito } from '@/lib/gestionale/f24/prospetto';
import type { Building } from '@/types/buildings';
import type { Flash } from '@/types/flash';

const props = defineProps<{
    condominio: Building;
    condomini: Building[];
    delega: any;
    banche: { id: number; cassa_id: number; nome: string; tipo: string }[];
    motivo_blocco_modifica: string | null;
    /** I campi che il modello ministeriale vuole e che l'anagrafica non sa ancora. */
    campi_mancanti: string[];
}>();

const { generatePath, generateRoute } = usePermission();
const { euro } = useCurrencyFormatter();
const page = usePage<{ flash: { message?: Flash } }>();
const flashMessage = computed(() => page.props.flash.message);

const oggi = new Date();

const headerBreadcrumbs = computed(() => [
    { title: 'Gestionale', href: generatePath('gestionale/:condominio', { condominio: props.condominio.id }) },
    { title: 'Ritenute e F24', href: route(generateRoute('gestionale.f24.index'), { condominio: props.condominio.id }) },
    { title: 'Delega' },
]);

const righe = computed(() => righeProspetto(props.delega.righe ?? []));

/**
 * Il totale si ricalcola dalle righe invece di leggere quello della testata: se i due
 * divergono è un difetto e va visto, non nascosto mostrando comunque il numero salvato.
 */
const totaleRighe = computed(() => totaleDebito(props.delega.righe ?? []));
const totaleDiscorde = computed(() => totaleRighe.value !== Number(props.delega.totale_debito));

const isVersata = computed(() => props.delega.stato === 'versata');
const isChiusa = computed(() => ['stornata', 'annullata'].includes(props.delega.stato));
const puoVersare = computed(() => ['bozza', 'confermata'].includes(props.delega.stato));

const dataIt = (d: string | null) =>
    d ? new Date(`${String(d).slice(0, 10)}T00:00:00`).toLocaleDateString('it-IT') : '—';

// ── Trascrizione ─────────────────────────────────────────────────────────────

const copiato = ref(false);

/** Il prospetto in testo semplice, per incollarlo dove serve. */
const testoProspetto = computed(() => {
    const intestazione = 'Codice tributo\tRateaz.\tMese rif.\tAnno rif.\tImporto a debito';
    const corpo = righe.value
        .map((r) => `${r.codiceTributo}\t${r.rateazione}\t${r.mese}\t${r.anno}\t${(r.importoDebito / 100).toFixed(2).replace('.', ',')}`)
        .join('\n');

    return `${intestazione}\n${corpo}`;
});

const etichettaPlafond = computed(() =>
    props.delega.plafond === 'soglia_500_tre_finestre' ? 'Appalti 4%'
        : props.delega.plafond === 'soglia_100_annuale' ? 'Professionisti'
            : 'Versamento mensile'
);

/**
 * Il titolo della scheda, che è anche il nome con cui il browser propone il PDF quando si
 * stampa su file: «Delega F24 — Condominio X — 18-05-2026.pdf» invece del nome della
 * finestra. Va tenuto parlante per questo motivo, non solo per la linguetta del browser.
 */
const titoloDocumento = computed(() =>
    `Delega F24 — ${props.condominio.nome} — ${String(props.delega.data_scadenza).slice(0, 10).split('-').reverse().join('-')}`
);

/** Il motivo arriva come codice: la frase si compone qui, non a database. */
const motivo = computed(() => descrizioneMotivo(props.delega.motivo_codice));

/**
 * Il **prospetto** usa il dialogo di stampa del browser su un foglio ripulito via
 * `@media print`. È il documento da portare al commercialista o da tenere agli atti, e quello
 * da avere accanto mentre si digitano i campi nell'home banking.
 */
const stampa = () => window.print();

/**
 * Il **modello ministeriale** è un'altra cosa e passa da un'altra strada: lo compone il
 * server e arriva come PDF già impaginato, in tre copie.
 *
 * Non è una preferenza tecnica. Il modulo è una griglia di caselle con misure fisse, e i
 * margini che ogni browser applica di suo la sposterebbero; e il file scaricato prende un nome
 * costruito sul protocollo o sulla scadenza, invece di quello che il browser ricava dal titolo
 * della finestra.
 */
const modelloF24 = () =>
    window.open(
        route(generateRoute('gestionale.f24.modello'), {
            condominio: props.condominio.id,
            delega: props.delega.id,
        }),
        '_blank',
    );

/**
 * Cosa manca al modello per essere presentabile allo sportello.
 *
 * Il codice fiscale ha già un avviso suo, più severo, perché senza quello l'F24 non si
 * presenta affatto. Qui restano gli altri — il domicilio fiscale — che rendono il foglio
 * incompleto ma non nullo: si stampa lo stesso e si completa a penna, se l'amministratore
 * decide così.
 */
const mancantiOltreIlCodiceFiscale = computed(() =>
    (props.campi_mancanti ?? []).filter((campo) => !campo.toLowerCase().includes('codice fiscale')),
);

/**
 * Arrivando dallo scadenzario con `?stampa=1` il dialogo si apre da solo: dall'elenco il
 * gesto è «stampa questa», e obbligare a un passaggio in più sulla scheda per premere di
 * nuovo un pulsante è attrito senza motivo. Il rendering dev'essere finito, altrimenti il
 * browser fotografa una pagina a metà.
 */
onMounted(() => {
    if (new URLSearchParams(window.location.search).get('stampa') === '1') {
        requestAnimationFrame(() => setTimeout(stampa, 250));
    }
});

const copia = async () => {
    try {
        await navigator.clipboard.writeText(testoProspetto.value);
        copiato.value = true;
        setTimeout(() => (copiato.value = false), 2000);
    } catch {
        copiato.value = false;
    }
};

// ── Azioni ───────────────────────────────────────────────────────────────────

const mostraVersamento = ref(false);
const mostraStorno = ref(false);

const formVersamento = useForm({
    data_versamento: new Date().toISOString().substring(0, 10),
    conto_corrente_id: props.banche[0]?.id ?? null,
    cassa_id: props.banche[0]?.cassa_id ?? null,
    note: '',
});

const formStorno = useForm({ motivo: '', data_storno: new Date().toISOString().substring(0, 10) });

const scegliBanca = (id: number) => {
    formVersamento.conto_corrente_id = id;
    formVersamento.cassa_id = props.banche.find((b) => b.id === id)?.cassa_id ?? null;
};

const versa = () => {
    formVersamento.post(
        route(generateRoute('gestionale.f24.versa'), { condominio: props.condominio.id, delega: props.delega.id }),
        { preserveScroll: true, onSuccess: () => (mostraVersamento.value = false) },
    );
};

const storna = () => {
    formStorno.post(
        route(generateRoute('gestionale.f24.storna'), { condominio: props.condominio.id, delega: props.delega.id }),
        { preserveScroll: true, onSuccess: () => (mostraStorno.value = false) },
    );
};
</script>

<template>
    <Head :title="titoloDocumento" />
    <GestionaleLayout>
        <div class="px-6 py-8 space-y-4">
            <PageHeaderGuide
                page-title="Delega F24"
                :page-subtitle="`Scadenza ${dataIt(props.delega.data_scadenza)} · ${etichettaPlafond}`"
                :guides="[]"
                :breadcrumbs="(headerBreadcrumbs as any)"
                :condominio="(props.condominio as any)"
                :condomini="[]"
            >
                <template #actions>
                    <!--
                        Un solo pulsante per due documenti, com'è nel piano dei conti: due
                        stampe affiancate in testata sembrano due pulsanti in concorrenza, e
                        chi arriva qui la prima volta non sa quale dei due gli serve. Nel
                        menu invece stanno una sotto l'altra con la loro spiegazione.
                    -->
                    <DropdownMenu>
                        <DropdownMenuTrigger as-child>
                            <Button variant="outline" class="h-9 gap-2 font-medium shadow-sm">
                                <Printer class="h-4 w-4" /> Stampe
                                <ChevronDown class="h-3 w-3 opacity-60" />
                            </Button>
                        </DropdownMenuTrigger>
                        <DropdownMenuContent align="end" class="w-64 shadow-xl rounded-xl border-slate-100 p-1.5">
                            <DropdownMenuLabel class="text-[10px] text-slate-400 uppercase tracking-widest px-2 py-1.5 font-bold">
                                Documenti
                            </DropdownMenuLabel>
                            <DropdownMenuSeparator class="bg-slate-100" />
                            <DropdownMenuItem
                                class="cursor-pointer flex items-start gap-2.5 px-2 py-2 rounded-lg hover:bg-indigo-50 focus:bg-indigo-50 text-slate-700"
                                @click="modelloF24"
                            >
                                <FileText class="mt-0.5 h-3.5 w-3.5 shrink-0 text-indigo-500" />
                                <div>
                                    <div class="text-xs font-medium">Modello F24</div>
                                    <div class="text-[11px] text-slate-400">Il foglio da consegnare allo sportello</div>
                                </div>
                            </DropdownMenuItem>
                            <DropdownMenuItem
                                class="cursor-pointer flex items-start gap-2.5 px-2 py-2 rounded-lg hover:bg-indigo-50 focus:bg-indigo-50 text-slate-700"
                                @click="stampa"
                            >
                                <Printer class="mt-0.5 h-3.5 w-3.5 shrink-0 text-indigo-500" />
                                <div>
                                    <div class="text-xs font-medium">Prospetto</div>
                                    <div class="text-[11px] text-slate-400">I campi da digitare nell'home banking</div>
                                </div>
                            </DropdownMenuItem>
                        </DropdownMenuContent>
                    </DropdownMenu>
                    <Button
                        variant="outline"
                        class="h-9 gap-2 font-medium shadow-sm"
                        @click="router.visit(route(generateRoute('gestionale.f24.index'), { condominio: props.condominio.id }))"
                    >
                        <ArrowLeft class="h-4 w-4" /> Torna allo scadenzario
                    </Button>
                </template>
            </PageHeaderGuide>

            <div class="solo-stampa mb-6 border-b border-slate-300 pb-4">
                <h1 class="text-xl font-bold text-slate-900">Delega F24 — Ritenute d'acconto</h1>
                <p class="mt-1 text-sm text-slate-700">
                    {{ props.delega.denominazione_contribuente }}
                    <span v-if="props.delega.cf_contribuente"> · C.F. {{ props.delega.cf_contribuente }}</span>
                </p>
                <p class="mt-0.5 text-xs text-slate-500">
                    Scadenza {{ dataIt(props.delega.data_scadenza) }} · totale {{ euro(totaleRighe) }}
                    · stampato il {{ dataIt(new Date().toISOString()) }}
                </p>
            </div>

            <Alert v-if="flashMessage" :message="flashMessage.message" :type="flashMessage.type" />

            <!-- Intestazione della delega -->
            <div class="bg-white border border-slate-200 rounded-xl p-5 shadow-sm">
                <!-- Barra del titolo della card: dà una casa al badge di stato, che da solo
                     su una riga vuota sembrava un elemento dimenticato. -->
                <div class="mb-4 flex items-center justify-between border-b border-slate-100 pb-3">
                    <p class="text-[11px] font-bold uppercase tracking-wider text-slate-400">
                        Delega F24 · {{ etichettaPlafond }}
                    </p>
                    <Badge v-if="isVersata" class="bg-emerald-600 text-white">Versata</Badge>
                    <Badge v-else-if="props.delega.stato === 'stornata'" variant="destructive">Stornata</Badge>
                    <Badge v-else-if="props.delega.stato === 'annullata'" variant="secondary">Annullata</Badge>
                    <Badge v-else variant="outline">{{ props.delega.stato === 'confermata' ? 'Confermata' : 'Bozza' }}</Badge>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-4 gap-4">
                    <div>
                        <p class="text-[11px] uppercase tracking-wider text-slate-400">Contribuente</p>
                        <p class="text-sm font-semibold text-slate-800">{{ props.delega.denominazione_contribuente }}</p>
                        <p class="text-xs text-slate-500 font-mono">{{ props.delega.cf_contribuente ?? '—' }}</p>
                    </div>
                    <div>
                        <p class="text-[11px] uppercase tracking-wider text-slate-400">Scadenza</p>
                        <p class="text-sm font-semibold text-slate-800">{{ dataIt(props.delega.data_scadenza) }}</p>
                        <p v-if="puoVersare" class="text-xs text-slate-500">{{ testoScadenza(props.delega.data_scadenza, oggi) }}</p>
                    </div>
                    <div v-if="isVersata">
                        <p class="text-[11px] uppercase tracking-wider text-slate-400">Versata il</p>
                        <p class="text-sm font-semibold text-slate-800">{{ dataIt(props.delega.data_versamento) }}</p>
                    </div>
                    <div class="sm:text-right">
                        <p class="text-[11px] uppercase tracking-wider text-slate-400">Totale a debito</p>
                        <p class="text-2xl font-black text-slate-900">{{ euro(totaleRighe) }}</p>
                    </div>
                </div>

                <p v-if="motivo" class="mt-4 border-t border-slate-100 pt-3 text-xs text-slate-500">
                    {{ motivo }}
                </p>

                <!--
                    Perché questa riga esiste: guardando il Libro Giornale con tre deleghe in
                    bozza è naturale chiedersi dove siano finite. Non ci sono perché una bozza
                    non ha effetto contabile — ma finché non lo dice nessuno, l'assenza sembra
                    un dato mancante invece di una scelta.
                -->
                <p v-if="puoVersare" class="mt-2 flex items-start gap-1.5 text-xs text-slate-500">
                    <Info class="mt-0.5 h-3.5 w-3.5 shrink-0 text-slate-400" />
                    <span>
                        Nessun movimento contabile finché non registri il versamento: per questo la delega
                        non compare ancora nel Libro Giornale.
                    </span>
                </p>
                <p v-if="props.delega.note" class="mt-2 text-xs text-slate-500">
                    {{ props.delega.note }}
                </p>
            </div>

            <!--
                Il codice fiscale del contribuente è un campo OBBLIGATORIO dell'F24: senza,
                la delega non si può presentare. Va detto qui, dove si trascrive, e non
                lasciato scoprire davanti al modulo compilato a metà — è lo stesso principio
                del divieto muto corretto nella beta.34.
            -->
            <div
                v-if="!props.delega.cf_contribuente"
                class="border border-amber-300 bg-amber-50 rounded-xl p-4 text-sm text-amber-900 flex gap-3"
            >
                <AlertOctagon class="w-5 h-5 shrink-0" />
                <div>
                    <p class="font-semibold">Manca il codice fiscale del condominio</p>
                    <p class="mt-1">
                        L'F24 non si può presentare senza il codice fiscale del contribuente. Inseriscilo
                        nell'anagrafica del condominio, poi torna qui e premi «Aggiorna scadenze»: la delega
                        verrà ricalcolata con il dato corretto.
                    </p>
                </div>
            </div>

            <!--
                Il modello ministeriale chiede il domicilio fiscale, che il prospetto non usa:
                è per questo che l'assenza salta fuori solo adesso. Non blocca la stampa —
                l'amministratore può volere il foglio da completare a penna, e la decisione se
                un documento è pronto resta sua. Dirlo qui è però l'ultimo momento in cui
                l'informazione serve a qualcosa: allo sportello è tardi.
            -->
            <div
                v-if="mancantiOltreIlCodiceFiscale.length"
                class="border border-slate-300 bg-slate-50 rounded-xl p-4 text-sm text-slate-700 flex gap-3"
            >
                <Info class="w-5 h-5 shrink-0 text-slate-500" />
                <div>
                    <p class="font-semibold text-slate-800">
                        Il modello F24 uscirà con dei campi in bianco
                    </p>
                    <p class="mt-1">
                        L'anagrafica del condominio non ha
                        <span class="font-medium">{{ mancantiOltreIlCodiceFiscale.join(', ').toLowerCase() }}</span>.
                        La stampa funziona lo stesso e quelle caselle restano vuote, da completare a mano.
                        Compilando l'anagrafica escono già scritte.
                        <!--
                            Il collegamento sta in coda alla frase, non sotto: i campi ci sono
                            già nell'anagrafica — comune e provincia sono facoltativi, ed è per
                            questo che possono mancare — quindi qui non serve dire DOVE andare,
                            ma portarci, senza aprire un blocco a sé per una riga sola.
                        -->
                        <a
                            :href="route('condomini.edit', { id: props.condominio.id })"
                            class="inline-flex items-center gap-1 font-medium text-slate-800 underline underline-offset-2 hover:text-slate-950"
                        >
                            Completa l'anagrafica<ArrowRight class="h-3.5 w-3.5" />
                        </a>
                    </p>
                </div>
            </div>

            <div
                v-if="totaleDiscorde"
                class="border border-rose-300 bg-rose-50 rounded-xl p-4 text-sm text-rose-800 flex gap-3"
            >
                <AlertOctagon class="w-5 h-5 shrink-0" />
                <p>
                    Il totale registrato sulla delega ({{ euro(props.delega.totale_debito) }}) non coincide con la
                    somma delle righe ({{ euro(totaleRighe) }}). Non versare prima di aver aggiornato le scadenze.
                </p>
            </div>

            <!-- IL PROSPETTO: stessi campi e stesso ordine della sezione Erario -->
            <div class="bg-white border border-slate-200 rounded-xl shadow-sm overflow-hidden">
                <div class="flex items-center justify-between px-5 py-4 border-b border-slate-100">
                    <div>
                        <h2 class="text-sm font-bold uppercase tracking-wider text-slate-600">Sezione Erario</h2>
                        <p class="text-xs text-slate-400">
                            Campi nell'ordine del modello F24: si trascrivono così come sono
                        </p>
                    </div>
                    <!--
                        Qui resta la sola «Copia», che è l'azione di QUESTA tabella: prende le
                        sue righe e le porta negli appunti. Le stampe stanno in testata, nel
                        menu «Stampe», perché riguardano il documento e non la card — averle in
                        due posti faceva sembrare che fossero due stampe diverse.
                    -->
                    <div class="flex items-center gap-2 no-print">
                        <Button variant="outline" size="sm" @click="copia">
                            <component :is="copiato ? Check : Copy" class="w-4 h-4 mr-2" />
                            {{ copiato ? 'Copiato' : 'Copia' }}
                        </Button>
                    </div>
                </div>

                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="bg-slate-50 text-[11px] uppercase tracking-wider text-slate-500">
                            <tr>
                                <th class="text-left px-5 py-2.5 font-semibold">Codice tributo</th>
                                <th class="text-left px-3 py-2.5 font-semibold">Rateaz. / Reg. / Prov.</th>
                                <th class="text-left px-3 py-2.5 font-semibold">Mese rif.</th>
                                <th class="text-left px-3 py-2.5 font-semibold">Anno rif.</th>
                                <th class="text-right px-5 py-2.5 font-semibold">Importi a debito versati</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            <tr v-for="r in righe" :key="r.ordine" class="hover:bg-slate-50/60">
                                <td class="px-5 py-3 font-mono font-bold text-slate-800">{{ r.codiceTributo }}</td>
                                <td class="px-3 py-3 font-mono text-slate-600">{{ r.rateazione }}</td>
                                <td class="px-3 py-3 font-mono text-slate-600">{{ r.mese }}</td>
                                <td class="px-3 py-3 font-mono text-slate-600">{{ r.anno }}</td>
                                <td class="px-5 py-3 text-right font-bold text-slate-900">{{ euro(r.importoDebito) }}</td>
                            </tr>
                        </tbody>
                        <tfoot class="bg-slate-50 border-t-2 border-slate-200">
                            <!--
                                Etichetta e importo nella STESSA cella: messa nella colonna
                                dell'anno, «Totale» finiva a mezza tabella di distanza dalla
                                cifra che nomina, e a colpo d'occhio non si capiva più a quale
                                numero si riferisse.
                            -->
                            <tr>
                                <td colspan="4"></td>
                                <td class="px-5 py-3 text-right whitespace-nowrap">
                                    <span class="mr-3 text-xs font-bold uppercase tracking-wider text-slate-500">Totale</span>
                                    <span class="text-lg font-black text-slate-900">{{ euro(totaleRighe) }}</span>
                                </td>
                            </tr>
                        </tfoot>
                    </table>
                </div>

                <p class="px-5 py-3 text-[11px] text-slate-400 border-t border-slate-100">
                    Questi sono i dati da digitare nell'home banking o sul sito dell'Agenzia delle Entrate.
                    Per pagare allo sportello serve invece il foglio compilato: menu «Stampe» → «Modello F24».
                </p>
            </div>

            <!-- Cosa sta pagando: il legame che si cerca sei mesi dopo -->
            <div class="bg-white border border-slate-200 rounded-xl shadow-sm overflow-hidden">
                <div class="px-5 py-4 border-b border-slate-100 flex items-center gap-2">
                    <Users class="w-4 h-4 text-slate-400" />
                    <h2 class="text-sm font-bold uppercase tracking-wider text-slate-600">Ritenute comprese</h2>
                </div>

                <div v-for="riga in props.delega.righe" :key="riga.id" class="px-5 py-4 border-b border-slate-50 last:border-0">
                    <p class="text-xs font-semibold text-slate-500 mb-2">
                        Codice {{ riga.codice_tributo }} · periodo {{ String(riga.rateazione_mese_rif).slice(2, 4) }}/{{ riga.anno_riferimento }}
                    </p>
                    <div class="space-y-1.5">
                        <div
                            v-for="p in riga.pagamenti"
                            :key="p.id"
                            class="flex items-center justify-between text-sm"
                        >
                            <span class="text-slate-700">
                                {{ p.fornitore?.ragione_sociale ?? 'Fornitore' }}
                                <span class="text-slate-400 text-xs">· pagato il {{ dataIt(p.data_pagamento) }}</span>
                            </span>
                            <span class="font-semibold text-slate-800">{{ euro(p.pivot?.importo ?? 0) }}</span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Azioni -->
            <div v-if="puoVersare" class="no-print bg-white border border-slate-200 rounded-xl p-5 shadow-sm">
                <div v-if="!mostraVersamento" class="flex items-center justify-between">
                    <div>
                        <p class="text-sm font-semibold text-slate-800">Hai già versato questo F24?</p>
                        <p class="text-xs text-slate-500">
                            Registrandolo si chiude il debito verso l'Erario: DARE 2202 / AVERE banca.
                        </p>
                    </div>
                    <Button @click="mostraVersamento = true">
                        <Banknote class="w-4 h-4 mr-2" /> Registra versamento
                    </Button>
                </div>

                <div v-else class="space-y-4">
                    <h3 class="text-sm font-bold text-slate-800">Registra il versamento</h3>

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div>
                            <Label class="text-[11px] font-bold uppercase tracking-wider text-slate-500">Data del versamento</Label>
                            <Input v-model="formVersamento.data_versamento" type="date" class="mt-1" />
                            <p v-if="formVersamento.errors.data_versamento" class="text-xs text-rose-600 mt-1">
                                {{ formVersamento.errors.data_versamento }}
                            </p>
                        </div>
                        <div>
                            <Label class="text-[11px] font-bold uppercase tracking-wider text-slate-500">Conto di addebito</Label>
                            <div class="mt-1 space-y-1">
                                <button
                                    v-for="b in props.banche"
                                    :key="b.id"
                                    type="button"
                                    class="w-full text-left px-3 py-2 rounded-lg border text-sm transition"
                                    :class="formVersamento.conto_corrente_id === b.id
                                        ? 'border-slate-800 bg-slate-50 font-semibold'
                                        : 'border-slate-200 hover:border-slate-300'"
                                    @click="scegliBanca(b.id)"
                                >
                                    <Landmark class="w-3.5 h-3.5 inline mr-2 text-slate-400" />{{ b.nome }}
                                </button>
                            </div>
                        </div>
                    </div>

                    <div>
                        <Label class="text-[11px] font-bold uppercase tracking-wider text-slate-500">Note (opzionali)</Label>
                        <Input v-model="formVersamento.note" placeholder="Protocollo telematico, riferimento…" class="mt-1" />
                    </div>

                    <p v-if="formVersamento.errors.versamento" class="text-sm text-rose-600">
                        {{ formVersamento.errors.versamento }}
                    </p>

                    <div class="flex items-center justify-end gap-2">
                        <Button variant="ghost" @click="mostraVersamento = false">Annulla</Button>
                        <Button :disabled="formVersamento.processing || !formVersamento.conto_corrente_id" @click="versa">
                            Conferma versamento di {{ euro(totaleRighe) }}
                        </Button>
                    </div>
                </div>
            </div>

            <!-- Sigillo e storno -->
            <div v-if="isVersata" class="no-print bg-slate-50 border border-slate-200 rounded-xl p-5">
                <div v-if="!mostraStorno" class="flex items-start justify-between gap-4">
                    <div class="flex gap-3">
                        <Lock class="w-5 h-5 text-slate-400 shrink-0 mt-0.5" />
                        <div>
                            <p class="text-sm font-semibold text-slate-700">Delega versata: non è più modificabile</p>
                            <p class="text-xs text-slate-500 mt-1">
                                {{ props.motivo_blocco_modifica }}
                                Un versamento all'Erario è un fatto avvenuto: si corregge con uno storno, che scrive
                                il movimento uguale e contrario invece di riscrivere il passato.
                            </p>
                        </div>
                    </div>
                    <Button variant="outline" size="sm" class="shrink-0" @click="mostraStorno = true">
                        <RotateCcw class="w-4 h-4 mr-2" /> Storna
                    </Button>
                </div>

                <div v-else class="space-y-3">
                    <h3 class="text-sm font-bold text-slate-800">Storna il versamento</h3>
                    <p class="text-xs text-slate-500">
                        Il denaro rientra e il debito verso l'Erario torna aperto: le ritenute rientreranno nel
                        prossimo calcolo delle scadenze. Se l'Erario ha davvero incassato, il recupero va gestito
                        a parte.
                    </p>
                    <div>
                        <Label class="text-[11px] font-bold uppercase tracking-wider text-slate-500">Motivo dello storno</Label>
                        <Input v-model="formStorno.motivo" placeholder="Es. conto di addebito sbagliato" class="mt-1" />
                        <p v-if="formStorno.errors.motivo || formStorno.errors.storno" class="text-xs text-rose-600 mt-1">
                            {{ formStorno.errors.motivo || formStorno.errors.storno }}
                        </p>
                    </div>
                    <div class="flex items-center justify-end gap-2">
                        <Button variant="ghost" @click="mostraStorno = false">Annulla</Button>
                        <Button variant="destructive" :disabled="formStorno.processing" @click="storna">
                            Conferma storno
                        </Button>
                    </div>
                </div>
            </div>

            <div v-if="isChiusa && props.delega.motivo_annullamento" class="bg-slate-50 border border-slate-200 rounded-xl p-4">
                <p class="text-[11px] uppercase tracking-wider text-slate-400">Motivo</p>
                <p class="text-sm text-slate-700">{{ props.delega.motivo_annullamento }}</p>
            </div>
        </div>
    </GestionaleLayout>
</template>

<style>
/* Visibile solo in stampa: a schermo l'intestazione la dà già la pagina. */
.solo-stampa {
    display: none;
}

/**
 * Il foglio da consegnare al commercialista o da tenere agli atti.
 *
 * Non è il modello ministeriale — quello lo compone il server come PDF, vedi `modelloF24()`.
 * Questo è il prospetto dei dati, che su carta deve restare
 * leggibile e senza fronzoli. Via tutto ciò che serve solo a interagire — barra di
 * navigazione, breadcrumb, pulsanti, form — e via ombre e sfondi, che in stampa si
 * traducono in grigi sporchi e in inchiostro sprecato.
 */
@media print {
    /* Le superfici dell'applicazione: header, barre laterali, navigazione. */
    header,
    footer,
    nav,
    aside,
    .no-print {
        display: none !important;
    }

    /* L'intestazione che esiste SOLO su carta: un foglio consegnato a terzi deve dire
       da sé cos'è, di chi è e a quando si riferisce. A schermo sarebbe una ripetizione. */
    .solo-stampa {
        display: block !important;
    }

    /* Il contenuto occupa il foglio: niente margini dell'app, niente larghezze fisse. */
    body {
        background: #fff !important;
    }

    main,
    main > div {
        padding: 0 !important;
        margin: 0 !important;
        max-width: none !important;
    }

    /* Riquadri: bordo sottile, nessuna ombra, nessun fondo colorato. */
    .rounded-xl {
        box-shadow: none !important;
        border-color: #cbd5e1 !important;
        break-inside: avoid;
    }

    /* La tabella del prospetto non si spezza a metà riga fra due pagine. */
    table {
        break-inside: auto;
    }

    tr {
        break-inside: avoid;
        break-after: auto;
    }

    thead {
        display: table-header-group;
    }

    /* I fondi tinti degli avvisi diventano un bordo: l'informazione resta, l'inchiostro no. */
    .bg-amber-50,
    .bg-rose-50,
    .bg-slate-50 {
        background: #fff !important;
    }

    a[href]::after {
        content: none !important;
    }
}
</style>
