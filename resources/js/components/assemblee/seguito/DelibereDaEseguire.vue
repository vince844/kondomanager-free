<!--
SPDX-License-Identifier: AGPL-3.0-or-later
Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
-->
<script setup lang="ts">
/**
 * La card «Delibere da eseguire» della dashboard del condominio (la bozza approvata del seguito, scena 3): le cose da fare aperte delle delibere, una per
 * riga, con «entro il …», la delibera (assemblea e punto), la persona e l'azione mirata («Crea il piano straordinario», «Registra la dispensa», «Avvisa i
 * condòmini», «Segna eseguita»); a chi vede soltanto le assemblee il server manda «Apri la delibera». Senza righe la card non c'è (lo decide la pagina); la
 * lettura è differita e solo per chi vede le assemblee.
 */
import { Link } from '@inertiajs/vue3';
import { CalendarClock, Gavel } from 'lucide-vue-next';
import { BADGE, CLASSI_DEL_BADGE } from '@/lib/assemblee/assemblee';
import { giornoItaliano } from '@/lib/giorno';
import type { CosaDaFare } from '@/types/assemblee';

defineProps<{ condominioNome: string; righe: (CosaDaFare & { delibera: string; assemblea_id: number })[] }>();
</script>

<template>
    <section class="rounded-xl border border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-950" data-testid="delibere-da-eseguire">
        <header class="flex items-start justify-between gap-3 border-b border-slate-100 px-4 py-3 dark:border-slate-800">
            <div class="min-w-0">
                <h3 class="flex items-center gap-1.5 text-sm font-semibold text-slate-900 dark:text-white"><Gavel class="h-4 w-4" aria-hidden="true" /> Delibere da eseguire</h3>
                <p class="text-xs text-slate-500 dark:text-slate-400">Le delibere di {{ condominioNome }} che aspettano ancora qualcosa.</p>
            </div>
            <span :class="[BADGE, CLASSI_DEL_BADGE.ambra]" data-testid="quante">{{ righe.length }}</span>
        </header>
        <ul class="divide-y divide-slate-100 dark:divide-slate-800">
            <li v-for="r in righe" :key="r.id" class="flex flex-col gap-2 px-4 py-2.5 sm:flex-row sm:items-center sm:justify-between" data-testid="riga-da-eseguire">
                <div class="min-w-0">
                    <p class="break-words text-sm text-slate-900 dark:text-slate-100">{{ r.testo }}</p>
                    <p class="flex flex-wrap items-center gap-x-1 break-words text-xs text-slate-500 dark:text-slate-400">
                        <CalendarClock v-if="r.entro_il" class="h-3.5 w-3.5" aria-hidden="true" />
                        <span>{{ [r.entro_il ? `entro il ${giornoItaliano(r.entro_il)}` : null, r.delibera, r.chi].filter(Boolean).join(' · ') }}</span>
                    </p>
                </div>
                <Link v-if="r.azione?.url" :href="r.azione.url" class="inline-flex h-8 shrink-0 items-center justify-center rounded-md border border-slate-200 bg-white px-3 text-xs font-medium text-slate-700 hover:bg-slate-50 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-200 dark:hover:bg-slate-800">
                    {{ r.azione.testo }}</Link>
            </li>
        </ul>
    </section>
</template>
