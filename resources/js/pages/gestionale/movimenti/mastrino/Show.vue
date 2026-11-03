<script setup lang="ts">
import { computed } from 'vue';
import { Head, Link, usePage } from '@inertiajs/vue3';
import Alert from '@/components/Alert.vue';
import type { Flash } from '@/types/flash';
import GestionaleLayout from '@/layouts/GestionaleLayout.vue';
import MovimentiLayout from '@/layouts/gestionale/MovimentiLayout.vue';
import PageHeaderGuide from '@/components/PageHeaderGuide.vue';
import DataTable from '@/components/gestionale/movimenti/mastrino/Datatable.vue';
import { createColumns } from '@/components/gestionale/movimenti/mastrino/columns';
import { usePermission } from '@/composables/permissions';
import { useCurrencyFormatter } from '@/composables/useCurrencyFormatter';
import { Button } from '@/components/ui/button';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import { BookOpen, Printer, Scale, ArrowLeftRight, CornerDownRight, AlertTriangle, ChevronDown } from 'lucide-vue-next';
import type { MastrinoRow } from '@/components/gestionale/movimenti/mastrino/columns';
import type { Building } from '@/types/buildings';
import type { Esercizio } from '@/types/gestionale/esercizi';

interface PaginationMeta { current_page: number; last_page: number; total: number; per_page: number }

interface Conto { id: number; codice: string; nome: string; tipo: string; natura_dare: boolean }

interface Riepilogo {
    /** In centesimi: il saldo del conto a `riporto_al`, nel verso naturale. */
    riporto: number;
    /** Il giorno prima del periodo — o oggi, per un esercizio non ancora cominciato. */
    riporto_al: string;
    /** In centesimi, sulle sole righe mostrate. */
    totale_dare: number;
    totale_avere: number;
    /** In centesimi: l'ultimo saldo del periodo intero = la fotografia dello Stato patrimoniale. */
    saldo_finale: number;
    /** In centesimi: il saldo alla data dell'ultima riga mostrata, sul periodo intero. */
    saldo_alla_data: number;
    data_riferimento: string | null;
    totale_righe: number;
    altri_esercizi: number;
    /** Righe di QUESTO esercizio datate prima del suo inizio: stanno nel riporto, dichiarate. */
    riporto_proprie: number;
    postdatate: number;
    /** In centesimi: saldo iniziale di cassa non ancora a giornale — dichiarato, non sommato. */
    apertura_non_registrata: number;
}

const props = defineProps<{
    condominio: Building;
    esercizio: Esercizio;
    esercizi: Esercizio[];
    conto: Conto;
    conti: { id: number; codice: string; nome: string; tipo: string; righe: number }[];
    periodo: { dal: string; al: string; stato: 'futuro' | 'aperto' | 'chiuso' };
    righe: { data: MastrinoRow[]; meta: PaginationMeta };
    riepilogo: Riepilogo;
    filters: { search?: string; data_da?: string; data_a?: string; da?: string };
}>();

const { generatePath, generateRoute } = usePermission();
const page = usePage<{ flash: { message?: Flash } }>();
const flashMessage = computed(() => page.props.flash?.message);
const { euro } = useCurrencyFormatter();

const formatData = (iso: string) => {
    const [anno, mese, giorno] = iso.split('-');
    return `${giorno}/${mese}/${anno}`;
};

// La data del riporto la decide il servizio (D21.2): il giorno prima del periodo, oppure oggi
// per un esercizio non ancora cominciato — dove il riporto è la fotografia a oggi. Calcolarla
// qui la faceva sbagliare in tutti e due i modi (fuso orario e caso futuro).
const giornoPrima = computed(() => props.riepilogo.riporto_al);

const hrefStatoPatrimoniale = computed(() => generatePath('gestionale/:condominio/esercizi/:esercizio/stato-patrimoniale', {
    condominio: props.condominio.id,
    esercizio: props.esercizio.id,
}));

// Corto apposta: con il nome del conto in coda e cinque pulsanti a destra la barra usciva dallo
// schermo anche a 2000px (visto da Vincenzo a video). Il nome del conto è già nel titolo.
const headerBreadcrumbs = computed(() => [
    { title: 'Gestionale', href: generatePath('gestionale/:condominio', { condominio: props.condominio.id }) },
    { title: 'Stato patrimoniale', href: hrefStatoPatrimoniale.value },
    { title: 'Mastrino' },
]);

// Il pulsante «indietro» porta dove si era: alle casse se si è entrati da lì (`?da=casse`,
// che viaggia con i filtri), altrimenti allo Stato patrimoniale, che è la porta principale.
const backUrl = computed(() => {
    if (props.filters.da === 'casse') return generatePath('gestionale/:condominio/casse', { condominio: props.condominio.id });
    if (props.filters.da === 'libro-giornale') return generatePath('gestionale/:condominio/esercizi/:esercizio/scritture', { condominio: props.condominio.id, esercizio: props.esercizio.id });
    return hrefStatoPatrimoniale.value;
});
const backText = computed(() => ({ 'casse': 'Risorse e fondi', 'libro-giornale': 'Libro Giornale' } as Record<string, string>)[props.filters.da ?? ''] ?? 'Stato patrimoniale');

const columns = createColumns();

const filtrato = computed(() => !!(props.filters.search || props.filters.data_da || props.filters.data_a));

const etichettaSaldo = computed(() => props.riepilogo.data_riferimento
    ? `Saldo al ${formatData(props.riepilogo.data_riferimento)}`
    : `Saldo al ${formatData(props.periodo.al)}`);

/** Stessi filtri della pagina: si stampa quello che si sta guardando. */
function stampa() {
    const { da: _da, ...filtri } = props.filters;
    window.open(route(generateRoute('gestionale.esercizi.conti.movimenti.print'), {
        condominio: props.condominio.id,
        esercizio: props.esercizio.id,
        contoContabile: props.conto.id,
        ...filtri,
    }), '_blank');
}

/** Il libro mastro: tutti i conti, il periodo intero, senza filtri. */
function stampaLibroMastro() {
    window.open(route(generateRoute('gestionale.esercizi.libro-mastro.print'), {
        condominio: props.condominio.id,
        esercizio: props.esercizio.id,
    }), '_blank');
}

const versoNaturale = computed(() => props.conto.natura_dare ? 'dare meno avere' : 'avere meno dare');
const controNatura = computed(() => props.conto.natura_dare
    ? 'una cassa sotto zero, un credito che diventa un debito'
    : 'un fornitore a credito, un debito che diventa un credito');

const pageGuides = computed(() => [
    {
        title: 'Il mastrino di un conto',
        description: `Tutte le righe di partita doppia registrate su «${props.conto.nome}» con data nel periodo, in ordine di data, con dare, avere e saldo progressivo. È il dettaglio della riga dello Stato patrimoniale da cui sei arrivato: l'ultimo saldo è quello.`,
        icon: BookOpen,
        colorVariant: 'blue' as const,
        link: { label: 'Torna allo Stato patrimoniale', href: hrefStatoPatrimoniale.value },
    },
    {
        title: 'Il saldo parte dal riporto',
        description: `La prima riga è il riporto: quanto c'era sul conto il ${formatData(giornoPrima.value)}, con dentro tutto ciò che precede il periodo. Diversamente dalla Prima nota, che parte da zero a ogni esercizio e non mostra i trasferimenti verso i fondi, qui il saldo parte dal riporto, gli accantonamenti sono righe, e l'ultimo saldo è la fotografia dello Stato patrimoniale ${props.periodo.stato === 'chiuso' ? `al ${formatData(props.periodo.al)}` : 'di oggi'}.`,
        icon: CornerDownRight,
        colorVariant: 'emerald' as const,
    },
    {
        title: 'Il verso del saldo',
        description: `Il saldo è nel verso naturale del conto — ${versoNaturale.value} — come nella situazione patrimoniale. Un importo negativo è contro natura: ${controNatura.value}.`,
        icon: ArrowLeftRight,
        colorVariant: 'amber' as const,
    },
]);
</script>

<template>
    <Head :title="`Mastrino ${conto.nome}`" />
    <GestionaleLayout>
        <div class="px-6 py-8 space-y-3">
            <PageHeaderGuide
                :page-title="conto.nome"
                :page-subtitle="periodo.stato === 'futuro' ? `Mastrino del conto ${conto.codice}: l'esercizio comincia il ${formatData(periodo.dal)}, il saldo è la fotografia al ${formatData(periodo.al)}.` : `Mastrino del conto ${conto.codice}: dare, avere e saldo progressivo dal ${formatData(periodo.dal)} al ${formatData(periodo.al)}.`"
                :back-url="backUrl"
                :back-text="backText"
                :guides="pageGuides"
                :breadcrumbs="(headerBreadcrumbs as any)"
                :condominio="(props.condominio as any)"
                :condomini="[]"
                :esercizio="(props.esercizio as any)"
                :esercizi="(props.esercizi as any)"
            >
                <template #actions>
                    <DropdownMenu>
                        <DropdownMenuTrigger as-child>
                            <Button variant="outline" class="h-8 px-3 border-slate-200 text-slate-700 bg-white hover:bg-slate-50 shadow-sm shrink-0 gap-2">
                                <Printer class="w-4 h-4" />
                                <span class="hidden sm:inline">Stampa</span>
                                <ChevronDown class="w-3.5 h-3.5 text-slate-400" />
                            </Button>
                        </DropdownMenuTrigger>
                        <DropdownMenuContent align="end" class="w-72">
                            <DropdownMenuItem class="flex-col items-start gap-0.5 py-2" @click="stampa">
                                <span class="font-medium">Questo conto</span>
                                <span class="text-[11px] text-slate-500 leading-snug">{{ filtrato ? 'Con i filtri attivi: un estratto parziale, dichiarato sul foglio.' : `Il mastrino di ${conto.nome}, periodo intero.` }}</span>
                            </DropdownMenuItem>
                            <DropdownMenuItem class="flex-col items-start gap-0.5 py-2" @click="stampaLibroMastro">
                                <span class="font-medium">Tutti i conti — libro mastro</span>
                                <span class="text-[11px] text-slate-500 leading-snug">Un foglio solo con il mastrino di ogni conto movimentato, un conto per pagina, senza filtri.</span>
                            </DropdownMenuItem>
                        </DropdownMenuContent>
                    </DropdownMenu>
                </template>
            </PageHeaderGuide>

            <div class="w-full">
                <section class="w-full space-y-4">
                    <MovimentiLayout>
                        <div v-if="flashMessage" class="mb-4">
                            <Alert :message="flashMessage.message" :type="flashMessage.type" />
                        </div>

                        <div v-if="periodo.stato === 'futuro'" class="mb-4 rounded-lg border border-amber-200 bg-amber-50 p-3 text-[12px] text-amber-900 flex items-start gap-2">
                            <AlertTriangle class="w-4 h-4 mt-0.5 shrink-0" />
                            <div>Questo esercizio inizia il <b>{{ formatData(periodo.dal) }}</b> e non è ancora cominciato: il periodo è vuoto e il saldo è tutto riporto.</div>
                        </div>

                        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 mb-4">
                            <div class="border rounded-xl p-4 shadow-sm flex items-center gap-3 bg-slate-50/50 border-slate-200">
                                <div class="p-2.5 rounded-lg border shrink-0 bg-slate-100 border-slate-200">
                                    <CornerDownRight class="w-5 h-5 text-slate-600" />
                                </div>
                                <div class="min-w-0">
                                    <div class="text-[11px] uppercase tracking-wider text-slate-500 font-bold">Riporto al {{ formatData(giornoPrima) }}</div>
                                    <div class="text-lg font-bold tabular-nums" :class="riepilogo.riporto < 0 ? 'text-rose-600' : 'text-slate-700'">{{ euro(riepilogo.riporto) }}</div>
                                </div>
                            </div>
                            <div class="border rounded-xl p-4 shadow-sm flex items-center gap-3 bg-white border-slate-200">
                                <div class="min-w-0">
                                    <div class="text-[11px] uppercase tracking-wider text-slate-500 font-bold">Totale dare{{ filtrato ? ' (parziale)' : '' }}</div>
                                    <div class="text-lg font-bold tabular-nums text-slate-700">{{ euro(riepilogo.totale_dare) }}</div>
                                </div>
                            </div>
                            <div class="border rounded-xl p-4 shadow-sm flex items-center gap-3 bg-white border-slate-200">
                                <div class="min-w-0">
                                    <div class="text-[11px] uppercase tracking-wider text-slate-500 font-bold">Totale avere{{ filtrato ? ' (parziale)' : '' }}</div>
                                    <div class="text-lg font-bold tabular-nums text-slate-700">{{ euro(riepilogo.totale_avere) }}</div>
                                </div>
                            </div>
                            <div class="border rounded-xl p-4 shadow-sm flex items-center gap-3 bg-emerald-50/50 border-emerald-200">
                                <div class="p-2.5 rounded-lg border shrink-0 bg-emerald-100 border-emerald-200">
                                    <Scale class="w-5 h-5 text-emerald-600" />
                                </div>
                                <div class="min-w-0">
                                    <div class="text-[11px] uppercase tracking-wider text-slate-500 font-bold">{{ etichettaSaldo }}</div>
                                    <div class="text-lg font-bold tabular-nums" :class="riepilogo.saldo_alla_data < 0 ? 'text-rose-600' : 'text-slate-700'">{{ euro(riepilogo.saldo_alla_data) }}</div>
                                    <div v-if="!filtrato" class="text-[11px] text-slate-500">È il saldo di questo conto nella situazione patrimoniale al {{ formatData(periodo.al) }}.</div>
                                    <div v-else class="text-[11px] text-slate-500">Saldo vero a quella data, non il netto delle righe mostrate. Senza filtri: {{ euro(riepilogo.saldo_finale) }} al {{ formatData(periodo.al) }}.</div>
                                </div>
                            </div>
                        </div>

                        <!-- D21.9: ciò che il mastrino dichiara invece di nascondere. -->
                        <div v-if="riepilogo.apertura_non_registrata !== 0" class="mb-3 rounded-lg border border-amber-200 bg-amber-50 p-3 text-[12px] text-amber-900 flex items-start gap-2">
                            <AlertTriangle class="w-4 h-4 mt-0.5 shrink-0" />
                            <div>
                                <b>Saldo di apertura non ancora registrato a giornale: {{ euro(riepilogo.apertura_non_registrata) }}.</b>
                                Non è una riga di questo mastrino e non è compreso nel saldo: la situazione patrimoniale lo conta a parte, e il controllo di quadratura lo segnala.
                                <Link :href="generatePath('gestionale/:condominio/esercizi/:esercizio/scritture', { condominio: condominio.id, esercizio: esercizio.id })" class="font-semibold underline">Si registra dal Libro Giornale</Link>.
                            </div>
                        </div>
                        <div v-if="riepilogo.altri_esercizi > 0" class="mb-3 rounded-lg border border-amber-200 bg-amber-50 p-3 text-[12px] text-amber-900 flex items-start gap-2">
                            <AlertTriangle class="w-4 h-4 mt-0.5 shrink-0" />
                            <div>
                                <b>{{ riepilogo.altri_esercizi }} {{ riepilogo.altri_esercizi === 1 ? 'riga appartiene' : 'righe appartengono' }} a un altro esercizio</b> ma con una data dentro questo periodo: {{ riepilogo.altri_esercizi === 1 ? 'è marcata' : 'sono marcate' }} con il nome del suo esercizio. È la stessa anomalia che il controllo «liquidità e riepilogo» dello
                                <Link :href="hrefStatoPatrimoniale" class="font-semibold underline">Stato patrimoniale</Link> segnala.
                            </div>
                        </div>
                        <div v-if="riepilogo.riporto_proprie > 0" class="mb-3 rounded-lg border border-amber-200 bg-amber-50 p-3 text-[12px] text-amber-900 flex items-start gap-2">
                            <AlertTriangle class="w-4 h-4 mt-0.5 shrink-0" />
                            <div><b>{{ riepilogo.riporto_proprie }} {{ riepilogo.riporto_proprie === 1 ? 'riga di questo esercizio è datata' : 'righe di questo esercizio sono datate' }} prima del {{ formatData(periodo.dal) }}</b> e {{ riepilogo.riporto_proprie === 1 ? 'sta' : 'stanno' }} nel riporto, non fra le righe del periodo: il riporto non contiene solo gli esercizi precedenti.</div>
                        </div>
                        <div v-if="riepilogo.postdatate > 0" class="mb-3 rounded-lg border border-slate-200 bg-slate-50 p-3 text-[12px] text-slate-700 flex items-start gap-2">
                            <AlertTriangle class="w-4 h-4 mt-0.5 shrink-0 text-slate-400" />
                            <div>{{ riepilogo.postdatate }} {{ riepilogo.postdatate === 1 ? 'movimento datato' : 'movimenti datati' }} dopo il {{ formatData(periodo.al) }} non {{ riepilogo.postdatate === 1 ? 'compare' : 'compaiono' }}: il mastrino si ferma a oggi, come la fotografia.<template v-if="periodo.stato === 'futuro'"> Fra oggi e l'inizio dell'esercizio non {{ riepilogo.postdatate === 1 ? 'è' : 'sono' }} né riporto né righe.</template></div>
                        </div>

                        <DataTable
                            :columns="columns"
                            :data="righe.data"
                            :condominio="props.condominio"
                            :esercizio="props.esercizio"
                            :conto-id="conto.id"
                            :riporto="riepilogo.riporto"
                            :giorno-prima="giornoPrima"
                            :totale-righe="riepilogo.totale_righe"
                            :periodo="periodo"
                            :meta="righe.meta"
                        />
                    </MovimentiLayout>
                </section>
            </div>
        </div>
    </GestionaleLayout>
</template>
