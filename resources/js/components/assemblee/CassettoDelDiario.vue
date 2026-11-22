<!--
SPDX-License-Identifier: AGPL-3.0-or-later
Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
-->
<script setup lang="ts">
/**
 * Il diario dell'assemblea in un cassetto a destra (la scena 3 della bozza approvata dei documenti dei punti e del diario): «Diario» nel menu «Azioni»
 * della pagina lo apre, come la guida, senza lasciare il passo aperto. Il diario ha già tutte le modifiche dell'assemblea, più gli invii: prende il posto
 * della «Storia» della scheda.
 *
 * Aperto, chiede il diario al server con una visita parziale del solo `diario` e il filtro «Tutto»: non parte con la pagina. I filtri e «Mostra altre»
 * sono quelli di `DiarioDellAssemblea`, nella forma del cassetto.
 */
import { watch } from 'vue';
import { router } from '@inertiajs/vue3';
import { History, X } from 'lucide-vue-next';
import { Sheet, SheetClose, SheetContent, SheetDescription, SheetHeader, SheetTitle } from '@/components/ui/sheet';
import DiarioDellAssemblea from '@/components/assemblee/DiarioDellAssemblea.vue';
import type { DiarioDellaPagina } from '@/lib/assemblee/pagine';

const props = defineProps<{ open: boolean; diario: DiarioDellaPagina | null | undefined; sottotitolo: string }>();
defineEmits<{ (e: 'update:open', v: boolean): void }>();

watch(() => props.open, (aperto) => {
    if (aperto) {
        router.reload({ only: ['diario'], data: { diario_filtro: 'tutto', diario_dopo: undefined }, preserveScroll: true });
    }
}, { immediate: true });
</script>

<template>
    <Sheet :open="open" @update:open="$emit('update:open', $event)">
        <!-- Il pulsante di serie del cassetto (la sola «X») lascia il posto a «Chiudi» scritto per intero, come nella «Storia». -->
        <SheetContent class="w-full gap-0 overflow-y-auto p-0 sm:max-w-[420px] [&>button:last-child]:hidden" data-testid="cassetto-diario">
            <SheetHeader class="space-y-0 border-b border-slate-100 px-5 py-4 text-left dark:border-slate-800">
                <div class="flex items-start justify-between gap-3">
                    <div class="flex min-w-0 items-center gap-2">
                        <div class="shrink-0 rounded-lg bg-slate-100 p-1.5 text-slate-700 dark:bg-slate-800 dark:text-slate-300"><History class="h-4 w-4" /></div>
                        <SheetTitle class="break-words text-base font-bold text-slate-900 dark:text-white" data-testid="titolo-diario">Il diario dell'assemblea</SheetTitle>
                    </div>
                    <SheetClose data-testid="chiudi-diario"
                        class="inline-flex shrink-0 items-center gap-1 rounded-md px-2 py-1 text-xs font-bold text-slate-500 hover:bg-slate-100 hover:text-slate-800 dark:text-slate-400 dark:hover:bg-slate-800 dark:hover:text-slate-200">
                        <X class="h-4 w-4" /> Chiudi
                    </SheetClose>
                </div>
                <SheetDescription class="mt-1 text-xs text-slate-500 dark:text-slate-400" data-testid="sottotitolo-diario">
                    {{ sottotitolo }} · le modifiche e gli invii, dal più recente
                </SheetDescription>
            </SheetHeader>
            <DiarioDellAssemblea :diario="diario" forma="cassetto" />
        </SheetContent>
    </Sheet>
</template>
