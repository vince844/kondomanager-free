<script setup lang="ts">
import { defineProps, ref, computed } from "vue";
import { Link } from '@inertiajs/vue3';
import type { Segnalazione } from '@/types/segnalazioni';

const props = defineProps<{
  segnalazioni: Segnalazione[];
  priorityIcons: Record<string, any>; // or a more specific component map if you have one
  routeName: string;
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

const truncate = (text: string, length: number = 120) => {
  return text.length > length ? `${text.slice(0, length)}...` : text;
};

</script>

<template>
    <div class="flow-root">
      <ul role="list" class="divide-y divide-gray-200">
        <div v-if="!segnalazioni.length" class="p-4 mt-7 text-sm text-gray-800 rounded-lg bg-gray-50 dark:bg-gray-800 dark:text-gray-300" role="alert">
          <span class="font-medium">Nessuna segnalazione guasto ancora creata!</span>
        </div>
        <li v-for="segnalazione in segnalazioni" :key="segnalazione.id" class="py-3 sm:py-4">
          <div class="flex items-center space-x-4">
            <div class="flex-1 min-w-0">
  
              <Link
                :href="route(routeName, { id: segnalazione.id })"
                prefetch
                class="inline-flex items-center gap-2 text-sm text-muted-foreground font-bold"
              >
                <component
                  :is="priorityIcons[segnalazione.priority]"
                  class="w-4 h-4"
                  :class="{
                    'text-blue-400': segnalazione.priority === 'bassa',
                    'text-yellow-300': segnalazione.priority === 'media',
                    'text-orange-400': segnalazione.priority === 'alta',
                    'text-red-500': segnalazione.priority === 'urgente',
                  }"
                />
                {{ segnalazione.subject }}
              </Link>
  
              <div class="text-xs py-1 text-gray-600 font-light">
                <span>Inviata {{ segnalazione.created_at }} da {{ segnalazione.created_by.name }}</span>
              </div>
  
              <p class="text-sm text-gray-500 mt-3">
                <span class="mt-1 text-gray-600 py-1">
                  {{ isExpanded(Number(segnalazione.id)) ? segnalazione.description : truncate(segnalazione.description, 120) }}
                </span>
                <button
                  class="text-xs font-semibold text-gray-500 ml-1"
                  @click="toggleExpanded(Number(segnalazione.id))"
                >
                  {{ isExpanded(Number(segnalazione.id)) ? 'Mostra meno' : 'Mostra tutto' }}
                </button>
              </p>
  
            </div>
          </div>
        </li>
      </ul>
    </div>
  </template>