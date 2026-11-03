<script setup lang="ts">
/**
 * Coda 255 (1.11.0-beta.51): una frase del server con ogni importo in uno span che non va a capo, così il simbolo e la cifra non si separano
 * (`spezzaPerImporti`). Il testo non cambia: la frase intera, con il punto finale, resta quella che il server manda. La frase è testo, mai markup.
 */
import { computed } from 'vue';

import { spezzaPerImporti } from '@/lib/gestionale/passaggi/importoSenzaACapo';

const props = defineProps<{ testo: string }>();

const pezzi = computed(() => spezzaPerImporti(props.testo));
</script>

<template>
    <span><template v-for="(p, i) in pezzi" :key="i"><span v-if="p.importo" class="whitespace-nowrap">{{ p.testo }}</span><template v-else>{{ p.testo }}</template></template></span>
</template>
