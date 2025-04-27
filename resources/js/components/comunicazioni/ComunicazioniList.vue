<script setup lang="ts">

import { defineProps, ref } from "vue";
import { Link } from '@inertiajs/vue3';
import type { Comunicazione } from '@/types/comunicazioni';

const props = defineProps<{
  comunicazioni: Comunicazione[];
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
        <div v-if="!comunicazioni.length" class="p-4 mt-7 text-sm text-gray-800 rounded-lg bg-gray-50 dark:bg-gray-800 dark:text-gray-300" role="alert">
          <span class="font-medium">Nessuna comunicazione in bacheca ancora creata!</span>
        </div>
        <li v-for="comunicazione in comunicazioni" :key="comunicazione.id" class="py-3 sm:py-4">
          <div class="flex items-center space-x-4">
            <div class="flex-1 min-w-0">
  
              <Link
                :href="route(routeName, { id: comunicazione.id })"
                prefetch
                class="inline-flex items-center gap-2 text-sm text-muted-foreground font-bold"
              >
                <component
                  :is="priorityIcons[comunicazione.priority]"
                  class="w-4 h-4"
                  :class="{
                    'text-blue-400': comunicazione.priority === 'bassa',
                    'text-yellow-300': comunicazione.priority === 'media',
                    'text-orange-400': comunicazione.priority === 'alta',
                    'text-red-500': comunicazione.priority === 'urgente',
                  }"
                />
                {{ comunicazione.subject }}
              </Link>
  
              <div class="text-xs py-1 text-gray-600 font-light">
                <span>Inviata {{ comunicazione.created_at }} da {{ comunicazione.created_by.user.name }}</span>
              </div>
  
              <p class="text-sm text-gray-500 mt-3">
                <span class="mt-1 text-gray-600 py-1">
                  {{ isExpanded(Number(comunicazione.id)) ? comunicazione.description : truncate(comunicazione.description, 120) }}
                </span>
                <button
                  class="text-xs font-semibold text-gray-500 ml-1"
                  @click="toggleExpanded(Number(comunicazione.id))"
                >
                  {{ isExpanded(Number(comunicazione.id)) ? 'Mostra meno' : 'Mostra tutto' }}
                </button>
              </p>
  
            </div>
          </div>
        </li>
      </ul>
    </div>
  </template>