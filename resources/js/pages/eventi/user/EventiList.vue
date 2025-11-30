<script setup lang="ts">

import { ref, onMounted, computed, watch } from 'vue';
import { watchDebounced, useTimeoutFn } from '@vueuse/core';
import { Head, router, usePage } from "@inertiajs/vue3";
import AppLayout from "@/layouts/AppLayout.vue";
import Heading from "@/components/Heading.vue";
import Alert from "@/components/Alert.vue";
import { Button } from "@/components/ui/button";
import { RadioGroup, RadioGroupItem } from '@/components/ui/radio-group';
import { AlertDialog, AlertDialogAction, AlertDialogCancel, AlertDialogContent, AlertDialogDescription, AlertDialogFooter, AlertDialogHeader, AlertDialogTitle } from '@/components/ui/alert-dialog';
import { Pagination, PaginationContent, PaginationEllipsis, PaginationFirst, PaginationLast, PaginationItem, PaginationNext, PaginationPrevious } from '@/components/ui/pagination'
import { useEventi } from '@/composables/useEventi';
import { usePermission } from "@/composables/permissions";
import { Permission } from "@/enums/Permission";
import { CircleAlert, Pencil, Trash2, Loader2, SearchX, Plus, ChevronLeftIcon, ChevronRightIcon } from "lucide-vue-next";
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
  date_from?: string;
  date_to?: string;
  category_id?: number[];
}

const props = defineProps<Props>();
const page = usePage<{ flash: { message?: Flash }; auth: Auth }>();

// Permissions
const { hasPermission, generateRoute } = usePermission();
const auth = computed(() => page.props.auth);

// Eventi composable
const { eventi, meta, setEventi } = useEventi(props.eventi.data, {
  current_page: props.eventi.current_page,
  per_page: props.eventi.per_page,
  last_page: props.eventi.last_page,
  total: props.eventi.total,
});

// State
const searchQuery = ref(props.search ?? '');
const deleteMode = ref<'only_this' | 'this_and_future' | 'all'>('only_this');
const editMode = ref<'only_this' | 'all'>('all')
const eventoID = ref<number | null>(null);
const occurrenceDate = ref<string | null>(null);
const loadingCount = ref(0);
const errorState = ref<string | null>(null);
const hasSearched = ref(!!props.search); // Initialize based on props
const showDelayedLoading = ref(false);
const showNoResultsDelayed = ref(false);
const isInitialLoad = ref(true);
const isAlertOpen = ref(false);
const isDeleting = ref(false);
const isEditAlertOpen = ref(false)

// Computed
const flashMessage = computed(() => page.props.flash.message);
const isLoading = computed(() => loadingCount.value > 0);
const shouldShowLoading = computed(() => isLoading.value);

const filteredResults = computed(() => {
  return [...eventi.value].sort((a, b) => {
    const dateA = a.occurs ? new Date(a.occurs).getTime() : Number.MAX_SAFE_INTEGER;
    const dateB = b.occurs ? new Date(b.occurs).getTime() : Number.MAX_SAFE_INTEGER;
    return dateA - dateB || a.id - b.id || (a.occurrence_index ?? 0) - (b.occurrence_index ?? 0);
  });
});

const isRecurring = computed(() => {
  const event = eventi.value.find(e => e.id === eventoID.value);
  return !!event?.recurrence_id;
});

const showNoResults = computed(() => {
  return (
    !isLoading.value &&
    filteredResults.value.length === 0 &&
    (hasSearched.value || !isInitialLoad.value || showNoResultsDelayed.value)
  );
});

// Loading timers
const { start: startLoadingTimer, stop: stopLoadingTimer } = useTimeoutFn(
  () => (showDelayedLoading.value = true),
  LOADING_DELAY
);

const { start: startNoResultsTimer, stop: stopNoResultsTimer } = useTimeoutFn(
  () => (showNoResultsDelayed.value = true),
  NO_RESULTS_DELAY
);

// Lifecycle
onMounted(() => {
  updatePaginationData(props.eventi);
  isInitialLoad.value = false;
});

// Watchers
watch(() => props.eventi, updatePaginationData);

watch(searchQuery, (val) => {
  if (!val) resetSearchState();
});

// Debounced search
watchDebounced(
  searchQuery,
  async (newQuery) => {
    showNoResultsDelayed.value = false;
    await handleSearch(newQuery);
    if (newQuery && filteredResults.value.length === 0) {
      startNoResultsTimer();
    }
  },
  { debounce: SEARCH_DEBOUNCE, maxWait: SEARCH_MAX_WAIT }
);

// Utility functions
function updatePaginationData(newData: Props['eventi']) {
  setEventi(newData.data, {
    current_page: newData.current_page,
    per_page: newData.per_page,
    last_page: newData.last_page,
    total: newData.total,
  });
}

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

function resetSearchState() {
  hasSearched.value = false;
  showNoResultsDelayed.value = false;
  errorState.value = null;
  stopNoResultsTimer();
  updatePaginationData(props.eventi);
}

async function handleSearch(query: string) {
  hasSearched.value = true;
  incrementLoading();

  try {
    await router.get(
      route(generateRoute('eventi.index')),
      {
        page: 1,
        search: query || undefined,
        date_from: props.date_from || undefined,
        date_to: props.date_to || undefined,
        category_id: props.category_id || undefined,
      },
      {
        preserveState: false,
        preserveScroll: true,
        replace: true,
        onFinish: stopLoading,
      }
    );
  } catch (error) {
    errorState.value = "Errore durante la ricerca.";
    stopLoading();
  }
}

async function handlePageChange(page: number) {
  try {
    incrementLoading();
    await router.get(
      route(generateRoute('eventi.index')),
      {
        page,
        search: searchQuery.value || undefined,
        date_from: props.date_from || undefined,
        date_to: props.date_to || undefined,
        category_id: props.category_id || undefined,
      },
      {
        preserveState: false,
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

function getEventColor(occurs: string | Date): string {
  const eventDate = new Date(occurs);
  if (isNaN(eventDate.getTime())) return 'text-gray-500';
  const diffDays = Math.ceil((eventDate.getTime() - Date.now()) / (1000 * 60 * 60 * 24));

  if (diffDays <= 7) return 'text-red-500';
  if (diffDays <= 14) return 'text-yellow-500';
  return 'text-green-500';
}

function formatEventDate(date: string | Date) {
  const d = new Date(date);
  if (isNaN(d.getTime())) return { day: '--', month: '--' };
  return {
    day: d.getDate(),
    month: d.toLocaleString('it-IT', { month: 'short' }).toUpperCase()
  };
}

function handleDelete(evento: Evento) {
  eventoID.value = evento.id;
  occurrenceDate.value = evento.occurs
    ? new Date(evento.occurs).toISOString()
    : null;

  setTimeout(() => {
    isAlertOpen.value = true;
  }, 200);
}

function closeModal() {
  eventoID.value = null;
  isAlertOpen.value = false;
}

function deleteEvento() {
  if (eventoID.value === null || isDeleting.value) return;

  isDeleting.value = true;

  router.delete(route(generateRoute('eventi.destroy'), { evento: String(eventoID.value) }), {
    preserveScroll: false,
    preserveState: true,
    data: {
      mode: deleteMode.value,
      occurrence_date: occurrenceDate.value,
    },
    onSuccess: closeModal,
    onError: () => {
      console.error('Errore durante la cancellazione.');
    },
    onFinish: () => {
      isDeleting.value = false;
    }
  });
}

function handleEdit(evento: Evento) {
  eventoID.value = evento.id
  occurrenceDate.value = evento.occurs
    ? typeof evento.occurs === 'string'
      ? evento.occurs
      : evento.occurs.toISOString()
    : null
 /*  isDropdownOpen.value = false */
  
  if (evento.recurrence_id) {
    setTimeout(() => {
      isEditAlertOpen.value = true
    }, 200)
  } else {
    goToEdit(evento)
  }
}

async function editEvento() {
  if (eventoID.value === null) return
  
  try {
    const routeParams = { evento: eventoID.value }
    const queryParams = { 
      mode: editMode.value,
      occurrence_date: occurrenceDate.value
    }
    
    router.visit(
      route(generateRoute('eventi.edit'), routeParams),
      {
        data: queryParams,
        preserveScroll: true,
        onSuccess: () => {
          closeEditModal()
        },
        onError: (errors) => {
          console.error('Edit error:', errors)
        }
      }
    )
  } catch (error) {
    console.error('Navigation error:', error)
  }
}

function closeEditModal() {
  isEditAlertOpen.value = false
  eventoID.value = null
  occurrenceDate.value = null
 /*  isDropdownOpen.value = false */
}

function goToEdit(evento: Evento, e?: Event) {
  if (e) {
    e.preventDefault()
    e.stopPropagation()
  }
  
  router.visit(route(generateRoute('eventi.edit'), { evento: evento.id }))
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
        
        <div class="mb-4 flex flex-wrap items-center gap-4 justify-between">
          <!-- Search box -->
          <div class="flex-1 relative max-w-md">
            <input
              v-model="searchQuery"
              type="text"
              placeholder="Cerca per titolo..."
              class="w-full px-4 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-400"
            />
          </div>

          <!-- Legend + Crea button wrapper -->
          <div class="flex items-center gap-4 mt-2 lg:mt-0">
            <!-- Legend -->
            <div class="flex items-center gap-4 text-sm border border-gray-300 rounded-md px-4 py-1.5 bg-white shadow-sm">
              <div class="flex items-center gap-1">
                <span class="w-3 h-3 rounded-full bg-red-500"></span> ≤ 7 giorni
              </div>
              <div class="flex items-center gap-1">
                <span class="w-3 h-3 rounded-full bg-yellow-500"></span> ≤ 14 giorni
              </div>
              <div class="flex items-center gap-1">
                <span class="w-3 h-3 rounded-full bg-green-500"></span> oltre
              </div>
            </div>

            <!-- Crea button -->
            <Button
              v-if="hasPermission([Permission.CREATE_EVENTS])"
              as="a"
              :href="route(generateRoute('eventi.create'))"
              class="h-8 lg:flex items-center gap-2"
            >
              <Plus class="w-4 h-4" />
              <span>Crea</span>
            </Button>
          </div>
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
              :key="`${evento.id}-${evento.occurrence_index ?? 0}`"
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
                    <h3 class="font-semibold text-gray-900">{{ evento.title }}</h3>

                    <div class="flex items-center gap-2">
                      <button
                        v-if="hasPermission([Permission.EDIT_EVENTS]) || (hasPermission([Permission.EDIT_OWN_EVENTS]) && evento.created_by.user.id === auth.user.id)"
                        @click="handleEdit(evento)"
                        class="text-gray-700 hover:text-blue-600 transition-colors"
                        title="Modifica"
                      >
                        <Pencil class="w-4 h-4" />
                    </button>

                      <button
                        v-if="hasPermission([Permission.DELETE_EVENTS]) ||  (hasPermission([Permission.DELETE_OWN_EVENTS]) && evento.created_by.user.id === auth.user.id)"
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
          v-if="meta.total > 0"
          :items-per-page="meta.per_page"
          :total="meta.total"
          :default-page="meta.current_page"
          :sibling-count="1"
          show-edges
          @update:page="handlePageChange"
        >
          <PaginationContent v-slot="{ items }" class="mt-4">
            <!-- Prima pagina - doppia freccia sinistra -->
            <PaginationFirst :disabled="shouldShowLoading">
              <ChevronLeftIcon class="w-4 h-4" />
              <ChevronLeftIcon class="w-4 h-4 -ml-2" />
            </PaginationFirst>
            
            <!-- Pagina precedente - singola freccia sinistra -->
            <PaginationPrevious :disabled="shouldShowLoading">
              <ChevronLeftIcon class="w-4 h-4" />
            </PaginationPrevious>

            <template v-for="(item, index) in items" :key="index">
              <PaginationItem
                v-if="item.type === 'page'"
                :value="item.value"
                :is-active="item.value === meta.current_page"
                :disabled="shouldShowLoading"
              >
                {{ item.value }}
              </PaginationItem>
              
              <PaginationEllipsis v-else :index="index" />
            </template>

            <!-- Pagina successiva - singola freccia destra -->
            <PaginationNext :disabled="shouldShowLoading">
              <ChevronRightIcon class="w-4 h-4" />
            </PaginationNext>
            
            <!-- Ultima pagina - doppia freccia destra -->
            <PaginationLast :disabled="shouldShowLoading">
              <ChevronRightIcon class="w-4 h-4" />
              <ChevronRightIcon class="w-4 h-4 -ml-2" />
            </PaginationLast>
          </PaginationContent>
        </Pagination>
      </div>
    </div>

      <!-- Delete confirmation dialog -->
      <AlertDialog v-model:open="isAlertOpen">
        <AlertDialogContent>
          <AlertDialogHeader>
            <AlertDialogTitle>Sei sicuro di voler eliminare questo evento?</AlertDialogTitle>
            <AlertDialogDescription>
              <template v-if="isRecurring">
                Questo evento fa parte di una serie ricorrente. Scegli cosa vuoi eliminare:
                <RadioGroup v-model="deleteMode" class="mt-4 space-y-2">
                  <div class="flex items-center space-x-2">
                    <RadioGroupItem id="only_this" value="only_this" />
                    <label for="only_this" class="text-sm">Solo questo evento</label>
                  </div>
                  <div class="flex items-center space-x-2">
                    <RadioGroupItem id="this_and_future" value="this_and_future" />
                    <label for="this_and_future" class="text-sm">Questo e tutti i futuri</label>
                  </div>
                  <div class="flex items-center space-x-2">
                    <RadioGroupItem id="all" value="all" />
                    <label for="all" class="text-sm">Tutta la serie</label>
                  </div>
                </RadioGroup>
              </template>
              <template v-else>
                Questa azione non è reversibile. Eliminerà l'evento definitivamente.
              </template>
            </AlertDialogDescription>
          </AlertDialogHeader>
          <AlertDialogFooter>
            <AlertDialogCancel @click="closeModal">Annulla</AlertDialogCancel>
            <AlertDialogAction :disabled="isDeleting" @click="deleteEvento">
              <span v-if="isDeleting">Eliminazione...</span>
              <span v-else>Continua</span>
            </AlertDialogAction>
          </AlertDialogFooter>
        </AlertDialogContent>
      </AlertDialog>

      <AlertDialog v-model:open="isEditAlertOpen">
        <AlertDialogContent>
          <AlertDialogHeader>
            <AlertDialogTitle>Modifica evento ricorrente</AlertDialogTitle>
            <AlertDialogDescription>
              Questo evento fa parte di una serie ricorrente. Cosa vuoi modificare?
              <RadioGroup v-model="editMode" class="mt-4 space-y-2">
                <div class="flex items-center space-x-2">
                  <RadioGroupItem id="edit_only_this" value="only_this" />
                  <label for="edit_only_this" class="text-sm">Solo questo evento</label>
                </div>
                <div class="flex items-center space-x-2">
                  <RadioGroupItem id="edit_all" value="all" />
                  <label for="edit_all" class="text-sm">Tutta la serie</label>
                </div>
              </RadioGroup>
            </AlertDialogDescription>
          </AlertDialogHeader>
          <AlertDialogFooter>
            <AlertDialogCancel @click="closeEditModal">Annulla</AlertDialogCancel>
            <AlertDialogAction @click="editEvento">
              Continua
            </AlertDialogAction>
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