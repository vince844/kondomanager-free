<script setup lang="ts">

import { Head, router, Link } from "@inertiajs/vue3";
import AppLayout from '@/layouts/AppLayout.vue';
import Heading from '@/components/Heading.vue';
import type { Comunicazione } from '@/types/comunicazioni';
import { CircleArrowDown, CircleArrowRight, CircleArrowUp, CircleAlert } from 'lucide-vue-next';
import {
  Button,
} from '@/components/ui/button'

import {
  Pagination,
  PaginationEllipsis,
  PaginationFirst,
  PaginationLast,
  PaginationList,
  PaginationListItem,
  PaginationNext,
  PaginationPrev,
} from '@/components/ui/pagination'

defineProps<{ 
  comunicazioni: {
    data: Comunicazione[]
    current_page: number
    last_page: number
    per_page: number
    total: number
  }
}>()

const priorityIcons = {
  bassa: CircleArrowDown,
  media: CircleArrowRight,
  alta: CircleArrowUp,
  urgente: CircleAlert,
}

const handlePageChange = (page: number) => {
  router.get(route('user.comunicazioni.index'), { page }, {
    preserveState: true,
    preserveScroll: true,
  })
}

</script>
<template>
    <Head title="Elenco comunicazioni bacheca" />
  
    <AppLayout>
      <div class="px-4 py-6">
        <Heading title="Elenco comunicazioni bacheca" description="Di seguito la tabella con l'elenco di tutte le comunicazioni in bacheca registrate" />
  
       <!--  <div ref="statsContainerRef">
          <ComunicazioniStats ref="statsRef" />
        </div> -->

        <div class="container mx-auto">
    
            <article 
              v-for="comunicazione in comunicazioni.data" 
              :key="comunicazione.id" 
            >
            
              <div class="border p-4 rounded-md mt-3">
             
                    <Link
                        :href="route('user.comunicazioni.show', { id: comunicazione.id })"
                        prefetch
                        class="inline-flex items-center gap-2 text-lg/7 font-semibold text-gray-900 group-hover:text-gray-600"
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
                
                  <div class="flex items-center gap-x-4 text-xs pt-1"> 
                      <time :datetime="comunicazione.created_at" class="text-gray-500">Inviata {{ comunicazione.created_at }} da {{ comunicazione.created_by?.name }}</time> 
                  </div>
                  <p class="mt-5 line-clamp-3 text-sm/6 text-gray-600">{{ comunicazione.description }}</p>
              </div>

            </article>

            <Pagination
      v-slot="{ page }"
      :items-per-page="comunicazioni.per_page"
      :total="comunicazioni.total"
      :default-page="comunicazioni.current_page"
      :sibling-count="1"
      show-edges
      @update:page="handlePageChange"
    >
      <PaginationList v-slot="{ items }" class="flex items-center justify-center gap-1 mt-4">
          <PaginationFirst />
          <PaginationPrev />

          <template v-for="(item, index) in items" :key="index">
            <PaginationListItem
              v-if="item.type === 'page'"
              :value="item.value"
              as-child
            >
              <Button
                class="w-10 h-10 p-0"
                :variant="item.value === page ? 'default' : 'outline'"
              >
                {{ item.value }}
              </Button>
            </PaginationListItem>
            <PaginationEllipsis
              v-else
              :index="index"
            />
          </template>

          <PaginationNext />
          <PaginationLast />
        </PaginationList>
      </Pagination>
  
        </div> 
      </div>
    </AppLayout> 
  </template>