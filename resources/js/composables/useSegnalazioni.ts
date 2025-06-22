import { ref } from 'vue';
import { router } from '@inertiajs/vue3';
import type { Segnalazione } from '@/types/segnalazioni';
import type { PaginationMeta } from '@/types/pagination';

export function useSegnalazioni(initialData: Segnalazione[] = [], initialMeta: Partial<PaginationMeta> = {}) {
  const segnalazioni = ref<Segnalazione[]>(initialData);
  const meta = ref<PaginationMeta>({
    current_page: 1,
    per_page: 10,
    last_page: 1,
    total: 0,
    ...initialMeta
  });

  /**
   * Sets new segnalazioni data and pagination meta
   */
  const setSegnalazioni = (newData: Segnalazione[], newMeta: Partial<PaginationMeta>): void => {
    segnalazioni.value = newData;
    meta.value = {
      ...meta.value,
      ...newMeta
    };
  };

  /**
   * Removes a segnalazione by ID and handles pagination
   */
  const removeSegnalazione = (id: number): void => {
    segnalazioni.value = segnalazioni.value.filter(c => c.id !== id);
    meta.value.total = Math.max(0, meta.value.total - 1);
    meta.value.last_page = Math.max(1, Math.ceil(meta.value.total / meta.value.per_page));

    if (segnalazioni.value.length === 0 && meta.value.current_page > 1) {
      router.visit(window.location.pathname, {
        data: { page: meta.value.current_page - 1 },
        preserveScroll: true,
        preserveState: true,
        only: ['segnalazioni', 'meta', 'flash'],
      });
    }
  };

  return {
    segnalazioni,
    meta,
    setSegnalazioni,
    removeSegnalazione
  };
}