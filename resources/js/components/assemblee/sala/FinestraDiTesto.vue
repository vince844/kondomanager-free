<!--
SPDX-License-Identifier: AGPL-3.0-or-later
Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
-->
<script setup lang="ts">
/**
 * La finestra di un testo breve della sala: la dichiarazione a verbale (con chi la fa), le note del presidente sul voto e su chi non ha votato, la nota del
 * conflitto d'interessi, il perché della correzione di un voto confermato. Il testo va nel verbale, che leggono tutti: la finestra lo ricorda.
 */
import { computed, ref, watch } from 'vue';
import vSelect from 'vue-select';
import 'vue-select/dist/vue-select.css';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import type { Opzione } from './sala';

const props = withDefaults(defineProps<{
    open: boolean; titolo: string; descrizione?: string; etichetta: string; testo?: string | null; esempio?: string; pulsante: string;
    /** Chi: le persone fra cui scegliere (la dichiarazione); senza, la finestra non lo chiede. */
    persone?: Opzione[] | null; etichettaChi?: string; obbligatorio?: boolean; testidFinestra: string; testidTesto: string; testidConferma: string;
    testidChi?: string;
}>(), { descrizione: '', testo: null, esempio: '', persone: null, etichettaChi: 'Chi', obbligatorio: true, testidChi: 'chi' });
const emit = defineEmits<{ (e: 'update:open', v: boolean): void; (e: 'salva', testo: string, chi: number | null): void }>();

const scritto = ref('');
const chi = ref<number | null>(null);
watch(() => props.open, (aperta) => { if (aperta) { scritto.value = props.testo ?? ''; chi.value = null; } }, { immediate: true });
const pronta = computed(() => (!props.obbligatorio || scritto.value.trim() !== '') && (!props.persone || chi.value !== null) && scritto.value.length <= 2000);

function salva() {
    if (!pronta.value) return;
    emit('salva', scritto.value.trim(), chi.value);
    emit('update:open', false);
}
</script>

<template>
    <Dialog :open="open" @update:open="(v: boolean) => emit('update:open', v)">
        <DialogContent class="max-h-[90vh] overflow-y-auto sm:max-w-lg" :data-testid="testidFinestra" @open-auto-focus.prevent>
            <DialogHeader>
                <DialogTitle>{{ titolo }}</DialogTitle>
                <DialogDescription v-if="descrizione">{{ descrizione }}</DialogDescription>
            </DialogHeader>
            <div class="space-y-3 text-sm">
                <div v-if="persone" class="space-y-1.5">
                    <Label class="text-xs">{{ etichettaChi }}</Label>
                    <v-select v-model="chi" :options="persone" label="etichetta" :reduce="(o: Opzione) => o.id" :clearable="false" :data-testid="testidChi" />
                </div>
                <div class="space-y-1.5">
                    <Label :for="testidTesto" class="text-xs">{{ etichetta }}</Label>
                    <Textarea :id="testidTesto" v-model="scritto" rows="3" class="min-h-[72px] resize-none text-sm" :placeholder="esempio" :data-testid="testidTesto" />
                    <p class="text-xs text-slate-500 dark:text-slate-400">Va nel verbale, che leggono tutti: niente dati delicati. Al più 2.000 caratteri.</p>
                </div>
            </div>
            <DialogFooter class="gap-2">
                <Button variant="outline" size="sm" @click="emit('update:open', false)">Annulla</Button>
                <Button size="sm" :disabled="!pronta" :data-testid="testidConferma" @click="salva">{{ pulsante }}</Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
