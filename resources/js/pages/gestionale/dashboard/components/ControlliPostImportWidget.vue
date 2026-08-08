<script setup lang="ts">
/**
 * Il richiamo alla lista «Da controllare dopo l'importazione».
 *
 * Compare solo se c'è qualcosa di **aperto**, e sparisce da solo quando l'ultima voce si chiude —
 * senza che nessuno debba spuntarla, perché quasi tutte si verificano da sole. È l'unico modo
 * perché un condominio migrato mesi fa non porti in giro un riquadro che non serve più.
 */
import { ClipboardCheck, ChevronRight } from 'lucide-vue-next';

defineProps<{
  controlli: { totale: number; url: string; prime: { titolo: string; url: string | null }[] };
}>();
</script>

<template>
  <div class="rounded-xl border border-amber-300 bg-amber-50/60 p-4 dark:border-amber-900/60 dark:bg-amber-950/20">
    <div class="flex items-start justify-between gap-3">
      <div class="flex items-center gap-2">
        <ClipboardCheck class="h-4 w-4 text-amber-600 dark:text-amber-400" />
        <h3 class="text-sm font-semibold">Da controllare dopo l'importazione</h3>
      </div>
      <span class="shrink-0 text-sm font-semibold tabular-nums">{{ controlli.totale }}</span>
    </div>

    <ul class="mt-3 space-y-1.5">
      <li v-for="(v, i) in controlli.prime" :key="i" class="text-sm text-muted-foreground">
        · {{ v.titolo }}
      </li>
    </ul>

    <a :href="controlli.url" class="mt-3 inline-flex items-center gap-1 text-sm font-medium underline">
      Vedile tutte <ChevronRight class="h-3.5 w-3.5" />
    </a>
  </div>
</template>
