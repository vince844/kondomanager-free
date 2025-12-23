<script setup lang="ts">

import { computed, reactive, watch } from 'vue';
import { watchDebounced, useTimeoutFn } from '@vueuse/core';
import { Head, router, Link, usePage } from '@inertiajs/vue3';
import { trans } from 'laravel-vue-i18n';
import AppLayout from '@/layouts/AppLayout.vue';
import Heading from '@/components/Heading.vue';
import ComunicazioniStats from '@/components/comunicazioni/ComunicazioniStats.vue';
import Alert from '@/components/Alert.vue';
import { Button } from '@/components/ui/button';
import { Empty, EmptyContent, EmptyDescription, EmptyHeader, EmptyMedia, EmptyTitle } from '@/components/ui/empty';
import { useComunicazioni } from '@/composables/useComunicazioni';
import { usePermission } from '@/composables/permissions';
import { Permission } from '@/enums/Permission';
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import { Pencil, Trash2, Loader2, SearchX, Plus, Megaphone } from 'lucide-vue-next';
import { Pagination, PaginationContent, PaginationEllipsis, PaginationFirst, PaginationLast, PaginationItem, PaginationNext, PaginationPrevious } from '@/components/ui/pagination';
import { getPriorityMeta } from '@/types/comunicazioni';
import type { PaginationMeta } from '@/types/pagination';
import type { Comunicazione } from '@/types/comunicazioni';
import type { Flash } from '@/types/flash';
import type { Auth } from '@/types';

/* -------------------------------------------------
   Props
------------------------------------------------- */
interface Props {
  comunicazioni: { data: Comunicazione[] } & PaginationMeta;
  stats: { bassa: number; media: number; alta: number; urgente: number };
  search?: string;
}
const props = defineProps<Props>();

/* -------------------------------------------------
   Page & Permission helpers
------------------------------------------------- */
const page = usePage<{ flash: { message?: Flash }; auth: Auth }>();
const { hasPermission, generateRoute } = usePermission();

/* -------------------------------------------------
   Constants
------------------------------------------------- */
const LOADING_DELAY_MS = 300;
const NO_RESULTS_DELAY_MS = 400;

/* -------------------------------------------------
   Reactive UI state (grouped)
------------------------------------------------- */
const ui = reactive({
  searchQuery: props.search ?? '',
  loadingCount: 0,
  errorState: null as string | null,
  hasSearched: false,
  showDelayedLoading: false,
  showNoResultsDelayed: false,
  isAlertOpen: false,
  comunicazioneID: null as number | null,
});

/* -------------------------------------------------
   Computed helpers
------------------------------------------------- */
const auth = computed(() => page.props.auth);
const flashMessage = computed(() => page.props.flash.message);
const isLoading = computed(() => ui.loadingCount > 0);

/* -------------------------------------------------
   Composable for the list
------------------------------------------------- */
const {
  comunicazioni,
  meta,
  setComunicazioni,
  removeComunicazione,
} = useComunicazioni(props.comunicazioni.data, props.comunicazioni);

/* -------------------------------------------------
   Empty‑state derived values
------------------------------------------------- */
const showNoResults = computed(
  () =>
    !isLoading.value &&
    ui.hasSearched &&
    ui.searchQuery.trim() !== '' &&
    comunicazioni.value.length === 0 &&
    ui.showNoResultsDelayed,
);

const showNoCommunications = computed(
  () =>
    !isLoading.value &&
    (!ui.searchQuery || ui.searchQuery.trim() === '') &&
    comunicazioni.value.length === 0,
);

/* -------------------------------------------------
   Timers (VueUse)
------------------------------------------------- */
const { start: startLoadingTimer } = useTimeoutFn(
  () => (ui.showDelayedLoading = true),
  LOADING_DELAY_MS,
);
const { start: startNoResultsTimer } = useTimeoutFn(
  () => (ui.showNoResultsDelayed = true),
  NO_RESULTS_DELAY_MS,
);

/* -------------------------------------------------
   Watchers
------------------------------------------------- */
// Sync composable when parent sends fresh data
watch(
  () => props.comunicazioni,
  (newData) => setComunicazioni(newData.data, newData),
  { deep: true },
);

// Reset UI flags when the search box is cleared
watch(
  () => ui.searchQuery,
  (val) => {
    if (!val?.trim()) {
      ui.hasSearched = false;
      ui.showNoResultsDelayed = false;
      ui.errorState = null;
    }
  },
);

// Debounced search → server request
watchDebounced(
  () => ui.searchQuery,
  async (newQuery) => {
    const isEmpty = !newQuery?.trim();
    ui.showNoResultsDelayed = false;
    ui.hasSearched = !isEmpty;
    ui.loadingCount++;
    startLoadingTimer();

    router.get(
      route(generateRoute('comunicazioni.index')),
      { page: 1, search: isEmpty ? undefined : newQuery },
      {
        preserveState: true,
        replace: true,
        only: ['comunicazioni'],
        onFinish: () => {
          ui.loadingCount = Math.max(0, ui.loadingCount - 1);
          ui.showDelayedLoading = false;
          if (!isEmpty && comunicazioni.value.length === 0) startNoResultsTimer();
        },
      },
    );
  },
  { debounce: 400 },
);

/* -------------------------------------------------
   Helper methods
------------------------------------------------- */
function handlePageChange(p: number): void {
  ui.loadingCount++;
  router.get(
    route(generateRoute('comunicazioni.index')),
    { page: p, search: ui.searchQuery?.trim() || undefined },
    {
      preserveScroll: true,
      preserveState: true,
      onFinish: () => {
        ui.loadingCount = Math.max(0, ui.loadingCount - 1);
        ui.showDelayedLoading = false;
      },
    },
  );
}

/* Permission shortcuts – cached once */
const canCreate = hasPermission([Permission.CREATE_COMUNICAZIONI]);
const canEditAny = hasPermission([Permission.EDIT_COMUNICAZIONI]);
const canEditOwn = hasPermission([Permission.EDIT_OWN_COMUNICAZIONI]);
const canDeleteAny = hasPermission([Permission.DELETE_COMUNICAZIONI]);
const canDeleteOwn = hasPermission([Permission.DELETE_OWN_COMUNICAZIONI]);

function canEdit(c: Comunicazione): boolean {
  return (
    canEditAny ||
    (canEditOwn && c.created_by.user.id === auth.value.user.id)
  );
}
function canDelete(c: Comunicazione): boolean {
  return (
    canDeleteAny ||
    (canDeleteOwn && c.created_by.user.id === auth.value.user.id)
  );
}

/* Dialog handling */
function openDeleteDialog(id: number): void {
  ui.comunicazioneID = id;
  ui.isAlertOpen = true;
}
async function confirmDelete(): Promise<void> {
  if (!ui.comunicazioneID) return;

  router.delete(
    route(generateRoute('comunicazioni.destroy'), {
      id: ui.comunicazioneID,
    }),
    {
      onSuccess: () => {
        removeComunicazione(ui.comunicazioneID!);
        ui.isAlertOpen = false;
      },
    },
  );
}
</script>

<template>
  <Head :title="trans('comunicazioni.header.list_communications_head')" />

  <AppLayout>
    <div class="px-4 py-6">
      <Heading
        :title="trans('comunicazioni.header.list_communications_title')"
        :description="trans('comunicazioni.header.list_communications_description')"
      />
      <ComunicazioniStats :stats="stats" />

      <div v-if="flashMessage" class="py-4">
        <Alert :message="flashMessage.message" :type="flashMessage.type" />
      </div>

      <div class="container mx-auto mt-4">
        <!-- Search bar & New‑communication button -->
        <div class="mb-4 flex items-center gap-4">
          <input
            v-model="ui.searchQuery"
            type="text"
            :placeholder="trans('comunicazioni.table.filter_by_title')"
            class="max-w-md w-full px-4 py-2 border border-gray-300 rounded-md focus:ring-2 focus:ring-blue-400 focus:outline-none transition-all"
          />

          <Button
            v-if="canCreate"
            as="a"
            :href="route(generateRoute('comunicazioni.create'))"
            class="ml-auto gap-2"
          >
            <Plus class="w-4 h-4" />
            <span>{{ trans('comunicazioni.actions.new_communication') }}</span>
          </Button>
        </div>

        <!-- List + loading overlay -->
        <div class="relative min-h-[400px]">
          <Transition name="fade">
            <div
              v-if="ui.showDelayedLoading && isLoading"
              class="absolute inset-0 bg-white/60 backdrop-blur-[1px] flex flex-col items-center justify-center z-10 gap-2 text-center"
            >
              <Loader2 class="w-8 h-8 animate-spin text-primary" />
              <span class="text-sm font-medium text-gray-500">{{
                trans('comunicazioni.dialogs.loading')
              }}</span>
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
            <!-- Communication cards -->
            <article
              v-for="comunicazione in comunicazioni"
              :key="comunicazione.id"
              class="mb-4 border p-4 rounded-lg bg-white hover:shadow-md transition-shadow"
            >
              <div class="flex items-center justify-between">
                <Link
                  :href="route(generateRoute('comunicazioni.show'), { id: comunicazione.id })"
                  class="inline-flex items-center gap-2 text-lg/7 font-semibold text-gray-900 hover:text-primary transition-colors"
                >
                  <component
                    :is="getPriorityMeta(comunicazione.priority).icon"
                    class="w-4 h-4"
                    :class="getPriorityMeta(comunicazione.priority).colorClass"
                  />
                  {{ comunicazione.subject }}
                </Link>

                <div class="flex items-center gap-1">
                  <Link
                    v-if="canEdit(comunicazione)"
                    :href="route(generateRoute('comunicazioni.edit'), { id: comunicazione.id })"
                    class="p-2 text-gray-400 hover:text-blue-600 transition-colors"
                    :title="trans('comunicazioni.actions.edit')"
                    aria-label="Edit communication"
                  >
                    <Pencil class="w-4 h-4" />
                  </Link>

                  <button
                    v-if="canDelete(comunicazione)"
                    @click="openDeleteDialog(comunicazione.id)"
                    class="p-2 text-gray-400 hover:text-red-600 transition-colors"
                    :title="trans('comunicazioni.actions.delete')"
                    aria-label="Delete communication"
                  >
                    <Trash2 class="w-4 h-4" />
                  </button>
                </div>
              </div>

              <div class="flex items-center gap-x-4 text-xs pt-1">
                <time :datetime="comunicazione.created_at" class="text-gray-500">
                  {{
                    trans('comunicazioni.visibility.sent_on_by', {
                      date: comunicazione.created_at,
                      name: comunicazione.created_by.user.name,
                    })
                  }}
                </time>
              </div>

              <p class="mt-4 line-clamp-3 text-sm/6 text-gray-600">
                {{ comunicazione.description }}
              </p>
            </article>

            <!-- Empty / No‑result states -->
            <Empty
              v-if="showNoResults || showNoCommunications"
              key="empty"
              class="border border-dashed bg-gray-50/50"
            >
              <EmptyHeader>
                <EmptyMedia variant="icon">
                  <SearchX v-if="showNoResults" class="text-muted-foreground" />
                  <Megaphone v-else class="text-muted-foreground" />
                </EmptyMedia>

                <EmptyTitle>
                  {{ showNoResults
                    ? trans('comunicazioni.dialogs.no_communications_found')
                    : trans('comunicazioni.dialogs.no_communications') }}
                </EmptyTitle>

                <EmptyDescription>
                  {{ showNoResults
                    ? trans('comunicazioni.dialogs.change_search_criteria')
                    : trans('comunicazioni.dialogs.no_communications_created') }}
                </EmptyDescription>
              </EmptyHeader>

              <EmptyContent>
                <Button
                  v-if="showNoResults"
                  variant="outline"
                  size="sm"
                  @click="ui.searchQuery = ''"
                >
                  {{ trans('comunicazioni.dialogs.cancel_search') }}
                </Button>

                <Button
                  v-else-if="canCreate"
                  size="sm"
                  as="a"
                  :href="route(generateRoute('comunicazioni.create'))"
                >
                  {{ trans('comunicazioni.actions.new_communication') }}
                </Button>
              </EmptyContent>
            </Empty>
          </TransitionGroup>
        </div>

        <!-- Pagination (only when there is data) -->
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
            <PaginationFirst :disabled="isLoading" />
            <PaginationPrevious :disabled="isLoading" />

            <template v-for="(item, idx) in items" :key="idx">
              <PaginationItem
                v-if="item.type === 'page'"
                :value="item.value"
                :is-active="item.value === meta.current_page"
              >
                {{ item.value }}
              </PaginationItem>
              <PaginationEllipsis v-else :index="idx" />
            </template>

            <PaginationNext :disabled="isLoading" />
            <PaginationLast :disabled="isLoading" />
          </PaginationContent>
        </Pagination>
      </div>
    </div>

    <!-- Delete confirmation dialog -->
    <ConfirmDialog
      v-model:modelValue="ui.isAlertOpen"
      :title="trans('comunicazioni.dialogs.delete_communication_title')"
      :description="trans('comunicazioni.dialogs.delete_communication_description')"
      @confirm="confirmDelete"
    />
  </AppLayout>
</template>