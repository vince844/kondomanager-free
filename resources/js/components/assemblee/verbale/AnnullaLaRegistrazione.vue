<!--
SPDX-License-Identifier: AGPL-3.0-or-later
Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
-->
<script setup lang="ts">
/**
 * «Annulla la registrazione» con il perché (scena 4 della bozza delle scene, decisione 595 (5)): la riga resta nel registro, «Annullato», con il perché, il
 * giorno e chi; il numero non torna, e la registrazione giusta prende il numero dopo e dice che sostituisce questo. Il pulsante per uscire è «Chiudi»; se
 * il verbale era già partito, la finestra dice a chi.
 */
import { computed, ref, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import { AlertTriangle, Loader2 } from 'lucide-vue-next';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import InputError from '@/components/InputError.vue';
import { giorno, ora } from '@/lib/assemblee/verbale/pagina';
import { ARANCIO, CONTENUTO, CORPO, GRUPPO, PIEDE, PULSANTI, RIEPILOGO, TESTA, TITOLO_GRUPPO } from './finestra';
import type { VerbaleDellaPagina } from '@/types/verbale';

const props = defineProps<{ open: boolean; verbale: VerbaleDellaPagina; rotta: string }>();
const emit = defineEmits<{ 'update:open': [v: boolean] }>();
const motivo = ref('');
const errore = ref('');
const inCorso = ref(false);
watch(() => props.open, (a) => { if (a) { motivo.value = ''; errore.value = ''; } });
const partiti = computed(() => (props.verbale.trasmissione?.persone ?? []).filter((p) => p.riceve && p.invio?.spedito));

function annulla() {
    if (!motivo.value.trim()) return;
    inCorso.value = true;
    router.post(props.rotta, { motivo: motivo.value.trim() }, {
        preserveScroll: true,
        onSuccess: () => emit('update:open', false),
        onError: (e) => { errore.value = (e as Record<string, string>).motivo ?? Object.values(e)[0] ?? ''; },
        onFinish: () => { inCorso.value = false; },
    });
}
</script>

<template>
    <Dialog :open="open" @update:open="(v: boolean) => emit('update:open', v)">
        <DialogContent :class="CONTENUTO" data-testid="finestra-annulla">
            <DialogHeader :class="TESTA">
                <DialogTitle>Annulla la registrazione del verbale n. {{ verbale.numero }}</DialogTitle>
                <DialogDescription>Registrato il {{ giorno(verbale.registrato_il) }} alle {{ ora(verbale.registrato_il) }}<template v-if="verbale.registrato_da"> da {{ verbale.registrato_da }}</template>.</DialogDescription>
            </DialogHeader>
            <div :class="CORPO">
                <section :class="GRUPPO">
                    <Label for="motivo-annulla" class="text-xs">Perché (obbligatorio)</Label>
                    <Textarea id="motivo-annulla" v-model="motivo" rows="2" class="min-h-[60px] resize-none text-sm" data-testid="motivo-annulla"
                        placeholder="Per esempio: la scansione caricata è di un'altra assemblea." />
                    <p class="text-xs text-slate-500 dark:text-slate-400">Si legge nel registro e nella sua stampa: scrivilo per chi lo leggerà fra dieci anni.</p>
                    <InputError :message="errore" />
                </section>
                <section :class="GRUPPO">
                    <p :class="TITOLO_GRUPPO">Che cosa succede</p>
                    <ul class="list-disc space-y-0.5 pl-5 text-xs text-slate-700 dark:text-slate-300">
                        <li>La riga {{ verbale.numero }} resta nel registro, «Annullato», con il perché, il giorno e chi.</li>
                        <li>Il numero {{ verbale.numero }} non torna: la registrazione giusta prende il numero dopo, e dice che sostituisce il {{ verbale.numero }}.</li>
                        <li>Il testo torna in bozza, come versione nuova: se la copia giusta è già firmata, la registri subito.</li>
                    </ul>
                    <p v-if="partiti.length" :class="ARANCIO" data-testid="annulla-gia-partito">
                        <AlertTriangle class="mt-0.5 h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                        <span>È già partito a {{ partiti.map((p) => p.nome).join(', ') }}: il verbale giusto riparte per tutti.</span>
                    </p>
                    <p v-else class="text-xs text-slate-600 dark:text-slate-400">Non ancora trasmesso.</p>
                </section>
            </div>
            <div :class="PIEDE">
                <p :class="RIEPILOGO" role="status"><Loader2 v-if="inCorso" class="h-3.5 w-3.5 shrink-0 animate-spin" aria-hidden="true" />
                    <span>La riga resta, annullata.</span></p>
                <div :class="PULSANTI">
                    <Button variant="outline" @click="emit('update:open', false)">Chiudi</Button>
                    <Button variant="destructive" :disabled="!motivo.trim() || inCorso" data-testid="conferma-annulla" @click="annulla">Annulla il n. {{ verbale.numero }}</Button>
                </div>
            </div>
        </DialogContent>
    </Dialog>
</template>
