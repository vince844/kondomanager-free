<script setup lang="ts">
import { HardDrive, FileText, Calendar, Calculator } from 'lucide-vue-next';
import { formatBytes, formatNumber } from '@/utils/formatBytes'; 
import { trans } from 'laravel-vue-i18n';

defineProps<{
  stats: {
    total_storage_bytes: number,
    total_documents: number,
    uploaded_this_month: number,
    average_size_bytes: number
  }
}>();

const displayStats = {
  total_storage_bytes: {
    title: 'documenti.stats.total_storage_bytes',
    icon: HardDrive,
    format: (val: number) => formatBytes(val, undefined, true),
    cardStyle: 'bg-violet-50/50 border-violet-100 dark:bg-violet-900/10 dark:border-violet-900/30',
    titleColor: 'text-violet-600/70 dark:text-violet-500/70',
    numberColor: 'text-violet-700 dark:text-violet-400',
    iconColor: 'text-violet-500'
  },
  total_documents: {
    title: 'documenti.stats.total_documents',
    icon: FileText,
    format: (val: number) => formatNumber(val),
    cardStyle: 'bg-blue-50/50 border-blue-100 dark:bg-blue-900/10 dark:border-blue-900/30',
    titleColor: 'text-blue-600/70 dark:text-blue-500/70',
    numberColor: 'text-blue-700 dark:text-blue-400',
    iconColor: 'text-blue-500'
  },
  uploaded_this_month: {
    title: 'documenti.stats.uploaded_this_month',
    icon: Calendar,
    format: (val: number) => formatNumber(val),
    cardStyle: 'bg-emerald-50/50 border-emerald-100 dark:bg-emerald-900/10 dark:border-emerald-900/30',
    titleColor: 'text-emerald-600/70 dark:text-emerald-500/70',
    numberColor: 'text-emerald-700 dark:text-emerald-400',
    iconColor: 'text-emerald-500'
  },
  average_size_bytes: {
    title: 'documenti.stats.average_size_bytes',
    icon: Calculator,
    format: (val: number) => formatBytes(val, undefined, true),
    cardStyle: 'bg-rose-50/50 border-rose-100 dark:bg-rose-900/10 dark:border-rose-900/30',
    titleColor: 'text-rose-600/70 dark:text-rose-500/70',
    numberColor: 'text-rose-700 dark:text-rose-400',
    iconColor: 'text-rose-500'
  }
};
</script>

<template>
  <div class="grid gap-4 md:grid-cols-2 lg:grid-cols-4">
    <div 
      v-for="(item, key) in displayStats" 
      :key="key"
      :class="[
        'relative overflow-hidden rounded-2xl border p-5 shadow-sm transition-all hover:shadow-md',
        item.cardStyle
      ]"
    >
      <div class="flex items-center justify-between mb-4">
        <h3 :class="['text-[10px] font-bold uppercase tracking-widest', item.titleColor]">
          {{ trans(item.title) }}
        </h3>
        
        <component :is="item.icon" :class="['w-5 h-5', item.iconColor]" />
      </div>

      <div class="flex items-baseline gap-2">
        <span :class="['text-3xl font-black', item.numberColor]">
          {{ String(item.format(stats[key as keyof typeof stats] ?? 0)).split(' ')[0] }}
          <span 
            v-if="String(item.format(stats[key as keyof typeof stats] ?? 0)).split(' ')[1]" 
            class="text-lg font-bold opacity-60 ml-0.5"
          >
            {{ String(item.format(stats[key as keyof typeof stats] ?? 0)).split(' ')[1] }}
          </span>
        </span>
      </div>
    </div>
  </div>
</template>