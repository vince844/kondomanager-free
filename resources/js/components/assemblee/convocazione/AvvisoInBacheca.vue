<!--
SPDX-License-Identifier: AGPL-3.0-or-later
Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
-->
<script setup lang="ts">
/**
 * «Avviso anche in bacheca»: l'interruttore apre la pagina della comunicazione già compilata, con la frase pubblica dei punti e «Questo avviso non vale
 * come convocazione»; la comunicazione si salva per la sua strada (la moderazione, il registro, gli avvisi) e torna legata all'assemblea. Pubblicato,
 * l'interruttore resta acceso e la riga «Adesso: …» dice quando; «Apri l'avviso» porta alla comunicazione. In bacheca nessun dato di una persona.
 *
 * È una card, in basso nel passo, affiancata a «La prova della convocazione» e alta quanto lei (la bozza approvata del passo senza spazi vuoti).
 */
import { router } from '@inertiajs/vue3';
import { Megaphone } from 'lucide-vue-next';
import { Switch } from '@/components/ui/switch';
import type { SchedaDellaConvocazione } from './tipi';

const props = defineProps<{ bacheca: SchedaDellaConvocazione['bacheca']; rotta: string; gestisce: boolean }>();

function accendi(si: boolean) {
    if (!si || props.bacheca.acceso || !props.bacheca.si_puo || !props.gestisce) return;
    router.post(props.rotta, {});
}
</script>

<template>
    <div class="flex min-w-0 flex-col rounded-xl border border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-950" data-testid="card-bacheca">
        <div class="px-4 pb-2.5 pt-3.5 sm:px-5">
            <h3 class="flex items-center gap-2 text-base font-semibold text-slate-900 dark:text-slate-100"><Megaphone class="h-4 w-4 shrink-0" /> Avviso anche in bacheca</h3>
        </div>
        <div class="flex items-start gap-3 px-4 pb-4 sm:px-5" data-testid="avviso-in-bacheca">
            <Switch
                :model-value="bacheca.acceso"
                :disabled="bacheca.acceso || !bacheca.si_puo || !gestisce"
                aria-label="Avviso anche in bacheca"
                class="mt-0.5"
                @update:model-value="(v: boolean) => accendi(v)"
            />
            <div class="min-w-0 flex-1 space-y-1 text-xs leading-relaxed text-slate-500 dark:text-slate-400">
                <p>Adesso: {{ bacheca.adesso }}</p>
                <p v-if="!bacheca.si_puo && bacheca.perche">{{ bacheca.perche }}</p>
                <a v-if="bacheca.acceso && bacheca.url" :href="bacheca.url" class="inline-block font-medium text-slate-700 underline underline-offset-2 dark:text-slate-300">Apri l'avviso</a>
            </div>
        </div>
    </div>
</template>
