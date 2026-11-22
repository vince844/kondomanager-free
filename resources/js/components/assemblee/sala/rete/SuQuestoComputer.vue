<!--
SPDX-License-Identifier: AGPL-3.0-or-later
Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
-->
<script setup lang="ts">
/**
 * «Su questo computer»: il badge di una riga della sala (una presenza, un voto, una delega, una dichiarazione) segnata su questo dispositivo e non ancora
 * arrivata al server. Resta sulla riga finché il gesto non arriva (la chiave della riga è in `in_coda` della sala vista dal dispositivo).
 */
withDefaults(defineProps<{ dispositivo?: 'computer' | 'telefono' | 'tablet' }>(), { dispositivo: 'computer' });
const ETICHETTA = { computer: 'Su questo computer', telefono: 'Su questo telefono', tablet: 'Su questo tablet' } as const;
</script>

<template>
    <span
        data-testid="su-questo-computer"
        class="inline-flex items-center rounded-md border border-amber-300 bg-amber-50 px-1.5 py-0.5 text-xs font-medium text-amber-900 dark:border-amber-700 dark:bg-amber-950/50 dark:text-amber-200"
        title="Segnato senza rete: parte da solo quando torna la rete"
    >{{ ETICHETTA[dispositivo] }}</span>
</template>
