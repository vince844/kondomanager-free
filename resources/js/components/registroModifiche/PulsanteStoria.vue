<!--
SPDX-License-Identifier: AGPL-3.0-or-later
Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
-->
<script setup lang="ts">
/**
 * Il pulsante «Storia» dell'intestazione, accanto a «Guida» (§8 del progetto della tappa 1, scena 1 della bozza): l'icona dell'orologio, alto `h-8` come
 * «Guida» e gli altri pulsanti dell'intestazione (standard 4). Il clic lo gestisce chi lo usa.
 */
import { History } from 'lucide-vue-next';
</script>

<template>
    <button
        type="button"
        data-testid="pulsante-storia"
        class="inline-flex h-8 items-center justify-center gap-2 whitespace-nowrap rounded-lg border border-slate-200 bg-white px-3 text-xs font-bold text-slate-700 shadow-sm transition-colors hover:bg-slate-50 dark:border-slate-800 dark:bg-slate-900 dark:text-slate-300 dark:hover:bg-slate-800"
    >
        <History class="h-3.5 w-3.5" />
        Storia
    </button>
</template>
