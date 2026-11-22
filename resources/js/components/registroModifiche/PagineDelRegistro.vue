<!--
SPDX-License-Identifier: AGPL-3.0-or-later
Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
-->
<script setup lang="ts">
/**
 * Le pagine della tabella del registro delle modifiche, come nelle altre tabelle del programma: il conto delle righe, «Righe per pagina» (20, 50, 100),
 * «Pagina 2 di 7» e le quattro frecce, con i pulsanti di `DataTablePagination.vue`. Quel componente vuole la tabella di TanStack, che qui non c'è (le righe
 * sono già una pagina del server), e il suo menu delle righe per pagina non è un `v-select`: qui il menu è un `v-select`, come ogni menu nuovo.
 *
 * Non tiene la pagina: la dicono le props (dal server, `righe.current_page`, `last_page`, `per_page`, `total`), e il componente chiede la pagina o le
 * righe per pagina con gli eventi; la visita la fa chi lo usa. Al telefono il conto e le righe per pagina sopra, «Pagina 2 di 7» e le frecce sotto, a tutta
 * riga.
 */
import { computed } from 'vue';
import vSelect from 'vue-select';
import 'vue-select/dist/vue-select.css';
import { ChevronLeft, ChevronRight, ChevronsLeft, ChevronsRight } from 'lucide-vue-next';
import { Button } from '@/components/ui/button';
import { RIGHE_PER_PAGINA, testoPagina, testoRighe } from '@/lib/registroModifiche/stampa';

const props = defineProps<{ pagina: number; pagine: number; perPagina: number; totale: number }>();
const emit = defineEmits<{ (e: 'pagina', pagina: number): void; (e: 'per-pagina', righe: number): void }>();

/** Il menu offre le tre misure; quella in uso c'è sempre, anche se il server ne mandasse un'altra. */
const misure = computed(() => RIGHE_PER_PAGINA.includes(props.perPagina) ? RIGHE_PER_PAGINA : [...RIGHE_PER_PAGINA, props.perPagina].sort((a, b) => a - b));

function vai(pagina: number) {
    if (pagina >= 1 && pagina <= Math.max(1, props.pagine) && pagina !== props.pagina) emit('pagina', pagina);
}

function cambiaMisura(righe: number | null) {
    if (righe && righe !== props.perPagina) emit('per-pagina', righe);
}

const freccia = 'h-8 w-8 p-0 dark:border-slate-700 dark:bg-slate-900';
</script>

<template>
    <div class="flex flex-wrap items-center gap-x-4 gap-y-2 border-t border-slate-100 px-4 py-2.5 text-xs text-slate-500 dark:border-slate-800 dark:text-slate-400" data-testid="paginazione">
        <span class="mr-auto tabular-nums" data-testid="conto-righe">{{ testoRighe(totale) }}</span>
        <div class="flex items-center gap-2" data-testid="righe-per-pagina">
            <span class="whitespace-nowrap">Righe per pagina</span>
            <div class="menu-campo w-[76px] text-xs text-slate-700 dark:text-slate-300">
                <v-select :model-value="perPagina" :options="misure" :clearable="false" :searchable="false" class="w-full" @update:model-value="cambiaMisura" />
            </div>
        </div>
        <div class="flex basis-full items-center justify-between gap-3 sm:basis-auto sm:justify-end" data-testid="pagina-e-frecce">
            <span class="whitespace-nowrap font-medium tabular-nums text-slate-700 dark:text-slate-300" data-testid="pagina-di">{{ testoPagina(pagina, pagine) }}</span>
            <div class="flex items-center gap-1.5">
                <Button variant="outline" :class="freccia" :disabled="pagina <= 1" data-testid="pagina-prima" @click="vai(1)">
                    <span class="sr-only">Vai alla prima pagina</span><ChevronsLeft class="h-4 w-4" />
                </Button>
                <Button variant="outline" :class="freccia" :disabled="pagina <= 1" data-testid="pagina-precedente" @click="vai(pagina - 1)">
                    <span class="sr-only">Vai alla pagina precedente</span><ChevronLeft class="h-4 w-4" />
                </Button>
                <Button variant="outline" :class="freccia" :disabled="pagina >= pagine" data-testid="pagina-successiva" @click="vai(pagina + 1)">
                    <span class="sr-only">Vai alla pagina successiva</span><ChevronRight class="h-4 w-4" />
                </Button>
                <Button variant="outline" :class="freccia" :disabled="pagina >= pagine" data-testid="pagina-ultima" @click="vai(pagine)">
                    <span class="sr-only">Vai all'ultima pagina</span><ChevronsRight class="h-4 w-4" />
                </Button>
            </div>
        </div>
    </div>
</template>
