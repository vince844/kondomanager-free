<script setup lang="ts">

import { ref, onMounted, watch, computed } from 'vue';
import { watchDebounced, useTimeoutFn } from '@vueuse/core';
import { Head, router, Link } from "@inertiajs/vue3";
import AppLayout from "@/layouts/AppLayout.vue";
import Heading from "@/components/Heading.vue";
import type { Comunicazione } from "@/types/comunicazioni";
import { Button } from "@/components/ui/button";
import { CircleArrowDown, CircleArrowRight, CircleArrowUp, CircleAlert, List } from "lucide-vue-next";
import { usePermission } from "@/composables/permissions";

const { hasPermission, generateRoute } = usePermission();

const props = defineProps<{
  comunicazione: Comunicazione
}>();

const priorityIcons = {
  bassa: CircleArrowDown,
  media: CircleArrowRight,
  alta: CircleArrowUp,
  urgente: CircleAlert,
};

</script>

<template>
  <Head title="Elenco comunicazioni bacheca" />

  <AppLayout>
    <div class="px-4 py-6">

        <div class="flex flex-col lg:flex-row lg:justify-end space-y-2 lg:space-y-0 lg:space-x-2 items-start lg:items-center">
        
            <Link 
                as="button"
                :href="route(generateRoute('comunicazioni.index'))" 
                class="inline-flex items-center gap-2 rounded-md bg-primary text-sm font-medium text-white px-3 py-1.5 h-8 w-full lg:w-auto lg:h-8 hover:bg-primary/90 order-last lg:order-none lg:ml-auto"
            >
                <List class="w-4 h-4" />
                <span>Bacheca</span>
            </Link>

        </div>

        <div class="col-span-1 lg:col-span-3 mt-3">
                    
            <div class="bg-white dark:bg-muted rounded-md shadow-sm p-6 space-y-4 border">
                <div class="mb-1 space-y-0.5">
                    <!-- Icona + Titolo in linea -->
                    <div class="flex items-center space-x-2">
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
                    <h2 class="text-xl font-semibold tracking-tight">
                        {{ comunicazione.subject }}
                    </h2>
                    </div>

                    <!-- Sottotitolo con data e autore -->
                    <p class="text-sm text-muted-foreground">
                    Inviata {{ comunicazione.created_at }} da {{ comunicazione.created_by.name }}
                    </p>
                </div>
        
                <div class=" text-muted-foreground text-justify">

                    {{props.comunicazione.description }}

                </div>
                    
            </div>
        </div>

    </div>
  </AppLayout>
</template>
