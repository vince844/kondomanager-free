<script setup lang="ts">
/**
 * La scheda «Cassa» del rendiconto: le entrate e le uscite per cassa dell'esercizio, dal riporto dell'esercizio prima, e il raccordo fra cassa e
 * competenza. Gli stessi numeri della situazione patrimoniale (`StatoPatrimonialePaginaService`): la pagina non somma niente.
 */
import { computed } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import GestionaleLayout from '@/layouts/GestionaleLayout.vue';
import MovimentiLayout from '@/layouts/gestionale/MovimentiLayout.vue';
import PageHeaderGuide from '@/components/PageHeaderGuide.vue';
import RendicontoSchede from '@/components/gestionale/movimenti/rendiconto/RendicontoSchede.vue';
import { usePermission } from '@/composables/permissions';
import { useCurrencyFormatter } from '@/composables/useCurrencyFormatter';
import { Button } from '@/components/ui/button';
import { Printer, Wallet, Landmark, Scale } from 'lucide-vue-next';
import type { Building } from '@/types/buildings';
import type { Esercizio } from '@/types/gestionale/esercizi';

interface RigaCassa { cassa_id: number; conto_contabile_id: number | null; cassa: string; tipo: string; iniziale: number; entrate: number; uscite: number; finale: number; negativa: boolean; minimo: number | null; minimo_il: string | null }
interface Cassa {
    data: string; stato_esercizio: 'futuro' | 'aperto' | 'chiuso';
    riepilogo: {
        dal: string; al: string; righe: RigaCassa[]; totale: { iniziale: number; entrate: number; uscite: number; finale: number };
        giroconti: { entrate: number; uscite: number }; stornate: { coppie: number; importo: number };
    };
    raccordo: { righe: { voce: string; natura: string; effetto: number }[]; somma: number; variazione_liquidita: number; scarto: number; quadra: boolean };
    risultato_gestione: { risultato: number };
}

const props = defineProps<{ condominio: Building; condomini: Building[]; esercizio: Esercizio; esercizi: Esercizio[]; cassa: Cassa }>();

const { generatePath, generateRoute } = usePermission();
const { euro } = useCurrencyFormatter();
const c = computed(() => props.cassa);
const base = { condominio: props.condominio.id, esercizio: props.esercizio.id };
const formatData = (iso: string | null) => { if (!iso) return '—'; const [a, m, g] = iso.slice(0, 10).split('-'); return `${g}/${m}/${a}`; };
const importo = (v: number) => (v < 0 ? 'text-rose-600 dark:text-rose-400' : 'text-slate-800 dark:text-slate-200');
// La cassa apre il registro di contabilità dell'esercizio, cercato su quella cassa.
const hrefRegistro = (r: RigaCassa) => `${generatePath('gestionale/:condominio/esercizi/:esercizio/registro-contabilita', base)}?search=${encodeURIComponent(r.cassa)}`;
const stampa = () => window.open(`${route(generateRoute('gestionale.esercizi.stato-patrimoniale.print'), base)}?parte=cassa`, '_blank');

const headerBreadcrumbs = computed(() => [
    { title: 'Gestionale', href: generatePath('gestionale/:condominio', { condominio: props.condominio.id }) },
    { title: 'Movimenti' },
    { title: 'Rendiconto' },
    { title: 'Cassa' },
]);
const pageGuides = [
    { title: 'Per cassa', description: 'Il denaro entrato e uscito dalle casse con le scritture dell\'esercizio. La disponibilità iniziale è il riporto dell\'esercizio prima, con la stessa regola del registro di contabilità: ogni movimento si conta una volta, nell\'esercizio in cui è scritto.', icon: Wallet, colorVariant: 'blue' as const },
    { title: 'Ogni cassa', description: 'Una riga per ogni cassa, con la disponibilità iniziale, le entrate, le uscite e quella finale. La banca o i contanti andati sotto zero nell\'esercizio, anche per un giorno, sono in rosso con il giorno e la cifra: un conto reale non può contenere meno di niente.', icon: Landmark, colorVariant: 'amber' as const },
    { title: 'Il raccordo con la competenza', description: 'Perché nell\'esercizio la liquidità è cambiata: il risultato di gestione, più o meno le variazioni dei crediti verso i condòmini, dei debiti verso i fornitori e l\'Erario, degli anticipi e dei riporti. Se la somma non torna, la riga dice di quanto.', icon: Scale, colorVariant: 'emerald' as const },
];
</script>

<template>
    <Head title="Entrate e uscite per cassa" />
    <GestionaleLayout>
        <div class="px-6 py-8 space-y-3">
            <PageHeaderGuide
                page-title="Entrate e uscite per cassa"
                :page-subtitle="`Dal ${formatData(c.riepilogo.dal)} al ${formatData(c.riepilogo.al)}, e il raccordo fra cassa e competenza.`"
                :guides="pageGuides"
                :breadcrumbs="(headerBreadcrumbs as any)"
                :condominio="(props.condominio as any)"
                :condomini="(props.condomini as any)"
                :esercizio="(props.esercizio as any)"
                :esercizi="(props.esercizi as any)"
            >
                <template #actions>
                    <Button variant="outline" class="h-8 px-3 gap-2" @click="stampa"><Printer class="w-4 h-4" /> Stampa</Button>
                </template>
            </PageHeaderGuide>

            <div class="w-full">
                <section class="w-full space-y-4">
                    <MovimentiLayout>
                        <RendicontoSchede :condominio-id="props.condominio.id" :esercizio-id="props.esercizio.id" attiva="cassa" />

                        <div class="rounded-md border bg-white dark:bg-slate-900 mb-6 overflow-x-auto" data-test="per-cassa">
                            <table class="w-full text-[13px] min-w-[640px]">
                                <thead>
                                    <tr class="bg-gray-50/50 dark:bg-slate-800/30 text-[11px] uppercase tracking-wider text-slate-500 dark:text-slate-400">
                                        <th class="text-left font-bold py-2 px-4">Cassa</th>
                                        <th class="text-right font-bold py-2 px-4">Disponibilità iniziale</th>
                                        <th class="text-right font-bold py-2 px-4">Entrate</th>
                                        <th class="text-right font-bold py-2 px-4">Uscite</th>
                                        <th class="text-right font-bold py-2 px-4">Disponibilità finale</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr v-for="r in c.riepilogo.righe" :key="r.cassa_id" class="border-b border-slate-100 dark:border-slate-800" :class="{ 'bg-rose-50 dark:bg-rose-900/20': r.negativa }" :data-cassa="r.cassa_id">
                                        <td class="py-2 px-4 text-slate-700 dark:text-slate-300">
                                            <Link :href="hrefRegistro(r)" class="underline decoration-dotted decoration-slate-300 dark:decoration-slate-600 underline-offset-4 hover:text-primary" title="Apri il registro di contabilità su questa cassa">{{ r.cassa }}</Link>
                                            <span class="text-[11px] text-slate-400 dark:text-slate-500"> · {{ r.tipo }}</span>
                                            <div v-if="r.negativa" class="text-[11px] text-rose-700 dark:text-rose-400 font-semibold">sotto zero: {{ euro(r.minimo ?? 0) }} il {{ formatData(r.minimo_il) }}</div>
                                        </td>
                                        <td class="py-2 px-4 text-right tabular-nums align-top" :class="importo(r.iniziale)">{{ euro(r.iniziale) }}</td>
                                        <td class="py-2 px-4 text-right tabular-nums align-top text-emerald-700 dark:text-emerald-400">{{ euro(r.entrate) }}</td>
                                        <td class="py-2 px-4 text-right tabular-nums align-top text-rose-700 dark:text-rose-400">{{ euro(r.uscite) }}</td>
                                        <td class="py-2 px-4 text-right tabular-nums align-top font-semibold" :class="importo(r.finale)">{{ euro(r.finale) }}</td>
                                    </tr>
                                    <tr v-if="c.riepilogo.righe.length === 0"><td colspan="5" class="py-4 text-center text-slate-400 dark:text-slate-500 italic">Nessuna cassa</td></tr>
                                </tbody>
                                <tfoot>
                                    <tr class="border-t-2 border-slate-300 dark:border-slate-700 font-bold text-slate-800 dark:text-slate-200">
                                        <td class="py-2 px-4">Tutte le casse, flussi con l'esterno</td>
                                        <td class="py-2 px-4 text-right tabular-nums">{{ euro(c.riepilogo.totale.iniziale) }}</td>
                                        <td class="py-2 px-4 text-right tabular-nums text-emerald-700 dark:text-emerald-400">{{ euro(c.riepilogo.totale.entrate) }}</td>
                                        <td class="py-2 px-4 text-right tabular-nums text-rose-700 dark:text-rose-400">{{ euro(c.riepilogo.totale.uscite) }}</td>
                                        <td class="py-2 px-4 text-right tabular-nums">{{ euro(c.riepilogo.totale.finale) }}</td>
                                    </tr>
                                </tfoot>
                            </table>
                            <p class="px-4 py-2 text-[11px] text-slate-500 dark:text-slate-400 border-t">
                                Ogni riga quadra da sé: iniziale + entrate − uscite = finale.
                                <template v-if="c.riepilogo.giroconti.entrate !== 0">I trasferimenti fra casse ({{ euro(c.riepilogo.giroconti.entrate) }}) compaiono nelle righe e non nel totale, che conta i soli flussi con l'esterno. </template>
                                <template v-if="c.riepilogo.stornate.coppie !== 0">{{ c.riepilogo.stornate.coppie === 1 ? 'Un movimento stornato' : c.riepilogo.stornate.coppie + ' movimenti stornati' }} nell'esercizio ({{ euro(c.riepilogo.stornate.importo) }}) non {{ c.riepilogo.stornate.coppie === 1 ? 'conta' : 'contano' }} né fra le entrate né fra le uscite: originale e storno si annullano, e restano leggibili nel registro di contabilità. </template>
                                La disponibilità iniziale è il riporto dell'esercizio prima, con la regola del registro di contabilità (un fondo aperto con un saldo suo conta qui e non nel registro); l'apertura di una cassa conta qui, non fra le entrate. Entrate e uscite sono le scritture dell'esercizio. Una cassa apre il registro su quella cassa.
                            </p>
                        </div>

                        <div class="rounded-md border bg-white dark:bg-slate-900 mb-6" data-test="raccordo">
                            <div class="px-4 py-3 border-b bg-gray-50/50 dark:bg-slate-800/30">
                                <h3 class="font-semibold text-slate-800 dark:text-slate-200">Raccordo fra cassa e competenza</h3>
                                <p class="text-[11px] text-slate-500 dark:text-slate-400 mt-0.5">Perché nell'esercizio la liquidità è cambiata di {{ euro(c.raccordo.variazione_liquidita) }} mentre il risultato di gestione è {{ euro(c.risultato_gestione.risultato) }}.</p>
                            </div>
                            <table class="w-full text-[13px]">
                                <tbody>
                                    <tr v-for="r in c.raccordo.righe" :key="r.voce" class="border-b border-slate-100 dark:border-slate-800">
                                        <td class="py-2 px-4 text-slate-700 dark:text-slate-300">{{ r.voce }}</td>
                                        <td class="py-2 px-4 text-slate-400 dark:text-slate-500 text-[12px]">{{ r.natura }}</td>
                                        <td class="py-2 px-4 text-right tabular-nums whitespace-nowrap" :class="importo(r.effetto)">{{ euro(r.effetto) }}</td>
                                    </tr>
                                </tbody>
                                <tfoot>
                                    <tr class="border-t-2 border-slate-300 dark:border-slate-700 font-bold text-slate-800 dark:text-slate-200">
                                        <td class="py-2 px-4" colspan="2">Variazione della liquidità nell'esercizio</td>
                                        <td class="py-2 px-4 text-right tabular-nums whitespace-nowrap" :class="c.raccordo.quadra ? 'text-emerald-700 dark:text-emerald-400' : 'text-rose-700 dark:text-rose-400'">{{ euro(c.raccordo.variazione_liquidita) }}</td>
                                    </tr>
                                </tfoot>
                            </table>
                            <p class="px-4 py-2 text-[11px] text-slate-500 dark:text-slate-400 border-t">
                                Variazione della liquidità = risultato di gestione − aumento dei crediti + aumento dei debiti. Ogni riga è una posta con il suo nome.
                                <span v-if="!c.raccordo.quadra" class="text-rose-700 dark:text-rose-400 font-semibold">Non torna per {{ euro(c.raccordo.scarto) }}: una scrittura dell'esercizio non quadra, e la diagnosi dello sbilancio nel Libro Giornale la trova.</span>
                            </p>
                        </div>
                    </MovimentiLayout>
                </section>
            </div>
        </div>
    </GestionaleLayout>
</template>
