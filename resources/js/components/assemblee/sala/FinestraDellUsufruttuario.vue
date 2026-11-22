<!--
SPDX-License-Identifier: AGPL-3.0-or-later
Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
-->
<script setup lang="ts">
/**
 * «Sul punto 4 vota l'usufruttuario?» (scena 10a della bozza delle scene della tappa 3; decisione 589, T1): di serie sui punti straordinari vota il nudo
 * proprietario (art. 67 c. 7 disp. att.); la legge fa votare l'usufruttuario anche lì in tre casi, e il presidente sceglie quale. Cambia solo chi vota: la
 * testa e i millesimi restano quelli dell'unità. «Che cosa ha detto» è facoltativo e va nel verbale come dichiarazione. Gli artt. 985, 986 e 1006 c.c. si
 * citano senza spiegarli.
 */
import { computed, ref, watch } from 'vue';
import vSelect from 'vue-select';
import 'vue-select/dist/vue-select.css';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { millesimi } from '@/lib/assemblee/sala/conti';
import type { PercheVotaLUsufruttuario } from '@/types/assemblee';
import { PERCHE_DELL_USUFRUTTUARIO } from './sala';

const props = defineProps<{ open: boolean; numero: number; unita: string; usufruttuario: string; nudo: string; millesimi: number }>();
const emit = defineEmits<{ (e: 'update:open', v: boolean): void; (e: 'scelto', perche: PercheVotaLUsufruttuario, detto: string): void }>();

const perche = ref<PercheVotaLUsufruttuario | null>(null);
const valoreDi = (o: { valore: PercheVotaLUsufruttuario }) => o.valore;
const detto = ref('');
watch(() => props.open, (aperta) => { if (aperta) { perche.value = null; detto.value = ''; } }, { immediate: true });
const opzioni = computed(() => PERCHE_DELL_USUFRUTTUARIO.map((p) => ({ valore: p.valore, etichetta: `${p.etichetta} · ${p.nota}` })));

function conferma() {
    if (!perche.value) return;
    emit('scelto', perche.value, detto.value.trim());
    emit('update:open', false);
}
</script>

<template>
    <Dialog :open="open" @update:open="(v: boolean) => emit('update:open', v)">
        <DialogContent class="max-h-[90vh] overflow-y-auto sm:max-w-lg" data-testid="finestra-usufruttuario" @open-auto-focus.prevent>
            <DialogHeader>
                <DialogTitle>Sul punto {{ numero }} vota l'usufruttuario?</DialogTitle>
                <DialogDescription>{{ unita }}: usufrutto di {{ usufruttuario }}, nuda proprietà di {{ nudo }}.</DialogDescription>
            </DialogHeader>
            <div class="space-y-3 text-sm">
                <ul class="list-disc space-y-0.5 pl-4 text-xs text-slate-700 dark:text-slate-300">
                    <li>Di serie, sui punti straordinari vota il nudo proprietario (art. 67 c. 7 disp. att.).</li>
                    <li>La legge fa votare l'usufruttuario anche qui in tre casi: scegli quale.</li>
                    <li>Cambia solo chi vota: la testa e i {{ millesimi(props.millesimi) }} millesimi restano quelli dell'{{ unita }}.</li>
                </ul>
                <div class="space-y-1.5">
                    <Label class="text-xs">Perché</Label>
                    <v-select v-model="perche" :options="opzioni" label="etichetta" :reduce="valoreDi" :clearable="false"
                        :searchable="false" data-testid="perche-usufruttuario" />
                </div>
                <div class="space-y-1.5">
                    <Label for="detto-usufruttuario" class="text-xs">Che cosa ha detto (facoltativo)</Label>
                    <Textarea id="detto-usufruttuario" v-model="detto" rows="2" class="min-h-[60px] resize-none text-sm" data-testid="detto-usufruttuario"
                        placeholder="Per esempio: «Il portone lo pago io.»" />
                    <p class="text-xs text-slate-500 dark:text-slate-400">Va nel verbale, che leggono tutti: niente dati delicati.</p>
                </div>
            </div>
            <DialogFooter class="gap-2">
                <Button variant="outline" size="sm" @click="emit('update:open', false)">Annulla</Button>
                <Button size="sm" :disabled="!perche" data-testid="conferma-usufruttuario" @click="conferma">Vota {{ usufruttuario }}, e il verbale lo scrive</Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
