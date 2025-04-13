<script setup lang="ts">

import { Head, Link } from '@inertiajs/vue3';
import AppLayout from '@/layouts/AppLayout.vue';
import { Button } from '@/components/ui/button';
import Heading from '@/components/Heading.vue';
import { List, Pencil, Lock, LockOpen } from 'lucide-vue-next';
import type { Segnalazione } from '@/types/segnalazioni';
import '@vuepic/vue-datepicker/dist/main.css';
import SegnalazioneStats from '@/components/segnalazioni/SegnalazioneStats.vue';

const props = defineProps<{
  segnalazione: Segnalazione;
}>();  

</script>

<template>

    <Head title="Visualizza segnalazione guasto" />
  
    <AppLayout >
  
      <div class="px-4 py-6">
        
        <Heading title="Visualizza segnalazione guasto" description="Di seguito i dettagli della segnalazione guasto" />

                <!-- Container for buttons (wraps buttons for alignment) -->
                <div class="flex flex-col lg:flex-row lg:justify-end space-y-2 lg:space-y-0 lg:space-x-2 items-start lg:items-center">

                    <!-- Button for "Update Segnalazione" -->
                    <Button  class="lg:flex h-8 w-full lg:w-auto">
                        <Pencil class="w-4 h-4" />
                        Modifica
                    </Button>

                    <!-- Button for "Elenco Segnalazioni" -->
                    <Button class="lg:flex h-8 w-full lg:w-auto">
                        <!-- Conditional Rendering of Lucide Icons -->
                        <LockOpen v-if="props.segnalazione.is_locked" class="w-4 h-4" />
                        <Lock v-else class="w-4 h-4" />

                        <Link 
                        :href="route('admin.segnalazioni.toggleResolve', { id: props.segnalazione.id })" 
                        method="post" 
                        as="button" 
                        class="block lg:inline"
                        >
                        {{ props.segnalazione.is_locked ? 'Sblocca' : 'Blocca' }}
                        </Link>
                    </Button>

                    <Button class="lg:flex h-8 w-full lg:w-auto">
                        <List class="w-4 h-4" />
                        <Link :href="route('admin.segnalazioni.index')" class="block lg:inline">
                        Elenco 
                        </Link>
                    </Button>

                </div>

                <div class='mt-4'>
                    <SegnalazioneStats :segnalazione="segnalazione" />
                </div>

                <!-- Two-column layout (3:1 ratio) -->
                <div class="grid grid-cols-1 lg:grid-cols-4 gap-3 ">

                    <!-- Main Card (3/4 width) -->
                    <div class="col-span-1 lg:col-span-3 mt-3">
                        
                        <div class="bg-white dark:bg-muted rounded shadow-sm p-6 space-y-4 border">
                            <div class="mb-1 space-y-0.5">
                            <h2 class="text-xl font-semibold tracking-tight">{{ props.segnalazione.subject }}</h2>
                            <p class="text-sm text-muted-foreground">
                                Inviata {{  props.segnalazione.created_at}} da {{props.segnalazione.created_by.name }} 
                            </p>
                        </div>
                        
                        <div class=" text-muted-foreground text-justify">

                            {{props.segnalazione.description }}

                        </div>
                            
                        </div>
                    </div>

                    <!-- Side Card (1/4 width) -->
                    <div class="col-span-1 mt-3">
                        <div class="bg-white dark:bg-muted rounded shadow-sm p-3 border">

                            altri dettagli
                            
                        </div>
                    </div>
                
                </div>

          
      </div>
    </AppLayout> 
  
  </template>

<style src="vue-select/dist/vue-select.css"></style>