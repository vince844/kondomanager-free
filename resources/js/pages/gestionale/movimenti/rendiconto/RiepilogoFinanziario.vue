<script setup lang="ts">
/**
 * Il riepilogo finanziario per competenza dell'esercizio. Ogni numero arriva dal server (`RiepilogoPerCompetenza`): la pagina non somma niente, e
 * formatta solo le date e gli importi.
 */
import { computed, ref } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import GestionaleLayout from '@/layouts/GestionaleLayout.vue';
import MovimentiLayout from '@/layouts/gestionale/MovimentiLayout.vue';
import PageHeaderGuide from '@/components/PageHeaderGuide.vue';
import RendicontoSchede from '@/components/gestionale/movimenti/rendiconto/RendicontoSchede.vue';
import { usePermission } from '@/composables/permissions';
import { useCurrencyFormatter } from '@/composables/useCurrencyFormatter';
import { BookOpenCheck, ChevronDown, ChevronRight, Printer, Scale } from 'lucide-vue-next';
import { Button } from '@/components/ui/button';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import type { Building } from '@/types/buildings';
import type { Esercizio } from '@/types/gestionale/esercizi';

type Stato = 'conservato' | 'non_conservato' | 'solo_deliberato' | 'nessun_piano';

interface RigaDettaglio {
    tipo: 'fattura' | 'movimento';
    numero: string | null;
    fornitore: string | null;
    descrizione: string | null;
    data: string;
    consuntivo: number;
    pagato: number;
    da_pagare: number;
}

interface Voce {
    voce_id: number;
    nome: string;
    tipo: 'spesa' | 'entrata';
    preventivo: number | null;
    preventivo_stato: Stato;
    deliberato: number | null;
    deliberato_stato: Stato;
    consuntivo?: number;
    incassato?: number;
    anno_prima: number | null;
    scostamento?: number | null;
    pagato?: number;
    da_pagare?: number;
    ritenute_da_versare?: number;
    /** Quanto è pagato oltre il costo: il da pagare di una fattura non scende sotto zero. */
    pagato_in_piu?: number;
    dettaglio?: RigaDettaglio[];
    voci?: Voce[];
    /** La riga delle cifre scritte sul capitolo stesso. */
    proprio?: boolean;
}

interface RigaQuote {
    riga: 'ordinarie' | 'straordinarie' | 'esercizi_precedenti';
    deliberate: number | null;
    emesse: number;
    pagate: number;
    non_pagate: number;
    scadute: number;
    crediti: number;
    /** Il credito di un esercizio prima chiuso sulla rata 0: non chiude nessuna quota, e non è fra le pagate. */
    credito_riportato: number;
}

interface Fuori { consuntivo: number; pagato: number; da_pagare: number; numero: number }

interface Riepilogo {
    esercizio: { id: number; nome: string; dal: string; al: string };
    alla: string;
    finito: boolean;
    anno_prima: { id: number; nome: string } | null;
    blocchi: Array<{
        gestione: { id: number; nome: string; tipo: string };
        spese: Voce[];
        entrate: Voce[];
        quote: RigaQuote[];
        totale_spese: Record<string, number | null>;
        cumulato_opera: { dal: string | null; consuntivo: number } | null;
    }>;
    fuori_capitoli: { contestate: Fuori; commissioni_senza_voce: Fuori; non_classificati: Fuori };
    quote: { totale: RigaQuote & { netto: number; versamenti_registro: number } };
    risultato: { quote_emesse: number; costi: number; risultato: number };
    raccordo_quote: { quote_piani: number; quote_giornale: number; piani_emesse_altrove: number; altri_emesse_qui: number; altre_differenze: number };
    riconciliazione: { costi: number; capitoli_netti: number; commissioni_senza_voce: number; contestate: number; non_classificati: number; differenza: number };
    totali: { da_pagare: number; pagato_in_piu: number };
    avvertenze: { perimetro_da_pagare: string; pagato_diviso: string; anno_prima: string | null; deliberato: string | null; data: string };
}

const props = defineProps<{
    condominio: Building;
    condomini: Building[];
    esercizio: Esercizio;
    esercizi: Esercizio[];
    riepilogo: Riepilogo;
}>();

const { generatePath, generateRoute } = usePermission();
/** La stampa del riepilogo, con il dettaglio per spesa a scelta. */
const stampa = (dettaglio: boolean) => window.open(`${route(generateRoute('gestionale.esercizi.rendiconto.riepilogo.stampa'), { condominio: props.condominio.id, esercizio: props.esercizio.id })}${dettaglio ? '?dettaglio=1' : ''}`, '_blank');
const { euro } = useCurrencyFormatter();
const r = computed(() => props.riepilogo);

const formatData = (iso: string) => {
    const [anno, mese, giorno] = iso.slice(0, 10).split('-');
    return `${giorno}/${mese}/${anno}`;
};
const importo = (v: number | null | undefined) => (v === null || v === undefined ? '—' : euro(v));

/** Che cosa dice la colonna quando non ha un numero: mai un vuoto senza motivo. */
const perche = (stato: Stato): string => ({
    conservato: '',
    // Il preventivo che la voce non porta più (porta già quello dell'anno dopo): come la stampa.
    non_conservato: 'nel verbale dell\'assemblea',
    solo_deliberato: 'solo il deliberato',
    nessun_piano: 'nessun piano approvato',
}[stato]);

/** Il deliberato con le rate che il programma non ha (i piani di prima del riparto): la frase sotto le tabelle dice perché. */
const percheDeliberato = (stato: Stato): string => (stato === 'non_conservato' ? 'non disponibile' : perche(stato));

const aperte = ref<Record<number, boolean>>({});
const apri = (id: number) => { aperte.value[id] = !aperte.value[id]; };

const nomeRiga = (riga: RigaQuote['riga']) => ({ ordinarie: 'Quote ordinarie', straordinarie: 'Quote straordinarie', esercizi_precedenti: 'Esercizi precedenti' }[riga]);

const hrefCommissioni = computed(() => generatePath('gestionale/:condominio/esercizi/:esercizio/commissioni-senza-voce', { condominio: props.condominio.id, esercizio: props.esercizio.id }));
const hrefIncasso = (voceId: number) => `${generatePath('gestionale/:condominio/incassi-da-terzi/create', { condominio: props.condominio.id })}?voce=${voceId}`;

const headerBreadcrumbs = computed(() => [
    { title: 'Gestionale', href: generatePath('gestionale/:condominio', { condominio: props.condominio.id }) },
    { title: 'Movimenti' },
    { title: 'Rendiconto' },
    { title: 'Riepilogo finanziario' },
]);

const pageGuides = [
    {
        title: 'Per competenza',
        description: 'Le spese dell\'esercizio, pagate o no, per capitolo: il consuntivo è quello del giornale, il pagato e il da pagare sono alla data scritta in testa. Le entrate da terzi riducono le spese, come il piano riduce già le quote.',
        icon: BookOpenCheck,
        colorVariant: 'blue' as const,
    },
    {
        title: 'Il preventivo e il deliberato',
        description: 'Il deliberato con le rate è quanto i piani approvati dell\'esercizio chiedono per la voce. Il preventivo è l\'importo della voce finché la gestione non passa all\'esercizio dopo: da lì la voce porta di solito il nuovo preventivo, e la colonna rimanda al verbale dell\'assemblea.',
        icon: Scale,
        colorVariant: 'emerald' as const,
    },
];
</script>

<template>
    <Head title="Riepilogo finanziario" />
    <GestionaleLayout>
        <div class="px-6 py-8 space-y-3">
            <PageHeaderGuide
                page-title="Riepilogo finanziario"
                :page-subtitle="`${r.esercizio.nome}: per competenza, ${r.avvertenze.data}.`"
                :guides="pageGuides"
                :breadcrumbs="(headerBreadcrumbs as any)"
                :condominio="(props.condominio as any)"
                :condomini="(props.condomini as any)"
                :esercizio="(props.esercizio as any)"
                :esercizi="(props.esercizi as any)"
            >
                <template #actions>
                    <DropdownMenu>
                        <DropdownMenuTrigger as-child>
                            <Button variant="outline" class="h-8 px-3 gap-2"><Printer class="w-4 h-4" /> Stampa <ChevronDown class="w-3.5 h-3.5 text-slate-400 dark:text-slate-500" /></Button>
                        </DropdownMenuTrigger>
                        <DropdownMenuContent align="end" class="w-72">
                            <DropdownMenuItem class="flex-col items-start gap-0.5 py-2" data-test="stampa" @click="stampa(false)">
                                <span class="font-medium">Riepilogo finanziario</span>
                                <span class="text-[11px] text-slate-500 dark:text-slate-400 leading-snug">Per capitolo, le entrate da terzi, le quote e il risultato.</span>
                            </DropdownMenuItem>
                            <DropdownMenuItem class="flex-col items-start gap-0.5 py-2" data-test="stampa-dettaglio" @click="stampa(true)">
                                <span class="font-medium">Con il dettaglio per spesa</span>
                                <span class="text-[11px] text-slate-500 dark:text-slate-400 leading-snug">In più, una riga per fattura o movimento, con il pagato e il da pagare.</span>
                            </DropdownMenuItem>
                        </DropdownMenuContent>
                    </DropdownMenu>
                </template>
            </PageHeaderGuide>

            <div class="w-full">
                <section class="w-full space-y-4">
                    <MovimentiLayout>
                        <RendicontoSchede :condominio-id="props.condominio.id" :esercizio-id="props.esercizio.id" attiva="riepilogo" />

                        <p class="text-[12px] text-slate-500 dark:text-slate-400 mb-4" data-test="come-leggere">
                            Gli importi sono per competenza: le spese di questo esercizio, pagate o no. {{ r.avvertenze.pagato_diviso }}
                            {{ r.avvertenze.perimetro_da_pagare }}
                            <template v-if="r.avvertenze.anno_prima">La colonna «Anno prima» è vuota: {{ r.avvertenze.anno_prima }}.</template>
                        </p>

                        <div v-for="b in r.blocchi" :key="b.gestione.id" class="mb-8" :data-gestione="b.gestione.id">
                            <h2 class="text-base font-semibold text-slate-800 dark:text-slate-200 mb-2">{{ b.gestione.nome }}</h2>
                            <p v-if="b.cumulato_opera" class="text-[12px] text-slate-500 dark:text-slate-400 mb-2">
                                Cumulato dell'opera<template v-if="b.cumulato_opera.dal"> dal {{ formatData(b.cumulato_opera.dal) }}</template>: {{ euro(b.cumulato_opera.consuntivo) }}.
                            </p>

                            <table class="w-full text-sm" data-test="spese">
                                <thead>
                                    <tr class="border-b border-slate-200 dark:border-slate-800 text-[11px] uppercase tracking-wider text-slate-500 dark:text-slate-400">
                                        <th class="text-left py-2">Spese</th>
                                        <th class="text-right py-2">Preventivo</th>
                                        <th class="text-right py-2">Deliberato con le rate</th>
                                        <th class="text-right py-2">Consuntivo</th>
                                        <th class="text-right py-2">Anno prima</th>
                                        <th class="text-right py-2">Scostamento</th>
                                        <th class="text-right py-2">Pagato</th>
                                        <th class="text-right py-2">Da pagare</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <template v-for="c in b.spese" :key="c.voce_id">
                                        <tr class="border-b border-slate-100 dark:border-slate-800" :data-voce="c.voce_id">
                                            <td class="py-2 text-slate-700 dark:text-slate-300">
                                                <button type="button" class="inline-flex items-center gap-1 hover:underline" @click="apri(c.voce_id)">
                                                    <component :is="aperte[c.voce_id] ? ChevronDown : ChevronRight" class="w-3.5 h-3.5 text-slate-400 dark:text-slate-500" />
                                                    {{ c.nome }}
                                                </button>
                                            </td>
                                            <td class="py-2 text-right tabular-nums">{{ c.preventivo_stato === 'conservato' ? importo(c.preventivo) : perche(c.preventivo_stato) }}</td>
                                            <td class="py-2 text-right tabular-nums">{{ c.deliberato_stato === 'conservato' ? importo(c.deliberato) : percheDeliberato(c.deliberato_stato) }}</td>
                                            <td class="py-2 text-right tabular-nums font-semibold">{{ importo(c.consuntivo) }}</td>
                                            <td class="py-2 text-right tabular-nums text-slate-500 dark:text-slate-400">{{ importo(c.anno_prima) }}</td>
                                            <td class="py-2 text-right tabular-nums">{{ importo(c.scostamento) }}</td>
                                            <td class="py-2 text-right tabular-nums">{{ importo(c.pagato) }}</td>
                                            <td class="py-2 text-right tabular-nums">
                                                {{ importo(c.da_pagare) }}
                                                <span v-if="c.ritenute_da_versare" class="block text-[11px] text-slate-500 dark:text-slate-400">di cui ritenute da versare {{ euro(c.ritenute_da_versare) }}</span>
                                                <span v-if="c.pagato_in_piu" class="block text-[11px] text-amber-700 dark:text-amber-400">pagato in più {{ euro(c.pagato_in_piu) }}</span>
                                            </td>
                                        </tr>
                                        <template v-if="aperte[c.voce_id]">
                                            <template v-for="v in c.voci ?? []" :key="(v.proprio ? 'p' : 'v') + v.voce_id">
                                            <tr class="border-b border-slate-50 dark:border-slate-800 text-[13px]" :data-voce="v.proprio ? undefined : v.voce_id" :data-proprio="v.proprio ? c.voce_id : undefined">
                                                <td class="py-1.5 pl-6 text-slate-600 dark:text-slate-400" :class="v.proprio ? 'italic' : ''">{{ v.nome }}</td>
                                                <td class="py-1.5 text-right tabular-nums">{{ v.preventivo_stato === 'conservato' ? importo(v.preventivo) : perche(v.preventivo_stato) }}</td>
                                                <td class="py-1.5 text-right tabular-nums">{{ v.deliberato_stato === 'conservato' ? importo(v.deliberato) : percheDeliberato(v.deliberato_stato) }}</td>
                                                <td class="py-1.5 text-right tabular-nums">{{ importo(v.consuntivo) }}</td>
                                                <td class="py-1.5 text-right tabular-nums text-slate-500 dark:text-slate-400">{{ importo(v.anno_prima) }}</td>
                                                <td class="py-1.5 text-right tabular-nums">{{ importo(v.scostamento) }}</td>
                                                <td class="py-1.5 text-right tabular-nums">{{ importo(v.pagato) }}</td>
                                                <td class="py-1.5 text-right tabular-nums">{{ importo(v.da_pagare) }}</td>
                                            </tr>
                                            <tr v-for="(d, i) in v.dettaglio ?? []" :key="'vd' + v.voce_id + '-' + i" class="border-b border-slate-50 dark:border-slate-800 text-[12px] text-slate-600 dark:text-slate-400" data-test="dettaglio">
                                                <td class="py-1.5 pl-10" colspan="3">{{ formatData(d.data) }} · {{ d.numero ?? '' }}<template v-if="d.fornitore"> · {{ d.fornitore }}</template><template v-if="d.descrizione"> · {{ d.descrizione }}</template></td>
                                                <td class="py-1.5 text-right tabular-nums">{{ euro(d.consuntivo) }}</td>
                                                <td></td>
                                                <td></td>
                                                <td class="py-1.5 text-right tabular-nums">{{ euro(d.pagato) }}</td>
                                                <td class="py-1.5 text-right tabular-nums">{{ euro(d.da_pagare) }}</td>
                                            </tr>
                                            </template>
                                            <tr v-for="(d, i) in c.dettaglio ?? []" :key="'d' + i" class="border-b border-slate-50 dark:border-slate-800 text-[12px] text-slate-600 dark:text-slate-400" data-test="dettaglio">
                                                <td class="py-1.5 pl-6" colspan="3">{{ formatData(d.data) }} · {{ d.numero ?? '' }}<template v-if="d.fornitore"> · {{ d.fornitore }}</template><template v-if="d.descrizione"> · {{ d.descrizione }}</template></td>
                                                <td class="py-1.5 text-right tabular-nums">{{ euro(d.consuntivo) }}</td>
                                                <td></td>
                                                <td></td>
                                                <td class="py-1.5 text-right tabular-nums">{{ euro(d.pagato) }}</td>
                                                <td class="py-1.5 text-right tabular-nums">{{ euro(d.da_pagare) }}</td>
                                            </tr>
                                        </template>
                                    </template>
                                </tbody>
                            </table>

                            <table v-if="b.entrate.length" class="w-full text-sm mt-4" data-test="entrate">
                                <thead>
                                    <tr class="border-b border-slate-200 dark:border-slate-800 text-[11px] uppercase tracking-wider text-slate-500 dark:text-slate-400">
                                        <th class="text-left py-2">Entrate da terzi</th>
                                        <th class="text-right py-2">Preventivo</th>
                                        <th class="text-right py-2">Incassato</th>
                                        <th class="text-right py-2">Anno prima</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <template v-for="e in b.entrate" :key="e.voce_id">
                                    <tr class="border-b border-slate-100 dark:border-slate-800" :data-voce="e.voce_id">
                                        <td class="py-2 text-slate-700 dark:text-slate-300">
                                            {{ e.nome }}
                                            <Link v-if="!e.incassato" :href="hrefIncasso(e.voce_id)" class="ml-2 text-[11px] font-semibold text-primary hover:underline">Registra l'incasso</Link>
                                        </td>
                                        <td class="py-2 text-right tabular-nums">{{ e.preventivo_stato === 'conservato' ? importo(e.preventivo) : perche(e.preventivo_stato) }}</td>
                                        <td class="py-2 text-right tabular-nums font-semibold">{{ importo(e.incassato) }}</td>
                                        <td class="py-2 text-right tabular-nums text-slate-500 dark:text-slate-400">{{ importo(e.anno_prima) }}</td>
                                    </tr>
                                    <tr v-if="e.consuntivo" class="border-b border-slate-50 dark:border-slate-800 text-[12px] text-slate-600 dark:text-slate-400" :data-spese-entrata="e.voce_id">
                                        <td class="py-1.5 pl-6" colspan="4">
                                            Spese scritte sulla voce di entrata: {{ euro(e.consuntivo) }}, pagate {{ euro(e.pagato ?? 0) }}, da pagare {{ euro(e.da_pagare ?? 0) }}
                                            <template v-for="(d, i) in e.dettaglio ?? []" :key="'ed' + i"> · {{ d.numero ?? '' }}<template v-if="d.fornitore"> ({{ d.fornitore }})</template></template>
                                        </td>
                                    </tr>
                                    </template>
                                </tbody>
                            </table>

                            <table v-if="b.quote.length" class="w-full text-sm mt-4" data-test="quote">
                                <thead>
                                    <tr class="border-b border-slate-200 dark:border-slate-800 text-[11px] uppercase tracking-wider text-slate-500 dark:text-slate-400">
                                        <th class="text-left py-2">Quote dei condòmini</th>
                                        <th class="text-right py-2">Deliberate</th>
                                        <th class="text-right py-2">Emesse</th>
                                        <th class="text-right py-2">Pagate</th>
                                        <th class="text-right py-2">Non pagate</th>
                                        <th class="text-right py-2">Crediti sulle rate</th>
                                        <th class="text-right py-2">Credito riportato</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr v-for="q in b.quote" :key="q.riga" class="border-b border-slate-100 dark:border-slate-800">
                                        <td class="py-2 text-slate-700 dark:text-slate-300">{{ nomeRiga(q.riga) }}</td>
                                        <td class="py-2 text-right tabular-nums">{{ q.deliberate === null ? (q.riga === 'esercizi_precedenti' ? '—' : 'non disponibile') : euro(q.deliberate) }}</td>
                                        <td class="py-2 text-right tabular-nums">{{ euro(q.emesse) }}</td>
                                        <td class="py-2 text-right tabular-nums">{{ euro(q.pagate) }}</td>
                                        <td class="py-2 text-right tabular-nums">
                                            {{ euro(q.non_pagate) }}
                                            <span class="block text-[11px] text-slate-500 dark:text-slate-400">di cui scadute {{ euro(q.scadute) }}</span>
                                        </td>
                                        <td class="py-2 text-right tabular-nums">{{ euro(q.crediti) }}</td>
                                        <td class="py-2 text-right tabular-nums">{{ q.credito_riportato ? euro(q.credito_riportato) : '—' }}</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>

                        <p v-if="r.avvertenze.deliberato" class="text-[12px] text-slate-500 dark:text-slate-400 mb-6" data-test="deliberato-non-disponibile">{{ r.avvertenze.deliberato }}</p>

                        <section class="mb-6" data-test="fuori-capitoli">
                            <h3 class="text-sm font-semibold text-slate-700 dark:text-slate-300 mb-2">Fuori dai capitoli</h3>
                            <table class="w-full text-sm">
                                <thead>
                                    <tr class="border-b border-slate-200 dark:border-slate-800 text-[11px] uppercase tracking-wider text-slate-500 dark:text-slate-400">
                                        <th class="text-left py-2"></th>
                                        <th class="text-right py-2">Consuntivo</th>
                                        <th class="text-right py-2">Pagato</th>
                                        <th class="text-right py-2">Da pagare</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr class="border-b border-slate-100 dark:border-slate-800">
                                        <td class="py-2 text-slate-700 dark:text-slate-300">Fatture contestate <span class="text-[11px] text-slate-500 dark:text-slate-400">({{ r.fuori_capitoli.contestate.numero }}): fuori dai capitoli finché sono contestate</span></td>
                                        <td class="py-2 text-right tabular-nums">{{ euro(r.fuori_capitoli.contestate.consuntivo) }}</td>
                                        <td class="py-2 text-right tabular-nums">{{ euro(r.fuori_capitoli.contestate.pagato) }}</td>
                                        <td class="py-2 text-right tabular-nums">{{ euro(r.fuori_capitoli.contestate.da_pagare) }}</td>
                                    </tr>
                                    <tr class="border-b border-slate-100 dark:border-slate-800">
                                        <td class="py-2 text-slate-700 dark:text-slate-300">
                                            Commissioni senza voce <span class="text-[11px] text-slate-500 dark:text-slate-400">({{ r.fuori_capitoli.commissioni_senza_voce.numero }}): non stanno in nessun capitolo</span>
                                            <Link v-if="r.fuori_capitoli.commissioni_senza_voce.numero" :href="hrefCommissioni" class="ml-2 text-[11px] font-semibold text-primary hover:underline">Assegna la voce</Link>
                                        </td>
                                        <td class="py-2 text-right tabular-nums">{{ euro(r.fuori_capitoli.commissioni_senza_voce.consuntivo) }}</td>
                                        <td class="py-2 text-right tabular-nums">{{ euro(r.fuori_capitoli.commissioni_senza_voce.pagato) }}</td>
                                        <td class="py-2 text-right tabular-nums">{{ euro(r.fuori_capitoli.commissioni_senza_voce.da_pagare) }}</td>
                                    </tr>
                                    <tr v-if="r.fuori_capitoli.non_classificati.consuntivo" class="border-b border-slate-100 dark:border-slate-800">
                                        <td class="py-2 text-slate-700 dark:text-slate-300">Costi non classificati <span class="text-[11px] text-slate-500 dark:text-slate-400">({{ r.fuori_capitoli.non_classificati.numero }}): righe di costo senza voce</span></td>
                                        <td class="py-2 text-right tabular-nums">{{ euro(r.fuori_capitoli.non_classificati.consuntivo) }}</td>
                                        <td class="py-2 text-right tabular-nums">{{ euro(r.fuori_capitoli.non_classificati.pagato) }}</td>
                                        <td class="py-2 text-right tabular-nums">{{ euro(r.fuori_capitoli.non_classificati.da_pagare) }}</td>
                                    </tr>
                                </tbody>
                            </table>
                            <p class="text-[11px] text-slate-500 dark:text-slate-400 mt-2" data-test="pregresse">
                                Le fatture di un anno passato registrate come pregresse non sono costi di questo esercizio e non compaiono qui, tranne la parte non coperta dai saldi iniziali, se c'è: quella è un costo di questo esercizio, e sta fra le spese.
                            </p>
                        </section>

                        <section class="rounded-lg border border-slate-200 dark:border-slate-800 p-4 text-sm space-y-1" data-test="risultato">
                            <div class="font-semibold text-slate-800 dark:text-slate-200">
                                Risultato: quote emesse a giornale {{ euro(r.risultato.quote_emesse) }} − costi {{ euro(r.risultato.costi) }} =
                                <span :class="r.risultato.risultato < 0 ? 'text-rose-600 dark:text-rose-400' : 'text-emerald-700 dark:text-emerald-400'">{{ euro(r.risultato.risultato) }}</span>
                                ({{ r.risultato.risultato < 0 ? 'disavanzo' : 'avanzo' }})
                            </div>
                            <div class="text-[12px] text-slate-500 dark:text-slate-400" data-test="riconciliazione">
                                Costi dell'esercizio a giornale {{ euro(r.riconciliazione.costi) }} = spese per capitolo meno entrate {{ euro(r.riconciliazione.capitoli_netti) }}
                                + commissioni senza voce {{ euro(r.riconciliazione.commissioni_senza_voce) }} + fatture contestate {{ euro(r.riconciliazione.contestate) }}
                                <template v-if="r.riconciliazione.non_classificati"> + costi non classificati {{ euro(r.riconciliazione.non_classificati) }}</template>
                                <template v-if="r.riconciliazione.differenza"> + una differenza di {{ euro(r.riconciliazione.differenza) }} da guardare nel Libro Giornale</template>.
                            </div>
                            <div class="text-[12px] text-slate-500 dark:text-slate-400" data-test="raccordo-quote">
                                Quote dei piani dell'esercizio {{ euro(r.raccordo_quote.quote_piani) }} = quote a giornale {{ euro(r.raccordo_quote.quote_giornale) }}
                                + rate di questi piani emesse in un altro esercizio {{ euro(r.raccordo_quote.piani_emesse_altrove) }}
                                − rate di altri piani emesse in questo {{ euro(r.raccordo_quote.altri_emesse_qui) }}<template v-if="r.raccordo_quote.altre_differenze"> + {{ euro(r.raccordo_quote.altre_differenze) }} di debiti riportati dentro le rate (il pregresso emesso con le rate, che non va fra le quote a giornale)</template>.
                            </div>
                            <div class="text-[12px] text-slate-500 dark:text-slate-400" data-test="pagate-e-registro">
                                Le quote pagate, versate o compensate con un credito, sono {{ euro(r.quote.totale.pagate) }}; i versamenti dei condòmini nel registro di
                                contabilità di questo esercizio sono {{ euro(r.quote.totale.versamenti_registro) }}. Possono essere diversi: una compensazione non passa
                                dalla cassa; il registro conta anche i versamenti per le rate di altri piani e quelli con la data dell'anno dopo scritti in questo
                                esercizio, e mette gli storni degli incassi fra gli storni, mentre le pagate li tolgono.<template v-if="r.quote.totale.credito_riportato"> Il credito
                                riportato da un esercizio prima ({{ euro(r.quote.totale.credito_riportato) }}) non è fra le pagate: non ha ancora chiuso nessuna quota.</template>
                            </div>
                            <div class="text-[12px] text-slate-500 dark:text-slate-400">
                                Le non pagate sono lorde, come in «Rate scadute»: {{ euro(r.quote.totale.non_pagate) }}, con {{ euro(r.quote.totale.crediti) }} di crediti sulle rate accanto (il netto è {{ euro(r.quote.totale.netto) }}).
                            </div>
                        </section>
                    </MovimentiLayout>
                </section>
            </div>
        </div>
    </GestionaleLayout>
</template>
