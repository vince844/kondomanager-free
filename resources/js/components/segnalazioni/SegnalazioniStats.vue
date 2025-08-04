<script setup lang="ts">
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { CircleArrowUp, CircleArrowRight, CircleArrowDown, CircleAlert } from 'lucide-vue-next';

defineProps<{
  stats: {
    bassa: number,
    media: number,
    alta: number,
    urgente: number
  }
}>();

const icons = {
  bassa: CircleArrowDown,
  media: CircleArrowRight,
  alta: CircleArrowUp,
  urgente: CircleAlert
};
</script>

<template>
  <div class="grid gap-4 md:grid-cols-1 lg:grid-cols-4">
    <Card v-for="(icon, key) in icons" :key="key">
      <CardHeader class="flex flex-row items-center justify-between space-y-0 pb-2">
        <CardTitle class="text-sm font-medium">
          Priorità {{ key }}
        </CardTitle>
        <component :is="icon" class="w-5 h-5 text-muted-foreground" />
      </CardHeader>
      <CardContent>
        <div class="text-2xl font-bold">
          {{ stats[key] || 0 }}
        </div>
      </CardContent>
    </Card>
  </div>
</template>