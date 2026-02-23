<script setup lang="ts">
import { AlertTriangle, ClockAlert, ClockArrowUp, Clock } from 'lucide-vue-next';
import { trans } from 'laravel-vue-i18n';

const emit = defineEmits<{
  (e: 'setFilter', payload: { date_from: string; date_to: string }): void;
}>();

defineProps<{
  stats: {
    next_seven_days: number,
    next_fourteen_days: number,
    next_twentyeight_days: number,
    expired_last_seven_days?: number,
  }
}>();

const now = new Date();
const formatDate = (d: Date) => d.toISOString().slice(0, 10);

const displayStats = {
  expired_last_seven_days: {
    title: trans('eventi.stats.expired_last_seven_days'),
    icon: AlertTriangle,
    range: {
      date_from: formatDate(new Date(now.getTime() - 7 * 86400000)),
      date_to: formatDate(now),
    },
    cardStyle: 'bg-red-50/50 border-red-100 hover:border-red-300 dark:bg-red-900/10 dark:border-red-900/30 dark:hover:border-red-800',
    titleColor: 'text-red-600/70 dark:text-red-500/70',
    numberColor: 'text-red-700 dark:text-red-400',
    iconColor: 'text-red-500'
  },
  next_seven_days: {
    title: trans('eventi.stats.next_seven_days'),
    icon: ClockAlert,
    range: {
      date_from: formatDate(now),
      date_to: formatDate(new Date(now.getTime() + 7 * 86400000)),
    },
    cardStyle: 'bg-amber-50/50 border-amber-100 hover:border-amber-300 dark:bg-amber-900/10 dark:border-amber-900/30 dark:hover:border-amber-800',
    titleColor: 'text-amber-600/70 dark:text-amber-500/70',
    numberColor: 'text-amber-700 dark:text-amber-400',
    iconColor: 'text-amber-500'
  },
  next_fourteen_days: {
    title: trans('eventi.stats.next_fourteen_days'),
    icon: ClockArrowUp,
    range: {
      date_from: formatDate(now),
      date_to: formatDate(new Date(now.getTime() + 14 * 86400000)),
    },
    cardStyle: 'bg-blue-50/50 border-blue-100 hover:border-blue-300 dark:bg-blue-900/10 dark:border-blue-900/30 dark:hover:border-blue-800',
    titleColor: 'text-blue-600/70 dark:text-blue-500/70',
    numberColor: 'text-blue-700 dark:text-blue-400',
    iconColor: 'text-blue-500'
  },
  next_twentyeight_days: {
    title: trans('eventi.stats.next_twentyeight_days'),
    icon: Clock,
    range: {
      date_from: formatDate(now),
      date_to: formatDate(new Date(now.getTime() + 28 * 86400000)),
    },
    cardStyle: 'bg-emerald-50/50 border-emerald-100 hover:border-emerald-300 dark:bg-emerald-900/10 dark:border-emerald-900/30 dark:hover:border-emerald-800',
    titleColor: 'text-emerald-600/70 dark:text-emerald-500/70',
    numberColor: 'text-emerald-700 dark:text-emerald-400',
    iconColor: 'text-emerald-500'
  }
};
</script>

<template>
  <div class="grid gap-4 md:grid-cols-2 lg:grid-cols-4">
    <div
      v-for="(stat, key) in displayStats"
      :key="key"
      @click="emit('setFilter', stat.range)"
      :class="[
        'relative overflow-hidden rounded-2xl border p-5 shadow-sm transition-all duration-200 cursor-pointer hover:shadow-md hover:-translate-y-0.5',
        stat.cardStyle
      ]"
    >
      <div class="flex items-center justify-between mb-4">
        <h3 :class="['text-[10px] font-bold uppercase tracking-widest', stat.titleColor]">
          {{ stat.title }}
        </h3>
        <component :is="stat.icon" :class="['w-5 h-5', stat.iconColor]" />
      </div>
      <div class="flex items-baseline gap-2">
        <span :class="['text-3xl font-black', stat.numberColor]">
          {{ stats[key] || 0 }}
        </span>
      </div>
    </div>
  </div>
</template>
