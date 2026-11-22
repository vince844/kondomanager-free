<!--
SPDX-License-Identifier: AGPL-3.0-or-later
Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
-->
<script setup lang="ts">
/**
 * «Annulla e riconvoca»: dalla prima spedizione date e ordine del giorno non si cambiano più, e la strada è annullare l'assemblea con il motivo e crearne
 * una nuova con le date nuove, che copia l'ordine del giorno e rimette i memo nei suoi punti. Gli invii, i convocati e le prove dell'assemblea annullata
 * restano. La finestra dice il costo prima del clic (gli avvisi da rifare); il server porta alla pagina dell'assemblea nuova.
 */
import { computed, ref, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import { AlertTriangle, RotateCcw } from 'lucide-vue-next';
import { Button } from '@/components/ui/button';
import CampoGiorno from '@/components/CampoGiorno.vue';
import { controllaLeDate } from '@/lib/assemblee/date';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { classiNota } from './aiuti';
import type { SchedaDellaConvocazione } from './tipi';

const props = defineProps<{ open: boolean; riconvoca: SchedaDellaConvocazione['riconvoca']; rotta: string }>();
const emit = defineEmits<{ (e: 'update:open', v: boolean): void }>();

const motivo = ref('');
const primaGiorno = ref('');
const primaOra = ref('08:00');
const secondaGiorno = ref('');
const secondaOra = ref('18:30');
const errori = ref<Record<string, string>>({});
const inCorso = ref(false);

watch(() => props.open, (aperta) => {
    if (!aperta) return;
    motivo.value = '';
    primaGiorno.value = '';
    secondaGiorno.value = '';
    errori.value = {};
}, { immediate: true });

/** Le date nuove: la seconda è facoltativa, come in «Prepara l'assemblea» (art. 66 disp. att.: senza, l'assemblea non raggiunta va convocata di nuovo). */
const sedute = computed(() => [
    { convocazione: 'prima', numero: 1, fissata_il: primaGiorno.value ? `${primaGiorno.value} ${primaOra.value}` : '' },
    ...(secondaGiorno.value ? [{ convocazione: 'seconda', numero: 1, fissata_il: `${secondaGiorno.value} ${secondaOra.value}` }] : []),
]);
/** Il controllo delle date mentre si scrivono, lo stesso di «Prepara l'assemblea». */
const controllo = computed(() => (primaGiorno.value ? controllaLeDate(sedute.value) : null));
const manca = computed(() => motivo.value.trim() === '' || !primaGiorno.value || !primaOra.value || (!!secondaGiorno.value && !secondaOra.value)
    || controllo.value?.valide === false);

function conferma() {
    if (manca.value) return;
    inCorso.value = true;
    router.post(props.rotta, {
        motivo: motivo.value.trim(),
        sedute: sedute.value,
    }, {
        onSuccess: () => emit('update:open', false),
        onError: (e) => { errori.value = e as Record<string, string>; },
        onFinish: () => { inCorso.value = false; },
    });
}
</script>

<template>
    <Dialog :open="open" @update:open="(v: boolean) => emit('update:open', v)">
        <DialogContent class="max-h-[90vh] overflow-y-auto sm:max-w-lg" @open-auto-focus.prevent>
            <DialogHeader>
                <DialogTitle class="flex items-center gap-2"><RotateCcw class="h-5 w-5 text-rose-600" /> Annulla e riconvoca</DialogTitle>
                <DialogDescription>L'assemblea si annulla con il motivo, e ne nasce una nuova con le date nuove, lo stesso ordine del giorno e i memo nei suoi punti.</DialogDescription>
            </DialogHeader>

            <div class="space-y-3 text-sm">
                <div v-if="riconvoca.costo" :class="classiNota('or')" data-testid="costo-riconvocazione">
                    <AlertTriangle class="mt-0.5 h-4 w-4 shrink-0" />
                    <p>{{ riconvoca.costo }}</p>
                </div>
                <div class="space-y-1.5">
                    <Label for="riconvoca-motivo" class="text-xs">Perché si annulla</Label>
                    <Textarea id="riconvoca-motivo" v-model="motivo" rows="2" class="min-h-[60px] resize-none text-sm"
                        placeholder="Per esempio: va aggiunto un punto sulla sostituzione del portone." data-testid="motivo-riconvocazione" />
                </div>
                <div class="grid gap-3 sm:grid-cols-2">
                    <div class="space-y-1.5">
                        <Label class="text-xs">Prima convocazione</Label>
                        <div class="flex gap-2">
                            <CampoGiorno v-model="primaGiorno" class="h-9 min-w-0 flex-1 text-sm" aria-label="Il giorno della prima convocazione" />
                            <Input v-model="primaOra" type="time" class="h-9 w-24 text-sm" aria-label="L'ora della prima convocazione" />
                        </div>
                    </div>
                    <div class="space-y-1.5">
                        <Label class="text-xs">Seconda convocazione <span class="font-normal text-slate-500 dark:text-slate-400">(facoltativa)</span></Label>
                        <div class="flex gap-2">
                            <CampoGiorno v-model="secondaGiorno" class="h-9 min-w-0 flex-1 text-sm" svuotabile aria-label="Il giorno della seconda convocazione" />
                            <Input v-model="secondaOra" type="time" class="h-9 w-24 text-sm" aria-label="L'ora della seconda convocazione" />
                        </div>
                    </div>
                </div>
                <p v-if="controllo" class="text-xs" :class="controllo.valide ? 'text-slate-600 dark:text-slate-300' : 'text-rose-700 dark:text-rose-300'"
                    data-testid="date-riconvocazione">{{ controllo.frase }}</p>
                <p class="text-xs text-slate-500 dark:text-slate-400">La seconda in un giorno diverso dalla prima, e non oltre dieci giorni. Gli invii e le prove dell'assemblea annullata restano.</p>
                <ul v-if="Object.keys(errori).length" class="list-disc pl-4 text-xs text-rose-700 dark:text-rose-300">
                    <li v-for="(m, k) in errori" :key="k">{{ m }}</li>
                </ul>
            </div>

            <DialogFooter class="items-center gap-2">
                <span v-if="motivo.trim() === ''" class="text-xs text-slate-500">Scrivi perché si annulla.</span>
                <!-- «Chiudi», non «Annulla»: accanto ad «Annulla e riconvoca» la stessa parola diceva due cose diverse. -->
                <Button variant="outline" size="sm" data-testid="chiudi-riconvocazione" @click="emit('update:open', false)">Chiudi</Button>
                <Button variant="destructive" size="sm" :disabled="manca || inCorso" data-testid="conferma-riconvocazione" @click="conferma">Annulla e riconvoca</Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
