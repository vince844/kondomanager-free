<!--
SPDX-License-Identifier: AGPL-3.0-or-later
Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
-->
<script setup lang="ts">
/**
 * «Paga per l'urgenza» (decisione 561; scena 1a della bozza delle scene della tappa 2): la spesa urgente oltre il preventivo si paga subito e si riferisce
 * alla prima assemblea (art. 1135 c. 2 c.c.). È lo sblocco del pagamento di oggi (`fatture.approva-sforo`, che resta com'è), con il motivo dell'urgenza
 * obbligatorio nella finestra. **Non è la ratifica**: la fattura resta «da ratificare» finché non si sceglie la sua delibera, e il programma propone la spesa
 * fra i punti della prossima assemblea.
 */
import { computed, ref, useId, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import { Zap } from 'lucide-vue-next';
import SuggerimentoDellaI from '@/components/assemblee/convocazione/SuggerimentoDellaI.vue';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { usePermission } from '@/composables/permissions';
import { euro, giornoItaliano } from '@/lib/assemblee/assemblee';
import type { FatturaDellaRatifica } from '@/lib/assemblee/ratifica';

const props = defineProps<{ open: boolean; condominioId: number; condominioNome: string; fattura: FatturaDellaRatifica }>();
const emit = defineEmits<{ (e: 'update:open', v: boolean): void; (e: 'fatto'): void }>();

const { generateRoute } = usePermission();
const motivo = ref('');
/** Il campo del perché: la sua etichetta lo dice con `for`, perché la «i» accanto è un pulsante e non può stare dentro l'etichetta. */
const idDelMotivo = useId();
const errore = ref<string | null>(null);
const inCorso = ref(false);

// La motivazione si scrive una volta sola: la finestra parte con quella della registrazione della fattura, e si cambia solo se serve.
const ripresa = computed(() => (props.fattura.motivazione_sforo ?? '').trim());
watch(() => props.open, (a) => {
    if (a) {
        motivo.value = ripresa.value;
        errore.value = null;
    }
}, { immediate: true });

const corto = computed(() => motivo.value.trim().length < 10);
const quale = computed(() => [`Fattura n. ${props.fattura.numero_documento}`, props.fattura.data_documento ? `del ${giornoItaliano(props.fattura.data_documento)}` : null]
    .filter(Boolean).join(' '));
const testata = computed(() => [quale.value, props.fattura.fornitore_nome, props.fattura.totale_documento !== null ? euro(props.fattura.totale_documento) : null]
    .filter(Boolean).join(' · '));
const oltre = computed(() => props.fattura.sforo_oltre
    ? `La spesa supera il preventivo${props.fattura.voce_sforo ? ` della voce «${props.fattura.voce_sforo}»` : ''} di ${euro(props.fattura.sforo_oltre)}.`
    : 'La spesa supera il preventivo.');

function sblocca() {
    if (corto.value) return;
    inCorso.value = true;
    errore.value = null;
    router.post(route(generateRoute('gestionale.fatture.approva-sforo'), { condominio: props.condominioId, fattura: props.fattura.id }), { note: motivo.value.trim() }, {
        preserveScroll: true,
        onSuccess: () => { emit('fatto'); emit('update:open', false); },
        onError: (e: Record<string, string>) => { errore.value = Object.values(e)[0] ?? 'Il pagamento non è stato sbloccato. Riprova.'; },
        onFinish: () => { inCorso.value = false; },
    });
}
</script>

<template>
    <Dialog :open="open" @update:open="$emit('update:open', $event)">
        <DialogContent class="max-h-[100dvh] overflow-y-auto sm:max-w-[560px]" data-testid="finestra-paga-per-urgenza" @open-auto-focus.prevent>
            <DialogHeader>
                <DialogTitle>Paga per l'urgenza</DialogTitle>
                <DialogDescription>{{ testata }}</DialogDescription>
            </DialogHeader>
            <div class="space-y-3.5 text-sm">
                <div class="flex items-start gap-2.5 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2.5 text-amber-900 dark:border-amber-800 dark:bg-amber-950/40 dark:text-amber-200">
                    <Zap class="mt-0.5 h-4 w-4 shrink-0" />
                    <div>
                        <p class="font-medium">Si paga subito, e l'assemblea ne sente parlare dopo.</p>
                        <p class="mt-0.5 text-xs">{{ oltre }} Per i lavori urgenti puoi pagare e riferirne alla prima assemblea (art. 1135 c. 2); la ratifica, se la chiedi, arriva con la sua delibera.</p>
                    </div>
                </div>
                <div>
                    <span class="mb-1 flex items-center gap-1 text-xs font-medium text-slate-600 dark:text-slate-400"><label :for="idDelMotivo">Perché era urgente</label>
                        <SuggerimentoDellaI testid="i-perche-urgente" testo="Per esempio: perdita dalla colonna dell'acqua, riparata il 12/11 per non allagare gli appartamenti sotto." />
                    </span>
                    <textarea :id="idDelMotivo" v-model="motivo" rows="3" class="w-full rounded-md border border-input bg-transparent px-3 py-2 text-sm dark:bg-slate-950" data-testid="motivo-urgenza"
                        placeholder="Perdita dalla colonna dell'acqua: riparata il 12/11 per non allagare gli appartamenti sotto." />
                    <span v-if="ripresa !== ''" class="mt-1 block text-xs text-slate-500 dark:text-slate-400" data-testid="motivo-ripreso">È la motivazione scritta quando hai registrato la fattura: cambiala solo se serve.</span>
                    <span class="mt-1 block text-xs text-slate-500 dark:text-slate-400">Resta sulla fattura e va sotto il punto dell'ordine del giorno: lo leggono anche i condòmini, nell'avviso di convocazione. Lo sblocco resta nel registro delle modifiche.</span>
                    <span v-if="motivo.length > 0 && corto" class="mt-1 block text-xs text-rose-700 dark:text-rose-400">Scrivi perché era urgente, almeno in dieci caratteri.</span>
                </div>
                <p class="text-xs text-slate-600 dark:text-slate-300" data-testid="adesso-urgenza">
                    Adesso: la fattura aspetta la ratifica e non si paga. Con «Sblocca il pagamento» si paga come le altre, e il programma propone la spesa nell'ordine
                    del giorno della prossima assemblea di {{ condominioNome }}.
                </p>
                <p class="text-xs font-medium text-slate-700 dark:text-slate-300">Non è la ratifica: quella la dà l'assemblea. La fattura resta «da ratificare» finché non scegli la sua delibera.</p>
                <p v-if="errore" class="text-sm text-rose-700 dark:text-rose-400" data-testid="errore-urgenza">{{ errore }}</p>
            </div>
            <div class="flex flex-col-reverse gap-2 border-t border-slate-100 pt-3 sm:flex-row sm:justify-end dark:border-slate-800">
                <Button variant="outline" @click="$emit('update:open', false)">Annulla</Button>
                <Button :disabled="corto || inCorso" data-testid="sblocca-pagamento" @click="sblocca">{{ inCorso ? 'Un momento…' : 'Sblocca il pagamento' }}</Button>
            </div>
        </DialogContent>
    </Dialog>
</template>
