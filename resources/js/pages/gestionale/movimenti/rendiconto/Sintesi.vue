<script setup lang="ts">
/**
 * La sintesi del rendiconto dell'esercizio: lo stato, l'esito dei controlli con la riga fissa, i quattro riquadri, le fasce delle spese che non stanno in
 * nessun capitolo, il prospetto della gestione e il fascicolo per l'assemblea. Ogni numero arriva dal server (`SintesiDelRendiconto`): la pagina formatta
 * le date, gli importi e la percentuale, e non somma niente.
 */
import { computed, ref, watch } from 'vue';
import { Head, Link, usePage } from '@inertiajs/vue3';
import GestionaleLayout from '@/layouts/GestionaleLayout.vue';
import MovimentiLayout from '@/layouts/gestionale/MovimentiLayout.vue';
import PageHeaderGuide from '@/components/PageHeaderGuide.vue';
import Alert from '@/components/Alert.vue';
import RendicontoSchede from '@/components/gestionale/movimenti/rendiconto/RendicontoSchede.vue';
import { usePermission } from '@/composables/permissions';
import { useCurrencyFormatter } from '@/composables/useCurrencyFormatter';
import { Button } from '@/components/ui/button';
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover';
import { CheckCircle2, XCircle, AlertTriangle, FileDown, ListChecks, Scale, Landmark, Receipt, Users } from 'lucide-vue-next';
import type { Building } from '@/types/buildings';
import type { Esercizio } from '@/types/gestionale/esercizi';
import type { Flash } from '@/types/flash';

type Esito = 'quadra' | 'non_quadra' | 'segnalazione';
interface Sintesi {
    esercizio: { id: number; nome: string; dal: string; al: string };
    stato: { finito: boolean; alla: string; frase: string };
    esito: { tornano: number; non_tornano: number; da_leggere: number; frase: string; riga_fissa: string; righe: { id: string; nome: string; esito: Esito }[] };
    riquadri: {
        deliberato: { deliberato: number | null; preventivo: number | null; solo_ordinarie: boolean; ricostruito?: boolean };
        costi: { costi: number; per_capitolo: number; permille: number | null; base: 'deliberato' | 'preventivo' | null };
        quote: { pagate: number; emesse: number };
        liquidita: { liquidita: number; alla: string; frase: string };
    };
    /**
     * `da_assegnare`: le commissioni (o gli storni senza voce di registrazioni di un esercizio chiuso) a cui la voce si può dare, con la regola dell'elenco;
     * senza nessuna la fascia non invita ad assegnarla.
     */
    fasce: { tipo: 'commissioni' | 'storni' | 'contestate'; importo: number; numero: number; da_assegnare?: number; frase: string }[];
    prospetto: {
        preventivo: number | null; preventivo_solo_ordinarie?: boolean; deliberato: number | null; avvertenza_deliberato: string | null; avvertenza_deliberato_piani?: string[]; avvertenza_deliberato_ricostruito?: string | null; quote_piani: number; quote_pagate: number; quote_non_pagate: number; costi: number;
        quote_giornale: number; risultato: number; in_fondi: number;
        raccordo_quote: { quote_piani: number; quote_giornale: number; piani_emesse_altrove: number; altri_emesse_qui: number; altre_differenze: number };
    };
}

const props = defineProps<{ condominio: Building; condomini: Building[]; esercizio: Esercizio; esercizi: Esercizio[]; sintesi: Sintesi; parti: string[] }>();

const { generatePath, generateRoute } = usePermission();
/** Il messaggio che il server lascia in sessione: il fascicolo che non parte torna a questa pagina con la frase che dice quale parte togliere. */
const page = usePage<{ flash?: { message?: Flash } }>();
const flashMessage = computed(() => page.props.flash?.message);
const { euro } = useCurrencyFormatter();
const s = computed(() => props.sintesi);
const base = { condominio: props.condominio.id, esercizio: props.esercizio.id };

const formatData = (iso: string) => { const [a, m, g] = iso.slice(0, 10).split('-'); return `${g}/${m}/${a}`; };
const importo = (v: number | null) => (v === null ? 'nel verbale dell\'assemblea' : euro(v));
/** Il deliberato con le rate che il programma non ha: la frase sotto il prospetto dice perché. */
const deliberatoTesto = (v: number | null) => (v === null ? 'non disponibile' : euro(v));
/** La percentuale dai millesimi, con il segno a parte: −5 ‰ è «-0,5 %», non «0,5 %». */
const percentuale = (permille: number | null) => (permille === null ? null : `${permille < 0 ? '-' : ''}${Math.trunc(Math.abs(permille) / 10)},${Math.abs(permille) % 10} %`);
/** Il risultato a parole: zero è in pareggio, né avanzo né disavanzo. */
const comeRisultato = (r: number) => (r === 0 ? 'in pareggio' : (r < 0 ? 'disavanzo' : 'avanzo'));

const coloreEsito = (e: Esito) => ({ quadra: 'text-emerald-700 dark:text-emerald-400', non_quadra: 'text-rose-700 dark:text-rose-400', segnalazione: 'text-amber-700 dark:text-amber-400' }[e]);
const iconaEsito = (e: Esito) => ({ quadra: CheckCircle2, non_quadra: XCircle, segnalazione: AlertTriangle }[e]);
const testoEsito = (e: Esito) => ({ quadra: 'torna', non_quadra: 'non torna', segnalazione: 'da leggere' }[e]);
const hrefControllo = (id: string) => `${generatePath('gestionale/:condominio/esercizi/:esercizio/rendiconto/controlli', base)}#controllo-${id}`;
const hrefCommissioni = generatePath('gestionale/:condominio/esercizi/:esercizio/commissioni-senza-voce', base);
const hrefRiepilogo = generatePath('gestionale/:condominio/esercizi/:esercizio/rendiconto/riepilogo-finanziario', base);

/* ─── Il fascicolo: le parti a spunta, l'ordine resta quello fisso; la scelta si ricorda in questo browser, per chi lavora ─── */
const nomiParti: Record<string, string> = {
    riepilogo: 'Riepilogo finanziario', situazione: 'Situazione patrimoniale', cassa: 'Entrate e uscite per cassa',
    condomini: 'Condòmini: posizioni e crediti per anzianità', registro: 'Registro di contabilità', controlli: 'Controlli',
};
const utente = (usePage().props as { auth?: { user?: { id?: number } } }).auth?.user?.id ?? 0;
const chiaveScelta = `rendiconto-fascicolo-${utente}`;
const leggiScelta = (): { parti: string[]; dettaglio: boolean } | null => {
    try { const v = JSON.parse(window.localStorage.getItem(chiaveScelta) ?? 'null'); return v && Array.isArray(v.parti) ? v : null; } catch { return null; }
};
const ricordata = leggiScelta();
const scelte = ref<string[]>((ricordata?.parti ?? props.parti).filter((p) => props.parti.includes(p)));
const dettaglio = ref<boolean>(ricordata?.dettaglio ?? false);
watch([scelte, dettaglio], () => {
    try { window.localStorage.setItem(chiaveScelta, JSON.stringify({ parti: scelte.value, dettaglio: dettaglio.value })); } catch { /* il browser non la tiene: pazienza */ }
}, { deep: true });
const cambia = (p: string, on: boolean) => { scelte.value = on ? [...scelte.value, p] : scelte.value.filter((x) => x !== p); };
const hrefFascicolo = computed(() => {
    const q = new URLSearchParams();
    props.parti.filter((p) => scelte.value.includes(p)).forEach((p) => q.append('parti[]', p));
    if (dettaglio.value) q.set('dettaglio', '1');
    return `${route(generateRoute('gestionale.esercizi.rendiconto.fascicolo'), base)}?${q.toString()}`;
});
/** Il fascicolo con molti condòmini può volerci un minuto: intanto il pulsante non lo chiede una seconda volta. */
const inPreparazione = ref(false);
const scaricaFascicolo = () => {
    if (inPreparazione.value) return;
    inPreparazione.value = true;
    window.open(hrefFascicolo.value, '_blank');
    window.setTimeout(() => { inPreparazione.value = false; }, 60_000);
};

const headerBreadcrumbs = computed(() => [
    { title: 'Gestionale', href: generatePath('gestionale/:condominio', { condominio: props.condominio.id }) },
    { title: 'Movimenti' },
    { title: 'Rendiconto' },
]);

const pageGuides = [
    { title: 'Il rendiconto in una pagina', description: 'In alto l\'esito dei controlli, poi quattro numeri e il prospetto della gestione. Ogni numero viene da una scheda: il riepilogo finanziario, la situazione patrimoniale, i controlli. Con più gestioni qui sono sommate.', icon: Scale, colorVariant: 'blue' as const },
    { title: 'I controlli, con i numeri veri', description: 'Dicono se i numeri del programma tornano fra loro. Non dicono che il saldo della banca è quello dell\'estratto conto, né che ogni fattura è stata registrata: quello resta un controllo tuo.', icon: ListChecks, colorVariant: 'amber' as const },
];
</script>

<template>
    <Head title="Rendiconto" />
    <GestionaleLayout>
        <div class="px-6 py-8 space-y-3">
            <PageHeaderGuide
                page-title="Rendiconto"
                :page-subtitle="`${s.esercizio.nome} · ${s.stato.frase}`"
                :guides="pageGuides"
                :breadcrumbs="(headerBreadcrumbs as any)"
                :condominio="(props.condominio as any)"
                :condomini="(props.condomini as any)"
                :esercizio="(props.esercizio as any)"
                :esercizi="(props.esercizi as any)"
            >
                <template #actions>
                    <Popover>
                        <PopoverTrigger as-child>
                            <Button variant="outline" class="h-8 px-3 gap-2" data-test="apri-fascicolo"><FileDown class="w-4 h-4" /> Scarica il fascicolo</Button>
                        </PopoverTrigger>
                        <PopoverContent align="end" class="w-80 text-[12px] space-y-2">
                            <div class="font-semibold text-slate-800 dark:text-slate-200">Le parti del fascicolo</div>
                            <label v-for="p in props.parti" :key="p" class="flex items-center gap-2">
                                <input type="checkbox" :checked="scelte.includes(p)" :data-parte="p" @change="cambia(p, ($event.target as HTMLInputElement).checked)" />
                                {{ nomiParti[p] ?? p }}
                            </label>
                            <label class="flex items-center gap-2 pt-1 border-t">
                                <input v-model="dettaglio" type="checkbox" data-test="dettaglio" /> Il riepilogo con il dettaglio per spesa
                            </label>
                            <p class="text-[11px] text-slate-500 dark:text-slate-400">Le parti escono sempre in quest'ordine, con un frontespizio che ha l'esito dei controlli e l'indice. Ognuna si stampa anche da sola dalla sua scheda.</p>
                            <p class="text-[11px] text-slate-500 dark:text-slate-400">La scelta resta su questo computer.</p>
                            <Button size="sm" class="w-full" :disabled="scelte.length === 0 || inPreparazione" data-test="scarica-fascicolo" @click="scaricaFascicolo">Scarica il fascicolo</Button>
                            <p v-if="inPreparazione" class="text-[11px] text-amber-700 dark:text-amber-400" data-test="fascicolo-in-preparazione">Il fascicolo si apre nell'altra scheda; con molti condòmini può volerci più di un minuto. Se è troppo grande per il server, lì trovi quale parte togliere.</p>
                        </PopoverContent>
                    </Popover>
                </template>
            </PageHeaderGuide>

            <div class="w-full">
                <section class="w-full space-y-4">
                    <MovimentiLayout>
                        <RendicontoSchede :condominio-id="props.condominio.id" :esercizio-id="props.esercizio.id" attiva="sintesi" />

                        <div v-if="flashMessage" class="mb-3" data-test="avviso">
                            <Alert :message="flashMessage.message" :type="flashMessage.type" />
                        </div>

                        <!-- L'esito dei controlli, prima di tutto: ogni riga porta al suo controllo. -->
                        <div class="rounded-md border bg-white dark:bg-slate-900 mb-6" data-test="esito">
                            <div class="px-4 py-3 border-b bg-gray-50/50 dark:bg-slate-800/30 flex flex-wrap items-center justify-between gap-2">
                                <h3 class="font-semibold text-slate-800 dark:text-slate-200 flex items-center gap-2"><ListChecks class="w-4 h-4 text-slate-500 dark:text-slate-400" /> I controlli: {{ s.esito.frase }}</h3>
                            </div>
                            <ul class="px-4 py-2 grid grid-cols-1 md:grid-cols-2 gap-x-6">
                                <li v-for="r in s.esito.righe" :key="r.id" class="py-1 text-[12.5px] flex items-center justify-between gap-2" :data-controllo="r.id">
                                    <Link :href="hrefControllo(r.id)" class="text-slate-700 dark:text-slate-300 hover:underline">{{ r.nome }}</Link>
                                    <span class="inline-flex items-center gap-1 font-semibold text-[11px] whitespace-nowrap" :class="coloreEsito(r.esito)"><component :is="iconaEsito(r.esito)" class="w-3.5 h-3.5" /> {{ testoEsito(r.esito) }}</span>
                                </li>
                            </ul>
                            <p class="px-4 pb-3 text-[11.5px] text-slate-500 dark:text-slate-400" data-test="riga-fissa">{{ s.esito.riga_fissa }}</p>
                        </div>

                        <!-- I quattro riquadri -->
                        <div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-4 mb-6">
                            <div class="border rounded-xl p-4 shadow-sm bg-slate-50/50 dark:bg-slate-800/30 border-slate-200 dark:border-slate-800" data-test="riquadro-deliberato">
                                <div class="text-[11px] uppercase tracking-wider text-slate-500 dark:text-slate-400 font-bold flex items-center gap-1.5"><Scale class="w-3.5 h-3.5" /> Deliberato con le rate</div>
                                <div class="text-lg font-bold tabular-nums">{{ deliberatoTesto(s.riquadri.deliberato.deliberato) }}<span v-if="s.riquadri.deliberato.ricostruito && s.riquadri.deliberato.deliberato !== null" class="ml-2 text-[12px] font-semibold text-slate-600 dark:text-slate-400" data-test="riquadro-deliberato-ricostruito">ricostruito</span></div>
                                <div class="text-[11px] text-slate-500 dark:text-slate-400">preventivo{{ s.riquadri.deliberato.solo_ordinarie ? ' delle gestioni ordinarie' : '' }} {{ importo(s.riquadri.deliberato.preventivo) }}</div>
                            </div>
                            <div class="border rounded-xl p-4 shadow-sm bg-rose-50/40 dark:bg-rose-900/10 border-rose-200 dark:border-rose-900" data-test="riquadro-costi">
                                <div class="text-[11px] uppercase tracking-wider text-slate-500 dark:text-slate-400 font-bold flex items-center gap-1.5"><Receipt class="w-3.5 h-3.5" /> Costi dell'esercizio</div>
                                <div class="text-lg font-bold tabular-nums">{{ euro(s.riquadri.costi.costi) }}<span v-if="percentuale(s.riquadri.costi.permille)" class="ml-2 text-[12px] font-semibold text-slate-600 dark:text-slate-400">{{ percentuale(s.riquadri.costi.permille) }}</span></div>
                                <div v-if="s.riquadri.costi.base" class="text-[11px] text-slate-500 dark:text-slate-400">la percentuale è sul {{ s.riquadri.costi.base === 'deliberato' ? 'deliberato con le rate' : 'preventivo' }}</div>
                                <div class="text-[11px] text-slate-500 dark:text-slate-400">di cui per capitolo {{ euro(s.riquadri.costi.per_capitolo) }}</div>
                            </div>
                            <div class="border rounded-xl p-4 shadow-sm bg-sky-50/50 dark:bg-sky-900/20 border-sky-200 dark:border-sky-900" data-test="riquadro-quote">
                                <div class="text-[11px] uppercase tracking-wider text-slate-500 dark:text-slate-400 font-bold flex items-center gap-1.5"><Users class="w-3.5 h-3.5" /> Quote pagate</div>
                                <div class="text-lg font-bold tabular-nums">{{ euro(s.riquadri.quote.pagate) }}</div>
                                <div class="text-[11px] text-slate-500 dark:text-slate-400">su {{ euro(s.riquadri.quote.emesse) }} emesse</div>
                            </div>
                            <div class="border rounded-xl p-4 shadow-sm bg-emerald-50/40 dark:bg-emerald-900/10 border-emerald-200 dark:border-emerald-900" data-test="riquadro-liquidita">
                                <div class="text-[11px] uppercase tracking-wider text-slate-500 dark:text-slate-400 font-bold flex items-center gap-1.5"><Landmark class="w-3.5 h-3.5" /> Liquidità al {{ formatData(s.riquadri.liquidita.alla) }}</div>
                                <div class="text-lg font-bold tabular-nums">{{ euro(s.riquadri.liquidita.liquidita) }}</div>
                                <div class="text-[11px] text-slate-500 dark:text-slate-400">{{ s.riquadri.liquidita.frase }}</div>
                            </div>
                        </div>

                        <!-- Le fasce: le spese che non stanno in nessun capitolo -->
                        <div v-for="f in s.fasce" :key="f.tipo" class="mb-3 rounded-lg border p-3 text-[12.5px] flex flex-wrap items-center justify-between gap-2"
                            :class="f.tipo !== 'contestate' ? 'bg-amber-50 dark:bg-amber-900/20 border-amber-200 dark:border-amber-900 text-amber-900 dark:text-amber-200' : 'bg-slate-50 dark:bg-slate-800/50 border-slate-200 dark:border-slate-800 text-slate-800 dark:text-slate-200'" :data-fascia="f.tipo">
                            <span>{{ f.frase }}</span>
                            <Link v-if="f.tipo !== 'contestate'" :href="hrefCommissioni" class="font-semibold underline whitespace-nowrap">{{ (f.da_assegnare ?? 0) > 0 ? 'Assegna la voce' : 'Vedi l\'elenco' }}</Link>
                            <Link v-else :href="hrefRiepilogo" class="font-semibold underline whitespace-nowrap">Apri il riepilogo</Link>
                        </div>

                        <!-- Il prospetto della gestione -->
                        <div class="rounded-md border bg-white dark:bg-slate-900 mb-6" data-test="prospetto">
                            <div class="px-4 py-3 border-b bg-gray-50/50 dark:bg-slate-800/30"><h3 class="font-semibold text-slate-800 dark:text-slate-200">Il prospetto della gestione</h3></div>
                            <table class="w-full text-[13px]">
                                <tbody>
                                    <tr class="border-b border-slate-100 dark:border-slate-800"><td class="py-2 px-4">Preventivo{{ s.prospetto.preventivo_solo_ordinarie ? ' delle gestioni ordinarie' : '' }}</td><td class="py-2 px-4 text-right tabular-nums">{{ importo(s.prospetto.preventivo) }}</td></tr>
                                    <tr class="border-b border-slate-100 dark:border-slate-800"><td class="py-2 px-4">Deliberato con le rate</td><td class="py-2 px-4 text-right tabular-nums">{{ deliberatoTesto(s.prospetto.deliberato) }}</td></tr>
                                    <tr class="border-b border-slate-100 dark:border-slate-800"><td class="py-2 px-4">Quote dei piani dell'esercizio</td><td class="py-2 px-4 text-right tabular-nums">{{ euro(s.prospetto.quote_piani) }}</td></tr>
                                    <tr class="border-b border-slate-100 dark:border-slate-800"><td class="py-2 px-4">Quote pagate</td><td class="py-2 px-4 text-right tabular-nums">{{ euro(s.prospetto.quote_pagate) }}</td></tr>
                                    <tr class="border-b border-slate-100 dark:border-slate-800"><td class="py-2 px-4">Costi dell'esercizio</td><td class="py-2 px-4 text-right tabular-nums">{{ euro(s.prospetto.costi) }}</td></tr>
                                    <tr class="font-semibold" data-test="risultato">
                                        <td class="py-2 px-4">Risultato: quote emesse a giornale {{ euro(s.prospetto.quote_giornale) }} − costi {{ euro(s.prospetto.costi) }}</td>
                                        <td class="py-2 px-4 text-right tabular-nums" :class="s.prospetto.risultato < 0 ? 'text-rose-700 dark:text-rose-400' : 'text-emerald-700 dark:text-emerald-400'">{{ euro(s.prospetto.risultato) }} ({{ comeRisultato(s.prospetto.risultato) }})</td>
                                    </tr>
                                </tbody>
                            </table>
                            <p v-if="s.prospetto.avvertenza_deliberato" class="px-4 py-2 text-[11.5px] text-slate-500 dark:text-slate-400 border-t" data-test="deliberato-non-disponibile">{{ s.prospetto.avvertenza_deliberato }}</p>
                            <p v-for="(frase, i) in s.prospetto.avvertenza_deliberato_piani ?? []" :key="`piano-${i}`" class="px-4 pb-2 text-[11.5px] text-slate-500 dark:text-slate-400" data-test="deliberato-piani">{{ frase }}</p>
                            <p v-if="s.prospetto.avvertenza_deliberato_ricostruito" class="px-4 py-2 text-[11.5px] text-slate-500 dark:text-slate-400 border-t" data-test="deliberato-ricostruito">{{ s.prospetto.avvertenza_deliberato_ricostruito }}</p>
                            <p v-if="s.prospetto.quote_piani !== s.prospetto.quote_giornale" class="px-4 py-2 text-[11.5px] text-slate-500 dark:text-slate-400 border-t" data-test="raccordo-quote">
                                Quote dei piani dell'esercizio {{ euro(s.prospetto.raccordo_quote.quote_piani) }} = quote a giornale {{ euro(s.prospetto.raccordo_quote.quote_giornale) }}
                                + rate di questi piani emesse in un altro esercizio {{ euro(s.prospetto.raccordo_quote.piani_emesse_altrove) }}
                                − rate di altri piani emesse in questo {{ euro(s.prospetto.raccordo_quote.altri_emesse_qui) }}<template v-if="s.prospetto.raccordo_quote.altre_differenze"> + {{ euro(s.prospetto.raccordo_quote.altre_differenze) }} di debiti riportati dentro le rate</template>.
                            </p>
                        </div>
                    </MovimentiLayout>
                </section>
            </div>
        </div>
    </GestionaleLayout>
</template>
