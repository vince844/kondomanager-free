<script setup lang="ts">
import { computed } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import GestionaleLayout from '@/layouts/GestionaleLayout.vue';
import MovimentiLayout from '@/layouts/gestionale/MovimentiLayout.vue';
import PageHeaderGuide from '@/components/PageHeaderGuide.vue';
import RendicontoSchede from '@/components/gestionale/movimenti/rendiconto/RendicontoSchede.vue';
import { usePermission } from '@/composables/permissions';
import { useCurrencyFormatter } from '@/composables/useCurrencyFormatter';
import { Button } from '@/components/ui/button';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import { Camera, Scale, ListChecks, Printer, Landmark, Users, Receipt, TrendingUp, CheckCircle2, XCircle, ArrowRight, ListTree, CalendarClock, ChevronDown } from 'lucide-vue-next';
import type { Building } from '@/types/buildings';
import type { Esercizio } from '@/types/gestionale/esercizi';

/* ─── I tipi rispecchiano StatoPatrimonialePaginaService::costruisci() — tutto in centesimi ─── */
interface Voce { id: number; codice: string; nome: string; tipo: string; categoria: string; ruolo: string | null; saldo: number }
interface Gruppo { gruppo: string; ordine: number; voci: Voce[]; totale: number }
type Esito = 'quadra' | 'non_quadra' | 'segnalazione';
interface Controllo { id: string; nome: string; titolo: string; sinistra: number; destra: number; segno?: string; esito: Esito; nota: string; rimedio: string | null; azione: string | null; azione_parametro?: string | null }
interface RigaRiepilogo { cassa_id: number; conto_contabile_id: number | null; cassa: string; tipo: string; iniziale: number; entrate: number; uscite: number; finale: number; negativa: boolean; minimo: number | null; minimo_il: string | null }
interface Fondo { cassa: string; sottotipo: string | null; sottotipo_label: string; saldo: number; vincolato: boolean; vincolo_registrato: number; scoperto: number }
interface Fattura { id: number; fornitore: string; numero: string | null; data: string | null; scadenza: string | null; residuo: number; nota_credito: boolean; contestata: boolean; pregressa: boolean; esercizio_id: number; gestione_id: number | null }
interface RigaGestione { gestione: { id: number; nome: string; tipo: string }; costi: number; quote_emesse: number; crediti: number; debiti: number; cumulato_opera: { dal: string | null; consuntivo: number } | null }

interface Pagina {
    data: string; dal: string; stato_esercizio: 'futuro' | 'aperto' | 'chiuso';
    situazione: {
        attivo: { gruppi: Gruppo[]; totale: number }; passivo: { gruppi: Gruppo[]; totale: number };
        costi_voci: Voce[]; ricavi_voci: Voce[]; liquidita_non_contabilizzata: number; costi: number; ricavi: number;
        risultato_motore: number; sbilancio: number; quadra: boolean;
    };
    sintesi: { liquidita: number; in_fondi: number; crediti_condomini: number; debiti_fornitori: number; debiti_erario: number; spese_anticipate: number };
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
    debiti: {
        alla: string; fatture: Fattura[]; totale_residui: number; saldo_fornitori: number; saldo_erario: number; erario_su_fatture_non_pagate: number;
        scarto: number; scarto_voci: { voce: string; importo: number; fatture: string[] }[];
    };
    per_gestione: {
        righe: RigaGestione[]; senza_voce: number; senza_gestione: { quote_emesse: number; crediti: number; debiti: number }; differenza_debiti: number;
        totale: { costi: number; quote_emesse: number; crediti: number; debiti: number }; fatture_altra_gestione: string[]; nota_altra_gestione: string | null; nota_liquidita: string;
    };
    controlli: Controllo[];
}

const props = defineProps<{ condominio: Building; condomini: Building[]; esercizio: Esercizio; esercizi: Esercizio[]; pagina: Pagina }>();

const { generatePath, generateRoute } = usePermission();
const { euro } = useCurrencyFormatter();
const p = computed(() => props.pagina);

const formatData = (iso: string | null) => { if (!iso) return '—'; const [a, m, g] = iso.split('-'); return `${g}/${m}/${a}`; };
const importo = (v: number) => v < 0 ? 'text-rose-600 dark:text-rose-400' : 'text-slate-800 dark:text-slate-200';

const headerBreadcrumbs = computed(() => [
    { title: 'Gestionale', href: generatePath('gestionale/:condominio', { condominio: props.condominio.id }) },
    { title: 'Movimenti' },
    { title: 'Rendiconto' },
    { title: 'Situazione patrimoniale' },
]);

const base = { condominio: props.condominio.id, esercizio: props.esercizio.id };
const hrefControlli = computed(() => generatePath('gestionale/:condominio/esercizi/:esercizio/rendiconto/controlli', base));
const hrefCassa = computed(() => generatePath('gestionale/:condominio/esercizi/:esercizio/rendiconto/cassa', base));
const classeEsito = (e: Esito) => ({
    quadra: 'bg-emerald-50 dark:bg-emerald-900/20 border-emerald-200 dark:border-emerald-900 text-emerald-900 dark:text-emerald-200',
    non_quadra: 'bg-rose-50 dark:bg-rose-900/20 border-rose-200 dark:border-rose-900 text-rose-900 dark:text-rose-200',
    segnalazione: 'bg-amber-50 dark:bg-amber-900/20 border-amber-200 dark:border-amber-900 text-amber-900 dark:text-amber-200',
}[e]);

/* ─── Debiti: le fatture aperte alla data, nella pagina ─── */
const vaiAlleFatture = () => document.getElementById('fatture-aperte')?.scrollIntoView({ behavior: 'smooth' });
const segnoVoce = (v: number) => (v < 0 ? '−' : '+');
const hrefCondomini = computed(() => generatePath('gestionale/:condominio/esercizi/:esercizio/rendiconto/condomini', base));
// D21.7: ogni conto della situazione patrimoniale è la porta del suo mastrino — il dettaglio,
// riga per riga, del numero che sta accanto. Stesso esercizio, stessa data della fotografia.
const hrefMastrino = (contoId: number) => generatePath('gestionale/:condominio/esercizi/:esercizio/conti/:contoContabile/movimenti', {
    condominio: props.condominio.id, esercizio: props.esercizio.id, contoContabile: contoId,
});
const hrefFatture = generatePath('gestionale/:condominio/fatture', { condominio: props.condominio.id });
// L'elenco delle persone con rate scadute, alla data di questa pagina (per un esercizio chiuso la data di fine, non oggi): lo scaduto di
// ognuno, senza togliere il credito. Una data dopo oggi l'elenco la porta a oggi.
const hrefRateScadute = computed(() => `${generatePath('gestionale/:condominio/rate-scadute', { condominio: props.condominio.id })}?alla=${p.value.data}`);
const hrefPaga = (f: Fattura) => generatePath('gestionale/:condominio/pagamenti-fornitori/create', { condominio: props.condominio.id }) + `?fattura_id=${f.id}`;
const hrefF24 = generatePath('gestionale/:condominio/f24', { condominio: props.condominio.id });
const scaduta = (f: Fattura) => !!f.scadenza && f.scadenza < p.value.debiti.alla;
const senzaGestione = computed(() => p.value.per_gestione.senza_voce !== 0 || p.value.per_gestione.differenza_debiti !== 0
    || Object.values(p.value.per_gestione.senza_gestione).some(v => v !== 0));

const classeR8 = computed<Esito>(() => p.value.liquidita.esito_r8 === 'quadra' ? 'quadra' : (p.value.liquidita.esito_r8 === 'scoperto' ? 'non_quadra' : 'segnalazione'));

function stampa(parte: string | null = null) {
    window.open(route(generateRoute('gestionale.esercizi.stato-patrimoniale.print'), base) + (parte ? `?parte=${parte}` : ''), '_blank');
}

/** Il libro mastro — tutti i mastrini dell'esercizio — si stampa anche da qui, che è la porta dei conti (beta.26). */
function stampaLibroMastro() {
    window.open(route(generateRoute('gestionale.esercizi.libro-mastro.print'), base), '_blank');
}

const pageGuides = [
    { title: 'Una fotografia, non un flusso', description: 'Ogni saldo è quanto c\'era sui conti a quella data, con dentro tutto ciò che è successo prima — anche negli esercizi precedenti. Non si elencano gli anni passati: il loro effetto sta nel numero. Per l\'esercizio aperto la data è oggi; per uno chiuso, la sua data di fine.', icon: Camera, colorVariant: 'blue' as const },
    { title: 'Il risultato di gestione', description: 'Quote emesse ai condòmini meno costi dell\'esercizio: l\'avanzo o il disavanzo; che cosa farne lo decide l\'assemblea. In questa versione le quote emesse sono un debito della gestione verso i condòmini, non un ricavo: per questo attività e passività non sono uguali, e in mezzo ci sono i costi — elencati uno per uno.', icon: TrendingUp, colorVariant: 'emerald' as const },
    { title: 'I controlli, con i numeri veri', description: 'Uguaglianze e conteggi con i numeri del programma, non un semaforo: sono nella scheda «Controlli». Quando uno non torna, la riga dice che cosa è successo, che cosa fare, e porta nella pagina dove si fa. I controlli che il programma non può eseguire non compaiono.', icon: ListChecks, colorVariant: 'amber' as const },
];
</script>

<template>
    <Head title="Situazione patrimoniale" />
    <GestionaleLayout>
        <div class="px-6 py-8 space-y-3">
            <PageHeaderGuide
                page-title="Situazione patrimoniale"
                :page-subtitle="`Situazione al ${formatData(p.data)}: i conti, le fatture aperte, la divisione per gestione e la liquidità.`"
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
                            <Button variant="outline" class="h-8 px-3 border-slate-200 dark:border-slate-800 text-slate-700 dark:text-slate-300 bg-white dark:bg-slate-900 hover:bg-slate-50 dark:hover:bg-slate-800/50 shadow-sm shrink-0 gap-2">
                                <Printer class="w-4 h-4" />
                                <span class="hidden sm:inline">Stampa</span>
                                <ChevronDown class="w-3.5 h-3.5 text-slate-400 dark:text-slate-500" />
                            </Button>
                        </DropdownMenuTrigger>
                        <DropdownMenuContent align="end" class="w-72">
                            <DropdownMenuItem class="flex-col items-start gap-0.5 py-2" @click="stampa('situazione')">
                                <span class="font-medium">Situazione patrimoniale</span>
                                <span class="text-[11px] text-slate-500 dark:text-slate-400 leading-snug">Questa scheda: i conti alla data, le fatture aperte, la divisione per gestione e la liquidità, con l'esito dei controlli in testa.</span>
                            </DropdownMenuItem>
                            <DropdownMenuItem class="flex-col items-start gap-0.5 py-2" @click="stampa()">
                                <span class="font-medium">Situazione, cassa e controlli</span>
                                <span class="text-[11px] text-slate-500 dark:text-slate-400 leading-snug">La situazione, le entrate e le uscite per cassa, il raccordo e i controlli in un foglio solo.</span>
                            </DropdownMenuItem>
                            <DropdownMenuItem class="flex-col items-start gap-0.5 py-2" @click="stampaLibroMastro">
                                <span class="font-medium">Libro mastro</span>
                                <span class="text-[11px] text-slate-500 dark:text-slate-400 leading-snug">Il mastrino di ogni conto movimentato, un conto per pagina, dal riporto all'ultimo saldo.</span>
                            </DropdownMenuItem>
                        </DropdownMenuContent>
                    </DropdownMenu>
                </template>
            </PageHeaderGuide>

            <div class="w-full">
                <section class="w-full space-y-4">
                    <MovimentiLayout>
                        <RendicontoSchede :condominio-id="props.condominio.id" :esercizio-id="props.esercizio.id" attiva="situazione" />

                        <!-- Esercizio non ancora iniziato: la fotografia è a oggi, i flussi sono zero. Va detto. -->
                        <div v-if="p.stato_esercizio === 'futuro'" class="mb-6 rounded-lg border border-amber-200 dark:border-amber-900 bg-amber-50 dark:bg-amber-900/20 p-3 text-[12px] text-amber-900 dark:text-amber-200 flex items-start gap-2">
                            <CalendarClock class="w-4 h-4 mt-0.5 shrink-0" />
                            <div>Questo esercizio inizia il <b>{{ formatData(p.dal) }}</b> e non è ancora cominciato: la situazione qui sotto è quella di oggi, e il riepilogo dell'esercizio non ha ancora movimenti.</div>
                        </div>

                        <!-- ═══ I quattro numeri ═══════════════════════════════════════════════════ -->
                        <div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-4 mb-6">
                            <div class="border rounded-xl p-4 shadow-sm flex items-start gap-3 bg-slate-50/50 dark:bg-slate-800/30 border-slate-200 dark:border-slate-800">
                                <div class="p-2.5 rounded-lg border shrink-0 bg-slate-100 dark:bg-slate-800 border-slate-200 dark:border-slate-800"><Landmark class="w-5 h-5 text-slate-600 dark:text-slate-400" /></div>
                                <div class="min-w-0">
                                    <div class="text-[11px] uppercase tracking-wider text-slate-500 dark:text-slate-400 font-bold">Liquidità al {{ formatData(p.data) }}</div>
                                    <div class="text-lg font-bold tabular-nums" :class="importo(p.sintesi.liquidita)">{{ euro(p.sintesi.liquidita) }}</div>
                                    <div v-if="p.sintesi.in_fondi !== 0" class="text-[11px] text-slate-500 dark:text-slate-400">di cui nei fondi {{ euro(p.sintesi.in_fondi) }}</div>
                                </div>
                            </div>
                            <div class="border rounded-xl p-4 shadow-sm flex items-start gap-3 bg-sky-50/50 dark:bg-sky-900/20 border-sky-200 dark:border-sky-900">
                                <div class="p-2.5 rounded-lg border shrink-0 bg-sky-100 dark:bg-sky-900/30 border-sky-200 dark:border-sky-900"><Users class="w-5 h-5 text-sky-600 dark:text-sky-400" /></div>
                                <div class="min-w-0">
                                    <div class="text-[11px] uppercase tracking-wider text-slate-500 dark:text-slate-400 font-bold">Crediti verso i condòmini</div>
                                    <div class="text-lg font-bold tabular-nums" :class="importo(p.sintesi.crediti_condomini)">{{ euro(p.sintesi.crediti_condomini) }}</div>
                                    <div class="text-[11px] text-slate-500 dark:text-slate-400">quote emesse e non incassate, e spese anticipate per singole unità</div>
                                    <div v-if="p.sintesi.spese_anticipate !== 0" class="text-[11px] text-slate-500 dark:text-slate-400" data-test="spese-anticipate">di cui spese anticipate per singole unità, non ancora chieste: {{ euro(p.sintesi.spese_anticipate) }}</div>
                                    <Link :href="hrefRateScadute" data-testid="collegamento-rate-scadute" class="text-[11px] text-sky-700 dark:text-sky-400 underline">Chi deve che cosa: le rate scadute</Link>
                                    <Link :href="hrefCondomini" data-test="collegamento-condomini" class="block text-[11px] text-sky-700 dark:text-sky-400 underline">Le posizioni di ognuno</Link>
                                </div>
                            </div>
                            <div class="border rounded-xl p-4 shadow-sm flex items-start gap-3 bg-rose-50/50 dark:bg-rose-900/10 border-rose-200 dark:border-rose-900">
                                <div class="p-2.5 rounded-lg border shrink-0 bg-rose-100 dark:bg-rose-900/30 border-rose-200 dark:border-rose-900"><Receipt class="w-5 h-5 text-rose-600 dark:text-rose-400" /></div>
                                <div class="min-w-0">
                                    <div class="text-[11px] uppercase tracking-wider text-slate-500 dark:text-slate-400 font-bold">Debiti</div>
                                    <div class="text-lg font-bold tabular-nums" :class="importo(p.sintesi.debiti_fornitori + p.sintesi.debiti_erario)">{{ euro(p.sintesi.debiti_fornitori + p.sintesi.debiti_erario) }}</div>
                                    <div class="text-[11px] text-slate-500 dark:text-slate-400">fornitori {{ euro(p.sintesi.debiti_fornitori) }} · Erario {{ euro(p.sintesi.debiti_erario) }}</div>
                                    <div v-if="p.debiti.erario_su_fatture_non_pagate !== 0" class="text-[11px] text-slate-500 dark:text-slate-400" data-test="erario-non-pagate">di cui {{ euro(p.debiti.erario_su_fatture_non_pagate) }} di ritenute su fatture non ancora pagate: fino al pagamento sono un debito verso il fornitore</div>
                                    <!-- Sotto, non accanto all'importo: con le migliaia «6 fatture aperte» andava a capo. -->
                                    <button type="button" class="mt-1 inline-flex items-center gap-1 text-[11px] font-semibold text-primary hover:underline whitespace-nowrap" @click="vaiAlleFatture">
                                        <ListTree class="w-3.5 h-3.5" /> {{ p.debiti.fatture.length }} {{ p.debiti.fatture.length === 1 ? 'fattura aperta' : 'fatture aperte' }}
                                    </button>
                                </div>
                            </div>
                            <div class="border rounded-xl p-4 shadow-sm flex items-start gap-3" :class="p.risultato_gestione.risultato < 0 ? 'bg-amber-50/50 dark:bg-amber-900/10 border-amber-200 dark:border-amber-900' : 'bg-emerald-50/50 dark:bg-emerald-900/10 border-emerald-200 dark:border-emerald-900'">
                                <div class="p-2.5 rounded-lg border shrink-0" :class="p.risultato_gestione.risultato < 0 ? 'bg-amber-100 dark:bg-amber-900/30 border-amber-200 dark:border-amber-900' : 'bg-emerald-100 dark:bg-emerald-900/30 border-emerald-200 dark:border-emerald-900'">
                                    <TrendingUp class="w-5 h-5" :class="p.risultato_gestione.risultato < 0 ? 'text-amber-600 dark:text-amber-400' : 'text-emerald-600 dark:text-emerald-400'" />
                                </div>
                                <div class="min-w-0">
                                    <div class="text-[11px] uppercase tracking-wider text-slate-500 dark:text-slate-400 font-bold">{{ p.risultato_gestione.risultato < 0 ? 'Disavanzo' : (p.risultato_gestione.risultato > 0 ? 'Avanzo' : 'Risultato') }} di gestione</div>
                                    <div class="text-lg font-bold tabular-nums" :class="p.risultato_gestione.risultato < 0 ? 'text-amber-700 dark:text-amber-400' : 'text-emerald-700 dark:text-emerald-400'">{{ euro(p.risultato_gestione.risultato) }}</div>
                                    <div class="text-[11px] text-slate-500 dark:text-slate-400">quote emesse {{ euro(p.risultato_gestione.quote_emesse) }} − costi {{ euro(p.risultato_gestione.costi) }}</div>
                                </div>
                            </div>
                        </div>

                        <!-- I controlli e le entrate e uscite per cassa hanno le loro schede: qui la situazione alla data. -->
                        <p class="mb-6 text-[12px] text-slate-600 dark:text-slate-400" data-test="altre-schede">
                            I controlli, con l'esito, sono nella scheda <Link :href="hrefControlli" class="underline">Controlli</Link>; le entrate e le uscite per cassa e il
                            raccordo con la competenza nella scheda <Link :href="hrefCassa" class="underline">Cassa</Link>.
                        </p>

                        <!-- ═══ 3a — Situazione patrimoniale ════════════════════════════════════════ -->
                        <div class="rounded-md border bg-white dark:bg-slate-900 mb-6">
                            <div class="px-4 py-3 border-b bg-gray-50/50 dark:bg-slate-800/30 flex items-center justify-between">
                                <h3 class="font-semibold text-slate-800 dark:text-slate-200 flex items-center gap-2"><Camera class="w-4 h-4 text-slate-500 dark:text-slate-400" /> Situazione patrimoniale al {{ formatData(p.data) }}</h3>
                                <span class="text-[11px] text-slate-500 dark:text-slate-400">{{ p.stato_esercizio === 'chiuso' ? 'esercizio chiuso: fotografia alla data di fine' : 'fotografia a oggi' }} · un clic su un conto apre il suo mastrino</span>
                            </div>
                            <div class="grid grid-cols-1 lg:grid-cols-2 divide-y lg:divide-y-0 lg:divide-x">
                                <div v-for="lato in (['attivo', 'passivo'] as const)" :key="lato" class="p-4">
                                    <div class="text-[11px] uppercase tracking-wider text-slate-500 dark:text-slate-400 font-bold mb-2">{{ lato === 'attivo' ? 'Attività' : 'Passività' }}</div>
                                    <table class="w-full text-[13px]">
                                        <tbody>
                                            <template v-for="g in p.situazione[lato].gruppi" :key="g.gruppo">
                                                <tr class="bg-slate-50/70 dark:bg-slate-800/40">
                                                    <td colspan="2" class="pt-2 pb-1 px-2 text-[10.5px] uppercase tracking-wider text-slate-600 dark:text-slate-400 font-bold">{{ g.gruppo }}</td>
                                                    <td class="pt-2 pb-1 px-2 text-right tabular-nums font-semibold text-slate-700 dark:text-slate-300 whitespace-nowrap">{{ euro(g.totale) }}</td>
                                                </tr>
                                                <tr v-for="v in g.voci" :key="v.id" class="border-b border-slate-100 dark:border-slate-800">
                                                    <td colspan="2" class="py-1.5 px-2 text-slate-700 dark:text-slate-300 break-words"><Link :href="hrefMastrino(v.id)" class="underline decoration-dotted decoration-slate-300 dark:decoration-slate-600 underline-offset-4 hover:text-primary hover:decoration-primary" title="Apri il mastrino: tutti i movimenti di questo conto"><span class="text-slate-400 dark:text-slate-500 tabular-nums mr-2">{{ v.codice }}</span>{{ v.nome }}</Link></td>
                                                    <td class="py-1.5 px-2 text-right tabular-nums whitespace-nowrap align-top" :class="importo(v.saldo)">{{ euro(v.saldo) }}</td>
                                                </tr>
                                            </template>
                                            <tr v-if="lato === 'attivo' && p.situazione.liquidita_non_contabilizzata !== 0" class="bg-amber-50 dark:bg-amber-900/20">
                                                <td colspan="2" class="py-1.5 px-2 text-amber-800 dark:text-amber-300">Saldi di apertura non ancora registrati a giornale</td>
                                                <td class="py-1.5 px-2 text-right tabular-nums text-amber-800 dark:text-amber-300 font-semibold whitespace-nowrap">{{ euro(p.situazione.liquidita_non_contabilizzata) }}</td>
                                            </tr>
                                            <!-- I costi non ancora conguagliati stanno nella colonna delle attività, come
                                                 nella pratica condominiale (sono crediti futuri verso i condòmini): così le
                                                 due colonne finiscono sullo stesso numero — idea di Vincenzo al test reale. -->
                                            <template v-if="lato === 'attivo' && p.situazione.costi_voci.length > 0">
                                                <tr class="bg-amber-50/60 dark:bg-amber-900/10">
                                                    <td colspan="2" class="pt-2 pb-1 px-2 text-[10.5px] uppercase tracking-wider text-amber-800 dark:text-amber-300 font-bold">Costi non ancora conguagliati</td>
                                                    <td class="pt-2 pb-1 px-2 text-right tabular-nums font-semibold text-amber-800 dark:text-amber-300 whitespace-nowrap">{{ euro(p.situazione.costi) }}</td>
                                                </tr>
                                                <tr v-for="v in p.situazione.costi_voci" :key="'c' + v.id" class="border-b border-slate-100 dark:border-slate-800">
                                                    <td colspan="2" class="py-1.5 px-2 text-slate-700 dark:text-slate-300 break-words"><Link :href="hrefMastrino(v.id)" class="underline decoration-dotted decoration-slate-300 dark:decoration-slate-600 underline-offset-4 hover:text-primary hover:decoration-primary" title="Apri il mastrino: tutti i movimenti di questo conto"><span class="text-slate-400 dark:text-slate-500 tabular-nums mr-2">{{ v.codice }}</span>{{ v.nome }}</Link></td>
                                                    <td class="py-1.5 px-2 text-right tabular-nums whitespace-nowrap align-top" :class="importo(v.saldo)">{{ euro(v.saldo) }}</td>
                                                </tr>
                                            </template>
                                            <template v-if="lato === 'passivo' && p.situazione.ricavi_voci.length > 0">
                                                <tr class="bg-slate-50/70 dark:bg-slate-800/40">
                                                    <td colspan="2" class="pt-2 pb-1 px-2 text-[10.5px] uppercase tracking-wider text-slate-600 dark:text-slate-400 font-bold">Ricavi</td>
                                                    <td class="pt-2 pb-1 px-2 text-right tabular-nums font-semibold text-slate-700 dark:text-slate-300 whitespace-nowrap">{{ euro(p.situazione.ricavi) }}</td>
                                                </tr>
                                                <tr v-for="v in p.situazione.ricavi_voci" :key="'r' + v.id" class="border-b border-slate-100 dark:border-slate-800">
                                                    <td colspan="2" class="py-1.5 px-2 text-slate-700 dark:text-slate-300 break-words"><Link :href="hrefMastrino(v.id)" class="underline decoration-dotted decoration-slate-300 dark:decoration-slate-600 underline-offset-4 hover:text-primary hover:decoration-primary" title="Apri il mastrino: tutti i movimenti di questo conto"><span class="text-slate-400 dark:text-slate-500 tabular-nums mr-2">{{ v.codice }}</span>{{ v.nome }}</Link></td>
                                                    <td class="py-1.5 px-2 text-right tabular-nums whitespace-nowrap align-top" :class="importo(v.saldo)">{{ euro(v.saldo) }}</td>
                                                </tr>
                                            </template>
                                            <tr v-if="p.situazione[lato].gruppi.length === 0 && (lato === 'attivo' ? p.situazione.costi_voci.length === 0 : p.situazione.ricavi_voci.length === 0)">
                                                <td colspan="3" class="py-3 px-2 text-center text-slate-400 dark:text-slate-500 italic">Nessun conto movimentato</td>
                                            </tr>
                                        </tbody>
                                        <tfoot>
                                            <tr class="border-t-2 border-slate-300 dark:border-slate-700">
                                                <td colspan="2" class="py-2 px-2 font-bold" :class="p.situazione.quadra ? 'text-emerald-700 dark:text-emerald-400' : 'text-rose-700 dark:text-rose-400'">{{ lato === 'attivo' ? (p.situazione.costi !== 0 ? 'Totale attività e costi da conguagliare' : 'Totale attività') : (p.situazione.ricavi !== 0 ? 'Totale passività e ricavi' : 'Totale passività') }}</td>
                                                <td class="py-2 px-2 text-right tabular-nums font-bold whitespace-nowrap" :class="p.situazione.quadra ? 'text-emerald-700 dark:text-emerald-400' : 'text-rose-700 dark:text-rose-400'">{{ euro(lato === 'attivo' ? p.situazione.attivo.totale + p.situazione.costi : p.situazione.passivo.totale + p.situazione.ricavi) }}</td>
                                            </tr>
                                        </tfoot>
                                    </table>
                                </div>
                            </div>

                            <!-- Il verdetto, sotto le due colonne. Il titolo non dice più «non sono uguali»:
                                 davanti a due totali la parola che serve è «quadra». -->
                            <div class="border-t px-4 py-3" :class="p.situazione.quadra ? 'bg-emerald-50/40 dark:bg-emerald-900/10' : 'bg-rose-50/40 dark:bg-rose-900/10'">
                                <div class="text-[11px] uppercase tracking-wider font-bold mb-1.5 flex items-center gap-1.5" :class="p.situazione.quadra ? 'text-emerald-700 dark:text-emerald-400' : 'text-rose-700 dark:text-rose-400'">
                                    <component :is="p.situazione.quadra ? CheckCircle2 : XCircle" class="w-3.5 h-3.5" /> Quadratura: {{ p.situazione.quadra ? 'quadra' : 'non quadra' }}
                                </div>
                                <p class="text-[13px] text-slate-700 dark:text-slate-300">Attività <b class="tabular-nums text-slate-800 dark:text-slate-200">{{ euro(p.situazione.attivo.totale) }}</b> + Costi <b class="tabular-nums text-slate-800 dark:text-slate-200">{{ euro(p.situazione.costi) }}</b> = Passività <b class="tabular-nums" :class="p.situazione.quadra ? 'text-emerald-700 dark:text-emerald-400' : 'text-rose-700 dark:text-rose-400'">{{ euro(p.situazione.passivo.totale) }}</b><template v-if="p.situazione.ricavi !== 0"> + Ricavi <b class="tabular-nums text-slate-800 dark:text-slate-200">{{ euro(p.situazione.ricavi) }}</b></template><template v-if="!p.situazione.quadra"> <span class="text-rose-700 dark:text-rose-400">— sbilancio {{ euro(p.situazione.sbilancio) }}</span></template></p>
                                <p class="mt-1.5 text-[12px] text-slate-600 dark:text-slate-400 leading-relaxed">È la stessa uguaglianza del Libro Giornale, dare = avere. I costi dell'esercizio stanno fra le attività finché non saranno conguagliati: sono spese sostenute per conto dei condòmini. Le quote emesse ai condòmini stanno fra le passività come debito della gestione; con la chiusura d'esercizio costi e quote si compenseranno e resterà il conguaglio.</p>
                            </div>
                        </div>

                        <!-- ═══ Le fatture aperte alla data: per l'esercizio aperto e per quello finito ═══ -->
                        <div id="fatture-aperte" class="rounded-md border bg-white dark:bg-slate-900 mb-6" data-test="fatture-aperte">
                            <div class="px-4 py-3 border-b bg-gray-50/50 dark:bg-slate-800/30 flex items-center justify-between">
                                <h3 class="font-semibold text-slate-800 dark:text-slate-200 flex items-center gap-2"><Receipt class="w-4 h-4 text-slate-500 dark:text-slate-400" /> Fatture aperte al {{ formatData(p.debiti.alla) }}</h3>
                                <Button as-child size="sm" variant="outline" class="h-7 px-2.5 text-[11px] font-semibold shrink-0"><Link :href="hrefFatture">Tutte le fatture <ArrowRight class="w-3 h-3" /></Link></Button>
                            </div>
                            <p class="px-4 pt-3 text-[12px] text-slate-500 dark:text-slate-400">Le fatture di ogni esercizio con la data entro il {{ formatData(p.debiti.alla) }} e non ancora pagate a quella data, con il residuo verso il fornitore (al netto della ritenuta). In ordine di scadenza.</p>
                            <div class="p-4 overflow-x-auto">
                                <table class="w-full text-sm min-w-[640px]">
                                    <thead>
                                        <tr class="border-b border-slate-200 dark:border-slate-800 text-[11px] uppercase tracking-wider text-slate-500 dark:text-slate-400">
                                            <th class="text-left font-bold py-2">Fornitore</th>
                                            <th class="text-left font-bold py-2">Documento</th>
                                            <th class="text-left font-bold py-2">Scadenza</th>
                                            <th class="text-right font-bold py-2 pr-3">Residuo</th>
                                            <th class="py-2 w-px"></th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <tr v-for="f in p.debiti.fatture" :key="f.id" class="border-b border-slate-100 dark:border-slate-800" data-test="fattura-aperta">
                                            <td class="py-2 pr-3 text-slate-700 dark:text-slate-300">{{ f.fornitore }}</td>
                                            <td class="py-2 pr-3 text-slate-500 dark:text-slate-400 tabular-nums whitespace-nowrap">{{ f.numero ?? '—' }} <span class="text-[11px]">del {{ formatData(f.data) }}</span>
                                                <span v-if="f.contestata" class="ml-1 text-[10px] uppercase text-amber-700 dark:text-amber-400">contestata</span><span v-if="f.pregressa" class="ml-1 text-[10px] uppercase text-slate-500 dark:text-slate-400">pregressa</span></td>
                                            <td class="py-2 pr-3 tabular-nums whitespace-nowrap" :class="scaduta(f) ? 'text-rose-700 dark:text-rose-400 font-semibold' : 'text-slate-500 dark:text-slate-400'">{{ formatData(f.scadenza) }}<span v-if="scaduta(f)" class="text-[10px] uppercase ml-1">scaduta</span></td>
                                            <td class="py-2 pr-3 text-right tabular-nums font-semibold whitespace-nowrap" :class="f.nota_credito ? 'text-emerald-700 dark:text-emerald-400' : 'text-slate-800 dark:text-slate-200'">{{ f.nota_credito ? '−' : '' }}{{ euro(f.residuo) }}</td>
                                            <td class="py-2 text-right whitespace-nowrap">
                                                <!-- Pulsanti veri, non link: sono azioni (Vincenzo, a video). La nota di credito non si paga, si usa in un pagamento. -->
                                                <Button v-if="!f.nota_credito && p.stato_esercizio !== 'chiuso'" as-child size="sm" variant="outline" class="h-7 px-2.5 text-[11px] font-semibold">
                                                    <Link :href="hrefPaga(f)">Paga <ArrowRight class="w-3 h-3" /></Link>
                                                </Button>
                                                <span v-else-if="f.nota_credito" class="inline-flex items-center rounded-md border border-emerald-200 dark:border-emerald-900 bg-emerald-50 dark:bg-emerald-900/20 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wider text-emerald-700 dark:text-emerald-400">nota di credito</span>
                                            </td>
                                        </tr>
                                        <tr v-if="p.debiti.fatture.length === 0"><td colspan="5" class="py-4 text-center text-slate-400 dark:text-slate-500 italic">Nessuna fattura aperta a questa data</td></tr>
                                    </tbody>
                                    <tfoot>
                                        <tr class="border-t-2 border-slate-300 dark:border-slate-700 font-bold">
                                            <td class="py-2 text-slate-800 dark:text-slate-200" colspan="3">Fatture aperte</td>
                                            <td class="py-2 pr-3 text-right tabular-nums text-slate-800 dark:text-slate-200">{{ euro(p.debiti.totale_residui) }}</td><td></td>
                                        </tr>
                                    </tfoot>
                                </table>
                                <p class="mt-2 text-[12px] text-slate-600 dark:text-slate-400" data-test="riga-debiti">
                                    Fatture aperte {{ euro(p.debiti.totale_residui) }}<span v-for="v in p.debiti.scarto_voci" :key="v.voce">{{ ` ${segnoVoce(v.importo)} ${v.voce}` }}{{ v.fatture.length ? ` (${v.fatture.join(', ')})` : '' }} {{ euro(Math.abs(v.importo)) }}</span>
                                    = debiti verso fornitori a giornale <b class="tabular-nums">{{ euro(p.debiti.saldo_fornitori) }}</b>.
                                </p>
                                <p v-if="p.debiti.saldo_erario !== 0" class="mt-1 text-[12px] text-slate-600 dark:text-slate-400 flex items-center gap-3">
                                    <span>Debiti verso l'Erario {{ euro(p.debiti.saldo_erario) }}<template v-if="p.debiti.erario_su_fatture_non_pagate !== 0">, di cui {{ euro(p.debiti.erario_su_fatture_non_pagate) }} di ritenute su fatture non ancora pagate: fino al pagamento sono un debito verso il fornitore</template>.</span>
                                    <Button as-child size="sm" variant="outline" class="h-7 px-2.5 text-[11px] font-semibold"><Link :href="hrefF24">F24 <ArrowRight class="w-3 h-3" /></Link></Button>
                                </p>
                                <p class="mt-1 text-[11px] text-slate-500 dark:text-slate-400">Contiene i debiti registrati come fatture: un debito che non è una fattura (un debito da una sentenza, il TFR del portiere, un debito verso l'amministratore di prima) va detto a parte all'assemblea, finché il rendiconto non ha la nota esplicativa (in arrivo), o registrato come fattura del creditore.</p>
                            </div>
                        </div>

                        <!-- ═══ Per gestione ═══ -->
                        <div class="rounded-md border bg-white dark:bg-slate-900 mb-6 overflow-x-auto" data-test="per-gestione">
                            <div class="px-4 py-3 border-b bg-gray-50/50 dark:bg-slate-800/30">
                                <h3 class="font-semibold text-slate-800 dark:text-slate-200">Per gestione</h3>
                                <p class="text-[11px] text-slate-500 dark:text-slate-400 mt-0.5">I costi seguono la gestione della voce; le quote, quella del piano; i debiti, quella della fattura. Costi e quote sono dell'esercizio; crediti e debiti sono alla data.</p>
                            </div>
                            <table class="w-full text-[13px] min-w-[640px]">
                                <thead>
                                    <tr class="bg-gray-50/50 dark:bg-slate-800/30 text-[11px] uppercase tracking-wider text-slate-500 dark:text-slate-400">
                                        <th class="text-left font-bold py-2 px-4">Gestione</th>
                                        <th class="text-right font-bold py-2 px-4">Costi dell'esercizio</th>
                                        <th class="text-right font-bold py-2 px-4">Quote emesse nell'esercizio</th>
                                        <th class="text-right font-bold py-2 px-4">Crediti verso i condòmini</th>
                                        <th class="text-right font-bold py-2 px-4">Debiti verso fornitori</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr v-for="r in p.per_gestione.righe" :key="r.gestione.id" class="border-b border-slate-100 dark:border-slate-800" :data-gestione="r.gestione.id">
                                        <td class="py-2 px-4 text-slate-700 dark:text-slate-300">{{ r.gestione.nome }} <span class="text-[11px] text-slate-400 dark:text-slate-500">· {{ r.gestione.tipo }}</span>
                                            <div v-if="r.cumulato_opera" class="text-[11px] text-slate-500 dark:text-slate-400">cumulato dell'opera<template v-if="r.cumulato_opera.dal"> dal {{ formatData(r.cumulato_opera.dal) }}</template>: {{ euro(r.cumulato_opera.consuntivo) }}</div></td>
                                        <td class="py-2 px-4 text-right tabular-nums">{{ euro(r.costi) }}</td>
                                        <td class="py-2 px-4 text-right tabular-nums">{{ euro(r.quote_emesse) }}</td>
                                        <td class="py-2 px-4 text-right tabular-nums">{{ euro(r.crediti) }}</td>
                                        <td class="py-2 px-4 text-right tabular-nums">{{ euro(r.debiti) }}</td>
                                    </tr>
                                    <tr v-if="senzaGestione" class="border-b border-slate-100 dark:border-slate-800 text-slate-500 dark:text-slate-400">
                                        <td class="py-2 px-4 text-[12px]">Senza una gestione: costi senza voce, scritture senza gestione<template v-if="p.per_gestione.differenza_debiti !== 0">, e la differenza dei debiti</template></td>
                                        <td class="py-2 px-4 text-right tabular-nums">{{ euro(p.per_gestione.senza_voce) }}</td>
                                        <td class="py-2 px-4 text-right tabular-nums">{{ euro(p.per_gestione.senza_gestione.quote_emesse) }}</td>
                                        <td class="py-2 px-4 text-right tabular-nums">{{ euro(p.per_gestione.senza_gestione.crediti) }}</td>
                                        <td class="py-2 px-4 text-right tabular-nums">{{ euro(p.per_gestione.senza_gestione.debiti + p.per_gestione.differenza_debiti) }}</td>
                                    </tr>
                                </tbody>
                                <tfoot>
                                    <tr class="border-t-2 border-slate-300 dark:border-slate-700 font-bold text-slate-800 dark:text-slate-200">
                                        <td class="py-2 px-4">Condominio</td>
                                        <td class="py-2 px-4 text-right tabular-nums">{{ euro(p.per_gestione.totale.costi) }}</td>
                                        <td class="py-2 px-4 text-right tabular-nums">{{ euro(p.per_gestione.totale.quote_emesse) }}</td>
                                        <td class="py-2 px-4 text-right tabular-nums">{{ euro(p.per_gestione.totale.crediti) }}</td>
                                        <td class="py-2 px-4 text-right tabular-nums">{{ euro(p.per_gestione.totale.debiti) }}</td>
                                    </tr>
                                </tfoot>
                            </table>
                            <p class="px-4 py-2 text-[11px] text-slate-500 dark:text-slate-400 border-t"><template v-if="p.per_gestione.nota_altra_gestione">{{ p.per_gestione.nota_altra_gestione }} </template>{{ p.per_gestione.nota_liquidita }}</p>
                        </div>

                        <!-- ═══ D11 — Liquidità libera e accantonata ════════════════════════════════ -->
                        <div class="rounded-md border bg-white dark:bg-slate-900 mb-6">
                            <div class="px-4 py-3 border-b bg-gray-50/50 dark:bg-slate-800/30">
                                <h3 class="font-semibold text-slate-800 dark:text-slate-200 flex items-center gap-2"><Landmark class="w-4 h-4 text-slate-500 dark:text-slate-400" /> Liquidità: libera e accantonata</h3>
                            </div>
                            <!-- items-start: la tabella non deve stirarsi all'altezza del riquadro accanto,
                                 o le righe si allargano e nome e importo sembrano disallineati. -->
                            <div class="p-4 grid grid-cols-1 lg:grid-cols-2 gap-6 items-start">
                                <table class="w-full text-[13px]">
                                    <tbody>
                                        <tr class="border-b border-slate-100 dark:border-slate-800">
                                            <td class="py-1.5 text-slate-700 dark:text-slate-300">Libera — banca e contanti</td>
                                            <td class="py-1.5 text-right tabular-nums whitespace-nowrap" :class="importo(p.liquidita.libera)">{{ euro(p.liquidita.libera) }}</td>
                                        </tr>
                                        <tr v-for="f in p.liquidita.fondi" :key="f.cassa" class="border-b border-slate-100 dark:border-slate-800">
                                            <td class="py-1.5 text-slate-700 dark:text-slate-300 align-middle">
                                                <div class="leading-snug">{{ f.cassa }} <span class="text-[11px] text-slate-400 dark:text-slate-500">· {{ f.sottotipo_label }}{{ f.vincolato ? ', vincolato' : ', liberamente utilizzabile' }}</span></div>
                                                <div v-if="f.vincolo_registrato !== 0" class="text-[11px] leading-snug" :class="f.scoperto > 0 ? 'text-rose-600 dark:text-rose-400' : 'text-slate-500 dark:text-slate-400'">vincolo assegnato {{ euro(f.vincolo_registrato) }}<template v-if="f.scoperto > 0"> — mancano {{ euro(f.scoperto) }}</template></div>
                                            </td>
                                            <td class="py-1.5 text-right tabular-nums whitespace-nowrap align-middle" :class="importo(f.saldo)">{{ euro(f.saldo) }}</td>
                                        </tr>
                                        <tr v-if="p.liquidita.fondi.length === 0" class="border-b border-slate-100 dark:border-slate-800"><td class="py-1.5 text-slate-400 dark:text-slate-500 italic">Nessun fondo</td><td></td></tr>
                                        <tr v-for="a in p.liquidita.altra_voci" :key="a.voce" class="border-b border-slate-100 dark:border-slate-800 bg-amber-50/60 dark:bg-amber-900/10">
                                            <td class="py-1.5 text-amber-800 dark:text-amber-300">{{ a.voce }}</td>
                                            <td class="py-1.5 text-right tabular-nums whitespace-nowrap text-amber-800 dark:text-amber-300">{{ euro(a.saldo) }}</td>
                                        </tr>
                                    </tbody>
                                    <tfoot>
                                        <tr class="border-t-2 border-slate-300 dark:border-slate-700">
                                            <td class="py-2 font-bold text-slate-800 dark:text-slate-200">Totale liquidità</td>
                                            <td class="py-2 text-right tabular-nums font-bold text-slate-800 dark:text-slate-200 whitespace-nowrap">{{ euro(p.liquidita.liquidita_totale) }}</td>
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
                                            <template v-else-if="p.liquidita.esito_r8 === 'segnalazione'">I vincoli dichiarati si leggono a oggi: questo confronto vale per l'esercizio aperto, non alla data di un esercizio finito; il residuo vincolato dei fondi si legge con il consuntivo dei fondi (in arrivo).</template>
                                            <template v-else-if="p.liquidita.vincolo_registrato === 0">Nessun contributo è dichiarato vincolato: i fondi tengono {{ euro(p.liquidita.in_fondi) }} senza vincoli da coprire.</template>
                                            <template v-else>Ogni euro dichiarato vincolato ha una cassa fondo che lo tiene.</template>
                                        </div>
                                    </div>
                                    <p v-if="p.liquidita.avanzo_registrato !== 0 || p.liquidita.gia_speso_acconto !== 0" class="text-slate-500 dark:text-slate-400">
                                        <template v-if="p.liquidita.avanzo_registrato !== 0">Avanzo dichiarato nei contributi versati, conguagliabile: {{ euro(p.liquidita.avanzo_registrato) }}. </template>
                                        <template v-if="p.liquidita.gia_speso_acconto !== 0">Già speso in acconto prima di KondoManager, quindi non più liquido: {{ euro(p.liquidita.gia_speso_acconto) }}.</template>
                                    </p>
                                    <p class="text-slate-500 dark:text-slate-400">Un fondo è una partizione dello stesso conto corrente: il totale è banca + contanti + fondi, una volta sola.</p>
                                </div>
                            </div>
                        </div>

                        <!-- ═══ Cosa questa pagina non fa ═══════════════════════════════════════════ -->
                        <div class="rounded-md border border-dashed bg-slate-50/50 dark:bg-slate-800/30 p-4 text-[12px] text-slate-600 dark:text-slate-400 leading-relaxed space-y-1.5">
                            <div class="text-[11px] uppercase tracking-wider text-slate-500 dark:text-slate-400 font-bold">Cosa questa pagina non fa ancora, e con quale modulo lo farà</div>
                            <p><b>Non elenca i condòmini che devono soldi</b> — solo il totale. Le posizioni di ognuno e i crediti per anzianità sono nella scheda <Link :href="hrefCondomini" class="underline">Condòmini</Link>; l'elenco di chi ha rate scadute, con le sue unità, è nella pagina <Link :href="hrefRateScadute" class="underline">Rate scadute</Link>: lì lo scaduto di ognuno è lordo, senza togliere il credito, e non è la voce «Crediti verso i condòmini», che è il saldo di tutte le quote emesse.</p>
                            <p><b>Non confronta con l'estratto conto della banca.</b> È un controllo tuo finché non ci sarà la riconciliazione bancaria, che diventerà un controllo di questa pagina.</p>
                            <p><b>Non chiude l'esercizio.</b> Quote e costi si accumulano dal primo esercizio: il cumulato da conguagliare è {{ euro(p.risultato_gestione.cumulato) }}. La fotografia al 31/12 di un esercizio chiuso diventerà definitiva con la chiusura guidata.</p>
                            <p><b>Non ha conti di ricavo</b>, per questo non dichiara mai i conti «in pari». <b>Non sceglie una data qualunque</b>: oggi per l'esercizio aperto, la data di fine per uno chiuso.</p>
                        </div>
                    </MovimentiLayout>
                </section>
            </div>
        </div>

    </GestionaleLayout>
</template>
