<script setup lang="ts">
/**
 * Il percorso a sette tappe: dove sei, e quanto manca.
 *
 * ## Orientamento, non navigazione
 *
 * Il mockup faceva della verifica sette schermate con un «Continua →» per livello. Quella forma
 * è stata **abbandonata di proposito**: mostrava le decisioni su una schermata e le faceva
 * rispondere su un'altra, e da lì non si passava — un vicolo cieco trovato camminandoci dentro,
 * non da un test.
 *
 * Quindi lo stepper dice dove sei senza rimettere il passo-passo: i pallini non sono cliccabili,
 * e il percorso resta una pagina sola. È l'unica cosa che il mockup aveva e che serviva davvero:
 * senza, la verifica sembra una pagina di errori invece della quinta tappa di qualcosa che
 * finisce.
 */
withDefaults(defineProps<{
  passi: { chiave: string; etichetta: string; stato: 'pronto' | 'errori' | 'assente' }[];
  compatto?: boolean;
}>(), { compatto: false });

const PALLINO: Record<string, string> = {
  pronto: 'border-emerald-500 bg-emerald-500 text-white dark:border-emerald-500 dark:bg-emerald-500',
  errori: 'border-destructive bg-destructive text-destructive-foreground',
  assente: 'border-border bg-background text-muted-foreground',
};

const TESTO: Record<string, string> = {
  pronto: 'text-foreground',
  errori: 'text-destructive font-medium',
  assente: 'text-muted-foreground',
};
</script>

<template>
  <!-- Scorre in orizzontale invece di andare a capo: sette tappe su uno schermo stretto -->
  <div class="flex items-center gap-0 overflow-x-auto py-1">
    <template v-for="(p, i) in passi" :key="p.chiave">
      <div class="flex shrink-0 items-center gap-1.5" :class="compatto ? 'px-1' : 'px-1.5'">
        <span
          class="flex h-6 w-6 shrink-0 items-center justify-center rounded-full border text-[11px] font-medium"
          :class="PALLINO[p.stato]"
        >
          <template v-if="p.stato === 'pronto'">✓</template>
          <template v-else-if="p.stato === 'errori'">!</template>
          <template v-else>{{ i + 1 }}</template>
        </span>
        <span v-if="!compatto" class="whitespace-nowrap text-xs" :class="TESTO[p.stato]">
          {{ p.etichetta }}
        </span>
      </div>

      <span
        v-if="i < passi.length - 1"
        class="h-px min-w-[16px] flex-1 shrink"
        :class="p.stato === 'pronto' ? 'bg-emerald-500/60' : 'bg-border'"
      />
    </template>
  </div>
</template>
