<script setup lang="ts">

import { ref } from 'vue';
import axios from 'axios';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { CircleArrowUp, CircleArrowRight, CircleArrowDown, CircleAlert } from 'lucide-vue-next';

const stats = ref({
  bassa: 0,
  media: 0,
  alta: 0,
  urgente: 0,
});

const isLoading = ref(false);

function loadStats() {
  isLoading.value = true;
  axios.get(route('admin.comunicazioni.stats'))
    .then(response => {
      stats.value = {
        bassa: response.data.bassa ?? 0,
        media: response.data.media ?? 0,
        alta: response.data.alta ?? 0,
        urgente: response.data.urgente ?? 0,
      };
    })
    .finally(() => {
      isLoading.value = false;
    });
}

defineExpose({ loadStats });

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
          <span v-if="isLoading">...</span>
          <span v-else>+ {{ stats[key] }}</span>
        </div>
      </CardContent>
    </Card>
  </div>
</template>
