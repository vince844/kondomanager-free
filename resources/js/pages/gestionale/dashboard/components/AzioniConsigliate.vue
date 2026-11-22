<!--
SPDX-License-Identifier: AGPL-3.0-or-later
Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
-->
<script setup lang="ts">
/**
 * Il cassetto «Tesoreria: che cosa puoi fare», che si apre dalla card «Saldo previsto», a destra sopra la pagina. Dall'alto:
 *
 * - la situazione in una frase, calcolata: quando e di quanto il saldo va sotto zero e perché, che cosa entra e quando, le rate già scadute;
 * - il grafico con tre linee (la simulazione piena in indaco con il rosso sotto zero, «Se pagano tutti» e «Senza le rate» tratteggiate), la legenda, i fatti
 *   scritti sul grafico;
 * - la simulazione: il cursore da niente a tutte le rate già scadute, con la tacca di «quanto basta» (un clic porta lì il cursore), e le tre cifre che lo
 *   seguono: il punto più basso, i giorni sotto zero, la cifra fra 30 giorni;
 * - le azioni consigliate, ognuna con i suoi numeri e il pulsante giusto, nell'ordine: le ritenute («Prepara l'F24», la delega della scadenza quando ce n'è
 *   una sola, se no l'elenco), i bonifici segnalati («Verifica i bonifici», l'Action inbox del condominio su quel tipo), le rate da emettere («Emetti la rata»
 *   apre la finestra «Emetti le rate»), il recupero (le persone in una tabella sotto la frase, al telefono una card per persona; «Manda un sollecito» apre la
 *   lettera sulla persona o sulle persone; sotto, a contorno, «Registra gli incassi», con la riga che dice di registrare prima i versamenti già arrivati), le
 *   fatture («Apri le fatture»), il giroconto («Fai un giroconto», con i due conti e la cifra già scelti), il fondo («Registra lo sblocco», la pagina del
 *   fondo), il piano straordinario («Prepara un piano straordinario»); la riga sulle scadenze delle rate, che approva l'assemblea. Con «Scelgo io» nel
 *   condominio le azioni non ci sono.
 *
 * Le regole stanno in `resources/js/lib/gestionale/tesoreria/azioniConsigliate.ts`. Niente parte da solo: ogni pulsante apre una pagina, la finestra delle
 * lettere o quella dell'emissione, dove l'amministratore decide. Dopo un'emissione dalla sua finestra l'esito si legge qui, in testa alle azioni, finché il
 * cassetto resta aperto: la tesoreria si è già aggiornata, e l'azione delle rate da emettere non c'è più se il piano non ne ha altre.
 */
import { computed, defineAsyncComponent, reactive, ref, watch } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import { ArrowRight, ChevronDown, Info, Sparkles, X } from 'lucide-vue-next';
import { Sheet, SheetClose, SheetContent, SheetDescription, SheetHeader, SheetTitle } from '@/components/ui/sheet';
import LettereDialog from '@/components/gestionale/lettere/LettereDialog.vue';
import EmissioneRapidaDialog from './EmissioneRapidaDialog.vue';
import { usePermission } from '@/composables/permissions';
import { Permission } from '@/enums/Permission';
import { euroSegno, type SaldoPrevisto } from '@/lib/gestionale/tesoreria/saldoPrevisto';
import {
    RIGA_DELLE_SCADENZE, azioniConsigliate, cifreDellaSimulazione, descrizioneDelGraficoGrande, domandaDelRecupero, percentuale, quantoBasta, simulazione, situazione,
    tacca, testoDelleAltre, testoGiorniSottoZero, type AzioneConsigliata, type DatiDelleAzioni, type Recupero,
} from '@/lib/gestionale/tesoreria/azioniConsigliate';

const GraficoTreLinee = defineAsyncComponent(() => import('./GraficoTreLinee.vue'));

const props = defineProps<{
    open: boolean;
    saldoPrevisto: SaldoPrevisto;
    recupero: Recupero;
    /** La scelta del condominio «Il programma suggerisce la prossima azione»: spenta, niente azioni consigliate. */
    suggerisce: boolean;
    condominioId: number;
    oggi: string;
    /** I fatti delle altre azioni (le ritenute, i bonifici, le rate da emettere, i conti, i fondi, il deficit): senza, solo il recupero e le fatture. */
    datiDelleAzioni?: DatiDelleAzioni | null;
}>();
const emit = defineEmits<{ (e: 'update:open', aperto: boolean): void }>();

const { generateRoute, hasPermission } = usePermission();
/** Un indirizzo del gestionale del condominio; null se la rotta non c'è (il pulsante non porta in un vicolo cieco). */
const rotta = (nome: string, altro: Record<string, unknown> = {}) => {
    try {
        return route(generateRoute(`gestionale.${nome}`), { condominio: props.condominioId, ...altro });
    } catch {
        return null;
    }
};

const pezziDellaSituazione = computed(() => situazione(props.saldoPrevisto, props.recupero));
const TONI = { rosso: 'font-bold text-red-600 dark:text-red-400', forte: 'font-bold text-slate-900 dark:text-white' } as const;

// La simulazione: si parte da niente, ogni volta che il cassetto si apre.
const recuperato = ref(0);
// Le azioni con le righe aperte («Mostra le rate»).
const aperti = reactive(new Set<number>());
// L'esito di un'emissione, in testa alle azioni: vale finché il cassetto resta aperto.
const esitoEmissione = ref<{ titolo: string; testo: string } | null>(null);
const capoversiDellEsito = computed(() => (esitoEmissione.value?.testo ?? '').split(/\n\s*\n/).filter((c) => c.trim() !== ''));
watch(() => props.open, (aperto) => {
    if (aperto) {
        recuperato.value = 0;
        esitoEmissione.value = null;
    }
});
const basta = computed(() => quantoBasta(props.saldoPrevisto));
const laTacca = computed(() => tacca(basta.value, props.recupero.cifra));
const cifre = computed(() => cifreDellaSimulazione(simulazione(props.saldoPrevisto, recuperato.value)));
const coloreCifra = (c: number) => (c < 0 ? 'text-red-600 dark:text-red-400' : c === 0 ? 'text-slate-500 dark:text-slate-400' : 'text-slate-900 dark:text-white');
const portaAQuantoBasta = () => {
    recuperato.value = Math.min(basta.value, props.recupero.cifra);
};

const azioni = computed(() => (props.suggerisce ? azioniConsigliate(props.saldoPrevisto, props.recupero, props.datiDelleAzioni ?? null) : []));
const PULSANTE_A_CONTORNO = 'inline-flex h-9 w-full items-center justify-center gap-1.5 whitespace-nowrap rounded-md border border-slate-200 bg-white px-3 text-xs font-bold text-slate-800 hover:bg-slate-50 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-100 dark:hover:bg-slate-800';
/** Dove porta il pulsante di un'azione: la pagina dove la cosa si fa, con quello che serve già scelto. */
const indirizzo = (a: AzioneConsigliata) => {
    const p = a.pulsante;
    switch (p.tipo) {
        case 'rate_scadute': return rotta('rate-scadute.index');
        case 'fattura': return rotta('fatture.show', { fattura: p.fatture?.[0] });
        case 'fatture': return rotta('fatture.index', { stato_pagamento: 'aperta' });
        // La delega della scadenza, quando il server ne conosce una sola per quel giorno; altrimenti l'elenco, dove le deleghe si generano.
        case 'f24': return p.delega ? rotta('f24.show', { delega: p.delega }) : rotta('f24.index');
        // Il giroconto con i due conti e la cifra già scelti: si registra solo con «Registra giroconto», dopo la causale.
        case 'giroconto': return rotta('giroconti.create', { origine: p.origine, destinazione: p.destinazione, importo: p.importo });
        case 'fondo': return rotta('casse.edit', { cassa: p.cassaId });
        case 'piano_straordinario': return p.esercizioId ? rotta('esercizi.piani-rate.create', { esercizio: p.esercizioId, tipo: 'straordinario' }) : null;
        case 'inbox':
            // L'Action inbox chiede «Visualizza scadenze in agenda»: senza, l'azione resta (dice il fatto) ma senza un pulsante verso una pagina chiusa.
            if (!hasPermission([Permission.VIEW_EVENTS])) return null;
            try {
                return route('admin.inbox', { condominio_id: props.condominioId, tipo: 'verifica_pagamento' });
            } catch {
                return null;
            }
        default: return null;
    }
};

// La lettera: sulla persona, o sulle persone spuntate, con il sollecito proposto (si cambia nella finestra).
const lettera = ref<{ aperta: boolean; persona: { id: number; nome: string } | null; selezione: { tutte: boolean; ids: number[] } | null }>({ aperta: false, persona: null, selezione: null });
function scrivi(a: AzioneConsigliata) {
    const persone = a.pulsante.persone ?? [];
    lettera.value = persone.length === 1
        ? { aperta: true, persona: persone[0], selezione: null }
        : { aperta: true, persona: null, selezione: { tutte: false, ids: persone.map((p) => p.id) } };
}
const ricarica = () => router.reload({ only: ['widgets', 'seiMesi', 'compitiSeiMesi'] });

// La finestra «Emetti le rate», sul piano dell'azione.
const emissione = ref<{ aperta: boolean; pianoId: number | null }>({ aperta: false, pianoId: null });
const apriEmissione = (a: AzioneConsigliata) => {
    emissione.value = { aperta: true, pianoId: a.pulsante.pianoId ?? null };
};
</script>

<template>
    <Sheet :open="open" @update:open="emit('update:open', $event)">
        <!-- Il pulsante di serie del cassetto (la sola «X») lascia il posto a «Chiudi» scritto per intero. -->
        <SheetContent class="w-full gap-0 overflow-y-auto p-0 sm:max-w-[820px] [&>button:last-child]:hidden" data-testid="cassetto-azioni">
            <SheetHeader class="space-y-0 border-b border-slate-100 px-5 py-5 text-left dark:border-slate-800 sm:px-7">
                <div class="flex items-start justify-between gap-3">
                    <div class="flex min-w-0 items-center gap-2">
                        <div class="shrink-0 rounded-lg bg-indigo-100 p-1.5 text-indigo-700 dark:bg-indigo-900/40 dark:text-indigo-300"><Sparkles class="h-4 w-4" /></div>
                        <SheetTitle class="text-lg font-bold text-slate-900 dark:text-white">Tesoreria: che cosa puoi fare</SheetTitle>
                    </div>
                    <SheetClose data-testid="chiudi-cassetto"
                        class="inline-flex shrink-0 items-center gap-1 rounded-md px-2 py-1 text-xs font-bold text-slate-500 hover:bg-slate-100 hover:text-slate-800 dark:text-slate-400 dark:hover:bg-slate-800 dark:hover:text-slate-200">
                        <X class="h-4 w-4" /> Chiudi
                    </SheetClose>
                </div>
                <SheetDescription class="mt-2 max-w-[640px] text-sm text-slate-600 dark:text-slate-300" data-testid="situazione"><span v-for="(p, i) in pezziDellaSituazione" :key="i" :class="p.tono ? TONI[p.tono] : ''">{{ p.testo }}</span></SheetDescription>
            </SheetHeader>

            <div class="space-y-5 px-5 py-5 sm:px-7">
                <!-- Il grafico con tre linee e la legenda. -->
                <section>
                    <div class="flex flex-wrap items-center justify-between gap-x-3 gap-y-1">
                        <h3 class="text-sm font-bold text-slate-900 dark:text-white">Il saldo nei prossimi 30 giorni</h3>
                        <div class="flex flex-wrap items-center gap-x-4 gap-y-1 text-xs text-slate-600 dark:text-slate-300" data-testid="legenda">
                            <span class="flex items-center gap-1.5"><span class="h-0.5 w-4 rounded bg-indigo-600 dark:bg-indigo-400"></span>Simulazione</span> <span class="flex items-center gap-1.5"><span class="w-4 border-t-2 border-dashed border-emerald-500 dark:border-emerald-400"></span>Se pagano tutti</span> <span class="flex items-center gap-1.5"><span class="w-4 border-t-2 border-dashed border-slate-400 dark:border-slate-500"></span>Senza le rate</span>
                        </div>
                    </div>
                    <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">La simulazione è il saldo con le rate in scadenza e la parte delle rate già scadute che scegli qui sotto. Le altre due linee sono i limiti.</p>
                    <div class="mt-3 h-64 w-full" role="img" :aria-label="descrizioneDelGraficoGrande(saldoPrevisto, recuperato)" data-testid="grafico-azioni">
                        <GraficoTreLinee v-if="open" :saldo-previsto="saldoPrevisto" :recuperato="recuperato" />
                    </div>
                </section>

                <!-- La simulazione: il cursore, la tacca di quanto basta, le tre cifre. -->
                <section class="rounded-xl border border-slate-200 bg-slate-50/70 p-4 dark:border-slate-800 dark:bg-slate-800/40" data-testid="simulazione">
                    <div class="flex flex-wrap items-baseline justify-between gap-x-3 gap-y-1">
                        <p class="text-sm font-semibold text-slate-900 dark:text-white" data-testid="domanda-recupero">{{ domandaDelRecupero(saldoPrevisto) }}</p>
                        <p class="text-sm tabular-nums" data-testid="recuperato"><b class="text-slate-900 dark:text-white">{{ euroSegno(recuperato) }}</b> <span class="text-slate-500 dark:text-slate-400">({{ percentuale(recuperato, recupero.cifra) }})</span></p>
                    </div>
                    <template v-if="recupero.cifra > 0">
                        <div class="relative mt-3">
                            <input v-model.number="recuperato" type="range" min="0" :max="recupero.cifra" step="1" data-testid="cursore"
                                aria-label="Quanto recuperi delle rate già scadute" class="w-full accent-indigo-600 dark:accent-indigo-400" />
                            <div v-if="laTacca" class="pointer-events-none absolute -top-1 h-5 w-0.5 bg-amber-500" :style="{ left: `${laTacca.posizione}%` }" aria-hidden="true"></div>
                        </div>
                        <div class="mt-1.5 flex justify-between gap-2 text-xs text-slate-500 dark:text-slate-400">
                            <span>niente</span>
                            <button v-if="laTacca" type="button" data-testid="tacca-testo" class="font-semibold text-amber-700 hover:underline dark:text-amber-400" @click="portaAQuantoBasta">{{ laTacca.testo }}</button>
                            <span data-testid="tutte" class="text-right">tutte, {{ euroSegno(recupero.cifra) }}</span>
                        </div>
                        <p v-if="laTacca?.oltre" class="mt-1 text-xs text-amber-800 dark:text-amber-300">Anche recuperando tutte le rate già scadute il saldo con le rate in scadenza va sotto zero: il recupero da solo non basta.</p>
                    </template>
                    <p v-else class="mt-2 text-xs text-slate-500 dark:text-slate-400">Non ci sono rate già scadute da recuperare.</p>
                    <div class="mt-4 grid grid-cols-1 gap-3 sm:grid-cols-3">
                        <div class="rounded-lg border border-slate-200 bg-white px-3 py-2 dark:border-slate-800 dark:bg-slate-900" data-testid="cifra-simulazione"><p class="text-xs text-slate-500 dark:text-slate-400">Il punto più basso</p> <p class="text-base font-bold tabular-nums" :class="coloreCifra(cifre.minimo)">{{ euroSegno(cifre.minimo) }}</p></div>
                        <div class="rounded-lg border border-slate-200 bg-white px-3 py-2 dark:border-slate-800 dark:bg-slate-900" data-testid="cifra-simulazione"><p class="text-xs text-slate-500 dark:text-slate-400">Giorni sotto zero</p> <p class="text-base font-bold tabular-nums" :class="cifre.giorniSottoZero ? 'text-red-600 dark:text-red-400' : 'text-slate-500 dark:text-slate-400'">{{ testoGiorniSottoZero(cifre.giorniSottoZero) }}</p></div>
                        <div class="rounded-lg border border-slate-200 bg-white px-3 py-2 dark:border-slate-800 dark:bg-slate-900" data-testid="cifra-simulazione"><p class="text-xs text-slate-500 dark:text-slate-400">Fra 30 giorni</p> <p class="text-base font-bold tabular-nums" :class="coloreCifra(cifre.fine)">{{ euroSegno(cifre.fine) }}</p></div>
                    </div>
                </section>

                <!-- Le azioni consigliate: con «Scelgo io» nel condominio non ci sono. -->
                <section v-if="suggerisce" data-testid="sezione-azioni">
                    <h3 class="text-sm font-bold text-slate-900 dark:text-white">Azioni consigliate</h3>
                    <p class="mt-0.5 text-xs text-slate-500 dark:text-slate-400">Calcolate dai numeri qui sopra; la scelta resta tua.</p>
                    <!-- L'esito dell'emissione dalla finestra: la tesoreria qui sopra e le azioni qui sotto sono già quelle di dopo. -->
                    <div v-if="esitoEmissione" role="status" data-testid="esito-emissione"
                        class="mt-3 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-900 dark:border-emerald-900/60 dark:bg-emerald-950/30 dark:text-emerald-200">
                        <div class="flex items-start justify-between gap-3">
                            <p class="font-bold">{{ esitoEmissione.titolo }}</p>
                            <button type="button" data-testid="chiudi-esito" class="shrink-0 rounded-md px-1.5 text-xs font-bold text-emerald-800 hover:bg-emerald-100 dark:text-emerald-300 dark:hover:bg-emerald-900/40"
                                @click="esitoEmissione = null">Chiudi</button>
                        </div>
                        <p v-for="(capoverso, k) in capoversiDellEsito" :key="k" class="mt-1 text-xs" data-testid="capoverso-esito">{{ capoverso }}</p>
                    </div>
                    <div class="mt-3 space-y-2.5">
                        <div v-for="(a, i) in azioni" :key="`${a.chiave}-${i}`" data-testid="azione-consigliata" :data-chiave="a.chiave"
                            class="flex flex-col gap-3 rounded-xl border border-slate-200 p-4 dark:border-slate-800 sm:flex-row">
                            <div class="flex min-w-0 flex-1 gap-3">
                                <div class="flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-indigo-600 text-xs font-bold text-white">{{ i + 1 }}</div>
                                <div class="min-w-0 flex-1">
                                    <p class="text-sm font-bold text-slate-900 dark:text-white">{{ a.titolo }}</p>
                                    <p class="mt-1 text-xs text-slate-600 dark:text-slate-400" data-testid="frase-azione"><span v-for="(p, k) in a.frase" :key="k" :class="p.tono === 'forte' ? 'font-bold text-slate-800 dark:text-slate-200' : ''">{{ p.testo }}</span></p>
                                    <!-- Le persone del recupero, sotto la frase che finisce ai due punti: una riga per persona. Al telefono ogni riga è una card, con il nome
                                         della colonna accanto al valore; da 768 pixel una tabella, con i nomi delle colonne in testa. -->
                                    <table v-if="a.tabella?.righe.length" data-testid="tabella-persone" class="mt-2 block w-full border-collapse text-xs text-slate-700 dark:text-slate-300 md:table">
                                        <thead class="hidden md:table-header-group">
                                            <tr class="border-b border-slate-200 text-left text-slate-500 dark:border-slate-700 dark:text-slate-400">
                                                <th scope="col" class="px-2 py-1.5 font-semibold">Chi</th>
                                                <th scope="col" class="px-2 py-1.5 font-semibold">Unità</th>
                                                <th scope="col" class="px-2 py-1.5 text-right font-semibold">Rate scadute</th>
                                                <th scope="col" class="px-2 py-1.5 font-semibold">Ultima lettera</th>
                                            </tr>
                                        </thead>
                                        <tbody class="block space-y-2 md:table-row-group md:space-y-0">
                                            <tr v-for="r in a.tabella.righe" :key="r.anagraficaId" data-testid="riga-persona"
                                                class="block rounded-lg border border-slate-200 px-3 py-2 dark:border-slate-700 md:table-row md:rounded-none md:border-0 md:border-b md:border-slate-100 md:p-0 md:dark:border-slate-800">
                                                <td data-colonna="chi" class="block pb-1 md:table-cell md:px-2 md:py-1.5"><span data-valore class="break-words font-bold text-slate-900 dark:text-white">{{ r.nome }}</span></td>
                                                <td data-colonna="unita" class="flex items-baseline justify-between gap-3 py-0.5 md:table-cell md:px-2 md:py-1.5">
                                                    <span data-etichetta class="shrink-0 text-slate-500 dark:text-slate-400 md:hidden">Unità</span><span data-valore class="text-right md:text-left">{{ r.unita || '—' }}</span>
                                                </td>
                                                <td data-colonna="rate" class="flex items-baseline justify-between gap-3 py-0.5 md:table-cell md:px-2 md:py-1.5 md:text-right">
                                                    <span data-etichetta class="shrink-0 text-slate-500 dark:text-slate-400 md:hidden">Rate scadute</span><span data-valore class="whitespace-nowrap tabular-nums">{{ euroSegno(r.cifra) }}</span>
                                                </td>
                                                <td data-colonna="lettera" class="flex items-baseline justify-between gap-3 py-0.5 md:table-cell md:px-2 md:py-1.5">
                                                    <span data-etichetta class="shrink-0 text-slate-500 dark:text-slate-400 md:hidden">Ultima lettera</span>
                                                    <span v-if="r.lettera" data-valore class="text-right md:text-left">{{ r.lettera }}</span>
                                                    <span v-else data-valore data-testid="nessuna-lettera"
                                                        class="inline-flex rounded-md border border-slate-200 bg-slate-50 px-1.5 text-xs text-slate-500 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-400">nessuna</span>
                                                </td>
                                            </tr>
                                            <tr v-if="a.tabella.altre" class="block md:table-row">
                                                <td colspan="4" data-testid="altre-persone" class="block pt-1 text-slate-500 dark:text-slate-400 md:table-cell md:px-2 md:py-1.5">{{ testoDelleAltre(a.tabella.altre) }}</td>
                                            </tr>
                                        </tbody>
                                    </table>
                                    <template v-if="a.dettagli?.length">
                                        <button type="button" class="mt-1.5 inline-flex items-center gap-1 text-xs font-semibold text-indigo-600 hover:underline dark:text-indigo-400"
                                            data-testid="mostra-dettagli" :aria-expanded="aperti.has(i)" @click="aperti.has(i) ? aperti.delete(i) : aperti.add(i)">
                                            {{ aperti.has(i) ? 'Nascondi le rate' : 'Mostra le rate' }}<ChevronDown class="h-3.5 w-3.5 transition-transform" :class="aperti.has(i) ? 'rotate-180' : ''" />
                                        </button>
                                        <ul v-if="aperti.has(i)" class="mt-1 space-y-0.5 text-xs text-slate-600 dark:text-slate-400" data-testid="dettagli-azione">
                                            <li v-for="(d, k) in a.dettagli" :key="k" class="tabular-nums">{{ d }}</li>
                                        </ul>
                                    </template>
                                    <p v-if="a.nota" class="mt-1.5 text-xs text-slate-500 dark:text-slate-400" :data-testid="a.chiave === 'recupero' ? 'prima-di-sollecitare' : 'nota-azione'">{{ a.nota }}</p>
                                </div>
                            </div>
                            <!-- I pulsanti in una colonna, larghi uguali: il primo pieno (il sollecito) o a contorno; «Registra gli incassi» sotto, a contorno. Al telefono
                                 la colonna è larga quanto la card. -->
                            <div class="flex w-full shrink-0 flex-col gap-2 self-start sm:w-48 sm:self-center">
                                <button v-if="a.pulsante.tipo === 'lettera'" type="button" data-testid="pulsante-azione"
                                    class="inline-flex h-9 w-full items-center justify-center gap-1.5 whitespace-nowrap rounded-md bg-slate-900 px-3 text-xs font-bold text-white hover:bg-slate-800 dark:bg-white dark:text-slate-900 dark:hover:bg-slate-200"
                                    @click="scrivi(a)">{{ a.pulsante.testo }}<ArrowRight class="h-3.5 w-3.5" /></button>
                                <button v-else-if="a.pulsante.tipo === 'emissione'" type="button" data-testid="pulsante-azione" :class="PULSANTE_A_CONTORNO"
                                    @click="apriEmissione(a)">{{ a.pulsante.testo }}<ArrowRight class="h-3.5 w-3.5" /></button>
                                <Link v-else-if="indirizzo(a)" :href="indirizzo(a)!" data-testid="pulsante-azione" :class="PULSANTE_A_CONTORNO">{{ a.pulsante.testo }}<ArrowRight class="h-3.5 w-3.5" /></Link>
                                <Link v-if="a.secondo && rotta('movimenti-rate.create')" :href="rotta('movimenti-rate.create')!" data-testid="pulsante-incassi" :class="PULSANTE_A_CONTORNO">{{ a.secondo.testo }}<ArrowRight class="h-3.5 w-3.5" /></Link>
                            </div>
                        </div>
                        <p v-if="azioni.length === 0" class="text-xs text-slate-500 dark:text-slate-400">Con le rate in scadenza il saldo non va sotto zero nei 30 giorni: nessuna azione da consigliare.</p>
                        <p class="flex items-start gap-1.5 pt-1 text-xs text-slate-500 dark:text-slate-400" data-testid="riga-scadenze"><Info class="mt-0.5 h-3.5 w-3.5 shrink-0" />{{ RIGA_DELLE_SCADENZE }}</p>
                    </div>
                </section>
            </div>
        </SheetContent>
    </Sheet>

    <LettereDialog
        v-model:open="lettera.aperta"
        :condominio-id="condominioId"
        :persona="lettera.persona"
        :selezione="lettera.selezione"
        tipo-iniziale="sollecito"
        :oggi="oggi"
        @fatto="ricarica"
    />

    <EmissioneRapidaDialog
        v-model:open="emissione.aperta"
        :condominio-id="condominioId"
        :piano-id="emissione.pianoId"
        :oggi="oggi"
        @emesse="esitoEmissione = $event"
    />
</template>
