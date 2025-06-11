import { ref } from 'vue';
import { router } from '@inertiajs/vue3';
import type { Documento } from '@/types/documenti';
import type { PaginationMeta } from '@/types/pagination';

const documenti = ref<Documento[]>([]);
const meta = ref<PaginationMeta>({
  current_page: 1,
  per_page: 10,
  last_page: 1,
  total: 0,
});

export function useDocumenti() {
  /**
   * Sets new comunicazioni data and pagination meta
   */
  const setDocumenti = (newData: Documento[], newMeta: PaginationMeta): void => {
    documenti.value = newData;
    meta.value = {
      ...meta.value,
      ...newMeta
    };
  };

  /**
   * Removes a comunicazione by ID and handles pagination
   */
  const removeDocumento = (id: string): void => {
    // Filter out the deleted item
    documenti.value = documenti.value.filter(c => c.id !== id);
    
    // Update pagination meta
    meta.value.total = Math.max(0, meta.value.total - 1);
    meta.value.last_page = Math.max(1, Math.ceil(meta.value.total / meta.value.per_page));

    // If current page is empty and not first page, go to previous page
    if (documenti.value.length === 0 && meta.value.current_page > 1) {
      router.visit(window.location.pathname, {
        data: { page: meta.value.current_page - 1 },
        preserveScroll: true,
        preserveState: true,
        only: ['comunicazioni', 'meta', 'flash'],
      });
    }
  };

  return {
    documenti,
    meta,
    setDocumenti,
    removeDocumento
  };
}