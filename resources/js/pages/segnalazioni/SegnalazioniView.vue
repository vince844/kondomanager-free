<script setup lang="ts">

import { Head, Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import AppLayout from '@/layouts/AppLayout.vue';
import Heading from '@/components/Heading.vue';
import { List, Lock, LockOpen, ListCheck, ListX } from 'lucide-vue-next';
import { usePermission } from '@/composables/permissions';
import { priorityConstants, statoConstants } from '@/lib/segnalazioni/constants';
import { Permission } from '@/enums/Permission';
import { trans } from 'laravel-vue-i18n';
import type { Segnalazione } from '@/types/segnalazioni';
import '@vuepic/vue-datepicker/dist/main.css';

const props = defineProps<{
  segnalazione: Segnalazione;
}>();  

const { hasPermission, generateRoute } = usePermission();

const priorityItem = computed(() => {
  return priorityConstants.find(p => p.value === props.segnalazione.priority);
});

const statusItem = computed(() => {
  return statoConstants.find(p => p.value === props.segnalazione.stato);
});

</script>

<template>

    <Head :title="trans('segnalazioni.header.view_ticket_head')" />
  
    <AppLayout >
  
      <div class="px-4 py-6">
        
        <Heading 
            :title="trans('segnalazioni.header.view_ticket_title')" 
            :description="trans('segnalazioni.header.view_ticket_description')" 
        />

            <div class="flex flex-wrap flex-col lg:flex-row lg:justify-end gap-2 items-start lg:items-center">
                <Link 
                    as="button"
                    method="post"
                    v-if="hasPermission([Permission.EDIT_SEGNALAZIONI])"
                    :href="route(generateRoute('segnalazioni.toggleResolve'), { id: props.segnalazione.id })" 
                    class="inline-flex items-center justify-center gap-2 rounded-md bg-primary text-sm font-medium text-white px-3 py-1.5 h-8 w-full lg:w-auto hover:bg-primary/90"
                >
                    <LockOpen v-if="props.segnalazione.is_locked" class="w-4 h-4" />
                    <Lock v-else class="w-4 h-4" />
                    <span>
                        {{ 
                            props.segnalazione.is_locked 
                            ? trans('segnalazioni.actions.unlock_ticket') 
                            : trans('segnalazioni.actions.lock_ticket') 
                        }}
                    </span>
                </Link>

                <Link 
                    as="button"
                    v-if="hasPermission([Permission.VIEW_SEGNALAZIONI])"
                    :href="route(generateRoute('segnalazioni.index'))" 
                    class="inline-flex items-center justify-center gap-2 rounded-md bg-primary text-sm font-medium text-white px-3 py-1.5 h-8 w-full lg:w-auto hover:bg-primary/90"
                >
                    <List class="w-4 h-4" />
                    <span>{{ trans('segnalazioni.actions.list_tickets') }}</span>
                </Link>
            </div>

            <div class="bg-card mb-6 grid grid-cols-1 gap-x-8 gap-y-4 rounded-lg border p-6 text-sm md:grid-cols-2 mt-4">
                <!-- Left Column -->
                <div class="space-y-4">

                    <!-- Priority (dynamic) -->
                    <div class="flex items-center gap-2">
                        <span class="text-muted-foreground font-semibold w-24">{{ trans('segnalazioni.table.priority') }}:</span>

                        <div
                            class="flex items-center gap-1"
                            v-if="priorityItem"
                        >
                            <div
                                class="inline-flex items-center rounded-md border px-2.5 py-0.5 font-semibold transition-colors focus:outline-hidden focus:ring-2 focus:ring-ring focus:ring-offset-2 border-transparent shadow-sm text-xs"
                                :class="priorityItem.colorClass"
                                >
                                <component
                                    :is="priorityItem.icon"
                                    class="h-3 w-3 mr-2"
                                    :class="priorityItem.colorClass"
                                />
                                {{ trans(priorityItem.label) }}
                            </div>
                        </div>
                    </div>

                    <!-- Created -->
                    <div class="flex items-center gap-2">
                    <span class="text-muted-foreground font-semibold w-24">{{ trans('segnalazioni.visibility.created_on') }}:</span>
                    <div class="flex items-center gap-1">
                        <svg class="tabler-icon tabler-icon-calendar text-muted-foreground h-3.5 w-3.5" xmlns="http://www.w3.org/2000/svg" width="24" height="24" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path d="M4 7a2 2 0 0 1 2 -2h12a2 2 0 0 1 2 2v12a2 2 0 0 1 -2 2h-12a2 2 0 0 1 -2 -2v-12z" />
                        <path d="M16 3v4" />
                        <path d="M8 3v4" />
                        <path d="M4 11h16" />
                        <path d="M11 15h1" />
                        <path d="M12 15v3" />
                        </svg>
                        <span>{{ segnalazione.created_at }}</span>
                    </div>
                    </div>

                    <!-- Project -->
                    <div class="flex items-center gap-2">
                    <span class="text-muted-foreground font-semibold w-24">{{ trans('segnalazioni.label.building') }}:</span>
                    <span class="capitalize font-medium">{{ segnalazione.condominio.full.nome }}</span>
                    </div>
                </div>

                <!-- Right Column -->
                <div class="space-y-4">
                    
                       <div class="flex items-center gap-2">
                        <span class="text-muted-foreground font-semibold w-24">{{ trans('segnalazioni.table.status') }}:</span>

                        <div
                            class="flex items-center gap-1"
                            v-if="statusItem"
                        >
                        
                            <div
                                class="inline-flex items-center rounded-md border px-2.5 py-0.5 font-semibold transition-colors focus:outline-hidden focus:ring-2 focus:ring-ring focus:ring-offset-2 border-transparent shadow-sm text-xs"
                                :class="statusItem.colorClass"
                            >
                                <component
                                    :is="statusItem.icon"
                                    class="h-3 w-3 mr-2"
                                    :class="statusItem.colorClass"
                                />
                                {{ trans(statusItem.label) }}
                            </div>
                        </div>
                    </div>

                    <!-- Sprint -->
                    <div class="flex items-center gap-2">
                    <span class="text-muted-foreground font-semibold w-24">{{ trans('segnalazioni.table.visibility') }}:</span>
                    <component 
                        :is="segnalazione.is_published ? ListCheck : ListX" 
                        :class="[
                        'text-muted-foreground',
                        segnalazione.is_published ? 'w-3.5 h-3.5' : 'w-3.5 h-3.5'
                        ]" 
                    />
                    {{ 
                        segnalazione.is_published 
                        ? trans('segnalazioni.visibility.public') 
                        : trans('segnalazioni.visibility.private') 
                    }}
                   <!--  <span>Sprint 24 (May 2024)</span> -->
                    </div>

                    <!-- Estimated Time -->
             <!--        <div class="flex items-center gap-2">
                    <span class="text-muted-foreground w-24">Est. Time</span>
                    <div class="flex items-center gap-1">
                        <svg class="tabler-icon tabler-icon-clock-hour-4 text-muted-foreground h-3.5 w-3.5" xmlns="http://www.w3.org/2000/svg" width="24" height="24" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path d="M12 12m-9 0a9 9 0 1 0 18 0a9 9 0 1 0 -18 0" />
                        <path d="M12 12l3 2" />
                        <path d="M12 7v5" />
                        </svg>
                        <span>1 week</span>
                    </div>
                    </div> -->

                </div>

            </div>

            <!-- Two-column layout (3:1 ratio) -->
          <!--   <div class="grid grid-cols-1 lg:grid-cols-4 gap-3 "> -->
            <div class="">
               
                <!-- Main Card (3/4 width) -->
                <div class="col-span-1 lg:col-span-3 mt-3">
                    
                    <div class="bg-white dark:bg-muted rounded shadow-sm p-6 space-y-4 border">
                        <div class="mb-1 space-y-0.5">
                            <h2 class="text-xl font-semibold tracking-tight">
                                {{ props.segnalazione.subject }}
                            </h2>
                            <p class="text-sm text-muted-foreground">
                                {{ 
                                    trans('segnalazioni.visibility.sent_on_by', { 
                                        date: props.segnalazione.created_at, 
                                        name: props.segnalazione.created_by.user.name 
                                    }) 
                                }}
                            </p>
                        </div>
                        
                        <div class="mt-4 text-muted-foreground text-justify">

                            {{props.segnalazione.description }}

                        </div>
                        
                    </div>
                </div>
            
            </div>
      </div>
    </AppLayout> 
  
  </template>

<style src="vue-select/dist/vue-select.css"></style>