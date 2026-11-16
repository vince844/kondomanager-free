<script setup lang="ts">
/**
 * La scheda «Cassa» del rendiconto: le entrate e le uscite per cassa dell'esercizio, dal riporto dell'esercizio prima, e il raccordo fra cassa e
 * competenza. Ogni conto in banca chiude con il saldo della banca, con dentro i fondi che sono parte dello stesso conto, e sotto le righe «di cui».
 * Gli stessi numeri della situazione patrimoniale (`StatoPatrimonialePaginaService`, `FondiNelConto`): la pagina non somma niente. Sotto ogni conto con dei
 * fondi il gruppo «Come si divide il conto»: ogni fondo con il suo tipo in un badge, la parte libera con gli spostamenti verso i fondi, la riga «si può
 * usare» (`TipoDelFondo`). Il «Totale delle casse» è la somma delle righe delle casse, in grassetto.
 */
import { computed, ref } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import GestionaleLayout from '@/layouts/GestionaleLayout.vue';
import MovimentiLayout from '@/layouts/gestionale/MovimentiLayout.vue';
import PageHeaderGuide from '@/components/PageHeaderGuide.vue';
import RendicontoCassaGuide from '@/components/guides/RendicontoCassaGuide.vue';
import RendicontoSchede from '@/components/gestionale/movimenti/rendiconto/RendicontoSchede.vue';
import { usePermission } from '@/composables/permissions';
import { useCurrencyFormatter } from '@/composables/useCurrencyFormatter';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { Printer, Wallet, Landmark, Scale, Lock, LockOpen } from 'lucide-vue-next';
import type { Building } from '@/types/buildings';
import type { Esercizio } from '@/types/gestionale/esercizi';

/** Il tipo di un fondo: l'etichetta e il tono (si può usare, vincolato, sbloccato dall'assemblea, senza tipo). */
interface TipoFondo { testo: string; tono: 'libero' | 'vincolato' | 'sbloccato' | 'non_indicato' }
/** Una riga «di cui» di un conto: una partizione (un fondo, una cassa virtuale) o la parte libera. Un fondo ha il suo tipo e dice se si può usare. */
interface DiCui {
    cassa_id: number; cassa: string | null; tipo: string; conto_contabile_id: number; iniziale: number; entrate: number; uscite: number; finale: number;
    tipo_fondo?: TipoFondo; usabile?: boolean;
    /** Per la parte libera: il denaro andato nei fondi (fra le sue uscite) e quello tornato dai fondi (fra le sue entrate). */
    messi_nei_fondi?: number; tornati_dai_fondi?: number;
}
/** Una cassa reale con dentro le sue partizioni, o una partizione che non sta nel conto di una banca (`senza_banca`). */
interface Conto extends DiCui {
    negativa: boolean; minimo: number | null; minimo_il: string | null; senza_banca: boolean;
    /** Gli spostamenti fra la parte libera e i fondi: nelle righe «di cui», non nella riga del conto. */
    interni: number; di_cui: DiCui[];
    /** La parte libera sotto zero con il conto (i fondi dentro) sopra zero: un pagamento ha usato il denaro di un fondo. */
    libero_negativo?: boolean; libero_minimo?: number | null; libero_minimo_il?: string | null;
    /** Quanto si può usare alla fine dell'esercizio, per un conto con dei fondi: la parte libera e i fondi generici o sbloccati. */
    si_puo_usare?: number | null;
}
interface Cassa {
    data: string; stato_esercizio: 'futuro' | 'aperto' | 'chiuso';
    riepilogo: {
        dal: string; al: string; conti: Conto[]; fra_le_casse: number; totale: { iniziale: number; entrate: number; uscite: number; finale: number };
        stornate: { coppie: number; importo: number };
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
// Le colonne Entrate e Uscite: il verde e il rosso per una cifra che c'è; uno zero non è un'entrata né un'uscita, e sta nel grigio del testo secondario.
const SECONDARIO = 'text-slate-500 dark:text-slate-400';
const entrata = (v: number) => (v === 0 ? SECONDARIO : 'text-emerald-700 dark:text-emerald-400');
const uscita = (v: number) => (v === 0 ? SECONDARIO : 'text-rose-700 dark:text-rose-400');
// «si può usare»: l'etichetta e la cifra azzurre, come i collegamenti; una cifra sotto zero resta rossa.
const AZZURRO = 'text-blue-700 dark:text-blue-400';
const siPuoUsare = (v: number) => (v < 0 ? 'text-rose-600 dark:text-rose-400' : AZZURRO);
// Una cassa reale apre il registro di contabilità filtrato su quella cassa, con il ritorno qui; un fondo apre il suo mastrino.
const hrefRegistro = (r: Conto) => `${generatePath('gestionale/:condominio/esercizi/:esercizio/registro-contabilita', base)}?cassa=${r.cassa_id}&da=cassa`;
const hrefMastrino = (contoId: number) => generatePath('gestionale/:condominio/esercizi/:esercizio/conti/:contoContabile/movimenti', { ...base, contoContabile: contoId });
const stampa = () => window.open(`${route(generateRoute('gestionale.esercizi.stato-patrimoniale.print'), base)}?parte=cassa`, '_blank');

const senzaBanca = computed(() => c.value.riepilogo.conti.some((r) => r.senza_banca));
const liberoSottoZero = computed(() => c.value.riepilogo.conti.some((r) => r.libero_negativo));
const conSiPuoUsare = computed(() => c.value.riepilogo.conti.some((r) => r.si_puo_usare != null));

// Il badge del tipo di un fondo: i colori dei cartellini della pagina, con il lucchetto aperto per ciò che si può usare.
const TONI: Record<TipoFondo['tono'], string> = {
    libero: 'border-emerald-200 dark:border-emerald-900 bg-emerald-50 dark:bg-emerald-950/40 text-emerald-800 dark:text-emerald-200',
    vincolato: 'border-amber-200 dark:border-amber-900 bg-amber-50 dark:bg-amber-950/40 text-amber-800 dark:text-amber-200',
    sbloccato: 'border-sky-200 dark:border-sky-900 bg-sky-50 dark:bg-sky-950/40 text-sky-800 dark:text-sky-200',
    non_indicato: 'border-slate-200 dark:border-slate-700 bg-slate-50 dark:bg-slate-800/60 text-slate-700 dark:text-slate-200',
};
// Le righe del gruppo «Come si divide il conto»: il grigio delle righe secondarie, il testo più piccolo, un filetto a sinistra sulla prima cella. Al
// telefono ogni riga va a capo (il nome sopra, le cifre sotto con il nome della loro colonna), senza scorrere di lato.
const RIGA_DEL_GRUPPO = 'border-b border-slate-100 dark:border-slate-800 text-[12px] max-sm:block max-sm:py-1';
const GRIGIO = 'bg-gray-50/50 dark:bg-slate-800/30';
const GRUPPO = `${RIGA_DEL_GRUPPO} ${GRIGIO}`;
const FILETTO = 'border-l-2 border-l-slate-300 dark:border-l-slate-600';
const FILETTO_TELEFONO = 'max-sm:border-l-2 max-sm:border-l-slate-300 dark:max-sm:border-l-slate-600';
const CIFRA = 'py-1.5 px-4 text-right tabular-nums align-top sm:whitespace-nowrap max-sm:flex max-sm:justify-between max-sm:items-start max-sm:gap-3 max-sm:py-0.5 '
    + 'max-sm:before:content-[attr(data-label)] max-sm:before:text-left max-sm:before:font-normal max-sm:before:text-[11px] max-sm:before:text-slate-400';
const NOTA = 'block whitespace-normal text-[10px] font-normal text-slate-400 dark:text-slate-500';
const classeBadge = (t: TipoFondo) => `${TONI[t.tono] ?? TONI.non_indicato} max-w-full whitespace-normal text-left px-1.5 py-0 text-[10.5px] font-semibold leading-4`;

// La guida della scheda, che si apre di lato dal pulsante «Guida» dell'intestazione, come nelle altre pagine.
const mostraGuida = ref(false);
const headerBreadcrumbs = computed(() => [
    { title: 'Gestionale', href: generatePath('gestionale/:condominio', { condominio: props.condominio.id }) },
    { title: 'Movimenti' },
    { title: 'Rendiconto' },
    { title: 'Cassa' },
]);
const pageGuides = [
    { title: 'Per cassa', description: 'Il denaro entrato e uscito dalle casse con le scritture dell\'esercizio. La disponibilità iniziale è il riporto dell\'esercizio prima, con la stessa regola del registro di contabilità: ogni movimento si conta una volta, nell\'esercizio in cui è scritto.', icon: Wallet, colorVariant: 'blue' as const },
    { title: 'Ogni conto, con i suoi fondi', description: 'Un fondo è una parte dello stesso conto corrente: il conto chiude con il saldo della banca, e sotto, in «Come si divide il conto», le righe dicono quanto è in ogni fondo, quanto è libero e quanto si può usare; sono già nella riga del conto e non si sommano al totale. La banca, con i fondi dentro, o i contanti andati sotto zero nell\'esercizio, anche per un giorno, sono in rosso con il giorno e la cifra; la parte libera sotto zero, con il conto sopra zero, è segnata a parte.', icon: Landmark, colorVariant: 'amber' as const },
    { title: 'Il raccordo con la competenza', description: 'Perché nell\'esercizio la liquidità è cambiata: il risultato di gestione, più o meno le variazioni dei crediti verso i condòmini, dei debiti verso i fornitori e l\'Erario, degli anticipi e dei riporti. Se la somma non torna, la riga dice di quanto.', icon: Scale, colorVariant: 'emerald' as const },
];
</script>

<template>
    <Head title="Entrate e uscite per cassa" />
    <GestionaleLayout>
        <div class="px-6 py-8 space-y-3">
            <PageHeaderGuide
                has-text-guide
                text-guide-title="Guida"
                @open-text-guide="mostraGuida = true"
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

                        <div class="rounded-md border bg-white dark:bg-slate-900 mb-6" data-test="per-cassa">
                            <table class="w-full text-[13px] max-sm:block">
                                <thead class="max-sm:hidden">
                                    <tr class="bg-gray-50/50 dark:bg-slate-800/30 text-[11px] uppercase tracking-wider text-slate-500 dark:text-slate-400">
                                        <th class="text-left font-bold py-2 px-4">Cassa</th>
                                        <th class="text-right font-bold py-2 px-4">Disponibilità iniziale</th>
                                        <th class="text-right font-bold py-2 px-4">Entrate</th>
                                        <th class="text-right font-bold py-2 px-4">Uscite</th>
                                        <th class="text-right font-bold py-2 px-4">Disponibilità finale</th>
                                    </tr>
                                </thead>
                                <tbody class="max-sm:block">
                                    <template v-for="r in c.riepilogo.conti" :key="r.cassa_id + (r.senza_banca ? '-p' : '')">
                                        <tr class="border-b border-slate-100 dark:border-slate-800 max-sm:block max-sm:py-1.5" :class="{ 'bg-rose-50 dark:bg-rose-900/20': r.negativa }" :data-cassa="r.cassa_id" :data-senza-banca="r.senza_banca ? '' : undefined">
                                            <td class="py-2 px-4 text-slate-700 dark:text-slate-300 max-sm:block max-sm:pb-1">
                                                <Link v-if="!r.senza_banca" :href="hrefRegistro(r)" class="underline decoration-dotted decoration-slate-300 dark:decoration-slate-600 underline-offset-4 hover:text-primary font-semibold" title="Apri il registro di contabilità di questa cassa">{{ r.cassa }}</Link>
                                                <Link v-else :href="hrefMastrino(r.conto_contabile_id)" class="underline decoration-dotted decoration-slate-300 dark:decoration-slate-600 underline-offset-4 hover:text-primary font-semibold" title="Apri il mastrino del fondo">{{ r.cassa }}</Link>
                                                <span class="text-[11px] text-slate-400 dark:text-slate-500"> · {{ r.tipo }}</span>
                                                <div v-if="r.tipo_fondo" class="mt-1"><Badge variant="outline" :class="classeBadge(r.tipo_fondo)" data-test="tipo-fondo" :data-tono="r.tipo_fondo.tono"><component :is="r.usabile ? LockOpen : Lock" class="w-3 h-3 shrink-0" />{{ r.tipo_fondo.testo }}</Badge></div>
                                                <div v-if="r.senza_banca" class="text-[11px] text-slate-500 dark:text-slate-400">non è nel conto di una banca</div>
                                                <div v-if="r.negativa" class="text-[11px] text-rose-700 dark:text-rose-400 font-semibold">sotto zero: {{ euro(r.minimo ?? 0) }} il {{ formatData(r.minimo_il) }}</div>
                                            </td>
                                            <td :class="[CIFRA, 'py-2 font-semibold', importo(r.iniziale)]" data-label="Disponibilità iniziale">{{ euro(r.iniziale) }}</td>
                                            <td :class="[CIFRA, 'py-2 font-semibold', entrata(r.entrate)]" data-label="Entrate">{{ euro(r.entrate) }}</td>
                                            <td :class="[CIFRA, 'py-2 font-semibold', uscita(r.uscite)]" data-label="Uscite">{{ euro(r.uscite) }}</td>
                                            <td :class="[CIFRA, 'py-2 font-semibold', importo(r.finale)]" data-label="Disponibilità finale">{{ euro(r.finale) }}</td>
                                        </tr>
                                        <!-- Come si divide il conto: un dettaglio della riga sopra, che non si somma al totale. -->
                                        <template v-if="r.di_cui.length > 0">
                                            <tr :class="GRUPPO" data-test="come-si-divide" :data-gruppo="r.cassa_id">
                                                <td colspan="5" :class="[FILETTO, 'py-1 pl-6 pr-4 text-[11px] text-slate-500 dark:text-slate-400 max-sm:block']"><span class="font-semibold text-slate-600 dark:text-slate-300">Come si divide il conto</span> · le righe qui sotto sono già nella riga del conto, non si sommano al totale</td>
                                            </tr>
                                            <tr v-for="d in r.di_cui" :key="r.cassa_id + '-' + d.tipo + '-' + d.cassa_id" :class="[RIGA_DEL_GRUPPO, d.tipo === 'libero' && r.libero_negativo ? 'bg-amber-50 dark:bg-amber-900/20' : GRIGIO]" :data-di-cui="d.tipo === 'libero' ? 'libero' : d.cassa_id" :data-gruppo="r.cassa_id">
                                                <td :class="[FILETTO, 'py-1.5 pl-6 pr-4 text-slate-600 dark:text-slate-400 max-sm:block']">
                                                    <template v-if="d.tipo === 'libero'">libero</template>
                                                    <div v-else class="flex flex-col items-start gap-1 sm:flex-row sm:flex-wrap sm:items-center sm:gap-x-2">
                                                        <span>in <Link :href="hrefMastrino(d.conto_contabile_id)" class="underline decoration-dotted decoration-slate-300 dark:decoration-slate-600 underline-offset-4 hover:text-primary" title="Apri il mastrino del fondo">«{{ d.cassa }}»</Link></span>
                                                        <Badge v-if="d.tipo_fondo" variant="outline" :class="classeBadge(d.tipo_fondo)" data-test="tipo-fondo" :data-tono="d.tipo_fondo.tono"><component :is="d.usabile ? LockOpen : Lock" class="w-3 h-3 shrink-0" />{{ d.tipo_fondo.testo }}</Badge>
                                                    </div>
                                                    <div v-if="d.tipo === 'libero' && r.libero_negativo" class="text-[11px] text-amber-800 dark:text-amber-300 font-semibold" data-test="libero-sotto-zero">la parte libera sotto zero: {{ euro(r.libero_minimo ?? 0) }} il {{ formatData(r.libero_minimo_il ?? null) }}</div>
                                                </td>
                                                <td :class="[CIFRA, FILETTO_TELEFONO, importo(d.iniziale)]" data-label="Disponibilità iniziale">{{ euro(d.iniziale) }}</td>
                                                <td :class="[CIFRA, FILETTO_TELEFONO, entrata(d.entrate)]" data-label="Entrate">
                                                    <span>{{ euro(d.entrate) }} <span v-if="d.tipo === 'fondo'" :class="NOTA">messi nel fondo</span><span v-else-if="d.tipo === 'libero' && (d.tornati_dai_fondi ?? 0) > 0" :class="NOTA">di cui {{ euro(d.tornati_dai_fondi ?? 0) }} tornati dai fondi</span></span>
                                                </td>
                                                <td :class="[CIFRA, FILETTO_TELEFONO, uscita(d.uscite)]" data-label="Uscite">
                                                    <span>{{ euro(d.uscite) }} <span v-if="d.tipo === 'fondo'" :class="NOTA">usati</span><span v-else-if="d.tipo === 'libero' && (d.messi_nei_fondi ?? 0) > 0" :class="NOTA">di cui {{ euro(d.messi_nei_fondi ?? 0) }} messi nei fondi</span></span>
                                                </td>
                                                <td :class="[CIFRA, FILETTO_TELEFONO, importo(d.finale)]" data-label="Disponibilità finale">{{ euro(d.finale) }}</td>
                                            </tr>
                                            <tr v-if="r.si_puo_usare != null" :class="GRUPPO" data-test="si-puo-usare" :data-gruppo="r.cassa_id">
                                                <td :class="[FILETTO, 'py-1.5 pl-6 pr-4 text-slate-700 dark:text-slate-300 max-sm:block']">
                                                    <span :class="['font-semibold', AZZURRO]" data-test="etichetta-si-puo-usare">si può usare</span> <span class="block text-[11px] text-slate-500 dark:text-slate-400">la parte libera e i fondi generici o sbloccati</span>
                                                </td>
                                                <td class="py-1.5 px-4 max-sm:hidden"></td>
                                                <td class="py-1.5 px-4 max-sm:hidden"></td>
                                                <td class="py-1.5 px-4 max-sm:hidden"></td>
                                                <td :class="[CIFRA, FILETTO_TELEFONO, 'font-semibold', siPuoUsare(r.si_puo_usare)]" data-label="Disponibilità finale">{{ euro(r.si_puo_usare) }}</td>
                                            </tr>
                                        </template>
                                    </template>
                                    <tr v-if="c.riepilogo.conti.length === 0" class="max-sm:block"><td colspan="5" class="py-4 text-center text-slate-400 dark:text-slate-500 italic max-sm:block">Nessuna cassa</td></tr>
                                </tbody>
                                <tfoot class="max-sm:block">
                                    <tr class="border-t-2 border-slate-500 dark:border-slate-400 font-bold text-slate-800 dark:text-slate-200 max-sm:block max-sm:py-1.5" data-test="totale-delle-casse">
                                        <td class="py-2 px-4 max-sm:block max-sm:pb-1">Totale delle casse <span class="block text-[11px] font-normal text-slate-500 dark:text-slate-400">il denaro entrato e uscito dal condominio: la somma delle righe in grassetto<template v-if="c.riepilogo.fra_le_casse !== 0">, meno {{ euro(c.riepilogo.fra_le_casse) }} passati da una cassa all'altra</template></span></td>
                                        <td :class="[CIFRA, 'py-2']" data-label="Disponibilità iniziale">{{ euro(c.riepilogo.totale.iniziale) }}</td>
                                        <td :class="[CIFRA, 'py-2', entrata(c.riepilogo.totale.entrate)]" data-label="Entrate">{{ euro(c.riepilogo.totale.entrate) }}</td>
                                        <td :class="[CIFRA, 'py-2', uscita(c.riepilogo.totale.uscite)]" data-label="Uscite">{{ euro(c.riepilogo.totale.uscite) }}</td>
                                        <td :class="[CIFRA, 'py-2']" data-label="Disponibilità finale">{{ euro(c.riepilogo.totale.finale) }}</td>
                                    </tr>
                                </tfoot>
                            </table>
                            <p class="px-4 py-2 text-[11px] text-slate-500 dark:text-slate-400 border-t" data-test="frase-cassa">
                                <template v-if="conSiPuoUsare">La riga «si può usare» è la parte libera più i fondi generici e quelli sbloccati dall'assemblea: i fondi vincolati ai lavori, il TFR, la morosità e i fondi senza tipo restano fuori. </template>
                                <template v-if="c.riepilogo.stornate.coppie !== 0">{{ c.riepilogo.stornate.coppie === 1 ? 'Un movimento stornato' : c.riepilogo.stornate.coppie + ' movimenti stornati' }} nell'esercizio ({{ euro(c.riepilogo.stornate.importo) }}) non {{ c.riepilogo.stornate.coppie === 1 ? 'conta' : 'contano' }} né fra le entrate né fra le uscite: originale e storno si annullano, e restano leggibili nel registro di contabilità. </template>
                                <template v-if="liberoSottoZero">La parte libera di un conto sotto zero, con il conto e i suoi fondi sopra zero, vuol dire che un pagamento ha usato il denaro di un fondo: di solito manca il giroconto dal fondo al conto, e il controllo «Casse reali sotto zero» lo dice. </template>
                                <template v-if="senzaBanca">Un fondo con la sua riga non è nel conto di una banca: con più conti, il programma non sa in quale stia il denaro di un fondo aperto con un saldo suo, o passato fra fondi di banche diverse. </template>
                                La disponibilità iniziale è il riporto dell'esercizio prima: l'apertura di una cassa conta lì, non fra le entrate. Un conto apre il registro di contabilità di quella cassa; un fondo apre il suo mastrino.
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
        <RendicontoCassaGuide v-model:open="mostraGuida" />
    </GestionaleLayout>
</template>
