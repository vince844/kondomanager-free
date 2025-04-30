<script setup lang="ts">
import { ref, onMounted, watch, computed } from 'vue';
import { watchDebounced, useTimeoutFn } from '@vueuse/core';
import { Head, router, Link, usePage } from "@inertiajs/vue3";
import AppLayout from "@/layouts/AppLayout.vue";
import Heading from "@/components/Heading.vue";
import ComunicazioniStats from '@/components/comunicazioni/ComunicazioniStats.vue';
import Alert from "@/components/Alert.vue";
import { Button } from "@/components/ui/button";
import { usePermission } from "@/composables/permissions";
import type { Comunicazione } from "@/types/comunicazioni";
import type { Flash } from '@/types/flash';
import type { Auth } from '@/types';
import {
  CircleArrowDown, CircleArrowRight, CircleArrowUp, CircleAlert,
  Pencil, Trash2, Loader2, SearchX, BellPlus
} from "lucide-vue-next";
import {
  AlertDialog, AlertDialogAction, AlertDialogCancel, AlertDialogContent,
  AlertDialogDescription, AlertDialogFooter, AlertDialogHeader, AlertDialogTitle,
} from '@/components/ui/alert-dialog';
import {
  Pagination, PaginationEllipsis, PaginationFirst, PaginationLast,
  PaginationList, PaginationListItem, PaginationNext, PaginationPrev,
} from "@/components/ui/pagination";
import { getPriorityMeta } from "@/types/comunicazioni"; 

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

// Global state
const page = usePage<{ flash: { message?: Flash }, auth: Auth }>();
const { hasPermission, generateRoute } = usePermission();
const auth = computed(() => page.props.auth);
const flashMessage = computed(() => page.props.flash.message);
const comunicazioneID = ref('');
const isAlertOpen = ref(false);

// Search + loading
const searchQuery = ref(props.search ?? '');
const loadingCount = ref(0);
const isInitialLoad = ref(true);
const showDelayedLoading = ref(false);
const showNoResults = ref(false);
const errorState = ref<string | null>(null);

const { start: startLoadingTimer, stop: stopLoadingTimer } = useTimeoutFn(() => {
  showDelayedLoading.value = true;
}, 300);

const isLoading = computed(() => loadingCount.value > 0);
const shouldShowLoading = computed(() => !isInitialLoad.value && showDelayedLoading.value && isLoading.value);

// Comunicazioni display and search
const displayedComunicazioni = ref<Comunicazione[]>(props.comunicazioni.data);
const filteredResults = computed(() => {
  const query = searchQuery.value.toLowerCase();
  return !query
    ? displayedComunicazioni.value
    : displayedComunicazioni.value.filter(c =>
        c.subject.toLowerCase().includes(query) ||
        (c.description && c.description.toLowerCase().includes(query)));
});

watch(() => props.comunicazioni.data, (newData) => {
  displayedComunicazioni.value = newData;
  showNoResults.value = filteredResults.value.length === 0 && !isLoading.value;
  isInitialLoad.value = false;
}, { immediate: true });

// Page change logic
async function handlePageChange(page: number) {
  try {
    loadingCount.value++;
    errorState.value = null;
    startLoadingTimer();

    await router.get(route(generateRoute('comunicazioni.index')), {
      page, search: searchQuery.value || undefined
    }, {
      preserveState: true,
      preserveScroll: true,
      onCancel: handleCancel,
    });
  } catch (e) {
    console.error(e);
    errorState.value = "Errore di caricamento. Riprova.";
  } finally {
    stopLoading();
  }
}

function handleCancel() {
  stopLoadingTimer();
  showDelayedLoading.value = false;
  loadingCount.value--;
}

function stopLoading() {
  stopLoadingTimer();
  showDelayedLoading.value = false;
  loadingCount.value--;
}

// Debounced search
let lastAbortController: AbortController | null = null;
watchDebounced(
  searchQuery,
  (newQuery, _, onCleanup) => {
    lastAbortController?.abort();
    const controller = new AbortController();
    lastAbortController = controller;
    onCleanup(() => controller.abort());

    loadingCount.value++;
    errorState.value = null;
    showNoResults.value = false;
    startLoadingTimer();

    const timeoutTimer = setTimeout(() => {
      errorState.value = "La richiesta sta impiegando troppo tempo...";
    }, 5000);

    router.get(route(generateRoute('comunicazioni.index')), { search: newQuery, page: 1 }, {
      preserveState: true,
      preserveScroll: true,
      replace: true,
      only: ['comunicazioni'],
      onCancel: () => {
        clearTimeout(timeoutTimer);
        handleCancel();
      },
      onFinish: () => {
        clearTimeout(timeoutTimer);
        stopLoading();
        showNoResults.value = filteredResults.value.length === 0;
      },
      onError: () => {
        clearTimeout(timeoutTimer);
        stopLoading();
        errorState.value = "Errore durante la ricerca.";
      }
    });
  },
  { debounce: 400, maxWait: 1000 }
);

// Lazy load stats
const statsRef = ref();
const statsContainerRef = ref();
const hasLoaded = ref(false);

onMounted(() => {
  const observer = new IntersectionObserver(([entry]) => {
    if (entry.isIntersecting && !hasLoaded.value) {
      statsRef.value?.loadStats?.();
      hasLoaded.value = true;
      observer.disconnect();
    }
  });
  if (statsContainerRef.value) observer.observe(statsContainerRef.value);
});

// Router lifecycle
onMounted(() => {
  router.on('start', () => {
    loadingCount.value++;
    if (!isInitialLoad.value) startLoadingTimer();
  });
  router.on('finish', stopLoading);
  router.on('error', stopLoading);
});

// Delete dialog
function handleDelete(comunicazione: Comunicazione) {
  comunicazioneID.value = comunicazione.id;
  setTimeout(() => { isAlertOpen.value = true }, 200);
}
function closeModal() { isAlertOpen.value = false }
function deleteComunicazione() {
  router.delete(route(generateRoute('comunicazioni.destroy'), { id: comunicazioneID.value }), {
    preserveScroll: true,
    onSuccess: () => closeModal()
  });
}
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

      <div v-if="flashMessage" class="py-4"> 
        <Alert :message="flashMessage.message" :type="flashMessage.type" />
      </div>

      <!-- Search input with error display -->
      <div class="container mx-auto">

        <div class="mb-4 flex items-center gap-4">
          <div class="flex-1 relative max-w-md">
            <input
              v-model="searchQuery"
              type="text"
              placeholder="Cerca per titolo..."
              aria-label="Search communications"
              class="w-full px-4 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-400"
            />
          </div>

          <Button
            v-if="hasPermission(['Crea comunicazioni'])"
            as="a"
            :href="route(generateRoute('comunicazioni.create'))"
            class="h-8 lg:flex items-center gap-2 ml-auto"
          >
            <BellPlus class="w-4 h-4" />
            <span>Crea</span>
          </Button>
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

                  <div class="flex items-center justify-between">
                      <!-- Subject with priority icon -->
                      <Link
                        :href="route(generateRoute('comunicazioni.show'), { id: comunicazione.id })"
                        class="inline-flex items-center gap-2 text-lg/7 font-semibold text-gray-900 hover:text-blue-600 transition-colors"
                      >
                      <component
                        :is="getPriorityMeta(comunicazione.priority).icon"
                        class="w-4 h-4"
                        :class="getPriorityMeta(comunicazione.priority).colorClass"
                      />
                        {{ comunicazione.subject }}
                      </Link>

                    <!-- Action icons on the right -->
                    <div class="flex items-center gap-2">
                      <Link
                        v-if="hasPermission(['Modifica comunicazioni']) || (hasPermission(['Modifica proprie comunicazioni']) && comunicazione.created_by.user.id === auth.user.id)"
                        :href="route(generateRoute('comunicazioni.edit'), { id: comunicazione.id })"
                        class="text-gray-700 hover:text-blue-600 transition-colors"
                        title="Modifica"
                      >
                        <Pencil class="w-3 h-3" />
                      </Link>

                      <button
                        v-if="hasPermission(['Elimina comunicazioni']) || (hasPermission(['Elimina proprie comunicazioni']) && comunicazione.created_by.user.id === auth.user.id)"
                        @click="handleDelete(comunicazione)"
                        class="text-gray-700 hover:text-red-600 transition-colors"
                        title="Elimina"
                      >
                        <Trash2 class="w-3 h-3" />
                      </button>
                    </div>
                  </div>

                  <div class="flex items-center gap-x-4 text-xs pt-1">
                    <time
                      :datetime="comunicazione.created_at"
                      class="text-gray-500"
                    >
                      Inviata {{ comunicazione.created_at }} da
                      {{ comunicazione.created_by.user.name }}
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

    <!-- AlertDialog moved outside DropdownMenu -->
   <AlertDialog v-model:open="isAlertOpen" >
      <AlertDialogContent>
        <AlertDialogHeader>
          <AlertDialogTitle>Sei sicuro di volere eliminare questa comunicazione?</AlertDialogTitle>
          <AlertDialogDescription>
            Questa azione non è reversibile. Eliminerà la comunicazione e tutti i dati ad essa associati.
          </AlertDialogDescription>
        </AlertDialogHeader>
        <AlertDialogFooter>
          <AlertDialogCancel @click="isAlertOpen = false">Cancella</AlertDialogCancel>
          <AlertDialogAction  @click="deleteComunicazione()">Continua</AlertDialogAction>
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