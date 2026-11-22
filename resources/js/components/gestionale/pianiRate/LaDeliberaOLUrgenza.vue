<!--
SPDX-License-Identifier: AGPL-3.0-or-later
Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
-->
<script setup lang="ts">
/**
 * «La delibera, o l'urgenza» nel nuovo piano di una gestione straordinaria (Coda 688, la proposta 4 della bozza approvata con la decisione 623): **una scelta
 * sola, uguale sulle due schede** del nuovo piano, in un menu: «Delibera dell'assemblea» o «Urgenza (art. 1135 c.c.)». Cambiando scheda la scelta resta
 * quella che si vede: non c'è più una scelta nascosta che il server butta via.
 *
 * - Con la delibera: «Scegli la delibera», com'era (lo mette la pagina nello spazio `delibera`).
 * - Con l'urgenza: **il motivo**, obbligatorio, e **il giorno dei lavori** («Lavori ordinati il», di serie oggi, si cambia), sulle due schede, che decide
 *   chi paga: chi era titolare quel giorno paga tutto, nessuna divisione a giorni. Sulla scheda delle fatture la fattura senza periodo prende questo giorno,
 *   quella con il suo periodo tiene il suo; sotto il giorno, quante lo prendono.
 * - Il piano d'urgenza nasce approvato, e lo riferisci alla prima assemblea: il punto ti viene proposto quando la prepari.
 *
 * Gli errori sono rossi sotto il campo (decisione 612); niente scritte sotto 12 pixel.
 */
import vSelect from 'vue-select';
import 'vue-select/dist/vue-select.css';
import CampoGiorno from '@/components/CampoGiorno.vue';
import SuggerimentoDellaI from '@/components/assemblee/convocazione/SuggerimentoDellaI.vue';
import { SCELTE_DELL_AUTORIZZAZIONE, fraseDelGiornoDeiLavori, fraseDelleFatture } from '@/lib/gestionale/pianiRate/urgenza';

const props = withDefaults(defineProps<{
    tipo: string;
    motivo: string;
    giorno: string;
    /** `voci`: «Dalle voci del preventivo»; `fatture`: «Spese fuori preventivo». */
    scheda: 'voci' | 'fatture';
    condominioNome: string;
    errori?: { tipo?: string; motivo?: string; giorno?: string; delibera?: string };
    /** Sulla scheda delle fatture: quante fatture scelte non dicono il periodo. */
    fattureSenzaPeriodo?: number;
}>(), { errori: () => ({}), fattureSenzaPeriodo: 0 });
const emit = defineEmits<{ (e: 'update:tipo', v: string): void; (e: 'update:motivo', v: string): void; (e: 'update:giorno', v: string): void }>();

const etichetta = 'mb-1 flex items-center gap-1 text-xs font-medium text-slate-600 dark:text-slate-400';
const erroreSotto = 'mt-1 block text-xs text-rose-700 dark:text-rose-400';
// I menu leggono il valore con una funzione con il nome: lo schema scritto dentro l'attributo il controllo dei tipi non lo legge.
const perValore = (o: { valore: string }) => o.valore;
</script>

<template>
    <section class="space-y-3 rounded-xl border border-amber-200 bg-white p-5 dark:border-amber-900 dark:bg-slate-950" data-testid="la-delibera-o-l-urgenza">
        <h4 class="text-sm font-bold text-slate-800 dark:text-slate-100">La delibera, o l'urgenza</h4>
        <div class="menu-campo max-w-sm">
            <span :class="etichetta">Che cosa autorizza la spesa</span>
            <v-select :model-value="props.tipo || null" :options="SCELTE_DELL_AUTORIZZAZIONE" label="nome" :reduce="perValore" :clearable="false"
                :searchable="false" placeholder="Scegli" data-testid="scelta-autorizzazione" @update:model-value="(v: string) => emit('update:tipo', v)" />
            <span v-if="errori.tipo" :class="erroreSotto">{{ errori.tipo }}</span>
        </div>

        <slot v-if="props.tipo !== 'urgenza'" name="delibera" />

        <template v-else>
            <p class="text-sm text-slate-700 dark:text-slate-300">Lavori urgenti fatti senza delibera: si riferiscono alla prima assemblea.</p>
            <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                <label class="block"><span :class="etichetta">Il motivo dell'urgenza</span>
                    <textarea :value="props.motivo" rows="2" class="w-full rounded-md border border-input bg-transparent px-3 py-2 text-sm dark:bg-slate-950"
                        placeholder="Per esempio: rottura della colonna montante dell'acqua il 02/03/2027, con perdite nelle cantine" data-testid="motivo-urgenza"
                        @input="emit('update:motivo', ($event.target as HTMLTextAreaElement).value)" />
                    <span v-if="errori.motivo" :class="erroreSotto">{{ errori.motivo }}</span>
                </label>
                <div>
                    <span :class="etichetta">Lavori ordinati il
                        <SuggerimentoDellaI testo="Il giorno in cui hai accertato il guasto e ordinato i lavori. Chi era titolare dell'unità quel giorno paga tutta la spesa, anche se poi vende: nessuna divisione a giorni. Lo cambi se il rogito dice altro." testid="i-giorno-dei-lavori" />
                    </span>
                    <CampoGiorno :model-value="props.giorno" class="h-9 text-sm" data-testid="giorno-dei-lavori" @update:model-value="(v: string) => emit('update:giorno', v)" />
                    <span v-if="errori.giorno" :class="erroreSotto">{{ errori.giorno }}</span>
                    <span class="mt-1 block text-xs text-slate-600 dark:text-slate-400" data-testid="frase-del-giorno">{{ fraseDelGiornoDeiLavori(props.giorno) }}</span>
                    <span v-if="props.scheda === 'fatture'" class="mt-1 block text-xs text-slate-600 dark:text-slate-400" data-testid="periodo-sulle-fatture">
                        {{ fraseDelleFatture(props.giorno, props.fattureSenzaPeriodo) }}
                    </span>
                </div>
            </div>
            <p class="text-xs text-slate-600 dark:text-slate-400">Lo riferisci alla prima assemblea di {{ props.condominioNome }}: il punto ti viene proposto quando la prepari.</p>
            <p class="text-xs font-medium text-slate-700 dark:text-slate-300" data-testid="nasce-approvato">Il piano nasce approvato: l'autorizzazione è l'urgenza.</p>
        </template>
    </section>
</template>
