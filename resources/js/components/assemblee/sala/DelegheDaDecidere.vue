<!--
SPDX-License-Identifier: AGPL-3.0-or-later
Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
-->
<script setup lang="ts">
/**
 * Le deleghe oltre il quinto «da decidere» (con «Decide il presidente» nelle impostazioni dello studio): finché il presidente non decide **non contano**, né
 * nella barra né nel voto (art. 67 c. 1 disp. att.). Una riga per delega, con il conto del quinto, «Accetta con il motivo» e «Rifiuta»: tutte e due con il
 * motivo, che va nel verbale (il gesto `delega_decidi`). Prima dell'apertura e a sala aperta.
 */
import { computed, ref } from 'vue';
import { AlertTriangle } from 'lucide-vue-next';
import type { DelegaDellaSala } from '@/types/assemblee';
import { useSala } from './contesto';
import FinestraDiTesto from './FinestraDiTesto.vue';

const { sala, segna, puoGestire } = useSala();
const daDecidere = computed(() => sala.value.deleghe.filter((d) => d.stato === 'valida' && d.da_decidere));

const finestra = ref(false);
const scelta = ref<{ delega: DelegaDellaSala; accetta: boolean } | null>(null);
function decidi(delega: DelegaDellaSala, accetta: boolean) {
    scelta.value = { delega, accetta };
    finestra.value = true;
}
function deciso(motivo: string) {
    if (scelta.value) void segna('delega_decidi', { delega_id: scelta.value.delega.id, accetta: scelta.value.accetta, motivo });
}
const pulsante = 'inline-flex h-8 items-center justify-center rounded-md border border-slate-200 bg-white px-2.5 text-xs font-medium text-slate-700 hover:bg-slate-50 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-200 dark:hover:bg-slate-800';
</script>

<template>
    <section v-if="daDecidere.length" class="space-y-2 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2.5 text-sm text-amber-950 dark:border-amber-800 dark:bg-amber-950/40
        dark:text-amber-100" data-testid="deleghe-da-decidere">
        <p class="flex items-start gap-2 font-semibold">
            <AlertTriangle class="mt-0.5 h-4 w-4 shrink-0" aria-hidden="true" />
            {{ daDecidere.length === 1 ? 'Una delega oltre il quinto: decide il presidente.' : `${daDecidere.length} deleghe oltre il quinto: decide il presidente.` }}
        </p>
        <ul class="space-y-2">
            <li v-for="d in daDecidere" :key="d.id" class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between" :data-delega="d.id">
                <span class="min-w-0 text-xs">
                    <span class="font-medium">Delega di {{ d.delegante_nome }} a {{ d.delegato_nome }}</span><template v-if="d.quinto"> · {{ d.quinto }}</template>.
                    Finché il presidente non decide, non conta (art. 67 c. 1 disp. att.).
                </span>
                <span v-if="puoGestire" class="flex shrink-0 gap-1.5">
                    <button type="button" :class="pulsante" data-testid="delega-accetta" @click="decidi(d, true)">Accetta con il motivo</button>
                    <button type="button" :class="pulsante" data-testid="delega-rifiuta" @click="decidi(d, false)">Rifiuta</button>
                </span>
            </li>
        </ul>
        <FinestraDiTesto v-model:open="finestra" :titolo="scelta?.accetta ? 'Accettare la delega oltre il quinto?' : 'Rifiutare la delega oltre il quinto?'"
            :descrizione="scelta ? `Delega di ${scelta.delega.delegante_nome} a ${scelta.delega.delegato_nome}. Il motivo del presidente va nel verbale.` : ''"
            etichetta="Il motivo del presidente" esempio="Per esempio: il condòmino lo ha chiesto per iscritto, e nessuno si oppone."
            :pulsante="scelta?.accetta ? 'Accetta la delega' : 'Rifiuta la delega'" testid-finestra="finestra-delega-decidi" testid-testo="motivo-delega-decidi"
            testid-conferma="conferma-delega-decidi" @salva="deciso" />
    </section>
</template>
