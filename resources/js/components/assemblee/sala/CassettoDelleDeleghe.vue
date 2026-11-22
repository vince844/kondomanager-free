<!--
SPDX-License-Identifier: AGPL-3.0-or-later
Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
-->
<script setup lang="ts">
/**
 * Le deleghe ricevute in un cassetto a destra (la bozza delle card dei passi e del cassetto delle deleghe, scena 2): «Vedi le deleghe» della card del passo
 * «In sala» lo apre sopra la pagina, che resta dietro velata: si guarda, si segna una delega, si chiude, e si è dove si era. Largo circa 560 pixel, al
 * telefono tutta la larghezza.
 *
 * In testa «Deleghe ricevute» e la riga dell'assemblea; nel corpo lo stesso elenco della sezione del passo (`ElencoDelleDeleghe`, nella forma del
 * cassetto); nel piede fisso «Chiudi». La sala arriva con il passo aperto: finché non c'è, «Un momento…».
 */
import { Users } from 'lucide-vue-next';
import { Sheet, SheetContent, SheetDescription, SheetHeader, SheetTitle } from '@/components/ui/sheet';
import type { SalaDellAssemblea } from '@/types/assemblee';
import ElencoDelleDeleghe from './ElencoDelleDeleghe.vue';

defineProps<{ open: boolean; sala: SalaDellAssemblea | undefined; puoGestire: boolean; condominioId: number; assembleaId: number }>();
const emit = defineEmits<{ (e: 'update:open', v: boolean): void }>();
</script>

<template>
    <Sheet :open="open" @update:open="emit('update:open', $event)">
        <SheetContent class="flex w-full flex-col gap-0 p-0 sm:max-w-[560px]" data-testid="cassetto-deleghe">
            <SheetHeader class="space-y-0 border-b border-slate-100 px-5 py-4 pr-12 text-left dark:border-slate-800">
                <div class="flex min-w-0 items-center gap-2">
                    <div class="shrink-0 rounded-lg bg-slate-100 p-1.5 text-slate-700 dark:bg-slate-800 dark:text-slate-300"><Users class="h-4 w-4" aria-hidden="true" /></div>
                    <SheetTitle class="break-words text-base font-bold text-slate-900 dark:text-white" data-testid="titolo-deleghe">Deleghe ricevute</SheetTitle>
                </div>
                <SheetDescription class="mt-1 text-xs text-slate-500 dark:text-slate-400" data-testid="sottotitolo-deleghe">
                    {{ sala ? `${sala.assemblea.nome} · valgono per tutte le sue date` : 'Valgono per tutte le date dell\'assemblea' }}
                </SheetDescription>
            </SheetHeader>
            <div class="min-h-0 flex-1 overflow-y-auto px-5 py-4">
                <ElencoDelleDeleghe v-if="sala" :sala="sala" :puo-gestire="puoGestire" :condominio-id="condominioId" :assemblea-id="assembleaId" forma="cassetto" />
                <p v-else class="py-6 text-center text-sm text-slate-500 dark:text-slate-400">Un momento…</p>
            </div>
            <div class="flex items-center justify-between gap-3 border-t border-slate-100 px-5 py-3 dark:border-slate-800">
                <p class="text-xs text-slate-500 dark:text-slate-400">Le deleghe si segnano anche dalla sezione del passo.</p>
                <button type="button"
                    class="inline-flex h-7 shrink-0 items-center justify-center rounded-md border border-slate-200 bg-white px-2.5 text-xs font-medium text-slate-700 hover:bg-slate-50 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-200 dark:hover:bg-slate-800"
                    data-testid="chiudi-deleghe" @click="emit('update:open', false)">Chiudi</button>
            </div>
        </SheetContent>
    </Sheet>
</template>
