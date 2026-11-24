<!--
SPDX-License-Identifier: AGPL-3.0-or-later
Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
-->
<script setup lang="ts">
/**
 * L'entrata di una testa con più persone (chi entra: un comproprietario, il nudo proprietario, il conduttore che interviene), e «In collegamento» quando
 * l'avviso non indica la videoconferenza (scena 4 della bozza approvata della sala, decisione 521 (4)): decide il presidente, il motivo va nel verbale, e nel
 * motivo niente dati sulla salute.
 *
 * **I contitolari presenti tutti e due** (decisione 664 (6), rilievo C53; art. 67 c. 2 disp. att.: l'unità in comproprietà ha un solo rappresentante):
 * «Chi entra in sala per l'Int. 5?» chiede chi rappresenta l'unità (vota) e, per ognuna delle altre persone della testa, la spunta «Anche Russo Anna è in
 * sala, senza voto». Il gesto `entra` le porta in `con`; la sala conta una testa, e chi è entrato insieme esce con chi la rappresenta.
 */
import { computed, ref, watch } from 'vue';
import vSelect from 'vue-select';
import 'vue-select/dist/vue-select.css';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import type { ComePresente } from '@/types/assemblee';
import type { Opzione } from './sala';

const props = withDefaults(defineProps<{
    open: boolean;
    candidati: Opzione[];
    come: ComePresente;
    serveIlMotivo: boolean;
    /** Le persone della testa (i contitolari): fra loro una rappresenta l'unità, le altre possono entrare insieme, senza voto. */
    persone?: number[];
    /** Le unità della testa, come si leggono: «Int. 5». */
    unita?: string;
    /** Le persone della testa che hanno anche un'unità tutta loro: entrano da sé e votano per la loro testa, mai «insieme» (revisione del 22/11). */
    conTestaSua?: number[];
}>(), { persone: () => [], unita: '', conTestaSua: () => [] });
const emit = defineEmits<{ (e: 'update:open', v: boolean): void; (e: 'entra', chi: number, motivo: string, con: number[]): void }>();

const chi = ref<number | null>(null);
const motivo = ref('');
const anche = ref<number[]>([]);
watch(() => props.open, (aperta) => {
    if (!aperta) return;
    chi.value = props.candidati.length === 1 ? props.candidati[0].id : null;
    motivo.value = '';
    anche.value = [];
}, { immediate: true });
// Cambiata la persona che rappresenta l'unità, le spunte ripartono vuote.
watch(chi, () => { anche.value = []; });
const nome = computed(() => props.candidati.find((c) => c.id === chi.value)?.etichetta ?? '');
const pronta = computed(() => chi.value !== null && (!props.serveIlMotivo || motivo.value.trim() !== ''));
/** Più persone della stessa testa: chi entra rappresenta l'unità e vota. */
const contitolari = computed(() => props.persone.length > 1);
/** Le altre persone della testa, se chi entra è una di loro (il conduttore entra da sé). */
const altre = computed(() => (chi.value !== null && props.persone.includes(chi.value)
    ? props.candidati.filter((c) => props.persone.includes(c.id) && c.id !== chi.value && !props.conTestaSua.includes(c.id)) : []));
const perLUnita = computed(() => (props.unita ? ` per l'${props.unita}` : ''));
const titolo = computed(() => {
    if (props.come === 'in_collegamento') return props.candidati.length > 1 ? `Chi è in collegamento${perLUnita.value}?` : `Segnare ${nome.value || 'la persona'} in collegamento?`;

    return `Chi entra in sala${perLUnita.value}?`;
});
function spunta(id: number, si: boolean | 'indeterminate') {
    anche.value = si === true ? [...new Set([...anche.value, id])] : anche.value.filter((x) => x !== id);
}

function conferma() {
    if (!pronta.value || chi.value === null) return;
    emit('entra', chi.value, motivo.value.trim(), anche.value.filter((x) => altre.value.some((a) => a.id === x)));
    emit('update:open', false);
}
</script>

<template>
    <Dialog :open="open" @update:open="(v: boolean) => emit('update:open', v)">
        <DialogContent class="max-h-[90vh] overflow-y-auto sm:max-w-lg" data-testid="finestra-entrata" @open-auto-focus.prevent>
            <DialogHeader>
                <DialogTitle>{{ titolo }}</DialogTitle>
                <DialogDescription v-if="serveIlMotivo">L'avviso di questa assemblea non indica la videoconferenza.</DialogDescription>
            </DialogHeader>
            <div class="space-y-3 text-sm">
                <div v-if="candidati.length > 1" class="space-y-1.5">
                    <Label class="text-xs">{{ contitolari ? 'Chi rappresenta l\'unità (vota)' : 'Chi' }}</Label>
                    <v-select v-model="chi" :options="candidati" label="etichetta" :reduce="(c: Opzione) => c.id" :clearable="false" data-testid="chi-entra" />
                </div>
                <!-- Gli altri contitolari: presenti, senza voto (art. 67 c. 2 disp. att.) -->
                <div v-if="altre.length > 0" class="space-y-1.5">
                    <label v-for="a in altre" :key="a.id" class="flex cursor-pointer items-start gap-2 text-sm text-slate-800 dark:text-slate-200" :data-testid="`anche-${a.id}`">
                        <Checkbox :model-value="anche.includes(a.id)" class="mt-0.5 rounded" @update:model-value="(v: boolean | 'indeterminate') => spunta(a.id, v)" />
                        <span>Anche {{ a.etichetta }} è {{ come === 'in_collegamento' ? 'in collegamento' : 'in sala' }}, senza voto</span>
                    </label>
                    <p class="text-xs text-slate-500 dark:text-slate-400">L'unità in comproprietà ha un solo rappresentante, che vota (art. 67 c. 2 disp. att.): la sala conta una testa.</p>
                </div>
                <template v-if="serveIlMotivo">
                    <ul class="list-disc space-y-0.5 pl-4 text-xs text-slate-700 dark:text-slate-300">
                        <li>Per partecipare in videoconferenza la legge chiede la piattaforma nell'avviso e il consenso della maggioranza dei condòmini (art. 66 c. 3 e 6 disp. att.).</li>
                        <li>Decide il presidente; se lo segni, il verbale lo scrive con il motivo.</li>
                    </ul>
                    <div class="space-y-1.5">
                        <Label for="motivo-collegamento" class="text-xs">Perché</Label>
                        <Textarea id="motivo-collegamento" v-model="motivo" rows="2" class="min-h-[60px] resize-none text-sm" data-testid="motivo-collegamento"
                            placeholder="Per esempio: fuori città per lavoro, chiede di seguire in collegamento; nessuno dei presenti si oppone." />
                        <p class="text-xs text-amber-800 dark:text-amber-300">Nel motivo niente dati sulla salute o altri dati delicati: il verbale va a tutti.</p>
                    </div>
                </template>
            </div>
            <DialogFooter class="gap-2">
                <Button variant="outline" size="sm" @click="emit('update:open', false)">Annulla</Button>
                <Button size="sm" :disabled="!pronta" data-testid="conferma-entrata" @click="conferma">
                    {{ serveIlMotivo ? 'Segna, e il verbale lo scrive' : come === 'in_collegamento' ? 'In collegamento' : 'Entra' }}
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
