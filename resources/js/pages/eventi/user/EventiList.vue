<script setup lang="ts">

import { ref, onMounted, computed, watch } from 'vue';
import { watchDebounced, useTimeoutFn } from '@vueuse/core';
import { Head, router, Link, usePage } from "@inertiajs/vue3";
import AppLayout from "@/layouts/AppLayout.vue";
import Heading from "@/components/Heading.vue";
import ComunicazioniStats from '@/components/comunicazioni/ComunicazioniStats.vue';
import Alert from "@/components/Alert.vue";
import { Button } from "@/components/ui/button";
import { useEventi } from '@/composables/useEventi';
import { usePermission } from "@/composables/permissions";
import { Permission }  from "@/enums/Permission";
import { CircleAlert, Pencil, Trash2, Loader2, SearchX, Plus } from "lucide-vue-next";
import {
  AlertDialog, AlertDialogAction, AlertDialogCancel, AlertDialogContent,
  AlertDialogDescription, AlertDialogFooter, AlertDialogHeader, AlertDialogTitle,
} from '@/components/ui/alert-dialog';
import {
  Pagination, PaginationEllipsis, PaginationFirst, PaginationLast,
  PaginationList, PaginationListItem, PaginationNext, PaginationPrev,
} from "@/components/ui/pagination";
import { getPriorityMeta } from "@/types/comunicazioni";
import type { PaginationMeta } from '@/types/pagination';
import type { Evento } from "@/types/eventi";
import type { Flash } from '@/types/flash';
import type { Auth } from '@/types';

// Constants
const LOADING_DELAY = 300;
const SEARCH_DEBOUNCE = 400;
const SEARCH_MAX_WAIT = 1000;
const NO_RESULTS_DELAY = 400;

// Props and page data
interface Props {
  eventi: { data: Evento[] } & PaginationMeta;
  search?: string;
}
const props = defineProps<Props>();
const page = usePage<{ flash: { message?: Flash }; auth: Auth }>();

// Permissions and data setup
const { hasPermission, generateRoute } = usePermission();

const { eventi, meta, setEventi, removeEvento } = useEventi(
  props.eventi.data,
  {
    current_page: props.eventi.current_page,
    per_page: props.eventi.per_page,
    last_page: props.eventi.last_page,
    total: props.eventi.total,
  }
);

// Reactive state
const searchQuery = ref(props.search ?? '');
const loadingCount = ref(0);
const isInitialLoad = ref(true);
const errorState = ref<string | null>(null);
const hasSearched = ref(false);
const showDelayedLoading = ref(false);
const showNoResultsDelayed = ref(false);
const isAlertOpen = ref(false);
const eventoID = ref<number | null>(null);

// Computed values
const auth = computed(() => page.props.auth);
const flashMessage = computed(() => page.props.flash.message);
const isLoading = computed(() => loadingCount.value > 0);
const shouldShowLoading = computed(() => isLoading.value);

const filteredResults = computed(() => eventi.value);

const showNoResults = computed(() =>
  !isLoading.value &&
  hasSearched.value &&
  searchQuery.value &&
  filteredResults.value.length === 0 &&
  showNoResultsDelayed.value
);

// Timeout handlers
const { start: startLoadingTimer, stop: stopLoadingTimer } = useTimeoutFn(
  () => (showDelayedLoading.value = true),
  LOADING_DELAY
);
const { start: startNoResultsTimer, stop: stopNoResultsTimer } = useTimeoutFn(
  () => (showNoResultsDelayed.value = true),
  NO_RESULTS_DELAY
);

// Init
onMounted(() => {
  updatePaginationData(props.eventi);
  isInitialLoad.value = false;
});

// Watchers
watch(() => props.eventi, updatePaginationData);

watch(searchQuery, (val) => {
  if (!val) {
    hasSearched.value = false;
    showNoResultsDelayed.value = false;
    errorState.value = null;
    stopNoResultsTimer();
    updatePaginationData(props.eventi);
  }
});

watchDebounced(
  searchQuery,
  async (newQuery) => {
    showNoResultsDelayed.value = false;
    hasSearched.value = true;
    incrementLoading();

    try {
      await router.get(
        route(generateRoute('eventi.index')),
        { page: 1, search: newQuery || undefined },
        {
          preserveState: true,
          preserveScroll: true,
          replace: true,
          only: ['eventi'],
          onFinish: () => {
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
      errorState.value = "Errore durante la ricerca.";
      stopLoading();
      console.error('Search error:', error);
    }
  },
  { debounce: SEARCH_DEBOUNCE, maxWait: SEARCH_MAX_WAIT }
);

// Methods
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

function updatePaginationData(newData: Props['eventi']) {
  setEventi(newData.data, {
    current_page: newData.current_page,
    per_page: newData.per_page,
    last_page: newData.last_page,
    total: newData.total,
  });
}

function getEventColor(occurs: string | Date): string {
  const eventDate = typeof occurs === 'string' ? new Date(occurs) : occurs;
  const now = new Date();
  const diffDays = Math.ceil((eventDate.getTime() - now.getTime()) / (1000 * 60 * 60 * 24));

  if (diffDays <= 7) return 'text-red-500';
  if (diffDays <= 14) return 'text-yellow-500';
  return 'text-green-500';
}


function formatEventDate(date: string | Date) {
  const d = typeof date === 'string' ? new Date(date) : date;

  return {
    day: d.getDate(),
    month: d.toLocaleString('it-IT', { month: 'short' }).toUpperCase() // 'lug', 'ago', etc.
  };
}

async function handlePageChange(page: number) {
  try {
    incrementLoading();
    await router.get(
      route(generateRoute('eventi.index')),
      { page, search: searchQuery.value || undefined },
      {
        preserveState: true,
        preserveScroll: true,
        onFinish: stopLoading,
        onError: () => {
          errorState.value = "Errore di caricamento. Riprova.";
          stopLoading();
        },
      }
    );
  } catch {
    errorState.value = "Errore di caricamento. Riprova.";
    stopLoading();
  }
}

function handleDelete(evento: Evento) {
  eventoID.value = evento.id;
  isAlertOpen.value = true;
}

async function confirmDelete() {
  const id = eventoID.value;
  if (!id) return;

  const evento = eventi.value.find(s => s.id === id);
  if (!evento) {
    isAlertOpen.value = false;
    return;
  }

  try {
    await router.delete(route(generateRoute('eventi.destroy'), { id }), {
      preserveScroll: true,
      preserveState: true,
      only: ['stats'],
      onSuccess: () => {
        removeEvento(id);
        isAlertOpen.value = false;
      },
      onError: () => {
        errorState.value = "Errore durante l'eliminazione. Riprova.";
      }
    });
  } catch {
    errorState.value = "Errore durante l'eliminazione. Riprova.";
  } finally {
    isAlertOpen.value = false;
  }
}
</script>

<template>
  <Head title="Elenco scadenze agenda" />

  <AppLayout>
    <div class="px-4 py-6">
      <Heading
        title="Elenco scadenza in agenda"
        description="Di seguito la tabella con l'elenco di tutte le scadenza nell'agenda del condominio"
      />

      <div v-if="flashMessage" class="py-4">
        <Alert :message="flashMessage.message" :type="flashMessage.type" />
      </div>

      <div class="container mx-auto mt-4">
        <div class="mb-4 flex items-center gap-4">
          <div class="flex-1 relative max-w-md">
            <input
              v-model="searchQuery"
              type="text"
              placeholder="Cerca per titolo..."
              class="w-full px-4 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-400"
            />
          </div>

          <Button
            v-if="hasPermission([Permission.CREATE_EVENTS])"
            as="a"
            :href="route(generateRoute('eventi.create'))"
            class="h-8 lg:flex items-center gap-2 ml-auto"
          >
            <Plus class="w-4 h-4" />
            <span>Crea</span>
          </Button>
        </div>

        <div class="relative min-h-[300px]">
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
                      
          <article
            v-for="evento in filteredResults"
            :key="evento.id"
            class="mb-4 fade-list-item"
          >
          
          <div class="border p-4 rounded-md hover:shadow-sm transition-shadow flex gap-4"> 

              <!-- Date block -->
              <div :class="[
                'flex flex-col items-center justify-center w-16 select-none p-1',
                getEventColor(evento.occurs)
              ]">
                <span class="text-3xl font-extrabold leading-none">
                  {{ formatEventDate(evento.occurs).day }}
                </span>
                <span class="text-sm uppercase">
                  {{ formatEventDate(evento.occurs).month }}
                </span>
              </div>

              <!-- Event content -->
              <div class="flex-1 flex flex-col">
                <div class="flex items-center justify-between">
                  <h3 class="font-semibold text-lg text-gray-900">{{ evento.title }}</h3>

                  <div class="flex items-center gap-2">
                    <Link
                      v-if="hasPermission([Permission.EDIT_EVENTS]) || 
                          (hasPermission([Permission.EDIT_OWN_EVENTS]) && 
                            evento.created_by.user.id === auth.user.id)"
                      :href="route(generateRoute('eventi.edit'), { id: evento.id })"
                      class="text-gray-700 hover:text-blue-600 transition-colors"
                      title="Modifica"
                    >
                      <Pencil class="w-4 h-4" />
                    </Link>

                    <button
                      v-if="hasPermission([Permission.DELETE_EVENTS]) || 
                          (hasPermission([Permission.DELETE_OWN_EVENTS]) && 
                            evento.created_by.user.id === auth.user.id)"
                      @click="handleDelete(evento)"
                      class="text-gray-700 hover:text-red-600 transition-colors"
                      title="Elimina"
                    >
                      <Trash2 class="w-4 h-4" />
                    </button>
                  </div> 
                </div>

                <p class="mt-2 line-clamp-3 text-sm text-gray-600">
                  {{ evento.description }}
                </p>
              </div>
            </div>
          </article>

            <!-- Empty state -->
            <div 
              v-if="showNoResults"
              key="no-results"
              class="text-center py-10 fade-list-item"
            >
              <SearchX class="mx-auto w-10 h-10 text-gray-400 mb-3" />
              <h3 class="text-lg font-medium text-gray-900">
                Nessun evento trovato
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

    <!-- Delete confirmation dialog -->
    <AlertDialog v-model:open="isAlertOpen">
      <AlertDialogContent>
        <AlertDialogHeader>
          <AlertDialogTitle>Sei sicuro di volere eliminare questa comunicazione?</AlertDialogTitle>
          <AlertDialogDescription>
            Questa azione non è reversibile. Eliminerà la comunicazione e tutti i dati ad essa associati.
          </AlertDialogDescription>
        </AlertDialogHeader>
        <AlertDialogFooter>
          <AlertDialogCancel>Annulla</AlertDialogCancel>
          <AlertDialogAction @click="confirmDelete">Conferma</AlertDialogAction>
        </AlertDialogFooter>
      </AlertDialogContent>
    </AlertDialog>
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