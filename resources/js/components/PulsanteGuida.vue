<!--
SPDX-License-Identifier: AGPL-3.0-or-later
Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
-->
<script setup lang="ts">
/**
 * Il pulsante che apre la guida nel cassetto: l'aspetto di «Guida» dell'intestazione delle pagine (`PageHeaderGuide.vue`), alto come gli altri pulsanti
 * dell'intestazione, con il testo piccolo, l'indaco chiaro e il libro. Il nome cambia («Guida», «Guida completa»); il clic lo gestisce chi lo usa.
 */
import { BookOpen } from 'lucide-vue-next';

withDefaults(defineProps<{ testo?: string }>(), { testo: 'Guida' });
</script>

<template>
    <button
        type="button"
        class="inline-flex h-8 items-center justify-center gap-2 text-xs font-bold px-3 rounded-lg bg-indigo-50 text-indigo-600 hover:bg-indigo-100 border border-indigo-100 transition-colors dark:bg-indigo-950/30 dark:text-indigo-400 dark:border-indigo-900/50 dark:hover:bg-indigo-950/60"
    >
        <BookOpen class="w-3.5 h-3.5" />
        {{ testo }}
    </button>
</template>
