<!--
SPDX-License-Identifier: AGPL-3.0-or-later
Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
-->
<script setup lang="ts">
/**
 * Il passo 4 «In sala» della pagina dell'assemblea (scene 1, 4, 5, 6 e 7 della bozza delle scene della tappa 3; decisione 589, T2).
 *
 * - **Prima del giorno**: il riquadro in testa dice l'ora della prima convocazione («La prima convocazione è domenica 22/11/2026 alle 08:00: quel giorno
 *   qui si apre la sala»), come l'ha scritta l'amministratore, senza conversione di fuso. «Deleghe ricevute» (`ElencoDelleDeleghe`, lo stesso elenco del
 *   cassetto che apre la card «Vedi le deleghe»), con «Segna una delega ricevuta» e il conto del quinto per ogni delegato, sui titolari di oggi; accanto il
 *   posto «Dal portale dei condòmini», vuoto e spiegato; «Il foglio della sala» da stampare il giorno prima, con le deleghe già scritte.
 * - **Dal giorno**: «Apri la sala», «Segna deserta la prima convocazione» (dallo studio, senza aprire l'assemblea) e «Registra dal foglio della sala».
 * - **Durante**: «Torna in sala»; sospesa, «Sospesa · prosegue …», e «Riapri la sala» quel giorno.
 * - **Dopo**: chiusa all'ora, con «Le presenze e i voti» e la sala in sola lettura.
 *
 * Le due stampe hanno le loro rotte (il foglio e il prospetto), nella prop della sala. Il foglio dice se è già stampato e, quando una delega è cambiata
 * dopo la stampa, «ristampa il foglio»; la scansione di una delega si apre con «Apri».
 */
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import { BADGE, CLASSI_DEL_BADGE } from '@/lib/assemblee/assemblee';
import type { SalaDellAssemblea } from '@/types/assemblee';
import { giornoDellaSeduta, giornoDiRoma, oraDiRoma } from './sala';
import ElencoDelleDeleghe from './ElencoDelleDeleghe.vue';

const props = defineProps<{ sala: SalaDellAssemblea; apriLaSala: { attiva: boolean; perche: string; url?: string; testo?: string }; puoGestire: boolean;
    condominioId: number; assembleaId: number }>();

const stato = computed(() => props.sala.assemblea.stato);
const prima = computed(() => props.sala.sedute.find((s) => s.convocazione === 'prima' && s.numero === 1) ?? null);
const daAprire = computed(() => props.sala.da_aprire);
const sedutaDaAprire = computed(() => props.sala.sedute.find((s) => s.id === daAprire.value?.seduta_id) ?? null);
const sospesa = computed(() => (stato.value === 'in_corso' && props.sala.seduta_id === null
    ? [...props.sala.sedute].filter((s) => s.esito === 'sospesa').sort((a, b) => (b.chiusa_il ?? '').localeCompare(a.chiusa_il ?? ''))[0] ?? null : null));
const chiusa = computed(() => stato.value === 'chiusa');
const dalGiorno = computed(() => stato.value === 'convocata' && daAprire.value?.si_apre === true);
const delleDeleghe = computed(() => ['convocata', 'in_corso'].includes(stato.value));
const ultimaChiusura = computed(() => [...props.sala.sedute].filter((s) => s.chiusa_il).sort((a, b) => (b.chiusa_il ?? '').localeCompare(a.chiusa_il ?? ''))[0] ?? null);

const titolo = computed(() => {
    if (chiusa.value) return `Chiusa alle ${oraDiRoma(ultimaChiusura.value?.chiusa_il)}`;
    if (stato.value === 'deserta') return 'Deserta in prima e in seconda convocazione';
    if (sospesa.value && sedutaDaAprire.value) return `Sospesa · prosegue ${giornoDellaSeduta(sedutaDaAprire.value.giorno)} alle ${sedutaDaAprire.value.ora}`;
    if (stato.value === 'in_corso') return 'La sala è aperta';
    if (dalGiorno.value) return prima.value?.esito === 'deserta' && sedutaDaAprire.value
        ? `Prima deserta · la seconda ${giornoDellaSeduta(sedutaDaAprire.value.giorno)} alle ${sedutaDaAprire.value.ora}` : 'La sala si apre oggi';

    // Prima del giorno il riquadro non ripete la card «Apri la sala»: dice l'ora della prima convocazione, scritta dall'amministratore (non si converte).
    return prima.value ? `La prima convocazione è ${giornoDellaSeduta(prima.value.giorno)} alle ${prima.value.ora}: quel giorno qui si apre la sala` : 'La sala';
});

const foglio = computed(() => props.sala.rotte.foglio ?? null);
const prospetto = computed(() => props.sala.rotte.prospetto ?? null);
/** «Non ancora stampato», «Stampato il 13/12/2026 alle 17:00», e «ristampa il foglio» se una delega è cambiata dopo la stampa. */
const stampato = computed(() => {
    const il = props.sala.foglio?.stampato_il;
    if (!il) return 'Non ancora stampato';
    const [anno, mese, giorno] = giornoDiRoma(new Date(il)).split('-');

    return `Stampato il ${giorno}/${mese}/${anno} alle ${oraDiRoma(il)}`;
});
const daRistampare = computed(() => props.sala.foglio?.da_ristampare === true);
const urlDellaSala = computed(() => props.apriLaSala.url ?? props.sala.rotte.pagina);

const pulsante = 'inline-flex h-7 items-center justify-center rounded-md border border-slate-200 bg-white px-3 text-xs font-medium text-slate-700 hover:bg-slate-50 disabled:cursor-not-allowed disabled:opacity-50 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-200 dark:hover:bg-slate-800';
const principale = 'inline-flex h-8 items-center justify-center rounded-md bg-slate-900 px-3 text-xs font-semibold text-white hover:bg-slate-800 dark:bg-white dark:text-slate-900';
</script>

<template>
    <div class="space-y-4" data-testid="passo-in-sala">
        <section class="flex flex-col gap-3 rounded-xl border border-slate-200 bg-white px-4 py-4 sm:flex-row sm:items-center sm:justify-between dark:border-slate-800 dark:bg-slate-950">
            <div class="min-w-0">
                <h3 class="text-base font-semibold text-slate-900 dark:text-white" data-testid="riquadro-della-sala">{{ titolo }}</h3>
                <p v-if="sospesa && sedutaDaAprire" class="text-xs text-slate-600 dark:text-slate-400">
                    «Riapri la sala» si accende {{ giornoDellaSeduta(sedutaDaAprire.giorno) }}.
                </p>
                <p v-else-if="chiusa" class="text-xs text-slate-600 dark:text-slate-400">«Le presenze e i voti» è il prospetto con nomi e millesimi che il verbale richiama.</p>
            </div>
            <div class="flex flex-wrap gap-2">
                <template v-if="dalGiorno && puoGestire">
                    <Link :href="`${urlDellaSala}?foglio=1`" :class="pulsante" data-testid="registra-dal-foglio">Registra dal foglio della sala</Link>
                    <Link v-if="prima?.esito !== 'deserta'" :href="`${urlDellaSala}?deserta=1`" :class="pulsante" data-testid="segna-deserta">Segna deserta la prima convocazione</Link>
                </template>
                <template v-if="chiusa">
                    <a v-if="prospetto" :href="prospetto" target="_blank" rel="noopener" :class="pulsante" data-testid="presenze-e-voti">Le presenze e i voti</a>
                    <button v-else type="button" :class="pulsante" disabled title="La stampa arriva con il prospetto della sala." data-testid="presenze-e-voti">Le presenze e i voti</button>
                </template>
                <Link v-if="apriLaSala.attiva" :href="urlDellaSala" :class="principale" data-testid="apri-la-sala">
                    {{ chiusa ? 'Torna in sala, in sola lettura' : apriLaSala.testo ?? 'Apri la sala' }}
                </Link>
            </div>
        </section>

        <template v-if="delleDeleghe">
            <div class="grid gap-4 lg:grid-cols-[minmax(0,2fr)_minmax(0,1fr)]">
                <!-- Deleghe ricevute: lo stesso elenco del cassetto che apre la card «Vedi le deleghe» -->
                <section id="deleghe-ricevute" class="scroll-mt-4 rounded-xl border border-slate-200 bg-white px-4 py-4 dark:border-slate-800 dark:bg-slate-950">
                    <ElencoDelleDeleghe :sala="sala" :puo-gestire="puoGestire" :condominio-id="condominioId" :assemblea-id="assembleaId" forma="sezione" />
                </section>

                <div class="space-y-4">
                    <!-- Dal portale dei condòmini: vuoto, finché il portale non le manda -->
                    <section class="space-y-1.5 rounded-xl border border-dashed border-slate-300 bg-white px-4 py-4 dark:border-slate-700 dark:bg-slate-950" data-testid="dal-portale">
                        <div class="flex items-center justify-between gap-2">
                            <h3 class="text-sm font-semibold text-slate-900 dark:text-white">Dal portale dei condòmini</h3>
                            <span :class="[BADGE, CLASSI_DEL_BADGE.neutro]">Nessuna</span>
                        </div>
                        <ul class="list-disc space-y-0.5 pl-4 text-xs text-slate-600 dark:text-slate-400">
                            <li>Qui arriveranno le deleghe che i condòmini mandano dal loro portale.</li>
                            <li>Si accettano con un clic, con lo stesso conto del quinto.</li>
                            <li>Oggi le deleghe arrivano su carta, per email o a mano: le segni con «Segna una delega ricevuta».</li>
                        </ul>
                    </section>

                    <!-- Il foglio della sala -->
                    <section id="foglio-della-sala" class="scroll-mt-4 space-y-2 rounded-xl border border-slate-200 bg-white px-4 py-4 dark:border-slate-800 dark:bg-slate-950">
                        <!-- La testa come quella delle deleghe ricevute (Vincenzo, 21/11/2026 sera): il titolo con il badge, a destra il pulsante piccolo; al telefono
                             il pulsante va sotto, a tutta riga. -->
                        <div class="flex flex-col gap-2 sm:flex-row sm:flex-nowrap sm:items-start sm:justify-between" data-testid="testa-foglio">
                            <h3 class="flex flex-wrap items-center gap-2 text-sm font-semibold text-slate-900 dark:text-white">Il foglio della sala
                                <span :class="[BADGE, CLASSI_DEL_BADGE[daRistampare ? 'ambra' : sala.foglio?.stampato_il ? 'verde' : 'neutro'], 'font-normal']" data-testid="foglio-stampato">{{ stampato }}</span></h3>
                            <a v-if="foglio" :href="foglio" target="_blank" rel="noopener" :class="[pulsante, 'w-full shrink-0 sm:w-auto']" data-testid="stampa-foglio">{{ daRistampare ? 'Ristampa il foglio' : 'Stampa il foglio' }}</a>
                            <button v-else type="button" :class="[pulsante, 'w-full shrink-0 sm:w-auto']" disabled title="La stampa arriva con il foglio della sala." data-testid="stampa-foglio">Stampa il foglio</button>
                        </div>
                        <p v-if="daRistampare" class="text-xs font-medium text-amber-800 dark:text-amber-300" data-testid="ristampa-il-foglio">
                            Una delega è cambiata dopo la stampa: ristampa il foglio.
                        </p>
                        <ul class="list-disc space-y-0.5 pl-4 text-xs text-slate-600 dark:text-slate-400">
                            <li>Se la rete o la corrente mancano, la sala si tiene sulla carta e si registra dopo.</li>
                            <li>Si stampa il giorno prima: le deleghe ricevute sono già scritte nella colonna «Presente o delega a».</li>
                            <li>Se arriva una delega dopo la stampa, ristampalo.</li>
                        </ul>
                    </section>
                </div>
            </div>
        </template>
    </div>
</template>
