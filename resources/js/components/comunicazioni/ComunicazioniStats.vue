<script setup lang="ts">

import { computed } from 'vue';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { CircleArrowUp, CircleArrowRight, CircleArrowDown, CircleAlert } from 'lucide-vue-next';
import type { Comunicazione } from '@/types/comunicazioni';

const props = defineProps<{
  comunicazioni: Comunicazione[];
}>();

const countByPriority = computed(() => {
  const counts = {
    bassa: 0,
    media: 0,
    alta: 0,
    urgente: 0,
  };

  for (const s of props.comunicazioni) {
    switch (s.priority) {
      case 'bassa': counts.bassa++; break;
      case 'media': counts.media++; break;
      case 'alta': counts.alta++; break;
      case 'urgente': counts.urgente++; break;
    }
  }

  return counts;
});
</script>

<template>
  <div class="grid gap-4 md:grid-cols-1 lg:grid-cols-4">
    <Card v-for="(icon, key) in {
      bassa: CircleArrowDown,
      media: CircleArrowRight,
      alta: CircleArrowUp,
      urgente: CircleAlert
    }" :key="key">
      <CardHeader class="flex flex-row items-center justify-between space-y-0 pb-2">
        <CardTitle class="text-sm font-medium">
          Priorità {{ key }}
        </CardTitle>
        <component :is="icon" class="w-5 h-5 text-muted-foreground" />
      </CardHeader>
      <CardContent>
        <div class="text-2xl font-bold">
          + {{ countByPriority[key] }}
        </div>
      </CardContent>
    </Card>
  </div>
</template>
