<script setup lang="ts">

import { ref } from "vue";
import { useEventStyling } from '@/composables/useEventStyling';
import EventDetailsDialog from '@/components/eventi/EventDetailsDialog.vue'; 
import { Building2, Tag, CalendarDays } from 'lucide-vue-next';
import { trans } from 'laravel-vue-i18n';
import type { Evento } from '@/types/eventi';

const props = defineProps<{
  eventi: Evento[];
}>();

const { getEventStyle } = useEventStyling();
const expandedIds = ref<Set<number>>(new Set());
const selectedEvent = ref<Evento | null>(null);
const isDialogOpen = ref(false);

const openDetails = (evento: Evento) => {
  selectedEvent.value = evento;
  isDialogOpen.value = true;
};

// Gestione Espansione Descrizione
const isExpanded = (id: number) => expandedIds.value.has(id);
const toggleExpanded = (id: number, e: Event) => {
  e.stopPropagation(); 
  if (expandedIds.value.has(id)) {
    expandedIds.value.delete(id);
  } else {
    expandedIds.value.add(id);
  }
};

const getCondominioName = (evento: Evento) => {
    if (evento.meta && evento.meta.condominio_nome) {
        return evento.meta.condominio_nome;
    }
    if (evento.condomini && evento.condomini.length > 0) {
        return evento.condomini[0].nome;
    }
    return null;
};
</script>

<template>
  <div class="flow-root">
    <ul role="list" class="divide-y divide-gray-200">
      <div
        v-if="!eventi.length"
        class="flex items-center justify-center py-8 text-xs font-medium text-slate-400 uppercase tracking-widest"
      >
        {{ trans('dashboard.widgets.no_events_created') }}
      </div>

      <li v-for="evento in eventi" :key="evento.id" class="py-3 sm:py-4">
        <div 
            class="flex items-start space-x-4 cursor-pointer hover:bg-gray-50 dark:hover:bg-gray-800/50 rounded-lg p-2 transition-all group"
            @click="openDetails(evento)"
        >
          <div class="flex-1 min-w-0"> <a class="inline-flex items-center gap-2 text-sm font-bold transition-colors w-full">
              <component
                :is="getEventStyle(evento).icon"
                :class="['w-4 h-4 shrink-0', getEventStyle(evento).color]" 
              />
              <span :class="[getEventStyle(evento).color]" class="truncate" :title="evento.title">
                  {{ evento.title }}
              </span>
            </a>

            <div class="text-xs py-1 text-gray-600 font-light dark:text-gray-400 flex flex-wrap items-center gap-y-1 gap-x-1">
              
              <span class="font-medium whitespace-nowrap shrink-0" :class="getEventStyle(evento).color">
                {{ getEventStyle(evento).label }}
              </span>

              <span v-if="getCondominioName(evento)" 
                    class="flex items-center gap-1 text-slate-700 dark:text-slate-300 font-medium ml-1 max-w-[140px] sm:max-w-[220px]"
                    :title="getCondominioName(evento)"
              >
                 • <Building2 class="w-3 h-3 shrink-0" /> 
                 <span class="truncate">{{ getCondominioName(evento) }}</span>
              </span>

              <span class="flex items-center gap-1 whitespace-nowrap ml-1 shrink-0"> 
                • <Tag class="w-3 h-3" /> {{ evento.categoria?.name?.toLowerCase() }}
              </span>
              
              <span v-if="evento.start_time" class="flex items-center gap-1 whitespace-nowrap ml-1 shrink-0"> 
                • <CalendarDays class="w-3 h-3" /> {{ new Date(evento.start_time).toLocaleDateString() }}
              </span>
            </div>

            <div class="mt-2 text-sm text-gray-500 dark:text-gray-400">
              <p :class="{'line-clamp-2': !isExpanded(Number(evento.id))}" class="break-words">
                {{ evento.description }}
              </p>
              
              <button
                v-if="evento.description && evento.description.length > 120"
                class="text-xs font-semibold text-gray-500 hover:text-gray-800 dark:hover:text-white mt-1"
                @click="(e) => toggleExpanded(Number(evento.id), e)"
              >
                {{ isExpanded(Number(evento.id)) ? 'Mostra meno' : 'Mostra tutto' }}
              </button>
            </div>

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
