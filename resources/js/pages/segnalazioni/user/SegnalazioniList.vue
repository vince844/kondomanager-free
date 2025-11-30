<script setup lang="ts">

import { ref, onMounted, computed, watch } from 'vue';
import { watchDebounced, useTimeoutFn } from '@vueuse/core';
import { Head, router, Link, usePage } from "@inertiajs/vue3";
import AppLayout from "@/layouts/AppLayout.vue";
import Heading from "@/components/Heading.vue";
import SegnalazioniStats from '@/components/segnalazioni/SegnalazioniStats.vue';
import Alert from "@/components/Alert.vue";
import { Button } from "@/components/ui/button";
import { useSegnalazioni } from '@/composables/useSegnalazioni';
import { usePermission } from "@/composables/permissions";
import { Permission } from "@/enums/Permission";
import { CircleAlert, Pencil, Trash2, Loader2, SearchX, Plus, ChevronLeftIcon, ChevronRightIcon } from "lucide-vue-next";
import { AlertDialog, AlertDialogAction, AlertDialogCancel, AlertDialogContent, AlertDialogDescription, AlertDialogFooter, AlertDialogHeader, AlertDialogTitle } from '@/components/ui/alert-dialog';
import { Pagination, PaginationContent, PaginationEllipsis, PaginationFirst, PaginationLast, PaginationItem, PaginationNext, PaginationPrevious } from '@/components/ui/pagination'
import { getPriorityMeta } from "@/types/comunicazioni";
import type { PaginationMeta } from '@/types/pagination';
import type { Segnalazione } from "@/types/segnalazioni";
import type { Flash } from '@/types/flash';
import type { Auth } from '@/types';

// Constants
const LOADING_DELAY = 300;
const SEARCH_DEBOUNCE = 400;
const SEARCH_MAX_WAIT = 1000;
const NO_RESULTS_DELAY = 400;

// Props and page data
interface Props {
  segnalazioni: { data: Segnalazione[] } & PaginationMeta;
  stats: { bassa: number; media: number; alta: number; urgente: number };
  search?: string;
}
const props = defineProps<Props>();
const page = usePage<{ flash: { message?: Flash }; auth: Auth }>();

// Permissions and data setup
const { hasPermission, generateRoute } = usePermission();

const { segnalazioni, meta, setSegnalazioni, removeSegnalazione } = useSegnalazioni(
  props.segnalazioni.data,
  {
    current_page: props.segnalazioni.current_page,
    per_page: props.segnalazioni.per_page,
    last_page: props.segnalazioni.last_page,
    total: props.segnalazioni.total,
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
const segnalazioneID = ref<number | null>(null);

// Computed values
const auth = computed(() => page.props.auth);
const flashMessage = computed(() => page.props.flash.message);
const isLoading = computed(() => loadingCount.value > 0);
const shouldShowLoading = computed(() => isLoading.value);

const filteredResults = computed(() => segnalazioni.value);

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
  updatePaginationData(props.segnalazioni);
  isInitialLoad.value = false;
});

// Watchers
watch(() => props.segnalazioni, updatePaginationData);

watch(searchQuery, (val) => {
  if (!val) {
    hasSearched.value = false;
    showNoResultsDelayed.value = false;
    errorState.value = null;
    stopNoResultsTimer();
    updatePaginationData(props.segnalazioni);
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
        route(generateRoute('segnalazioni.index')),
        { page: 1, search: newQuery || undefined },
        {
          preserveState: true,
          preserveScroll: true,
          replace: true,
          only: ['segnalazioni'],
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

function updatePaginationData(newData: Props['segnalazioni']) {
  setSegnalazioni(newData.data, {
    current_page: newData.current_page,
    per_page: newData.per_page,
    last_page: newData.last_page,
    total: newData.total,
  });
}

async function handlePageChange(page: number) {
  try {
    incrementLoading();
    await router.get(
      route(generateRoute('segnalazioni.index')),
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

function handleDelete(segnalazione: Segnalazione) {
  segnalazioneID.value = segnalazione.id;
  isAlertOpen.value = true;
}

async function confirmDelete() {
  const id = segnalazioneID.value;
  if (!id) return;

  const segnalazione = segnalazioni.value.find(s => s.id === id);
  if (!segnalazione) {
    isAlertOpen.value = false;
    return;
  }

  try {
    await router.delete(route(generateRoute('segnalazioni.destroy'), { id }), {
      preserveScroll: true,
      preserveState: true,
      only: ['stats'],
      onSuccess: () => {
        removeSegnalazione(id);
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
  <Head title="Elenco segnalazioni bacheca" />

  <AppLayout>
    <div class="px-4 py-6">
      <Heading
        title="Elenco segnalazioni bacheca"
        description="Di seguito la tabella con l'elenco di tutte le segnalazioni in bacheca registrate"
      />

      <SegnalazioniStats :stats="stats" />

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
            v-if="hasPermission([Permission.CREATE_SEGNALAZIONI])"
            as="a"
            :href="route(generateRoute('segnalazioni.create'))"
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
              v-for="segnalazione in filteredResults"
              :key="segnalazione.id"
              class="mb-4 fade-list-item"
            >
              <div class="border p-4 rounded-md hover:shadow-sm transition-shadow">
                <div class="flex items-center justify-between">
                  <Link
                    :href="route(generateRoute('segnalazioni.show'), { id: segnalazione.id })"
                    class="inline-flex items-center gap-2 text-lg/7 font-semibold text-gray-900 hover:hover:text-muted-foreground transition-colors"
                  >
                    <component
                      :is="getPriorityMeta(segnalazione.priority).icon"
                      class="w-4 h-4"
                      :class="getPriorityMeta(segnalazione.priority).colorClass"
                    />
                    {{ segnalazione.subject }}
                  </Link>

                  <div class="flex items-center gap-2">
                    <Link
                      v-if="hasPermission([Permission.EDIT_SEGNALAZIONI]) || 
                           (hasPermission([Permission.EDIT_OWN_SEGNALAZIONI]) && 
                            segnalazione.created_by.user.id === auth.user.id)"
                      :href="route(generateRoute('segnalazioni.edit'), { id: segnalazione.id })"
                      class="text-gray-700 hover:text-blue-600 transition-colors"
                      title="Modifica"
                    >
                      <Pencil class="w-3 h-3" />
                    </Link>

                    <button
                      v-if="hasPermission([Permission.DELETE_SEGNALAZIONI]) || 
                           (hasPermission([Permission.DELETE_OWN_SEGNALAZIONI]) && 
                            segnalazione.created_by.user.id === auth.user.id)"
                      @click="handleDelete(segnalazione)"
                      class="text-gray-700 hover:text-red-600 transition-colors"
                      title="Elimina"
                    >
                      <Trash2 class="w-3 h-3" />
                    </button>
                  </div>
                </div>

                <div class="flex items-center gap-x-4 text-xs pt-1">
                  <time
                    :datetime="segnalazione.created_at"
                    class="text-gray-500"
                  >
                    Inviata {{ segnalazione.created_at }} da
                    {{ segnalazione.created_by.user.name }}
                  </time>
                </div>

                <p class="mt-5 line-clamp-3 text-sm/6 text-gray-600">
                  {{ segnalazione.description }}
                </p>
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
                Nessuna segnalazione trovata
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
          <AlertDialogTitle>Sei sicuro di volere eliminare questa segnalazione?</AlertDialogTitle>
          <AlertDialogDescription>
            Questa azione non è reversibile. Eliminerà la segnalazione e tutti i dati ad essa associati.
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