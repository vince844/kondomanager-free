<!--
SPDX-License-Identifier: AGPL-3.0-or-later
Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
-->
<script setup lang="ts">
/**
 * Il campo della ricerca sopra un elenco lungo di persone, come quello delle presenze della sala: la lente, la × che lo svuota (anche con Esc), a tutta
 * riga al telefono. Filtra chi lo usa, sul posto (`lib/assemblee/sala/cerca.ts`): il campo non chiede niente al server. Le parole del campo le dà chi lo
 * usa («Cerca per nome o per unità»), così stanno nella sua pagina. In una finestra Esc svuota la ricerca; a campo vuoto chiude la finestra, come sempre.
 */
import { ref } from 'vue';
import { Search, X } from 'lucide-vue-next';

const props = defineProps<{ modelValue: string; etichetta: string; testid: string; testidSvuota: string }>();
const emit = defineEmits<{ 'update:modelValue': [valore: string] }>();

const campo = ref<HTMLInputElement | null>(null);
function svuota() {
    emit('update:modelValue', '');
    campo.value?.focus();
}
function esc(e: KeyboardEvent) {
    if (!props.modelValue) return;
    e.stopPropagation();
    e.preventDefault();
    svuota();
}
</script>

<template>
    <div class="relative w-full sm:max-w-xs">
        <Search class="pointer-events-none absolute left-2.5 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400 dark:text-slate-500" aria-hidden="true" />
        <input ref="campo" :value="props.modelValue" type="text" inputmode="search" enterkeyhint="search" autocomplete="off" spellcheck="false"
            :placeholder="etichetta" :aria-label="etichetta"
            class="h-10 w-full rounded-md border border-slate-200 bg-white pl-8 pr-10 text-base text-slate-900 placeholder:text-slate-400 focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-400 sm:h-9 sm:text-sm dark:border-slate-700 dark:bg-slate-900 dark:text-white dark:placeholder:text-slate-500"
            :data-testid="testid" @input="emit('update:modelValue', ($event.target as HTMLInputElement).value)" @keydown.esc="esc" />
        <button v-if="props.modelValue" type="button" aria-label="Svuota la ricerca" :data-testid="testidSvuota"
            class="absolute right-1 top-1/2 inline-flex h-8 w-8 -translate-y-1/2 items-center justify-center rounded-md text-slate-500 hover:bg-slate-100 hover:text-slate-800 dark:text-slate-400 dark:hover:bg-slate-800 dark:hover:text-slate-100"
            @click="svuota">
            <X class="h-4 w-4" aria-hidden="true" />
        </button>
    </div>
</template>
