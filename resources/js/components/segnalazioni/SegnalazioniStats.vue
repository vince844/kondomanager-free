<script setup lang="ts">
import { CircleArrowUp, CircleArrowRight, CircleArrowDown, CircleAlert } from 'lucide-vue-next';
import { trans } from 'laravel-vue-i18n';

defineProps<{
  stats: {
    bassa: number,
    media: number,
    alta: number,
    urgente: number
  }
}>();

const config = {
  bassa: {
    icon: CircleArrowDown,
    lang: 'segnalazioni.stats.low_priority',
    cardStyle: 'bg-emerald-50/50 border-emerald-100 dark:bg-emerald-900/10 dark:border-emerald-900/30',
    titleColor: 'text-emerald-600/70 dark:text-emerald-500/70',
    numberColor: 'text-emerald-700 dark:text-emerald-400',
    iconColor: 'text-emerald-500'
  },
  media: {
    icon: CircleArrowRight,
    lang: 'segnalazioni.stats.medium_priority',
    cardStyle: 'bg-blue-50/50 border-blue-100 dark:bg-blue-900/10 dark:border-blue-900/30',
    titleColor: 'text-blue-600/70 dark:text-blue-500/70',
    numberColor: 'text-blue-700 dark:text-blue-400',
    iconColor: 'text-blue-500'
  },
  alta: {
    icon: CircleArrowUp,
    lang: 'segnalazioni.stats.high_priority',
    cardStyle: 'bg-amber-50/50 border-amber-100 dark:bg-amber-900/10 dark:border-amber-900/30',
    titleColor: 'text-amber-600/70 dark:text-amber-500/70',
    numberColor: 'text-amber-700 dark:text-amber-400',
    iconColor: 'text-amber-500'
  },
  urgente: {
    icon: CircleAlert,
    lang: 'segnalazioni.stats.urgent_priority',
    cardStyle: 'bg-red-50/50 border-red-100 dark:bg-red-900/10 dark:border-red-900/30',
    titleColor: 'text-red-600/70 dark:text-red-500/70',
    numberColor: 'text-red-700 dark:text-red-400',
    iconColor: 'text-red-500'
  }
};
</script>

<template>
  <div class="grid gap-4 md:grid-cols-2 lg:grid-cols-4">
    <div 
      v-for="(item, key) in config" 
      :key="key"
      :class="[
        'relative overflow-hidden rounded-2xl border p-5 shadow-sm transition-all hover:shadow-md',
        item.cardStyle
      ]"
    >
      <div class="flex items-center justify-between mb-4">
        <h3 :class="['text-[10px] font-bold uppercase tracking-widest', item.titleColor]">
          {{ trans(item.lang) }}
        </h3>
        
        <component :is="item.icon" :class="['w-5 h-5', item.iconColor]" />
      </div>

      <div class="flex items-baseline gap-2">
        <span :class="['text-3xl font-black', item.numberColor]">
          {{ stats[key] ?? 0 }}
        </span>
      </div>
    </div>
  </div>
</template>