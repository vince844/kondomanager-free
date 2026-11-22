<!--
SPDX-License-Identifier: AGPL-3.0-or-later
Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
-->
<script setup lang="ts">
/**
 * «Segnare deserta la prima (o la seconda) convocazione?» (scena 4 della bozza delle scene della sala): l'ora in cui si constata che il numero non c'è (si
 * corregge), e che cosa succede, a schema. Il gesto `deserta` porta quell'ora; dal foglio della sala, anche il segno del foglio. `dopoLAppello`: la seduta è
 * già aperta e i presenti sono contati (si segna finché non si vota): chi è in sala esce a quell'ora, e l'appello resta per il verbale. La seconda deserta fa
 * l'assemblea deserta: i punti tornano fra quelli per la prossima (art. 1136 c. 3 c.c.).
 */
import { computed, ref, watch } from 'vue';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import type { SedutaDellaSala } from '@/types/assemblee';
import { useSala } from './contesto';
import { giornoDellaSeduta } from './sala';

const props = withDefaults(defineProps<{ open: boolean; seduta: SedutaDellaSala | null; seconda: SedutaDellaSala | null; dopoLAppello?: boolean }>(),
    { dopoLAppello: false });
const emit = defineEmits<{ (e: 'update:open', v: boolean): void }>();
const { segna, oraDelGesto, dalFoglio } = useSala();

const ora = ref('');
watch(() => props.open, (aperta) => { if (aperta) ora.value = oraDelGesto(); }, { immediate: true });
const prima = computed(() => props.seduta?.convocazione === 'prima');

async function conferma() {
    if (!props.seduta || !ora.value) return;
    await segna('deserta', { seduta_id: props.seduta.id, ...(dalFoglio.value ? { dal_foglio: true } : {}) }, { ora: ora.value, giorno: props.seduta.giorno });
    emit('update:open', false);
}
</script>

<template>
    <Dialog :open="open" @update:open="(v: boolean) => emit('update:open', v)">
        <DialogContent class="max-h-[90vh] overflow-y-auto sm:max-w-lg" data-testid="finestra-deserta" @open-auto-focus.prevent>
            <DialogHeader>
                <DialogTitle>Segnare deserta la {{ prima ? 'prima' : 'seconda' }} convocazione?</DialogTitle>
                <DialogDescription v-if="seduta">{{ giornoDellaSeduta(seduta.giorno) }}, convocata alle {{ seduta.ora }}.</DialogDescription>
            </DialogHeader>
            <div class="space-y-3 text-sm">
                <div class="space-y-1.5">
                    <Label for="ora-deserta" class="text-xs">Alle</Label>
                    <Input id="ora-deserta" v-model="ora" type="time" class="h-9 w-32 text-sm" data-testid="ora-deserta" />
                    <p class="text-xs text-slate-500 dark:text-slate-400">L'ora in cui si constata che il numero non c'è.</p>
                </div>
                <div class="rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 dark:border-slate-800 dark:bg-slate-900">
                    <p class="text-xs font-semibold text-slate-900 dark:text-white">Che cosa succede</p>
                    <ul class="mt-1 list-disc space-y-0.5 pl-4 text-xs text-slate-700 dark:text-slate-300">
                        <li>La {{ prima ? 'prima' : 'seconda' }} convocazione resta deserta, con l'ora.</li>
                        <li v-if="dopoLAppello">Chi è in sala esce alle {{ ora }}: l'appello, con l'ora di apertura, resta per il verbale.</li>
                        <li v-if="prima && seconda">Si prosegue in seconda: {{ giornoDellaSeduta(seconda.giorno) }} alle {{ seconda.ora }}, come nell'avviso.</li>
                        <li v-if="!prima">Deserte la prima e la seconda, l'assemblea è deserta: i punti tornano fra quelli per la prossima.</li>
                        <li>Il registro dei verbali la annota come mancata costituzione (art. 1130 n. 7 c.c.).</li>
                        <li v-if="prima">Le deleghe ricevute valgono anche per la seconda.</li>
                        <li v-if="prima">La seconda rilegge chi è titolare al suo giorno: un passaggio di proprietà nel frattempo lo dice il controllo.</li>
                    </ul>
                </div>
            </div>
            <DialogFooter class="gap-2">
                <Button variant="outline" size="sm" @click="emit('update:open', false)">Annulla</Button>
                <Button size="sm" :disabled="!ora" data-testid="conferma-deserta" @click="conferma">Segna deserta alle {{ ora }}</Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
