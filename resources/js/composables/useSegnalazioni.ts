// resources/js/composables/useSegnalazioni.ts
import { ref } from 'vue';
import type { Segnalazione } from '@/types/segnalazioni';

// Module-level state
const segnalazioni = ref<Segnalazione[]>([]);
const meta = ref({
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

    const removeSegnalazione = (id: number) => {
    const initialLength = segnalazioni.value.length;
    segnalazioni.value = segnalazioni.value.filter(c => Number(c.id) !== id);
    if (segnalazioni.value.length < initialLength) {
      meta.value.total = Math.max(0, meta.value.total - 1);
    }
  };

/*   const removeSegnalazione = (id: string | number) => {
    const idNum = typeof id === 'string' ? Number(id) : id;
    const initialLength = segnalazioni.value.length;
    
    segnalazioni.value = segnalazioni.value.filter(c => Number(c.id) !== idNum);
    
    if (segnalazioni.value.length < initialLength) {
      meta.value.total = Math.max(0, meta.value.total - 1);
      return true; // Deletion occurred
    }
    return false;
  }; */

  const restoreSegnalazione = (segnalazione: Segnalazione) => {
    if (!segnalazioni.value.some(c => Number(c.id) === Number(segnalazione.id))) {
      segnalazioni.value = [segnalazione, ...segnalazioni.value];
      meta.value.total += 1;
      return true;
    }
    return false;
  };

  return {
    segnalazioni,
    meta,
    setSegnalazioni,
    removeSegnalazione,
    restoreSegnalazione,
  };
}