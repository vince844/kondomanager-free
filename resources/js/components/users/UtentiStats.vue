<!--
  Le tre card sopra l'elenco utenti (Coda 398): quanti sono, quanti hanno l'email da verificare, quanti sono sospesi.

  Il disegno è quello delle altre statistiche (`EventiStats.vue`, `DocumentiStats.vue`). I numeri vengono dal server e
  contano l'archivio intero: non seguono i filtri né la pagina. Come le card degli eventi, un clic applica il filtro
  che la card racconta: «da verificare» mostra i non verificati, «sospesi» i sospesi, «totale» toglie ogni filtro.
  Ogni card manda **tutti** i filtri della barra, quelli che non le appartengono a `null`: il clic mostra esattamente
  le persone che il numero conta, non quelle che restano dopo un filtro per ruolo o per nome acceso prima.
-->
<script setup lang="ts">
import { CircleX, MailWarning, Users } from 'lucide-vue-next';
import { trans } from 'laravel-vue-i18n';
import type { FiltriElencoUtenti, StatisticheUtenti } from '@/types/users';

const emit = defineEmits<{
  (e: 'setFilter', filtri: FiltriElencoUtenti): void;
}>();

defineProps<{
  stats: StatisticheUtenti;
}>();

/**
 * I filtri che manda una card: tutti quelli della barra, `null` per quelli che non le appartengono. Si costruiscono a
 * ogni clic, così chi li riceve non altera quelli del clic dopo.
 */
const filtri = (suoi: Partial<FiltriElencoUtenti> = {}): FiltriElencoUtenti =>
  ({ name: null, roles: null, stato: null, verifica: null, ...suoi });

const displayStats = {
  totale: {
    title: 'users.stats.total',
    hint: 'users.stats.total_hint',
    icon: Users,
    filtri: () => filtri(),
    cardStyle: 'bg-blue-50/50 border-blue-100 hover:border-blue-300 dark:bg-blue-900/10 dark:border-blue-900/30 dark:hover:border-blue-800',
    titleColor: 'text-blue-600/70 dark:text-blue-500/70',
    numberColor: 'text-blue-700 dark:text-blue-400',
    iconColor: 'text-blue-500',
  },
  da_verificare: {
    title: 'users.stats.to_verify',
    hint: 'users.stats.to_verify_hint',
    icon: MailWarning,
    filtri: () => filtri({ verifica: ['non_verificato'] }),
    cardStyle: 'bg-amber-50/50 border-amber-100 hover:border-amber-300 dark:bg-amber-900/10 dark:border-amber-900/30 dark:hover:border-amber-800',
    titleColor: 'text-amber-600/70 dark:text-amber-500/70',
    numberColor: 'text-amber-700 dark:text-amber-400',
    iconColor: 'text-amber-500',
  },
  sospesi: {
    title: 'users.stats.suspended',
    hint: 'users.stats.suspended_hint',
    icon: CircleX,
    filtri: () => filtri({ stato: ['sospeso'] }),
    cardStyle: 'bg-red-50/50 border-red-100 hover:border-red-300 dark:bg-red-900/10 dark:border-red-900/30 dark:hover:border-red-800',
    titleColor: 'text-red-600/70 dark:text-red-500/70',
    numberColor: 'text-red-700 dark:text-red-400',
    iconColor: 'text-red-500',
  },
};
</script>

<template>
  <div class="grid gap-4 md:grid-cols-3">
    <button
      v-for="(stat, key) in displayStats"
      :key="key"
      type="button"
      :data-card="key"
      :title="trans(stat.hint)"
      @click="emit('setFilter', stat.filtri())"
      :class="[
        'relative overflow-hidden rounded-2xl border p-5 text-left shadow-sm transition-all duration-200 cursor-pointer hover:shadow-md hover:-translate-y-0.5',
        stat.cardStyle
      ]"
    >
      <div class="flex items-center justify-between mb-4">
        <h3 :class="['text-[10px] font-bold uppercase tracking-widest', stat.titleColor]">
          {{ trans(stat.title) }}
        </h3>
        <component :is="stat.icon" :class="['w-5 h-5', stat.iconColor]" />
      </div>
      <div class="flex items-baseline gap-2">
        <span :class="['text-3xl font-black', stat.numberColor]">
          {{ stats[key] ?? 0 }}
        </span>
      </div>
    </button>
  </div>
</template>
