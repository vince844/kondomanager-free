<!--
SPDX-License-Identifier: AGPL-3.0-or-later
Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
-->
<script setup lang="ts">
/**
 * «Togli» una persona dalla lista di chi riceve il verbale (scena 6 della bozza delle scene, decisione 595 (3)): perché il programma la propone, «Se lo
 * lasci» e «Se lo togli» affiancati con i trenta giorni di ognuno, il perché obbligatorio, che resta nel registro delle modifiche.
 */
import { ref, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import { Loader2 } from 'lucide-vue-next';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import InputError from '@/components/InputError.vue';
import { CALCOLO_PRUDENTE, puntiInParole } from '@/lib/assemblee/verbale/pagina';
import { AIUTO, CONTENUTO, CORPO, GRUPPO, PIEDE, PULSANTI, RIEPILOGO, TESTA, TITOLO_GRUPPO } from './finestra';
import type { DestinatarioDelVerbale } from '@/types/verbale';

const props = defineProps<{ open: boolean; persona: DestinatarioDelVerbale | null; rotta: string; giornoDellaDelibera: string }>();
const emit = defineEmits<{ 'update:open': [v: boolean] }>();
const perche = ref('');
const errore = ref('');
const inCorso = ref(false);
watch(() => props.open, (a) => { if (a) { perche.value = ''; errore.value = ''; } });
function togli() {
    if (!props.persona || !perche.value.trim()) return;
    inCorso.value = true;
    router.post(props.rotta, { anagrafica_id: props.persona.anagrafica_id, perche: perche.value.trim() }, {
        preserveScroll: true,
        onSuccess: () => emit('update:open', false),
        onError: (e) => { errore.value = (e as Record<string, string>).perche ?? Object.values(e)[0] ?? ''; },
        onFinish: () => { inCorso.value = false; },
    });
}
</script>

<template>
    <Dialog :open="open" @update:open="(v: boolean) => emit('update:open', v)">
        <DialogContent v-if="persona" :class="CONTENUTO" data-testid="finestra-togli">
            <DialogHeader :class="TESTA">
                <DialogTitle>Togli {{ persona.nome }} dalla lista?</DialogTitle>
                <DialogDescription>Lo propongo perché: {{ persona.perche }}.</DialogDescription>
            </DialogHeader>
            <div :class="CORPO">
                <section :class="GRUPPO">
                    <div class="grid gap-2 sm:grid-cols-2">
                        <div class="rounded-lg border border-slate-200 px-3 py-2 text-xs dark:border-slate-800">
                            <p :class="TITOLO_GRUPPO">Se lo lasci</p>
                            <p class="mt-1 text-slate-700 dark:text-slate-300">Riceve il verbale ({{ persona.mezzo.parola.toLowerCase() }}). {{ puntiInParole(persona.punti_assente) }}: i trenta
                                giorni partono dalla consegna.</p>
                        </div>
                        <div class="rounded-lg border border-slate-200 px-3 py-2 text-xs dark:border-slate-800">
                            <p :class="TITOLO_GRUPPO">Se lo togli</p>
                            <p class="mt-1 text-slate-700 dark:text-slate-300">Non riceve il verbale. {{ puntiInParole(persona.punti_assente) }}: lo conto come chi c'era e non ha
                                votato, astenuto: dal {{ giornoDellaDelibera }}.</p>
                        </div>
                    </div>
                    <p :class="AIUTO">Se era davvero fuori e non riceve il verbale, il suo termine per quei punti non parte. Le date sono {{ CALCOLO_PRUDENTE }}.</p>
                </section>
                <section :class="GRUPPO">
                    <Label for="perche-togli" class="text-xs">Perché lo togli (obbligatorio)</Label>
                    <Textarea id="perche-togli" v-model="perche" rows="2" class="min-h-[60px] resize-none text-sm" data-testid="perche-togli"
                        placeholder="Per esempio: è rientrato alle 19:40, prima del voto del punto 4, e in sala non è stato segnato." />
                    <InputError :message="errore" />
                    <p :class="AIUTO">La scelta resta nel registro delle modifiche, con il perché.</p>
                </section>
            </div>
            <div :class="PIEDE">
                <p :class="RIEPILOGO" role="status"><Loader2 v-if="inCorso" class="h-3.5 w-3.5 shrink-0 animate-spin" aria-hidden="true" /><span>{{ persona.nome }} non riceverà il verbale.</span></p>
                <div :class="PULSANTI">
                    <Button variant="outline" @click="emit('update:open', false)">Chiudi</Button>
                    <Button :disabled="!perche.trim() || inCorso" data-testid="conferma-togli" @click="togli">Toglilo dalla lista</Button>
                </div>
            </div>
        </DialogContent>
    </Dialog>
</template>
