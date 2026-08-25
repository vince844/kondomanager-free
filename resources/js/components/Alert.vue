<script setup lang="ts">
import { Alert, AlertDescription } from '@/components/ui/alert'
import { ref, onMounted, onBeforeUnmount } from 'vue';
import { X } from 'lucide-vue-next';
import type { Flash } from '@/types/flash';

const props = defineProps<Flash>()

const showNotification = ref(false);
let timer: ReturnType<typeof setTimeout> | null = null;

/**
 * Gli avvisi di ERRORE non scompaiono più da soli.
 *
 * Un errore spiega perché un'operazione è stata rifiutata e cosa fare invece:
 * farlo svanire dopo tre secondi equivale a negare senza spiegare — chi distoglie
 * lo sguardo un istante vede solo che "non è successo niente". Successi e note
 * informative possono invece autochiudersi, perché comunicano che è andato tutto bene.
 */
const autoChiude = () => props.type !== 'error' && props.type !== 'warning';

const hideFlash = () => {
  showNotification.value = false;
};

onMounted(() => {
  showNotification.value = true;

  // NB: qui in precedenza si azzerava anche `usePage().props.flash`, mutando lo
  // stato globale condiviso da tutte le pagine. Oltre a essere un effetto
  // collaterale fuori posto, lasciava il canale sporco per i messaggi successivi.
  if (autoChiude()) {
    timer = setTimeout(hideFlash, 6000);
  }
});

onBeforeUnmount(() => {
  if (timer) clearTimeout(timer);
});
</script>

<template>
  <Alert
    v-if="showNotification"
    class="relative pr-10"
    :class="{'bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded': type === 'success',
            'bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded': type === 'error',
            'bg-yellow-100 border border-yellow-400 text-yellow-700 px-4 py-3 rounded': type === 'warning',
            'bg-blue-100 border border-blue-400 text-blue-700 px-4 py-3 rounded': type === 'info'
            }"
    >
    <AlertDescription class="font-medium">
      {{ message }}
    </AlertDescription>

    <button
      type="button"
      class="absolute right-2 top-2 rounded p-1 opacity-60 transition hover:opacity-100"
      aria-label="Chiudi avviso"
      @click="hideFlash"
    >
      <X class="h-4 w-4" />
    </button>
  </Alert>
</template>
