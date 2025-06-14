<script setup lang="ts">

import { ref, onMounted, computed } from 'vue';
import { watchDebounced, useTimeoutFn } from '@vueuse/core';
import { Head, router, Link, usePage } from "@inertiajs/vue3";
import AppLayout from "@/layouts/AppLayout.vue";
import Heading from "@/components/Heading.vue";
import SegnalazioniStats from '@/components/segnalazioni/SegnalazioniStats.vue';
import { useSegnalazioni } from '@/composables/useSegnalazioni';
import Alert from "@/components/Alert.vue";
import { Button } from "@/components/ui/button";
import { usePermission } from "@/composables/permissions";
import { CircleAlert, Pencil, Trash2, Loader2, SearchX, BellPlus } from "lucide-vue-next";
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
import type { Segnalazione } from "@/types/segnalazioni";
import type { Flash } from '@/types/flash';
import type { Auth } from '@/types';

const LOADING_DELAY = 300;
const SEARCH_DEBOUNCE = 400;
const SEARCH_MAX_WAIT = 1000;
const ERROR_TIMEOUT = 5000;

interface Props {
  segnalazioni: {
    data: Segnalazione[];
  } & PaginationMeta;
  stats: {
    bassa: number;
    media: number;
    alta: number;
    urgente: number;
  };
  search?: string;
}

const props = defineProps<Props>();
const page = usePage<{ flash: { message?: Flash }, auth: Auth }>();
const { hasPermission, generateRoute } = usePermission();
const { segnalazioni, meta, setSegnalazioni, removeSegnalazione } = useSegnalazioni();

// Component state
const auth = computed(() => page.props.auth);
const flashMessage = computed(() => page.props.flash.message);
const segnalazioneID = ref<string>('');
const isAlertOpen = ref(false);
const searchQuery = ref(props.search ?? '');
const lastAbortController = ref<AbortController | null>(null);
const loadingCount = ref(0);
const isInitialLoad = ref(true);
const showDelayedLoading = ref(false);
const errorState = ref<string | null>(null);

// Loading states
const { start: startLoadingTimer, stop: stopLoadingTimer } = useTimeoutFn(() => {
  showDelayedLoading.value = true;
}, LOADING_DELAY);

const isLoading = computed(() => loadingCount.value > 0);
const shouldShowLoading = computed(() => !isInitialLoad.value && showDelayedLoading.value && isLoading.value);
const showNoResults = computed(() => filteredResults.value.length === 0 && !isLoading.value);

// Initialize data
onMounted(() => {
  setSegnalazioni(props.segnalazioni.data, {
    current_page: props.segnalazioni.current_page,
    per_page: props.segnalazioni.per_page,
    last_page: props.segnalazioni.last_page,
    total: props.segnalazioni.total,
  });
  isInitialLoad.value = false;
});

// Filtered results
const filteredResults = computed(() => {
  if (!searchQuery.value) return segnalazioni.value;
  const query = searchQuery.value.toLowerCase();
  return segnalazioni.value.filter(c =>
    c.subject.toLowerCase().includes(query)
  );
});

// Loading helpers
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

// Pagination handler
async function handlePageChange(page: number) {
  try {
    incrementLoading();
    await router.get(route(generateRoute('segnalazioni.index')), {
      page, 
      search: searchQuery.value || undefined
    }, {
      preserveState: true,
      preserveScroll: true,
      onFinish: stopLoading,
      onError: () => errorState.value = "Errore di caricamento. Riprova."
    });
  } catch (e) {
    errorState.value = "Errore di caricamento. Riprova.";
    stopLoading();
  }
}

// Search handler
watchDebounced(
  searchQuery,
  async (newQuery, _, onCleanup) => {
    lastAbortController.value?.abort();
    const controller = new AbortController();
    lastAbortController.value = controller;
    onCleanup(() => controller.abort());

    incrementLoading();

    const timeoutTimer = setTimeout(() => {
      errorState.value = "La richiesta sta impiegando troppo tempo...";
    }, ERROR_TIMEOUT);

    try {
      await router.get(route(generateRoute('segnalazioni.index')), { 
        search: newQuery, 
        page: 1 
      }, {
        preserveState: true,
        preserveScroll: true,
        replace: true,
        only: ['segnalazioni'],
        onFinish: () => clearTimeout(timeoutTimer),
        onError: () => errorState.value = "Errore durante la ricerca."
      });
    } finally {
      clearTimeout(timeoutTimer);
      stopLoading();
    }
  },
  { debounce: SEARCH_DEBOUNCE, maxWait: SEARCH_MAX_WAIT }
);

// Delete handler
function handleDelete(segnalazione: Segnalazione) {
  segnalazioneID.value = segnalazione.id;
  isAlertOpen.value = true;
}

async function confirmDelete() {
  const id = segnalazioneID.value;
  const SegnalazioneToDelete = segnalazioni.value.find(c => c.id === id);

  if (!SegnalazioneToDelete) {
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
  } catch (error) {
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
            v-if="hasPermission(['Crea segnalazioni'])"
            as="a"
            :href="route(generateRoute('segnalazioni.create'))"
            class="h-8 lg:flex items-center gap-2 ml-auto"
          >
            <BellPlus class="w-4 h-4" />
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
                    class="inline-flex items-center gap-2 text-lg/7 font-semibold text-gray-900 hover:text-blue-600 transition-colors"
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
                      v-if="hasPermission(['Modifica segnalazioni']) || 
                           (hasPermission(['Modifica proprie segnalazioni']) && 
                            segnalazione.created_by.user.id === auth.user.id)"
                      :href="route(generateRoute('segnalazioni.edit'), { id: segnalazione.id })"
                      class="text-gray-700 hover:text-blue-600 transition-colors"
                      title="Modifica"
                    >
                      <Pencil class="w-3 h-3" />
                    </Link>

                    <button
                      v-if="hasPermission(['Elimina segnalazioni']) || 
                           (hasPermission(['Elimina proprie segnalazioni']) && 
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