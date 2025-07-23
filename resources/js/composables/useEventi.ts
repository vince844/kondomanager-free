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
    ...initialMeta
  });

  /**
   * Sets new eventi data and pagination meta
   */
  const setEventi = (newData: Evento[], newMeta: Partial<PaginationMeta>): void => {
    eventi.value = newData;
    meta.value = {
      ...meta.value,
      ...newMeta
    };
  };

  /**
   * Removes a segnalazione by ID and handles pagination
   */
  const removeEvento = (id: number): void => {
    eventi.value = eventi.value.filter(c => c.id !== id);
    meta.value.total = Math.max(0, meta.value.total - 1);
    meta.value.last_page = Math.max(1, Math.ceil(meta.value.total / meta.value.per_page));

    if (eventi.value.length === 0 && meta.value.current_page > 1) {
      router.visit(window.location.pathname, {
        data: { page: meta.value.current_page - 1 },
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