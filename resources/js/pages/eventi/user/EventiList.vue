In questo file dell'**Agenda/Eventi**, la situazione è più complessa perché gestisci eventi ricorrenti, ma i problemi di base rimangono gli stessi: la sincronizzazione delle props e la struttura della paginazione.

Inoltre, ho aggiunto i parametri `date_from`, `date_to` e `category_id` nei vari passaggi di navigazione, altrimenti se l'utente filtrasse per data e poi cambiasse pagina, perderebbe i filtri temporali.

Ecco il file completo e corretto:

```vue
<script setup lang="ts">
import { ref, computed, watch } from 'vue';
import { watchDebounced, useTimeoutFn } from '@vueuse/core';
import { Head, router, usePage } from "@inertiajs/vue3";
import AppLayout from "@/layouts/AppLayout.vue";
import Heading from "@/components/Heading.vue";
import Alert from "@/components/Alert.vue";
import { Button } from "@/components/ui/button";
import { RadioGroup, RadioGroupItem } from '@/components/ui/radio-group';
import { 
  Empty, 
  EmptyContent, 
  EmptyDescription, 
  EmptyHeader, 
  EmptyMedia,  
  EmptyTitle 
} from '@/components/ui/empty';
import { 
  AlertDialog, 
  AlertDialogAction, 
  AlertDialogCancel, 
  AlertDialogContent, 
  AlertDialogDescription, 
  AlertDialogFooter, 
  AlertDialogHeader, 
  AlertDialogTitle 
} from '@/components/ui/alert-dialog';
import { 
  Pagination, 
  PaginationContent, 
  PaginationEllipsis, 
  PaginationFirst, 
  PaginationLast, 
  PaginationItem, 
  PaginationNext, 
  PaginationPrevious 
} from '@/components/ui/pagination'
import { useEventi } from '@/composables/useEventi';
import { usePermission } from "@/composables/permissions";
import { Permission } from "@/enums/Permission";
import { 
  CircleAlert, Pencil, Trash2, Loader2, 
  SearchX, Plus, CalendarDays, ChevronLeftIcon, ChevronRightIcon 
} from "lucide-vue-next";
import type { PaginationMeta } from '@/types/pagination';
import type { Evento } from "@/types/eventi";
import type { Flash } from '@/types/flash';
import type { Auth } from '@/types';

interface Props {
  eventi: { data: Evento[] } & PaginationMeta;
  search?: string;
  date_from?: string;
  date_to?: string;
  category_id?: number[];
}

const props = defineProps<Props>();
const page = usePage<{ flash: { message?: Flash }; auth: Auth }>();
const { hasPermission, generateRoute } = usePermission();

// Composable initialization
const { eventi, meta, setEventi } = useEventi(props.eventi.data, props.eventi);

// State
const searchQuery = ref(props.search ?? '');
const deleteMode = ref<'only_this' | 'this_and_future' | 'all'>('only_this');
const editMode = ref<'only_this' | 'all'>('all')
const eventoID = ref<number | null>(null);
const occurrenceDate = ref<string | null>(null);
const loadingCount = ref(0);
const errorState = ref<string | null>(null);
const hasSearched = ref(false);
const showDelayedLoading = ref(false);
const showNoResultsDelayed = ref(false);
const isAlertOpen = ref(false);
const isDeleting = ref(false);
const isEditAlertOpen = ref(false)

const isLoading = computed(() => loadingCount.value > 0);

// Sincronizzazione fondamentale Props -> Local State
watch(() => props.eventi, (newData) => {
  setEventi(newData.data, newData);
}, { deep: true });

// Logica Empty States
const showNoResults = computed(() => 
  !isLoading.value && 
  hasSearched.value && 
  searchQuery.value.trim() !== '' && 
  eventi.value.length === 0 && 
  showNoResultsDelayed.value
);

const showNoEvents = computed(() => 
  !isLoading.value && 
  (!searchQuery.value || searchQuery.value.trim() === '') && 
  eventi.value.length === 0
);

// Ordinamento locale per sicurezza
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

const { start: startLoadingTimer } = useTimeoutFn(() => (showDelayedLoading.value = true), 300);
const { start: startNoResultsTimer } = useTimeoutFn(() => (showNoResultsDelayed.value = true), 400);

// Reset ricerca
watch(searchQuery, (newVal) => {
  if (!newVal || newVal.trim() === '') {
    hasSearched.value = false;
    showNoResultsDelayed.value = false;
    errorState.value = null;
  }
});

watchDebounced(searchQuery, async (newQuery) => {
    const isQueryEmpty = !newQuery || newQuery.trim() === '';
    hasSearched.value = !isQueryEmpty;
    showNoResultsDelayed.value = false;
    loadingCount.value++;
    startLoadingTimer();

    router.get(route(generateRoute('eventi.index')), 
      { 
        page: 1, 
        search: newQuery || undefined,
        date_from: props.date_from || undefined,
        date_to: props.date_to || undefined,
        category_id: props.category_id || undefined,
      },
      {
        preserveState: true,
        preserveScroll: true,
        replace: true,
        only: ['eventi'],
        onFinish: () => {
          loadingCount.value = Math.max(0, loadingCount.value - 1);
          showDelayedLoading.value = false;
          if (!isQueryEmpty && eventi.value.length === 0) startNoResultsTimer();
        }
      }
    );
}, { debounce: 400 });

async function handlePageChange(p: number) {
  loadingCount.value++;
  startLoadingTimer();
  router.get(route(generateRoute('eventi.index')), 
    { 
      page: p, 
      search: searchQuery.value || undefined,
      date_from: props.date_from || undefined,
      date_to: props.date_to || undefined,
      category_id: props.category_id || undefined,
    },
    {
      preserveState: true,
      preserveScroll: true,
      onFinish: () => {
        loadingCount.value = Math.max(0, loadingCount.value - 1);
        showDelayedLoading.value = false;
      },
      onError: () => (errorState.value = "Errore di caricamento.")
    }
  );
}

// Helpers grafici
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

// Actions
function handleDelete(evento: Evento) {
  eventoID.value = evento.id;
  occurrenceDate.value = evento.occurs ? new Date(evento.occurs).toISOString() : null;
  isAlertOpen.value = true;
}

function deleteEvento() {
  if (eventoID.value === null || isDeleting.value) return;
  isDeleting.value = true;
  router.delete(route(generateRoute('eventi.destroy'), { evento: String(eventoID.value) }), {
    data: { mode: deleteMode.value, occurrence_date: occurrenceDate.value },
    onSuccess: () => (isAlertOpen.value = false),
    onFinish: () => (isDeleting.value = false)
  });
}

function handleEdit(evento: Evento) {
  eventoID.value = evento.id
  occurrenceDate.value = evento.occurs ? (typeof evento.occurs === 'string' ? evento.occurs : evento.occurs.toISOString()) : null
  if (evento.recurrence_id) isEditAlertOpen.value = true
  else router.visit(route(generateRoute('eventi.edit'), { evento: evento.id }))
}

async function editEvento() {
  if (eventoID.value === null) return
  router.visit(route(generateRoute('eventi.edit'), { evento: eventoID.value }), {
    data: { mode: editMode.value, occurrence_date: occurrenceDate.value }
  })
}
</script>

<template>
  <Head title="Elenco scadenze agenda" />

  <AppLayout>
    <div class="px-4 py-6">
      <Heading title="Agenda Condominiale" description="Gestione delle scadenze e degli eventi programmati." />

      <div class="container mx-auto mt-4">
        <div class="mb-4 flex flex-wrap items-center gap-4 justify-between">
          <input
            v-model="searchQuery"
            type="text"
            placeholder="Cerca evento..."
            class="max-w-md w-full px-4 py-2 border border-gray-300 rounded-md focus:ring-2 focus:ring-blue-400 focus:outline-none transition-all"
          />

          <div class="flex items-center gap-4">
            <div class="hidden sm:flex items-center gap-4 text-xs border rounded-md px-3 py-1.5 bg-white shadow-sm">
              <div class="flex items-center gap-1"><span class="w-2 h-2 rounded-full bg-red-500"></span> ≤ 7gg</div>
              <div class="flex items-center gap-1"><span class="w-2 h-2 rounded-full bg-yellow-500"></span> ≤ 14gg</div>
              <div class="flex items-center gap-1"><span class="w-2 h-2 rounded-full bg-green-500"></span> oltre</div>
            </div>

            <Button v-if="hasPermission([Permission.CREATE_EVENTS])" as="a" :href="route(generateRoute('eventi.create'))" class="gap-2 h-9">
              <Plus class="w-4 h-4" /> Crea
            </Button>
          </div>
        </div>

        <div v-if="page.props.flash.message" class="mb-4">
          <Alert :message="page.props.flash.message.message" :type="page.props.flash.message.type" />
        </div>

        <div class="relative min-h-[400px]">
          <Transition name="fade">
            <div v-if="showDelayedLoading && isLoading" class="absolute inset-0 bg-white/60 backdrop-blur-[1px] flex flex-col items-center justify-center z-10 gap-2">
              <Loader2 class="w-8 h-8 animate-spin text-primary" />
              <span class="text-sm font-medium text-gray-500">Aggiornamento agenda...</span>
            </div>
          </Transition>

          <TransitionGroup 
            tag="div"
            enter-active-class="transition duration-300 ease-out"
            enter-from-class="opacity-0 translate-y-4"
            enter-to-class="opacity-100 translate-y-0"
            leave-active-class="transition duration-200 ease-in absolute w-full"
            leave-from-class="opacity-100 translate-y-0"
            leave-to-class="opacity-0 -translate-y-4"
            move-class="transition duration-300 ease-in-out"
          >
            <article
              v-for="evento in filteredResults"
              :key="`${evento.id}-${evento.occurrence_index ?? 0}`"
              class="mb-3 border p-4 rounded-lg bg-white hover:shadow-md transition-shadow flex gap-4"
            >
                <div :class="['flex flex-col items-center justify-center w-14 p-1 rounded bg-gray-50', getEventColor(evento.occurs)]">
                  <span class="text-2xl font-bold leading-none">{{ formatEventDate(evento.occurs).day }}</span>
                  <span class="text-[10px] font-bold uppercase tracking-tighter">{{ formatEventDate(evento.occurs).month }}</span>
                </div>

                <div class="flex-1 flex flex-col">
                  <div class="flex items-center justify-between">
                    <h3 class="font-semibold text-gray-900">{{ evento.title }}</h3>
                    <div class="flex items-center gap-1">
                      <button v-if="hasPermission([Permission.EDIT_EVENTS])" @click="handleEdit(evento)" class="p-1.5 text-gray-400 hover:text-blue-600 transition-colors">
                        <Pencil class="w-4 h-4" />
                      </button>
                      <button v-if="hasPermission([Permission.DELETE_EVENTS])" @click="handleDelete(evento)" class="p-1.5 text-gray-400 hover:text-red-600 transition-colors">
                        <Trash2 class="w-4 h-4" />
                      </button>
                    </div> 
                  </div>
                  <p class="text-sm text-gray-600 line-clamp-2 mt-1">{{ evento.description }}</p>
                </div>
            </article>

            <Empty v-if="showNoResults || showNoEvents" key="empty" class="border border-dashed bg-gray-50/50">
              <EmptyHeader>
                <EmptyMedia variant="icon">
                  <SearchX v-if="showNoResults" class="text-muted-foreground" />
                  <CalendarDays v-else class="text-muted-foreground" />
                </EmptyMedia>
                <EmptyTitle>{{ showNoResults ? 'Nessun evento trovato' : 'Agenda vuota' }}</EmptyTitle>
                <EmptyDescription>
                  {{ showNoResults ? 'Prova con termini diversi.' : 'Non ci sono eventi o scadenze programmate.' }}
                </EmptyDescription>
              </EmptyHeader>
              <EmptyContent>
                <Button v-if="showNoResults" variant="outline" size="sm" @click="searchQuery = ''">Annulla ricerca</Button>
                <Button v-else-if="hasPermission([Permission.CREATE_EVENTS])" size="sm" as="a" :href="route(generateRoute('eventi.create'))">Crea primo evento</Button>
              </EmptyContent>
            </Empty>
          </TransitionGroup>
        </div>

        <Pagination 
          v-if="meta.total > 0" 
          :total="meta.total" 
          :items-per-page="meta.per_page" 
          :default-page="meta.current_page" 
          :sibling-count="1"
          show-edges
          @update:page="handlePageChange"
        >
          <PaginationContent v-slot="{ items }" class="mt-8 justify-center">
            <PaginationFirst :disabled="isLoading" />
            <PaginationPrevious :disabled="isLoading" />

            <template v-for="(item, index) in items" :key="index">
              <PaginationItem v-if="item.type === 'page'" :value="item.value" :is-active="item.value === meta.current_page">
                {{ item.value }}
              </PaginationItem>
              <PaginationEllipsis v-else :index="index" />
            </template>

            <PaginationNext :disabled="isLoading" />
            <PaginationLast :disabled="isLoading" />
          </PaginationContent>
        </Pagination>
      </div>
    </div>

    <AlertDialog v-model:open="isAlertOpen">
      <AlertDialogContent>
        <AlertDialogHeader>
          <AlertDialogTitle>Elimina evento</AlertDialogTitle>
          <AlertDialogDescription>
            <template v-if="isRecurring">
              Scegli la modalità di eliminazione per la serie:
              <RadioGroup v-model="deleteMode" class="mt-4 space-y-2">
                <div class="flex items-center space-x-2">
                  <RadioGroupItem id="only_this" value="only_this" /><label for="only_this" class="text-sm">Solo questa occorrenza</label>
                </div>
                <div class="flex items-center space-x-2">
                  <RadioGroupItem id="this_and_future" value="this_and_future" /><label for="this_and_future" class="text-sm">Questa e le future</label>
                </div>
                <div class="flex items-center space-x-2">
                  <RadioGroupItem id="all" value="all" /><label for="all" class="text-sm">Tutta la serie</label>
                </div>
              </RadioGroup>
            </template>
            <span v-else>Questa azione è definitiva.</span>
          </AlertDialogDescription>
        </AlertDialogHeader>
        <AlertDialogFooter>
          <AlertDialogCancel>Annulla</AlertDialogCancel>
          <AlertDialogAction :disabled="isDeleting" @click="deleteEvento">
            {{ isDeleting ? 'Eliminazione...' : 'Conferma' }}
          </AlertDialogAction>
        </AlertDialogFooter>
      </AlertDialogContent>
    </AlertDialog>

    <AlertDialog v-model:open="isEditAlertOpen">
      <AlertDialogContent>
        <AlertDialogHeader>
          <AlertDialogTitle>Modifica evento ricorrente</AlertDialogTitle>
          <AlertDialogDescription>
            Cosa desideri modificare della serie?
            <RadioGroup v-model="editMode" class="mt-4 space-y-2">
              <div class="flex items-center space-x-2">
                <RadioGroupItem id="edit_only_this" value="only_this" /><label for="edit_only_this" class="text-sm">Solo questa occorrenza</label>
              </div>
              <div class="flex items-center space-x-2">
                <RadioGroupItem id="edit_all" value="all" /><label for="edit_all" class="text-sm">Tutta la serie (modifica radice)</label>
              </div>
            </RadioGroup>
          </AlertDialogDescription>
        </AlertDialogHeader>
        <AlertDialogFooter>
          <AlertDialogCancel @click="isEditAlertOpen = false">Annulla</AlertDialogCancel>
          <AlertDialogAction @click="editEvento">Continua</AlertDialogAction>
        </AlertDialogFooter>
      </AlertDialogContent>
    </AlertDialog>
  </AppLayout>
</template>

```