<script setup lang="ts">
/**
 * Lo scadenzario delle ritenute d'acconto.
 *
 * Risponde alla domanda che l'amministratore si fa ogni mese intorno al 16: *«devo versare
 * qualcosa, e quanto?»*. Prima di questa versione la risposta andava ricostruita a mano,
 * pagamento per pagamento, applicando due soglie diverse e tre regole di scadenza — e il
 * conto 2202 non si chiudeva mai, perché il movimento che lo chiude non esisteva.
 *
 * La pagina è costruita attorno a una gerarchia precisa: **cosa è in ritardo** viene prima
 * di tutto, perché una scadenza fiscale mancata costa sanzioni; poi cosa scade a breve;
 * poi il resto. I calcoli di urgenza stanno in `lib/gestionale/f24/prospetto.ts` e sono
 * coperti da vitest — qui non si fa aritmetica.
 */
import { computed, ref } from 'vue';
import { Head, router, usePage } from '@inertiajs/vue3';
import GestionaleLayout from '@/layouts/GestionaleLayout.vue';
import MovimentiLayout from '@/layouts/gestionale/MovimentiLayout.vue';
import PageHeaderGuide from '@/components/PageHeaderGuide.vue';
import Alert from '@/components/Alert.vue';
import RitenuteF24Guide from '@/components/guides/RitenuteF24Guide.vue';
import { Tooltip, TooltipContent, TooltipProvider, TooltipTrigger } from '@/components/ui/tooltip';
import { Button } from '@/components/ui/button';
import DataTable from '@/components/gestionale/movimenti/f24/Datatable.vue';
import { createColumns } from '@/components/gestionale/movimenti/f24/columns';
import {
    AlertTriangle, BookOpen, CalendarClock, CheckCircle2, Landmark, RefreshCw, Receipt,
} from 'lucide-vue-next';
import { usePermission } from '@/composables/permissions';
import { useCurrencyFormatter } from '@/composables/useCurrencyFormatter';
import type { Building } from '@/types/buildings';
import type { Flash } from '@/types/flash';

interface Delega {
    id: number;
    stato: string;
    plafond: string;
    data_scadenza: string;
    data_versamento: string | null;
    totale_debito: number;
    note: string | null;
    righe: { id: number; codice_tributo: string }[];
}

const props = defineProps<{
    condominio: Building;
    condomini: Building[];
    esercizio: { id: number; nome: string } | null;
    deleghe: { data: Delega[]; meta: any };
    riepilogo: {
        da_versare_cents: number;
        versato_cents: number;
        scadute: number;
        prossima_scadenza: string | null;
    };
    soglie: { appalti_cents: number; professionisti_cents: number };
    da_calcolare: { numero: number; totale_cents: number };
}>();

const { generatePath, generateRoute } = usePermission();
const { euro } = useCurrencyFormatter();
const page = usePage<{ flash: { message?: Flash } }>();
const flashMessage = computed(() => page.props.flash.message);

const headerBreadcrumbs = computed(() => [
    { title: 'Gestionale', href: generatePath('gestionale/:condominio', { condominio: props.condominio.id }) },
    { title: 'Movimenti' },
    { title: 'Ritenute e F24' },
]);

/**
 * Le soglie NON si riscrivono qui.
 *
 * Erano due numeri battuti a mano nel testo della guida — «€ 500», «€ 100» — mentre quelli
 * veri stanno in `config/fiscale.php` e li usa `PlafondRitenuteService` per decidere le
 * scadenze. Due copie della stessa cifra, e il giorno che il legislatore la cambia una delle
 * due comincia a mentire: la schermata direbbe 500 mentre il sistema calcola su un altro
 * numero. Ora arrivano dal server, da quell'unica fonte.
 */
const sogliaEuro = (cents: number) =>
    // Simbolo DAVANTI al numero, come fa `useCurrencyFormatter` in tutto il gestionale.
    // `Intl` con `style: 'currency'` e locale italiana lo mette in coda («500 €»), che è la
    // convenzione tipografica italiana ma non quella di questa applicazione.
    `€ ${new Intl.NumberFormat('it-IT', { maximumFractionDigits: 0 }).format(cents / 100)}`;

const pageGuides = computed(() => [
    {
        title: 'Quando si versa',
        description: `Soglia ${sogliaEuro(props.soglie.appalti_cents)} per gli appalti, `
            + `${sogliaEuro(props.soglie.professionisti_cents)} per i professionisti, `
            + 'e comunque il 16 giugno e il 16 dicembre.',
        icon: CalendarClock,
        colorVariant: 'blue' as const,
    },
    { title: 'Cosa si versa', description: 'Le ritenute operate pagando i fornitori, raggruppate per codice tributo e mese.', icon: Receipt, colorVariant: 'amber' as const },
    { title: 'Il conto si chiude', description: 'Registrando il versamento il debito verso l\'Erario viene estinto in partita doppia.', icon: Landmark, colorVariant: 'slate' as const },
]);

const oggi = new Date();

/**
 * Il pulsante di ricalcolo sa se serve premerlo.
 *
 * Un pulsante sempre uguale costringe a premerlo «per sicurezza» dopo ogni pagamento, e chi
 * non lo fa non sa di doverlo fare: le ritenute appena operate resterebbero fuori dallo
 * scadenzario senza che niente lo segnali. Qui invece cambia aspetto solo quando cambiarlo
 * significa qualcosa.
 *
 * Niente lampeggio: un elemento che si muove da solo è difficile da leggere, dà fastidio a
 * chi ha disturbi vestibolari, e per giunta smette di funzionare come segnale appena
 * qualcuno ci si abitua. Bastano il colore, il numero e una riga di spiegazione.
 */
const serveRicalcolo = computed(() => props.da_calcolare.numero > 0);

const mostraGuida = ref(false);

/**
 * Il perché del colore vive nel tooltip, non accanto al pulsante.
 *
 * Una frase permanente in barra ruba spazio ai filtri e si legge una volta sola: la seconda
 * volta è rumore. La spiegazione stabile — cosa fa il ricalcolo, perché il pulsante si
 * accende — sta nella guida della pagina, che è la superficie prevista dal flusso di lavoro.
 */
const spiegazioneRicalcolo = computed(() =>
    serveRicalcolo.value
        ? `${props.da_calcolare.numero} ${props.da_calcolare.numero === 1 ? 'ritenuta operata non è' : 'ritenute operate non sono'} ancora in una delega: premi per ricalcolare lo scadenzario.`
        : 'Lo scadenzario è allineato ai pagamenti registrati.'
);

const dataIt = (d: string | null) =>
    d ? new Date(`${d.slice(0, 10)}T00:00:00`).toLocaleDateString('it-IT') : '—';

const calcola = () => {
    router.post(
        route(generateRoute('gestionale.f24.genera'), { condominio: props.condominio.id }),
        {},
        { preserveScroll: true },
    );
};
</script>

<template>
    <Head title="Ritenute e F24" />
    <GestionaleLayout>
        <div class="px-6 py-8 space-y-3">
            <PageHeaderGuide
                page-title="Ritenute e F24"
                page-subtitle="Le ritenute d'acconto operate pagando i fornitori, quando vanno versate e con quale codice tributo."
                :guides="pageGuides"
                :breadcrumbs="(headerBreadcrumbs as any)"
                :condominio="(props.condominio as any)"
                :condomini="(props.condomini as any)"
            >
                <template #actions>
                    <Button
                        variant="outline"
                        class="h-9 gap-2 border-amber-200 bg-white font-medium text-amber-700 shadow-sm hover:bg-amber-50 hover:text-amber-800"
                        @click="mostraGuida = true"
                    >
                        <BookOpen class="h-4 w-4" /> Guida
                    </Button>
                </template>
            </PageHeaderGuide>

            <div class="w-full">
                <section class="w-full space-y-4">
                    <MovimentiLayout>
                        <Alert v-if="flashMessage" :message="flashMessage.message" :type="flashMessage.type" class="mb-4" />

                        <!-- Riepilogo: il ritardo per primo -->
                        <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 mb-6">
                            <div
                                class="border rounded-xl p-4 shadow-sm flex items-center gap-4"
                                :class="riepilogo.scadute > 0 ? 'bg-rose-50 border-rose-200' : 'bg-white border-slate-200'"
                            >
                                <div class="p-2.5 rounded-lg border" :class="riepilogo.scadute > 0 ? 'bg-white border-rose-200' : 'bg-slate-50 border-slate-100'">
                                    <AlertTriangle class="w-5 h-5" :class="riepilogo.scadute > 0 ? 'text-rose-600' : 'text-slate-400'" />
                                </div>
                                <div>
                                    <p class="text-xs font-medium uppercase tracking-wider" :class="riepilogo.scadute > 0 ? 'text-rose-700' : 'text-slate-500'">
                                        Scadute
                                    </p>
                                    <p class="text-2xl font-black" :class="riepilogo.scadute > 0 ? 'text-rose-700' : 'text-slate-900'">
                                        {{ riepilogo.scadute }}
                                    </p>
                                </div>
                            </div>

                            <div class="bg-white border border-slate-200 rounded-xl p-4 shadow-sm flex items-center gap-4">
                                <div class="bg-amber-50 p-2.5 rounded-lg border border-amber-100">
                                    <CalendarClock class="w-5 h-5 text-amber-600" />
                                </div>
                                <div>
                                    <p class="text-xs text-slate-500 font-medium uppercase tracking-wider">Da versare</p>
                                    <p class="text-2xl font-black text-slate-900">{{ euro(riepilogo.da_versare_cents) }}</p>
                                    <p v-if="riepilogo.prossima_scadenza" class="text-[11px] text-slate-400">
                                        prossima il {{ dataIt(riepilogo.prossima_scadenza) }}
                                    </p>
                                </div>
                            </div>

                            <div class="bg-white border border-slate-200 rounded-xl p-4 shadow-sm flex items-center gap-4">
                                <div class="bg-emerald-50 p-2.5 rounded-lg border border-emerald-100">
                                    <CheckCircle2 class="w-5 h-5 text-emerald-600" />
                                </div>
                                <div>
                                    <p class="text-xs text-slate-500 font-medium uppercase tracking-wider">Versato</p>
                                    <p class="text-2xl font-black text-slate-900">{{ euro(riepilogo.versato_cents) }}</p>
                                </div>
                            </div>
                        </div>

                        <DataTable
                            :columns="createColumns(props.condominio.id, oggi)"
                            :data="props.deleghe.data"
                            :condominio="(props.condominio as any)"
                            :meta="props.deleghe.meta"
                        >
                            <template #azioni>
                                <TooltipProvider :delay-duration="200">
                                    <Tooltip>
                                        <TooltipTrigger as-child>
                                            <Button
                                                :variant="serveRicalcolo ? 'default' : 'outline'"
                                                class="h-8"
                                                :class="serveRicalcolo ? 'bg-amber-500 text-white hover:bg-amber-600' : ''"
                                                @click="calcola"
                                            >
                                                <RefreshCw class="mr-2 h-4 w-4" />
                                                Aggiorna scadenze
                                                <span
                                                    v-if="serveRicalcolo"
                                                    class="ml-2 rounded bg-white/25 px-1.5 py-0.5 text-[10px] font-black"
                                                >{{ props.da_calcolare.numero }}</span>
                                            </Button>
                                        </TooltipTrigger>
                                        <TooltipContent><p>{{ spiegazioneRicalcolo }}</p></TooltipContent>
                                    </Tooltip>
                                </TooltipProvider>
                            </template>
                        </DataTable>

                    </MovimentiLayout>
                </section>
            </div>
        </div>

        <RitenuteF24Guide v-model:open="mostraGuida" :soglie="props.soglie" />
    </GestionaleLayout>
</template>
