<!--
SPDX-License-Identifier: AGPL-3.0-or-later
Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
-->
<script setup lang="ts">
/**
 * La finestra «Emetti le rate», che si apre dall'azione delle rate da emettere nel cassetto della tesoreria. Dall'alto:
 *
 * - il piano in un menu del programma (quello dell'azione; gli altri piani del condominio con rate da emettere si scelgono da lì), che nella finestra non si
 *   apre da solo;
 * - le rate non ancora emesse del piano, con la casella: spuntate di serie le già scadute e quelle dei prossimi 30 giorni, cioè le rate dell'azione;
 * - la data di emissione (oggi, nel giorno dell'amministratore), la descrizione facoltativa, «Avvisa i condòmini» con l'interruttore acceso, come nella pagina
 *   del piano;
 * - il riepilogo (quante rate, quanti condòmini, la cifra), «Apri il piano» e «Emetti N rate», spento senza rate spuntate.
 *
 * I dati arrivano a ogni apertura con un caricamento parziale della dashboard (`emissioneRapida`, solo quando la finestra li chiede). L'emissione va alla rotta
 * della pagina del piano (`EmissioneRateController::store`) con `da=dashboard`, con gli stessi controlli: il rifiuto (il piano non approvato, il piano degli
 * addebiti, i passaggi da seguire, la delibera cambiata) e gli errori della richiesta si leggono qui, e la finestra resta. Dopo l'emissione la dashboard
 * riporta solo la tesoreria (e il messaggio), senza ricaricare la pagina né tornare in alto; la finestra si chiude e passa l'esito al cassetto, e l'azione
 * sparisce se il piano non ha più rate da emettere. Il messaggio non resta anche in cima alla dashboard: l'esito si legge dove l'amministratore guarda.
 */
import { computed, ref, watch } from 'vue';
import { Link, router, usePage } from '@inertiajs/vue3';
import vSelect from 'vue-select';
import 'vue-select/dist/vue-select.css';
import { ArrowRight, Info, Loader2, Receipt, X } from 'lucide-vue-next';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Dialog, DialogClose, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Switch } from '@/components/ui/switch';
import { Tooltip, TooltipContent, TooltipProvider, TooltipTrigger } from '@/components/ui/tooltip';
import { usePermission } from '@/composables/permissions';
import { blocchiMessaggio } from '@/lib/gestionale/pianiRate/blocchiMessaggio';
import { euroSegno, giornoIntero } from '@/lib/gestionale/tesoreria/saldoPrevisto';
import {
    AVVISA, dataDellaRata, esitoDellaRisposta, etichettaDelPiano, frasiDegliErrori, quandoScade, riepilogo, spuntateDiSerie, testoDelPulsante, testoDelRiepilogo,
    type DatiDellaFinestra, type FlashDellEmissione,
} from '@/lib/gestionale/tesoreria/emissioneRapida';

const props = defineProps<{
    open: boolean;
    condominioId: number;
    /** Il piano dell'azione: la finestra si apre su di lui. */
    pianoId: number | null;
    /** Il giorno dell'amministratore, per la data di emissione. */
    oggi: string;
}>();
const emit = defineEmits<{
    (e: 'update:open', aperta: boolean): void;
    (e: 'emesse', esito: { titolo: string; testo: string }): void;
}>();

const pagina = usePage();
const { generateRoute } = usePermission();
/** Un indirizzo del gestionale del condominio; null se la rotta non c'è. */
const rotta = (nome: string, altro: Record<string, unknown> = {}) => {
    try {
        return route(generateRoute(`gestionale.${nome}`), { condominio: props.condominioId, ...altro });
    } catch {
        return null;
    }
};

const dati = ref<DatiDellaFinestra | null>(null);
const caricamento = ref(false);
const nonLetti = ref(false);
const pianoScelto = ref<number | null>(null);
const spuntate = ref<number[]>([]);
const dataEmissione = ref('');
const descrizione = ref('');
const avvisa = ref(true);
const inCorso = ref(false);
const rifiuto = ref<string | null>(null);
const avviso = ref<{ titolo: string; testo: string } | null>(null);
const errori = ref<string[]>([]);

const piano = computed(() => dati.value?.piani.find((p) => p.pianoId === pianoScelto.value) ?? null);
const opzioni = computed(() => (dati.value?.piani ?? []).map((p) => ({ id: p.pianoId, label: etichettaDelPiano(p) })));
const idDellOpzione = (o: { id: number }) => o.id;
const conti = computed(() => riepilogo(piano.value, spuntate.value));
const oggiDellaFinestra = computed(() => dati.value?.oggi ?? props.oggi);
const fino = computed(() => dati.value?.fino ?? '');
const indirizzoDelPiano = computed(() => (piano.value?.esercizioId ? rotta('esercizi.piani-rate.show', { esercizio: piano.value.esercizioId, pianoRate: piano.value.pianoId }) : null));
const capoversiDelRifiuto = computed(() => blocchiMessaggio(rifiuto.value));
/** La scadenza nel suo colore, e detta con la scritta: scaduta in rosso, nei 30 giorni in ambra, più avanti in grigio. */
const TONI = { scaduta: 'text-red-600 dark:text-red-400', presto: 'text-amber-700 dark:text-amber-400', avanti: 'text-slate-500 dark:text-slate-400' } as const;
const righe = computed(() => (piano.value?.rate ?? []).map((r) => {
    const quando = quandoScade(r.scadenza, oggiDellaFinestra.value, fino.value);

    return { ...r, data: dataDellaRata(r), quando: quando.testo, tono: TONI[quando.tono] };
}));

function azzeraMessaggi() {
    rifiuto.value = null;
    avviso.value = null;
    errori.value = [];
}

/** Il piano scelto, con le sue rate spuntate di serie. */
function impostaPiano(id: number | null) {
    pianoScelto.value = id;
    spuntate.value = spuntateDiSerie(piano.value, fino.value);
}

/** Dal menu: un altro piano, con le sue rate spuntate di serie; quello che la finestra diceva del piano di prima non vale più. */
function cambiaPiano(id: number | null) {
    impostaPiano(id);
    azzeraMessaggi();
}

/** I piani e le rate, dal server: il piano che si vuole tenere se ha ancora rate da emettere, altrimenti il primo. */
function leggi(daTenere: number | null) {
    caricamento.value = true;
    nonLetti.value = false;
    router.reload({
        only: ['emissioneRapida'],
        onSuccess: (p) => {
            const d = ((p.props as Record<string, unknown>).emissioneRapida ?? null) as DatiDellaFinestra | null;
            dati.value = d;
            impostaPiano(d?.piani.some((x) => x.pianoId === daTenere) ? daTenere : d?.piani[0]?.pianoId ?? null);
        },
        onError: () => {
            nonLetti.value = true;
        },
        onFinish: () => {
            caricamento.value = false;
        },
    });
}

watch(() => props.open, (aperta) => {
    if (!aperta) return;
    dati.value = null;
    pianoScelto.value = props.pianoId;
    spuntate.value = [];
    dataEmissione.value = props.oggi;
    descrizione.value = '';
    avvisa.value = true;
    inCorso.value = false;
    azzeraMessaggi();
    leggi(props.pianoId);
}, { immediate: true });

function alterna(id: number, si: boolean) {
    spuntate.value = si ? [...new Set([...spuntate.value, id])] : spuntate.value.filter((x) => x !== id);
}
const tutte = () => { spuntate.value = (piano.value?.rate ?? []).map((r) => r.rataId); };
const nessuna = () => { spuntate.value = []; };

/** Il messaggio della risposta si legge nella finestra o nel cassetto: in cima alla dashboard non resta. */
function togliIlMessaggioDallaPagina() {
    const flash = (pagina.props as Record<string, unknown>).flash as { message?: unknown } | undefined;
    if (flash) flash.message = undefined;
}

function emetti() {
    const p = piano.value;
    if (!p || conti.value.rate === 0 || inCorso.value) return;
    const url = rotta('piani-rate.emetti', { pianoRate: p.pianoId });
    if (!url) return;
    // Nell'ordine del piano, non in quello delle spunte.
    const scelte = p.rate.map((r) => r.rataId).filter((id) => spuntate.value.includes(id));
    inCorso.value = true;
    azzeraMessaggi();
    router.post(url, {
        rate_ids: scelte,
        data_emissione: dataEmissione.value,
        descrizione_personalizzata: descrizione.value,
        invia_notifiche: avvisa.value,
        da: 'dashboard',
    }, {
        // La tesoreria, il messaggio, l'Inbox operativa (l'emissione toglie i compiti «Emettere la rata» delle rate emesse: la lista da capo, come una scheda)
        // e i sei mesi (le rate emesse e già scadute sono debito nuovo). La pagina resta dov'è, con il cassetto aperto.
        only: ['widgets', 'flash', 'inboxTasks', 'inboxSchede', 'inboxScaduti', 'seiMesi', 'compitiSeiMesi'],
        reset: ['inboxTasks'],
        preserveScroll: true,
        preserveState: true,
        onSuccess: (risposta) => {
            const esito = esitoDellaRisposta((risposta.props as Record<string, unknown>).flash as FlashDellEmissione | undefined, scelte.length);
            togliIlMessaggioDallaPagina();
            if (esito.tipo === 'rifiuto') {
                rifiuto.value = esito.testo;

                return;
            }
            if (esito.tipo === 'nessuna') {
                // Nessuna rata a giornale: la finestra resta, e le rate si rileggono (una può essere stata emessa da un'altra pagina).
                avviso.value = { titolo: esito.titolo, testo: esito.testo };
                leggi(p.pianoId);

                return;
            }
            emit('emesse', { titolo: esito.titolo, testo: esito.testo });
            emit('update:open', false);
        },
        onError: (e) => {
            errori.value = frasiDegliErrori(e as Record<string, string>);
            togliIlMessaggioDallaPagina();
            // Una rata che non è più del piano (ricalcolato in un'altra pagina): le rate si rileggono, così «scegli di nuovo le rate» si può fare.
            if (Object.keys(e ?? {}).some((k) => k.startsWith('rate_ids'))) leggi(p.pianoId);
        },
        onFinish: () => {
            inCorso.value = false;
        },
    });
}
</script>

<template>
    <Dialog :open="open" @update:open="(v: boolean) => emit('update:open', v)">
        <!-- Il menu del piano non si apre da solo: la finestra non mette il cursore da nessuna parte. -->
        <DialogContent :show-close-button="false" class="max-h-[92dvh] gap-0 overflow-y-auto p-0 sm:max-w-[640px]" data-testid="finestra-emissione" @open-auto-focus.prevent>
            <DialogHeader class="space-y-0 border-b border-slate-100 px-5 py-4 text-left dark:border-slate-800 sm:px-6">
                <div class="flex items-start justify-between gap-3">
                    <div class="flex min-w-0 items-center gap-2">
                        <div class="shrink-0 rounded-lg bg-indigo-100 p-1.5 text-indigo-700 dark:bg-indigo-900/40 dark:text-indigo-300"><Receipt class="h-4 w-4" /></div>
                        <DialogTitle class="text-lg font-bold text-slate-900 dark:text-white">Emetti le rate</DialogTitle>
                    </div>
                    <DialogClose data-testid="chiudi-finestra"
                        class="inline-flex shrink-0 items-center gap-1 rounded-md px-2 py-1 text-xs font-bold text-slate-500 hover:bg-slate-100 hover:text-slate-800 dark:text-slate-400 dark:hover:bg-slate-800 dark:hover:text-slate-200">
                        <X class="h-4 w-4" /> Chiudi
                    </DialogClose>
                </div>
                <DialogDescription class="mt-2 text-sm text-slate-600 dark:text-slate-300">
                    Le rate spuntate vanno a giornale con la data di emissione, come dalla pagina del piano e con gli stessi controlli.
                </DialogDescription>
            </DialogHeader>

            <div class="space-y-5 px-5 py-5 sm:px-6">
                <!-- Il piano. -->
                <section>
                    <Label class="text-xs font-semibold text-slate-700 dark:text-slate-200">Il piano rate</Label>
                    <div class="menu-piano mt-1.5" data-testid="menu-piano">
                        <v-select
                            :model-value="pianoScelto"
                            :options="opzioni"
                            label="label"
                            :reduce="idDellOpzione"
                            :searchable="false"
                            :clearable="false"
                            :disabled="caricamento || opzioni.length === 0"
                            class="w-full"
                            @update:model-value="cambiaPiano"
                        />
                    </div>
                    <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">Gli altri piani del condominio con rate da emettere si scelgono da qui.</p>
                </section>

                <!-- Le rate del piano. -->
                <section>
                    <div class="flex flex-wrap items-baseline justify-between gap-x-3 gap-y-1">
                        <h3 class="text-sm font-bold text-slate-900 dark:text-white">Le rate non ancora emesse</h3>
                        <div v-if="piano" class="flex items-center gap-1.5 text-xs">
                            <button type="button" class="font-semibold text-indigo-600 hover:underline dark:text-indigo-400" data-testid="tutte" @click="tutte">Tutte</button>
                            <span class="text-slate-300 dark:text-slate-600">·</span>
                            <button type="button" class="font-semibold text-indigo-600 hover:underline dark:text-indigo-400" data-testid="nessuna" @click="nessuna">Nessuna</button>
                        </div>
                    </div>
                    <p class="mt-0.5 text-xs text-slate-500 dark:text-slate-400" data-testid="spuntate-di-serie">
                        Spuntate di serie le rate già scadute e quelle che scadono entro il {{ fino ? giornoIntero(fino) : '…' }}, come nell'azione.
                    </p>

                    <div v-if="caricamento" class="mt-2 space-y-1.5" data-testid="caricamento" aria-busy="true">
                        <div v-for="i in 3" :key="i" class="h-10 animate-pulse rounded-md bg-slate-100 dark:bg-slate-800"></div>
                    </div>
                    <p v-else-if="nonLetti" class="mt-2 text-xs text-red-700 dark:text-red-300" data-testid="non-letti">Non è stato possibile leggere le rate: chiudi la finestra e riaprila.</p>
                    <p v-else-if="!piano" class="mt-2 text-xs text-slate-500 dark:text-slate-400" data-testid="niente-da-emettere">Nei piani approvati di questo condominio non ci sono rate da emettere.</p>
                    <div v-else class="mt-2 overflow-hidden rounded-lg border border-slate-200 dark:border-slate-800" role="table" aria-label="Le rate non ancora emesse">
                        <!-- Colonne fisse: la casella, la rata, la scadenza, i condòmini, la cifra; al telefono la scadenza va sotto la rata e i condòmini non ci sono. -->
                        <div role="row" class="grid grid-cols-[1rem_minmax(0,1fr)_6.5rem] items-center gap-3 whitespace-nowrap bg-slate-50 px-3 py-2 text-xs font-semibold text-slate-500 dark:bg-slate-800/50 dark:text-slate-400 sm:grid-cols-[1rem_minmax(0,1fr)_8.5rem_5.5rem_7rem]">
                            <span role="columnheader"><span class="sr-only">Scelta</span></span>
                            <span role="columnheader">Rata</span>
                            <span role="columnheader" class="hidden sm:block">Scadenza</span>
                            <span role="columnheader" class="hidden text-right sm:block">Condòmini</span>
                            <span role="columnheader" class="text-right">Cifra</span>
                        </div>
                        <ul class="max-h-[38dvh] overflow-y-auto">
                            <li v-for="r in righe" :key="r.rataId" role="row" data-testid="rata-finestra" :data-rata="r.rataId"
                                class="grid grid-cols-[1rem_minmax(0,1fr)_6.5rem] items-center gap-3 border-t border-slate-100 px-3 py-2 dark:border-slate-800 sm:grid-cols-[1rem_minmax(0,1fr)_8.5rem_5.5rem_7rem]">
                                <Checkbox :model-value="spuntate.includes(r.rataId)" :aria-label="`Rata ${r.numero}`"
                                    @update:model-value="(v: boolean | 'indeterminate') => alterna(r.rataId, v === true)" />
                                <div class="min-w-0">
                                    <p class="text-sm font-semibold text-slate-900 dark:text-white">Rata {{ r.numero }}</p>
                                    <p class="text-xs sm:hidden"><span class="tabular-nums text-slate-600 dark:text-slate-300">{{ r.data }}</span> · <span :class="r.tono">{{ r.quando }}</span></p>
                                </div>
                                <div class="hidden text-xs sm:block">
                                    <p class="tabular-nums text-slate-700 dark:text-slate-200">{{ r.data }}</p>
                                    <p :class="r.tono" data-testid="quando">{{ r.quando }}</p>
                                </div>
                                <span class="hidden text-right text-sm tabular-nums text-slate-700 dark:text-slate-200 sm:block">{{ r.persone }}</span>
                                <span class="text-right text-sm font-bold tabular-nums" :class="r.cifra === 0 ? 'text-slate-500 dark:text-slate-400' : 'text-slate-900 dark:text-white'" data-testid="cifra-rata">{{ euroSegno(r.cifra) }}</span>
                            </li>
                        </ul>
                    </div>
                </section>

                <!-- La data e la descrizione. -->
                <section class="grid gap-4 sm:grid-cols-[11rem_minmax(0,1fr)]">
                    <div class="space-y-1.5">
                        <Label for="emissione-data" class="text-xs font-semibold text-slate-700 dark:text-slate-200">Data di emissione</Label>
                        <Input id="emissione-data" v-model="dataEmissione" type="date" class="h-9 text-sm" data-testid="data-emissione" />
                        <p class="text-xs text-slate-500 dark:text-slate-400">La data della scrittura in prima nota.</p>
                    </div>
                    <div class="space-y-1.5">
                        <Label for="emissione-descrizione" class="text-xs font-semibold text-slate-700 dark:text-slate-200">Descrizione (facoltativa)</Label>
                        <Input id="emissione-descrizione" v-model="descrizione" type="text" maxlength="255" class="h-9 text-sm" placeholder="Per esempio: Rate di primavera" data-testid="descrizione" />
                        <p class="text-xs text-slate-500 dark:text-slate-400">Vuota, la scrittura dice «Emissione» e il nome della rata.</p>
                    </div>
                </section>

                <!-- Avvisa i condòmini: l'interruttore della pagina del piano, acceso di serie. -->
                <section class="flex flex-col justify-between gap-4 rounded-lg border border-slate-200 p-4 dark:border-slate-800 sm:flex-row sm:items-center" data-testid="riga-avvisa">
                    <div class="flex-1">
                        <div class="mb-1 flex items-center gap-1.5">
                            <label for="emissione-avvisa" class="block text-sm font-medium leading-none text-slate-900 dark:text-white">{{ AVVISA.titolo }}</label>
                            <TooltipProvider :delay-duration="200">
                                <Tooltip>
                                    <TooltipTrigger as-child>
                                        <span tabindex="0" role="img" :aria-label="AVVISA.esempio" data-testid="info-avvisa"
                                            class="inline-flex cursor-help items-center rounded text-slate-400 hover:text-slate-600 focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-400 dark:text-slate-500 dark:hover:text-slate-300">
                                            <Info class="h-3.5 w-3.5" />
                                        </span>
                                    </TooltipTrigger>
                                    <TooltipContent class="max-w-80 text-xs leading-snug"><p>{{ AVVISA.esempio }}</p></TooltipContent>
                                </Tooltip>
                            </TooltipProvider>
                        </div>
                        <p class="text-sm text-muted-foreground">{{ AVVISA.spiegazione }}</p>
                        <p class="mt-2 rounded-md bg-slate-50 px-2.5 py-1.5 text-xs leading-snug text-slate-700 dark:bg-slate-800/50 dark:text-slate-300" data-testid="adesso-avvisa">{{ avvisa ? AVVISA.acceso : AVVISA.spento }}</p>
                    </div>
                    <Switch id="emissione-avvisa" v-model="avvisa" data-testid="interruttore-avvisa" />
                </section>

                <!-- Il riepilogo delle rate spuntate. -->
                <section class="rounded-lg bg-slate-50 px-4 py-3 dark:bg-slate-800/40" data-testid="riepilogo">
                    <p v-if="conti.rate > 0" class="text-sm text-slate-700 dark:text-slate-200">Emetti <b class="tabular-nums text-slate-900 dark:text-white" data-testid="riepilogo-cifre">{{ testoDelRiepilogo(conti) }}</b>.</p>
                    <p v-else class="text-sm text-slate-500 dark:text-slate-400" data-testid="nessuna-spunta">Spunta almeno una rata.</p>
                    <p class="mt-0.5 text-xs text-slate-500 dark:text-slate-400">La cifra è, per ogni condòmino, la sua parte ancora da pagare delle rate spuntate.</p>
                </section>

                <!-- Il rifiuto del server, l'esito senza rate emesse, gli errori della richiesta: la finestra resta. -->
                <section v-if="rifiuto" role="alert" class="rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800 dark:border-red-900/60 dark:bg-red-950/30 dark:text-red-200" data-testid="rifiuto">
                    <p class="font-bold">Le rate non sono state emesse</p>
                    <div v-for="(capoverso, i) in capoversiDelRifiuto" :key="i" class="mt-1.5 space-y-1">
                        <template v-for="(blocco, j) in capoverso" :key="j">
                            <p v-if="blocco.tipo === 'testo'">{{ blocco.testo }}</p>
                            <ul v-else-if="blocco.tipo === 'punti'" class="list-disc space-y-0.5 pl-5"><li v-for="(voce, k) in blocco.voci" :key="k">{{ voce }}</li></ul>
                            <ol v-else class="list-decimal space-y-1 pl-5"><li v-for="(voce, k) in blocco.voci" :key="k" class="pl-1">{{ voce }}</li></ol>
                        </template>
                    </div>
                </section>
                <section v-if="avviso" role="status" class="rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900 dark:border-amber-900/60 dark:bg-amber-950/30 dark:text-amber-200" data-testid="avviso">
                    <p class="font-bold">{{ avviso.titolo }}</p>
                    <p class="mt-1">{{ avviso.testo }}</p>
                </section>
                <ul v-if="errori.length" role="alert" class="list-disc space-y-0.5 pl-5 text-xs text-red-700 dark:text-red-300" data-testid="errori">
                    <li v-for="m in errori" :key="m">{{ m }}</li>
                </ul>
            </div>

            <DialogFooter class="flex-col-reverse gap-2 border-t border-slate-100 px-5 py-4 dark:border-slate-800 sm:flex-row sm:items-center sm:justify-between sm:px-6">
                <Link v-if="indirizzoDelPiano" :href="indirizzoDelPiano" data-testid="apri-il-piano"
                    class="inline-flex h-9 items-center justify-center gap-1.5 whitespace-nowrap rounded-md border border-slate-200 bg-white px-3 text-xs font-bold text-slate-800 hover:bg-slate-50 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-100 dark:hover:bg-slate-800">
                    Apri il piano<ArrowRight class="h-3.5 w-3.5" />
                </Link>
                <span v-else></span>
                <Button class="h-9 text-xs font-bold" :disabled="conti.rate === 0 || inCorso || caricamento" data-testid="emetti" @click="emetti">
                    <Loader2 v-if="inCorso" class="mr-1 h-4 w-4 animate-spin" />
                    {{ testoDelPulsante(conti.rate) }}
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>

<style>
/* Il menu del piano: alto come i campi della finestra, con il testo della finestra; nel tema scuro leggibile. */
.menu-piano .v-select {
    --vs-font-size: 0.875rem;
    --vs-line-height: 1.25;
    --vs-border-radius: 0.375rem;
    --vs-border-color: var(--input);
    --vs-controls-color: rgb(100 116 139);
    background: transparent;
}
.menu-piano .vs__dropdown-toggle {
    min-height: 2.25rem;
    padding: 0;
    align-items: center;
    box-shadow: 0 1px 2px 0 rgb(0 0 0 / 0.05);
}
.menu-piano .vs__selected-options {
    align-items: center;
    padding-left: 8px;
    min-width: 0;
}
.menu-piano .vs__selected {
    margin: 0 !important;
    padding-top: 0 !important;
    padding-bottom: 0 !important;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}
.menu-piano .vs__search {
    margin: 0 !important;
    padding: 0 !important;
}
.menu-piano .vs__actions {
    padding: 0 8px 0 4px;
}
.dark .menu-piano .v-select {
    --vs-dropdown-bg: rgb(15 23 42);
    --vs-dropdown-color: rgb(226 232 240);
    --vs-dropdown-option-color: rgb(226 232 240);
    --vs-selected-color: rgb(226 232 240);
    --vs-search-input-color: rgb(226 232 240);
    --vs-controls-color: rgb(148 163 184);
    --vs-border-color: var(--input);
}
.dark .menu-piano .vs__dropdown-toggle {
    background: color-mix(in oklab, var(--input) 30%, transparent);
}
.dark .menu-piano .vs__dropdown-option--highlight {
    background: rgb(30 41 59) !important;
    color: rgb(241 245 249) !important;
}
</style>
