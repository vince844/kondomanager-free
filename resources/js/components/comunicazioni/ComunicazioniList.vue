<script setup lang="ts">

import { ref } from "vue";
import { Link } from '@inertiajs/vue3';
import { usePermission } from "@/composables/permissions";
import { CircleArrowDown, CircleArrowRight, CircleArrowUp, CircleAlert } from 'lucide-vue-next';
import { trans } from 'laravel-vue-i18n';
import type { Comunicazione } from '@/types/comunicazioni';

const props = defineProps<{
  comunicazioni: Comunicazione[];
  routeName: string;
}>();

const priorityIcons = {
  bassa: CircleArrowDown,
  media: CircleArrowRight,
  alta: CircleArrowUp,
  urgente: CircleAlert,
}

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

</script>

<template>
    <div class="flow-root">
      <ul role="list" class="divide-y divide-gray-200">
        <div v-if="!comunicazioni.length" class="p-4 mt-7 text-sm text-gray-800 rounded-lg bg-gray-50 dark:bg-gray-800 dark:text-gray-300" role="alert">
          <span class="font-medium">{{ trans('comunicazioni.dialogs.no_communications_created') }}</span>
        </div>
        <li v-for="comunicazione in comunicazioni" :key="comunicazione.id" class="py-3 sm:py-4">
          <div class="flex items-center space-x-4">
            <div class="flex-1 min-w-0">
  
              <Link
                :href="route(generateRoute(routeName), { id: comunicazione.id })"
                class="inline-flex items-center gap-2 text-sm font-bold hover:text-muted-foreground transition-colors"
              >
                <component
                  :is="priorityIcons[comunicazione.priority]"
                  class="w-4 h-4"
                  :class="{
                    'text-green-400': comunicazione.priority === 'bassa',
                    'text-blue-400': comunicazione.priority === 'media',
                    'text-orange-400': comunicazione.priority === 'alta',
                    'text-red-500': comunicazione.priority === 'urgente',
                  }"
                />
                {{ comunicazione.subject }}
              </Link>
  
              <div class="text-xs py-1 text-gray-600 font-light">
                <span> 
                  {{ 
                    trans('comunicazioni.visibility.sent_on_by', { 
                        date: comunicazione.created_at, 
                        name: comunicazione.created_by.user.name 
                    }) 
                  }}
                </span>
              </div>
  
              <p class="text-sm text-gray-500 mt-3">
                <span class="mt-1 text-gray-600 py-1">
                  {{ 
                    isExpanded(Number(comunicazione.id)) 
                    ? comunicazione.description 
                    : truncate(comunicazione.description, 120) 
                  }}
                </span>
                <button
                  class="text-xs font-semibold text-gray-500 ml-1"
                  @click="toggleExpanded(Number(comunicazione.id))"
                >
                  {{ 
                    isExpanded(Number(comunicazione.id)) 
                    ? trans('comunicazioni.actions.show_less')
                    : trans('comunicazioni.actions.show_more')
                  }}
                </button>
              </p>
  
            </div>
          </div>
        </li>
      </ul>
    </div>
  </template>