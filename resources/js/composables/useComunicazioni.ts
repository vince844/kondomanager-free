import { ref } from 'vue';
import { router } from '@inertiajs/vue3';
import type { Comunicazione } from '@/types/comunicazioni';
import type { PaginationMeta } from '@/types/pagination';

const comunicazioni = ref<Comunicazione[]>([]);
const meta = ref<PaginationMeta>({
  current_page: 1,
  per_page: 10,
  last_page: 1,
  total: 0,
});

export function useComunicazioni() {
  /**
   * Sets new comunicazioni data and pagination meta
   */
  const setComunicazioni = (newData: Comunicazione[], newMeta: PaginationMeta): void => {
    comunicazioni.value = newData;
    meta.value = {
      ...meta.value,
      ...newMeta
    };
  };

  /**
   * Removes a comunicazione by ID and handles pagination
   */
  const removeComunicazione = (id: string): void => {
    // Filter out the deleted item
    comunicazioni.value = comunicazioni.value.filter(c => c.id !== id);
    
    // Update pagination meta
    meta.value.total = Math.max(0, meta.value.total - 1);
    meta.value.last_page = Math.max(1, Math.ceil(meta.value.total / meta.value.per_page));

    // If current page is empty and not first page, go to previous page
    if (comunicazioni.value.length === 0 && meta.value.current_page > 1) {
      router.visit(window.location.pathname, {
        data: { page: meta.value.current_page - 1 },
        preserveScroll: true,
        preserveState: true,
        only: ['comunicazioni', 'meta', 'flash'],
      });
    }
  };

  return {
    comunicazioni,
    meta,
    setComunicazioni,
    removeComunicazione
  };
}