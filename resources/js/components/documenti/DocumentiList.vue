<script setup lang="ts">

import { ref } from "vue";
import { CloudDownload } from 'lucide-vue-next';
import { usePermission } from "@/composables/permissions";
import type { Documento } from '@/types/documenti';

const props = defineProps<{
  documenti: Documento[];
}>();

const { generateRoute } = usePermission();

const expandedIds = ref<Set<number>>(new Set());

const isExpanded = (id: number) => expandedIds.value.has(id);

const toggleExpanded = (id: number) => {
  if (expandedIds.value.has(id)) {
    expandedIds.value.delete(id);
  } else {
    expandedIds.value.add(id);
  }
};

const truncate = (text: string, length: number = 120) => {
  return text.length > length ? `${text.slice(0, length)}...` : text;
};

const truncatedName = (name: string, length: number = 80) => {
  return name.length > length ? `${name.slice(0, length)}...` : name;
};

</script>

<template>
    <div class="flow-root">
      <ul role="list" class="divide-y divide-gray-200">
        <div v-if="!documenti.length" class="p-4 mt-7 text-sm text-gray-800 rounded-lg bg-gray-50 dark:bg-gray-800 dark:text-gray-300" role="alert">
          <span class="font-medium">Nessun documento in archivio ancora creato!</span>
        </div>
        <li v-for="documento in documenti" :key="documento.id" class="py-3 sm:py-4">
          <div class="flex items-center space-x-4">
            <div class="flex-1 min-w-0">
  
              <a
                :href="route(generateRoute('documenti.download'), { id: documento.id })"
                class="inline-flex items-center gap-2 text-sm text-muted-foreground font-bold hover:text-primary transition-colors"
              >
                <CloudDownload class="w-4 h-4 text-green-400" />

                {{ truncatedName(documento.name, 40) }}
                
              </a>
  
              <div class="text-xs py-1 text-gray-600 font-light">
                <span>Inviato {{ documento.created_at }} da {{ documento.created_by.user.name }} in {{ documento.categoria.name.toLowerCase() }}</span>
              </div>
  
              <p class="text-sm text-gray-500 mt-3">
                <span class="mt-1 text-gray-600 py-1">
                  {{ isExpanded(Number(documento.id)) ? documento.description : truncate(documento.description, 120) }}
                </span>
                <button
                  v-if="documento.description.length > 120"
                  class="text-xs font-semibold text-gray-500 ml-1"
                  @click="toggleExpanded(Number(documento.id))"
                >
                  {{ isExpanded(Number(documento.id)) ? 'Mostra meno' : 'Mostra tutto' }}
                </button>
              </p>
  
            </div>
          </div>
        </li>
      </ul>
    </div>
  </template>