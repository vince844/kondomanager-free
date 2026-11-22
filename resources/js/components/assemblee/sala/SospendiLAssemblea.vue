<!--
SPDX-License-Identifier: AGPL-3.0-or-later
Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
-->
<script setup lang="ts">
/**
 * «Sospendi l'assemblea» (scena 5 della bozza delle scene della tappa 3): si prosegue solo in una data di prosecuzione scritta nell'avviso (art. 66 c. 5
 * disp. att.), e la finestra propone solo quelle; un altro giorno è una nuova convocazione, «Rinvia l'assemblea». Senza date nell'avviso lo dice. Che cosa
 * succede, a schema. Con un voto da confermare non si sospende (lo dice il server).
 *
 * Il promemoria ai condòmini convocati (decisione 595 (1)), acceso di serie: l'email a chi l'ha e un avviso in bacheca senza nomi. Acceso, si sceglie se
 * l'avviso in bacheca si pubblica subito o resta in bozza, da pubblicare dalla bacheca.
 */
import { computed, ref, watch } from 'vue';
import vSelect from 'vue-select';
import 'vue-select/dist/vue-select.css';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import { Switch } from '@/components/ui/switch';
import { useSala } from './contesto';
import { giornoDellaSeduta } from './sala';

const props = defineProps<{ open: boolean }>();
const emit = defineEmits<{ (e: 'update:open', v: boolean): void; (e: 'rinvia'): void }>();
const { sala, segna, oraDelGesto } = useSala();

const aperta = computed(() => sala.value.sedute.find((s) => s.id === sala.value.seduta_id) ?? null);
const prosecuzioni = computed(() => sala.value.sedute.filter((s) => aperta.value && s.convocazione === aperta.value.convocazione && s.numero > aperta.value.numero
    && s.aperta_il === null && s.esito === null).map((s) => ({ id: s.id, etichetta: `${giornoDellaSeduta(s.giorno)} alle ${s.ora}` })));
const prosegue = ref<number | null>(null);
const idDi = (p: { id: number }) => p.id;
const promemoria = ref(true);
const bacheca = ref<'pubblica' | 'bozza'>('pubblica');
watch(() => props.open, (si) => {
    if (!si) return;
    prosegue.value = prosecuzioni.value[0]?.id ?? null;
    promemoria.value = true;
    bacheca.value = 'pubblica';
}, { immediate: true });
const scelta = computed(() => prosecuzioni.value.find((p) => p.id === prosegue.value) ?? null);
const senza = computed(() => sala.value.punti.filter((p) => p.esito === null));

async function sospendi() {
    if (!aperta.value || prosegue.value === null) return;
    await segna('sospendi', { seduta_id: aperta.value.id, prosegue_seduta_id: prosegue.value, promemoria: promemoria.value,
        ...(promemoria.value ? { bacheca: bacheca.value } : {}) });
    emit('update:open', false);
}
</script>

<template>
    <Dialog :open="open" @update:open="(v: boolean) => emit('update:open', v)">
        <DialogContent class="max-h-[90vh] overflow-y-auto sm:max-w-lg" data-testid="finestra-sospendi" @open-auto-focus.prevent>
            <DialogHeader>
                <DialogTitle>Sospendi l'assemblea</DialogTitle>
                <DialogDescription>Sono le {{ oraDelGesto() }}. {{ senza.length ? `Restano ${senza.map((p) => p.numero).join(', ')}.` : 'Ogni punto ha il suo esito.' }}</DialogDescription>
            </DialogHeader>
            <div v-if="prosecuzioni.length" class="space-y-3 text-sm">
                <div class="space-y-1.5">
                    <Label class="text-xs">Prosegue</Label>
                    <v-select v-model="prosegue" :options="prosecuzioni" label="etichetta" :reduce="idDi" :clearable="false" :searchable="false"
                        data-testid="prosegue" />
                    <p class="text-xs text-slate-500 dark:text-slate-400">Solo le date dell'avviso. Un altro giorno serve una nuova convocazione: «Rinvia l'assemblea».</p>
                </div>
                <div class="space-y-2 rounded-lg border border-slate-200 px-3 py-2.5 dark:border-slate-800" data-testid="il-promemoria">
                    <label class="flex items-start justify-between gap-3">
                        <span class="min-w-0">
                            <span class="block text-sm font-medium text-slate-900 dark:text-white">Manda il promemoria ai condòmini convocati</span>
                            <span class="block text-xs text-slate-500 dark:text-slate-400">Un'email a chi l'ha, e un avviso in bacheca per tutti, senza nomi: il giorno, l'ora, il luogo e i punti che restano.</span>
                        </span>
                        <Switch v-model="promemoria" data-testid="promemoria" />
                    </label>
                    <div v-if="promemoria" class="space-y-1">
                        <p class="text-xs font-semibold text-slate-500 dark:text-slate-400">L'avviso in bacheca</p>
                        <div class="grid grid-cols-2 gap-1 rounded-lg bg-slate-100 p-1 dark:bg-slate-900" role="radiogroup" aria-label="L'avviso in bacheca">
                            <button v-for="s in [{ v: 'pubblica', t: 'Pubblica subito' }, { v: 'bozza', t: 'Lascia in bozza' }] as const" :key="s.v" type="button" role="radio"
                                :aria-checked="bacheca === s.v ? 'true' : 'false'" :data-testid="`bacheca-${s.v}`"
                                class="h-8 rounded-md text-xs font-medium" :class="bacheca === s.v ? 'bg-white text-slate-900 shadow-sm dark:bg-slate-800 dark:text-white' : 'text-slate-600 dark:text-slate-400'"
                                @click="bacheca = s.v">{{ s.t }}</button>
                        </div>
                        <p class="text-xs text-slate-500 dark:text-slate-400">{{ bacheca === 'pubblica' ? 'I condòmini lo vedono subito.' : 'Resta in bozza: lo pubblichi tu dalla bacheca.' }}</p>
                    </div>
                </div>
                <div class="rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 dark:border-slate-800 dark:bg-slate-900">
                    <p class="text-xs font-semibold text-slate-900 dark:text-white">Che cosa succede</p>
                    <ul class="mt-1 list-disc space-y-0.5 pl-4 text-xs text-slate-700 dark:text-slate-300">
                        <li>Le presenze di stasera si chiudono alle {{ oraDelGesto() }}.</li>
                        <li>I punti decisi restano decisi; {{ scelta ? scelta.etichetta : '' }} si riparte dal primo punto senza esito.</li>
                        <li>Quel giorno la sala si riapre dal passo «In sala», e le presenze si segnano di nuovo.</li>
                        <li>Le deleghe valgono anche nella prosecuzione.</li>
                        <li>Il diario e il verbale scrivono l'ora della sospensione e la data in cui si prosegue.</li>
                    </ul>
                </div>
            </div>
            <p v-else class="rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-900 dark:border-amber-800 dark:bg-amber-950/40 dark:text-amber-200"
                data-testid="senza-prosecuzione">
                L'avviso non indica altre date. Per continuare un altro giorno serve una nuova convocazione (art. 66 c. 5 disp. att.).
            </p>
            <DialogFooter class="gap-2">
                <template v-if="prosecuzioni.length">
                    <Button variant="outline" size="sm" @click="emit('update:open', false)">Annulla</Button>
                    <Button size="sm" :disabled="prosegue === null" data-testid="conferma-sospendi" @click="sospendi">Sospendi, prosegue il {{ scelta?.etichetta.split(' ')[1]?.slice(0, 5) }}</Button>
                </template>
                <template v-else>
                    <Button variant="outline" size="sm" @click="emit('update:open', false)">Torna in sala</Button>
                    <Button size="sm" data-testid="vai-al-rinvio" @click="emit('update:open', false); emit('rinvia')">Rinvia l'assemblea</Button>
                </template>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
