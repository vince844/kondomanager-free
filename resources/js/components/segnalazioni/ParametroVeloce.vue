<!--
SPDX-License-Identifier: AGPL-3.0-or-later
Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
-->
<script setup lang="ts">
/**
 * Un valore della card dei parametri della scheda della segnalazione (lo stato lavorazione, la priorità) che si cambia con un clic (Coda 671,
 * decisioni 466 (2) e 487). Per chi può modificare la segnalazione il valore è un pulsante che apre un menu con i valori; la scelta la manda
 * fuori (`scegli`), e la pagina la salva. Per gli altri resta testo, com'era. La freccia accanto al valore si vede sempre, non solo al
 * passaggio del mouse; al telefono il pulsante e le voci sono alti 40 px.
 */
import { computed, type Component } from 'vue';
import { trans } from 'laravel-vue-i18n';
import { Check, ChevronDown } from 'lucide-vue-next';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';

interface Opzione {
    value: string;
    label: string;
    icon: Component;
    colorClass: string;
}

const props = defineProps<{
    /** Il nome del campo per le prove e per chi legge la pagina: `stato` o `priority`. */
    campo: string;
    opzioni: Opzione[];
    valore: string | null | undefined;
    /** Il nome del comando per chi non vede: «Cambia lo stato lavorazione». */
    comando: string;
    /** Che cosa si legge quando il valore manca. */
    vuoto: string;
    puoModificare: boolean;
    inCorso?: boolean;
}>();

const emit = defineEmits<{ (e: 'scegli', valore: string): void }>();

const scelta = computed(() => props.opzioni.find((o) => o.value === props.valore) ?? null);
const testo = computed(() => (scelta.value ? trans(scelta.value.label) : props.vuoto));
</script>

<template>
    <DropdownMenu v-if="puoModificare">
        <DropdownMenuTrigger as-child>
            <button
                type="button"
                :data-parametro-veloce="campo"
                :aria-label="`${comando}: ${testo}`"
                :disabled="inCorso"
                class="-mx-1.5 flex min-h-8 w-[calc(100%+0.75rem)] min-w-0 items-center gap-2 rounded-md px-1.5 py-1 text-left transition-colors hover:bg-slate-100 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring disabled:opacity-60 pointer-coarse:min-h-10 dark:hover:bg-slate-800"
            >
                <component :is="scelta.icon" v-if="scelta" class="w-4 h-4 shrink-0" :class="scelta.colorClass" />
                <span class="text-sm font-bold truncate" :class="scelta ? 'text-slate-900 dark:text-slate-100' : 'text-slate-400'" :title="testo">{{ testo }}</span>
                <ChevronDown class="ml-auto h-3.5 w-3.5 shrink-0 text-slate-400" aria-hidden="true" />
            </button>
        </DropdownMenuTrigger>
        <DropdownMenuContent align="start" class="min-w-44">
            <DropdownMenuItem
                v-for="o in opzioni"
                :key="o.value"
                :data-voce="o.value"
                role="menuitemradio"
                :aria-checked="o.value === valore ? 'true' : 'false'"
                class="gap-2 pointer-coarse:min-h-10"
                @select="emit('scegli', o.value)"
            >
                <component :is="o.icon" class="w-4 h-4 shrink-0" :class="o.colorClass" />
                <span>{{ trans(o.label) }}</span>
                <Check v-if="o.value === valore" class="ml-auto h-4 w-4 text-slate-500" aria-hidden="true" />
            </DropdownMenuItem>
        </DropdownMenuContent>
    </DropdownMenu>

    <template v-else>
        <div v-if="scelta" class="flex items-center gap-2" :data-parametro="campo">
            <component :is="scelta.icon" class="w-4 h-4 shrink-0" :class="scelta.colorClass" />
            <span class="text-sm font-bold text-slate-900 dark:text-slate-100 truncate" :title="testo">{{ testo }}</span>
        </div>
        <div v-else class="text-sm font-semibold text-slate-400" :data-parametro="campo">{{ vuoto }}</div>
    </template>
</template>
