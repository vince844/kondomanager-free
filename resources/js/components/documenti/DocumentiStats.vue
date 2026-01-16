<script setup lang="ts">

import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { HardDrive, FileText, Calendar, Calculator } from 'lucide-vue-next';
import { formatBytes, formatNumber } from '@/utils/formatBytes'; 

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
    title: 'Spazio totale utilizzato',
    icon: HardDrive,
    format: (val: number) => formatBytes(val), 
  },
  total_documents: {
    title: 'Documenti totali',
    icon: FileText,
    format: (val: number) => formatNumber(val), 
  },
  uploaded_this_month: {
    title: 'Caricati questo mese',
    icon: Calendar,
    format: (val: number) => formatNumber(val), 
  },
  average_size_bytes: {
    title: 'Dimensione media documento',
    icon: Calculator,
    format: (val: number) => formatBytes(val), 
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
          {{ stat.format(stats[key]) }}
        </div>
      </CardContent>
    </Card>
  </div>
</template>