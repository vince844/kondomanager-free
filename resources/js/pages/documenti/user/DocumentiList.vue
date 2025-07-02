<script setup lang="ts">

import { ref, onMounted, computed, watch } from 'vue';
import { watchDebounced, useTimeoutFn } from '@vueuse/core';
import { Head, usePage, router, Link } from "@inertiajs/vue3";
import AppLayout from "@/layouts/AppLayout.vue";
import Heading from "@/components/Heading.vue";
import DocumentiListCards from '@/components/documenti/DocumentiListCards.vue';
import { usePermission } from "@/composables/permissions";
import { useDocumenti } from '@/composables/useDocumenti';
import { Permission } from '@/enums/Permission';
import { Button } from "@/components/ui/button";
import Alert from "@/components/Alert.vue";
import { CircleAlert, Loader2, SearchX, Plus, List } from "lucide-vue-next";
import { Pagination, PaginationEllipsis, PaginationFirst, PaginationLast, PaginationList, PaginationListItem, PaginationNext, PaginationPrev } from "@/components/ui/pagination";
import type { Categoria } from '@/types/categorie';
import type { Documento } from '@/types/documenti';
import type { PaginationMeta } from '@/types/pagination';
import type { Flash } from '@/types/flash';
import type { Auth } from '@/types';

const LOADING_DELAY = 300;
const SEARCH_DEBOUNCE = 400;
const SEARCH_MAX_WAIT = 1000;

interface Props {
  documenti: {
    data: Documento[];
  } & PaginationMeta;
  categoria: Categoria;
  search?: string;
}

const props = defineProps<Props>();
const page = usePage<{ flash: { message?: Flash }, auth: Auth }>();
const { hasPermission, generateRoute } = usePermission();
const { documenti, meta, setDocumenti } = useDocumenti(
  props.documenti.data,
  {
    current_page: props.documenti.current_page,
    per_page: props.documenti.per_page,
    last_page: props.documenti.last_page,
    total: props.documenti.total,
  }
);

const flashMessage = computed(() => page.props.flash.message);
const searchQuery = ref(props.search ?? '');
const loadingCount = ref(0);
const isInitialLoad = ref(true);
const showDelayedLoading = ref(false);
const errorState = ref<string | null>(null);
const showNoResultsDelayed = ref(false);
const hasSearched = ref(false);
const justResetSearch = ref(false);

let latestSearchId = 0;

const { start: startLoadingTimer, stop: stopLoadingTimer } = useTimeoutFn(() => {
  showDelayedLoading.value = true;
}, LOADING_DELAY);

const { start: startNoResultsTimer, stop: stopNoResultsTimer } = useTimeoutFn(() => {
  showNoResultsDelayed.value = true;
}, 400);

const isLoading = computed(() => loadingCount.value > 0);
const shouldShowLoading = computed(() => isLoading.value);

const filteredResults = computed(() => {
  return documenti.value;
});

const showNoResults = computed(() => {
  return (
    !isLoading.value &&
    hasSearched.value &&
    searchQuery.value &&
    documenti.value.length === 0 &&
    showNoResultsDelayed.value
  );
});

const showNoDocuments = computed(() => {
  return (
    !isLoading.value &&
    !hasSearched.value &&
    documenti.value.length === 0 &&
    !justResetSearch.value
  );
});

function incrementLoading() {
  loadingCount.value++;
  errorState.value = null;
  startLoadingTimer();
}

function stopLoading() {
  stopLoadingTimer();
  showDelayedLoading.value = false;
  loadingCount.value = Math.max(0, loadingCount.value - 1);
}

function updatePaginationData(newDocumenti: Props['documenti']) {
  setDocumenti(newDocumenti.data, {
    current_page: newDocumenti.current_page,
    per_page: newDocumenti.per_page,
    last_page: newDocumenti.last_page,
    total: newDocumenti.total,
  });
}

onMounted(() => {
  updatePaginationData(props.documenti);
  isInitialLoad.value = false;
});

watch(
  () => props.documenti,
  (newDocumenti) => {
    updatePaginationData(newDocumenti);
  }
);

async function handlePageChange(page: number) {
  try {
    incrementLoading();
    await router.get(
      route(generateRoute('categorie-documenti.show'), { id: props.categoria.id }),
      {
        page,
        search: searchQuery.value || undefined,
      },
      {
        preserveState: true,
        preserveScroll: true,
        onFinish: stopLoading,
        onError: () => {
          errorState.value = "Errore di caricamento. Riprova.";
          stopLoading();
        }
      }
    );
  } catch (e) {
    errorState.value = "Errore di caricamento. Riprova.";
    stopLoading();
  }
}

// 🔐 Race condition guard
watchDebounced(
  searchQuery,
  async (newQuery) => {
    const currentSearchId = ++latestSearchId;

    showNoResultsDelayed.value = false;
    incrementLoading();
    hasSearched.value = true;

    try {
      await router.get(
        route(generateRoute('categorie-documenti.show'), { id: props.categoria.id }),
        {
          page: 1,
          search: newQuery || undefined,
        },
        {
          preserveState: true,
          preserveScroll: true,
          replace: true,
          only: ['documenti'],
          onFinish: () => {
            // Ignore outdated responses
            if (currentSearchId !== latestSearchId) return;

            stopLoading();
            if (newQuery && filteredResults.value.length === 0) {
              startNoResultsTimer();
            } else {
              showNoResultsDelayed.value = false;
            }
          }
        }
      );
    } catch (error) {
      if (currentSearchId === latestSearchId) {
        errorState.value = "Errore durante la ricerca.";
        stopLoading();
        showNoResultsDelayed.value = false;
      }
      console.error('Search error:', error);
    }
  },
  { debounce: SEARCH_DEBOUNCE, maxWait: SEARCH_MAX_WAIT }
);

watch(searchQuery, (val) => {
  if (!val) {
    justResetSearch.value = true;

    hasSearched.value = false;
    showNoResultsDelayed.value = false;
    errorState.value = null;
    stopNoResultsTimer();

    router.get(
      route(generateRoute('categorie-documenti.show'), { id: props.categoria.id }),
      {},
      {
        preserveState: true,
        preserveScroll: true,
        replace: true,
        only: ['documenti'],
        onFinish: () => {
          justResetSearch.value = false;
        }
      }
    );
  }
});
</script>

<template>
  <Head title="Elenco documenti archivio" />

  <AppLayout>
    <div class="px-4 py-6">
      <Heading
        :title="`Elenco documenti nella categoria ${categoria.name.toLowerCase()}`"
        description="Di seguito una lista delle categorie utilizzate per classificare i documenti nell'archivio del condominio."
      />

      <div class="container mx-auto mt-4">
        <!-- Search and action buttons -->
        <div class="mb-4 flex items-center gap-4">
          <div class="flex-1 relative max-w-md">
            <input
              v-model="searchQuery"
              type="text"
              placeholder="Cerca per titolo..."
              class="w-full px-4 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-400"
            />
          </div>

          <div class="flex items-center gap-2 ml-auto">
            <Button
              v-if="hasPermission([Permission.CREATE_ARCHIVE_DOCUMENTS])"
              as="a"
              :href="route(generateRoute('documenti.create'), { categoria: props.categoria.id })"
              class="h-8 lg:flex items-center gap-2"
            >
              <Plus class="w-4 h-4" />
              <span>Crea</span>
            </Button>

            <Link 
              as="button"
              v-if="hasPermission([Permission.VIEW_ARCHIVE_DOCUMENTS])"
              :href="route(generateRoute('categorie-documenti.index'))" 
              class="inline-flex items-center justify-center gap-2 rounded-md bg-primary text-sm font-medium text-white px-3 py-1.5 h-8 w-full lg:w-auto hover:bg-primary/90"
            >
              <List class="w-4 h-4" />
              <span>Categorie</span>
            </Link>
          </div>
        </div>

        <!-- Flash message -->
        <div v-if="flashMessage" class="py-4">
          <Alert :message="flashMessage.message" :type="flashMessage.type" />
        </div>

        <!-- Content area -->
        <div class="relative min-h-[200px]">
          <!-- Loading state -->
          <Transition name="fade">
            <div 
              v-if="shouldShowLoading"
              class="absolute inset-0 bg-white bg-opacity-80 flex flex-col items-center justify-center z-10 gap-2"
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
            >
              <CircleAlert class="w-5 h-5 text-red-500 mt-0.5 flex-shrink-0" />
              <div>
                <h3 class="font-medium text-red-800">Errore di caricamento</h3>
                <p class="text-red-700">{{ errorState }}</p>
                <Button 
                  variant="outline" 
                  size="sm" 
                  class="mt-2"
                  @click="handlePageChange(meta.current_page)"
                >
                  Riprova
                </Button>
              </div>
            </div>
          </Transition>

          <!-- Results -->
          <TransitionGroup name="fade-list" tag="div">
            <div v-if="filteredResults.length > 0" class="grid gap-4 md:grid-cols-1 lg:grid-cols-4">
              <DocumentiListCards
                v-for="documento in filteredResults"
                :key="documento.id"
                :documento="documento"
              />
            </div>

            <!-- Search with no results -->
            <div
              v-if="showNoResults"
              key="no-results"
              class="fade-list-item w-full border border-gray-300 rounded-lg p-6 text-center bg-white mt-6"
            >
              <SearchX class="mx-auto w-10 h-10 text-gray-400 mb-3" />
              <h3 class="text-lg font-medium text-gray-900">
                Nessun documento trovato
              </h3>
              <p class="mt-1 text-gray-500">
                Prova a modificare i criteri di ricerca
              </p>
              <Button
                v-if="searchQuery"
                size="sm"
                class="mt-3"
                @click="searchQuery = ''"
              >
                Cancella ricerca
              </Button>
            </div>

            <!-- No documents at all -->
            <div 
              v-if="showNoDocuments"
              key="no-documents"
              class="fade-list-item w-full border border-gray-300 rounded-lg p-6 text-center bg-white mt-6"
            >
              <SearchX class="mx-auto w-10 h-10 text-gray-400 mb-3" />
              <h3 class="text-lg font-medium text-gray-900">
                Nessun documento presente
              </h3>
              <p class="mt-1 text-gray-500">
                Non ci sono ancora documenti per questa categoria.
              </p>
            </div>

          </TransitionGroup>
        </div>

        <!-- Pagination -->
        <Pagination
          v-slot="{ page }"
          :items-per-page="meta.per_page"
          :total="meta.total"
          :default-page="meta.current_page"
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
