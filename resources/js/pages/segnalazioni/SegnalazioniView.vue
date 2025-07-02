<script setup lang="ts">

import { Head, Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import AppLayout from '@/layouts/AppLayout.vue';
import Heading from '@/components/Heading.vue';
import { List, Lock, LockOpen, ListCheck, ListX } from 'lucide-vue-next';
import { usePermission } from '@/composables/permissions';
import { priorityConstants, statoConstants } from '@/lib/segnalazioni/constants';
import { Permission } from '@/enums/Permission';
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

    <Head title="Visualizza segnalazione guasto" />
  
    <AppLayout >
  
      <div class="px-4 py-6">
        
        <Heading title="Visualizza segnalazione guasto" description="Di seguito i dettagli della segnalazione guasto" />

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
                    <span>{{ props.segnalazione.is_locked ? 'Sblocca' : 'Blocca' }}</span>
                </Link>

                <Link 
                    as="button"
                    v-if="hasPermission([Permission.VIEW_SEGNALAZIONI])"
                    :href="route(generateRoute('segnalazioni.index'))" 
                    class="inline-flex items-center justify-center gap-2 rounded-md bg-primary text-sm font-medium text-white px-3 py-1.5 h-8 w-full lg:w-auto hover:bg-primary/90"
                >
                    <List class="w-4 h-4" />
                    <span>Elenco</span>
                </Link>
            </div>

         <!--    <div class='mt-4'>
                <SegnalazioneStats :segnalazione="segnalazione" />
            </div> -->

            <div class="bg-card mb-6 grid grid-cols-1 gap-x-8 gap-y-4 rounded-lg border p-6 text-sm md:grid-cols-2 mt-4">
                <!-- Left Column -->
                <div class="space-y-4">

                    <!-- Priority (dynamic) -->
                    <div class="flex items-center gap-2">
                        <span class="text-muted-foreground font-semibold w-24">Priorità:</span>

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
                                {{ priorityItem.label }}
                            </div>
                        </div>
                    </div>

                    <!-- Assignees -->
     <!--                <div class="flex items-center gap-2">
                    <span class="text-muted-foreground w-24">Assignees</span>
                    <div>
                        <div class="flex -space-x-2">
                        <span class="relative flex shrink-0 overflow-hidden rounded-full border-background h-5 w-5 border-2">
                            <img class="aspect-square h-full w-full" alt="Michael Kane" src="/avatars/avatar-1.png" />
                        </span>
                        <span class="relative flex shrink-0 overflow-hidden rounded-full border-background h-5 w-5 border-2">
                            <img class="aspect-square h-full w-full" alt="Olivier Giroud" src="/avatars/avatar-2.png" />
                        </span>
                        <span class="relative flex shrink-0 overflow-hidden rounded-full border-background h-5 w-5 border-2">
                            <img class="aspect-square h-full w-full" alt="Isabella Chen" src="/avatars/avatar-3.png" />
                        </span>
                        </div>
                    </div>
                    </div> -->

                    <!-- Due Date -->
             <!--        <div class="flex items-center gap-2">
                    <span class="text-muted-foreground w-24">Due Date</span>
                    <div class="flex items-center gap-1">
                        <svg class="tabler-icon tabler-icon-calendar text-muted-foreground h-3.5 w-3.5" xmlns="http://www.w3.org/2000/svg" width="24" height="24" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path d="M4 7a2 2 0 0 1 2 -2h12a2 2 0 0 1 2 2v12a2 2 0 0 1 -2 2h-12a2 2 0 0 1 -2 -2v-12z" />
                        <path d="M16 3v4" />
                        <path d="M8 3v4" />
                        <path d="M4 11h16" />
                        <path d="M11 15h1" />
                        <path d="M12 15v3" />
                        </svg>
                        <span>Jul 15, 2025</span>
                    </div>
                    </div> -->

                    <!-- Created -->
                    <div class="flex items-center gap-2">
                    <span class="text-muted-foreground font-semibold w-24">Creata:</span>
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
                    <span class="text-muted-foreground font-semibold w-24">Condominio:</span>
                    <span class="capitalize font-medium">{{ segnalazione.condominio.full.nome }}</span>
                    </div>
                </div>

                <!-- Right Column -->
                <div class="space-y-4">
                    
                       <div class="flex items-center gap-2">
                        <span class="text-muted-foreground font-semibold w-24">Stato:</span>

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
                                {{ statusItem.label }}
                            </div>
                        </div>
                    </div>

                    <!-- Status -->
                  <!--   <div class="flex items-center gap-2">
                    <span class="text-muted-foreground w-24">Status</span>
                    <div class="inline-flex items-center rounded-md border px-2.5 py-0.5 font-semibold transition-colors focus:outline-hidden focus:ring-2 focus:ring-ring focus:ring-offset-2 text-xs capitalize border-blue-200 bg-blue-100 text-blue-700">
                        In Progress
                    </div>
                    </div> -->

                    <!-- Sprint -->
                    <div class="flex items-center gap-2">
                    <span class="text-muted-foreground font-semibold w-24">Visibilità:</span>
                    <component 
                        :is="segnalazione.is_published ? ListCheck : ListX" 
                        :class="[
                        'text-muted-foreground',
                        segnalazione.is_published ? 'w-3.5 h-3.5' : 'w-3.5 h-3.5'
                        ]" 
                    />
                    {{ segnalazione.is_published ? 'Pubblicata' : 'Bozza' }}
                   <!--  <span>Sprint 24 (May 2024)</span> -->
                    </div>

                    <!-- Estimated Time -->
                    <div class="flex items-center gap-2">
                    <span class="text-muted-foreground w-24">Est. Time</span>
                    <div class="flex items-center gap-1">
                        <svg class="tabler-icon tabler-icon-clock-hour-4 text-muted-foreground h-3.5 w-3.5" xmlns="http://www.w3.org/2000/svg" width="24" height="24" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path d="M12 12m-9 0a9 9 0 1 0 18 0a9 9 0 1 0 -18 0" />
                        <path d="M12 12l3 2" />
                        <path d="M12 7v5" />
                        </svg>
                        <span>1 week</span>
                    </div>
                    </div>

                    <!-- Linked Items -->
                 <!--    <div class="flex gap-2">
                    <span class="text-muted-foreground w-24">Linked Items</span>
                    <div class="space-y-1">
                        <div class="flex items-center gap-1">
                        <svg class="tabler-icon tabler-icon-git-branch text-muted-foreground h-3.5 w-3.5" xmlns="http://www.w3.org/2000/svg" width="24" height="24" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path d="M7 18m-2 0a2 2 0 1 0 4 0a2 2 0 1 0 -4 0" />
                            <path d="M7 6m-2 0a2 2 0 1 0 4 0a2 2 0 1 0 -4 0" />
                            <path d="M17 6m-2 0a2 2 0 1 0 4 0a2 2 0 1 0 -4 0" />
                            <path d="M7 8l0 8" />
                            <path d="M9 18h6a2 2 0 0 0 2 -2v-5" />
                            <path d="M14 14l3 -3l3 3" />
                        </svg>
                        <span class="text-xs">PR #1234: Implement new workflow UI</span>
                        </div>
                        <div class="flex items-center gap-1">
                        <svg class="tabler-icon tabler-icon-git-branch text-muted-foreground h-3.5 w-3.5" xmlns="http://www.w3.org/2000/svg" width="24" height="24" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path d="M7 18m-2 0a2 2 0 1 0 4 0a2 2 0 1 0 -4 0" />
                            <path d="M7 6m-2 0a2 2 0 1 0 4 0a2 2 0 1 0 -4 0" />
                            <path d="M17 6m-2 0a2 2 0 1 0 4 0a2 2 0 1 0 -4 0" />
                            <path d="M7 8l0 8" />
                            <path d="M9 18h6a2 2 0 0 0 2 -2v-5" />
                            <path d="M14 14l3 -3l3 3" />
                        </svg>
                        <span class="text-xs">Issue #5678: Database schema updates</span>
                        </div>
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
                        <h2 class="text-xl font-semibold tracking-tight">{{ props.segnalazione.subject }}</h2>
                        <p class="text-sm text-muted-foreground">
                            Inviata {{  props.segnalazione.created_at}} da {{props.segnalazione.created_by.user.name }} 
                        </p>
                    </div>
                    
                    <div class="mt-4 text-muted-foreground text-justify">

                        {{props.segnalazione.description }}

                    </div>
                        
                    </div>
                </div>

                <!-- Side Card (1/4 width) -->
      <!--           <div class="col-span-1 mt-3">
                    <div class="bg-white dark:bg-muted rounded shadow-sm p-3 border">

                        altri dettagli
                        
                    </div>
                </div> -->
            
            </div>
      </div>
    </AppLayout> 
  
  </template>

<style src="vue-select/dist/vue-select.css"></style>