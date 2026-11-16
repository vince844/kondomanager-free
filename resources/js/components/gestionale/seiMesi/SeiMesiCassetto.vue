<!--
SPDX-License-Identifier: AGPL-3.0-or-later
Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
-->
<script setup lang="ts">
/**
 * Il cassetto «Sei mesi per agire · tutti i condomini» dell'Action inbox: si apre a destra, sopra la pagina, senza lasciarla; chiuso di serie, lo aprono
 * «Apri l'elenco» della colonna e la card «Sei mesi». Dentro la lista dello studio in tre sezioni (`SeiMesiDelloStudio`), con lo stato della fila della pagina:
 * il cassetto non chiede niente da sé. «Chiudi» in alto a destra.
 */
import { Sheet, SheetClose, SheetContent, SheetDescription, SheetHeader, SheetTitle } from '@/components/ui/sheet';
import { Hourglass, X } from 'lucide-vue-next';
import SeiMesiDelloStudio from '@/components/gestionale/seiMesi/SeiMesiDelloStudio.vue';
import { DESCRIZIONE_CASSETTO, titoloDelCassetto, type StatoDelloStudio } from '@/lib/gestionale/debiti/seiMesiDelloStudio';

defineProps<{ open: boolean; stato: StatoDelloStudio; condominio: string | null }>();
defineEmits<{ (e: 'update:open', aperto: boolean): void; (e: 'riprova'): void }>();
</script>

<template>
    <Sheet :open="open" @update:open="$emit('update:open', $event)">
        <!-- Il pulsante di serie del cassetto (la sola «X») lascia il posto a «Chiudi» scritto per intero. -->
        <SheetContent class="w-full gap-0 overflow-y-auto p-0 sm:max-w-3xl [&>button:last-child]:hidden" data-testid="cassetto-sei-mesi">
            <SheetHeader class="space-y-0 border-b border-slate-100 px-6 py-5 text-left dark:border-slate-800">
                <div class="flex items-start justify-between gap-3">
                    <div class="flex min-w-0 items-center gap-2">
                        <div class="shrink-0 rounded-lg bg-amber-100 p-1.5 text-amber-700 dark:bg-amber-900/40 dark:text-amber-300"><Hourglass class="h-4 w-4" /></div>
                        <SheetTitle class="text-lg font-bold text-slate-900 dark:text-white">{{ titoloDelCassetto(condominio) }}</SheetTitle>
                    </div>
                    <SheetClose data-testid="chiudi-cassetto"
                        class="inline-flex shrink-0 items-center gap-1 rounded-md px-2 py-1 text-xs font-bold text-slate-500 hover:bg-slate-100 hover:text-slate-800 dark:text-slate-400 dark:hover:bg-slate-800 dark:hover:text-slate-200">
                        <X class="h-4 w-4" /> Chiudi
                    </SheetClose>
                </div>
                <SheetDescription class="mt-1.5 text-sm text-slate-500 dark:text-slate-400">{{ DESCRIZIONE_CASSETTO }}</SheetDescription>
            </SheetHeader>
            <div class="px-6 py-5">
                <SeiMesiDelloStudio :stato="stato" @riprova="$emit('riprova')" />
            </div>
        </SheetContent>
    </Sheet>
</template>
