<!--
SPDX-License-Identifier: AGPL-3.0-or-later
Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
-->
<script setup lang="ts">
/**
 * Le date dell'avviso e il resto della testata dell'assemblea (§3 P2; scena 1 della bozza della pagina): il tipo, per chi, la prima e la seconda
 * convocazione con il giorno e l'ora, dove, come si partecipa (in videoconferenza solo con il consenso registrato, art. 66 c. 6), le date di prosecuzione con
 * lo stesso avviso (art. 66 c. 5). **Il programma controlla le date mentre le scrivi** (`lib/assemblee/date.ts`, gli stessi casi del server, R11): la frase
 * sotto le due convocazioni è verde quando va bene, rossa quando no.
 *
 * «Per chi» ha anche i condòmini di una tabella segnata «di un gruppo» (Coda 734, scena 7 della bozza approvata, {@see perimetriDellAssemblea}): scelto il
 * gruppo, il riquadro azzurro dice chi riceve l'avviso e come conta la sala.
 * Il componente tiene solo il modulo (`v-model`): salvare lo fa la pagina, con la sua visita.
 */
import { computed } from 'vue';
import vSelect from 'vue-select';
import 'vue-select/dist/vue-select.css';
import { AlertTriangle, Plus, X } from 'lucide-vue-next';
import SuggerimentoDellaI from '@/components/assemblee/convocazione/SuggerimentoDellaI.vue';
import CampoGiorno from '@/components/CampoGiorno.vue';
import { FRASE_MANCA_LA_PRIMA, controllaLeDate, fraseDeiCinqueGiorni } from '@/lib/assemblee/date';
import type { TerminiDellaPrima } from '@/types/assemblee';
import { gruppoDelPerimetro, perimetriDellAssemblea, seduteDaControllare, type ModuloDelleDate, type ScelteDelleAssemblee, type SedutaDellaPagina } from '@/lib/assemblee/pagine';

const props = withDefaults(defineProps<{
    modelValue: ModuloDelleDate;
    scelte: ScelteDelleAssemblee;
    disabilitato?: boolean;
    /** Il perimetro non si cambia dopo la preparazione: decide chi è convocato. */
    perimetroBloccato?: boolean;
    /** Falso finché la pagina non prova a preparare l'assemblea: un campo che manca non è ancora un errore. Gli errori sulle date scritte restano subito. */
    mostraMancanti?: boolean;
    /** I giorni da cui la prima convocazione ha i cinque giorni dell'art. 66 c. 3 (il server, da oggi): con la prima convocazione più vicina compare la nota. */
    termini?: TerminiDellaPrima | null;
}>(), { disabilitato: false, perimetroBloccato: false, mostraMancanti: true, termini: null });
const emit = defineEmits<{ (e: 'update:modelValue', v: ModuloDelleDate): void }>();

interface Opzione { valore: string; nome: string; sotto?: string }

const cambia = <K extends keyof ModuloDelleDate>(chiave: K, valore: ModuloDelleDate[K]) => emit('update:modelValue', { ...props.modelValue, [chiave]: valore });
const seduta = (convocazione: 'prima' | 'seconda', numero = 1) => props.modelValue.sedute.find((s) => s.convocazione === convocazione && s.numero === numero);

function cambiaSeduta(convocazione: 'prima' | 'seconda', numero: number, campo: 'giorno' | 'ora', valore: string) {
    const sedute = [...props.modelValue.sedute];
    const i = sedute.findIndex((s) => s.convocazione === convocazione && s.numero === numero);
    if (i >= 0) {
        sedute[i] = { ...sedute[i], [campo]: valore };
    } else {
        sedute.push({ convocazione, numero, giorno: campo === 'giorno' ? valore : '', ora: campo === 'ora' ? valore : (convocazione === 'prima' ? '08:00' : '18:30') });
    }
    cambia('sedute', sedute);
}

const prosecuzioni = computed(() => props.modelValue.sedute.filter((s) => s.numero > 1).sort((a, b) => a.numero - b.numero));

function aggiungiProsecuzione() {
    const numero = Math.max(1, ...props.modelValue.sedute.filter((s) => s.convocazione === 'seconda').map((s) => s.numero)) + 1;
    cambia('sedute', [...props.modelValue.sedute, { convocazione: 'seconda', numero, giorno: '', ora: '18:30' }]);
}

function togliProsecuzione(s: SedutaDellaPagina) {
    cambia('sedute', props.modelValue.sedute.filter((x) => !(x.convocazione === s.convocazione && x.numero === s.numero)));
}

const controllo = computed(() => controllaLeDate(seduteDaControllare(props.modelValue.sedute)));
// I cinque giorni (decisione 662): una nota, non un errore; non blocca, e dice quando sì.
const cinqueGiorni = computed(() => fraseDeiCinqueGiorni(seduta('prima')?.giorno, props.termini));

const tipi = computed<Opzione[]>(() => props.scelte.tipi);
const modalita = computed<Opzione[]>(() => props.scelte.modalita);
const perimetri = computed<Opzione[]>(() => perimetriDellAssemblea(props.scelte));
const gruppo = computed(() => gruppoDelPerimetro(props.scelte, props.modelValue.perimetro));
const inVideo = computed(() => props.modelValue.modalita !== 'presenza');

const etichetta = 'mb-1 flex items-center gap-1 text-xs font-medium text-slate-600 dark:text-slate-400';
const input = 'h-9 w-full rounded-md border border-input bg-transparent px-3 text-sm shadow-xs dark:bg-slate-950 disabled:opacity-60';
</script>

<template>
    <div class="space-y-3.5" data-testid="date-dell-assemblea">
        <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
            <div class="menu-campo"><span :class="etichetta">Tipo</span>
                <v-select :model-value="modelValue.tipo" :options="tipi" label="nome" :reduce="(o: Opzione) => o.valore" :clearable="false" :searchable="false"
                    :disabled="disabilitato" @update:model-value="(v: string) => cambia('tipo', v)" />
            </div>
            <div class="menu-campo">
                <span :class="etichetta">Per chi
                    <SuggerimentoDellaI testid="i-per-chi-assemblea"
                        testo="Un'assemblea di tutto il condominio, di una palazzina, di una scala o dei soli condòmini di una tabella segnata «di un gruppo»: decide chi è convocato e la tabella del valore. Un punto della sola scala B si sceglie nel punto." />
                </span>
                <v-select :model-value="modelValue.perimetro" :options="perimetri" label="nome" :reduce="(o: Opzione) => o.valore" :clearable="false" :searchable="false"
                    :disabled="disabilitato || perimetroBloccato" @update:model-value="(v: string) => cambia('perimetro', v)"><template #option="o"><span class="block">{{ o.nome }}</span><span v-if="o.sotto" class="block text-xs opacity-70">{{ o.sotto }}</span></template></v-select>
            </div>
            <p v-if="gruppo" class="rounded-md border border-sky-200 bg-sky-50 px-3 py-2 text-xs text-sky-900 sm:col-span-2 dark:border-sky-800 dark:bg-sky-950/40 dark:text-sky-200" data-testid="assemblea-del-gruppo"><span class="font-medium">L'assemblea è dei soli condòmini {{ gruppo.unita === 1 ? 'dell\'unità' : `delle ${gruppo.unita} unità` }} della tabella «{{ gruppo.nome }}» (art. 1123 c. 3 c.c.).</span> L'avviso va a loro, e la sala conta teste e millesimi fra loro. Il gruppo e la tabella non si cambiano dopo la preparazione: decidono chi è convocato.</p>
            <div>
                <span :class="etichetta">Prima convocazione</span>
                <div class="grid grid-cols-[minmax(0,1fr)_96px] gap-2">
                    <CampoGiorno :model-value="seduta('prima')?.giorno ?? ''" class="h-9 text-sm" :disabled="disabilitato" aria-label="Il giorno della prima convocazione"
                        data-testid="prima-giorno" @update:model-value="(v: string) => cambiaSeduta('prima', 1, 'giorno', v)" />
                    <input type="time" :value="seduta('prima')?.ora ?? ''" :class="input" :disabled="disabilitato" aria-label="L'ora della prima convocazione"
                        data-testid="prima-ora" @input="cambiaSeduta('prima', 1, 'ora', ($event.target as HTMLInputElement).value)" />
                </div>
            </div>
            <div>
                <span :class="etichetta">Seconda convocazione</span>
                <div class="grid grid-cols-[minmax(0,1fr)_96px] gap-2">
                    <CampoGiorno :model-value="seduta('seconda')?.giorno ?? ''" class="h-9 text-sm" :disabled="disabilitato" aria-label="Il giorno della seconda convocazione"
                        data-testid="seconda-giorno" @update:model-value="(v: string) => cambiaSeduta('seconda', 1, 'giorno', v)" />
                    <input type="time" :value="seduta('seconda')?.ora ?? ''" :class="input" :disabled="disabilitato" aria-label="L'ora della seconda convocazione"
                        data-testid="seconda-ora" @input="cambiaSeduta('seconda', 1, 'ora', ($event.target as HTMLInputElement).value)" />
                </div>
            </div>
        </div>
        <!-- Come negli altri moduli: solo l'errore, rosso sotto i campi e senza icona; le date giuste non hanno una riga. -->
        <p v-if="!controllo.valide && (mostraMancanti || controllo.frase !== FRASE_MANCA_LA_PRIMA)" class="-mt-2.5 text-sm text-red-600 dark:text-red-500" data-testid="controllo-date">{{ controllo.frase }}</p>
        <div v-if="cinqueGiorni" class="-mt-1 flex items-start gap-2.5 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2.5 text-xs text-amber-800 dark:border-amber-900 dark:bg-amber-950/40 dark:text-amber-300"
            data-testid="cinque-giorni">
            <AlertTriangle class="mt-0.5 h-3.5 w-3.5 shrink-0" />
            <p>{{ cinqueGiorni }}</p>
        </div>

        <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
            <label class="block"><span :class="etichetta">Dove</span>
                <input :value="modelValue.luogo" type="text" placeholder="Sala condominiale, Via Roma 12" :class="input" :disabled="disabilitato"
                    @input="cambia('luogo', ($event.target as HTMLInputElement).value)" />
            </label>
            <div class="menu-campo"><span :class="etichetta">Come si partecipa</span>
                <v-select :model-value="modelValue.modalita" :options="modalita" label="nome" :reduce="(o: Opzione) => o.valore" :clearable="false" :searchable="false"
                    :disabled="disabilitato" @update:model-value="(v: string) => cambia('modalita', v)" />
            </div>
        </div>
        <p class="text-xs text-slate-500 dark:text-slate-400">In videoconferenza solo con il consenso della maggioranza dei condòmini, che il programma ti chiede di registrare (art. 66 c. 6).</p>
        <div v-if="inVideo" class="grid grid-cols-1 gap-3 sm:grid-cols-3">
            <label class="block"><span :class="etichetta">La piattaforma</span>
                <input :value="modelValue.piattaforma" type="text" :class="input" :disabled="disabilitato" @input="cambia('piattaforma', ($event.target as HTMLInputElement).value)" />
            </label>
            <label class="block"><span :class="etichetta">Consenso alla videoconferenza del</span>
                <CampoGiorno :model-value="modelValue.consenso_videoconferenza_il" class="h-9 text-sm" :disabled="disabilitato" svuotabile
                    @update:model-value="(v: string) => cambia('consenso_videoconferenza_il', v)" />
            </label>
            <label class="block"><span :class="etichetta">Come è stato dato il consenso</span>
                <input :value="modelValue.consenso_videoconferenza_modo" type="text" placeholder="Delibera del 14/05/2026" :class="input" :disabled="disabilitato"
                    @input="cambia('consenso_videoconferenza_modo', ($event.target as HTMLInputElement).value)" />
            </label>
        </div>

        <div class="rounded-lg border border-slate-200 px-3 py-2.5 dark:border-slate-700">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <span class="text-sm font-medium text-slate-800 dark:text-slate-200">Altre date di prosecuzione con lo stesso avviso</span>
                <button v-if="!disabilitato" type="button" class="inline-flex h-8 items-center gap-1 rounded-md border border-slate-200 px-2.5 text-xs font-medium dark:border-slate-700"
                    @click="aggiungiProsecuzione"><Plus class="h-3.5 w-3.5" /> Aggiungi una data</button>
            </div>
            <p v-if="prosecuzioni.length === 0" class="mt-1 text-xs text-slate-500 dark:text-slate-400">Adesso: nessuna. Per un'assemblea lunga, le date in più vanno nell'avviso (art. 66 c. 5).</p>
            <div v-for="s in prosecuzioni" :key="`p-${s.numero}`" class="mt-2 grid grid-cols-[minmax(0,1fr)_96px_auto] items-center gap-2">
                <CampoGiorno :model-value="s.giorno" class="h-9 text-sm" :disabled="disabilitato" :aria-label="`Il giorno della prosecuzione ${s.numero - 1}`"
                    @update:model-value="(v: string) => cambiaSeduta(s.convocazione, s.numero, 'giorno', v)" />
                <input type="time" :value="s.ora" :class="input" :disabled="disabilitato" :aria-label="`L'ora della prosecuzione ${s.numero - 1}`"
                    @input="cambiaSeduta(s.convocazione, s.numero, 'ora', ($event.target as HTMLInputElement).value)" />
                <button v-if="!disabilitato" type="button" class="inline-flex h-8 w-8 items-center justify-center rounded-md border border-slate-200 dark:border-slate-700"
                    aria-label="Togli questa data" @click="togliProsecuzione(s)"><X class="h-4 w-4" /></button>
            </div>
        </div>
    </div>
</template>
