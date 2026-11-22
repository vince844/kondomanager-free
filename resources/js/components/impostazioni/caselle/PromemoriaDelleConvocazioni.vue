<!--
SPDX-License-Identifier: AGPL-3.0-or-later
Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
-->
<script setup lang="ts">
/**
 * «I promemoria per email», sotto le caselle dello studio: «Promemoria per email delle convocazioni da spedire», acceso di serie. Va a chi ha preparato
 * l'assemblea, due giorni lavorativi prima del giorno limite del compito «Spedire la convocazione entro il …» e la mattina di quel giorno, se restano avvisi
 * da spedire. L'interruttore si cambia da solo, al clic, senza salvare la casella; la riga «Adesso» arriva dal server, che dice anche quando senza un server
 * di posta il promemoria non parte.
 */
import { ref, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import { Switch } from '@/components/ui/switch';
import type { PromemoriaDelleConvocazioni } from './tipi';

const props = defineProps<{ promemoria: PromemoriaDelleConvocazioni }>();

const acceso = ref(props.promemoria.acceso);
watch(() => props.promemoria.acceso, (v) => { acceso.value = v; });

function cambia(v: boolean) {
    acceso.value = v;
    router.post(route('admin.settings.mail.promemoria'), { acceso: v }, {
        preserveScroll: true,
        only: ['promemoria'],
        // Non è andato: l'interruttore torna com'era.
        onError: () => { acceso.value = props.promemoria.acceso; },
    });
}
</script>

<template>
    <div class="overflow-hidden rounded-xl border border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-950" data-testid="promemoria-convocazioni">
        <div class="border-b border-slate-100 px-4 py-3.5 sm:px-5 dark:border-slate-800">
            <h3 class="text-[15px] font-semibold text-slate-900 dark:text-slate-100">I promemoria per email</h3>
            <p class="mt-0.5 text-sm text-slate-500 dark:text-slate-400">Il programma ti ricorda per email ciò che hai da fare entro un giorno; il compito resta
                nell'Action inbox.</p>
        </div>
        <div class="flex items-start justify-between gap-4 px-4 py-3.5 sm:px-5">
            <div class="min-w-0 flex-1">
                <label for="interruttore-promemoria" class="text-sm font-medium text-slate-900 dark:text-slate-100">Promemoria per email delle convocazioni da spedire</label>
                <p class="mt-0.5 text-xs leading-relaxed text-slate-500 dark:text-slate-400">Esempio: con le raccomandate da spedire entro giovedì 03/12, l'email arriva
                    martedì 01/12 e giovedì 03/12, la mattina.</p>
                <p class="mt-2 rounded-md bg-slate-50 px-2.5 py-1.5 text-xs leading-snug text-slate-700 dark:bg-slate-800/50 dark:text-slate-300" data-testid="adesso-promemoria">
                    {{ promemoria.adesso }}</p>
            </div>
            <Switch id="interruttore-promemoria" :model-value="acceso" data-testid="interruttore-promemoria" @update:model-value="(v: boolean) => cambia(v)" />
        </div>
    </div>
</template>
