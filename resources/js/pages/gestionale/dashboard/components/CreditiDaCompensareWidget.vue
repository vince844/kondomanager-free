<!--
SPDX-License-Identifier: AGPL-3.0-or-later
Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
-->
<script setup lang="ts">
/**
 * «Crediti da compensare» della dashboard del condominio, sotto la card «Saldo previsto» nella fila della tesoreria: le persone con un credito, con la frase che
 * dice quale rata copre, «Compensa» (apre «Nuovo incasso» già puntato sulla rata, senza scrivere niente) e «Rimborsa» (l'estratto conto della persona, dove sta
 * «Rimborsa il credito»). Niente scorrimento dentro la card, e la card non si allunga: fino a 8 persone tutte, oltre le prime 5 e «Mostra tutte», che apre
 * una finestra con l'elenco intero.
 */
import { computed, ref } from 'vue';
import { Wallet, X } from 'lucide-vue-next';
import { Dialog, DialogClose, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import RigaCreditoDaCompensare, { type CreditoDaCompensare } from './RigaCreditoDaCompensare.vue';

const props = defineProps<{ crediti: CreditoDaCompensare[] }>();

const TUTTE_FINO_A = 8;
const MOSTRATE_OLTRE = 5;
const finestra = ref(false);
const mostrate = computed(() => (props.crediti.length <= TUTTE_FINO_A ? props.crediti : props.crediti.slice(0, MOSTRATE_OLTRE)));
const mancano = computed(() => props.crediti.length - mostrate.value.length);
const persone = (n: number) => (n === 1 ? '1 persona' : `${n} persone`);
</script>

<template>
    <section class="rounded-xl border border-sidebar-border/70 bg-white dark:bg-slate-900 shadow-sm overflow-hidden" data-testid="crediti-da-compensare">
        <div class="flex items-center justify-between gap-2 border-b border-slate-100 bg-slate-50/50 px-4 py-3 dark:border-slate-800 dark:bg-slate-900/50">
            <div class="flex items-center gap-1.5">
                <Wallet class="w-4 h-4 text-blue-500" />
                <h3 class="text-xs font-bold uppercase tracking-widest text-slate-500">Crediti da compensare</h3>
            </div>
            <span class="text-xs font-semibold text-slate-500 dark:text-slate-400">{{ persone(crediti.length) }}</span>
        </div>

        <div class="space-y-2 p-4">
            <p class="text-xs text-slate-500 dark:text-slate-400">Chi ha un credito: «Compensa» apre «Nuovo incasso» già puntato sulla rata che il credito copre, «Rimborsa» il suo estratto conto. Le righe in grigio non hanno niente da compensare.</p>
            <RigaCreditoDaCompensare v-for="c in mostrate" :key="c.anagrafica_id" :credito="c" />
            <div v-if="mancano > 0" class="flex flex-wrap items-center justify-between gap-2 pt-1 text-xs" data-testid="fondo-crediti">
                <span class="text-slate-500 dark:text-slate-400">Qui le prime {{ MOSTRATE_OLTRE }}; {{ mancano === 1 ? 'manca 1 persona' : `mancano ${mancano} persone` }}</span>
                <button type="button" class="ml-auto font-medium text-primary hover:underline" data-testid="mostra-tutte-crediti" @click="finestra = true">Mostra tutte</button>
            </div>
        </div>

        <!-- L'elenco intero in una finestra: la card della dashboard resta della sua altezza. -->
        <Dialog v-model:open="finestra">
            <DialogContent :show-close-button="false" class="max-h-[92dvh] gap-0 overflow-y-auto p-0 sm:max-w-[520px]" data-testid="finestra-crediti">
                <DialogHeader class="space-y-0 border-b border-slate-100 px-5 py-4 text-left dark:border-slate-800">
                    <div class="flex items-start justify-between gap-3">
                        <DialogTitle class="text-lg font-bold text-slate-900 dark:text-white">Crediti da compensare</DialogTitle>
                        <DialogClose class="inline-flex shrink-0 items-center gap-1 rounded-md px-2 py-1 text-xs font-bold text-slate-500 hover:bg-slate-100 hover:text-slate-800 dark:text-slate-400 dark:hover:bg-slate-800 dark:hover:text-slate-200">
                            <X class="h-4 w-4" /> Chiudi
                        </DialogClose>
                    </div>
                    <DialogDescription class="mt-2 text-sm text-slate-600 dark:text-slate-300">
                        {{ persone(crediti.length) }} con un credito. «Compensa» apre «Nuovo incasso» già puntato sulla rata, «Rimborsa» l'estratto conto della persona.
                    </DialogDescription>
                </DialogHeader>
                <div class="space-y-2 px-5 py-4">
                    <RigaCreditoDaCompensare v-for="c in crediti" :key="c.anagrafica_id" :credito="c" />
                </div>
            </DialogContent>
        </Dialog>
    </section>
</template>
