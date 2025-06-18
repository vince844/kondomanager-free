<script setup lang="ts">

import { ref } from "vue";
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import type { Documento } from '@/types/documenti';
import { usePermission } from "@/composables/permissions";

const { generateRoute } = usePermission();

defineProps<{
  documento: Documento;
}>();

const expandedIds = ref<Set<number>>(new Set());

const isExpanded = (id: number) => expandedIds.value.has(id);

const toggleExpanded = (id: number) => {
  if (expandedIds.value.has(id)) {
    expandedIds.value.delete(id);
  } else {
    expandedIds.value.add(id);
  }
};

const truncate = (text: string, length: number = 50) => {
  return text.length > length ? `${text.slice(0, length)}...` : text;
};

const truncatedName = (name: string, length: number = 80) => {
  return name.length > length ? `${name.slice(0, length)}...` : name;
};

</script>

<template>
  <Card>
  <CardHeader class="p-2">
    <div class="flex items-center justify-between">
      <CardTitle class="text-base font-medium">
        <a
          :href="route(generateRoute('documenti.download'), { id: documento.id })"
          class="text-primary hover:text-muted-foreground"
        >
          {{ truncatedName(documento.name, 20) }}
        </a>
      </CardTitle>

      <Badge
        variant="outline"
        class="rounded-md text-xs"
      >
        {{ documento.mime_type }}
      </Badge>
    </div>

    <div class="text-xs text-gray-600 font-light">
      <span>Inviato {{ documento.created_at }} da {{ documento.created_by.user.name }}</span>
    </div>
  </CardHeader>


    <CardContent class="p-2">
      <div class="text-sm text-muted-foreground">
 
        <span class="mt-1 text-gray-600 py-1">
          {{ isExpanded(Number(documento.id)) ? documento.description : truncate(documento.description, 50) }}
        </span>
        <button
          v-if="documento.description.length > 50"
          class="text-xs font-semibold text-gray-500 ml-1"
          @click="toggleExpanded(Number(documento.id))"
        >
          {{ isExpanded(Number(documento.id)) ? 'Mostra meno' : 'Mostra tutto' }}
        </button>
       
      </div>
    </CardContent>
  </Card>
</template>
