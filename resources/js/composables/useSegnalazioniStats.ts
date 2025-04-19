import { ref } from 'vue';
import axios from 'axios';
import { useToast } from '@/components/ui/toast/use-toast';

export function useSegnalazioniStats() {
  const stats = ref({
    bassa: 0,
    media: 0,
    alta: 0,
    urgente: 0,
  });

  const isLoading = ref(false);
  const { toast } = useToast();

  function loadStats() {
    isLoading.value = true;

    axios.get(route('segnalazioni.stats'))
      .then(response => {
        stats.value = {
          bassa: response.data.bassa ?? 0,
          media: response.data.media ?? 0,
          alta: response.data.alta ?? 0,
          urgente: response.data.urgente ?? 0,
        };
      })
      .catch(error => {
        console.error('Errore nel caricamento delle statistiche:', error);
        toast({
          title: 'Ops, si è verificato un errore',
          description: 'Impossibile caricare le statistiche delle segnalazioni. Riprova più tardi.',
          variant: 'destructive',
        });
      })
      .finally(() => {
        isLoading.value = false;
      });
  }

  return {
    stats,
    isLoading,
    loadStats,
  };
}