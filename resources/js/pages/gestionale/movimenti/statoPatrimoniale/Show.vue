<script setup lang="ts">
import { computed, ref } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import GestionaleLayout from '@/layouts/GestionaleLayout.vue';
import MovimentiLayout from '@/layouts/gestionale/MovimentiLayout.vue';
import PageHeaderGuide from '@/components/PageHeaderGuide.vue';
import { usePermission } from '@/composables/permissions';
import { useCurrencyFormatter } from '@/composables/useCurrencyFormatter';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import { Camera, Scale, ListChecks, Printer, Landmark, Users, Receipt, TrendingUp, CheckCircle2, XCircle, AlertTriangle, ArrowRight, ListTree, CalendarClock, ChevronDown } from 'lucide-vue-next';
import type { Building } from '@/types/buildings';
import type { Esercizio } from '@/types/gestionale/esercizi';

/* ─── I tipi rispecchiano StatoPatrimonialePaginaService::costruisci() — tutto in centesimi ─── */
interface Voce { id: number; codice: string; nome: string; tipo: string; categoria: string; ruolo: string | null; saldo: number }
interface Gruppo { gruppo: string; ordine: number; voci: Voce[]; totale: number }
type Esito = 'quadra' | 'non_quadra' | 'segnalazione';
interface Controllo { id: string; nome: string; titolo: string; sinistra: number; destra: number; segno?: string; esito: Esito; nota: string; rimedio: string | null; azione: string | null; azione_parametro?: string | null }
interface RigaRiepilogo { cassa_id: number; conto_contabile_id: number | null; cassa: string; tipo: string; iniziale: number; entrate: number; uscite: number; finale: number; negativa: boolean; minimo: number | null; minimo_il: string | null }
interface Fondo { cassa: string; sottotipo: string | null; sottotipo_label: string; saldo: number; vincolato: boolean; vincolo_registrato: number; scoperto: number }
interface Fattura { id: number; fornitore: string; numero: string | null; data: string | null; scadenza: string | null; residuo: number; nota_credito: boolean }

interface Pagina {
    data: string; dal: string; stato_esercizio: 'futuro' | 'aperto' | 'chiuso';
    situazione: {
        attivo: { gruppi: Gruppo[]; totale: number }; passivo: { gruppi: Gruppo[]; totale: number };
        costi_voci: Voce[]; ricavi_voci: Voce[]; liquidita_non_contabilizzata: number; costi: number; ricavi: number;
        risultato_motore: number; sbilancio: number; quadra: boolean;
    };
    sintesi: { liquidita: number; in_fondi: number; crediti_condomini: number; debiti_fornitori: number; debiti_erario: number };
    risultato_gestione: { quote_emesse: number; costi: number; risultato: number; cumulato: number };
    riepilogo: {
        dal: string; al: string; stato_esercizio: string; righe: RigaRiepilogo[];
        totale: { iniziale: number; entrate: number; uscite: number; finale: number };
        giroconti: { entrate: number; uscite: number }; stornate: { coppie: number; importo: number }; piede_quadra: boolean;
        casse_reali_negative: { cassa: string; minimo: number; data: string | null }[];
    };
    liquidita: {
        liquidita_totale: number; libera: number; in_fondi: number; vincolata: number; generica: number;
        altra: number; altra_voci: { voce: string; saldo: number }[]; fondi: Fondo[];
        vincolo_registrato: number; vincolo_su_fondi: number; vincolo_non_accantonato: number; scoperto_fondi: number;
        avanzo_registrato: number; gia_speso_acconto: number; esito_r8: string;
    };
    raccordo: { righe: { voce: string; natura: string; effetto: number }[]; somma: number; variazione_liquidita: number; scarto: number; quadra: boolean };
    debiti: { disponibile: boolean; fatture: Fattura[]; totale_residui: number; saldo_fornitori: number; saldo_erario: number; scarto: number };
    controlli: Controllo[];
}

const props = defineProps<{ condominio: Building; condomini: Building[]; esercizio: Esercizio; esercizi: Esercizio[]; pagina: Pagina }>();

const { generatePath, generateRoute } = usePermission();
const { euro } = useCurrencyFormatter();
const p = computed(() => props.pagina);
const oggiISO = new Date().toISOString().slice(0, 10);

const formatData = (iso: string | null) => { if (!iso) return '—'; const [a, m, g] = iso.split('-'); return `${g}/${m}/${a}`; };
const importo = (v: number) => v < 0 ? 'text-rose-600' : 'text-slate-800';

const headerBreadcrumbs = computed(() => [
    { title: 'Gestionale', href: generatePath('gestionale/:condominio', { condominio: props.condominio.id }) },
    { title: 'Movimenti' },
    { title: 'Stato patrimoniale' },
]);

/* ─── Controlli: conteggio onesto e destinazioni dei rimedi ─── */
const nonQuadrano = computed(() => p.value.controlli.filter(c => c.esito === 'non_quadra').length);
const segnalazioni = computed(() => p.value.controlli.filter(c => c.esito === 'segnalazione').length);
const riassuntoControlli = computed(() => {
    if (nonQuadrano.value === 0 && segnalazioni.value === 0) return 'tutti i controlli quadrano';
    const parti: string[] = [];
    if (nonQuadrano.value > 0) parti.push(`${nonQuadrano.value} non ${nonQuadrano.value === 1 ? 'quadra' : 'quadrano'}`);
    if (segnalazioni.value > 0) parti.push(`${segnalazioni.value} da leggere`);
    return parti.join(', ');
});
const classeEsito = (e: Esito) => ({
    quadra: 'bg-emerald-50 border-emerald-200 text-emerald-900',
    non_quadra: 'bg-rose-50 border-rose-200 text-rose-900',
    segnalazione: 'bg-amber-50 border-amber-200 text-amber-900',
}[e]);
const coloreEsito = (e: Esito) => ({ quadra: 'text-emerald-700', non_quadra: 'text-rose-700', segnalazione: 'text-amber-700' }[e]);
const iconaEsito = (e: Esito) => ({ quadra: CheckCircle2, non_quadra: XCircle, segnalazione: AlertTriangle }[e]);
const testoEsito = (e: Esito) => ({ quadra: 'quadra', non_quadra: 'non quadra', segnalazione: 'da leggere' }[e]);

const base = { condominio: props.condominio.id, esercizio: props.esercizio.id };
const hrefAzione = (c: Controllo): string | null => {
    switch (c.azione) {
        case 'libro-giornale': return generatePath('gestionale/:condominio/esercizi/:esercizio/scritture', base);
        case 'prima-nota': return generatePath('gestionale/:condominio/esercizi/:esercizio/registro-contabilita', base) + (c.azione_parametro ? `?search=${encodeURIComponent(c.azione_parametro)}` : '');
        case 'giroconti': return generatePath('gestionale/:condominio/giroconti', { condominio: props.condominio.id });
        case 'casse': return generatePath('gestionale/:condominio/casse', { condominio: props.condominio.id });
        case 'esercizio': return generatePath('gestionale/:condominio/esercizi/:esercizio/edit', base);
        case 'cassa': return generatePath('gestionale/:condominio/casse/:cassa/edit', { condominio: props.condominio.id, cassa: c.azione_parametro ?? '' });
        default: return null;
    }
};
const etichettaAzione = (a: string | null) => ({ 'libro-giornale': 'Apri il Libro Giornale', 'prima-nota': 'Apri la Prima nota', giroconti: 'Vai ai giroconti', casse: 'Vai alle casse', esercizio: 'Modifica l\'esercizio', cassa: 'Registra il saldo iniziale' }[a ?? ''] ?? 'Vai');

/* ─── Debiti: la modale delle fatture da pagare ─── */
const debitiAperti = ref(false);
// D21.7: ogni conto della situazione patrimoniale è la porta del suo mastrino — il dettaglio,
// riga per riga, del numero che sta accanto. Stesso esercizio, stessa data della fotografia.
const hrefMastrino = (contoId: number) => generatePath('gestionale/:condominio/esercizi/:esercizio/conti/:contoContabile/movimenti', {
    condominio: props.condominio.id, esercizio: props.esercizio.id, contoContabile: contoId,
});
const hrefFatture = generatePath('gestionale/:condominio/fatture', { condominio: props.condominio.id });
const hrefPaga = (f: Fattura) => generatePath('gestionale/:condominio/pagamenti-fornitori/create', { condominio: props.condominio.id }) + `?fattura_id=${f.id}`;
const hrefF24 = generatePath('gestionale/:condominio/f24', { condominio: props.condominio.id });
const scaduta = (f: Fattura) => !!f.scadenza && f.scadenza < oggiISO;

const classeR8 = computed<Esito>(() => p.value.liquidita.esito_r8 === 'quadra' ? 'quadra' : (p.value.liquidita.esito_r8 === 'scoperto' ? 'non_quadra' : 'segnalazione'));

function stampa() {
    window.open(route(generateRoute('gestionale.esercizi.stato-patrimoniale.print'), base), '_blank');
}

/** Il libro mastro — tutti i mastrini dell'esercizio — si stampa anche da qui, che è la porta dei conti (beta.26). */
function stampaLibroMastro() {
    window.open(route(generateRoute('gestionale.esercizi.libro-mastro.print'), base), '_blank');
}

const pageGuides = [
    { title: 'Una fotografia, non un flusso', description: 'Ogni saldo è quanto c\'era sui conti a quella data, con dentro tutto ciò che è successo prima — anche negli esercizi precedenti. Non si elencano gli anni passati: il loro effetto sta nel numero. Per l\'esercizio aperto la data è oggi; per uno chiuso, la sua data di fine.', icon: Camera, colorVariant: 'blue' as const },
    { title: 'Il risultato di gestione', description: 'Quote emesse ai condòmini meno costi dell\'esercizio: l\'avanzo o il disavanzo che andrà a conguaglio. In questa versione le quote emesse sono un debito della gestione verso i condòmini, non un ricavo: per questo attività e passività non sono uguali, e in mezzo ci sono i costi — elencati uno per uno.', icon: TrendingUp, colorVariant: 'emerald' as const },
    { title: 'I controlli dicono come rimediare', description: 'Sei uguaglianze con i numeri veri, non un semaforo. Quando una non torna, la card dice cosa è successo, cosa fare, e porta nella pagina dove si fa. I controlli che il programma non può eseguire non compaiono.', icon: ListChecks, colorVariant: 'amber' as const },
];
</script>

<template>
    <Head title="Stato patrimoniale" />
    <GestionaleLayout>
        <div class="px-6 py-8 space-y-3">
            <PageHeaderGuide
                page-title="Stato patrimoniale"
                :page-subtitle="`Situazione al ${formatData(p.data)}, riepilogo finanziario dell'esercizio e controlli di quadratura.`"
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
                            <Button variant="outline" class="h-8 px-3 border-slate-200 text-slate-700 bg-white hover:bg-slate-50 shadow-sm shrink-0 gap-2">
                                <Printer class="w-4 h-4" />
                                <span class="hidden sm:inline">Stampa</span>
                                <ChevronDown class="w-3.5 h-3.5 text-slate-400" />
                            </Button>
                        </DropdownMenuTrigger>
                        <DropdownMenuContent align="end" class="w-72">
                            <DropdownMenuItem class="flex-col items-start gap-0.5 py-2" @click="stampa">
                                <span class="font-medium">Stato patrimoniale</span>
                                <span class="text-[11px] text-slate-500 leading-snug">Questa pagina: card, controlli, situazione patrimoniale, liquidità e riepilogo.</span>
                            </DropdownMenuItem>
                            <DropdownMenuItem class="flex-col items-start gap-0.5 py-2" @click="stampaLibroMastro">
                                <span class="font-medium">Libro mastro</span>
                                <span class="text-[11px] text-slate-500 leading-snug">Il mastrino di ogni conto movimentato, un conto per pagina, dal riporto all'ultimo saldo.</span>
                            </DropdownMenuItem>
                        </DropdownMenuContent>
                    </DropdownMenu>
                </template>
            </PageHeaderGuide>

            <div class="w-full">
                <section class="w-full space-y-4">
                    <MovimentiLayout>
                        <!-- Esercizio non ancora iniziato: la fotografia è a oggi, i flussi sono zero. Va detto. -->
                        <div v-if="p.stato_esercizio === 'futuro'" class="mb-6 rounded-lg border border-amber-200 bg-amber-50 p-3 text-[12px] text-amber-900 flex items-start gap-2">
                            <CalendarClock class="w-4 h-4 mt-0.5 shrink-0" />
                            <div>Questo esercizio inizia il <b>{{ formatData(p.dal) }}</b> e non è ancora cominciato: la situazione qui sotto è quella di oggi, e il riepilogo dell'esercizio non ha ancora movimenti.</div>
                        </div>

                        <!-- ═══ I quattro numeri ═══════════════════════════════════════════════════ -->
                        <div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-4 mb-6">
                            <div class="border rounded-xl p-4 shadow-sm flex items-start gap-3 bg-slate-50/50 border-slate-200">
                                <div class="p-2.5 rounded-lg border shrink-0 bg-slate-100 border-slate-200"><Landmark class="w-5 h-5 text-slate-600" /></div>
                                <div class="min-w-0">
                                    <div class="text-[11px] uppercase tracking-wider text-slate-500 font-bold">Liquidità al {{ formatData(p.data) }}</div>
                                    <div class="text-lg font-bold tabular-nums" :class="importo(p.sintesi.liquidita)">{{ euro(p.sintesi.liquidita) }}</div>
                                    <div v-if="p.sintesi.in_fondi !== 0" class="text-[11px] text-slate-500">di cui nei fondi {{ euro(p.sintesi.in_fondi) }}</div>
                                </div>
                            </div>
                            <div class="border rounded-xl p-4 shadow-sm flex items-start gap-3 bg-sky-50/50 border-sky-200">
                                <div class="p-2.5 rounded-lg border shrink-0 bg-sky-100 border-sky-200"><Users class="w-5 h-5 text-sky-600" /></div>
                                <div class="min-w-0">
                                    <div class="text-[11px] uppercase tracking-wider text-slate-500 font-bold">Crediti verso i condòmini</div>
                                    <div class="text-lg font-bold tabular-nums" :class="importo(p.sintesi.crediti_condomini)">{{ euro(p.sintesi.crediti_condomini) }}</div>
                                    <div class="text-[11px] text-slate-500">quote emesse e non incassate</div>
                                </div>
                            </div>
                            <div class="border rounded-xl p-4 shadow-sm flex items-start gap-3 bg-rose-50/50 border-rose-200">
                                <div class="p-2.5 rounded-lg border shrink-0 bg-rose-100 border-rose-200"><Receipt class="w-5 h-5 text-rose-600" /></div>
                                <div class="min-w-0">
                                    <div class="text-[11px] uppercase tracking-wider text-slate-500 font-bold">Debiti</div>
                                    <div class="text-lg font-bold tabular-nums" :class="importo(p.sintesi.debiti_fornitori + p.sintesi.debiti_erario)">{{ euro(p.sintesi.debiti_fornitori + p.sintesi.debiti_erario) }}</div>
                                    <div class="text-[11px] text-slate-500">fornitori {{ euro(p.sintesi.debiti_fornitori) }} · Erario {{ euro(p.sintesi.debiti_erario) }}</div>
                                    <!-- Sotto, non accanto all'importo: con le migliaia «6 fatture da pagare» andava a capo. -->
                                    <button v-if="p.debiti.disponibile" type="button" class="mt-1 inline-flex items-center gap-1 text-[11px] font-semibold text-primary hover:underline whitespace-nowrap" @click="debitiAperti = true">
                                        <ListTree class="w-3.5 h-3.5" /> {{ p.debiti.fatture.length }} {{ p.debiti.fatture.length === 1 ? 'fattura' : 'fatture' }} da pagare
                                    </button>
                                </div>
                            </div>
                            <div class="border rounded-xl p-4 shadow-sm flex items-start gap-3" :class="p.risultato_gestione.risultato < 0 ? 'bg-amber-50/50 border-amber-200' : 'bg-emerald-50/50 border-emerald-200'">
                                <div class="p-2.5 rounded-lg border shrink-0" :class="p.risultato_gestione.risultato < 0 ? 'bg-amber-100 border-amber-200' : 'bg-emerald-100 border-emerald-200'">
                                    <TrendingUp class="w-5 h-5" :class="p.risultato_gestione.risultato < 0 ? 'text-amber-600' : 'text-emerald-600'" />
                                </div>
                                <div class="min-w-0">
                                    <div class="text-[11px] uppercase tracking-wider text-slate-500 font-bold">{{ p.risultato_gestione.risultato < 0 ? 'Disavanzo' : (p.risultato_gestione.risultato > 0 ? 'Avanzo' : 'Risultato') }} di gestione</div>
                                    <div class="text-lg font-bold tabular-nums" :class="p.risultato_gestione.risultato < 0 ? 'text-amber-700' : 'text-emerald-700'">{{ euro(p.risultato_gestione.risultato) }}</div>
                                    <div class="text-[11px] text-slate-500">quote emesse {{ euro(p.risultato_gestione.quote_emesse) }} − costi {{ euro(p.risultato_gestione.costi) }}</div>
                                </div>
                            </div>
                        </div>

                        <!-- ═══ I controlli — prima delle tabelle, perché è la domanda che si fa per prima ═══ -->
                        <div class="mb-6">
                            <div class="flex items-center justify-between mb-3">
                                <h3 class="text-[11px] uppercase tracking-wider text-slate-500 font-bold flex items-center gap-1.5">
                                    <ListChecks class="w-3.5 h-3.5" /> Controlli di quadratura
                                </h3>
                                <span class="text-[11px] font-semibold" :class="nonQuadrano > 0 ? 'text-rose-700' : (segnalazioni > 0 ? 'text-amber-700' : 'text-emerald-700')">{{ riassuntoControlli }}</span>
                            </div>
                            <!-- Un elenco, una riga per controllo: cinque card impilate occupavano mezza pagina
                                 (Vincenzo, a video). Il rimedio compare solo sotto la riga che non torna. -->
                            <div class="rounded-md border bg-white divide-y">
                                <div v-for="c in p.controlli" :key="c.id" class="px-4 py-2.5" :class="c.esito === 'quadra' ? '' : (c.esito === 'non_quadra' ? 'bg-rose-50/60' : 'bg-amber-50/60')">
                                    <div class="grid grid-cols-1 lg:grid-cols-12 gap-x-4 gap-y-1 items-center">
                                        <div class="lg:col-span-3 flex items-center justify-between gap-2 min-w-0">
                                            <div class="font-semibold text-[13px] text-slate-800 leading-snug">{{ c.nome }}</div>
                                            <span class="inline-flex items-center gap-1 font-semibold text-[11px] shrink-0 whitespace-nowrap" :class="coloreEsito(c.esito)">
                                                <component :is="iconaEsito(c.esito)" class="w-3.5 h-3.5" /> {{ testoEsito(c.esito) }}
                                            </span>
                                        </div>
                                        <div class="lg:col-span-4 min-w-0">
                                            <div class="text-[13px] font-semibold tabular-nums text-slate-800 whitespace-nowrap">
                                                <template v-if="c.id === 'CN'">{{ c.sinistra }} {{ c.sinistra === 1 ? 'cassa' : 'casse' }} sotto zero</template>
                                                <template v-else>{{ euro(c.sinistra) }} <span class="text-slate-400 font-normal">{{ c.segno ?? (c.esito === 'non_quadra' ? '≠' : '=') }}</span> {{ euro(c.destra) }}</template>
                                            </div>
                                            <div class="text-[11px] text-slate-500 leading-snug">{{ c.titolo }}</div>
                                        </div>
                                        <div class="lg:col-span-5 text-[11.5px] leading-snug text-slate-600">{{ c.nota }}</div>
                                    </div>
                                    <div v-if="c.rimedio" class="mt-2 pt-2 border-t border-dashed text-[11.5px] leading-snug flex flex-col sm:flex-row sm:items-center gap-x-4 gap-y-1" :class="coloreEsito(c.esito)">
                                        <div class="min-w-0"><span class="font-semibold">Come rimediare:</span> {{ c.rimedio }}</div>
                                        <Link v-if="hrefAzione(c)" :href="hrefAzione(c)!" class="inline-flex items-center gap-1 font-semibold hover:underline shrink-0 whitespace-nowrap">
                                            {{ etichettaAzione(c.azione) }} <ArrowRight class="w-3 h-3" />
                                        </Link>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- ═══ 3a — Situazione patrimoniale ════════════════════════════════════════ -->
                        <div class="rounded-md border bg-white mb-6">
                            <div class="px-4 py-3 border-b bg-gray-50/50 flex items-center justify-between">
                                <h3 class="font-semibold text-slate-800 flex items-center gap-2"><Camera class="w-4 h-4 text-slate-500" /> Situazione patrimoniale al {{ formatData(p.data) }}</h3>
                                <span class="text-[11px] text-slate-500">{{ p.stato_esercizio === 'chiuso' ? 'esercizio chiuso: fotografia alla data di fine' : 'fotografia a oggi' }} · un clic su un conto apre il suo mastrino</span>
                            </div>
                            <div class="grid grid-cols-1 lg:grid-cols-2 divide-y lg:divide-y-0 lg:divide-x">
                                <div v-for="lato in (['attivo', 'passivo'] as const)" :key="lato" class="p-4">
                                    <div class="text-[11px] uppercase tracking-wider text-slate-500 font-bold mb-2">{{ lato === 'attivo' ? 'Attività' : 'Passività' }}</div>
                                    <table class="w-full text-[13px]">
                                        <tbody>
                                            <template v-for="g in p.situazione[lato].gruppi" :key="g.gruppo">
                                                <tr class="bg-slate-50/70">
                                                    <td colspan="2" class="pt-2 pb-1 px-2 text-[10.5px] uppercase tracking-wider text-slate-600 font-bold">{{ g.gruppo }}</td>
                                                    <td class="pt-2 pb-1 px-2 text-right tabular-nums font-semibold text-slate-700 whitespace-nowrap">{{ euro(g.totale) }}</td>
                                                </tr>
                                                <tr v-for="v in g.voci" :key="v.id" class="border-b border-slate-100">
                                                    <td colspan="2" class="py-1.5 px-2 text-slate-700 break-words"><Link :href="hrefMastrino(v.id)" class="underline decoration-dotted decoration-slate-300 underline-offset-4 hover:text-primary hover:decoration-primary" title="Apri il mastrino: tutti i movimenti di questo conto"><span class="text-slate-400 tabular-nums mr-2">{{ v.codice }}</span>{{ v.nome }}</Link></td>
                                                    <td class="py-1.5 px-2 text-right tabular-nums whitespace-nowrap align-top" :class="importo(v.saldo)">{{ euro(v.saldo) }}</td>
                                                </tr>
                                            </template>
                                            <tr v-if="lato === 'attivo' && p.situazione.liquidita_non_contabilizzata !== 0" class="bg-amber-50">
                                                <td colspan="2" class="py-1.5 px-2 text-amber-800">Saldi di apertura non ancora registrati a giornale</td>
                                                <td class="py-1.5 px-2 text-right tabular-nums text-amber-800 font-semibold whitespace-nowrap">{{ euro(p.situazione.liquidita_non_contabilizzata) }}</td>
                                            </tr>
                                            <!-- I costi non ancora conguagliati stanno nella colonna delle attività, come
                                                 nella pratica condominiale (sono crediti futuri verso i condòmini): così le
                                                 due colonne finiscono sullo stesso numero — idea di Vincenzo al test reale. -->
                                            <template v-if="lato === 'attivo' && p.situazione.costi_voci.length > 0">
                                                <tr class="bg-amber-50/60">
                                                    <td colspan="2" class="pt-2 pb-1 px-2 text-[10.5px] uppercase tracking-wider text-amber-800 font-bold">Costi non ancora conguagliati</td>
                                                    <td class="pt-2 pb-1 px-2 text-right tabular-nums font-semibold text-amber-800 whitespace-nowrap">{{ euro(p.situazione.costi) }}</td>
                                                </tr>
                                                <tr v-for="v in p.situazione.costi_voci" :key="'c' + v.id" class="border-b border-slate-100">
                                                    <td colspan="2" class="py-1.5 px-2 text-slate-700 break-words"><Link :href="hrefMastrino(v.id)" class="underline decoration-dotted decoration-slate-300 underline-offset-4 hover:text-primary hover:decoration-primary" title="Apri il mastrino: tutti i movimenti di questo conto"><span class="text-slate-400 tabular-nums mr-2">{{ v.codice }}</span>{{ v.nome }}</Link></td>
                                                    <td class="py-1.5 px-2 text-right tabular-nums whitespace-nowrap align-top" :class="importo(v.saldo)">{{ euro(v.saldo) }}</td>
                                                </tr>
                                            </template>
                                            <template v-if="lato === 'passivo' && p.situazione.ricavi_voci.length > 0">
                                                <tr class="bg-slate-50/70">
                                                    <td colspan="2" class="pt-2 pb-1 px-2 text-[10.5px] uppercase tracking-wider text-slate-600 font-bold">Ricavi</td>
                                                    <td class="pt-2 pb-1 px-2 text-right tabular-nums font-semibold text-slate-700 whitespace-nowrap">{{ euro(p.situazione.ricavi) }}</td>
                                                </tr>
                                                <tr v-for="v in p.situazione.ricavi_voci" :key="'r' + v.id" class="border-b border-slate-100">
                                                    <td colspan="2" class="py-1.5 px-2 text-slate-700 break-words"><Link :href="hrefMastrino(v.id)" class="underline decoration-dotted decoration-slate-300 underline-offset-4 hover:text-primary hover:decoration-primary" title="Apri il mastrino: tutti i movimenti di questo conto"><span class="text-slate-400 tabular-nums mr-2">{{ v.codice }}</span>{{ v.nome }}</Link></td>
                                                    <td class="py-1.5 px-2 text-right tabular-nums whitespace-nowrap align-top" :class="importo(v.saldo)">{{ euro(v.saldo) }}</td>
                                                </tr>
                                            </template>
                                            <tr v-if="p.situazione[lato].gruppi.length === 0 && (lato === 'attivo' ? p.situazione.costi_voci.length === 0 : p.situazione.ricavi_voci.length === 0)">
                                                <td colspan="3" class="py-3 px-2 text-center text-slate-400 italic">Nessun conto movimentato</td>
                                            </tr>
                                        </tbody>
                                        <tfoot>
                                            <tr class="border-t-2 border-slate-300">
                                                <td colspan="2" class="py-2 px-2 font-bold" :class="p.situazione.quadra ? 'text-emerald-700' : 'text-rose-700'">{{ lato === 'attivo' ? (p.situazione.costi !== 0 ? 'Totale attività e costi da conguagliare' : 'Totale attività') : (p.situazione.ricavi !== 0 ? 'Totale passività e ricavi' : 'Totale passività') }}</td>
                                                <td class="py-2 px-2 text-right tabular-nums font-bold whitespace-nowrap" :class="p.situazione.quadra ? 'text-emerald-700' : 'text-rose-700'">{{ euro(lato === 'attivo' ? p.situazione.attivo.totale + p.situazione.costi : p.situazione.passivo.totale + p.situazione.ricavi) }}</td>
                                            </tr>
                                        </tfoot>
                                    </table>
                                </div>
                            </div>

                            <!-- Il verdetto, sotto le due colonne. Il titolo non dice più «non sono uguali»:
                                 davanti a due totali la parola che serve è «quadra». -->
                            <div class="border-t px-4 py-3" :class="p.situazione.quadra ? 'bg-emerald-50/40' : 'bg-rose-50/40'">
                                <div class="text-[11px] uppercase tracking-wider font-bold mb-1.5 flex items-center gap-1.5" :class="p.situazione.quadra ? 'text-emerald-700' : 'text-rose-700'">
                                    <component :is="p.situazione.quadra ? CheckCircle2 : XCircle" class="w-3.5 h-3.5" /> Quadratura: {{ p.situazione.quadra ? 'quadra' : 'non quadra' }}
                                </div>
                                <p class="text-[13px] text-slate-700">Attività <b class="tabular-nums text-slate-800">{{ euro(p.situazione.attivo.totale) }}</b> + Costi <b class="tabular-nums text-slate-800">{{ euro(p.situazione.costi) }}</b> = Passività <b class="tabular-nums" :class="p.situazione.quadra ? 'text-emerald-700' : 'text-rose-700'">{{ euro(p.situazione.passivo.totale) }}</b><template v-if="p.situazione.ricavi !== 0"> + Ricavi <b class="tabular-nums text-slate-800">{{ euro(p.situazione.ricavi) }}</b></template><template v-if="!p.situazione.quadra"> <span class="text-rose-700">— sbilancio {{ euro(p.situazione.sbilancio) }}</span></template></p>
                                <p class="mt-1.5 text-[12px] text-slate-600 leading-relaxed">È la stessa uguaglianza del Libro Giornale, dare = avere. I costi dell'esercizio stanno fra le attività finché non saranno conguagliati: sono spese sostenute per conto dei condòmini. Le quote emesse ai condòmini stanno fra le passività come debito della gestione; con la chiusura d'esercizio costi e quote si compenseranno e resterà il conguaglio.</p>
                            </div>
                        </div>

                        <!-- ═══ D11 — Liquidità libera e accantonata ════════════════════════════════ -->
                        <div class="rounded-md border bg-white mb-6">
                            <div class="px-4 py-3 border-b bg-gray-50/50">
                                <h3 class="font-semibold text-slate-800 flex items-center gap-2"><Landmark class="w-4 h-4 text-slate-500" /> Liquidità: libera e accantonata</h3>
                            </div>
                            <!-- items-start: la tabella non deve stirarsi all'altezza del riquadro accanto,
                                 o le righe si allargano e nome e importo sembrano disallineati. -->
                            <div class="p-4 grid grid-cols-1 lg:grid-cols-2 gap-6 items-start">
                                <table class="w-full text-[13px]">
                                    <tbody>
                                        <tr class="border-b border-slate-100">
                                            <td class="py-1.5 text-slate-700">Libera — banca e contanti</td>
                                            <td class="py-1.5 text-right tabular-nums whitespace-nowrap" :class="importo(p.liquidita.libera)">{{ euro(p.liquidita.libera) }}</td>
                                        </tr>
                                        <tr v-for="f in p.liquidita.fondi" :key="f.cassa" class="border-b border-slate-100">
                                            <td class="py-1.5 text-slate-700 align-middle">
                                                <div class="leading-snug">{{ f.cassa }} <span class="text-[11px] text-slate-400">· {{ f.sottotipo_label }}{{ f.vincolato ? ', vincolato' : ', liberamente utilizzabile' }}</span></div>
                                                <div v-if="f.vincolo_registrato !== 0" class="text-[11px] leading-snug" :class="f.scoperto > 0 ? 'text-rose-600' : 'text-slate-500'">vincolo assegnato {{ euro(f.vincolo_registrato) }}<template v-if="f.scoperto > 0"> — mancano {{ euro(f.scoperto) }}</template></div>
                                            </td>
                                            <td class="py-1.5 text-right tabular-nums whitespace-nowrap align-middle" :class="importo(f.saldo)">{{ euro(f.saldo) }}</td>
                                        </tr>
                                        <tr v-if="p.liquidita.fondi.length === 0" class="border-b border-slate-100"><td class="py-1.5 text-slate-400 italic">Nessun fondo</td><td></td></tr>
                                        <tr v-for="a in p.liquidita.altra_voci" :key="a.voce" class="border-b border-slate-100 bg-amber-50/60">
                                            <td class="py-1.5 text-amber-800">{{ a.voce }}</td>
                                            <td class="py-1.5 text-right tabular-nums whitespace-nowrap text-amber-800">{{ euro(a.saldo) }}</td>
                                        </tr>
                                    </tbody>
                                    <tfoot>
                                        <tr class="border-t-2 border-slate-300">
                                            <td class="py-2 font-bold text-slate-800">Totale liquidità</td>
                                            <td class="py-2 text-right tabular-nums font-bold text-slate-800 whitespace-nowrap">{{ euro(p.liquidita.liquidita_totale) }}</td>
                                        </tr>
                                    </tfoot>
                                </table>
                                <div class="text-[12px] space-y-2">
                                    <div class="rounded-lg border p-3" :class="classeEsito(classeR8)">
                                        <div class="font-semibold mb-1.5">Vincoli dichiarati e casse che li tengono</div>
                                        <!-- Quattro righe piane, senza rientri: il «di cui» rientrato sembrava un elenco
                                             a metà (Vincenzo, a video). Le prime tre sono i vincoli, l'ultima è la cassa. -->
                                        <table class="w-full tabular-nums">
                                            <tbody>
                                                <tr class="border-b border-current/10"><td class="py-1">Vincoli dichiarati nei contributi versati</td><td class="py-1 text-right font-semibold whitespace-nowrap">{{ euro(p.liquidita.vincolo_registrato) }}</td></tr>
                                                <tr class="border-b border-current/10"><td class="py-1">Vincoli coperti da una cassa fondo</td><td class="py-1 text-right whitespace-nowrap">{{ euro(p.liquidita.vincolo_su_fondi) }}</td></tr>
                                                <tr class="border-b border-current/10"><td class="py-1">Vincoli ancora nella liquidità libera</td><td class="py-1 text-right whitespace-nowrap">{{ euro(p.liquidita.vincolo_non_accantonato) }}</td></tr>
                                                <tr><td class="py-1 font-semibold">Accantonato nelle casse fondo</td><td class="py-1 text-right font-bold whitespace-nowrap">{{ euro(p.liquidita.in_fondi) }}</td></tr>
                                            </tbody>
                                        </table>
                                        <div class="mt-2 opacity-90">
                                            <template v-if="p.liquidita.esito_r8 === 'scoperto'">Un fondo ha meno di quanto gli è stato assegnato: mancano {{ euro(p.liquidita.scoperto_fondi) }}. Accantona con un giroconto dalla banca, o correggi il vincolo dichiarato.</template>
                                            <template v-else-if="p.liquidita.esito_r8 === 'non_accantonato'">{{ euro(p.liquidita.vincolo_non_accantonato) }} dichiarati vincolati stanno in banca, non in un fondo: il vincolo esiste, l'accantonamento no. È legittimo; se vuoi che sia anche nei conti, accantona in una cassa fondo.</template>
                                            <template v-else-if="p.liquidita.esito_r8 === 'segnalazione'">I vincoli dichiarati non hanno una data: questo confronto vale per l'esercizio aperto, non per uno chiuso.</template>
                                            <template v-else-if="p.liquidita.vincolo_registrato === 0">Nessun contributo è dichiarato vincolato: i fondi tengono {{ euro(p.liquidita.in_fondi) }} senza vincoli da coprire.</template>
                                            <template v-else>Ogni euro dichiarato vincolato ha una cassa fondo che lo tiene.</template>
                                        </div>
                                    </div>
                                    <p v-if="p.liquidita.avanzo_registrato !== 0 || p.liquidita.gia_speso_acconto !== 0" class="text-slate-500">
                                        <template v-if="p.liquidita.avanzo_registrato !== 0">Avanzo dichiarato nei contributi versati, conguagliabile: {{ euro(p.liquidita.avanzo_registrato) }}. </template>
                                        <template v-if="p.liquidita.gia_speso_acconto !== 0">Già speso in acconto prima di KondoManager, quindi non più liquido: {{ euro(p.liquidita.gia_speso_acconto) }}.</template>
                                    </p>
                                    <p class="text-slate-500">Un fondo è una partizione dello stesso conto corrente: il totale è banca + contanti + fondi, una volta sola.</p>
                                </div>
                            </div>
                        </div>

                        <!-- ═══ 3b — Riepilogo finanziario ══════════════════════════════════════════ -->
                        <div class="rounded-md border bg-white mb-6 overflow-x-auto">
                            <div class="px-4 py-3 border-b bg-gray-50/50 flex items-center justify-between">
                                <h3 class="font-semibold text-slate-800">Riepilogo finanziario dell'esercizio</h3>
                                <span class="text-[11px] text-slate-500">dal {{ formatData(p.riepilogo.dal) }} al {{ formatData(p.riepilogo.al) }}</span>
                            </div>
                            <table class="w-full text-[13px] min-w-[640px]">
                                <thead>
                                    <tr class="bg-gray-50/50 text-[11px] uppercase tracking-wider text-slate-500">
                                        <th class="text-left font-bold py-2 px-4">Cassa</th>
                                        <th class="text-right font-bold py-2 px-4">Disponibilità iniziale</th>
                                        <th class="text-right font-bold py-2 px-4">Entrate</th>
                                        <th class="text-right font-bold py-2 px-4">Uscite</th>
                                        <th class="text-right font-bold py-2 px-4">Disponibilità finale</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr v-for="r in p.riepilogo.righe" :key="r.cassa_id" class="border-b border-slate-100" :class="{ 'bg-rose-50': r.negativa }">
                                        <td class="py-2 px-4 text-slate-700">
                                            <Link v-if="r.conto_contabile_id" :href="hrefMastrino(r.conto_contabile_id)" class="underline decoration-dotted decoration-slate-300 underline-offset-4 hover:text-primary hover:decoration-primary" title="Apri il mastrino del conto di questa cassa">{{ r.cassa }}</Link><template v-else>{{ r.cassa }}</template> <span class="text-[11px] text-slate-400">· {{ r.tipo }}</span>
                                            <div v-if="r.negativa" class="text-[11px] text-rose-700 font-semibold">sotto zero: {{ euro(r.minimo ?? 0) }} il {{ formatData(r.minimo_il) }}</div>
                                        </td>
                                        <td class="py-2 px-4 text-right tabular-nums align-top" :class="importo(r.iniziale)">{{ euro(r.iniziale) }}</td>
                                        <td class="py-2 px-4 text-right tabular-nums align-top text-emerald-700">{{ euro(r.entrate) }}</td>
                                        <td class="py-2 px-4 text-right tabular-nums align-top text-rose-700">{{ euro(r.uscite) }}</td>
                                        <td class="py-2 px-4 text-right tabular-nums align-top font-semibold" :class="importo(r.finale)">{{ euro(r.finale) }}</td>
                                    </tr>
                                    <tr v-if="p.riepilogo.righe.length === 0"><td colspan="5" class="py-4 text-center text-slate-400 italic">Nessuna cassa</td></tr>
                                </tbody>
                                <tfoot>
                                    <tr class="border-t-2 border-slate-300 font-bold text-slate-800">
                                        <td class="py-2 px-4">Tutte le casse, flussi con l'esterno</td>
                                        <td class="py-2 px-4 text-right tabular-nums">{{ euro(p.riepilogo.totale.iniziale) }}</td>
                                        <td class="py-2 px-4 text-right tabular-nums text-emerald-700">{{ euro(p.riepilogo.totale.entrate) }}</td>
                                        <td class="py-2 px-4 text-right tabular-nums text-rose-700">{{ euro(p.riepilogo.totale.uscite) }}</td>
                                        <td class="py-2 px-4 text-right tabular-nums">{{ euro(p.riepilogo.totale.finale) }}</td>
                                    </tr>
                                </tfoot>
                            </table>
                            <p class="px-4 py-2 text-[11px] text-slate-500 border-t">
                                Ogni riga quadra da sé: iniziale + entrate − uscite = finale.
                                <template v-if="p.riepilogo.giroconti.entrate !== 0">I trasferimenti fra casse ({{ euro(p.riepilogo.giroconti.entrate) }}) compaiono nelle righe — un accantonamento è un'uscita della banca e un'entrata del fondo — e non nel totale, che conta i soli flussi con l'esterno. </template>
                                <template v-if="p.riepilogo.stornate.coppie !== 0">{{ p.riepilogo.stornate.coppie === 1 ? 'Un movimento stornato' : p.riepilogo.stornate.coppie + ' movimenti stornati' }} nell'esercizio ({{ euro(p.riepilogo.stornate.importo) }}) non {{ p.riepilogo.stornate.coppie === 1 ? 'conta' : 'contano' }} né fra le entrate né fra le uscite: originale e storno si annullano, e restano leggibili nella Prima nota. </template>
                                La disponibilità iniziale è quanto c'era il giorno prima dell'inizio dell'esercizio; l'apertura di una cassa conta qui, non fra le entrate. Entrate e uscite sono le scritture dell'esercizio.
                            </p>
                        </div>

                        <!-- ═══ R7 — Raccordo fra cassa e competenza ════════════════════════════════ -->
                        <div class="rounded-md border bg-white mb-6">
                            <div class="px-4 py-3 border-b bg-gray-50/50">
                                <h3 class="font-semibold text-slate-800">Raccordo fra cassa e competenza</h3>
                                <p class="text-[11px] text-slate-500 mt-0.5">Perché nell'esercizio la liquidità è cambiata di {{ euro(p.raccordo.variazione_liquidita) }} mentre il risultato di gestione è {{ euro(p.risultato_gestione.risultato) }}.</p>
                            </div>
                            <table class="w-full text-[13px]">
                                <tbody>
                                    <tr v-for="r in p.raccordo.righe" :key="r.voce" class="border-b border-slate-100">
                                        <td class="py-2 px-4 text-slate-700">{{ r.voce }}</td>
                                        <td class="py-2 px-4 text-slate-400 text-[12px]">{{ r.natura }}</td>
                                        <td class="py-2 px-4 text-right tabular-nums whitespace-nowrap" :class="importo(r.effetto)">{{ euro(r.effetto) }}</td>
                                    </tr>
                                </tbody>
                                <tfoot>
                                    <tr class="border-t-2 border-slate-300 font-bold text-slate-800">
                                        <td class="py-2 px-4" colspan="2">Variazione della liquidità nell'esercizio</td>
                                        <td class="py-2 px-4 text-right tabular-nums whitespace-nowrap" :class="p.raccordo.quadra ? 'text-emerald-700' : 'text-rose-700'">{{ euro(p.raccordo.variazione_liquidita) }}</td>
                                    </tr>
                                </tfoot>
                            </table>
                            <p class="px-4 py-2 text-[11px] text-slate-500 border-t">
                                Variazione della liquidità = risultato di gestione − aumento dei crediti + aumento dei debiti. Ogni riga è una posta con il suo nome.
                                <span v-if="!p.raccordo.quadra" class="text-rose-700 font-semibold">Non torna per {{ euro(p.raccordo.scarto) }}: una scrittura dell'esercizio non quadra — la diagnosi dello sbilancio nel Libro Giornale la trova.</span>
                            </p>
                        </div>

                        <!-- ═══ Cosa questa pagina non fa ═══════════════════════════════════════════ -->
                        <div class="rounded-md border border-dashed bg-slate-50/50 p-4 text-[12px] text-slate-600 leading-relaxed space-y-1.5">
                            <div class="text-[11px] uppercase tracking-wider text-slate-500 font-bold">Cosa questa pagina non fa ancora, e con quale modulo lo farà</div>
                            <p><b>Non elenca i condòmini che devono soldi</b> — solo il totale. L'elenco per unità arriva con il modulo morosi e solleciti, e si aprirà da qui.</p>
                            <p><b>Non confronta con l'estratto conto della banca.</b> È un controllo tuo finché non ci sarà la riconciliazione bancaria, che diventerà un controllo di questa pagina.</p>
                            <p><b>Non chiude l'esercizio.</b> Quote e costi si accumulano dal primo esercizio: il cumulato da conguagliare è {{ euro(p.risultato_gestione.cumulato) }}. La fotografia al 31/12 di un esercizio chiuso diventerà definitiva con la chiusura guidata.</p>
                            <p><b>Non ha conti di ricavo</b>, per questo non dichiara mai i conti «in pari». <b>Non sceglie una data qualunque</b>: oggi per l'esercizio aperto, la data di fine per uno chiuso.</p>
                        </div>
                    </MovimentiLayout>
                </section>
            </div>
        </div>

        <!-- ═══ Modale: le fatture da pagare ═══════════════════════════════════════════════ -->
        <Dialog v-model:open="debitiAperti">
            <DialogContent class="sm:max-w-4xl">
                <DialogHeader>
                    <DialogTitle>Fatture da pagare</DialogTitle>
                    <DialogDescription>A chi devi soldi, oggi. Ogni riga porta al pagamento di quella fattura.</DialogDescription>
                </DialogHeader>
                <div class="max-h-[50vh] overflow-y-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b border-slate-200 text-[11px] uppercase tracking-wider text-slate-500">
                                <th class="text-left font-bold py-2">Fornitore</th>
                                <th class="text-left font-bold py-2">Documento</th>
                                <th class="text-left font-bold py-2">Scadenza</th>
                                <th class="text-right font-bold py-2 pr-3">Residuo</th>
                                <th class="py-2 w-px"></th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="f in p.debiti.fatture" :key="f.id" class="border-b border-slate-100">
                                <td class="py-2 pr-3 text-slate-700">{{ f.fornitore }}</td>
                                <td class="py-2 pr-3 text-slate-500 tabular-nums whitespace-nowrap">{{ f.numero ?? '—' }} <span class="text-[11px]">del {{ formatData(f.data) }}</span></td>
                                <td class="py-2 pr-3 tabular-nums whitespace-nowrap" :class="scaduta(f) ? 'text-rose-700 font-semibold' : 'text-slate-500'">{{ formatData(f.scadenza) }}<span v-if="scaduta(f)" class="text-[10px] uppercase ml-1">scaduta</span></td>
                                <td class="py-2 pr-3 text-right tabular-nums font-semibold whitespace-nowrap" :class="f.nota_credito ? 'text-emerald-700' : 'text-slate-800'">{{ f.nota_credito ? '−' : '' }}{{ euro(f.residuo) }}</td>
                                <td class="py-2 text-right whitespace-nowrap">
                                    <!-- Pulsanti veri, non link: sono azioni (Vincenzo, a video). La nota di credito
                                         non si paga, si usa in un pagamento: etichetta, su una riga. -->
                                    <Button v-if="!f.nota_credito" as-child size="sm" variant="outline" class="h-7 px-2.5 text-[11px] font-semibold">
                                        <Link :href="hrefPaga(f)">Paga <ArrowRight class="w-3 h-3" /></Link>
                                    </Button>
                                    <span v-else class="inline-flex items-center rounded-md border border-emerald-200 bg-emerald-50 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wider text-emerald-700">nota di credito</span>
                                </td>
                            </tr>
                            <tr v-if="p.debiti.fatture.length === 0"><td colspan="5" class="py-4 text-center text-slate-400 italic">Nessuna fattura da pagare</td></tr>
                        </tbody>
                        <tfoot>
                            <tr class="border-t-2 border-slate-300 font-bold">
                                <td class="py-2 text-slate-800" colspan="3">Residuo delle fatture aperte</td>
                                <td class="py-2 text-right tabular-nums text-slate-800">{{ euro(p.debiti.totale_residui) }}</td><td></td>
                            </tr>
                            <tr class="text-[12px] text-slate-600">
                                <td class="py-1" colspan="3">Saldo del conto «Debiti verso fornitori»</td>
                                <td class="py-1 text-right tabular-nums">{{ euro(p.debiti.saldo_fornitori) }}</td><td></td>
                            </tr>
                            <tr v-if="p.debiti.scarto !== 0" class="text-[12px] text-amber-800">
                                <td class="py-1" colspan="3">Differenza — debiti a giornale senza una fattura aperta (un pregresso, una fattura contestata), o una fattura registrata solo in parte</td>
                                <td class="py-1 text-right tabular-nums font-semibold">{{ euro(p.debiti.scarto) }}</td><td></td>
                            </tr>
                            <tr v-if="p.debiti.saldo_erario !== 0" class="text-[12px] text-slate-600">
                                <td class="py-1" colspan="3">Ritenute da versare all'Erario</td>
                                <td class="py-1 text-right tabular-nums">{{ euro(p.debiti.saldo_erario) }}</td>
                                <td class="py-1 text-right whitespace-nowrap">
                                    <Button as-child size="sm" variant="outline" class="h-7 px-2.5 text-[11px] font-semibold"><Link :href="hrefF24">F24 <ArrowRight class="w-3 h-3" /></Link></Button>
                                </td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
                <div class="flex items-center justify-between text-[11px] text-slate-500">
                    <span>Il residuo è quello di oggi: per un esercizio chiuso l'elenco non può dire quali fatture erano aperte al 31/12.</span>
                    <Button as-child size="sm" variant="outline" class="h-7 px-2.5 text-[11px] font-semibold shrink-0"><Link :href="hrefFatture">Tutte le fatture <ArrowRight class="w-3 h-3" /></Link></Button>
                </div>
            </DialogContent>
        </Dialog>
    </GestionaleLayout>
</template>
