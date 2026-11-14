<script setup lang="ts">
/**
 * La scheda «Conguaglio» del rendiconto: la proposta di conguaglio per persona e unità di ogni gestione, alla data del rendiconto, con la sua
 * richiesta (la lettura non si somma a quella delle altre schede). Ogni numero arriva dal server (`ConguaglioDelRendiconto`): la pagina non somma
 * niente, formatta gli importi. Le righe sono a pagine; i totali sono di tutte. Le note dei passaggi e gli avvisi sono per l'amministratore, e
 * stanno solo qui: le stampe per i condòmini non li hanno.
 */
import { computed, ref } from 'vue';
import { Head, Link, usePage } from '@inertiajs/vue3';
import GestionaleLayout from '@/layouts/GestionaleLayout.vue';
import MovimentiLayout from '@/layouts/gestionale/MovimentiLayout.vue';
import PageHeaderGuide from '@/components/PageHeaderGuide.vue';
import Alert from '@/components/Alert.vue';
import RendicontoSchede from '@/components/gestionale/movimenti/rendiconto/RendicontoSchede.vue';
import InvioDeiFogli from '@/components/gestionale/rendiconto/InvioDeiFogli.vue';
import UltimoInvioDelFoglio from '@/components/gestionale/rendiconto/UltimoInvioDelFoglio.vue';
import { Button } from '@/components/ui/button';
import { usePermission } from '@/composables/permissions';
import { useCurrencyFormatter } from '@/composables/useCurrencyFormatter';
import { Printer, FileDown, Scale, Users, Mail } from 'lucide-vue-next';
import type { Building } from '@/types/buildings';
import type { Esercizio } from '@/types/gestionale/esercizi';
import type { Flash } from '@/types/flash';

interface Riga {
    anagrafica_id: number; nome: string; immobile_id: number | null; unita: string | null; giorni: number | null;
    a: number | null; personali: number | null; b: number | null; b_non_emesse: number | null; conguaglio: number | null; posizione: number | null; fuori: number | null; saldo: number | null;
    /** Le rate dei piani dell'esercizio non ancora emesse: il saldo non le comprende ancora. */
    non_emesse?: number | null;
    non_disponibile: string | null; note: string[]; subtotale: boolean; gestione_id: number;
    /** La riga di un'opera in corso: nessuna spesa dell'opera né quota, il conguaglio non si propone. */
    in_corso?: boolean;
}
interface Totali { a: number; personali: number; b: number; conguaglio: number; posizione: number; fuori_dalle_rate: number; saldo: number; non_disponibili: number; a_non_disponibili?: number; non_emesse?: number }
interface Gestione {
    id: number; nome: string; tipo: string; non_calcolabile: string | null; totali: Totali;
    non_attribuite: { conto_id: number; voce: string; piano_conto_id: number | null; importo: number; motivo: string }[];
    /** L'opera straordinaria in corso: lo speso e il chiesto, senza proporre. */
    in_corso?: { frase: string; gestione_id: number; senza_data_fine: boolean; sposta_data_fine?: boolean; spesi: number | null; chiesti: number | null } | null;
    /** L'opera finita in questo esercizio su più esercizi: il conguaglio è sull'intera gestione. */
    opera_finita?: string | null;
    /** Gli sfori coperti da un fondo, come li indica la fattura: il conguaglio resta lordo. */
    coperture?: string[];
    /** Le frasi per l'amministratore sulla gestione (una spesa per singole unità di giorni senza titolare, un piano senza l'esercizio scritto): come le coperture. */
    avvisi?: string[];
    /** Il già versato non usato di unità senza un titolare alla data: resta della gestione. */
    gia_versato_senza_titolare?: string | null;
}
interface Scheda {
    gestioni: Gestione[];
    da_altre_gestioni: { nome: string; totali: { posizione: number; personali: number; conguaglio: number; saldo: number } | null };
    fuori_dai_capitoli: { nome: string; importo: number; motivo: string }[];
    condominio: Totali & { non_attribuite: number };
    /** Le righe non disponibili con la loro spesa dell'esercizio, che i totali non contano; null se non ce ne sono. */
    non_disponibili?: string | null;
    data: { alla: string; finito: boolean; calcolato_il: string | null; frase: string };
    secondi: number | null;
    /** Il secondo momento, come righe: i crediti e i debiti verso i condòmini dopo l'approvazione e la scrittura del conguaglio. */
    secondo_momento?: { crediti_oggi: number; personali: number; a_debito: number; crediti_dopo: number; a_credito: number; frase: string } | null;
    righe: Riga[];
    pagine: { corrente: number; ultima: number; per_pagina: number; righe: number; dopo: number };
}
type Scelta = 'tutte' | 'a_debito' | 'con_email';
/** `non_disponibili`: chi ha il conguaglio non disponibile su un'unità e su nessuna è a debito, che «a debito» lascia fuori. */
type Conteggi = Record<Scelta, number> & { non_disponibili?: number };
interface Fogli { tutto: Conteggi; pagina: Conteggi; tetto: number; ricordata: Scelta }

const props = defineProps<{
    condominio: Building; condomini: Building[]; esercizio: Esercizio; esercizi: Esercizio[]; conguaglio: Scheda;
    frasi: { proposta: string; segno: string; competenza: string; giorni: string; centesimi: string; colonne: string; non_emesse?: string }; fogli: Fogli;
}>();

const { generatePath, generateRoute } = usePermission();
const { euro } = useCurrencyFormatter();
const page = usePage<{ flash?: { message?: Flash } }>();
const flashMessage = computed(() => page.props.flash?.message);
const k = computed(() => props.conguaglio);
const base = { condominio: props.condominio.id, esercizio: props.esercizio.id };

/** Le cifre del conguaglio e del saldo con il più quando sono a debito, dopo il simbolo come il meno («€ +1,48», «€ -1,48»); «non disponibile» quando il programma non le ha. */
const conSegno = (v: number | null) => (v === null ? 'non disponibile' : v > 0 ? euro(v).replace(/^€(\s*)/, '€$1+') : euro(v));
const cella = (v: number | null) => (v === null ? 'non disponibile' : v === 0 ? '' : euro(v));
const nomeGestione = (id: number) => (id === 0 ? k.value.da_altre_gestioni.nome : (k.value.gestioni.find((g) => g.id === id)?.nome ?? ''));
const gestione = (id: number) => k.value.gestioni.find((g) => g.id === id);
/** «Scrivi la data di fine»: la modifica della gestione, dove la data di fine si scrive. */
const hrefGestione = (id: number) => generatePath('gestionale/:condominio/esercizi/:esercizio/gestioni/:gestione/edit', { ...base, gestione: id });
/** Le righe della pagina, raggruppate per gestione nell'ordine in cui arrivano. */
const gruppi = computed(() => {
    const out: { gestione_id: number; righe: Riga[] }[] = [];
    for (const r of k.value.righe) {
        const ultimo = out[out.length - 1];
        if (ultimo && ultimo.gestione_id === r.gestione_id) ultimo.righe.push(r);
        else out.push({ gestione_id: r.gestione_id, righe: [r] });
    }
    return out;
});

const hrefPagina = (n: number) => `${generatePath('gestionale/:condominio/esercizi/:esercizio/rendiconto/conguaglio', base)}?pagina=${n}`;
const hrefEstratto = (id: number) => generatePath('gestionale/:condominio/anagrafiche/:anagrafica/estratto-conto', { condominio: props.condominio.id, anagrafica: id });
const hrefFoglio = (id: number) => `${generatePath('gestionale/:condominio/esercizi/:esercizio/rendiconto/conguaglio/fogli', base)}/${id}`;
const hrefRiepilogo = generatePath('gestionale/:condominio/esercizi/:esercizio/rendiconto/riepilogo-finanziario', base);
/** «Non attribuite» apre il piano dei conti della voce, dove si collega una tabella. */
const hrefPianoConti = (pianoConto: number | null) => (pianoConto === null ? hrefRiepilogo
    : generatePath('gestionale/:condominio/esercizi/:esercizio/piani-conti/:pianoConto', { ...base, pianoConto }));
const stampa = (nome: string) => window.open(route(generateRoute(nome), base), '_blank');

/* ─── I fogli in un archivio: le persone scelte, di tutto l'elenco o di questa pagina; l'ultima scelta la ricorda il server ─── */
const chi = ref<Scelta>(props.fogli.ricordata);
const soloPagina = ref(false);
const quante = computed(() => (soloPagina.value ? props.fogli.pagina : props.fogli.tutto)[chi.value]);
const troppe = computed(() => quante.value > props.fogli.tetto);
// «Con il conguaglio a debito» lascia fuori chi ha il conguaglio non disponibile: il riquadro lo dice, con quante persone.
const fuoriDaiDebiti = computed(() => {
    const n = chi.value === 'a_debito' ? ((soloPagina.value ? props.fogli.pagina : props.fogli.tutto).non_disponibili ?? 0) : 0;
    if (n === 0) return '';
    return n === 1 ? ' 1 persona ha il conguaglio non disponibile e non è fra queste.' : ` ${n} persone hanno il conguaglio non disponibile e non sono fra queste.`;
});
const hrefFogli = computed(() => {
    const q = new URLSearchParams({ chi: chi.value });
    if (soloPagina.value) q.set('pagina', String(k.value.pagine.corrente));
    return `${route(generateRoute('gestionale.esercizi.rendiconto.conguaglio.fogli'), base)}?${q.toString()}`;
});
const scelte: { valore: Scelta; etichetta: string }[] = [
    { valore: 'tutte', etichetta: 'Tutte le persone' },
    { valore: 'a_debito', etichetta: 'Con il conguaglio a debito' },
    { valore: 'con_email', etichetta: 'Con un indirizzo email' },
];

const headerBreadcrumbs = computed(() => [
    { title: 'Gestionale', href: generatePath('gestionale/:condominio', { condominio: props.condominio.id }) },
    { title: 'Movimenti' },
    { title: 'Rendiconto' },
    { title: 'Conguaglio' },
]);
const pageGuides = [
    { title: 'La proposta di conguaglio', description: 'Per persona e unità: le spese dell\'esercizio che le toccano, le spese personali non ancora chieste, meno le quote dei piani. È una proposta: diventa una richiesta quando l\'assemblea approva il rendiconto.', icon: Scale, colorVariant: 'blue' as const },
    { title: 'Il saldo finale', description: 'La posizione sulle rate, come nell\'estratto conto, più i saldi fuori dalle rate, più il conguaglio. Positivo vuol dire da versare, negativo a credito.', icon: Users, colorVariant: 'amber' as const },
    { title: 'Il foglio di ogni persona', description: 'Per ogni persona un foglio con le sue unità, voce per voce, il conguaglio e il saldo, con la firma. Si scarica uno per uno o in un archivio, oppure si manda per email agli indirizzi della persona; accanto a ogni persona c\'è l\'ultimo invio.', icon: Mail, colorVariant: 'emerald' as const },
];
</script>

<template>
    <Head title="Conguaglio" />
    <GestionaleLayout>
        <div class="px-6 py-8 space-y-3">
            <PageHeaderGuide
                page-title="Conguaglio"
                :page-subtitle="`${props.esercizio.nome}. ${props.frasi.proposta}`"
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
                        <RendicontoSchede :condominio-id="props.condominio.id" :esercizio-id="props.esercizio.id" attiva="conguaglio" />

                        <div v-if="flashMessage" class="mb-3" data-test="avviso"><Alert :message="flashMessage.message" :type="flashMessage.type" /></div>

                        <p class="text-[12px] text-slate-600 dark:text-slate-400 mb-1" data-test="data">
                            {{ k.data.frase }}<template v-if="k.secondi !== null"> La lettura ha chiesto {{ k.secondi.toLocaleString('it-IT') }} secondi.</template>
                        </p>
                        <p class="text-[12px] text-slate-500 dark:text-slate-400 mb-4" data-test="come-leggere">
                            {{ props.frasi.segno }} {{ props.frasi.colonne }} {{ props.frasi.non_emesse }} {{ props.frasi.competenza }} {{ props.frasi.giorni }} {{ props.frasi.centesimi }}
                            Sono i dati di tutti i condòmini.
                        </p>

                        <div class="flex flex-wrap items-center gap-2 mb-4">
                            <Button variant="outline" class="h-8 px-3 gap-2" data-test="stampa-per-persona" @click="stampa('gestionale.esercizi.rendiconto.conguaglio.stampa')"><Printer class="w-4 h-4" /> Conguaglio per persona</Button>
                            <Button variant="outline" class="h-8 px-3 gap-2" data-test="stampa-per-tabella" @click="stampa('gestionale.esercizi.rendiconto.conguaglio.per-tabella.stampa')"><Printer class="w-4 h-4" /> Consuntivo per tabella</Button>
                        </div>

                        <!-- I fogli delle persone in un archivio -->
                        <div class="rounded-md border p-3 mb-6 text-[12.5px] space-y-2" data-test="fogli">
                            <div class="font-semibold text-slate-800 dark:text-slate-200">Il foglio di ogni persona: in un archivio o per email</div>
                            <div class="flex flex-wrap gap-3">
                                <label v-for="s in scelte" :key="s.valore" class="flex items-center gap-1.5">
                                    <input v-model="chi" type="radio" :value="s.valore" :data-scelta="s.valore" /> {{ s.etichetta }}
                                </label>
                                <label class="flex items-center gap-1.5"><input v-model="soloPagina" type="checkbox" data-test="solo-pagina" /> solo le persone di questa pagina</label>
                            </div>
                            <p class="text-slate-600 dark:text-slate-400" data-test="quante">Persone scelte: {{ quante }}.{{ fuoriDaiDebiti }}<template v-if="troppe"> In un archivio ne stanno al massimo {{ props.fogli.tetto }}: scegli le persone di una pagina dell'elenco.</template></p>
                            <div class="flex flex-wrap items-center gap-2">
                                <a v-if="!troppe && quante > 0" :href="hrefFogli" class="inline-flex items-center gap-2 h-8 px-3 rounded-md border font-medium" data-test="scarica-fogli"><FileDown class="w-4 h-4" /> Scarica i fogli</a>
                                <InvioDeiFogli :condominio-id="props.condominio.id" :esercizio-id="props.esercizio.id" :chi="chi" :pagina="soloPagina ? k.pagine.corrente : null" :quante="quante" />
                            </div>
                        </div>

                        <div class="overflow-x-auto">
                            <table class="w-full text-sm min-w-[1100px]" data-test="righe">
                                <thead>
                                    <tr class="border-b border-slate-200 dark:border-slate-800 text-[11px] uppercase tracking-wider text-slate-500 dark:text-slate-400">
                                        <th class="text-left py-2 pr-3">Persona</th>
                                        <th class="text-left py-2 pr-3">Unità</th>
                                        <th class="text-right py-2 pr-3">Spese dell'esercizio</th>
                                        <th class="text-right py-2 pr-3">Spese personali non chieste</th>
                                        <th class="text-right py-2 pr-3">Quote dei piani</th>
                                        <th class="text-right py-2 pr-3">Conguaglio</th>
                                        <th class="text-right py-2 pr-3">Posizione sulle rate</th>
                                        <th class="text-right py-2 pr-3">Fuori dalle rate</th>
                                        <th class="text-right py-2">Saldo finale</th>
                                    </tr>
                                </thead>
                                <tbody v-for="gr in gruppi" :key="gr.gestione_id" :data-gestione="gr.gestione_id">
                                    <tr><td colspan="9" class="pt-4 pb-1 font-semibold text-slate-800 dark:text-slate-200">{{ nomeGestione(gr.gestione_id) }}</td></tr>
                                    <tr v-if="gestione(gr.gestione_id)?.in_corso" data-test="in-corso">
                                        <td colspan="9" class="pb-2 text-[12px] text-amber-800 dark:text-amber-300">
                                            {{ gestione(gr.gestione_id)!.in_corso!.frase }}
                                            <Link v-if="gestione(gr.gestione_id)!.in_corso!.senza_data_fine" :href="hrefGestione(gr.gestione_id)" class="ml-1 font-semibold underline" data-test="scrivi-data-fine">Scrivi la data di fine</Link>
                                            <Link v-else-if="gestione(gr.gestione_id)!.in_corso!.sposta_data_fine" :href="hrefGestione(gr.gestione_id)" class="ml-1 font-semibold underline" data-test="sposta-data-fine">Apri la gestione</Link>
                                        </td>
                                    </tr>
                                    <tr v-if="gestione(gr.gestione_id)?.opera_finita" data-test="opera-finita"><td colspan="9" class="pb-2 text-[12px] text-slate-600 dark:text-slate-400">{{ gestione(gr.gestione_id)!.opera_finita }}</td></tr>
                                    <tr v-for="(c, i) in (gestione(gr.gestione_id)?.coperture ?? [])" :key="`cop-${gr.gestione_id}-${i}`" data-test="copertura"><td colspan="9" class="pb-2 text-[12px] text-slate-600 dark:text-slate-400">{{ c }}</td></tr>
                                    <tr v-for="(a, i) in (gestione(gr.gestione_id)?.avvisi ?? [])" :key="`avv-${gr.gestione_id}-${i}`" data-test="avviso"><td colspan="9" class="pb-2 text-[12px] text-slate-600 dark:text-slate-400">{{ a }}</td></tr>
                                    <template v-for="r in gr.righe" :key="`${r.gestione_id}-${r.anagrafica_id}-${r.immobile_id}-${r.subtotale}`">
                                        <tr class="border-b border-slate-100 dark:border-slate-800" :class="r.subtotale ? 'font-semibold bg-slate-50 dark:bg-slate-800/40' : ''" data-test="riga">
                                            <td class="py-1.5 pr-3">
                                                <template v-if="r.subtotale">{{ r.nome }}</template>
                                                <template v-else>
                                                    {{ r.nome }}
                                                    <span class="block text-[11px]">
                                                        <a :href="hrefFoglio(r.anagrafica_id)" target="_blank" class="underline decoration-dotted underline-offset-4">il suo foglio</a> ·
                                                        <Link :href="hrefEstratto(r.anagrafica_id)" class="underline decoration-dotted underline-offset-4">l'estratto conto</Link>
                                                    </span>
                                                    <UltimoInvioDelFoglio :condominio-id="props.condominio.id" :esercizio-id="props.esercizio.id" :persona="r.anagrafica_id" />
                                                </template>
                                            </td>
                                            <td class="py-1.5 pr-3 text-slate-600 dark:text-slate-400">{{ r.unita ?? '—' }}<span v-if="r.giorni !== null" class="block text-[11px]" data-test="giorni">{{ r.giorni }} giorni</span></td>
                                            <td class="py-1.5 pr-3 text-right tabular-nums">{{ cella(r.a) }}</td>
                                            <td class="py-1.5 pr-3 text-right tabular-nums">{{ cella(r.personali) }}</td>
                                            <td class="py-1.5 pr-3 text-right tabular-nums">{{ cella(r.b) }}<span v-if="r.b_non_emesse" class="block text-[11px] text-slate-500 dark:text-slate-400">di cui non emesse {{ euro(r.b_non_emesse) }}</span></td>
                                            <td class="py-1.5 pr-3 text-right tabular-nums font-semibold">{{ conSegno(r.conguaglio) }}</td>
                                            <td class="py-1.5 pr-3 text-right tabular-nums">{{ cella(r.posizione) }}</td>
                                            <td class="py-1.5 pr-3 text-right tabular-nums">{{ cella(r.fuori) }}</td>
                                            <td class="py-1.5 text-right tabular-nums font-semibold">
                                                {{ conSegno(r.saldo) }}
                                                <span v-if="r.non_emesse && r.saldo !== null" class="block text-[11px] font-normal text-slate-500 dark:text-slate-400" data-test="non-emesse">più le rate non ancora emesse: {{ euro(r.non_emesse) }}</span>
                                            </td>
                                        </tr>
                                        <tr v-if="r.non_disponibile || r.note.length" class="border-b border-slate-100 dark:border-slate-800" data-test="note">
                                            <td colspan="9" class="pb-2 pl-4 text-[11.5px] text-amber-800 dark:text-amber-300">
                                                <p v-if="r.non_disponibile">Non disponibile: {{ r.non_disponibile }}</p>
                                                <p v-for="(n, i) in r.note" :key="i">{{ n }}</p>
                                            </td>
                                        </tr>
                                    </template>
                                </tbody>
                                <tbody v-if="k.righe.length === 0"><tr><td colspan="9" class="py-4 text-center text-slate-400 dark:text-slate-500 italic">Nessuna persona con una cifra in questo esercizio</td></tr></tbody>
                                <tfoot>
                                    <tr class="border-t-2 border-slate-300 dark:border-slate-700 font-bold text-slate-800 dark:text-slate-200" data-test="condominio">
                                        <td class="py-2 pr-3" colspan="2">Il condominio</td>
                                        <td class="py-2 pr-3 text-right tabular-nums">{{ euro(k.condominio.a) }}</td>
                                        <td class="py-2 pr-3 text-right tabular-nums">{{ euro(k.condominio.personali) }}</td>
                                        <td class="py-2 pr-3 text-right tabular-nums">{{ euro(k.condominio.b) }}</td>
                                        <td class="py-2 pr-3 text-right tabular-nums">{{ conSegno(k.condominio.conguaglio) }}</td>
                                        <td class="py-2 pr-3 text-right tabular-nums">{{ euro(k.condominio.posizione) }}</td>
                                        <td class="py-2 pr-3 text-right tabular-nums">{{ euro(k.condominio.fuori_dalle_rate) }}</td>
                                        <td class="py-2 text-right tabular-nums">
                                            {{ conSegno(k.condominio.saldo) }}
                                            <span v-if="k.condominio.non_emesse" class="block text-[11px] font-normal text-slate-500 dark:text-slate-400" data-test="non-emesse-condominio">più le rate non ancora emesse: {{ euro(k.condominio.non_emesse) }}</span>
                                        </td>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                        <div v-if="k.pagine.ultima > 1" class="flex gap-3 text-[12px] mt-2" data-test="pagine">
                            <span class="text-slate-500 dark:text-slate-400">Pagina {{ k.pagine.corrente }} di {{ k.pagine.ultima }}</span>
                            <Link v-if="k.pagine.corrente > 1" :href="hrefPagina(k.pagine.corrente - 1)" class="underline">le righe prima</Link>
                            <Link v-if="k.pagine.dopo > 0" :href="hrefPagina(k.pagine.corrente + 1)" class="underline" data-test="altre">altre {{ k.pagine.dopo }} righe</Link>
                        </div>
                        <p v-if="k.non_disponibili" class="text-[12px] text-slate-500 dark:text-slate-400 mt-2" data-test="non-disponibili">{{ k.non_disponibili }}</p>
                        <p v-for="g in k.gestioni.filter((x) => x.gia_versato_senza_titolare)" :key="`senza-titolare-${g.id}`" class="text-[12px] text-slate-500 dark:text-slate-400 mt-2" data-test="senza-titolare">{{ g.nome }}: {{ g.gia_versato_senza_titolare }}</p>
                        <p v-if="k.secondo_momento" class="text-[12.5px] text-slate-700 dark:text-slate-300 mt-3" data-test="secondo-momento">{{ k.secondo_momento.frase }}</p>

                        <!-- Le uscite che nessuno paga -->
                        <div v-if="k.gestioni.some((g) => g.non_attribuite.length) || k.fuori_dai_capitoli.length" class="mt-6" data-test="nessuno">
                            <h3 class="font-semibold text-slate-800 dark:text-slate-200 mb-1">Le uscite che nessuno paga</h3>
                            <ul class="text-[12.5px] space-y-1">
                                <template v-for="g in k.gestioni" :key="g.id">
                                    <li v-for="n in g.non_attribuite" :key="`${g.id}-${n.conto_id}`" data-test="non-attribuita">
                                        <Link :href="hrefPianoConti(n.piano_conto_id)" class="underline decoration-dotted underline-offset-4">{{ n.voce }}</Link> ({{ g.nome }}): {{ euro(n.importo) }} non attribuiti.
                                        {{ n.motivo }}
                                        <Link :href="hrefRiepilogo" class="ml-2 text-[11px] font-semibold underline" data-test="apri-riepilogo">Apri il riepilogo</Link>
                                    </li>
                                </template>
                                <li v-for="f in k.fuori_dai_capitoli" :key="f.nome" data-test="fuori-capitoli">{{ f.nome }}, fuori dai capitoli: {{ euro(f.importo) }}. {{ f.motivo }}</li>
                            </ul>
                        </div>
                        <p v-for="g in k.gestioni.filter((x) => x.non_calcolabile)" :key="`nc-${g.id}`" class="mt-3 text-[12.5px] text-amber-800 dark:text-amber-300" data-test="non-calcolabile">
                            {{ g.nome }}: il conguaglio non si calcola. {{ g.non_calcolabile }}
                        </p>
                    </MovimentiLayout>
                </section>
            </div>
        </div>
    </GestionaleLayout>
</template>
