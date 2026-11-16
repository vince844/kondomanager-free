<!--
SPDX-License-Identifier: AGPL-3.0-or-later
Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
-->
<script setup lang="ts">
/**
 * Una persona di «Crediti da compensare», nella card e nella finestra con l'elenco intero: il nome, il credito, la frase che dice quale rata copre,
 * «Compensa» (apre «Nuovo incasso» già puntato sulla rata) e «Rimborsa» (l'estratto conto della persona).
 */
import { Link } from '@inertiajs/vue3';
import { Button } from '@/components/ui/button';

export interface CreditoDaCompensare {
    anagrafica_id: number;
    nome: string;
    totale_formatted: string;
    /** Quanto di quel credito è davvero spendibile sulle rate aperte, in centesimi. */
    compensabile_cents: number;
    /** Se la riga porta da qualche parte: c'è un bersaglio, oppure c'è debito su un'altra gestione. */
    azionabile: boolean;
    compensabile_formatted: string;
    /** La frase che dice quale rata copre, o che non c'è niente da coprire. */
    copre: string;
    rata_bersaglio_id: number | null;
    url: string;
    /** L'estratto conto della persona: dove il credito si rimborsa. */
    url_estratto_conto?: string;
}

defineProps<{ credito: CreditoDaCompensare }>();
</script>

<template>
    <div data-testid="riga-credito" class="rounded-lg border px-2.5 py-2"
        :class="credito.azionabile ? 'bg-blue-50/50 dark:bg-blue-900/10 border-blue-100 dark:border-blue-900/30' : 'bg-slate-50/60 dark:bg-slate-800/30 border-slate-100 dark:border-slate-800'">
        <div class="flex items-baseline justify-between gap-2">
            <span class="min-w-0 truncate text-sm font-medium text-slate-700 dark:text-slate-200">{{ credito.nome }}</span>
            <span class="shrink-0 text-sm font-bold tabular-nums text-blue-700 dark:text-blue-300">{{ credito.totale_formatted }}</span>
        </div>
        <p class="mt-0.5 text-xs leading-snug" :class="credito.compensabile_cents > 0 ? 'text-blue-800/80 dark:text-blue-200/80' : 'text-slate-500 dark:text-slate-400 italic'">{{ credito.copre }}</p>
        <div class="mt-1.5 grid grid-cols-2 gap-1.5">
            <Link v-if="credito.azionabile" :href="credito.url" class="w-full" data-testid="compensa">
                <Button size="sm" variant="outline" class="h-8 w-full px-2 text-xs" tabindex="-1">Compensa</Button>
            </Link>
            <Link v-if="credito.url_estratto_conto" :href="credito.url_estratto_conto" class="w-full" :class="{ 'col-start-2': !credito.azionabile }" data-testid="rimborsa">
                <Button size="sm" variant="outline" class="h-8 w-full px-2 text-xs" tabindex="-1">Rimborsa</Button>
            </Link>
        </div>
    </div>
</template>
