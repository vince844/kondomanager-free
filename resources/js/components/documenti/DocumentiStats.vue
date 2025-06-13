<script setup lang="ts">
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { HardDrive, FileText, Calendar, Calculator } from 'lucide-vue-next';

defineProps<{
  stats: {
    total_storage_bytes: number,
    total_documents: number,
    uploaded_this_month: number,
    average_size_bytes: number
  }
}>();

// Helper to format bytes automatically to B, KB, MB, GB, TB
function formatBytes(bytes: number): string {
  if (bytes === 0) return '0 B';
  const k = 1024;
  const sizes = ['B', 'KB', 'MB', 'GB', 'TB'];
  const i = Math.floor(Math.log(bytes) / Math.log(k));
  const size = (bytes / Math.pow(k, i)).toFixed(2);
  return `${size} ${sizes[i]}`;
}

const displayStats = {
  total_storage_bytes: {
    title: 'Spazio totale utilizzato',
    icon: HardDrive,
    format: formatBytes,
  },
  total_documents: {
    title: 'Documenti totali',
    icon: FileText,
    format: (val: number) => val.toString(),
  },
  uploaded_this_month: {
    title: 'Caricati questo mese',
    icon: Calendar,
    format: (val: number) => val.toString(),
  },
  average_size_bytes: {
    title: 'Dimensione media documento',
    icon: Calculator,
    format: formatBytes,
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
