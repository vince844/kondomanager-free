<script setup lang="ts">
import { computed, ref } from 'vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import GestionaleLayout from '@/layouts/GestionaleLayout.vue';
import MovimentiLayout from '@/layouts/gestionale/MovimentiLayout.vue';
import PageHeaderGuide from '@/components/PageHeaderGuide.vue';
import DataTable from '@/components/gestionale/movimenti/scritture/Datatable.vue'
import { createColumns } from '@/components/gestionale/movimenti/scritture/columns';
import { usePermission } from '@/composables/permissions';
import { useCurrencyFormatter } from '@/composables/useCurrencyFormatter';
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Tooltip, TooltipContent, TooltipProvider, TooltipTrigger } from '@/components/ui/tooltip';
import { Button } from '@/components/ui/button';
import { ScrollText, Scale, CheckCircle2, XCircle, AlertTriangle, ArrowDownCircle, ArrowUpCircle, ArrowRight, ListTree, Printer } from 'lucide-vue-next';
import Alert from "@/components/Alert.vue";
import type { ScritturaRow } from '@/components/gestionale/movimenti/scritture/columns';
import type { Building } from '@/types/buildings';
import type { Esercizio } from '@/types/gestionale/esercizi';
import type { Flash } from '@/types/flash';

interface PaginationMeta {
    current_page: number;
    last_page: number;
    total: number;
    per_page: number;
}

interface Quadratura {
    quadra: boolean;
    /** In CENTESIMI. */
    sbilancio: number;
    totale_attivo: number;
    totale_passivo: number;
    costi: number;
    ricavi: number;
    risultato_esercizio: number;
    liquidita_non_contabilizzata: number;
}

interface RiepilogoDareAvere {
    totale_dare: number;
    totale_avere: number;
}

interface ScritturaNonQuadrata {
    id: number;
    numero_protocollo: string;
    causale: string;
}

interface CassaSenzaApertura {
    id: number;
    nome: string;
    /** In CENTESIMI. */
    saldo_iniziale: number;
}

interface Diagnosi {
    scritture_non_quadrate: ScritturaNonQuadrata[];
    casse_senza_apertura: CassaSenzaApertura[];
}

const props = defineProps<{
    condominio: Building;
    condomini: Building[];
    esercizio: Esercizio;
    esercizi: Esercizio[];
    scritture: { data: ScritturaRow[]; meta: PaginationMeta };
    tipiMovimento: { value: string; label: string }[];
    stati: string[];
    riepilogoDareAvere: RiepilogoDareAvere;
    quadratura: Quadratura;
    /** Presente solo quando quadratura.quadra è false. */
    diagnosi: Diagnosi | null;
    filters: { search?: string; tipo_movimento?: string; stato?: string; data_da?: string; data_a?: string };
}>();

const { generatePath, generateRoute } = usePermission();
const { euro } = useCurrencyFormatter();
const page = usePage<{ flash: { message?: Flash } }>();
const flashMessage = computed(() => page.props.flash.message);

const headerBreadcrumbs = computed(() => [
    { title: 'Gestionale', href: generatePath('gestionale/:condominio', { condominio: props.condominio.id }) },
    { title: 'Movimenti' },
    { title: 'Libro Giornale' },
]);

/**
 * La cassa su cui si sta registrando l'apertura: serve solo a non far premere due volte lo
 * stesso pulsante. L'azione lato server è comunque idempotente — non registra due aperture —
 * ma un doppio clic che sembra non fare niente è un difetto a sé.
 */
const registrandoApertura = ref<number | null>(null);

/**
 * La cura che mancava alla diagnosi: porta a giornale il saldo di apertura di una cassa che
 * ce l'ha in colonna. Prima qui c'era solo il link alla pagina della cassa, dove nessun
 * pulsante registra l'apertura — il widget nominava la causa e non offriva niente.
 */
/**
 * §10.1.2 di docs/registri_contabili.md: la stampa richiesta da un utente, riga per riga. Gli
 * stessi filtri della pagina — non "tutto l'esercizio" — perché è la lettura naturale di un
 * pulsante sulla pagina già filtrata: `props.filters` è la fonte più affidabile per questo,
 * più della query string del browser, perché è già ciò che il server ha interpretato.
 */
function stampaGiornale() {
    window.open(route(generateRoute('gestionale.esercizi.scritture.print'), {
        condominio: props.condominio.id,
        esercizio: props.esercizio.id,
        ...props.filters,
    }), '_blank');
}

function registraApertura(cassaId: number) {
    registrandoApertura.value = cassaId;

    router.post(
        route(generateRoute('gestionale.casse.registra-apertura'), { condominio: props.condominio.id, cassa: cassaId }),
        {},
        { preserveScroll: true, onFinish: () => { registrandoApertura.value = null; } }
    );
}

/**
 * Traduzione in linguaggio corrente dell'equazione patrimoniale, per chi non mastica
 * contabilità. Deve ramificare PRIMA su quadra/non quadra: un condominio in disavanzo ma
 * quadrato è normale, uno sbilanciato non lo è mai, e le due frasi non vanno confuse.
 */
const spiegazioneQuadratura = computed(() => {
    if (!props.quadratura.quadra) {
        const scrittureRotte = props.diagnosi?.scritture_non_quadrate.length ?? 0;
        const casseSenzaApertura = props.diagnosi?.casse_senza_apertura.length ?? 0;

        if (scrittureRotte > 0) {
            return scrittureRotte === 1
                ? 'C\'è 1 scrittura non bilanciata al suo interno: è la causa più probabile dello sbilancio, vedi sotto.'
                : `Ci sono ${scrittureRotte} scritture non bilanciate al loro interno: è la causa più probabile dello sbilancio, vedi sotto.`;
        }
        if (casseSenzaApertura > 0) {
            return casseSenzaApertura === 1
                ? 'C\'è 1 cassa con un saldo di apertura non ancora registrato a giornale: è la causa più probabile dello sbilancio, vedi sotto.'
                : `Ci sono ${casseSenzaApertura} casse con un saldo di apertura non ancora registrato a giornale: è la causa più probabile dello sbilancio, vedi sotto.`;
        }
        return 'Lo sbilancio non è riconducibile a una causa nota automaticamente. Da dove guardare: apri i dettagli qui sotto e confronta le tre voci dell\'equazione, poi controlla se i totali Dare e Avere del riquadro accanto coincidono — se non coincidono la causa è una scrittura rotta. Se tornano entrambi, sentiamoci con il supporto.';
    }
    // Nessuna frase sul «risultato»: senza conti di ricavo il risultato del motore è −costi, e
    // «più spese di quante addebitate» era falso ogni volta che le quote emesse superavano le
    // spese (visto al test reale della beta.25: quote 1.000, spese 488, frase «più spese»).
    // Si dice cosa c'è fra le due colonne, e dove leggerlo voce per voce.
    if (props.quadratura.costi === 0 && props.quadratura.ricavi === 0) {
        return 'Attivo e passivo coincidono: nessun costo ancora registrato in questo esercizio.';
    }
    return 'I costi non ancora conguagliati stanno fra attivo e passivo: le quote emesse ai condòmini sono un debito della gestione, non un ricavo.';
});

const hrefStatoPatrimoniale = computed(() => generatePath('gestionale/:condominio/esercizi/:esercizio/stato-patrimoniale', {
    condominio: props.condominio.id,
    esercizio: props.esercizio.id,
}));

const pareggioDareAvere = computed(() => props.riepilogoDareAvere.totale_dare === props.riepilogoDareAvere.totale_avere);
const filtriAttivi = computed(() => Object.values(props.filters ?? {}).some((v) => v !== undefined && v !== null && v !== ''));

const isDettagliOpen = ref(false);

/** "Disavanzo"/"Avanzo"/"Pareggio" a seconda del segno — stessa logica di spiegazioneQuadratura. */
const esito = (importo: number) => importo < 0 ? 'Disavanzo' : (importo > 0 ? 'Avanzo' : 'Pareggio');

/**
 * Sezione 1: la storia economica dell'anno — perché c'è (o non c'è) un buco.
 * Letta PRIMA della quadratura patrimoniale: "abbiamo speso X e incassato Y,
 * quindi siamo a Z" è più immediato di un'equazione a tre termini.
 */
type RigaDettaglio = { voce: string; valore: string; evidenzia?: boolean };
const sintesiEconomica = computed((): RigaDettaglio[] => [
    { voce: 'Totale attivo (liquidità, crediti)', valore: euro(props.quadratura.totale_attivo) },
    { voce: 'Costi dell\'esercizio, non ancora conguagliati', valore: euro(props.quadratura.costi) },
    { voce: 'Attivo + costi', valore: euro(props.quadratura.totale_attivo + props.quadratura.costi), evidenzia: true },
]);

/**
 * Sezione 2: l'altro membro dell'uguaglianza — le passività (e i ricavi, se un giorno ci
 * saranno). Le quote emesse ai condòmini stanno qui, come debito della gestione.
 */
const quadraturaPatrimoniale = computed(() => {
    if (props.quadratura.ricavi === 0) {
        return [{ voce: 'Totale passivo (debiti, quote emesse, riporti)', valore: euro(props.quadratura.totale_passivo), evidenzia: true }];
    }
    const righe: RigaDettaglio[] = [{ voce: 'Totale passivo (debiti, quote emesse, riporti)', valore: euro(props.quadratura.totale_passivo) }];
    righe.push({ voce: 'Ricavi', valore: euro(props.quadratura.ricavi) });
    righe.push({ voce: 'Passivo + ricavi', valore: euro(props.quadratura.totale_passivo + props.quadratura.ricavi), evidenzia: true });
    return righe;
});

const pageGuides = [
    {
        title: 'Registro cronologico',
        description: 'Tutte le scritture contabili dell\'esercizio selezionato, in ordine di registrazione. Non sostituisce il registro di contabilità — entrate e uscite, art. 1130, comma 1, n. 7 c.c.',
        icon: ScrollText,
        colorVariant: 'blue' as const,
        // Dalla beta.24 la pagina esiste: prima era un rimando testuale a una voce di menu
        // disattivata (§10.1.3 di docs/registri_contabili.md), ora è un link vero.
        link: {
            label: 'Vai al registro di contabilità (Prima nota)',
            href: generatePath('gestionale/:condominio/esercizi/:esercizio/registro-contabilita', {
                condominio: props.condominio.id,
                esercizio: props.esercizio.id,
            }),
        },
    },
    { title: 'Verifica quadratura', description: 'Il riquadro Stato Patrimoniale verifica che Attivo + Costi = Passivo sulle scritture di questo esercizio — la stessa uguaglianza del dare = avere. Attivo e Passivo da soli non devono coincidere: in mezzo ci sono i costi non ancora conguagliati. Quando non torna ti dice la causa e, dove è possibile, ti dà il pulsante per rimediare.', icon: Scale, colorVariant: 'emerald' as const },
    { title: 'Cambia esercizio', description: 'Usa il selettore esercizio in alto per consultare le operazioni di anni precedenti.', icon: ArrowUpCircle, colorVariant: 'amber' as const },
];
</script>

<template>
    <Head title="Libro Giornale" />
    <GestionaleLayout>
        <div class="px-6 py-8 space-y-3">
            <PageHeaderGuide
                page-title="Libro Giornale"
                page-subtitle="Registro cronologico di tutte le scritture contabili dell'esercizio, con verifica di quadratura patrimoniale."
                :guides="pageGuides"
                :breadcrumbs="(headerBreadcrumbs as any)"
                :condominio="(props.condominio as any)"
                :condomini="(props.condomini as any)"
                :esercizio="(props.esercizio as any)"
                :esercizi="(props.esercizi as any)"
            >
                <template #actions>
                    <Button variant="outline" class="h-8 px-3 border-slate-200 text-slate-700 bg-white hover:bg-slate-50 shadow-sm shrink-0 gap-2" @click="stampaGiornale">
                        <Printer class="w-4 h-4" />
                        <span class="hidden sm:inline">Stampa</span>
                    </Button>
                </template>
            </PageHeaderGuide>

            <div class="w-full">
                <section class="w-full space-y-4">
                    <MovimentiLayout>
                        <div class="grid grid-cols-1 lg:grid-cols-2 gap-4 mb-6">

                            <!-- Widget: verifica quadratura Stato Patrimoniale. Stesso registro del riquadro
                                 Dare/Avere accanto — tre cifre con l'etichetta sopra, non un blocco di testo —
                                 e la stessa uguaglianza della pagina Stato patrimoniale: Attivo + Costi = Passivo. -->
                            <div
                                class="border rounded-xl p-4 shadow-sm flex items-start gap-4"
                                :class="quadratura.quadra ? 'bg-emerald-50/50 border-emerald-200' : 'bg-rose-50/50 border-rose-200'"
                            >
                                <div
                                    class="p-2.5 rounded-lg border shrink-0 mt-0.5"
                                    :class="quadratura.quadra ? 'bg-emerald-100 border-emerald-200' : 'bg-rose-100 border-rose-200'"
                                >
                                    <component :is="quadratura.quadra ? CheckCircle2 : XCircle" class="w-5 h-5" :class="quadratura.quadra ? 'text-emerald-600' : 'text-rose-600'" />
                                </div>
                                <div class="min-w-0 flex-1">
                                    <div class="flex items-center justify-between gap-3">
                                        <p class="text-xs font-medium uppercase tracking-wider" :class="quadratura.quadra ? 'text-emerald-700' : 'text-rose-700'">
                                            Stato Patrimoniale {{ quadratura.quadra ? 'quadra' : 'non quadra' }}
                                        </p>
                                        <div class="flex items-center gap-2 shrink-0">
                                            <Link :href="hrefStatoPatrimoniale" class="inline-flex items-center gap-1 text-[11px] font-semibold hover:underline" :class="quadratura.quadra ? 'text-emerald-700' : 'text-rose-700'">Apri lo Stato patrimoniale <ArrowRight class="w-3 h-3" /></Link>
                                            <TooltipProvider :delay-duration="200">
                                                <Tooltip>
                                                    <TooltipTrigger as-child>
                                                        <button
                                                            type="button"
                                                            class="inline-flex items-center justify-center w-5 h-5 rounded hover:text-slate-800 hover:bg-white/60 transition-colors"
                                                            :class="quadratura.quadra ? 'text-slate-400' : 'text-slate-600'"
                                                            aria-label="Dettagli calcolo"
                                                            @click="isDettagliOpen = true"
                                                        >
                                                            <ListTree class="w-3.5 h-3.5" />
                                                        </button>
                                                    </TooltipTrigger>
                                                    <TooltipContent><p>Dettagli calcolo</p></TooltipContent>
                                                </Tooltip>
                                            </TooltipProvider>
                                        </div>
                                    </div>

                                    <!-- Le tre cifre dell'uguaglianza, con l'etichetta sopra come nel riquadro
                                         Dare/Avere. Nello stato di errore i grigi si scuriscono di due gradini,
                                         così il rosso continua a marcare solo il numero che allarma. -->
                                    <div class="flex items-end flex-wrap gap-x-3 gap-y-1 mt-2">
                                        <div>
                                            <p class="text-[10px] font-medium uppercase tracking-wider" :class="quadratura.quadra ? 'text-slate-500' : 'text-slate-600'">Attivo</p>
                                            <p class="text-lg font-black leading-tight tabular-nums" :class="quadratura.quadra ? 'text-slate-700' : 'text-slate-800'">{{ euro(quadratura.totale_attivo) }}</p>
                                        </div>
                                        <span class="text-lg font-light pb-0.5" :class="quadratura.quadra ? 'text-slate-400' : 'text-slate-600'">+</span>
                                        <div>
                                            <p class="text-[10px] font-medium uppercase tracking-wider" :class="quadratura.quadra ? 'text-slate-500' : 'text-slate-600'">Costi</p>
                                            <p class="text-lg font-black leading-tight tabular-nums" :class="quadratura.quadra ? 'text-slate-700' : 'text-slate-800'">{{ euro(quadratura.costi) }}</p>
                                        </div>
                                        <span class="text-lg font-light pb-0.5" :class="quadratura.quadra ? 'text-slate-400' : 'text-slate-600'">=</span>
                                        <div>
                                            <p class="text-[10px] font-medium uppercase tracking-wider" :class="quadratura.quadra ? 'text-slate-500' : 'text-slate-600'">Passivo</p>
                                            <p class="text-lg font-black leading-tight tabular-nums" :class="quadratura.quadra ? 'text-emerald-700' : 'text-rose-700'">{{ euro(quadratura.totale_passivo) }}</p>
                                        </div>
                                        <template v-if="quadratura.ricavi !== 0">
                                            <span class="text-lg font-light pb-0.5 text-slate-400">+</span>
                                            <div>
                                                <p class="text-[10px] font-medium uppercase tracking-wider text-slate-500">Ricavi</p>
                                                <p class="text-lg font-black leading-tight tabular-nums text-slate-700">{{ euro(quadratura.ricavi) }}</p>
                                            </div>
                                        </template>
                                    </div>

                                    <!-- Una riga in linguaggio corrente: «Attivo/Passivo» da soli sembrano dover
                                         coincidere, e senza spiegazione un amministratore non tecnico legge due
                                         numeri diversi accanto a un bollino verde e non si fida. -->
                                    <p class="text-[11px] mt-2 leading-snug" :class="quadratura.quadra ? 'text-slate-600' : 'text-slate-800'">{{ spiegazioneQuadratura }}</p>

                                    <!-- Diagnosi: le due cause riconoscibili con certezza, con link diretto
                                         a dove intervenire. Oltre queste due il messaggio sopra dice già che
                                         serve una verifica manuale — non c'è altro da elencare qui. -->
                                    <ul v-if="!quadratura.quadra && diagnosi" class="mt-2 space-y-1">
                                        <li v-for="s in diagnosi.scritture_non_quadrate" :key="`s-${s.id}`" class="text-xs">
                                            <Link
                                                :href="route(generateRoute('gestionale.scritture.show'), { condominio: props.condominio.id, scrittura: s.id })"
                                                class="text-rose-700 font-semibold hover:underline"
                                            >
                                                {{ s.numero_protocollo }}
                                            </Link>
                                            <span :class="quadratura.quadra ? 'text-slate-500' : 'text-slate-700'">— {{ s.causale }}</span>
                                        </li>
                                        <li v-for="c in diagnosi.casse_senza_apertura" :key="`c-${c.id}`" class="text-xs flex flex-wrap items-center gap-x-2 gap-y-1">
                                            <Link
                                                :href="route(generateRoute('gestionale.casse.edit'), { condominio: props.condominio.id, cassa: c.id })"
                                                class="text-rose-700 font-semibold hover:underline"
                                            >
                                                {{ c.nome }}
                                            </Link>
                                            <span :class="quadratura.quadra ? 'text-slate-500' : 'text-slate-700'">— apertura mancante, {{ euro(c.saldo_iniziale) }}</span>
                                            <!-- La cura, non solo la diagnosi. Prima qui c'era il solo link alla pagina
                                                 della cassa, dove nessun pulsante registra l'apertura: il widget nominava
                                                 la causa e lasciava l'amministratore senza niente da fare. -->
                                            <button
                                                type="button"
                                                :disabled="registrandoApertura === c.id"
                                                class="inline-flex items-center gap-1 px-2 py-0.5 rounded-md border border-rose-200 bg-white text-rose-700 font-semibold hover:bg-rose-50 disabled:opacity-50 transition-colors"
                                                @click="registraApertura(c.id)"
                                            >
                                                {{ registrandoApertura === c.id ? 'Registro…' : 'Registra apertura' }}
                                            </button>
                                        </li>
                                    </ul>
                                </div>
                            </div>

                            <!-- Widget: riepilogo Dare/Avere del periodo filtrato. Stesso registro del riquadro
                                 accanto: icona di esito, titolo, cifre con l'etichetta sopra, una riga di testo. -->
                            <div
                                class="border rounded-xl p-4 shadow-sm flex items-start gap-4"
                                :class="pareggioDareAvere ? 'bg-white border-slate-200' : 'bg-amber-50/50 border-amber-200'"
                            >
                                <div
                                    class="p-2.5 rounded-lg border shrink-0 mt-0.5"
                                    :class="pareggioDareAvere ? 'bg-emerald-50 border-emerald-200' : 'bg-amber-100 border-amber-200'"
                                >
                                    <component :is="pareggioDareAvere ? CheckCircle2 : AlertTriangle" class="w-5 h-5" :class="pareggioDareAvere ? 'text-emerald-600' : 'text-amber-600'" />
                                </div>
                                <div class="min-w-0 flex-1">
                                    <p class="text-xs font-medium uppercase tracking-wider" :class="pareggioDareAvere ? 'text-emerald-700' : 'text-amber-700'">
                                        Partita doppia: dare {{ pareggioDareAvere ? '=' : '≠' }} avere
                                    </p>
                                    <div class="flex items-end flex-wrap gap-x-3 gap-y-1 mt-2">
                                        <div class="flex items-center gap-2">
                                            <div class="p-1.5 rounded-lg bg-blue-50 border border-blue-100 shrink-0">
                                                <ArrowDownCircle class="w-4 h-4 text-blue-600" />
                                            </div>
                                            <div>
                                                <p class="text-[10px] text-slate-500 font-medium uppercase tracking-wider">Totale dare</p>
                                                <p class="text-lg font-black leading-tight tabular-nums text-blue-700">{{ euro(riepilogoDareAvere.totale_dare) }}</p>
                                            </div>
                                        </div>
                                        <span class="text-lg font-light pb-0.5 text-slate-400">{{ pareggioDareAvere ? '=' : '≠' }}</span>
                                        <div class="flex items-center gap-2">
                                            <div class="p-1.5 rounded-lg bg-amber-50 border border-amber-100 shrink-0">
                                                <ArrowUpCircle class="w-4 h-4 text-amber-600" />
                                            </div>
                                            <div>
                                                <p class="text-[10px] text-slate-500 font-medium uppercase tracking-wider">Totale avere</p>
                                                <p class="text-lg font-black leading-tight tabular-nums text-amber-700">{{ euro(riepilogoDareAvere.totale_avere) }}</p>
                                            </div>
                                        </div>
                                    </div>
                                    <!-- Dare e Avere di un registro corretto tornano sempre: se non tornano
                                         è il segnale più diretto di una scrittura rotta fra quelle filtrate. -->
                                    <p class="text-[11px] mt-2 leading-snug" :class="pareggioDareAvere ? 'text-slate-600' : 'text-amber-800'">
                                        <template v-if="pareggioDareAvere">{{ scritture.meta.total === 1 ? 'Una scrittura' : scritture.meta.total + ' scritture' }} {{ filtriAttivi ? 'nel periodo filtrato' : 'nell\'esercizio' }}, ognuna con dare uguale ad avere: è la base su cui poggia lo stato patrimoniale.</template>
                                        <template v-else>Dare e avere non tornano: almeno una scrittura fra quelle {{ filtriAttivi ? 'filtrate' : 'dell\'esercizio' }} è rotta al suo interno — è segnalata in tabella.</template>
                                    </p>
                                </div>
                            </div>

                        </div>

                        <div v-if="flashMessage" class="mb-3">
                            <Alert :message="flashMessage.message" :type="flashMessage.type" />
                        </div>

                        <div>
                            <DataTable
                                :columns="createColumns()"
                                :data="props.scritture.data"
                                :meta="props.scritture.meta"
                                :condominio="props.condominio"
                                :esercizio="props.esercizio"
                            />
                        </div>

                    </MovimentiLayout>
                </section>
            </div>
        </div>

        <!-- Modale: dettaglio completo del calcolo di StatoPatrimonialeService::calcola,
             in due passaggi — prima la storia economica, poi la prova che quadra con i
             conti. Un'unica tabella con 7 righe eterogenee obbligava a leggere l'equazione
             prima di sapere da dove venisse il numero; separare i due piani rende esplicito
             che il disavanzo/avanzo di gestione È la differenza fra Attivo e Passivo. -->
        <Dialog v-model:open="isDettagliOpen">
            <DialogContent class="sm:max-w-[480px]">
                <DialogHeader>
                    <DialogTitle class="flex items-center gap-2">
                        <Scale class="w-5 h-5 text-slate-500" />
                        Dettaglio Stato Patrimoniale
                    </DialogTitle>
                    <DialogDescription>
                        {{ props.esercizio.nome }}.
                    </DialogDescription>
                </DialogHeader>

                <div class="space-y-5">
                    <div>
                        <h4 class="text-[10px] font-bold uppercase tracking-wider text-slate-400 mb-1.5">
                            1. Primo membro — attivo più costi
                        </h4>
                        <table class="w-full text-sm">
                            <tbody>
                                <tr
                                    v-for="riga in sintesiEconomica"
                                    :key="riga.voce"
                                    class="border-b border-slate-100 last:border-0"
                                    :class="{ 'font-bold': riga.evidenzia }"
                                >
                                    <td class="py-2 pr-4 text-slate-600">{{ riga.voce }}</td>
                                    <td class="py-2 text-right tabular-nums text-slate-900">
                                        {{ riga.valore }}
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <div>
                        <h4 class="text-[10px] font-bold uppercase tracking-wider text-slate-400 mb-1.5">
                            2. Secondo membro — passivo
                        </h4>
                        <table class="w-full text-sm">
                            <tbody>
                                <tr
                                    v-for="riga in quadraturaPatrimoniale"
                                    :key="riga.voce"
                                    class="border-b border-slate-100 last:border-0"
                                    :class="{ 'font-bold': riga.evidenzia }"
                                >
                                    <td class="py-2 pr-4 text-slate-600">{{ riga.voce }}</td>
                                    <td class="py-2 text-right tabular-nums text-slate-900">{{ riga.valore }}</td>
                                </tr>
                                <tr class="font-bold">
                                    <td class="py-2 pr-4 text-slate-600">Controllo Quadratura</td>
                                    <td class="py-2 text-right tabular-nums" :class="quadratura.quadra ? 'text-emerald-700' : 'text-rose-700'">
                                        {{ euro(quadratura.sbilancio) }}
                                        <span class="font-normal text-xs text-slate-500">({{ quadratura.quadra ? 'i due membri coincidono: quadra' : 'i due membri NON coincidono' }})</span>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <p v-if="quadratura.liquidita_non_contabilizzata !== 0" class="text-xs text-amber-700 bg-amber-50 border border-amber-200 rounded-lg p-2.5 leading-relaxed">
                        Nell'Attivo sono inclusi {{ euro(quadratura.liquidita_non_contabilizzata) }} di saldi di apertura
                        cassa non ancora registrati con una scrittura a giornale.
                    </p>
                </div>

                <p class="text-[11px] text-slate-500 leading-relaxed">
                    Sono le scritture di questo esercizio, le stesse del giornale qui sotto. Il dettaglio voce per voce,
                    la liquidità libera e accantonata, il riepilogo finanziario e i controlli sono nella
                    <Link :href="hrefStatoPatrimoniale" class="font-semibold text-emerald-700 hover:underline">pagina Stato patrimoniale</Link>.
                </p>
            </DialogContent>
        </Dialog>
    </GestionaleLayout>
</template>
