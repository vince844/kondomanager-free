<script setup lang="ts">
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { AlertTriangle, CalendarDays, CalendarCheck, CalendarClock } from 'lucide-vue-next';

defineProps<{
  stats: {
    next_seven_days: number,
    next_fourteen_days: number,
    next_twentyeight_days: number,
    expired_last_seven_days: number, 
  }
}>();

const displayStats = {
  expired_last_seven_days: {
    title: 'Scaduti negli ultimi 7 giorni',
    icon: AlertTriangle,
    format: (val: number) => val.toString(),
  },
  next_seven_days: {
    title: 'Scadenza prossimi 7 giorni',
    icon: CalendarDays,
    format: (val: number) => val.toString(),
  },
  next_fourteen_days: {
    title: 'Scadenza prossimi 14 giorni',
    icon: CalendarCheck,
    format: (val: number) => val.toString(),
  },
  next_twentyeight_days: {
    title: 'Scadenza prossimi 30 giorni',
    icon: CalendarClock,
    format: (val: number) => val.toString(),
  }
};
</script>

<template>
  <div class="grid gap-4 md:grid-cols-1 lg:grid-cols-4">
    <Card v-for="(stat, key) in displayStats" :key="key">
      <CardHeader class="flex flex-row items-center justify-between space-y-0 pb-2">
        <CardTitle class="text-sm font-medium">
          {{ stat.title }}
        </CardTitle>
        <component :is="stat.icon" class="w-5 h-5 text-muted-foreground" />
      </CardHeader>
      <CardContent>
        <div class="text-2xl font-bold">
          {{ stat.format(stats[key]) || 0 }}
        </div>
      </CardContent>
    </Card>
  </div>
</template>
