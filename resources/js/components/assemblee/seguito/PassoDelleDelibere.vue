<!--
SPDX-License-Identifier: AGPL-3.0-or-later
Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
-->
<script setup lang="ts">
/**
 * Il passo 6 «Delibere eseguite» (scena 1 della bozza della tappa), e lo stesso seguito nella pagina della riunione tenuta su carta (scena 5), che la strada
 * non ha:
 *
 * - finché il seguito non arriva (una visita parziale, come il «Diario»), «Un momento…»;
 * - prima del verbale registrato, la frase del lucchetto;
 * - con delibere da confermare, **il riepilogo** «Che cosa cambia nel gestionale»; confermate, **la tabella** con l'esito di ogni riga della conferma in testa;
 * - **il verbale annullato** dopo la conferma: «Il verbale è annullato: le delibere applicate restano», con le schede che le usano; niente si disfa da solo,
 *   e le cose aperte restano nella tabella;
 * - **tre card in testa** (decisione 645), nella strada e nella riunione su carta: «Da fare adesso» con il suo pulsante, «Le delibere» con i conti (che erano nel
 *   badge giallo della testa del passo) e «Trenta giorni».
 *
 * La finestra «Crea il piano straordinario», che si apre dalla cosa scelta (`cosa` nell'indirizzo), la monta la pagina dell'assemblea una volta sola, per la
 * strada e per la riunione su carta.
 */
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import { AlertTriangle, Check, Clock, Gavel, ShieldCheck } from 'lucide-vue-next';
import RiepilogoDelSeguito from '@/components/assemblee/seguito/RiepilogoDelSeguito.vue';
import TabellaDelleDelibere from '@/components/assemblee/seguito/TabellaDelleDelibere.vue';
import { contiDelleDelibere, esitiDaMostrare } from '@/lib/assemblee/seguito';
import { ARANCIO, PICCOLO } from '@/components/assemblee/verbale/finestra';
import type { EsitoDellaRiga, PaginaDelSeguito } from '@/types/assemblee';

/** La frase del passo quando il verbale del giorno è annullato dopo la conferma (la stessa del server). */
const VERBALE_ANNULLATO = 'Il verbale è annullato: le delibere applicate restano.';

const props = withDefaults(defineProps<{
    seguito?: PaginaDelSeguito;
    esiti?: Record<string, EsitoDellaRiga> | null;
    condominioId: number;
    condominioNome: string;
    assembleaId: number;
    /** «Dall'assemblea del 15/12/2026, verbale n. 42.» */
    sottotitolo: string;
    /** La riunione tenuta su carta (o registrata a mano): niente strada, le voci in testa le dice il passo. */
    suCarta?: boolean;
    evidenzia?: { delibera: number | null; cosa: number | null };
}>(), { seguito: undefined, esiti: null, suCarta: false, evidenzia: () => ({ delibera: null, cosa: null }) });

const annullato = computed(() => props.seguito?.passo.perche === VERBALE_ANNULLATO);
const confermate = computed(() => (props.seguito?.tabella ?? []).filter((r) => r.seguito.stato !== 'da_confermare'));
const daConfermare = computed(() => (props.seguito?.riepilogo.da_confermare ?? 0) > 0);
const chiuso = computed(() => props.seguito !== undefined && !props.seguito.riepilogo.aperto && !annullato.value && confermate.value.length === 0);
/** Le schede che usano le delibere del verbale annullato. */
const schedeDelVerbaleAnnullato = computed(() => (props.seguito?.tabella ?? []).filter((r) => r.verbale_annullato)
    .flatMap((r) => r.posti.filter((p) => p.esiste).map((p) => `${p.nome} (punto ${r.punto})`)));
const esiti = computed(() => (props.esiti && Object.keys(props.esiti).length ? esitiDaMostrare(props.esiti) : null));
const primaDaEseguire = computed(() => props.seguito?.da_eseguire[0] ?? null);
const conti = computed(() => contiDelleDelibere(confermate.value, props.seguito?.da_eseguire.length ?? 0));
</script>

<template>
    <div class="space-y-3" data-testid="passo-delle-delibere">
        <p v-if="seguito === undefined" class="rounded-xl border border-slate-200 bg-white px-4 py-6 text-center text-sm text-slate-500 dark:border-slate-800 dark:bg-slate-950 dark:text-slate-400">
            Un momento…</p>

        <template v-else>
            <p v-if="chiuso" class="rounded-xl border border-slate-200 bg-white px-4 py-6 text-center text-sm text-slate-600 dark:border-slate-800 dark:bg-slate-950 dark:text-slate-400"
                data-testid="passo-chiuso">{{ seguito.riepilogo.perche_chiuso ?? seguito.passo.piede }}</p>

            <!-- Il verbale annullato dopo la conferma: niente si disfa da solo -->
            <div v-if="annullato" :class="ARANCIO" data-testid="verbale-annullato">
                <AlertTriangle class="mt-0.5 h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                <div class="min-w-0 flex-1 space-y-1">
                    <p class="font-semibold">{{ VERBALE_ANNULLATO }}</p>
                    <p v-if="schedeDelVerbaleAnnullato.length">Le schede che le usano: {{ schedeDelVerbaleAnnullato.join(', ') }}.</p>
                    <p>{{ seguito.passo.piede }}</p>
                </div>
            </div>

            <!-- Le tre card in testa, come nelle altre pagine (decisione 645): la prossima cosa da fare, i conti delle delibere, i trenta giorni -->
            <ul v-if="!daConfermare && confermate.length" class="grid grid-cols-1 gap-2.5 sm:grid-cols-3" data-testid="voci-in-testa">
                <li class="flex h-full flex-col justify-between gap-2.5 rounded-lg border border-slate-200 bg-slate-50 px-3 py-2.5 text-xs text-slate-700 dark:border-slate-800 dark:bg-slate-900/50 dark:text-slate-300">
                    <span class="flex min-w-0 items-start gap-2">
                        <Clock class="mt-0.5 h-3.5 w-3.5 shrink-0 text-amber-700 dark:text-amber-400" aria-hidden="true" />
                        <span class="min-w-0 break-words"><b class="block text-[13px] font-semibold text-slate-900 dark:text-slate-100">Da fare adesso</b>
                            {{ primaDaEseguire ? `${primaDaEseguire.tipo === 'fuori_dal_programma' ? primaDaEseguire.testo : primaDaEseguire.tipo_parola}${primaDaEseguire.punto ? ` · punto ${primaDaEseguire.punto}` : ''}${primaDaEseguire.chi ? ` · a ${primaDaEseguire.chi}` : ''}`
                                : 'nessuna cosa da fare aperta' }}</span>
                    </span>
                    <span v-if="seguito.puo.gestire && primaDaEseguire?.azione?.url" class="flex">
                        <Link :href="primaDaEseguire.azione.url" :class="PICCOLO" data-testid="card-azione">{{ primaDaEseguire.azione.testo }}</Link>
                    </span>
                </li>
                <li class="flex h-full flex-col gap-2.5 rounded-lg border border-slate-200 bg-slate-50 px-3 py-2.5 text-xs text-slate-700 dark:border-slate-800 dark:bg-slate-900/50 dark:text-slate-300">
                    <span class="flex min-w-0 items-start gap-2">
                        <Gavel class="mt-0.5 h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                        <span class="min-w-0 break-words"><b class="block text-[13px] font-semibold text-slate-900 dark:text-slate-100">Le delibere</b>
                            {{ conti.principale }}<span v-if="conti.altre" class="block text-slate-500 dark:text-slate-400">{{ conti.altre }}</span></span>
                    </span>
                </li>
                <li class="flex h-full flex-col gap-2.5 rounded-lg border border-slate-200 bg-slate-50 px-3 py-2.5 text-xs text-slate-700 dark:border-slate-800 dark:bg-slate-900/50 dark:text-slate-300">
                    <span class="flex min-w-0 items-start gap-2">
                        <ShieldCheck class="mt-0.5 h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                        <span class="min-w-0 break-words"><b class="block text-[13px] font-semibold text-slate-900 dark:text-slate-100">Trenta giorni</b> {{ seguito.trenta_giorni.frase }}</span>
                    </span>
                </li>
            </ul>

            <!-- L'esito di ogni riga della conferma, o di «Applica» -->
            <div v-if="esiti" class="space-y-1 rounded-lg border border-slate-200 bg-white px-3 py-2.5 text-xs text-slate-700 dark:border-slate-800 dark:bg-slate-950 dark:text-slate-300"
                data-testid="esiti-della-conferma">
                <p class="flex items-center gap-1.5 font-semibold text-slate-900 dark:text-white"><Check class="h-3.5 w-3.5 text-emerald-600 dark:text-emerald-400" aria-hidden="true" />
                    {{ esiti.applicate === 1 ? '1 riga applicata' : `${esiti.applicate} righe applicate` }}<template v-if="esiti.non.length">, {{ esiti.non.length === 1
                        ? '1 non applicata' : `${esiti.non.length} non applicate` }}</template></p>
                <p v-for="(f, i) in esiti.non" :key="i" class="break-words text-amber-800 dark:text-amber-300" data-testid="non-applicata">{{ f }}</p>
            </div>

            <RiepilogoDelSeguito v-if="daConfermare" :condominio-id="condominioId" :assemblea-id="assembleaId" :riepilogo="seguito.riepilogo" :sottotitolo="sottotitolo"
                :puo-gestire="seguito.puo.gestire" />

            <template v-if="confermate.length">
                <p v-if="!daConfermare" class="text-xs text-slate-600 dark:text-slate-400">Ogni delibera dice com'è andata e che cosa manca. Le cose da fare con una data sono
                    anche nell'Action inbox.</p>
                <TabellaDelleDelibere :condominio-id="condominioId" :condominio-nome="condominioNome" :assemblea-id="assembleaId" :righe="confermate"
                    :puo-gestire="seguito.puo.gestire" :evidenzia="evidenzia" />
            </template>
        </template>
    </div>
</template>
