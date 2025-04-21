<script setup lang="ts">

import { ref, onMounted, watch, computed } from 'vue';
import { watchDebounced, useTimeoutFn } from '@vueuse/core';
import { Head, router, Link } from "@inertiajs/vue3";
import AppLayout from "@/layouts/AppLayout.vue";
import Heading from "@/components/Heading.vue";
import type { Comunicazione } from "@/types/comunicazioni";
import ComunicazioniStats from '@/components/comunicazioni/ComunicazioniStats.vue';
import { Button } from "@/components/ui/button";
import {
  CircleArrowDown,
  CircleArrowRight,
  CircleArrowUp,
  CircleAlert,
  Loader2,
  SearchX
} from "lucide-vue-next";
import {
  Pagination,
  PaginationEllipsis,
  PaginationFirst,
  PaginationLast,
  PaginationList,
  PaginationListItem,
  PaginationNext,
  PaginationPrev,
} from "@/components/ui/pagination";

const props = defineProps<{
  comunicazioni: {
    data: Comunicazione[];
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
  },
  search?: string
}>();

// State management
const loadingCount = ref(0);
const searchQuery = ref(props.search ?? '');
const lastAbortController = ref<AbortController | null>(null);
const errorState = ref<string | null>(null);
const showNoResults = ref(false);
const isInitialLoad = ref(true); // Track initial load state
const showDelayedLoading = ref(false);

const statsRef = ref()
const statsContainerRef = ref()
const hasLoaded = ref(false)

const { start: startLoadingTimer, stop: stopLoadingTimer } = useTimeoutFn(
  () => showDelayedLoading.value = true,
  300 // Show loading after 300ms delay
);

const isLoading = computed(() => loadingCount.value > 0);
const shouldShowLoading = computed(() => !isInitialLoad.value && showDelayedLoading.value && isLoading.value);

// Immediately update displayed data
const displayedComunicazioni = ref<Comunicazione[]>(props.comunicazioni.data);

const priorityIcons = {
  bassa: CircleArrowDown,
  media: CircleArrowRight,
  alta: CircleArrowUp,
  urgente: CircleAlert,
};

// Client-side filtered results with memoization
const filteredResults = computed(() => {
  if (!searchQuery.value) return displayedComunicazioni.value;
  
  const query = searchQuery.value.toLowerCase();
  return displayedComunicazioni.value.filter(c => 
    c.subject.toLowerCase().includes(query) ||
    (c.description && c.description.toLowerCase().includes(query))
  );
});

// Enhanced page change handler with error handling
const handlePageChange = async (page: number) => {
  try {
    loadingCount.value++;
    errorState.value = null;
    startLoadingTimer();
    
    await router.get(
      route("user.comunicazioni.index"),
      {
        page,
        search: searchQuery.value || undefined,
      },
      {
        preserveState: true,
        preserveScroll: true,
        onCancel: () => {
          stopLoadingTimer();
          showDelayedLoading.value = false;
          loadingCount.value--;
        }
      }
    );
  } catch (error) {
    errorState.value = "Failed to load page. Please try again.";
    console.error("Page change error:", error);
  } finally {
    stopLoadingTimer();
    showDelayedLoading.value = false;
    loadingCount.value--;
  }
};

// Debounced server search with cancellation
watchDebounced(
  searchQuery,
  (newQuery, _, onCleanup) => {
    // Cancel previous request
    lastAbortController.value?.abort();
    
    const controller = new AbortController();
    lastAbortController.value = controller;
    onCleanup(() => controller.abort());
    
    loadingCount.value++;
    errorState.value = null;
    showNoResults.value = false;
    startLoadingTimer();
    
    const timeoutTimer = setTimeout(() => {
      errorState.value = "Request is taking longer than expected...";
    }, 5000);

    router.get(
      route('user.comunicazioni.index'),
      { search: newQuery, page: 1 },
      {
        preserveState: true,
        preserveScroll: true,
        replace: true,
        only: ['comunicazioni'],
        onCancel: () => {
          clearTimeout(timeoutTimer);
          stopLoadingTimer();
          showDelayedLoading.value = false;
          loadingCount.value--;
        },
        onFinish: () => {
          clearTimeout(timeoutTimer);
          stopLoadingTimer();
          showDelayedLoading.value = false;
          loadingCount.value--;
          showNoResults.value = filteredResults.value.length === 0;
        },
        onError: () => {
          clearTimeout(timeoutTimer);
          stopLoadingTimer();
          showDelayedLoading.value = false;
          loadingCount.value--;
          errorState.value = "Search failed. Please try again.";
        }
      }
    );
  },
  { debounce: 400, maxWait: 1000 }
);

// Update displayed data when props change
watch(
  () => props.comunicazioni.data,
  (newData) => {
    displayedComunicazioni.value = newData;
    showNoResults.value = filteredResults.value.length === 0 && !isLoading.value;
    // Mark initial load as complete after first data update
    isInitialLoad.value = false;
  },
  { immediate: true }
);

onMounted(() => {
  router.on('start', () => {
    loadingCount.value++;
    // Only start loading timer for subsequent loads
    if (!isInitialLoad.value) {
      startLoadingTimer();
    }
  });

  onMounted(() => {
  const observer = new IntersectionObserver(([entry]) => {
    if (entry.isIntersecting && !hasLoaded.value) {
      statsRef.value?.loadStats?.()
      hasLoaded.value = true
      observer.disconnect()
    }
  })

  if (statsContainerRef.value) {
    observer.observe(statsContainerRef.value)
  }
})
  
  router.on('finish', () => {
    loadingCount.value--;
    stopLoadingTimer();
    showDelayedLoading.value = false;
  });
  
  router.on('error', () => {
    loadingCount.value--;
    stopLoadingTimer();
    showDelayedLoading.value = false;
  });
});
</script>

<template>
  <Head title="Elenco comunicazioni bacheca" />

  <AppLayout>
    <div class="px-4 py-6">
      <Heading
        title="Elenco comunicazioni bacheca"
        description="Di seguito la tabella con l'elenco di tutte le comunicazioni in bacheca registrate"
      />

      <div ref="statsComunicazioniContainerRef" class="mb-4">
        <ComunicazioniStats ref="statsRef" />
      </div>

      <!-- Search input with error display -->
      <div class="container mx-auto">
        <div class="mb-4 max-w-md relative">
          <input
            v-model="searchQuery"
            type="text"
            placeholder="Cerca per titolo..."
            aria-label="Search communications"
            class="w-full px-4 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-400"
          />
        </div>

        <!-- Content area -->
        <div class="relative min-h-[300px]">
          <!-- Delayed loading overlay - only shows after initial load -->
          <Transition name="fade">
            <div 
              v-if="shouldShowLoading"
              class="absolute inset-0 bg-white bg-opacity-80 flex flex-col items-center justify-center z-10 gap-2"
              aria-live="polite"
              :aria-busy="isLoading"
            >
              <Loader2 class="w-8 h-8 animate-spin text-gray-500" />
              <p class="text-gray-600">Caricamento in corso...</p>
            </div>
          </Transition>

          <!-- Error state -->
          <Transition name="fade">
            <div 
              v-if="errorState && !shouldShowLoading"
              class="bg-red-50 border border-red-200 rounded-md p-4 mb-4 flex items-start gap-3"
              role="alert"
            >
              <CircleAlert class="w-5 h-5 text-red-500 mt-0.5 flex-shrink-0" />
              <div>
                <h3 class="font-medium text-red-800">Errore di caricamento</h3>
                <p class="text-red-700">{{ errorState }}</p>
                <Button 
                  variant="outline" 
                  size="sm" 
                  class="mt-2"
                  @click="handlePageChange(comunicazioni.current_page)"
                >
                  Riprova
                </Button>
              </div>
            </div>
          </Transition>

          <!-- Results -->
          <div>
            <TransitionGroup 
              name="fade-list" 
              tag="div"
              aria-live="polite"
            >
              <article
                v-for="comunicazione in filteredResults"
                :key="comunicazione.id"
                class="mb-4 fade-list-item"
              >
                <div class="border p-4 rounded-md hover:shadow-sm transition-shadow">
                  <Link
                    :href="route('user.comunicazioni.show', { id: comunicazione.id })"
                    prefetch
                    class="inline-flex items-center gap-2 text-lg/7 font-semibold text-gray-900 group-hover:text-blue-600 transition-colors"
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
                    <time
                      :datetime="comunicazione.created_at"
                      class="text-gray-500"
                    >
                      Inviata {{ comunicazione.created_at }} da
                      {{ comunicazione.created_by?.name }}
                    </time>
                  </div>

                  <p class="mt-5 line-clamp-3 text-sm/6 text-gray-600">
                    {{ comunicazione.description }}
                  </p>
                </div>
              </article>

              <!-- Enhanced empty state -->
              <div 
                v-if="showNoResults && !shouldShowLoading"
                key="no-results"
                class="text-center py-10 fade-list-item"
              >
                <SearchX class="mx-auto w-10 h-10 text-gray-400 mb-3" />
                <h3 class="text-lg font-medium text-gray-900">
                  Nessuna comunicazione trovata
                </h3>
                <p class="mt-1 text-gray-500">
                  Prova a modificare i criteri di ricerca
                </p>
                <Button
                  v-if="searchQuery"
                  variant="ghost"
                  size="sm"
                  class="mt-3"
                  @click="searchQuery = ''"
                >
                  Cancella ricerca
                </Button>
              </div>
            </TransitionGroup>
          </div>
        </div>

        <!-- Pagination -->
        <Pagination
          v-slot="{ page }"
          :items-per-page="comunicazioni.per_page"
          :total="comunicazioni.total"
          :default-page="comunicazioni.current_page"
          :sibling-count="1"
          show-edges
          @update:page="handlePageChange"
        >
          <PaginationList
            v-slot="{ items }"
            class="flex items-center justify-center gap-1 mt-4"
          >
            <PaginationFirst :disabled="shouldShowLoading" />
            <PaginationPrev :disabled="shouldShowLoading" />

            <template v-for="(item, index) in items" :key="index">
              <PaginationListItem
                v-if="item.type === 'page'"
                :value="item.value"
                as-child
                :disabled="shouldShowLoading"
              >
                <Button
                  class="w-10 h-10 p-0"
                  :variant="item.value === page ? 'default' : 'outline'"
                  :disabled="shouldShowLoading"
                >
                  {{ item.value }}
                </Button>
              </PaginationListItem>

              <PaginationEllipsis v-else :index="index" />
            </template>

            <PaginationNext :disabled="shouldShowLoading" />
            <PaginationLast :disabled="shouldShowLoading" />
          </PaginationList>
        </Pagination>
      </div>
    </div>
  </AppLayout>
</template>

<style>
.fade-enter-active,
.fade-leave-active {
  transition: opacity 0.2s ease;
}

.fade-enter-from,
.fade-leave-to {
  opacity: 0;
}

.fade-list-move,
.fade-list-enter-active {
  transition: all 0.3s ease;
}

.fade-list-leave-active {
  transition: all 0.2s ease;
  position: absolute;
  width: calc(100% - 2rem);
}

.fade-list-enter-from,
.fade-list-leave-to {
  opacity: 0;
  transform: translateY(10px);
}

.fade-list-leave-to {
  transform: translateY(-10px);
}
</style>