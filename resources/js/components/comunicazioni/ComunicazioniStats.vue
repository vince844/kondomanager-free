<script setup lang="ts">

import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { CircleArrowUp, CircleArrowRight, CircleArrowDown, CircleAlert } from 'lucide-vue-next';
import { trans } from 'laravel-vue-i18n';

// Props ricevuti dal genitore
defineProps<{
  stats: {
    bassa: number;
    media: number;
    alta: number;
    urgente: number;
  };
}>();

const config = {
  bassa: {
    icon: CircleArrowDown,
    lang: 'comunicazioni.stats.low_priority'
  },
  media: {
    icon: CircleArrowRight,
    lang: 'comunicazioni.stats.medium_priority'
  },
  alta: {
    icon: CircleArrowUp,
    lang: 'comunicazioni.stats.high_priority'
  },
  urgente: {
    icon: CircleAlert,
    lang: 'comunicazioni.stats.urgent_priority'
  }
};
</script>

<template>
  <div class="grid gap-4 md:grid-cols-1 lg:grid-cols-4">
    <!-- Loop sulla configurazione -->
    <Card v-for="(item, key) in config" :key="key">
      <CardHeader class="flex flex-row items-center justify-between space-y-0 pb-2">
        <CardTitle class="text-sm font-medium">
          {{ trans(item.lang) }}
        </CardTitle>

        <!-- Icona corrispondente -->
        <component :is="item.icon" class="w-5 h-5 text-muted-foreground" />
      </CardHeader>

      <CardContent>
        <div class="text-2xl font-bold">
          <!-- Valore numerico della statistica -->
          {{ stats[key] ?? 0 }}
        </div>
      </CardContent>
    </Card>
  </div>
</template>