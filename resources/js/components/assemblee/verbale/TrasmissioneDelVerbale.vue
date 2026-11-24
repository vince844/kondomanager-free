<!--
SPDX-License-Identifier: AGPL-3.0-or-later
Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
-->
<script setup lang="ts">
/**
 * «La trasmissione e i trenta giorni» (scena 3 della bozza del verbale, scena 6 della bozza delle scene, decisione 595 (3)):
 *
 * - **Chi riceve il verbale**: la lista proposta dai dati della sala, in gruppi (chi non è mai entrato; chi era fuori al voto di un punto, con i punti; chi
 *   è stato aggiunto; in videoconferenza o mista tutti), con il mezzo e la prova della convocazione; «Togli dalla lista» nel «⋯» della riga, «Aggiungi una
 *   persona» in cima.
 * - **Le azioni della convocazione**: «Manda le PEC (5)», «Stampa le lettere (11)», «Registra la spedizione»: pulsanti piccoli e chiari (alti 28 px, scritta
 *   a 12), a tutta riga al telefono; scuro, e piccolo anche lui, solo il gesto principale (rilievi C28 e C60, `gestoPrincipale`). «Registra la consegna» è nel «⋯» della riga.
 * - **I trenta giorni** per persona e per punto (art. 1137 c. 2 c.c.): «dalla consegna», «fino al 14/01/2027», «favorevole, nessun termine», con il conto
 *   prudente; sotto, chi c'era (contrari, astenuti, chi non ha votato) dalla delibera, e «Segna impugnata» su ogni delibera.
 * - **La copia di prima**: dopo «Sostituisci la copia», chi l'aveva già riceve l'avviso «N hanno la copia di prima: rimandala», con l'elenco (decisione 615 (6));
 *   «Rimanda la copia nuova» apre prima una finestra piccola con chi la riceve e come (`RimandaLaCopia`), e manda solo con «Manda».
 *
 * **La riga di ogni persona** (la prova a video del 22/11/2026, notte: il badge attaccato al testo, le colonne sparse): dal portatile in su colonne con la loro
 * intestazione e le stesse larghezze per ogni riga, «Chi» (il nome e l'unità), «Perché lo riceve», «Mezzo», «Stato» (il badge, con il giorno sotto), «Può
 * impugnare» («punti 1–3, fino al 21/12/2026») e il «⋯» con i gesti della riga («Registra la consegna», «Togli dalla lista»). Al telefono ogni persona è una scheda con
 * le stesse cose, una sotto l'altra, e il «⋯» in alto a destra.
 */
import { computed, ref } from 'vue';
import axios from 'axios';
import { router } from '@inertiajs/vue3';
import { AlertTriangle, Loader2, MoreHorizontal } from 'lucide-vue-next';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import RegistraLaConsegna from '@/components/assemblee/convocazione/RegistraLaConsegna.vue';
import RegistraLaSpedizione from '@/components/assemblee/convocazione/RegistraLaSpedizione.vue';
import { apriPdfInUnaScheda, fraseDaErroreConFile } from '@/lib/gestionale/incassi/ricevute';
import { BADGE, CLASSI_DEL_BADGE } from '@/lib/assemblee/assemblee';
import {
    CALCOLO_PRUDENTE, conLaCopiaDiPrima, gestoPrincipale, giorno, gruppiDeiDestinatari, mezzoDellaConsegna, nomeDellaDelibera, righeDeiTrentaGiorni, stampateConLaCopiaDiPrima,
} from '@/lib/assemblee/verbale/pagina';
import AggiungiUnaPersona from './AggiungiUnaPersona.vue';
import RimandaLaCopia from './RimandaLaCopia.vue';
import SegnaImpugnata from './SegnaImpugnata.vue';
import TogliDallaLista from './TogliDallaLista.vue';
import { ARANCIO, CARD, PICCOLO, PRINCIPALE_PICCOLO, percorso } from './finestra';
import type { DestinatarioDelVerbale, PaginaDelVerbale, VerbaleDellaPagina } from '@/types/verbale';

const props = defineProps<{ verbale: VerbaleDellaPagina; rotte: PaginaDelVerbale['rotte']; puoGestire: boolean }>();
const t = computed(() => props.verbale.trasmissione!);
const g = computed(() => gruppiDeiDestinatari(t.value));
const ricevono = computed(() => t.value.persone.filter((p) => p.riceve));
const daSpedire = (mezzi: string[]) => ricevono.value.filter((p) => mezzi.includes(p.mezzo.mezzo) && !p.invio?.spedito);
const pec = computed(() => daSpedire(['pec']));
const lettere = computed(() => daSpedire(['raccomandata', 'a_mano', 'mano', 'fax']));
const copiaDiPrima = computed(() => conLaCopiaDiPrima(t.value, props.verbale.copia?.sostituita_il));
const stampateDiPrima = computed(() => stampateConLaCopiaDiPrima(t.value, props.verbale.copia?.sostituita_il));
/** Il gesto principale: le lettere ancora da stampare; altrimenti le PEC da mandare; altrimenti la spedizione delle lettere stampate; altrimenti nessuno. */
const principale = computed(() => gestoPrincipale(t.value));
const perTutti = computed(() => props.verbale.termini?.per_tutti ?? null);
const inCorso = ref<string | null>(null);
const messaggio = ref('');

const ricarica = () => router.reload({ only: ['verbale', 'flash'] });
function mandaLePec(anagrafiche?: number[], diNuovo = false) {
    if (!props.rotte.pec) return;
    inCorso.value = 'pec';
    router.post(props.rotte.pec, { ...(anagrafiche ? { anagrafiche } : {}), ...(diNuovo ? { di_nuovo: true } : {}) },
        { preserveScroll: true, onFinish: () => { inCorso.value = null; } });
}
function stampaLeLettere(anagrafiche?: number[], diNuovo = false) {
    if (!props.rotte.lettere) return;
    inCorso.value = 'lettere';
    messaggio.value = '';
    apriPdfInUnaScheda(() => axios.post(props.rotte.lettere!, { ...(anagrafiche ? { anagrafiche } : {}), ...(diNuovo ? { di_nuovo: true } : {}) },
        { responseType: 'blob' }), 'lettere-del-verbale.pdf')
        .then(() => ricarica())
        .catch(async (e) => { messaggio.value = await fraseDaErroreConFile(e, 'Le lettere non si sono stampate. Riprova.'); })
        .finally(() => { inCorso.value = null; });
}
const finestraRimanda = ref(false);
function rimanda() {
    const ids = (mezzi: string[]) => copiaDiPrima.value.filter((p) => mezzi.includes(p.mezzo.mezzo)).map((p) => p.anagrafica_id);
    if (ids(['pec']).length) mandaLePec(ids(['pec']), true);
    if (ids(['raccomandata', 'a_mano', 'mano', 'fax']).length) stampaLeLettere(ids(['raccomandata', 'a_mano', 'mano', 'fax']), true);
}

// La riga nella forma della consegna e della spedizione della convocazione, che le finestre conoscono.
const comeRiga = (p: DestinatarioDelVerbale) => ({ chiave: String(p.anagrafica_id), anagrafica_id: p.anagrafica_id, nome: p.nome, unita: p.unita, mezzo: mezzoDellaConsegna(p.mezzo.mezzo),
    mezzo_parola: p.mezzo.parola, invio_id: p.invio?.id ?? null, esito: p.invio?.esito ?? null, ricevuta_il: p.invio?.ricevuta_il ?? null }) as never;
const consegna = ref<DestinatarioDelVerbale | null>(null);
const finestraConsegna = computed({ get: () => consegna.value !== null, set: (v: boolean) => { if (!v) { consegna.value = null; ricarica(); } } });
const finestraSpedizione = ref(false);
const daRegistrareSpedite = computed(() => ricevono.value.filter((p) => p.invio?.stato === 'stampata'));
const togli = ref<DestinatarioDelVerbale | null>(null);
const finestraTogli = computed({ get: () => togli.value !== null, set: (v: boolean) => { if (!v) togli.value = null; } });
const finestraAggiungi = ref(false);
const impugnata = ref<{ punto: number; delibera: number } | null>(null);
const finestraImpugnata = computed({ get: () => impugnata.value !== null, set: (v: boolean) => { if (!v) impugnata.value = null; } });
const primaDelibera = computed(() => t.value.punti[0]?.deliberata_il ?? null);

/** Lo stato della riga: la parola del badge e, sotto, il giorno («Consegnata», «il 21/11/2026»), che nel badge lo allungava oltre la sua colonna. */
const statoDi = (p: DestinatarioDelVerbale): { testo: string; colore: string; giorno: string | null } => {
    if (!p.invio) return { testo: 'Da spedire', colore: CLASSI_DEL_BADGE.ambra, giorno: null };
    if (p.invio.ricevuta_il) return { testo: 'Consegnata', colore: CLASSI_DEL_BADGE.verde, giorno: `il ${giorno(p.invio.ricevuta_il)}` };
    if (p.invio.esito_parola) return { testo: p.invio.esito_parola, colore: CLASSI_DEL_BADGE.blu, giorno: null };
    if (p.invio.spedito) return { testo: 'Spedita', colore: CLASSI_DEL_BADGE.blu, giorno: null };
    if (p.invio.stato === 'stampata') return { testo: 'Stampata', colore: CLASSI_DEL_BADGE.neutro, giorno: null };
    return { testo: p.invio.errore ? 'Non partita' : 'Da spedire', colore: CLASSI_DEL_BADGE.ambra, giorno: null };
};
/** I gesti della riga, nel suo «⋯»: la consegna di chi è partito e non ha ancora ricevuto; «Togli dalla lista» per chi è proposto e non ancora partito. */
const puoRegistrareLaConsegna = (p: DestinatarioDelVerbale) => !!(p.invio && p.invio.spedito && !p.invio.ricevuta_il);
const puoTogliere = (p: DestinatarioDelVerbale) => p.proposto && !p.invio?.spedito;
/** Le colonne dal portatile in su, le stesse per l'intestazione e per ogni riga: chi, perché, mezzo, stato, «Può impugnare», il «⋯». */
const COLONNE = 'lg:grid-cols-[minmax(0,1.2fr)_minmax(0,1.2fr)_6.5rem_8.5rem_minmax(0,1.4fr)_2rem]';
const gruppi = computed(() => [
    { chiave: 'tutti', titolo: 'In videoconferenza o mista: tutti i convocati', persone: g.value.tutti },
    { chiave: 'mai_entrati', titolo: 'Mai in sala', persone: g.value.mai_entrati },
    { chiave: 'fuori', titolo: 'Fuori al voto di un punto', persone: g.value.fuori },
    { chiave: 'aggiunti', titolo: 'Aggiunti da te', persone: g.value.aggiunti },
].filter((x) => x.persone.length));
const chiCera = computed(() => (props.verbale.termini?.delibere ?? []).filter((d) => d.chi.some((c) => c.perche !== 'assente')));
</script>

<template>
    <section id="trasmissione" :class="[CARD, 'scroll-mt-4 space-y-4 px-4 py-4']" data-testid="la-trasmissione">
        <div class="flex flex-wrap items-start justify-between gap-2">
            <div class="min-w-0">
                <h2 class="flex items-center gap-2 text-[15px] font-semibold text-slate-900 dark:text-white">Chi riceve il verbale n. {{ verbale.numero }}
                    <span :class="[BADGE, CLASSI_DEL_BADGE.neutro]">{{ t.quanti }}</span></h2>
                <p class="mt-0.5 text-xs text-slate-600 dark:text-slate-400">{{ t.videoconferenza ? 'In videoconferenza o mista il verbale va a tutti i convocati.'
                    : 'Proposta dai dati della sala: chi non è mai entrato, e chi era fuori al voto di un punto, per quel punto. Prima di spedire togli o aggiungi chi vuoi.' }}</p>
                <p class="text-xs text-slate-500 dark:text-slate-400">Lo stesso mezzo e la stessa prova della convocazione · in busta o per PEC, mai in bacheca.</p>
            </div>
        </div>
        <div v-if="puoGestire" class="flex flex-col gap-2 sm:flex-row sm:flex-wrap" data-testid="gesti-della-trasmissione">
            <!-- Piccoli e chiari come nella convocazione (rilievo C28); scuro solo il gesto principale, se c'è: le lettere da stampare, o le PEC da mandare. -->
            <button type="button" :class="[principale === 'lettere' ? PRINCIPALE_PICCOLO : PICCOLO, 'w-full sm:w-auto']" :disabled="lettere.length === 0 || inCorso !== null"
                data-testid="stampa-le-lettere" @click="stampaLeLettere()">
                <Loader2 v-if="inCorso === 'lettere'" class="h-3.5 w-3.5 animate-spin" aria-hidden="true" />Stampa le lettere ({{ lettere.length }})</button>
            <button type="button" :class="[principale === 'pec' ? PRINCIPALE_PICCOLO : PICCOLO, 'w-full sm:w-auto']" :disabled="pec.length === 0 || inCorso !== null" data-testid="manda-le-pec"
                @click="mandaLePec()">
                <Loader2 v-if="inCorso === 'pec'" class="h-3.5 w-3.5 animate-spin" aria-hidden="true" />Manda le PEC ({{ pec.length }})</button>
            <button type="button" :class="[principale === 'spedizione' ? PRINCIPALE_PICCOLO : PICCOLO, 'w-full sm:w-auto']" :disabled="daRegistrareSpedite.length === 0"
                data-testid="registra-la-spedizione" @click="finestraSpedizione = true">Registra la spedizione</button>
            <button type="button" :class="[PICCOLO, 'w-full sm:w-auto']" data-testid="aggiungi-una-persona" @click="finestraAggiungi = true">Aggiungi una persona</button>
            <a v-if="rotte.prova" :href="rotte.prova" target="_blank" rel="noopener" :class="[PICCOLO, 'w-full sm:w-auto']" data-testid="la-prova-della-trasmissione">La prova della trasmissione</a>
        </div>
        <p v-if="messaggio" class="text-sm text-red-600 dark:text-red-500">{{ messaggio }}</p>

        <!-- La copia sostituita dopo la trasmissione: chi ha quella di prima -->
        <div v-if="stampateDiPrima.length" :class="ARANCIO" data-testid="stampate-con-la-copia-di-prima">
            <AlertTriangle class="mt-0.5 h-3.5 w-3.5 shrink-0" aria-hidden="true" />
            <div class="min-w-0 flex-1 space-y-1.5">
                <p class="font-semibold">{{ stampateDiPrima.length === 1 ? '1 lettera stampata' : `${stampateDiPrima.length} lettere stampate` }} con la copia di prima:
                    ristampale prima di spedirle.</p>
                <p>{{ stampateDiPrima.map((p) => p.nome).join(', ') }}.</p>
                <button v-if="puoGestire" type="button" :class="PICCOLO" :disabled="inCorso !== null" data-testid="ristampa-le-lettere"
                    @click="stampaLeLettere(stampateDiPrima.map((p) => p.anagrafica_id), true)">Ristampa le lettere</button>
            </div>
        </div>
        <div v-if="copiaDiPrima.length" :class="ARANCIO" data-testid="copia-di-prima">
            <AlertTriangle class="mt-0.5 h-3.5 w-3.5 shrink-0" aria-hidden="true" />
            <div class="min-w-0 flex-1 space-y-1.5">
                <p class="font-semibold">{{ copiaDiPrima.length === 1 ? '1 ha' : `${copiaDiPrima.length} hanno` }} la copia di prima: rimandala.</p>
                <p>{{ copiaDiPrima.map((p) => `${p.nome} (${p.mezzo.parola.toLowerCase()})`).join(', ') }}.</p>
                <button v-if="puoGestire" type="button" :class="PICCOLO" :disabled="inCorso !== null" data-testid="rimanda-la-copia" @click="finestraRimanda = true">Rimanda la copia nuova</button>
            </div>
        </div>

        <div v-for="gr in gruppi" :key="gr.chiave" class="space-y-1.5" :data-gruppo="gr.chiave">
            <p class="text-xs font-semibold text-slate-500 dark:text-slate-400">{{ gr.titolo }} · {{ gr.persone.length }}</p>
            <!-- Dal portatile in su una tabella con l'intestazione; al telefono una scheda per persona, con il «⋯» in alto a destra. -->
            <div class="lg:overflow-hidden lg:rounded-lg lg:border lg:border-slate-200 lg:dark:border-slate-800">
                <div :class="['hidden gap-x-3 border-b border-slate-200 bg-slate-50 px-3 py-2 text-xs font-medium text-slate-500 lg:grid dark:border-slate-800 dark:bg-slate-900/40 dark:text-slate-400', COLONNE]"
                    data-testid="intestazione-trasmissione">
                    <span>Chi</span><span>Perché lo riceve</span><span>Mezzo</span><span>Stato</span><span>Può impugnare</span><span class="sr-only">Azioni</span>
                </div>
                <ul class="space-y-2 lg:space-y-0 lg:divide-y lg:divide-slate-100 lg:dark:divide-slate-800">
                    <li v-for="p in gr.persone" :key="p.anagrafica_id"
                        :class="['relative grid gap-x-3 gap-y-1 rounded-lg border border-slate-200 px-3 py-2.5 text-xs lg:items-start lg:rounded-none lg:border-0 dark:border-slate-800', COLONNE]"
                        data-testid="persona-della-trasmissione" :data-anagrafica="p.anagrafica_id">
                        <span class="min-w-0 pr-9 lg:pr-0" data-colonna="chi"><b class="block break-words text-sm font-medium text-slate-900 dark:text-white">{{ p.nome }}</b>
                            <span class="text-slate-500 dark:text-slate-400">{{ p.unita }}</span></span>
                        <span class="min-w-0 break-words text-slate-700 dark:text-slate-300" data-colonna="perche">{{ p.perche }}<template v-if="!p.convocato"> · non convocato<template v-if="p.mezzo.perche">: {{ p.mezzo.perche }}</template></template></span>
                        <span class="text-slate-700 dark:text-slate-300" data-colonna="mezzo">{{ p.mezzo.parola }}</span>
                        <span class="flex flex-col items-start gap-0.5" data-colonna="stato">
                            <span :class="[BADGE, statoDi(p).colore]" data-testid="badge-stato">{{ statoDi(p).testo }}</span>
                            <span v-if="statoDi(p).giorno" class="tabular-nums text-slate-500 dark:text-slate-400">{{ statoDi(p).giorno }}</span>
                        </span>
                        <span class="min-w-0" data-colonna="impugnare">
                            <span class="font-medium text-slate-500 lg:hidden dark:text-slate-400" data-testid="etichetta-al-telefono">Può impugnare</span>
                            <span class="block space-y-0.5 text-slate-700 dark:text-slate-300" data-testid="trenta-giorni">
                                <span v-for="r in righeDeiTrentaGiorni(p)" :key="r" class="block break-words">{{ r }}</span>
                            </span>
                        </span>
                        <span class="absolute right-2 top-2 lg:static lg:flex lg:justify-end" data-colonna="azioni">
                            <DropdownMenu v-if="puoGestire && (puoRegistrareLaConsegna(p) || puoTogliere(p))">
                                <DropdownMenuTrigger as-child>
                                    <button type="button" :class="[PICCOLO, 'w-7 px-0']" :aria-label="`Altre azioni per ${p.nome}`" data-testid="menu-della-persona">
                                        <MoreHorizontal class="h-4 w-4" aria-hidden="true" /></button>
                                </DropdownMenuTrigger>
                                <DropdownMenuContent align="end" class="w-52">
                                    <DropdownMenuItem v-if="puoRegistrareLaConsegna(p)" data-testid="voce-registra-la-consegna" @select="consegna = p">Registra la consegna</DropdownMenuItem>
                                    <DropdownMenuItem v-if="puoTogliere(p)" data-testid="voce-togli" @select="togli = p">Togli dalla lista</DropdownMenuItem>
                                </DropdownMenuContent>
                            </DropdownMenu>
                        </span>
                    </li>
                </ul>
            </div>
        </div>

        <div v-if="g.tolti.length" class="space-y-1" data-gruppo="tolti">
            <p class="text-xs font-semibold text-slate-500 dark:text-slate-400">Tolti dalla lista · {{ g.tolti.length }}</p>
            <ul class="space-y-0.5 text-xs text-slate-700 dark:text-slate-300">
                <li v-for="p in g.tolti" :key="p.anagrafica_id">{{ p.nome }}: «{{ p.tolto?.perche }}»<template v-if="p.tolto?.da"> · {{ p.tolto.da }}</template> ·
                    {{ righeDeiTrentaGiorni(p).join('; ') }}</li>
            </ul>
        </div>

        <!-- Chi c'era: i trenta giorni dalla delibera -->
        <div v-if="chiCera.length" class="space-y-1.5 rounded-lg bg-slate-50 px-3 py-2.5 dark:bg-slate-900/40" data-testid="chi-cera">
            <p class="text-xs font-semibold text-slate-500 dark:text-slate-400">Chi c'era: i trenta giorni dalla delibera</p>
            <div v-for="d in chiCera" :key="d.delibera_id" class="flex flex-col gap-1 text-xs text-slate-700 sm:flex-row sm:items-start sm:justify-between dark:text-slate-300">
                <p class="min-w-0 break-words"><b class="font-semibold">{{ nomeDellaDelibera(d) }}</b> · {{ d.chi.filter((c) => c.perche !== 'assente').map((c) => `${c.nome} (${c.perche === 'non_votato'
                    ? 'non ha votato, conta astenuto' : c.perche})`).join(', ') }}<template v-if="d.chi.find((c) => c.fino_al && c.perche !== 'assente')"> · fino al {{
                    giorno(d.chi.find((c) => c.fino_al && c.perche !== 'assente')!.fino_al) }}</template></p>
                <button v-if="puoGestire" type="button" class="shrink-0 text-xs font-medium underline underline-offset-2" data-testid="segna-impugnata"
                    @click="impugnata = { punto: d.punto, delibera: d.delibera_id }">Segna impugnata</button>
            </div>
            <p class="text-xs text-slate-500 dark:text-slate-400">I favorevoli non hanno termine. Non ricevono il verbale: c'erano.</p>
        </div>

        <p class="text-xs text-slate-600 dark:text-slate-400" data-testid="per-tutti">
            {{ t.consegnati }} di {{ t.quanti }} consegnati · <template v-if="perTutti">per tutti i trenta giorni finiscono il {{ giorno(perTutti) }}</template>
            <template v-else>i trenta giorni di tutti si conoscono all'ultima consegna</template> · {{ CALCOLO_PRUDENTE }}: dal giorno dopo, agosto non conta, l'ultimo
            giorno festivo passa al primo lavorativo.
        </p>

        <RegistraLaConsegna v-model:open="finestraConsegna" :riga="consegna ? comeRiga(consegna) : null" :rotta="percorso.consegna(rotte.assemblea)"
            :rotta-ricevuta="percorso.ricevuta(rotte.assemblea)" />
        <RegistraLaSpedizione v-if="rotte.spedizione" cosa="verbale" :open="finestraSpedizione" :righe="daRegistrareSpedite.map(comeRiga)" :scelte="null" :rotta="rotte.spedizione"
            @update:open="(v: boolean) => { finestraSpedizione = v; if (!v) ricarica(); }" />
        <TogliDallaLista v-if="rotte.togli" v-model:open="finestraTogli" :persona="togli" :rotta="rotte.togli" :giorno-della-delibera="giorno(primaDelibera)" />
        <AggiungiUnaPersona v-if="rotte.aggiungi" v-model:open="finestraAggiungi" :trasmissione="t" :rotta="rotte.aggiungi" />
        <RimandaLaCopia v-model:open="finestraRimanda" :persone="copiaDiPrima" :numero="verbale.numero" @manda="rimanda" />
        <SegnaImpugnata v-model:open="finestraImpugnata" :punto="impugnata?.punto ?? null" :rotta="impugnata ? percorso.impugnata(rotte.assemblea, impugnata.delibera) : ''" />
    </section>
</template>
