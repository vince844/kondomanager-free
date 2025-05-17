// resources/js/composables/useSegnalazioni.ts
import { ref } from 'vue';
import { router } from '@inertiajs/vue3';
import type { Segnalazione } from '@/types/segnalazioni';
import type { PaginationMeta } from '@/types/pagination';

// Module-level state
const segnalazioni = ref<Segnalazione[]>([]);
const meta = ref<PaginationMeta>({
  current_page: 1,
  per_page: 10,
  last_page: 1,
  total: 0,
});

export function useSegnalazioni() {
  
  const setSegnalazioni = (newData: Segnalazione[], newMeta: typeof meta.value) => {
    segnalazioni.value = [...newData]; // New array reference
    meta.value = { ...newMeta };
  };

  const removeSegnalazione = (id: number | string) => {
    
      segnalazioni.value = segnalazioni.value.filter(c => c.id !== id);
      meta.value.total = Math.max(0, meta.value.total - 1);
  
      const newLastPage = Math.max(1, Math.ceil(meta.value.total / meta.value.per_page));
  
      if (segnalazioni.value.length === 0 && meta.value.current_page > 1) {
        // Go to previous page
        router.visit(window.location.pathname, {
          data: { page: meta.value.current_page - 1 },
          preserveScroll: true,
          preserveState: true,
          only: ['segnalazioni', 'meta', 'flash'],
        });
        return;
    }

    meta.value.last_page = newLastPage;
  };

  return {
    segnalazioni,
    meta,
    setSegnalazioni,
    removeSegnalazione
  };
}