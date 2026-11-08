<script setup lang="ts">
/**
 * La scheda «Controlli» del rendiconto: i controlli, con i numeri veri, e le registrazioni oltre il confine dell'esercizio nei due versi, con l'effetto
 * sul riepilogo, la tabella per tipo e l'elenco (a pagine, con i filtri). Nessun pulsante «correggi»: la regola che sposterà le registrazioni arriva
 * con la chiusura guidata. Ogni numero arriva dal server (`ControlliDelRendiconto`, `AnnoACavallo`).
 */
import { computed } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import GestionaleLayout from '@/layouts/GestionaleLayout.vue';
import MovimentiLayout from '@/layouts/gestionale/MovimentiLayout.vue';
import PageHeaderGuide from '@/components/PageHeaderGuide.vue';
import RendicontoSchede from '@/components/gestionale/movimenti/rendiconto/RendicontoSchede.vue';
import { usePermission } from '@/composables/permissions';
import { useCurrencyFormatter } from '@/composables/useCurrencyFormatter';
import { Button } from '@/components/ui/button';
import { CheckCircle2, XCircle, AlertTriangle, ArrowRight, ListChecks, CalendarRange, Printer } from 'lucide-vue-next';
import type { Building } from '@/types/buildings';
import type { Esercizio } from '@/types/gestionale/esercizi';

type Esito = 'quadra' | 'non_quadra' | 'segnalazione';
interface Controllo { id: string; nome: string; titolo: string; sinistra: number; destra: number; segno?: string; esito: Esito; nota: string; rimedio: string | null; azione: string | null; azione_parametro?: string | null }
interface RigaConfine { scrittura_id: number; verso: 'A' | 'B'; data: string; esercizio_id: number; esercizio: string; protocollo: string | null; tipo: string; categoria: string; etichetta: string; causale: string | null; importo: number; rossa: boolean; senza_esercizio: boolean; nota: string | null }
interface AnnoACavallo {
    dal: string; al: string; numero: number; rosse: number; da_leggere: number;
    versi: Record<'A' | 'B', { numero: number; rosse: number; da_leggere: number }>;
    effetto: Record<'A' | 'B', { quote: number; costi: number; entrate: number; pregresse: number; frase: string | null }>;
    per_tipo: { verso: 'A' | 'B'; tipo: string; etichetta: string; numero: number; importo: number; rosse: number }[];
    filtri: { verso: string | null; tipo: string | null; colore: string | null };
    righe_filtrate: number; righe: RigaConfine[];
    pagine: { current_page: number; last_page: number; per_page: number; total: number; from: number | null; to: number | null; nome: string };
    fuori_da_ogni_esercizio: { fuori: number; nel_periodo: number; prevale: boolean }; frase_norma: string; rimedi: string[];
}

const props = defineProps<{
    condominio: Building; condomini: Building[]; esercizio: Esercizio; esercizi: Esercizio[];
    controlli: Controllo[]; esito: { frase: string; riga_fissa: string }; anno_a_cavallo: AnnoACavallo;
}>();

const { generatePath, generateRoute } = usePermission();
const { euro } = useCurrencyFormatter();
const a = computed(() => props.anno_a_cavallo);
const base = { condominio: props.condominio.id, esercizio: props.esercizio.id };
const formatData = (iso: string) => { const [y, m, g] = iso.slice(0, 10).split('-'); return `${g}/${m}/${y}`; };

const coloreEsito = (e: Esito) => ({ quadra: 'text-emerald-700 dark:text-emerald-400', non_quadra: 'text-rose-700 dark:text-rose-400', segnalazione: 'text-amber-700 dark:text-amber-400' }[e]);
const iconaEsito = (e: Esito) => ({ quadra: CheckCircle2, non_quadra: XCircle, segnalazione: AlertTriangle }[e]);
const testoEsito = (e: Esito) => ({ quadra: 'torna', non_quadra: 'non torna', segnalazione: 'da leggere' }[e]);
const conteggio = (c: Controllo): string | null => {
    if (c.id === 'CN') return `${c.sinistra} ${c.sinistra === 1 ? 'cassa' : 'casse'} sotto zero`;
    if (c.id === 'AC') return `${c.sinistra} ${c.sinistra === 1 ? 'registrazione' : 'registrazioni'} oltre il confine`;
    if (c.id === 'GE') return `${c.sinistra} ${c.sinistra === 1 ? 'fattura' : 'fatture'} con voci di un'altra gestione`;
    return null;
};

const hrefAzione = (c: Controllo): string | null => {
    switch (c.azione) {
        case 'libro-giornale': return generatePath('gestionale/:condominio/esercizi/:esercizio/scritture', base);
        case 'prima-nota': return generatePath('gestionale/:condominio/esercizi/:esercizio/registro-contabilita', base) + (c.azione_parametro ? `?search=${encodeURIComponent(c.azione_parametro)}` : '');
        case 'giroconti': return generatePath('gestionale/:condominio/giroconti', { condominio: props.condominio.id });
        case 'casse': return generatePath('gestionale/:condominio/casse', { condominio: props.condominio.id });
        case 'esercizio': return generatePath('gestionale/:condominio/esercizi/:esercizio/edit', base);
        case 'cassa': return generatePath('gestionale/:condominio/casse/:cassa/edit', { condominio: props.condominio.id, cassa: c.azione_parametro ?? '' });
        case 'riepilogo': return generatePath('gestionale/:condominio/esercizi/:esercizio/rendiconto/riepilogo-finanziario', base);
        case 'condomini': return generatePath('gestionale/:condominio/esercizi/:esercizio/rendiconto/condomini', base);
        case 'anno-a-cavallo': return '#anno-a-cavallo';
        default: return null;
    }
};
const etichettaAzione = (az: string | null) => ({
    'libro-giornale': 'Apri il Libro Giornale', 'prima-nota': 'Apri il registro di contabilità', giroconti: 'Vai ai giroconti', casse: 'Vai alle casse',
    esercizio: 'Modifica l\'esercizio', cassa: 'Registra il saldo iniziale', riepilogo: 'Apri il riepilogo finanziario', condomini: 'Apri i condòmini',
    'anno-a-cavallo': 'Vedi l\'elenco',
}[az ?? ''] ?? 'Vai');

/** «Apri nel Libro Giornale»: il giornale dell'esercizio in cui la registrazione sta, cercato sul suo protocollo. */
const hrefGiornale = (r: RigaConfine) => {
    const giornale = generatePath('gestionale/:condominio/esercizi/:esercizio/scritture', { condominio: props.condominio.id, esercizio: r.esercizio_id });
    return r.protocollo ? `${giornale}?search=${encodeURIComponent(r.protocollo)}` : generatePath('gestionale/:condominio/scritture/:scrittura', { condominio: props.condominio.id, scrittura: r.scrittura_id });
};

const filtra = (chiave: 'verso' | 'tipo' | 'colore', valore: string | null) => {
    const filtri = { ...a.value.filtri, [chiave]: valore };
    const q = Object.fromEntries(Object.entries(filtri).filter(([, v]) => v !== null && v !== ''));
    router.get(generatePath('gestionale/:condominio/esercizi/:esercizio/rendiconto/controlli', base), q, { preserveScroll: true, preserveState: true, only: ['anno_a_cavallo'] });
};
/** Una pagina dell'elenco, con i filtri scelti e le righe per pagina di questa: senza, la pagina dopo si aprirebbe con le righe per pagina di prima. */
const hrefPagina = (n: number) => {
    const q = new URLSearchParams();
    Object.entries(a.value.filtri).forEach(([k, v]) => { if (v) q.set(k, v); });
    q.set('per_page', String(a.value.pagine.per_page));
    q.set('pagina_confine', String(n));
    return `${generatePath('gestionale/:condominio/esercizi/:esercizio/rendiconto/controlli', base)}?${q.toString()}`;
};
/** Una pagina scritta a mano nell'indirizzo, oltre l'ultima: non è un elenco vuoto per i filtri. */
const dopoLUltima = computed(() => a.value.righe.length === 0 && a.value.pagine.current_page > a.value.pagine.last_page);
/**
 * Il segno della riga: una fattura registrata come pregressa lo ha in tutti e due i versi (in rosso nella pagina dell'esercizio della sua data, da leggere in
 * quella dell'esercizio in cui sta); le altre righe in rosso dicono che cambiano la competenza, le righe da leggere non hanno segno.
 */
const segnoRiga = (r: RigaConfine) => (r.categoria === 'fattura_pregressa' ? 'pregressa' : r.rossa ? 'cambia la competenza' : null);
const versoTesto = { A: 'scritte qui, con la data fuori', B: 'con la data di questo esercizio, scritte in un altro' } as const;
const stampa = () => window.open(`${route(generateRoute('gestionale.esercizi.stato-patrimoniale.print'), base)}?parte=controlli`, '_blank');

const headerBreadcrumbs = computed(() => [
    { title: 'Gestionale', href: generatePath('gestionale/:condominio', { condominio: props.condominio.id }) },
    { title: 'Movimenti' },
    { title: 'Rendiconto' },
    { title: 'Controlli' },
]);
const pageGuides = [
    { title: 'I controlli, con i numeri veri', description: 'Uguaglianze e conteggi con i numeri del programma, non un semaforo. Quando uno non torna, la riga dice che cosa è successo, che cosa fare, e porta nella pagina dove si fa. I controlli che il programma non può eseguire non compaiono.', icon: ListChecks, colorVariant: 'amber' as const },
    { title: 'Oltre il confine dell\'esercizio', description: 'Le registrazioni con la data del fatto in un esercizio e scritte in un altro. In rosso quelle che cambiano che cosa il riepilogo per competenza conta; da leggere il denaro che attraversa il confine. Una fattura registrata come pregressa è in rosso nell\'esercizio della sua data, dove cambia la competenza, e da leggere in quello in cui sta, che non la conta come costo; se ha una parte non coperta dai saldi iniziali, quella parte è un costo dell\'esercizio in cui sta, e la riga resta rossa anche lì.', icon: CalendarRange, colorVariant: 'blue' as const },
];
</script>

<template>
    <Head title="Controlli del rendiconto" />
    <GestionaleLayout>
        <div class="px-6 py-8 space-y-3">
            <PageHeaderGuide
                page-title="Controlli del rendiconto"
                :page-subtitle="`I controlli: ${props.esito.frase}.`"
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
                        <RendicontoSchede :condominio-id="props.condominio.id" :esercizio-id="props.esercizio.id" attiva="controlli" />

                        <p class="mb-3 text-[12px] text-slate-600 dark:text-slate-400" data-test="riga-fissa"><b>{{ props.esito.frase }}.</b> {{ props.esito.riga_fissa }}</p>

                        <div class="rounded-md border bg-white dark:bg-slate-900 divide-y mb-6">
                            <div v-for="c in props.controlli" :id="`controllo-${c.id}`" :key="c.id" class="px-4 py-2.5" :class="c.esito === 'quadra' ? '' : (c.esito === 'non_quadra' ? 'bg-rose-50/60 dark:bg-rose-900/10' : 'bg-amber-50/60 dark:bg-amber-900/10')" :data-controllo="c.id">
                                <div class="grid grid-cols-1 lg:grid-cols-12 gap-x-4 gap-y-1 items-center">
                                    <div class="lg:col-span-3 flex items-center justify-between gap-2 min-w-0">
                                        <div class="font-semibold text-[13px] text-slate-800 dark:text-slate-200 leading-snug">{{ c.nome }}</div>
                                        <span class="inline-flex items-center gap-1 font-semibold text-[11px] shrink-0 whitespace-nowrap" :class="coloreEsito(c.esito)">
                                            <component :is="iconaEsito(c.esito)" class="w-3.5 h-3.5" /> {{ testoEsito(c.esito) }}
                                        </span>
                                    </div>
                                    <div class="lg:col-span-4 min-w-0">
                                        <div class="text-[13px] font-semibold tabular-nums text-slate-800 dark:text-slate-200">
                                            <template v-if="conteggio(c)">{{ conteggio(c) }}</template>
                                            <template v-else>{{ euro(c.sinistra) }} <span class="text-slate-400 dark:text-slate-500 font-normal">{{ c.segno ?? (c.esito === 'non_quadra' ? '≠' : '=') }}</span> {{ euro(c.destra) }}</template>
                                        </div>
                                        <div class="text-[11px] text-slate-500 dark:text-slate-400 leading-snug">{{ c.titolo }}</div>
                                    </div>
                                    <div class="lg:col-span-5 text-[11.5px] leading-snug text-slate-600 dark:text-slate-400">{{ c.nota }}</div>
                                </div>
                                <div v-if="c.rimedio" class="mt-2 pt-2 border-t border-dashed text-[11.5px] leading-snug flex flex-col sm:flex-row sm:items-center gap-x-4 gap-y-1" :class="coloreEsito(c.esito)">
                                    <div class="min-w-0"><span class="font-semibold">Come rimediare:</span> {{ c.rimedio }}</div>
                                    <a v-if="c.azione === 'anno-a-cavallo'" href="#anno-a-cavallo" class="inline-flex items-center gap-1 font-semibold hover:underline shrink-0 whitespace-nowrap">{{ etichettaAzione(c.azione) }} <ArrowRight class="w-3 h-3" /></a>
                                    <Link v-else-if="hrefAzione(c)" :href="hrefAzione(c)!" class="inline-flex items-center gap-1 font-semibold hover:underline shrink-0 whitespace-nowrap">{{ etichettaAzione(c.azione) }} <ArrowRight class="w-3 h-3" /></Link>
                                </div>
                            </div>
                        </div>

                        <!-- Le registrazioni oltre il confine: niente da correggere da qui. -->
                        <div id="anno-a-cavallo" class="rounded-md border bg-white dark:bg-slate-900 mb-6" data-test="anno-a-cavallo">
                            <div class="px-4 py-3 border-b bg-gray-50/50 dark:bg-slate-800/30">
                                <h3 class="font-semibold text-slate-800 dark:text-slate-200 flex items-center gap-2"><CalendarRange class="w-4 h-4 text-slate-500 dark:text-slate-400" /> Registrazioni oltre il confine dell'esercizio</h3>
                                <p class="text-[11.5px] text-slate-500 dark:text-slate-400 mt-0.5" data-test="norma">{{ a.frase_norma }}</p>
                            </div>
                            <div class="px-4 py-3 text-[12.5px] space-y-1">
                                <p v-for="v in (['A', 'B'] as const)" :key="v" :data-verso="v">
                                    <b>{{ a.versi[v].numero }}</b> {{ versoTesto[v] }}<template v-if="a.versi[v].numero">: {{ a.versi[v].rosse }} in rosso, {{ a.versi[v].da_leggere }} da leggere</template>.
                                    <span v-if="a.effetto[v].frase" class="text-slate-600 dark:text-slate-400"> {{ a.effetto[v].frase }}</span>
                                </p>
                                <p v-for="(r, i) in a.rimedi" :key="i" class="text-[12px] text-slate-600 dark:text-slate-400" data-test="rimedio">{{ r }}</p>
                            </div>

                            <div v-if="a.per_tipo.length" class="px-4 pb-3 overflow-x-auto">
                                <table class="w-full text-[12.5px] min-w-[560px]" data-test="per-tipo">
                                    <thead>
                                        <tr class="border-b text-[11px] uppercase tracking-wider text-slate-500 dark:text-slate-400">
                                            <th class="text-left py-1.5">Verso</th><th class="text-left py-1.5">Tipo</th><th class="text-right py-1.5">Registrazioni</th><th class="text-right py-1.5">In rosso</th><th class="text-right py-1.5">Importo</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <tr v-for="t in a.per_tipo" :key="t.verso + t.tipo" class="border-b border-slate-100 dark:border-slate-800">
                                            <td class="py-1.5 text-slate-500 dark:text-slate-400">{{ t.verso }}</td>
                                            <td class="py-1.5"><button type="button" class="hover:underline" @click="filtra('tipo', t.tipo)">{{ t.etichetta }}</button></td>
                                            <td class="py-1.5 text-right tabular-nums">{{ t.numero }}</td>
                                            <td class="py-1.5 text-right tabular-nums">{{ t.rosse }}</td>
                                            <td class="py-1.5 text-right tabular-nums">{{ euro(t.importo) }}</td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>

                            <div v-if="a.numero" class="px-4 pb-4">
                                <div class="flex flex-wrap items-center gap-2 mb-2 text-[12px]" data-test="filtri">
                                    <span class="text-slate-500 dark:text-slate-400">Filtri:</span>
                                    <select :value="a.filtri.verso ?? ''" class="border rounded px-2 py-1 dark:bg-slate-900" data-filtro="verso" @change="filtra('verso', ($event.target as HTMLSelectElement).value || null)">
                                        <option value="">tutti e due i versi</option><option value="A">verso A</option><option value="B">verso B</option>
                                    </select>
                                    <select :value="a.filtri.colore ?? ''" class="border rounded px-2 py-1 dark:bg-slate-900" data-filtro="colore" @change="filtra('colore', ($event.target as HTMLSelectElement).value || null)">
                                        <option value="">rosse e da leggere</option><option value="rosso">in rosso</option><option value="da_leggere">da leggere</option>
                                    </select>
                                    <button v-if="a.filtri.tipo" type="button" class="underline" @click="filtra('tipo', null)">tutti i tipi</button>
                                </div>
                                <table class="w-full text-[12.5px]" data-test="elenco">
                                    <thead>
                                        <tr class="border-b text-[11px] uppercase tracking-wider text-slate-500 dark:text-slate-400">
                                            <th class="text-left py-1.5">Data</th><th class="text-left py-1.5">Sta nell'esercizio</th><th class="text-left py-1.5">Protocollo</th><th class="text-left py-1.5">Tipo</th><th class="text-right py-1.5">Importo</th><th class="py-1.5"></th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <tr v-for="r in a.righe" :key="r.scrittura_id" class="border-b border-slate-100 dark:border-slate-800 align-top" :class="r.rossa ? 'bg-rose-50/40 dark:bg-rose-900/10' : ''" :data-scrittura="r.scrittura_id">
                                            <td class="py-1.5 tabular-nums whitespace-nowrap">{{ formatData(r.data) }}</td>
                                            <td class="py-1.5">{{ r.esercizio }}</td>
                                            <td class="py-1.5 tabular-nums">{{ r.protocollo ?? '—' }}</td>
                                            <td class="py-1.5">{{ r.etichetta }}<span v-if="segnoRiga(r)" class="ml-1 text-[10px] uppercase" :class="r.rossa ? 'text-rose-700 dark:text-rose-400' : 'text-slate-500 dark:text-slate-400'">{{ segnoRiga(r) }}</span>
                                                <div v-if="r.nota" class="text-[11px] text-slate-500 dark:text-slate-400">{{ r.nota }}</div></td>
                                            <td class="py-1.5 text-right tabular-nums">{{ euro(r.importo) }}</td>
                                            <td class="py-1.5 text-right whitespace-nowrap"><Link :href="hrefGiornale(r)" class="text-[11px] font-semibold text-primary hover:underline" data-test="apri-giornale">Apri nel Libro Giornale</Link></td>
                                        </tr>
                                        <tr v-if="dopoLUltima"><td colspan="6" class="py-3 text-center text-slate-400 dark:text-slate-500 italic" data-test="dopo-l-ultima">Questa pagina è dopo l'ultima. <Link :href="hrefPagina(1)" preserve-scroll class="underline">Vai alla prima</Link></td></tr>
                                        <tr v-else-if="a.righe.length === 0"><td colspan="6" class="py-3 text-center text-slate-400 dark:text-slate-500 italic">Nessuna registrazione con questi filtri</td></tr>
                                    </tbody>
                                </table>
                                <div v-if="a.pagine.last_page > 1" class="flex gap-3 mt-2 text-[12px]" data-test="pagine-confine">
                                    <span v-if="!dopoLUltima" class="text-slate-500 dark:text-slate-400">Righe {{ a.pagine.from }}–{{ a.pagine.to }} di {{ a.pagine.total }}</span>
                                    <Link v-if="a.pagine.current_page > 1" :href="hrefPagina(a.pagine.current_page - 1)" preserve-scroll class="underline">Precedenti</Link>
                                    <Link v-if="a.pagine.current_page < a.pagine.last_page" :href="hrefPagina(a.pagine.current_page + 1)" preserve-scroll class="underline">Successive</Link>
                                </div>
                            </div>
                        </div>
                    </MovimentiLayout>
                </section>
            </div>
        </div>
    </GestionaleLayout>
</template>
