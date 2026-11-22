<!--
SPDX-License-Identifier: AGPL-3.0-or-later
Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
-->
<script setup lang="ts">
/**
 * Due versioni (decisione 519 (4), scena 2 della bozza delle scene della sala): un gesto partito da una versione che nel frattempo è cambiata in un altro modo
 * (un secondo dispositivo, il computer riacceso). Niente si sovrascrive: le due versioni affiancate, con il dispositivo e l'ora, e «Tieni questa» su ognuna.
 * La scelta è un gesto (`scegli`, con `questa` o `quella`) e resta nel registro delle modifiche con le due ore e chi ha scelto. Finché non si sceglie, il
 * punto non si conferma.
 */
import { computed } from 'vue';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { ora } from '@/lib/senzaRete/sala';
import { nomeDelDispositivo } from '@/lib/senzaRete/striscia';
import type { DueVersioniDellaSala } from '@/types/assemblee';

const props = withDefaults(
    defineProps<{
        open: boolean;
        conflitto: DueVersioniDellaSala & { quella_dispositivo?: string | null; quella_segnato_il?: string | null };
        /** Il dispositivo di questa pagina: la versione segnata qui si dice «Da questo computer». */
        mioDispositivo: string;
        /** «Il voto di Romano Ida al punto 4». */
        titolo: string;
        /** Il numero del punto, se le due versioni riguardano un voto: finché non si sceglie, non si conferma. */
        punto?: number | null;
        dispositivo?: 'computer' | 'telefono' | 'tablet';
        fuso?: string;
        inCorso?: boolean;
    }>(),
    { punto: null, dispositivo: 'computer', fuso: 'Europe/Rome', inCorso: false },
);
defineEmits<{ (e: 'update:open', v: boolean): void; (e: 'scegli', scelta: 'questa' | 'quella'): void }>();

const valore = (frase: string | null) => (frase ?? '').split(':').slice(1).join(':').trim() || (frase ?? '');
const daQui = computed(() => props.conflitto.dispositivo === props.mioDispositivo);
const questa = computed(() => ({
    da: daQui.value ? `Da questo ${props.dispositivo}` : `Dal dispositivo «${nomeDelDispositivo(props.conflitto.dispositivo)}»`,
    quando: props.conflitto.segnato_il ? `segnato alle ${ora(props.conflitto.segnato_il, props.fuso)}` : null,
    valore: valore(props.conflitto.questa),
}));
const quella = computed(() => ({
    da: props.conflitto.quella_dispositivo
        ? (props.conflitto.quella_dispositivo === props.mioDispositivo ? `Da questo ${props.dispositivo}` : `Dal dispositivo «${nomeDelDispositivo(props.conflitto.quella_dispositivo)}»`)
        : 'Com\'è adesso sul server',
    quando: props.conflitto.quella_segnato_il ? `segnato alle ${ora(props.conflitto.quella_segnato_il, props.fuso)}` : 'arrivato prima',
    valore: valore(props.conflitto.quella),
}));
</script>

<template>
    <Dialog :open="open" @update:open="$emit('update:open', $event)">
        <DialogContent class="max-h-[100dvh] overflow-y-auto sm:max-w-[560px]" data-testid="finestra-due-versioni" @open-auto-focus.prevent>
            <DialogHeader>
                <DialogTitle>{{ titolo }}: due versioni</DialogTitle>
                <DialogDescription>Non sovrascrivo niente: scegli tu quale tenere.</DialogDescription>
            </DialogHeader>
            <div class="grid gap-3 sm:grid-cols-2">
                <div v-for="(v, i) in [questa, quella]" :key="i" class="flex flex-col gap-2 rounded-md border border-slate-200 p-3 dark:border-slate-700" :data-testid="i === 0 ? 'versione-questa' : 'versione-quella'">
                    <p class="text-sm font-medium text-slate-800 dark:text-slate-100">{{ v.da }}</p>
                    <p v-if="v.quando" class="text-xs text-slate-500 dark:text-slate-400">{{ v.quando }}</p>
                    <p class="text-base font-semibold text-slate-900 dark:text-slate-50">{{ v.valore }}</p>
                    <Button class="mt-auto w-full" :variant="i === 0 ? 'default' : 'outline'" :disabled="inCorso" :data-testid="i === 0 ? 'tieni-questa' : 'tieni-quella'"
                        @click="$emit('scegli', i === 0 ? 'questa' : 'quella')">Tieni questa</Button>
                </div>
            </div>
            <ul class="list-disc space-y-1 pl-5 text-xs text-slate-600 dark:text-slate-300">
                <li v-if="punto !== null" data-testid="due-versioni-punto">Finché non scegli, il punto {{ punto }} non si conferma.</li>
                <li>La scelta resta nel registro delle modifiche, con le due ore e chi ha scelto.</li>
            </ul>
        </DialogContent>
    </Dialog>
</template>
