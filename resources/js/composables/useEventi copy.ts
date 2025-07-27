import { ref } from 'vue';
import { router } from '@inertiajs/vue3';
import type { Evento } from '@/types/eventi';
import type { PaginationMeta } from '@/types/pagination';

export function useEventi(initialData: Evento[] = [], initialMeta: Partial<PaginationMeta> = {}) {
  const eventi = ref<Evento[]>(initialData);
  const meta = ref<PaginationMeta>({
    current_page: 1,
    per_page: 10,
    last_page: 1,
    total: 0,
    search: '',
    ...initialMeta
  });

  /**
   * Sets new eventi data and pagination meta
   */
  const setEventi = (newData: Evento[], newMeta: Partial<PaginationMeta>): void => {
    eventi.value = [...newData].sort((a, b) => {
      const dateA = a.occurs ? new Date(a.occurs).getTime() : Number.MAX_SAFE_INTEGER;
      const dateB = b.occurs ? new Date(b.occurs).getTime() : Number.MAX_SAFE_INTEGER;
      return dateA - dateB || a.id - b.id || (a.occurrence_index ?? 0) - (b.occurrence_index ?? 0);
    }).map(event => {
      if (!event.occurs) {
        console.warn(`Invalid occurs value for event ID ${event.id}:`, event.occurs);
      }
      return event;
    });
    meta.value = {
      ...meta.value,
      ...newMeta
    };
    console.log('setEventi:', eventi.value.map(e => ({
      id: e.id,
      occurs: e.occurs,
      occurrence_index: e.occurrence_index ?? 0,
    })));
  };

  /**
   * Removes a evento by ID and handles pagination
   */
  const removeEvento = (id: number): void => {
    eventi.value = eventi.value.filter(c => c.id !== id).sort((a, b) => {
      const dateA = a.occurs ? new Date(a.occurs).getTime() : Number.MAX_SAFE_INTEGER;
      const dateB = b.occurs ? new Date(b.occurs).getTime() : Number.MAX_SAFE_INTEGER;
      return dateA - dateB || a.id - b.id || (a.occurrence_index ?? 0) - (b.occurrence_index ?? 0);
    });
    meta.value.total = Math.max(0, meta.value.total - 1);
    meta.value.last_page = Math.max(1, Math.ceil(meta.value.total / meta.value.per_page));

    if (eventi.value.length === 0 && meta.value.current_page > 1) {
      router.visit(window.location.pathname, {
        data: {
          page: meta.value.current_page - 1,
          search: meta.value.search,
          date_from: meta.value.date_from,
          date_to: meta.value.date_to,
          category_id: meta.value.category_id,
        },
        preserveScroll: true,
        preserveState: true,
        only: ['eventi', 'meta', 'flash'],
      });
    }
  };

  return {
    eventi,
    meta,
    setEventi,
    removeEvento
  };
}