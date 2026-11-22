<!--
SPDX-License-Identifier: AGPL-3.0-or-later
Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
-->
<script setup lang="ts">
/**
 * «Rinvia l'assemblea» dalla sala (all'apertura, quando il presidente sceglie di rinviare; o dal menu, quando si prosegue un altro giorno): il motivo e le
 * date della riconvocazione. L'assemblea si annulla «Rinviata dalla sala» e ne nasce una nuova, con un avviso nuovo e la sua delega (le deleghe di questa
 * non passano). Non dopo una delibera confermata: lì si chiude con i punti rimasti «Rinviata» (lo dice il server).
 */
import { computed, ref, watch } from 'vue';
import { Button } from '@/components/ui/button';
import CampoGiorno from '@/components/CampoGiorno.vue';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { useSala } from './contesto';

const props = defineProps<{ open: boolean }>();
const emit = defineEmits<{ (e: 'update:open', v: boolean): void }>();
const { sala, segna } = useSala();

const motivo = ref('');
const primaGiorno = ref('');
const primaOra = ref('');
const secondaGiorno = ref('');
const secondaOra = ref('');
watch(() => props.open, (aperta) => {
    if (!aperta) return;
    motivo.value = '';
    primaGiorno.value = '';
    primaOra.value = '';
    secondaGiorno.value = '';
    secondaOra.value = '';
}, { immediate: true });
const pronta = computed(() => motivo.value.trim() !== '' && primaGiorno.value !== '' && primaOra.value !== '' && secondaGiorno.value !== '' && secondaOra.value !== '');

async function rinvia() {
    if (!pronta.value) return;
    await segna('rinvia', {
        motivo: motivo.value.trim(),
        sedute: [
            { convocazione: 'prima', numero: 1, fissata_il: `${primaGiorno.value} ${primaOra.value}` },
            { convocazione: 'seconda', numero: 1, fissata_il: `${secondaGiorno.value} ${secondaOra.value}` },
        ],
        ...(sala.value.seduta_id !== null ? { seduta_id: sala.value.seduta_id } : sala.value.da_aprire ? { seduta_id: sala.value.da_aprire.seduta_id } : {}),
    });
    emit('update:open', false);
}
</script>

<template>
    <Dialog :open="open" @update:open="(v: boolean) => emit('update:open', v)">
        <DialogContent class="max-h-[90vh] overflow-y-auto sm:max-w-lg" data-testid="finestra-rinvia" @open-auto-focus.prevent>
            <DialogHeader>
                <DialogTitle>Rinvia l'assemblea</DialogTitle>
                <DialogDescription>L'assemblea si annulla «Rinviata dalla sala» e ne nasce una nuova, da convocare con un avviso nuovo.</DialogDescription>
            </DialogHeader>
            <div class="space-y-3 text-sm">
                <div class="space-y-1.5">
                    <Label for="motivo-rinvia" class="text-xs">Perché si rinvia</Label>
                    <Textarea id="motivo-rinvia" v-model="motivo" rows="2" class="min-h-[60px] resize-none text-sm" data-testid="motivo-rinvia"
                        placeholder="Per esempio: Bianchi Anna non risulta convocata." />
                </div>
                <div class="grid grid-cols-2 gap-2">
                    <div class="space-y-1.5"><Label for="rinvia-prima-giorno" class="text-xs">Prima convocazione</Label>
                        <CampoGiorno id="rinvia-prima-giorno" v-model="primaGiorno" class="h-9 text-sm" data-testid="rinvia-prima-giorno" /></div>
                    <div class="space-y-1.5"><Label for="rinvia-prima-ora" class="text-xs">Alle</Label>
                        <Input id="rinvia-prima-ora" v-model="primaOra" type="time" class="h-9 text-sm" data-testid="rinvia-prima-ora" /></div>
                    <div class="space-y-1.5"><Label for="rinvia-seconda-giorno" class="text-xs">Seconda convocazione</Label>
                        <CampoGiorno id="rinvia-seconda-giorno" v-model="secondaGiorno" class="h-9 text-sm" data-testid="rinvia-seconda-giorno" /></div>
                    <div class="space-y-1.5"><Label for="rinvia-seconda-ora" class="text-xs">Alle</Label>
                        <Input id="rinvia-seconda-ora" v-model="secondaOra" type="time" class="h-9 text-sm" data-testid="rinvia-seconda-ora" /></div>
                </div>
                <ul class="list-disc space-y-0.5 pl-4 text-xs text-slate-600 dark:text-slate-400">
                    <li>La seconda in un altro giorno, dopo la prima e non oltre dieci giorni (art. 1136 c. 3 c.c.): lo controlla il server.</li>
                    <li>Le deleghe di questa assemblea non passano: la riconvocazione ha la sua delega.</li>
                    <li>Il motivo resta nel diario e nel registro delle modifiche.</li>
                </ul>
            </div>
            <DialogFooter class="gap-2">
                <Button variant="outline" size="sm" @click="emit('update:open', false)">Annulla</Button>
                <Button size="sm" :disabled="!pronta" data-testid="conferma-rinvia" @click="rinvia">Rinvia e riconvoca</Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
