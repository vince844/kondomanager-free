<!--
SPDX-License-Identifier: AGPL-3.0-or-later
Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
-->
<script setup lang="ts">
/**
 * La strada dei sei passi dell'assemblea (la variante A della bozza dei passi guidati, scene 1 e 2; il sesto dalla scena 1 della bozza del seguito delle
 * delibere): «Date e ordine del giorno», «Documenti», «Convocazione», «In sala», «Verbale e trasmissione», «Delibere eseguite», come li scrive il server
 * (`PassiDellAssemblea`). Un passo chiuso con `apribile` (le delibere con il verbale annullato) ha il lucchetto e si apre lo stesso; il badge del passo
 * aperto è quello del server quando c'è («1 delibera da eseguire»).
 *
 * - Dal tablet in su in orizzontale: il cerchio (verde con la spunta un passo fatto, scuro con il numero quello di adesso, grigio con il numero un passo che
 *   viene dopo, il lucchetto un passo chiuso), la linea verde fino al passo di adesso, il nome e la riga sotto, il segno sotto il passo aperto. Un passo
 *   fatto o quello di adesso è un pulsante e si apre; uno chiuso, o che viene dopo, no: il perché lo scrive il server, sul passaggio del mouse e per chi
 *   legge lo schermo («si apre quando «Date e ordine del giorno» è fatto»).
 * - Sotto i 640 pixel in verticale: «Passo 3 di 5», il nome, la barra di avanzamento, e l'elenco dei passi che si apre con un tocco.
 * - Il diario non sta sulla strada: è «Diario» nel menu «Azioni» della pagina, in un cassetto di lato.
 * - Sotto la strada il titolo del passo aperto, le card di «che cosa manca» (una voce che informa non conta e non blocca), il
 *   contenuto (lo slot); al telefono, senza badge e senza voci, il riquadro del titolo non c'è. In fondo «← il passo prima», la frase del passo e
 *   «Avanti», acceso quando il passo è fatto («Documenti» non blocca) e il passo dopo si apre; spento, dice il perché. Lo slot `azione` prende il posto
 *   di «Avanti» («Prepara l'assemblea»).
 * - Ogni card (la bozza delle card dei passi e del cassetto delle deleghe, scena 1): l'icona di ciò che è (le persone per le deleghe, la stampante per il
 *   foglio, il lucchetto per «Apri la sala»…), con il colore dello stato (azzurro informa, verde fatto, arancio da fare o da rifare); il titolo con il badge
 *   `rounded-md` dello stato («Da fare» ambra, «Fatto» verde, «Da vedere» grigio); la riga
 *   dello stato con la maiuscola, in arancio quando c'è da rifare; in fondo il pulsante piccolo del modulo (alto 28 pixel, come «Revoca») con il verbo
 *   intero scritto dal server. Le card di una fila hanno la stessa altezza e i pulsanti allineati in basso; al telefono una sotto l'altra, il pulsante a
 *   tutta riga. Il pulsante fa ciò che faceva il collegamento: il punto della pagina (`#convocati`), la stampa in una scheda nuova, la pagina del
 *   programma, il passo; in più una finestra della pagina (`apre`: il cassetto delle deleghe, «Segna una delega ricevuta») e il pulsante spento con il
 *   perché («Apri la sala» prima del giorno).
 * - Un collegamento dentro la pagina (`#convocati`) già nell'indirizzo avvisa di nuovo la pagina (`hashchange`): la tabella dei convocati mostra tutte le
 *   righe.
 *
 * La strada non chiede niente al server: dice quale passo aprire (`apri`), quale finestra (`apre`), che è partita una stampa (`stampa`, la pagina rilegge
 * i passi quando si torna), e la pagina fa il resto.
 */
import { computed, ref } from 'vue';
import { Link } from '@inertiajs/vue3';
import {
    ArrowLeft, ArrowRight, BookOpen, Calculator, CalendarClock, CalendarDays, Check, ChevronDown, Contact, DoorOpen, FileSignature, FileText, Info,
    ListChecks, ListOrdered, Lock, Mail, Printer, ScrollText, Send, Table, Users,
} from 'lucide-vue-next';
import type { Component } from 'vue';
import { BADGE, CLASSI_DEL_BADGE } from '@/lib/assemblee/assemblee';
import { siApre, type ChiaveDelPasso, type FinestraDellaVoce, type PassoDellaStrada, type VoceCheCosaManca } from '@/lib/assemblee/pagine';

const props = defineProps<{ passi: PassoDellaStrada[]; aperto: ChiaveDelPasso | null }>();
const emit = defineEmits<{ apri: [passo: ChiaveDelPasso]; apre: [finestra: FinestraDellaVoce]; stampa: [] }>();

const elencoAperto = ref(false);
/** Il passo di adesso; senza, l'ultimo fatto (la convocazione spedita, con la sala ancora chiusa). */
const adesso = computed(() => props.passi.find((p) => p.stato === 'adesso') ?? [...props.passi].reverse().find((p) => p.stato === 'fatto') ?? props.passi[0] ?? null);
const indice = computed(() => props.passi.findIndex((p) => p.chiave === props.aperto));
const passo = computed(() => (indice.value >= 0 ? props.passi[indice.value] : null));
const inCima = computed(() => passo.value ?? adesso.value);
const fatti = computed(() => props.passi.filter((p) => p.stato === 'fatto').length);
const avanzamento = computed(() => (props.passi.length === 0 ? '0%' : `${Math.round((fatti.value / props.passi.length) * 100)}%`));

const titolo = computed(() => (passo.value ? `Passo ${passo.value.numero} · ${passo.value.nome}` : ''));
const frase = computed(() => passo.value?.piede ?? '');

const prima = computed(() => (indice.value > 0 ? props.passi[indice.value - 1] : null));
const dopo = computed(() => (indice.value >= 0 ? props.passi[indice.value + 1] ?? null : null));
const avantiAttivo = computed(() => passo.value !== null && dopo.value !== null && (passo.value.stato === 'fatto' || passo.value.chiave === 'documenti')
    && siApre(dopo.value));
/** Spento: sul passo di adesso non fatto la frase del piede (che cosa serve per farlo); altrimenti il perché del passo dopo, che non si apre. */
const percheAvanti = computed(() => {
    if (avantiAttivo.value) return undefined;
    if (passo.value?.stato === 'adesso' && passo.value.chiave !== 'documenti') return passo.value.piede;
    return (dopo.value && !siApre(dopo.value) ? dopo.value.perche : null) ?? passo.value?.piede;
});
/** Al telefono il titolo è nascosto: senza voci il riquadro non c'è (resta dal tablet in su, con il titolo). */
const testaVuotaAlTelefono = computed(() => !(passo.value && passo.value.manca.length > 0));

// ─── Le card di «che cosa manca» ───────────────────────────────────────────────────────────────────────────────────────────────────────────────────────
/** Che cosa è la card, dalla chiave della voce: il nome (per le prove e per chi legge il sorgente) e l'icona. Senza un'icona giusta, la «i». */
const ICONE: Record<string, [string, Component]> = {
    date: ['calendario', CalendarDays], ordine: ['elenco', ListOrdered], tabella: ['tabella', Table],
    avviso: ['avviso', FileText], casella: ['posta', Mail], convocati: ['convocati', Contact],
    sala: ['sala', DoorOpen], deleghe: ['persone', Users], foglio: ['stampante', Printer], prospetto: ['stampante', Printer], convocazione: ['invio', Send],
    verbale: ['verbale', ScrollText], bozza: ['stampante', Printer], cifre: ['conti', Calculator], copia: ['firma', FileSignature],
    trasmissione: ['invio', Send], libro: ['libro', BookOpen], da_eseguire: ['compiti', ListChecks], trenta_giorni: ['scadenza', CalendarClock],
};
/** «Apri la sala» ha il lucchetto finché è spento, la porta quando si apre. */
const icona = (v: VoceCheCosaManca): [string, Component] => (v.chiave === 'apri' ? (v.collegamento?.spento ? ['lucchetto', Lock] : ['porta', DoorOpen])
    : ICONE[v.chiave] ?? ['informa', Info]);
/** Il colore dice lo stato: azzurro una voce che informa, verde una fatta, arancio una da fare o da rifare. */
const stato = (v: VoceCheCosaManca): 'azzurro' | 'verde' | 'arancio' => (v.informa ? 'azzurro' : v.fatto ? 'verde' : 'arancio');
const COLORE_DELL_ICONA = { azzurro: 'text-sky-700 dark:text-sky-400', verde: 'text-emerald-600 dark:text-emerald-400', arancio: 'text-amber-700 dark:text-amber-400' };
/** Lo stato scritto, nel badge accanto al titolo (decisione 648): «Da fare» (ambra), «Fatto» (verde), «Da vedere» (grigio, per ciò che non blocca). */
const BADGE_DELLO_STATO = {
    arancio: { testo: 'Da fare', classi: CLASSI_DEL_BADGE.ambra }, verde: { testo: 'Fatto', classi: CLASSI_DEL_BADGE.verde },
    azzurro: { testo: 'Da vedere', classi: CLASSI_DEL_BADGE.neutro },
};
/** La riga dello stato comincia con la maiuscola: il server la scrive come un pezzo di frase («a posto», «da impostare: …»). */
const conLaMaiuscola = (t: string) => (t ? t.charAt(0).toUpperCase() + t.slice(1) : t);
/** Il pulsante piccolo del modulo, come «Revoca» nell'elenco delle deleghe: alto 28 pixel, il testo a 12; al telefono a tutta riga. */
const PULSANTE = 'inline-flex h-7 w-full items-center justify-center gap-1.5 rounded-md border px-2.5 text-xs font-medium transition-colors disabled:cursor-not-allowed disabled:opacity-55 sm:w-auto';
const pulsanteDellaVoce = (v: VoceCheCosaManca) => [PULSANTE, v.rifare
    ? 'border-amber-200 bg-amber-50 text-amber-800 hover:bg-amber-100 dark:border-amber-800 dark:bg-amber-950/40 dark:text-amber-300 dark:hover:bg-amber-950/70'
    : 'border-slate-200 bg-white text-slate-700 hover:bg-slate-50 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-200 dark:hover:bg-slate-800'];

/** Un collegamento dentro la pagina già nell'indirizzo: l'evento si manda lo stesso, perché la pagina lo ascolta (la tabella mostra tutte le righe). */
function ancora(url: string) {
    if (typeof window !== 'undefined' && window.location.hash === url) {
        window.dispatchEvent(new HashChangeEvent('hashchange'));
    }
}

function apri(p: PassoDellaStrada) {
    if (!siApre(p)) return;
    elencoAperto.value = false;
    emit('apri', p.chiave);
}

/** Il pulsante di un passo che si apre; un passo chiuso, o che viene dopo, è un riquadro che dice il perché (nel title, e a voce con lo `sr-only`). */
const attributi = (p: PassoDellaStrada) => (siApre(p)
    ? { type: 'button', 'aria-current': props.aperto === p.chiave ? 'step' : undefined }
    : { title: p.perche ?? undefined });

const classiDelCerchio = (p: PassoDellaStrada) => ({
    fatto: 'border-emerald-500 bg-emerald-500 text-white',
    adesso: 'border-slate-900 bg-white text-slate-900 ring-4 ring-slate-100 dark:border-white dark:bg-slate-950 dark:text-white dark:ring-slate-800',
    dopo: 'border-slate-200 bg-white text-slate-500 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-400',
    chiuso: 'border-slate-200 bg-white text-slate-400 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-500',
})[p.stato];
/** Il grassetto è solo del passo aperto: dice «sei qui» senza riquadri né linee. Gli altri restano a peso normale, anche quelli fatti. */
const classiDelNome = (p: PassoDellaStrada) => (props.aperto === p.chiave ? 'font-semibold text-slate-900 dark:text-white' : ({
    fatto: 'font-medium text-slate-700 dark:text-slate-300',
    adesso: 'font-medium text-slate-900 dark:text-white',
    dopo: 'font-medium text-slate-500 dark:text-slate-400',
    chiuso: 'font-medium text-slate-500 dark:text-slate-400',
})[p.stato]);
/** La linea che arriva a un passo è verde se il passo è fatto o è quello di adesso. */
const verde = (p: PassoDellaStrada) => p.stato === 'fatto' || p.stato === 'adesso';
const pulsanteDelPiede = 'inline-flex h-8 w-full items-center justify-center gap-1.5 rounded-lg px-3 text-xs shadow-sm transition-colors sm:w-auto';
</script>

<template>
    <section class="rounded-xl border border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-950" data-testid="strada-dell-assemblea">
        <!-- Dal tablet in su: la strada in orizzontale -->
        <ol class="hidden px-2 pt-4 pb-3.5 sm:grid" :class="passi.length > 5 ? 'grid-cols-6' : 'grid-cols-5'" aria-label="I passi dell'assemblea" data-testid="strada">
            <li v-for="(p, i) in passi" :key="p.chiave" class="relative min-w-0 px-1" :data-passo="p.chiave" :data-stato="p.stato">
                <span v-if="i > 0" class="absolute top-[19px] -left-1/2 right-1/2 h-0.5" :class="verde(p) ? 'bg-emerald-500' : 'bg-slate-200 dark:bg-slate-800'"
                    data-testid="linea" :data-verde="verde(p) ? 'si' : 'no'" aria-hidden="true" />
                <!-- Nessun riquadro, né sul passo aperto né al passaggio del mouse: il mouse cambia solo il colore del nome (il contorno resta per la tastiera). -->
                <component :is="siApre(p) ? 'button' : 'div'" v-bind="attributi(p)" class="group relative flex w-full flex-col items-center rounded-lg px-1 py-1 text-center"
                    :class="siApre(p) ? 'focus-visible:outline-2 focus-visible:outline-slate-400' : 'cursor-default'"
                    :data-aperto="aperto === p.chiave ? 'si' : undefined" @click="apri(p)">
                    <span class="relative z-10 flex h-8 w-8 items-center justify-center rounded-full border-2 text-[13px] font-bold" :class="classiDelCerchio(p)" data-testid="cerchio">
                        <Check v-if="p.stato === 'fatto'" class="h-4 w-4" data-testid="spunta" aria-hidden="true" />
                        <Lock v-else-if="p.stato === 'chiuso'" class="h-3.5 w-3.5" data-testid="lucchetto" aria-hidden="true" />
                        <template v-else>{{ p.numero }}</template>
                    </span>
                    <span class="mt-2 block max-w-full break-words text-[13px] leading-tight transition-colors"
                        :class="[classiDelNome(p), siApre(p) ? 'group-hover:text-slate-900 dark:group-hover:text-white' : '']">{{ p.nome }}</span>
                    <span v-if="p.riga" class="mt-0.5 block max-w-full break-words text-xs leading-snug text-slate-500 dark:text-slate-400">{{ p.riga }}</span>
                    <span v-if="p.stato === 'adesso'" class="sr-only"> (il passo di adesso)</span>
                    <span v-else-if="!siApre(p) && p.perche && p.perche !== p.riga" class="sr-only"> ({{ p.perche }})</span>
                </component>
            </li>
        </ol>

        <!-- Al telefono: il passo aperto, la barra, l'elenco con un tocco -->
        <div v-if="inCima" class="px-4 pt-4 pb-1 sm:hidden" data-testid="strada-telefono">
            <button type="button" class="flex w-full items-center justify-between gap-3 text-left" :aria-expanded="elencoAperto ? 'true' : 'false'"
                data-testid="apri-elenco" @click="elencoAperto = !elencoAperto">
                <span class="min-w-0">
                    <span class="block text-xs text-slate-500 dark:text-slate-400">Passo {{ inCima.numero }} di {{ passi.length }}</span>
                    <span class="block break-words text-[15px] font-bold text-slate-900 dark:text-white">{{ inCima.nome }}</span>
                </span>
                <ChevronDown class="h-4 w-4 shrink-0 text-slate-400 transition-transform" :class="{ 'rotate-180': elencoAperto }" aria-hidden="true" />
            </button>
            <div class="mt-2 h-1.5 overflow-hidden rounded-full bg-slate-100 dark:bg-slate-800" role="progressbar" :aria-valuenow="fatti" aria-valuemin="0"
                :aria-valuemax="passi.length" :aria-label="`${fatti} passi fatti su ${passi.length}`">
                <span class="block h-full bg-emerald-500" :style="{ width: avanzamento }" data-testid="barra" />
            </div>
            <ol v-if="elencoAperto" class="mt-3 divide-y divide-slate-100 overflow-hidden rounded-lg border border-slate-200 dark:divide-slate-800 dark:border-slate-800"
                data-testid="elenco-dei-passi">
                <li v-for="p in passi" :key="p.chiave" :data-stato="p.stato">
                    <component :is="siApre(p) ? 'button' : 'div'" v-bind="attributi(p)" class="flex w-full items-start gap-2.5 px-3 py-2.5 text-left" @click="apri(p)">
                        <span class="flex h-6 w-6 shrink-0 items-center justify-center rounded-full border-2 text-xs font-bold" :class="classiDelCerchio(p)">
                            <Check v-if="p.stato === 'fatto'" class="h-3 w-3" aria-hidden="true" />
                            <Lock v-else-if="p.stato === 'chiuso'" class="h-3 w-3" aria-hidden="true" />
                            <template v-else>{{ p.numero }}</template>
                        </span>
                        <span class="min-w-0">
                            <span class="block break-words text-[13px]" :class="classiDelNome(p)">{{ p.nome }}</span>
                            <span v-if="p.riga" class="block break-words text-xs text-slate-500 dark:text-slate-400">{{ p.riga }}</span>
                            <span v-if="!siApre(p) && p.perche && p.perche !== p.riga" class="sr-only"> ({{ p.perche }})</span>
                        </span>
                    </component>
                </li>
            </ol>
        </div>

        <!-- Il passo aperto: il titolo, ciò che manca, il contenuto -->
        <div class="mt-3 border-t border-slate-100 px-4 py-3.5 sm:px-5 dark:border-slate-800" :class="{ 'hidden sm:block': testaVuotaAlTelefono }"
            data-testid="testa-del-passo">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <h3 class="hidden text-[15px] font-semibold text-slate-900 sm:block dark:text-white" data-testid="titolo-del-passo">{{ titolo }}</h3>
            </div>
            <ul v-if="passo && passo.manca.length" class="mt-3 grid gap-2.5" :class="passo.manca.length === 3 ? 'sm:grid-cols-3 lg:grid-cols-3' : passo.manca.length > 3 ? 'sm:grid-cols-2 lg:grid-cols-4' : 'sm:grid-cols-2 lg:grid-cols-3'"
                aria-label="Che cosa manca" data-testid="che-cosa-manca">
                <li v-for="v in passo.manca" :key="v.chiave ?? v.titolo"
                    class="flex flex-col justify-between gap-2.5 rounded-lg border border-slate-200 bg-slate-50 p-3 text-xs text-slate-700 dark:border-slate-800 dark:bg-slate-900/50 dark:text-slate-300"
                    :data-fatto="v.informa ? 'informa' : v.fatto ? 'si' : 'no'">
                    <div class="flex items-start gap-2">
                        <component :is="icona(v)[1]" class="mt-0.5 h-3.5 w-3.5 shrink-0" :class="COLORE_DELL_ICONA[stato(v)]" data-testid="icona" :data-icona="icona(v)[0]"
                            :data-colore="stato(v)" aria-hidden="true" />
                        <span class="min-w-0 break-words">
                            <span class="flex flex-wrap items-center gap-x-2 gap-y-1">
                                <b class="text-[13px] font-semibold text-slate-900 dark:text-slate-100">{{ v.titolo }}</b>
                                <span :class="[BADGE, BADGE_DELLO_STATO[stato(v)].classi]" data-testid="badge-della-voce">{{ BADGE_DELLO_STATO[stato(v)].testo }}</span>
                            </span>
                            <span class="block" :class="v.rifare ? 'font-medium text-amber-800 dark:text-amber-300' : 'text-slate-600 dark:text-slate-400'"
                                data-testid="riga-della-voce">{{ conLaMaiuscola(v.dettaglio) }}</span>
                        </span>
                    </div>
                    <div v-if="v.collegamento" class="flex">
                        <button v-if="v.collegamento.spento" type="button" :class="pulsanteDellaVoce(v)" disabled :title="v.collegamento.spento"
                            data-testid="pulsante-della-voce">{{ v.collegamento.testo }}<span class="sr-only"> ({{ v.collegamento.spento }})</span></button>
                        <button v-else-if="v.collegamento.apre" type="button" :class="pulsanteDellaVoce(v)" data-testid="pulsante-della-voce"
                            @click="emit('apre', v.collegamento.apre)">{{ v.collegamento.testo }}</button>
                        <a v-else-if="v.collegamento.url?.startsWith('#')" :href="v.collegamento.url" :class="pulsanteDellaVoce(v)" data-testid="pulsante-della-voce"
                            @click="ancora(v.collegamento.url)">{{ v.collegamento.testo }}</a>
                        <a v-else-if="v.collegamento.url && v.collegamento.stampa" :href="v.collegamento.url" target="_blank" rel="noopener" :class="pulsanteDellaVoce(v)"
                            data-testid="pulsante-della-voce" @click="emit('stampa')">{{ v.collegamento.testo }}</a>
                        <Link v-else-if="v.collegamento.url" :href="v.collegamento.url" :class="pulsanteDellaVoce(v)" data-testid="pulsante-della-voce">{{ v.collegamento.testo }}</Link>
                        <button v-else-if="v.collegamento.passo" type="button" :class="pulsanteDellaVoce(v)" data-testid="pulsante-della-voce"
                            @click="emit('apri', v.collegamento.passo)">{{ v.collegamento.testo }}</button>
                    </div>
                </li>
            </ul>
        </div>
        <div class="border-t border-slate-100 bg-slate-50/60 px-3 py-4 sm:px-5 dark:border-slate-800 dark:bg-slate-900/30" data-testid="contenuto-del-passo">
            <slot />
        </div>

        <!-- Il piede: il passo prima, la frase, «Avanti» -->
        <div class="flex flex-col gap-2.5 border-t border-slate-100 px-4 py-3 sm:flex-row sm:items-center sm:justify-between sm:px-5 dark:border-slate-800" data-testid="piede-del-passo">
            <p class="text-xs text-slate-500 sm:order-2 sm:flex-1 sm:text-center dark:text-slate-400">{{ frase }}</p>
            <div class="sm:order-3">
                <slot name="azione">
                    <button v-if="dopo" type="button" :class="[pulsanteDelPiede, 'bg-slate-900 font-semibold text-white disabled:cursor-not-allowed disabled:opacity-55 dark:bg-white dark:text-slate-900']"
                        :disabled="!avantiAttivo" :title="percheAvanti" data-testid="avanti" @click="dopo && apri(dopo)">
                        Avanti: {{ dopo.nome }}<ArrowRight class="h-3.5 w-3.5" aria-hidden="true" />
                    </button>
                </slot>
            </div>
            <div v-if="prima" class="sm:order-1">
                <button type="button" :class="[pulsanteDelPiede, 'border border-slate-200 bg-white font-medium text-slate-700 hover:bg-slate-50 disabled:cursor-not-allowed disabled:opacity-55 dark:border-slate-800 dark:bg-slate-900 dark:text-slate-300 dark:hover:bg-slate-800']"
                    :disabled="!siApre(prima)" :title="siApre(prima) ? undefined : prima.perche ?? undefined" data-testid="passo-prima" @click="apri(prima)">
                    <ArrowLeft class="h-3.5 w-3.5" aria-hidden="true" />{{ prima.nome }}
                </button>
            </div>
        </div>
    </section>
</template>
