<!--
SPDX-License-Identifier: AGPL-3.0-or-later
Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
-->
<script setup lang="ts">
/**
 * La finestra «Fatto» dei sei mesi: registri che hai agito per le persone spuntate della riga, in un gesto solo. Le persone aperte della riga, tutte spuntate di
 * serie; sopra la ricerca per nome e il filtro per la situazione (un menu del programma, che dentro la finestra non si apre da solo); «Tutte» e «Nessuna»
 * valgono per le persone che la ricerca e il filtro mostrano. Il conteggio dice anche le spuntate che il filtro nasconde; il pulsante dice per quante persone
 * si registra, e senza spunte è spento. Chi resta senza spunta resta nel riquadro, e il compito resta aperto per lui. La lista scorre dentro la finestra.
 */
import { computed, ref, watch } from 'vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import vSelect from 'vue-select';
import 'vue-select/dist/vue-select.css';
import { CheckCircle2, Search } from 'lucide-vue-next';
import { euroTesto } from '@/lib/gestionale/debiti/rateScadute';
import {
    SITUAZIONI, annoDi, personeDellaFinestra, testoNascoste, testoRegistraPer, testoSituazione, testoSpuntate, type PersonaLeggera,
} from '@/lib/gestionale/debiti/seiMesiPerAgire';

const props = defineProps<{
    open: boolean;
    esercizio: { id: number; nome: string };
    persone: PersonaLeggera[];
    oggi: string;
    errori?: Record<string, string>;
    inCorso?: boolean;
}>();
const emit = defineEmits<{
    (e: 'update:open', v: boolean): void;
    (e: 'registra', dati: { persone: number[]; giorno: string; nota: string }): void;
}>();

const spuntate = ref<number[]>([]);
const cerca = ref('');
const situazione = ref<string | null>(null);
const giorno = ref('');
const nota = ref('');

watch(() => props.open, (aperta) => {
    if (aperta) {
        spuntate.value = props.persone.map((p) => p.anagrafica_id);
        cerca.value = '';
        situazione.value = null;
        giorno.value = props.oggi;
        nota.value = '';
    }
}, { immediate: true });

const mostrate = computed(() => personeDellaFinestra(props.persone, cerca.value, situazione.value));
const nascosteSpuntate = computed(() => spuntate.value.filter((id) => !mostrate.value.some((p) => p.anagrafica_id === id)).length);
const nessunaSpunta = computed(() => spuntate.value.length === 0);
const qualcunoFuori = computed(() => spuntate.value.length < props.persone.length);

function alterna(id: number, si: boolean) {
    spuntate.value = si ? [...new Set([...spuntate.value, id])] : spuntate.value.filter((x) => x !== id);
}
function tutte() {
    spuntate.value = [...new Set([...spuntate.value, ...mostrate.value.map((p) => p.anagrafica_id)])];
}
function nessuna() {
    const viste = new Set(mostrate.value.map((p) => p.anagrafica_id));
    spuntate.value = spuntate.value.filter((id) => !viste.has(id));
}
function registra() {
    if (nessunaSpunta.value) return;
    // Nell'ordine della lista, non in quello delle spunte.
    const ordine = props.persone.map((p) => p.anagrafica_id).filter((id) => spuntate.value.includes(id));
    emit('registra', { persone: ordine, giorno: giorno.value, nota: nota.value });
}
</script>

<template>
    <Dialog :open="open" @update:open="(v: boolean) => emit('update:open', v)">
        <DialogContent class="sm:max-w-lg" @open-auto-focus.prevent>
            <DialogHeader>
                <DialogTitle class="flex items-center gap-2">
                    <CheckCircle2 class="h-5 w-5 text-emerald-600" /> Fatto: esercizio {{ annoDi(esercizio.nome) }}
                </DialogTitle>
                <DialogDescription>Registri che hai agito per le persone spuntate.</DialogDescription>
            </DialogHeader>

            <div class="space-y-3 text-sm">
                <div class="flex flex-col gap-2 sm:flex-row sm:items-center">
                    <div class="relative flex-1">
                        <Search class="pointer-events-none absolute left-2 top-1/2 h-3.5 w-3.5 -translate-y-1/2 text-slate-400" />
                        <Input v-model="cerca" placeholder="Cerca per nome" class="h-8 pl-7 text-xs" data-testid="cerca" />
                    </div>
                    <div class="menu-situazione sm:w-48" data-testid="filtro-situazione">
                        <v-select
                            v-model="situazione"
                            :options="SITUAZIONI"
                            label="etichetta"
                            :reduce="(s: { valore: string | null }) => s.valore"
                            :searchable="false"
                            :clearable="false"
                            class="w-full"
                        />
                    </div>
                </div>
                <div class="flex items-center gap-1 text-xs">
                    <Button variant="link" size="sm" class="h-auto p-0 text-xs" data-testid="tutte" @click="tutte">Tutte</Button>
                    <span class="text-slate-300">·</span>
                    <Button variant="link" size="sm" class="h-auto p-0 text-xs" data-testid="nessuna" @click="nessuna">Nessuna</Button>
                </div>

                <ul class="max-h-[50vh] overflow-y-auto rounded-md border border-slate-100 px-2 dark:border-slate-800">
                    <li v-for="p in mostrate" :key="p.anagrafica_id" class="flex items-center gap-2 border-b border-slate-100 py-1.5 last:border-b-0 dark:border-slate-800"
                        :data-testid="`persona-${p.anagrafica_id}`">
                        <Checkbox :model-value="spuntate.includes(p.anagrafica_id)" @update:model-value="(v: boolean | 'indeterminate') => alterna(p.anagrafica_id, v === true)" />
                        <div class="min-w-0 flex-1">
                            <p class="truncate text-sm text-slate-800 dark:text-slate-100">{{ p.nome }}</p>
                            <p class="text-xs text-slate-500 dark:text-slate-400">{{ testoSituazione(p.situazione) }}</p>
                        </div>
                        <span class="shrink-0 text-sm font-bold tabular-nums text-slate-800 dark:text-slate-100">{{ euroTesto(p.cifra) }}</span>
                    </li>
                    <li v-if="!mostrate.length" class="py-3 text-xs text-slate-500">Nessuna persona con questa ricerca.</li>
                </ul>
                <p class="text-xs text-slate-500 dark:text-slate-400" data-testid="conteggio">
                    {{ testoSpuntate(spuntate.length, persone.length) }}<template v-if="nascosteSpuntate > 0"> · {{ testoNascoste(nascosteSpuntate) }}</template>
                </p>

                <div class="grid gap-3 sm:grid-cols-[10rem_1fr]">
                    <div class="space-y-1.5">
                        <Label for="fatto-giorno" class="text-xs">Il giorno</Label>
                        <Input id="fatto-giorno" v-model="giorno" type="date" :max="oggi" class="h-8 text-xs" />
                    </div>
                    <div class="space-y-1.5">
                        <Label for="fatto-nota" class="text-xs">Che cosa hai fatto</Label>
                        <Textarea id="fatto-nota" v-model="nota" rows="2" class="min-h-[60px] resize-none text-sm"
                            placeholder="Per esempio: diffide mandate per raccomandata il 12/05/2026." />
                    </div>
                </div>
                <p v-if="qualcunoFuori" class="text-xs text-slate-600 dark:text-slate-300">Chi non spunti resta nel riquadro, e il compito resta aperto per lui.</p>
                <ul v-if="errori && Object.keys(errori).length" class="list-disc pl-4 text-xs text-rose-700 dark:text-rose-300">
                    <li v-for="(m, k) in errori" :key="k">{{ m }}</li>
                </ul>
            </div>

            <DialogFooter class="items-center gap-2">
                <span v-if="nessunaSpunta" class="text-xs text-slate-500">Spunta almeno una persona.</span>
                <Button variant="outline" size="sm" @click="emit('update:open', false)">Annulla</Button>
                <Button size="sm" :disabled="nessunaSpunta || inCorso" data-testid="registra-fatto" @click="registra">{{ testoRegistraPer(spuntate.length) }}</Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>

<style>
/* Il menu della situazione: alto come il campo della ricerca accanto, con il testo piccolo della finestra; nel tema scuro leggibile. */
.menu-situazione .v-select {
    --vs-font-size: 0.75rem;
    --vs-line-height: 1.25;
    --vs-border-radius: 0.375rem;
    --vs-border-color: var(--input);
    --vs-controls-color: rgb(100 116 139);
    background: transparent;
}
.menu-situazione .vs__dropdown-toggle {
    min-height: 2rem;
    padding: 0;
    align-items: center;
    box-shadow: 0 1px 2px 0 rgb(0 0 0 / 0.05);
}
.menu-situazione .vs__selected-options {
    align-items: center;
    padding-left: 8px;
}
.menu-situazione .vs__selected,
.menu-situazione .vs__search {
    margin: 0 !important;
    padding-top: 0 !important;
    padding-bottom: 0 !important;
}
.menu-situazione .vs__actions {
    padding: 0 8px 0 4px;
}
.menu-situazione .vs__open-indicator {
    transform: scale(0.7);
}
.menu-situazione .vs--open .vs__open-indicator {
    transform: rotate(180deg) scale(0.7);
}
.dark .menu-situazione .v-select {
    --vs-dropdown-bg: rgb(15 23 42);
    --vs-dropdown-color: rgb(226 232 240);
    --vs-dropdown-option-color: rgb(226 232 240);
    --vs-selected-color: rgb(226 232 240);
    --vs-search-input-color: rgb(226 232 240);
    --vs-controls-color: rgb(148 163 184);
    --vs-border-color: var(--input);
}
.dark .menu-situazione .vs__dropdown-toggle {
    background: color-mix(in oklab, var(--input) 30%, transparent);
}
.dark .menu-situazione .vs__dropdown-option--highlight {
    background: rgb(30 41 59) !important;
    color: rgb(241 245 249) !important;
}
</style>
