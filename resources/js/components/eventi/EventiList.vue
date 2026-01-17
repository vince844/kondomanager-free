<script setup lang="ts">
import { ref } from "vue";
import type { Evento } from '@/types/eventi';
// Importiamo il composable intelligente
import { useEventStyling } from '@/composables/useEventStyling';
import EventDetailsDialog from '@/components/eventi/EventDetailsDialog.vue'; 

const props = defineProps<{
  eventi: Evento[];
}>();

// Usiamo il composable condiviso invece di logica locale
const { getEventStyle } = useEventStyling();
const expandedIds = ref<Set<number>>(new Set());

// Logica Dialog
const selectedEvent = ref<Evento | null>(null);
const isDialogOpen = ref(false);

const openDetails = (evento: Evento) => {
  selectedEvent.value = evento;
  isDialogOpen.value = true;
};

// Logica Espansione Testo
const isExpanded = (id: number) => expandedIds.value.has(id);
const toggleExpanded = (id: number, e: Event) => {
  e.stopPropagation(); 
  if (expandedIds.value.has(id)) {
    expandedIds.value.delete(id);
  } else {
    expandedIds.value.add(id);
  }
};

const truncate = (text: string, length: number = 120) => {
    if (!text) return '';
    return text.length > length ? `${text.slice(0, length)}...` : text;
};
const truncatedName = (name: string, length: number = 40) => {
    if (!name) return '';
    return name.length > length ? `${name.slice(0, length)}...` : name;
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
        <div 
            class="flex items-center space-x-4 cursor-pointer hover:bg-gray-50 dark:hover:bg-gray-800/50 rounded-lg p-2 transition-all group"
            @click="openDetails(evento)"
        >
          <div class="flex-1 min-w-0">
            <a class="inline-flex items-center gap-2 text-sm font-bold transition-colors">
              <component
                :is="getEventStyle(evento).icon"
                :class="['w-4 h-4', getEventStyle(evento).color]"
              />
              <span :class="[getEventStyle(evento).color]">
                  {{ truncatedName(evento.title) }}
              </span>
            </a>

            <div class="text-xs py-1 text-gray-600 font-light dark:text-gray-400">
              <span class="font-medium" :class="getEventStyle(evento).color">
                {{ getEventStyle(evento).label }}
              </span>
              <span> • {{ evento.categoria?.name?.toLowerCase() }}</span>
              <span v-if="evento.start_time"> • il {{ new Date(evento.start_time).toLocaleDateString() }}</span>
            </div>

            <p class="text-sm text-gray-500 mt-2 dark:text-gray-400">
              <span class="mt-1 text-gray-600 py-1 dark:text-gray-300">
                {{ isExpanded(Number(evento.id)) ? evento.description : truncate(evento.description, 120) }}
              </span>
              <button
                v-if="evento.description && evento.description.length > 120"
                class="text-xs font-semibold text-gray-500 ml-1 hover:text-gray-800 dark:hover:text-white"
                @click="(e) => toggleExpanded(Number(evento.id), e)"
              >
                {{ isExpanded(Number(evento.id)) ? 'Mostra meno' : 'Mostra tutto' }}
              </button>
            </p>
          </div>
        </div>
      </li>
    </ul>

    <EventDetailsDialog 
        v-if="selectedEvent"
        :is-open="isDialogOpen"
        :evento="selectedEvent"
        @close="isDialogOpen = false"
    />
  </div>
</template>