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
import { ScrollText, Scale, CheckCircle2, XCircle, AlertTriangle, ArrowDownCircle, ArrowUpCircle, ListTree } from 'lucide-vue-next';
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
    if (props.quadratura.risultato_esercizio < 0) {
        return 'Il condominio ha registrato più spese di quante ne abbia finora addebitate ai condòmini: normale a esercizio in corso, non un errore.';
    }
    if (props.quadratura.risultato_esercizio > 0) {
        return 'Il condominio ha addebitato ai condòmini più di quanto ha speso finora.';
    }
    return 'Spese registrate e addebiti ai condòmini sono in pareggio.';
});

const pareggioDareAvere = computed(() => props.riepilogoDareAvere.totale_dare === props.riepilogoDareAvere.totale_avere);

const isDettagliOpen = ref(false);

/** "Disavanzo"/"Avanzo"/"Pareggio" a seconda del segno — stessa logica di spiegazioneQuadratura. */
const esito = (importo: number) => importo < 0 ? 'Disavanzo' : (importo > 0 ? 'Avanzo' : 'Pareggio');

/**
 * Sezione 1: la storia economica dell'anno — perché c'è (o non c'è) un buco.
 * Letta PRIMA della quadratura patrimoniale: "abbiamo speso X e incassato Y,
 * quindi siamo a Z" è più immediato di un'equazione a tre termini.
 */
const sintesiEconomica = computed(() => [
    { voce: 'Totale Spese (Costi)', valore: euro(props.quadratura.costi) },
    { voce: 'Totale Entrate (Ricavi)', valore: euro(props.quadratura.ricavi) },
    { voce: `${esito(props.quadratura.risultato_esercizio)} di Gestione`, valore: euro(props.quadratura.risultato_esercizio), evidenzia: true },
]);

/**
 * Sezione 2: la prova che il disavanzo/avanzo di sezione 1 bilancia esattamente
 * la differenza fra Attivo e Passivo — la "fotografia dei conti" oggi.
 */
const quadraturaPatrimoniale = computed(() => {
    const differenza = props.quadratura.totale_attivo - props.quadratura.totale_passivo;
    return [
        { voce: 'A. Totale Attivo (Cassa, Crediti)', valore: euro(props.quadratura.totale_attivo) },
        { voce: 'B. Totale Passivo (Debiti, Fondi)', valore: euro(props.quadratura.totale_passivo) },
        { voce: `C. ${esito(differenza)} da coprire (A − B)`, valore: euro(differenza), evidenzia: true },
    ];
});

const pageGuides = [
    { title: 'Registro cronologico', description: 'Tutte le scritture contabili dell\'esercizio selezionato, in ordine di registrazione.', icon: ScrollText, colorVariant: 'blue' as const },
    { title: 'Verifica quadratura', description: 'Il widget Stato Patrimoniale segnala se Attivo = Passivo + Risultato d\'esercizio torna, non se Attivo e Passivo coincidono fra loro. Quando non torna ti dice la causa e, dove è possibile, ti dà il pulsante per rimediare.', icon: Scale, colorVariant: 'emerald' as const },
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
            />

            <div class="w-full">
                <section class="w-full space-y-4">
                    <MovimentiLayout>
                        <div class="grid grid-cols-1 lg:grid-cols-2 gap-4 mb-6">

                            <!-- Widget: verifica quadratura Stato Patrimoniale -->
                            <div
                                class="border rounded-xl p-4 shadow-sm flex items-center gap-4"
                                :class="quadratura.quadra ? 'bg-emerald-50/50 border-emerald-200' : 'bg-rose-50/50 border-rose-200'"
                            >
                                <div
                                    class="p-2.5 rounded-lg border shrink-0"
                                    :class="quadratura.quadra ? 'bg-emerald-100 border-emerald-200' : 'bg-rose-100 border-rose-200'"
                                >
                                    <component :is="quadratura.quadra ? CheckCircle2 : XCircle" class="w-5 h-5" :class="quadratura.quadra ? 'text-emerald-600' : 'text-rose-600'" />
                                </div>
                                <div class="min-w-0 flex-1">
                                    <p class="text-xs font-medium uppercase tracking-wider" :class="quadratura.quadra ? 'text-emerald-700' : 'text-rose-700'">
                                        Stato Patrimoniale {{ quadratura.quadra ? 'quadra' : 'non quadra' }}
                                    </p>
                                    <!-- Messaggio in linguaggio corrente prima, numeri dopo: "Attivo/Passivo"
                                         da soli sembrano dover coincidere, ma "quadra" verifica un'equazione a
                                         tre termini (Attivo = Passivo + Risultato d'esercizio), non il pareggio
                                         fra le due masse — senza spiegazione un amministratore non tecnico legge
                                         due numeri diversi accanto a un bollino verde e non si fida. -->
                                    <p class="text-xs mt-1 leading-relaxed" :class="quadratura.quadra ? 'text-slate-700' : 'text-slate-800'">{{ spiegazioneQuadratura }}</p>

                                    <!-- Equazione compatta come riscontro numerico immediato: l'icona a fianco
                                         resta un extra per chi vuole anche Costi/Ricavi/Liquidità non
                                         contabilizzata, non un sostituto di questi tre numeri. -->
                                    <!-- I grigi chiari (slate-400/500) sono stati scelti per il fondo bianco:
                                         sul rosa dello stato di errore il contrasto scende sotto la soglia di
                                         leggibilità e l'equazione diventa un'ombra. Nello stato di errore i
                                         toni neutri si scuriscono di due gradini — restano neutri, così il
                                         rosso continua a marcare solo il numero che allarma. -->
                                    <div
                                        class="flex items-center flex-wrap gap-x-1.5 gap-y-0.5 mt-2 text-[11px]"
                                        :class="quadratura.quadra ? 'text-slate-400' : 'text-slate-600'"
                                    >
                                        <span>Attivo <strong :class="quadratura.quadra ? 'text-slate-600' : 'text-slate-800'">{{ euro(quadratura.totale_attivo) }}</strong></span>
                                        <span>=</span>
                                        <span>Passivo <strong :class="quadratura.quadra ? 'text-slate-600' : 'text-slate-800'">{{ euro(quadratura.totale_passivo) }}</strong></span>
                                        <span>+</span>
                                        <span>Risultato esercizio <strong :class="quadratura.risultato_esercizio < 0 ? 'text-rose-700' : (quadratura.quadra ? 'text-slate-600' : 'text-slate-800')">{{ euro(quadratura.risultato_esercizio) }}</strong></span>
                                        <TooltipProvider :delay-duration="200">
                                            <Tooltip>
                                                <TooltipTrigger as-child>
                                                    <button
                                                        type="button"
                                                        class="inline-flex items-center justify-center w-4 h-4 rounded hover:text-slate-800 hover:bg-white/60 transition-colors"
                                                        :class="quadratura.quadra ? 'text-slate-400' : 'text-slate-600'"
                                                        aria-label="Dettagli calcolo"
                                                        @click="isDettagliOpen = true"
                                                    >
                                                        <ListTree class="w-3 h-3" />
                                                    </button>
                                                </TooltipTrigger>
                                                <TooltipContent><p>Dettagli calcolo</p></TooltipContent>
                                            </Tooltip>
                                        </TooltipProvider>
                                    </div>

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

                            <!-- Widget: riepilogo Dare/Avere del periodo filtrato -->
                            <div class="bg-white border border-slate-200 rounded-xl p-4 shadow-sm flex items-center justify-between gap-4">
                                <div class="flex items-center gap-4">
                                    <div class="flex items-center gap-2">
                                        <div class="p-1.5 rounded-lg bg-blue-50 border border-blue-100 shrink-0">
                                            <ArrowDownCircle class="w-4 h-4 text-blue-600" />
                                        </div>
                                        <div>
                                            <p class="text-[10px] text-slate-500 font-medium uppercase tracking-wider">Totale Dare</p>
                                            <p class="text-lg font-black text-blue-700">{{ euro(riepilogoDareAvere.totale_dare) }}</p>
                                        </div>
                                    </div>
                                    <div class="flex items-center gap-2">
                                        <div class="p-1.5 rounded-lg bg-amber-50 border border-amber-100 shrink-0">
                                            <ArrowUpCircle class="w-4 h-4 text-amber-600" />
                                        </div>
                                        <div>
                                            <p class="text-[10px] text-slate-500 font-medium uppercase tracking-wider">Totale Avere</p>
                                            <p class="text-lg font-black text-amber-700">{{ euro(riepilogoDareAvere.totale_avere) }}</p>
                                        </div>
                                    </div>
                                </div>
                                <!-- Dare e Avere di un registro corretto tornano sempre: se non tornano
                                     è il segnale più diretto di una scrittura rotta fra quelle filtrate. -->
                                <div class="shrink-0" :title="pareggioDareAvere ? 'Dare e Avere tornano' : 'Dare e Avere NON tornano: controlla le scritture segnalate in tabella'">
                                    <CheckCircle2 v-if="pareggioDareAvere" class="w-5 h-5 text-emerald-500" />
                                    <AlertTriangle v-else class="w-5 h-5 text-amber-500" />
                                </div>
                            </div>

                        </div>

                        <div v-if="flashMessage" class="mb-3">
                            <Alert :message="flashMessage.message" :type="flashMessage.type" />
                        </div>

                        <div>
                            <DataTable
                                :columns="createColumns(props.condominio.id, props.esercizio.id)"
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
                            1. Sintesi economica — come è andato l'esercizio
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
                                    <td
                                        class="py-2 text-right tabular-nums"
                                        :class="riga.evidenzia ? (quadratura.risultato_esercizio < 0 ? 'text-rose-700' : 'text-emerald-700') : 'text-slate-900'"
                                    >
                                        {{ riga.valore }}
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <div>
                        <h4 class="text-[10px] font-bold uppercase tracking-wider text-slate-400 mb-1.5">
                            2. Quadratura patrimoniale — la fotografia dei conti oggi
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
                                        <span class="font-normal text-xs text-slate-500">({{ quadratura.quadra ? 'il bilancio quadra' : 'il bilancio NON quadra' }})</span>
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

                <p class="text-[11px] text-slate-400 leading-relaxed">
                    Questa tabella è una vista provvisoria: una pagina Stato Patrimoniale dedicata,
                    con il dettaglio voce per voce, arriverà in una beta successiva.
                </p>
            </DialogContent>
        </Dialog>
    </GestionaleLayout>
</template>
