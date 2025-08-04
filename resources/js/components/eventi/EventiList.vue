<script setup lang="ts">

import { ref } from "vue";
import { Clock, ClockAlert, ClockArrowUp } from 'lucide-vue-next';
import type { Evento } from '@/types/eventi';

const props = defineProps<{
  eventi: Evento[];
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

const truncatedName = (name: string, length: number = 80) => {
  return name.length > length ? `${name.slice(0, length)}...` : name;
};

const getDaysRemaining = (dateInput: string | Date): number => {
  const now = new Date();
  const target = typeof dateInput === 'string' ? new Date(dateInput) : dateInput;
  const msPerDay = 1000 * 60 * 60 * 24;
  return Math.floor((target.getTime() - now.getTime()) / msPerDay);
};

const getIconAndColor = (daysRemaining: number) => {
  if (daysRemaining <= 7) {
    return { icon: ClockAlert, color: 'text-red-500' };
  } else if (daysRemaining <= 14) {
    return { icon: ClockArrowUp, color: 'text-yellow-500' };
  } else {
    return { icon: Clock, color: 'text-green-400' };
  }
};

</script>

<template>
  <div class="flow-root">
    <ul role="list" class="divide-y divide-gray-200">
      <div
        v-if="!eventi.length"
        class="p-4 mt-7 text-sm text-gray-800 rounded-lg bg-gray-50 dark:bg-gray-800 dark:text-gray-300"
        role="alert"
      >
        <span class="font-medium">Nessuna scadenza in agenda ancora creata!</span>
      </div>

      <li v-for="evento in eventi" :key="evento.id" class="py-3 sm:py-4">
        <div class="flex items-center space-x-4">
          <div class="flex-1 min-w-0">
            <a
              class="inline-flex items-center gap-2 text-sm text-muted-foreground font-bold hover:text-primary transition-colors"
            >
              <component
                :is="getIconAndColor(getDaysRemaining(evento.occurs)).icon"
                :class="['w-4 h-4', getIconAndColor(getDaysRemaining(evento.occurs)).color]"
              />
              {{ truncatedName(evento.title, 40) }}
            </a>

            <div class="text-xs py-1 text-gray-600 font-light">
              <span>In scadenza il {{ evento.occurs_at }} in {{ evento.categoria.name.toLowerCase() }}</span>
            </div>

            <p class="text-sm text-gray-500 mt-3">
              <span class="mt-1 text-gray-600 py-1">
                {{ isExpanded(Number(evento.id)) ? evento.description : truncate(evento.description, 120) }}
              </span>
              <button
                v-if="evento.description.length > 120"
                class="text-xs font-semibold text-gray-500 ml-1"
                @click="toggleExpanded(Number(evento.id))"
              >
                {{ isExpanded(Number(evento.id)) ? 'Mostra meno' : 'Mostra tutto' }}
              </button>
            </p>
          </div>
        </div>
      </li>
    </ul>
  </div>
</template>
