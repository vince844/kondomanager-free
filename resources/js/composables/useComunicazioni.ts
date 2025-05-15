import { ref } from 'vue';
import type { Comunicazione } from '@/types/comunicazioni';

// Reactive references for the table data and metadata
const comunicazioni = ref<Comunicazione[]>([]);
const meta = ref({
  current_page: 1,
  per_page: 10,
  last_page: 1,
  total: 0,
});

export function useComunicazioni() {
  const setComunicazioni = (newData: Comunicazione[], newMeta: typeof meta.value) => {
    comunicazioni.value = newData;
    meta.value = newMeta;
  };

  const removeComunicazione = (id: number) => {
    const initialLength = comunicazioni.value.length;
    comunicazioni.value = comunicazioni.value.filter(c => Number(c.id) !== id);
    if (comunicazioni.value.length < initialLength) {
      meta.value.total = Math.max(0, meta.value.total - 1);
    }
  };

  const restoreComunicazione = (comunicazione: Comunicazione) => {
    comunicazioni.value = [comunicazione, ...comunicazioni.value];
    meta.value.total += 1;
  };

  return {
    comunicazioni,
    meta,
    setComunicazioni,
    removeComunicazione,
    restoreComunicazione,
  };
}