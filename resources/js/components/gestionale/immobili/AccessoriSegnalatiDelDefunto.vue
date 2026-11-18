<!--
SPDX-License-Identifier: AGPL-3.0-or-later
Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
-->
<script setup lang="ts">
/**
 * Decisione 448: nella successione con l'arretrato agli eredi, gli interessi e le spese del defunto con un pagamento segnalato dal portale e non ancora
 * verificato. Di serie restano al defunto, come la rata in bozza segnalata; con la casella della sua riga l'amministratore passa l'accessorio comunque
 * agli eredi, per quota come gli altri, e la segnalazione resta da verificare; la riga dice come si registra il pagamento, se risulta arrivato. Le righe le
 * manda il server con l'anteprima; la casella manda l'id della quota, e l'anteprima si rifà.
 */
import type { AccessorioSegnalato } from '@/types/gestionale/passaggi';

const props = defineProps<{ accessori: AccessorioSegnalato[]; defunto: string; modelValue: number[] }>();
const emit = defineEmits<{ (e: 'update:modelValue', v: number[]): void }>();

const spuntato = (a: AccessorioSegnalato) => props.modelValue.includes(a.quota_id);
function cambia(a: AccessorioSegnalato, acceso: boolean) {
    emit('update:modelValue', acceso ? [...props.modelValue.filter((id) => id !== a.quota_id), a.quota_id] : props.modelValue.filter((id) => id !== a.quota_id));
}
</script>

<template>
    <div v-if="accessori.length" class="space-y-2" data-testid="accessori-segnalati">
        <label v-for="a in accessori" :key="a.quota_id"
            class="flex items-start gap-2.5 rounded-lg border px-3.5 py-2.5 cursor-pointer select-none transition-colors"
            :class="spuntato(a) ? 'bg-white border-emerald-300 dark:bg-slate-950 dark:border-emerald-800' : 'bg-white border-slate-200 dark:bg-slate-950 dark:border-slate-700'"
            data-testid="accessorio-segnalato">
            <input type="checkbox" :checked="spuntato(a)" @change="cambia(a, ($event.target as HTMLInputElement).checked)"
                class="w-4 h-4 mt-0.5 accent-emerald-600 rounded border-slate-300" />
            <span class="flex flex-col gap-0.5 min-w-0">
                <span class="text-sm font-semibold text-slate-900 dark:text-slate-100">Passa comunque agli eredi «{{ a.causale }}» ({{ a.aperto_formattato }})</span>
                <span class="text-xs text-slate-600 dark:text-slate-400 leading-snug">Ha un pagamento segnalato dal portale e non ancora verificato: senza la spunta resta a nome di {{ defunto }}.</span>
                <span v-if="spuntato(a)" class="text-xs text-slate-600 dark:text-slate-400 leading-snug">Passa agli eredi per quota, come gli altri; la segnalazione resta da verificare.
                    Se il pagamento risulta arrivato, registralo con «Registra l'incasso» dal compito del bonifico segnalato, sulla quota di ogni erede con «Versato da» {{ defunto }}: il primo incasso chiude il compito.</span>
            </span>
        </label>
    </div>
</template>
