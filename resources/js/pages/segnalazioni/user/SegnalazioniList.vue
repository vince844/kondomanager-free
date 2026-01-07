<script setup lang="ts">

import { computed, reactive, watch } from 'vue';
import { watchDebounced, useTimeoutFn } from '@vueuse/core';
import { Head, router, Link, usePage } from '@inertiajs/vue3';
import { trans } from 'laravel-vue-i18n';
import AppLayout from '@/layouts/AppLayout.vue';
import Heading from '@/components/Heading.vue';
import SegnalazioniStats from '@/components/segnalazioni/SegnalazioniStats.vue';
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import { Button } from '@/components/ui/button';
import Alert from '@/components/Alert.vue';
import { Pencil, Trash2, Loader2, SearchX, Plus, Tags } from 'lucide-vue-next';
import { Empty, EmptyContent, EmptyDescription, EmptyHeader, EmptyMedia, EmptyTitle } from '@/components/ui/empty';
import { Pagination, PaginationContent, PaginationEllipsis, PaginationFirst, PaginationLast, PaginationItem, PaginationNext, PaginationPrevious } from '@/components/ui/pagination';
import { useSegnalazioni } from '@/composables/useSegnalazioni';
import { usePermission } from '@/composables/permissions';
import { Permission } from '@/enums/Permission';
import { getPriorityMeta } from '@/types/comunicazioni';
import type { PaginationMeta } from '@/types/pagination';
import type { Segnalazione } from '@/types/segnalazioni';
import type { Auth } from '@/types';
import type { Flash } from '@/types/flash';

/* -------------------------------------------------
   Props
------------------------------------------------- */
const props = defineProps<{
  segnalazioni: { data: Segnalazione[] } & PaginationMeta;
  stats: { bassa: number; media: number; alta: number; urgente: number };
  search?: string;
}>();

/* -------------------------------------------------
   Page & permission helpers
------------------------------------------------- */
const page = usePage<{ flash: { message?: Flash }; auth: Auth }>();
const { hasPermission, generateRoute } = usePermission();

/* -------------------------------------------------
   Constants (delays)
------------------------------------------------- */
const LOADING_DELAY_MS = 300;
const NO_RESULTS_DELAY_MS = 400;

/* -------------------------------------------------
   Reactive UI state (grouped)
------------------------------------------------- */
const ui = reactive({
  searchQuery: props.search ?? '',
  loadingCount: 0,
  hasSearched: false,
  showDelayedLoading: false,
  showNoResultsDelayed: false,
  isAlertOpen: false,
  segnalazioneID: null as number | null,
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
  segnalazioni,
  meta,
  setSegnalazioni,
  removeSegnalazione,
} = useSegnalazioni(props.segnalazioni.data, props.segnalazioni);

/* -------------------------------------------------
   Empty‑state derived values
------------------------------------------------- */
const showNoResults = computed(
  () =>
    !isLoading.value &&
    ui.hasSearched &&
    ui.searchQuery.trim() !== '' &&
    segnalazioni.value.length === 0 &&
    ui.showNoResultsDelayed,
);
const showNoTickets = computed(
  () =>
    !isLoading.value &&
    (!ui.searchQuery || ui.searchQuery.trim() === '') &&
    segnalazioni.value.length === 0,
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
  () => props.segnalazioni,
  (newData) => setSegnalazioni(newData.data, newData),
  { deep: true },
);

// Reset UI flags when the search box is cleared
watch(
  () => ui.searchQuery,
  (val) => {
    if (!val?.trim()) {
      ui.hasSearched = false;
      ui.showNoResultsDelayed = false;
    }
  },
);

// Debounced search → server request
watchDebounced(
  () => ui.searchQuery,
  async (newQuery) => {
    const isEmpty = !newQuery?.trim();
    ui.hasSearched = !isEmpty;
    ui.showNoResultsDelayed = false;
    ui.loadingCount++;
    startLoadingTimer();

    router.get(
      route(generateRoute('segnalazioni.index')),
      { page: 1, search: isEmpty ? undefined : newQuery },
      {
        preserveState: true,
        replace: true,
        only: ['segnalazioni'],
        onFinish: () => {
          ui.loadingCount = Math.max(0, ui.loadingCount - 1);
          ui.showDelayedLoading = false;
          if (!isEmpty && segnalazioni.value.length === 0) startNoResultsTimer();
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
  startLoadingTimer();

  router.get(
    route(generateRoute('segnalazioni.index')),
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

/* -------------------------------------------------
   Permission shortcuts – cached once
------------------------------------------------- */
const canCreate = hasPermission([Permission.CREATE_SEGNALAZIONI]);
const canEditAny = hasPermission([Permission.EDIT_SEGNALAZIONI]);
const canEditOwn = hasPermission([Permission.EDIT_OWN_SEGNALAZIONI]);
const canDeleteAny = hasPermission([Permission.DELETE_SEGNALAZIONI]);
const canDeleteOwn = hasPermission([Permission.DELETE_OWN_SEGNALAZIONI]);

function canEdit(s: Segnalazione): boolean {
  return (
    canEditAny ||
    (canEditOwn && s.created_by.user.id === auth.value.user.id)
  );
}
function canDelete(s: Segnalazione): boolean {
  return (
    canDeleteAny ||
    (canDeleteOwn && s.created_by.user.id === auth.value.user.id)
  );
}

/* -------------------------------------------------
   Dialog handling
------------------------------------------------- */
function openDeleteDialog(id: number): void {
  ui.segnalazioneID = id;
  ui.isAlertOpen = true;
}
async function confirmDelete(): Promise<void> {
  if (!ui.segnalazioneID) return;

  router.delete(
    route(generateRoute('segnalazioni.destroy'), {
      id: ui.segnalazioneID,
    }),
    {
      onSuccess: () => {
        removeSegnalazione(ui.segnalazioneID!);
        ui.isAlertOpen = false;
      },
    },
  );
}
</script>

<template>
  <Head :title="trans('segnalazioni.header.list_tickets_head')" />

  <AppLayout>
    <div class="px-4 py-6">
      <Heading
        :title="trans('segnalazioni.header.list_tickets_title')"
        :description="trans('segnalazioni.header.list_tickets_description')"
      />
      <SegnalazioniStats :stats="stats" />

      <div v-if="flashMessage" class="py-4">
        <Alert :message="flashMessage.message" :type="flashMessage.type" />
      </div>

      <div class="container mx-auto mt-4">
        <!-- Search bar + New ticket button -->
        <div class="mb-4 flex items-center gap-4">
          <input
            v-model="ui.searchQuery"
            type="text"
            :placeholder="trans('segnalazioni.table.filter_by_title')"
            class="max-w-md w-full px-4 py-2 border border-gray-300 rounded-md focus:ring-2 focus:ring-blue-400 focus:outline-none transition-all"
          />

          <Button
            v-if="canCreate"
            as="a"
            :href="route(generateRoute('segnalazioni.create'))"
            class="ml-auto gap-2"
          >
            <Plus class="w-4 h-4" />
            <span>{{ trans('segnalazioni.actions.new_ticket') }}</span>
          </Button>
        </div>

        <!-- List + loading overlay -->
        <div class="relative min-h-[400px]">
          <Transition name="fade">
            <div
              v-if="ui.showDelayedLoading && isLoading"
              class="absolute inset-0 bg-white/60 backdrop-blur-[1px] flex flex-col items-center justify-center z-10 gap-2"
            >
              <Loader2 class="w-8 h-8 animate-spin text-primary" />
              <span class="text-sm font-medium text-gray-500">{{
                trans('segnalazioni.dialogs.loading')
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
            <!-- Ticket cards -->
            <article
              v-for="segnalazione in segnalazioni"
              :key="segnalazione.id"
              class="mb-4 border p-4 rounded-lg bg-white hover:shadow-md transition-shadow"
            >
              <div class="flex justify-between items-start">
                <Link
                  :href="route(generateRoute('segnalazioni.show'), { id: segnalazione.id })"
                  class="flex items-center gap-3 font-semibold text-lg hover:text-primary transition-colors"
                >
                  <component
                    :is="getPriorityMeta(segnalazione.priority).icon"
                    class="w-5 h-5"
                    :class="getPriorityMeta(segnalazione.priority).colorClass"
                  />
                  {{ segnalazione.subject }}
                </Link>

                <div class="flex gap-1">
                  <Link
                    v-if="canEdit(segnalazione)"
                    :href="route(generateRoute('segnalazioni.edit'), { id: segnalazione.id })"
                    class="p-2 text-gray-400 hover:text-blue-600 transition-colors"
                    :title="trans('segnalazioni.actions.edit')"
                    aria-label="Edit ticket"
                  >
                    <Pencil class="w-4 h-4" />
                  </Link>

                  <button
                    v-if="canDelete(segnalazione)"
                    @click="openDeleteDialog(segnalazione.id)"
                    class="p-2 text-gray-400 hover:text-red-600 transition-colors"
                    :title="trans('segnalazioni.actions.delete')"
                    aria-label="Delete ticket"
                  >
                    <Trash2 class="w-4 h-4" />
                  </button>
                </div>
              </div>

              <p class="text-sm text-gray-600 mt-2 line-clamp-2 leading-relaxed">
                {{ segnalazione.description }}
              </p>

              <div class="mt-4 text-xs text-gray-400">
                {{
                  trans('segnalazioni.visibility.sent_on_by', {
                    date: segnalazione.created_at,
                    name: segnalazione.created_by.user.name,
                  })
                }}
              </div>
            </article>

            <!-- Empty / No‑result states -->
            <Empty
              v-if="showNoResults || showNoTickets"
              key="empty"
              class="border border-dashed bg-gray-50/50"
            >
              <EmptyHeader>
                <EmptyMedia variant="icon">
                  <SearchX v-if="showNoResults" class="text-muted-foreground" />
                  <Tags v-else class="text-muted-foreground" />
                </EmptyMedia>

                <EmptyTitle>
                  {{ showNoResults
                    ? trans('segnalazioni.dialogs.no_tickets_found')
                    : trans('segnalazioni.dialogs.no_tickets') }}
                </EmptyTitle>

                <EmptyDescription>
                  {{ showNoResults
                    ? trans('segnalazioni.dialogs.change_search_criteria')
                    : trans('segnalazioni.dialogs.no_tickets_created') }}
                </EmptyDescription>
              </EmptyHeader>

              <EmptyContent>
                <Button
                  v-if="showNoResults"
                  variant="outline"
                  size="sm"
                  @click="ui.searchQuery = ''"
                >
                  {{ trans('segnalazioni.dialogs.cancel_search') }}
                </Button>

                <Button
                  v-else-if="canCreate"
                  size="sm"
                  as="a"
                  :href="route(generateRoute('segnalazioni.create'))"
                >
                  {{ trans('segnalazioni.actions.new_ticket') }}
                </Button>
              </EmptyContent>
            </Empty>
          </TransitionGroup>
        </div>

        <!-- Pagination (only when there is data) -->
        <Pagination
          v-if="meta.total > 0"
          :total="meta.total"
          :items-per-page="meta.per_page"
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
      :title="trans('segnalazioni.dialogs.delete_ticket_title')"
      :description="trans('segnalazioni.dialogs.delete_ticket_description')"
      @confirm="confirmDelete"
    />
  </AppLayout>
</template>