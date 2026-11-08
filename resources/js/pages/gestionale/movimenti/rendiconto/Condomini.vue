<script setup lang="ts">
/**
 * La scheda «Condòmini» del rendiconto: le posizioni per persona e unità (§ 9) e i crediti per anzianità, una riga per rata non pagata (§ 10), alla
 * data del rendiconto. Un elenco per volta, ognuno con la sua richiesta (`?elenco=crediti` per il secondo): le due letture insieme, in un condominio
 * grande, non stanno nella memoria del server. Ogni numero arriva dal server (`PosizioniDeiCondomini`): la pagina non somma niente, formatta le date e
 * gli importi. L'elenco è a pagine; i totali e le righe che li legano sono di tutte le righe.
 */
import { computed } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import GestionaleLayout from '@/layouts/GestionaleLayout.vue';
import MovimentiLayout from '@/layouts/gestionale/MovimentiLayout.vue';
import PageHeaderGuide from '@/components/PageHeaderGuide.vue';
import RendicontoSchede from '@/components/gestionale/movimenti/rendiconto/RendicontoSchede.vue';
import { Button } from '@/components/ui/button';
import { usePermission } from '@/composables/permissions';
import { useCurrencyFormatter } from '@/composables/useCurrencyFormatter';
import { Printer, Users, Clock } from 'lucide-vue-next';
import type { Building } from '@/types/buildings';
import type { Esercizio } from '@/types/gestionale/esercizi';
import { NOTA_PREGRESSO_NELLA_RATA } from '@/lib/gestionale/debiti/rateScadute';

interface Pagine { current_page: number; last_page: number; per_page: number; total: number; from: number | null; to: number | null; nome: string }

interface Posizione {
    anagrafica_id: number; nome: string; immobile_id: number | null; unita: string | null;
    ordinarie: number; straordinarie: number; precedenti: number; successivi: number; non_emesse: number; crediti: number; totale: number; anticipi: number;
    /** Il pregresso spalmato nelle rate sta dentro le quote dell'esercizio: la riga lo segna. */
    pregresso_nella_rata: boolean;
}

interface Credito {
    anagrafica_id: number; nome: string; immobile_id: number | null; unita: string | null; rata_id: number; numero_rata: number; piano: string; gestione: string;
    importo: number; scadenza: string; scaduta: boolean; giorni: number | null; fascia: '1_30' | '31_90' | 'oltre_90' | null; origine: string | null; pregresso_nella_rata: boolean;
    annata_scadenza: string; annata_competenza: string; versato_dopo: number | null; resta_oggi: number | null; lettera: { tipo: string; data: string } | null;
}

interface Posizioni {
    alla: string; finito: boolean; righe: Posizione[]; pagine: Pagine;
    totali: Omit<Posizione, 'anagrafica_id' | 'nome' | 'immobile_id' | 'unita' | 'pregresso_nella_rata'>;
    mostra: { successivi: boolean; non_emesse: boolean; anticipi: boolean };
    riga_crediti: { crediti: number; posizioni: number; spese_anticipate: number; differenza: number };
    riga_anticipi: { anticipi: number; conto: number; differenza: number };
    avvertenze: { data: string; segno: string; ordine: string; esercizio_della_rata: string; anticipi: string; riservato: string };
}

interface Crediti {
    alla: string; oggi: string; finito: boolean; ordine: 'scaduto' | 'persona'; righe: Credito[]; pagine: Pagine;
    totali: { righe: number; persone: number; importo: number; scaduto: number; fasce: Record<'1_30' | '31_90' | 'oltre_90', number>; versato_dopo: number | null; resta_oggi: number | null };
    avvertenze: { data: string; segno: string; ordine: string; annate: string; versato_dopo: string | null; lettera: string; riservato: string };
}

const props = defineProps<{
    condominio: Building; condomini: Building[]; esercizio: Esercizio; esercizi: Esercizio[];
    elenco: 'posizioni' | 'crediti'; posizioni: Posizioni | null; crediti: Crediti | null; troppe: string | null;
}>();

const { generatePath, generateRoute } = usePermission();
const { euro } = useCurrencyFormatter();
const p = computed(() => props.posizioni);
const c = computed(() => props.crediti);
const avvertenze = computed(() => p.value?.avvertenze ?? c.value?.avvertenze ?? null);

const formatData = (iso: string) => {
    const [anno, mese, giorno] = iso.slice(0, 10).split('-');
    return `${giorno}/${mese}/${anno}`;
};
const cella = (v: number) => (v === 0 ? '' : euro(v));
const fasce = { '1_30': '1–30 giorni', '31_90': '31–90 giorni', oltre_90: 'oltre 90 giorni' } as const;
const ritardo = (r: Credito) => (r.origine !== null && r.giorni === null ? r.origine : (r.scaduta && r.fascia ? `${r.giorni} giorni, ${fasce[r.fascia]}` : 'non ancora scaduta'));

const base = computed(() => generatePath('gestionale/:condominio/esercizi/:esercizio/rendiconto/condomini', { condominio: props.condominio.id, esercizio: props.esercizio.id }));
const hrefElenco = (elenco: 'posizioni' | 'crediti') => (elenco === 'crediti' ? `${base.value}?elenco=crediti` : base.value);
const conParametri = (extra: Record<string, string | number>) => {
    const q = new URLSearchParams();
    if (props.elenco === 'crediti') q.set('elenco', 'crediti');
    if (c.value && c.value.ordine !== 'scaduto') q.set('ordine', c.value.ordine);
    if (p.value && p.value.pagine.current_page > 1) q.set('pagina_posizioni', String(p.value.pagine.current_page));
    if (c.value && c.value.pagine.current_page > 1) q.set('pagina_crediti', String(c.value.pagine.current_page));
    for (const [k, v] of Object.entries(extra)) q.set(k, String(v));
    const s = q.toString();
    return s ? `${base.value}?${s}` : base.value;
};
const hrefOrdine = (ordine: 'scaduto' | 'persona') => conParametri({ ordine, pagina_crediti: 1 });
const hrefPagina = (nome: string, n: number) => conParametri({ [nome]: n });
const hrefEstratto = (id: number, scheda?: string) => generatePath('gestionale/:condominio/anagrafiche/:anagrafica/estratto-conto', { condominio: props.condominio.id, anagrafica: id })
    + (scheda ? `?scheda=${scheda}` : '');
const hrefRateScadute = computed(() => `${generatePath('gestionale/:condominio/rate-scadute', { condominio: props.condominio.id })}?alla=${c.value?.alla ?? ''}`);

const stampa = (nome: string) => {
    window.open(route(generateRoute(nome), { condominio: props.condominio.id, esercizio: props.esercizio.id }) + (nome.endsWith('crediti.stampa') && c.value && c.value.ordine !== 'scaduto' ? `?ordine=${c.value.ordine}` : ''), '_blank');
};

const headerBreadcrumbs = computed(() => [
    { title: 'Gestionale', href: generatePath('gestionale/:condominio', { condominio: props.condominio.id }) },
    { title: 'Movimenti' },
    { title: 'Rendiconto' },
    { title: 'Condòmini' },
]);

const pageGuides = [
    { title: 'Le posizioni', description: 'Per persona e unità, quanto deve alla data: le quote dell\'esercizio, quelle degli esercizi precedenti con il pregresso, i crediti sulle rate con il segno meno. Gli anticipi sono un debito del condominio e stanno a parte.', icon: Users, colorVariant: 'blue' as const },
    { title: 'I crediti per anzianità', description: 'Una riga per rata non pagata, con i giorni di ritardo e le due annate: l\'esercizio della scadenza e quello del piano. Lo scaduto è quello di «Rate scadute» alla stessa data.', icon: Clock, colorVariant: 'amber' as const },
];
</script>

<template>
    <Head title="Condòmini" />
    <GestionaleLayout>
        <div class="px-6 py-8 space-y-3">
            <PageHeaderGuide
                page-title="Condòmini"
                :page-subtitle="`${props.esercizio.nome}: posizioni e crediti per anzianità${avvertenze ? `, ${avvertenze.data}` : ''}.`"
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
                        <RendicontoSchede :condominio-id="props.condominio.id" :esercizio-id="props.esercizio.id" attiva="condomini" />

                        <nav class="mb-4 flex gap-1 text-sm" aria-label="Gli elenchi dei condòmini" data-test="elenchi">
                            <Link :href="hrefElenco('posizioni')" class="px-3 py-1.5 rounded-md border" :class="props.elenco === 'posizioni' ? 'bg-slate-100 dark:bg-slate-800 font-semibold text-slate-900 dark:text-slate-100' : 'text-slate-500 dark:text-slate-400'"
                                :aria-current="props.elenco === 'posizioni' ? 'page' : undefined">Posizioni</Link>
                            <Link :href="hrefElenco('crediti')" class="px-3 py-1.5 rounded-md border" :class="props.elenco === 'crediti' ? 'bg-slate-100 dark:bg-slate-800 font-semibold text-slate-900 dark:text-slate-100' : 'text-slate-500 dark:text-slate-400'"
                                :aria-current="props.elenco === 'crediti' ? 'page' : undefined">Crediti per anzianità</Link>
                        </nav>

                        <p v-if="avvertenze" class="text-[12px] text-slate-500 dark:text-slate-400 mb-4" data-test="come-leggere">
                            {{ avvertenze.segno }} Sono i dati di tutti i condòmini. {{ avvertenze.riservato }}
                        </p>

                        <p v-if="props.troppe" class="mb-6 rounded-lg border border-amber-200 dark:border-amber-900 bg-amber-50 dark:bg-amber-900/20 p-3 text-[13px] text-amber-900 dark:text-amber-200" data-test="troppe">{{ props.troppe }}</p>

                        <!-- ═══ § 9 Le posizioni ═══ -->
                        <div v-if="p" class="mb-10" data-test="posizioni">
                            <div class="flex items-center justify-between mb-2">
                                <h2 class="text-base font-semibold text-slate-800 dark:text-slate-200">Posizioni dei condòmini</h2>
                                <Button variant="outline" class="h-8 px-3 gap-2" data-test="stampa-posizioni" @click="stampa('gestionale.esercizi.rendiconto.condomini.posizioni.stampa')"><Printer class="w-4 h-4" /> Stampa</Button>
                            </div>
                            <p class="text-[12px] text-slate-500 dark:text-slate-400 mb-2">{{ p.avvertenze.ordine }} {{ p.avvertenze.esercizio_della_rata }} {{ p.avvertenze.anticipi }}</p>
                            <div class="overflow-x-auto">
                                <table class="w-full text-sm min-w-[860px]">
                                    <thead>
                                        <tr class="border-b border-slate-200 dark:border-slate-800 text-[11px] uppercase tracking-wider text-slate-500 dark:text-slate-400">
                                            <th class="text-left py-2 pr-3">Persona</th>
                                            <th class="text-left py-2 pr-3">Unità</th>
                                            <th class="text-right py-2 pr-3">Ordinarie dell'esercizio</th>
                                            <th class="text-right py-2 pr-3">Straordinarie dell'esercizio</th>
                                            <th class="text-right py-2 pr-3">Esercizi precedenti</th>
                                            <th v-if="p.mostra.successivi" class="text-right py-2 pr-3">Esercizi successivi</th>
                                            <th v-if="p.mostra.non_emesse" class="text-right py-2 pr-3">Su rate non emesse</th>
                                            <th class="text-right py-2 pr-3">Crediti sulle rate</th>
                                            <th class="text-right py-2 pr-3">Totale</th>
                                            <th v-if="p.mostra.anticipi" class="text-right py-2">Anticipi</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <tr v-for="r in p.righe" :key="`${r.anagrafica_id}-${r.immobile_id}`" class="border-b border-slate-100 dark:border-slate-800" data-test="riga-posizione">
                                            <td class="py-1.5 pr-3"><Link :href="hrefEstratto(r.anagrafica_id)" class="underline decoration-dotted underline-offset-4">{{ r.nome }}</Link></td>
                                            <td class="py-1.5 pr-3 text-slate-600 dark:text-slate-400">{{ r.unita ?? '—' }}<span v-if="r.pregresso_nella_rata" class="block text-[11px] text-slate-400 dark:text-slate-500">contiene anche una parte di pregresso</span></td>
                                            <td class="py-1.5 pr-3 text-right tabular-nums">{{ cella(r.ordinarie) }}</td>
                                            <td class="py-1.5 pr-3 text-right tabular-nums">{{ cella(r.straordinarie) }}</td>
                                            <td class="py-1.5 pr-3 text-right tabular-nums">{{ cella(r.precedenti) }}</td>
                                            <td v-if="p.mostra.successivi" class="py-1.5 pr-3 text-right tabular-nums">{{ cella(r.successivi) }}</td>
                                            <td v-if="p.mostra.non_emesse" class="py-1.5 pr-3 text-right tabular-nums">{{ cella(r.non_emesse) }}</td>
                                            <td class="py-1.5 pr-3 text-right tabular-nums text-emerald-700 dark:text-emerald-400">{{ cella(r.crediti) }}</td>
                                            <td class="py-1.5 pr-3 text-right tabular-nums font-semibold">{{ euro(r.totale) }}</td>
                                            <td v-if="p.mostra.anticipi" class="py-1.5 text-right tabular-nums text-emerald-700 dark:text-emerald-400">{{ cella(r.anticipi) }}</td>
                                        </tr>
                                        <tr v-if="p.righe.length === 0"><td colspan="10" class="py-4 text-center text-slate-400 dark:text-slate-500 italic">Nessuna posizione diversa da zero a questa data</td></tr>
                                    </tbody>
                                    <tfoot>
                                        <tr class="border-t-2 border-slate-300 dark:border-slate-700 font-bold text-slate-800 dark:text-slate-200" data-test="totali-posizioni">
                                            <td class="py-2 pr-3" colspan="2">Totale</td>
                                            <td class="py-2 pr-3 text-right tabular-nums">{{ euro(p.totali.ordinarie) }}</td>
                                            <td class="py-2 pr-3 text-right tabular-nums">{{ euro(p.totali.straordinarie) }}</td>
                                            <td class="py-2 pr-3 text-right tabular-nums">{{ euro(p.totali.precedenti) }}</td>
                                            <td v-if="p.mostra.successivi" class="py-2 pr-3 text-right tabular-nums">{{ euro(p.totali.successivi) }}</td>
                                            <td v-if="p.mostra.non_emesse" class="py-2 pr-3 text-right tabular-nums">{{ euro(p.totali.non_emesse) }}</td>
                                            <td class="py-2 pr-3 text-right tabular-nums">{{ euro(p.totali.crediti) }}</td>
                                            <td class="py-2 pr-3 text-right tabular-nums">{{ euro(p.totali.totale) }}</td>
                                            <td v-if="p.mostra.anticipi" class="py-2 text-right tabular-nums">{{ euro(p.totali.anticipi) }}</td>
                                        </tr>
                                    </tfoot>
                                </table>
                            </div>
                            <div v-if="p.pagine.last_page > 1" class="flex gap-3 text-[12px] mt-2" data-test="pagine-posizioni">
                                <span class="text-slate-500 dark:text-slate-400">Righe {{ p.pagine.from }}–{{ p.pagine.to }} di {{ p.pagine.total }}</span>
                                <Link v-if="p.pagine.current_page > 1" :href="hrefPagina('pagina_posizioni', p.pagine.current_page - 1)" class="underline">Precedenti</Link>
                                <Link v-if="p.pagine.current_page < p.pagine.last_page" :href="hrefPagina('pagina_posizioni', p.pagine.current_page + 1)" class="underline">Successive</Link>
                            </div>
                            <p class="text-[12px] text-slate-600 dark:text-slate-400 mt-3" data-test="riga-crediti">
                                Crediti verso i condòmini della situazione patrimoniale {{ euro(p.riga_crediti.crediti) }} = posizioni senza gli anticipi {{ euro(p.riga_crediti.posizioni) }}
                                + spese anticipate per singole unità, non ancora chieste {{ euro(p.riga_crediti.spese_anticipate) }}<template v-if="p.riga_crediti.differenza !== 0">; differenza <b class="text-rose-700 dark:text-rose-400">{{ euro(p.riga_crediti.differenza) }}</b></template>.
                            </p>
                            <p class="text-[12px] text-slate-600 dark:text-slate-400" data-test="riga-anticipi">
                                Anticipi ricevuti dai condòmini {{ euro(p.riga_anticipi.anticipi) }} = conto «Anticipi ricevuti dai condòmini» {{ euro(p.riga_anticipi.conto) }}<template v-if="p.riga_anticipi.differenza !== 0">; differenza <b class="text-rose-700 dark:text-rose-400">{{ euro(p.riga_anticipi.differenza) }}</b></template>.
                            </p>
                        </div>

                        <!-- ═══ § 10 I crediti per anzianità ═══ -->
                        <div v-if="c" data-test="crediti">
                            <div class="flex items-center justify-between mb-2">
                                <h2 class="text-base font-semibold text-slate-800 dark:text-slate-200">Crediti per anzianità</h2>
                                <Button variant="outline" class="h-8 px-3 gap-2" data-test="stampa-crediti" @click="stampa('gestionale.esercizi.rendiconto.condomini.crediti.stampa')"><Printer class="w-4 h-4" /> Stampa</Button>
                            </div>
                            <p class="text-[12px] text-slate-600 dark:text-slate-400 mb-1" data-test="totali-crediti">
                                Rate non pagate {{ euro(c.totali.importo) }}, di cui scadute {{ euro(c.totali.scaduto) }}: 1–30 giorni {{ euro(c.totali.fasce['1_30']) }}, 31–90 giorni {{ euro(c.totali.fasce['31_90']) }},
                                oltre 90 giorni {{ euro(c.totali.fasce.oltre_90) }}. Lo scaduto è quello di <Link :href="hrefRateScadute" class="underline">«Rate scadute» al {{ formatData(c.alla) }}</Link>.
                            </p>
                            <p class="text-[12px] text-slate-500 dark:text-slate-400 mb-2">
                                {{ c.avvertenze.ordine }}
                                <Link v-if="c.ordine === 'scaduto'" :href="hrefOrdine('persona')" class="underline" data-test="ordine">In ordine di persona</Link>
                                <Link v-else :href="hrefOrdine('scaduto')" class="underline" data-test="ordine">In ordine di scaduto</Link>.
                                {{ c.avvertenze.annate }} <template v-if="c.avvertenze.versato_dopo">{{ c.avvertenze.versato_dopo }}</template> {{ c.avvertenze.lettera }}
                            </p>
                            <div class="overflow-x-auto">
                                <table class="w-full text-sm min-w-[980px]">
                                    <thead>
                                        <tr class="border-b border-slate-200 dark:border-slate-800 text-[11px] uppercase tracking-wider text-slate-500 dark:text-slate-400">
                                            <th class="text-left py-2 pr-3">Persona</th>
                                            <th class="text-left py-2 pr-3">Unità</th>
                                            <th class="text-left py-2 pr-3">Rata</th>
                                            <th class="text-right py-2 pr-3">Importo</th>
                                            <th class="text-left py-2 pr-3">Scadenza</th>
                                            <th class="text-left py-2 pr-3">Ritardo</th>
                                            <th class="text-left py-2 pr-3">Annata della scadenza</th>
                                            <th class="text-left py-2 pr-3">Annata del piano</th>
                                            <th v-if="c.finito" class="text-right py-2 pr-3">Versato dopo</th>
                                            <th v-if="c.finito" class="text-right py-2 pr-3">Resta oggi</th>
                                            <th class="text-left py-2">Ultima lettera partita o spedita</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <tr v-for="r in c.righe" :key="`${r.anagrafica_id}-${r.immobile_id}-${r.rata_id}`" class="border-b border-slate-100 dark:border-slate-800" data-test="riga-credito">
                                            <td class="py-1.5 pr-3"><Link :href="hrefEstratto(r.anagrafica_id)" class="underline decoration-dotted underline-offset-4">{{ r.nome }}</Link></td>
                                            <td class="py-1.5 pr-3 text-slate-600 dark:text-slate-400">{{ r.unita ?? '—' }}</td>
                                            <td class="py-1.5 pr-3">{{ r.piano }}, rata {{ r.numero_rata }} <span class="text-[11px] text-slate-400 dark:text-slate-500">· {{ r.gestione }}</span>
                                                <span v-if="r.pregresso_nella_rata" class="block text-[11px] text-slate-400 dark:text-slate-500">{{ NOTA_PREGRESSO_NELLA_RATA }}</span></td>
                                            <td class="py-1.5 pr-3 text-right tabular-nums">{{ euro(r.importo) }}</td>
                                            <td class="py-1.5 pr-3 tabular-nums">{{ formatData(r.scadenza) }}</td>
                                            <td class="py-1.5 pr-3" :class="r.scaduta ? 'text-rose-700 dark:text-rose-400' : 'text-slate-500 dark:text-slate-400'">{{ ritardo(r) }}</td>
                                            <td class="py-1.5 pr-3">{{ r.annata_scadenza }}</td>
                                            <td class="py-1.5 pr-3">{{ r.annata_competenza }}</td>
                                            <td v-if="c.finito" class="py-1.5 pr-3 text-right tabular-nums">{{ r.versato_dopo ? euro(r.versato_dopo) : '' }}</td>
                                            <td v-if="c.finito" class="py-1.5 pr-3 text-right tabular-nums">{{ euro(r.resta_oggi ?? 0) }}</td>
                                            <td class="py-1.5">
                                                <Link v-if="r.lettera" :href="hrefEstratto(r.anagrafica_id, 'corrispondenza')" class="underline decoration-dotted underline-offset-4">{{ r.lettera.tipo }} del {{ formatData(r.lettera.data) }}</Link>
                                            </td>
                                        </tr>
                                        <tr v-if="c.righe.length === 0"><td colspan="11" class="py-4 text-center text-slate-400 dark:text-slate-500 italic">Nessuna rata non pagata a questa data</td></tr>
                                    </tbody>
                                    <tfoot>
                                        <tr class="border-t-2 border-slate-300 dark:border-slate-700 font-bold text-slate-800 dark:text-slate-200">
                                            <td class="py-2 pr-3" colspan="3">Totale</td>
                                            <td class="py-2 pr-3 text-right tabular-nums">{{ euro(c.totali.importo) }}</td>
                                            <td colspan="4"></td>
                                            <td v-if="c.finito" class="py-2 pr-3 text-right tabular-nums">{{ euro(c.totali.versato_dopo ?? 0) }}</td>
                                            <td v-if="c.finito" class="py-2 pr-3 text-right tabular-nums">{{ euro(c.totali.resta_oggi ?? 0) }}</td>
                                            <td></td>
                                        </tr>
                                    </tfoot>
                                </table>
                            </div>
                            <div v-if="c.pagine.last_page > 1" class="flex gap-3 text-[12px] mt-2" data-test="pagine-crediti">
                                <span class="text-slate-500 dark:text-slate-400">Righe {{ c.pagine.from }}–{{ c.pagine.to }} di {{ c.pagine.total }}</span>
                                <Link v-if="c.pagine.current_page > 1" :href="hrefPagina('pagina_crediti', c.pagine.current_page - 1)" class="underline">Precedenti</Link>
                                <Link v-if="c.pagine.current_page < c.pagine.last_page" :href="hrefPagina('pagina_crediti', c.pagine.current_page + 1)" class="underline">Successive</Link>
                            </div>
                        </div>
                    </MovimentiLayout>
                </section>
            </div>
        </div>
    </GestionaleLayout>
</template>
