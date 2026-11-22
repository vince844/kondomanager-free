<!--
SPDX-License-Identifier: AGPL-3.0-or-later
Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
-->
<script setup lang="ts">
/**
 * La seduta aperta senza la scelta del presidente: la pagina non sapeva del controllo dell'art. 1136 c. 6 (senza rete, o il controllo cambiato intanto), e il
 * server l'ha aperta lo stesso, «da decidere», perché niente del lavoro si perda. Il presidente decide adesso: «Apri, e il verbale lo scrive» con il motivo
 * (il gesto `apertura_decidi`), o «Rinvia l'assemblea». Finché non decide l'assemblea non si chiude né si sospende.
 */
import { computed, ref } from 'vue';
import { AlertTriangle } from 'lucide-vue-next';
import { useSala } from './contesto';
import FinestraDiTesto from './FinestraDiTesto.vue';

const emit = defineEmits<{ (e: 'rinvia'): void }>();
const { sala, segna, puoGestire } = useSala();
const seduta = computed(() => sala.value.sedute.find((s) => s.id === sala.value.seduta_id) ?? null);
const problemi = computed(() => seduta.value?.apertura_da_decidere ?? null);
const finestra = ref(false);
function deciso(motivo: string) {
    if (seduta.value) void segna('apertura_decidi', { seduta_id: seduta.value.id, motivo });
}
const pulsante = 'inline-flex h-8 items-center justify-center rounded-md border border-slate-200 bg-white px-2.5 text-xs font-medium text-slate-700 hover:bg-slate-50 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-200 dark:hover:bg-slate-800';
</script>

<template>
    <section v-if="problemi && problemi.length" class="space-y-2 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2.5 text-sm text-amber-950 dark:border-amber-800
        dark:bg-amber-950/40 dark:text-amber-100" data-testid="apertura-da-decidere">
        <p class="flex items-start gap-2 font-semibold">
            <AlertTriangle class="mt-0.5 h-4 w-4 shrink-0" aria-hidden="true" />
            Aperta senza la scelta del presidente: il controllo dell'apertura è cambiato mentre la sala era senza rete.
        </p>
        <ul class="list-disc space-y-0.5 pl-5 text-xs">
            <li v-for="(p, i) in problemi" :key="i">{{ p }}</li>
            <li>L'assemblea non può deliberare se non consta che tutti gli aventi diritto sono stati convocati (art. 1136 c. 6 c.c.). Decide il presidente.</li>
            <li>Finché non decide, l'assemblea non si chiude né si sospende.</li>
        </ul>
        <div v-if="puoGestire" class="flex flex-wrap gap-2">
            <button type="button" :class="pulsante" data-testid="apertura-rinvia" @click="emit('rinvia')">Rinvia l'assemblea</button>
            <button type="button" :class="pulsante" data-testid="apertura-decidi" @click="finestra = true">Apri, e il verbale lo scrive</button>
        </div>
        <FinestraDiTesto v-model:open="finestra" titolo="Apri, e il verbale lo scrive" etichetta="Il motivo del presidente"
            descrizione="Va nel verbale, che leggono tutti: niente dati delicati." esempio="Per esempio: Gallo Enzo è presente e non si oppone."
            pulsante="Il presidente decide" testid-finestra="finestra-apertura-decidi" testid-testo="motivo-apertura-decidi" testid-conferma="conferma-apertura-decidi"
            @salva="deciso" />
    </section>
</template>
