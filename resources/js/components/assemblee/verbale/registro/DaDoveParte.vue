<!--
SPDX-License-Identifier: AGPL-3.0-or-later
Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
-->
<script setup lang="ts">
/**
 * «Da dove parte il registro dei verbali», la prima volta (scena 3 della bozza delle scene): «Continua il libro di carta» con l'ultimo numero e la data
 * (facoltativa), o «Comincia dal n. 1». Si cambia finché non c'è un verbale registrato; ogni cambio va nel registro delle modifiche.
 */
import { computed, ref, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import { Loader2 } from 'lucide-vue-next';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import CampoGiorno from '@/components/CampoGiorno.vue';
import InputError from '@/components/InputError.vue';
// Oggi a Roma, dove il programma conta i giorni, non sul computer: su un altro fuso il giorno del computer può essere ancora ieri (rilievo C54).
import { giornoDiRoma } from '@/components/assemblee/sala/sala';
import { AIUTO, CONTENUTO, CORPO, GRUPPO, PIEDE, PULSANTI, RIEPILOGO, SCELTE, TESTA, scelta } from '../finestra';
import type { PaginaDelRegistroDeiVerbali } from '@/types/verbale';

const props = defineProps<{ open: boolean; registro: PaginaDelRegistroDeiVerbali }>();
const emit = defineEmits<{ 'update:open': [v: boolean] }>();
const oggi = giornoDiRoma();
const come = ref<'libro' | 'da_uno'>('libro');
const ultimo = ref('');
const del_ = ref('');
const errori = ref<Record<string, string>>({});
const inCorso = ref(false);
watch(() => props.open, (a) => {
    if (!a) return;
    const p = props.registro.partenza;
    come.value = p?.scelta ?? 'libro';
    ultimo.value = p?.ultimo ? String(p.ultimo) : '';
    del_.value = p?.del ?? '';
    errori.value = {};
});
const prossimo = computed(() => (come.value === 'da_uno' ? 1 : Number(ultimo.value) > 0 ? Number(ultimo.value) + 1 : null));
function salva() {
    inCorso.value = true;
    router.post(props.registro.rotte.partenza, { registro: props.registro.perimetro.chiave, scelta: come.value,
        ...(come.value === 'libro' ? { ultimo: Number(ultimo.value) || null, del: del_.value || null } : {}) }, {
        preserveScroll: true,
        onSuccess: () => emit('update:open', false),
        onError: (e) => { errori.value = e as Record<string, string>; },
        onFinish: () => { inCorso.value = false; },
    });
}
</script>

<template>
    <Dialog :open="open" @update:open="(v: boolean) => emit('update:open', v)">
        <DialogContent :class="CONTENUTO" data-testid="finestra-da-dove-parte">
            <DialogHeader :class="TESTA">
                <DialogTitle>Da dove parte il registro dei verbali</DialogTitle>
                <DialogDescription>{{ registro.perimetro.nome }} · lo chiedo una volta sola.</DialogDescription>
            </DialogHeader>
            <div :class="CORPO">
                <section :class="GRUPPO">
                    <div :class="[SCELTE, 'grid-cols-2']" role="radiogroup" aria-label="Da dove parte il registro">
                        <button type="button" role="radio" :aria-checked="come === 'libro'" :class="scelta(come === 'libro')" data-testid="continua-il-libro" @click="come = 'libro'">
                            Continua il libro di carta</button>
                        <button type="button" role="radio" :aria-checked="come === 'da_uno'" :class="scelta(come === 'da_uno')" data-testid="dal-numero-uno" @click="come = 'da_uno'">
                            Comincia dal n. 1</button>
                    </div>
                    <p :class="AIUTO">{{ come === 'libro' ? 'Lo studio tiene già il libro dei verbali.' : 'Un condominio nuovo, o un libro nuovo.' }}</p>
                    <div v-if="come === 'libro'" class="grid gap-3 sm:grid-cols-2">
                        <div class="space-y-1.5">
                            <Label for="ultimo-numero" class="text-xs">L'ultimo numero del libro</Label>
                            <Input id="ultimo-numero" v-model="ultimo" inputmode="numeric" class="h-9 text-sm" data-testid="ultimo-numero" />
                            <InputError :message="errori.ultimo" />
                        </div>
                        <div class="space-y-1.5">
                            <Label class="text-xs">Del (facoltativo)</Label>
                            <CampoGiorno v-model="del_" :max="oggi" svuotabile class="h-9 text-sm" aria-label="Il giorno dell'ultimo verbale del libro" />
                            <p :class="AIUTO">La data serve a riconoscerlo nella stampa del registro.</p>
                        </div>
                    </div>
                </section>
                <section :class="GRUPPO">
                    <ul class="list-disc space-y-0.5 pl-5 text-xs text-slate-700 dark:text-slate-300">
                        <li>Lo cambi finché non registri il primo verbale; dopo, il numero va solo avanti.</li>
                        <li>Le assemblee deserte si annotano senza numero.</li>
                        <li>Il libro di carta resta: per ogni verbale e ogni annotazione stampi la pagina da incollare.</li>
                        <li>La scelta resta nel registro delle modifiche.</li>
                    </ul>
                </section>
            </div>
            <div :class="PIEDE">
                <p :class="RIEPILOGO" role="status"><Loader2 v-if="inCorso" class="h-3.5 w-3.5 shrink-0 animate-spin" aria-hidden="true" />
                    <span>{{ prossimo ? `Il prossimo verbale registrato qui prende il n. ${prossimo}.` : 'Scrivi l\'ultimo numero del libro.' }}</span></p>
                <div :class="PULSANTI">
                    <Button variant="outline" @click="emit('update:open', false)">Chiudi</Button>
                    <Button :disabled="prossimo === null || inCorso" data-testid="conferma-partenza" @click="salva">Continua dal n. {{ prossimo ?? '…' }}</Button>
                </div>
            </div>
        </DialogContent>
    </Dialog>
</template>
