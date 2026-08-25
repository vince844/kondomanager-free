<script setup lang="ts">
/**
 * La pillola con il verdetto: verde, ambra, neutro.
 *
 * ## Perché non si tocca `badgeVariants`
 *
 * Il `Badge` dell'applicazione ha quattro varianti — nero pieno, grigio, rosso, contorno — e non
 * ha né verde né ambra. Il risultato, nel wizard, era che «quadrano», «completo», «pronto» e
 * «affidabilità 96%» uscivano tutti grigi indistinguibili, e l'unico colore rimasto era il rosso:
 * una procedura che parla solo quando qualcosa va male.
 *
 * `badgeVariants` però è condiviso con tutta l'applicazione e il restyling 2.0 lo rivedrà: si
 * aggiunge qui, in locale, invece di aprire due colori nuovi nel sistema di design per una
 * schermata sola. `Badge` fonde già la `class` in arrivo con `cn()`, quindi basta questo.
 */
import { computed } from 'vue';
import { Badge } from '@/components/ui/badge';

const props = withDefaults(defineProps<{ stato?: 'ok' | 'warn' | 'neutro' }>(), {
  stato: 'neutro',
});

const CLASSI: Record<string, string> = {
  ok: 'border-emerald-300 bg-emerald-50 text-emerald-800 dark:border-emerald-900/60 dark:bg-emerald-950/40 dark:text-emerald-300',
  warn: 'border-amber-300 bg-amber-50 text-amber-900 dark:border-amber-900/60 dark:bg-amber-950/40 dark:text-amber-300',
  neutro: 'border-border bg-muted text-muted-foreground',
};

const classe = computed(() => CLASSI[props.stato] ?? CLASSI.neutro);
</script>

<template>
  <Badge variant="outline" :class="classe">
    <slot />
  </Badge>
</template>
