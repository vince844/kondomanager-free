<script setup lang="ts">
import { ref, computed, watch } from 'vue';
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
import { 
  Empty, 
  EmptyContent, 
  EmptyDescription, 
  EmptyHeader, 
  EmptyMedia,  
  EmptyTitle 
} from '@/components/ui/empty';
import { 
  CircleAlert, List, Loader2, SearchX, Plus, 
  FileStack, ChevronLeftIcon, ChevronRightIcon 
} from "lucide-vue-next";
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
import type { Categoria } from '@/types/categorie';
import type { Documento } from '@/types/documenti';
import type { PaginationMeta } from '@/types/pagination';
import type { Flash } from '@/types/flash';
import type { Auth } from '@/types';

interface Props {
  documenti: { data: Documento[] } & PaginationMeta;
  categoria: Categoria;
  search?: string;
}

const props = defineProps<Props>();
const page = usePage<{ flash: { message?: Flash }, auth: Auth }>();
const { hasPermission, generateRoute } = usePermission();

// Inizializzazione composable
const { documenti, meta, setDocumenti } = useDocumenti(props.documenti.data, props.documenti);

const searchQuery = ref(props.search ?? '');
const loadingCount = ref(0);
const showDelayedLoading = ref(false);
const errorState = ref<string | null>(null);
const showNoResultsDelayed = ref(false);
const hasSearched = ref(false);
let latestSearchId = 0;

const isLoading = computed(() => loadingCount.value > 0);

// Sincronizzazione fondamentale quando cambiano le props (Inertia reload)
watch(() => props.documenti, (newData) => {
  setDocumenti(newData.data, newData);
}, { deep: true });

// Logica per gli stati vuoti
const showNoResults = computed(() => 
  !isLoading.value && 
  hasSearched.value && 
  searchQuery.value.trim() !== '' && 
  documenti.value.length === 0 && 
  showNoResultsDelayed.value
);

const showNoDocuments = computed(() => 
  !isLoading.value && 
  (!searchQuery.value || searchQuery.value.trim() === '') && 
  documenti.value.length === 0
);

const { start: startLoadingTimer } = useTimeoutFn(() => (showDelayedLoading.value = true), 300);
const { start: startNoResultsTimer } = useTimeoutFn(() => (showNoResultsDelayed.value = true), 400);

// Reset stato ricerca
watch(searchQuery, (newVal) => {
  if (!newVal || newVal.trim() === '') {
    hasSearched.value = false;
    showNoResultsDelayed.value = false;
    errorState.value = null;
  }
});

async function handlePageChange(p: number) {
  loadingCount.value++;
  startLoadingTimer();
  router.get(
    route(generateRoute('categorie-documenti.show'), { id: props.categoria.id }),
    { page: p, search: searchQuery.value || undefined },
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

watchDebounced(searchQuery, async (newQuery) => {
    const currentSearchId = ++latestSearchId;
    const isQueryEmpty = !newQuery || newQuery.trim() === '';
    
    hasSearched.value = !isQueryEmpty;
    showNoResultsDelayed.value = false;
    loadingCount.value++;
    startLoadingTimer();

    router.get(
      route(generateRoute('categorie-documenti.show'), { id: props.categoria.id }),
      { page: 1, search: newQuery || undefined },
      {
        preserveState: true,
        preserveScroll: true,
        replace: true,
        only: ['documenti'],
        onFinish: () => {
          if (currentSearchId !== latestSearchId) return;
          loadingCount.value = Math.max(0, loadingCount.value - 1);
          showDelayedLoading.value = false;
          if (!isQueryEmpty && documenti.value.length === 0) startNoResultsTimer();
        }
      }
    );
}, { debounce: 400 });

</script>

<template>
  <Head :title="`Documenti: ${categoria.name}`" />

  <AppLayout>
    <div class="px-4 py-6">
      <Heading
        :title="`Documenti: ${categoria.name}`"
        description="Gestione dei documenti digitali relativi a questa categoria condominiale."
      />

      <div class="container mx-auto mt-4">
        <div class="mb-4 flex items-center gap-4">
          <input
            v-model="searchQuery"
            type="text"
            placeholder="Cerca per titolo..."
            class="max-w-md w-full px-4 py-2 border border-gray-300 rounded-md focus:ring-2 focus:ring-blue-400 focus:outline-none transition-all"
          />

          <div class="flex items-center gap-2 ml-auto">
            <Button
              v-if="hasPermission([Permission.CREATE_ARCHIVE_DOCUMENTS])"
              as="a"
              :href="route(generateRoute('documenti.create'), { categoria: props.categoria.id })"
              class="gap-2"
            >
              <Plus class="w-4 h-4" />
              <span>Crea</span>
            </Button>

            <Link 
              v-if="hasPermission([Permission.VIEW_ARCHIVE_DOCUMENTS])"
              :href="route(generateRoute('categorie-documenti.index'))" 
              class="inline-flex items-center justify-center gap-2 rounded-md bg-secondary text-secondary-foreground text-sm font-medium px-3 py-1.5 h-10 hover:bg-secondary/80 transition-colors"
            >
              <List class="w-4 h-4" />
              <span>Categorie</span>
            </Link>
          </div>
        </div>

        <div v-if="page.props.flash.message" class="py-2">
          <Alert :message="page.props.flash.message.message" :type="page.props.flash.message.type" />
        </div>

        <div class="relative min-h-[400px]">
          <Transition name="fade">
            <div v-if="showDelayedLoading && isLoading" class="absolute inset-0 bg-white/60 backdrop-blur-[1px] flex flex-col items-center justify-center z-10 gap-2">
              <Loader2 class="w-8 h-8 animate-spin text-primary" />
              <span class="text-sm font-medium text-gray-500">Aggiornamento...</span>
            </div>
          </Transition>

          <div v-if="errorState && !isLoading" class="bg-red-50 border border-red-200 rounded-md p-4 mb-4 flex items-start gap-3">
            <CircleAlert class="w-5 h-5 text-red-500 mt-0.5" />
            <div>
              <p class="text-red-700 font-medium">{{ errorState }}</p>
              <Button variant="link" size="sm" class="p-0 h-auto text-red-600" @click="handlePageChange(meta.current_page)">Riprova</Button>
            </div>
          </div>

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
            <div v-if="documenti.length > 0" key="grid" class="grid gap-4 md:grid-cols-2 lg:grid-cols-4">
              <DocumentiListCards
                v-for="documento in documenti"
                :key="documento.id"
                :documento="documento"
              />
            </div>

            <Empty v-else-if="showNoResults || showNoDocuments" key="empty" class="border border-dashed bg-gray-50/50">
              <EmptyHeader>
                <EmptyMedia variant="icon">
                  <SearchX v-if="showNoResults" class="text-muted-foreground" />
                  <FileStack v-else class="text-muted-foreground" />
                </EmptyMedia>
                <EmptyTitle>
                  {{ showNoResults ? 'Nessun risultato trovato' : 'Categoria vuota' }}
                </EmptyTitle>
                <EmptyDescription>
                  {{ showNoResults ? 'Prova a modificare i termini della tua ricerca.' : 'Non sono ancora stati caricati documenti in questa categoria.' }}
                </EmptyDescription>
              </EmptyHeader>
              <EmptyContent>
                <Button v-if="showNoResults" variant="outline" size="sm" @click="searchQuery = ''">
                  Annulla ricerca
                </Button>
                <Button v-else-if="hasPermission([Permission.CREATE_ARCHIVE_DOCUMENTS])" size="sm" as="a" :href="route(generateRoute('documenti.create'), { categoria: props.categoria.id })">
                  Carica documento
                </Button>
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
  </AppLayout>
</template>