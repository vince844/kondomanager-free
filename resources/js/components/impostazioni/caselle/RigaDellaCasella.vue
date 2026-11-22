<!--
SPDX-License-Identifier: AGPL-3.0-or-later
Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
-->
<script setup lang="ts">
/**
 * La riga di una casella nell'elenco delle caselle dello studio (scena 1 della bozza): l'icona, il nome con lo stato (la parola e il colore), l'indirizzo
 * con il server, «Serve per», la riga «Adesso» e a destra il pulsante giusto per lo stato. Al telefono il pulsante va sotto il testo, largo quanto la riga.
 * Con un consiglio (la posta mai provata dopo l'aggiornamento, decisione 577) una riga azzurra sotto «Adesso»: non è un allarme, e niente si ferma.
 */
import { Check, Lightbulb, MailCheck, Mail, X } from 'lucide-vue-next';
import { classiBadge } from './aiuti';
import type { StatoDellaCasella } from './tipi';

defineProps<{
    icona: 'posta' | 'pec';
    nome: string;
    stato: StatoDellaCasella;
    parola: string;
    riga: string | null;
    serve: string;
    adesso: string;
    consiglio?: string | null;
}>();
</script>

<template>
    <div class="grid grid-cols-[36px_minmax(0,1fr)] gap-3.5 px-4 py-4 sm:grid-cols-[36px_minmax(0,1fr)_auto] sm:px-5">
        <div class="flex h-9 w-9 items-center justify-center rounded-lg border border-slate-200 bg-slate-50 text-slate-700 dark:border-slate-800 dark:bg-slate-900 dark:text-slate-300">
            <MailCheck v-if="icona === 'pec'" class="h-[18px] w-[18px]" />
            <Mail v-else class="h-[18px] w-[18px]" />
        </div>
        <div class="min-w-0">
            <div class="flex flex-wrap items-center gap-2 text-sm font-semibold text-slate-900 dark:text-slate-100">
                <span>{{ nome }}</span>
                <span :class="classiBadge(stato)" data-testid="stato-casella">
                    <Check v-if="stato === 'provata' || stato === 'gia_pronta'" class="h-3 w-3" />
                    <X v-else-if="stato === 'non_andata'" class="h-3 w-3" />
                    {{ parola }}
                </span>
            </div>
            <p v-if="riga" class="mt-0.5 text-sm text-slate-700 [overflow-wrap:anywhere] dark:text-slate-300" data-testid="riga-casella">{{ riga }}</p>
            <p class="mt-1 text-sm text-slate-500 dark:text-slate-400"><b class="font-semibold text-slate-700 dark:text-slate-300">Serve per</b> {{ serve }}</p>
            <p class="mt-2 rounded-md bg-slate-100 px-2.5 py-1.5 text-xs leading-snug text-slate-700 [overflow-wrap:anywhere] dark:bg-slate-800/60 dark:text-slate-300"
                data-testid="adesso-casella">{{ adesso }}</p>
            <p v-if="consiglio" class="mt-1.5 flex items-start gap-1.5 rounded-md border border-sky-200 bg-sky-50 px-2.5 py-1.5 text-xs leading-snug text-sky-900 dark:border-sky-900 dark:bg-sky-950/40 dark:text-sky-200"
                data-testid="consiglio-casella"><Lightbulb class="mt-px h-3.5 w-3.5 shrink-0" /> {{ consiglio }}</p>
        </div>
        <div class="col-span-2 flex gap-2 sm:col-span-1 [&>*]:w-full sm:[&>*]:w-auto sm:[&>*]:min-w-[104px]">
            <slot />
        </div>
    </div>
</template>
