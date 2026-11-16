<script setup lang="ts">
/**
 * Gli accordi di rientro di una persona nella pagina del suo estratto conto: anche chiusi o riusciti, quando la persona non ha più rate
 * scadute e non è nell'elenco «Rate scadute». Per ognuno il segno con le due cifre e la parte versata oltre il capitale riconosciuto (un credito
 * della persona, che il programma non sposta), il calendario con le rate versate o no, la regola di decadenza e i suoi segnali, l'autorizzazione, la
 * chiusura o la decadenza registrata; «Apri l'accordo» apre la stessa finestra dell'elenco. Le date arrivano dal server come `Y-m-d`.
 */
import { ref } from 'vue';
import { Button } from '@/components/ui/button';
import AccordoRientroDialog from '@/components/gestionale/accordi/AccordoRientroDialog.vue';
import CalendarioDellAccordo from '@/components/gestionale/accordi/CalendarioDellAccordo.vue';
import { giorno } from '@/lib/gestionale/debiti/rateScadute';
import { testoAutorizzazione, testoSegnoAccordo, type AccordoRientro } from '@/lib/gestionale/debiti/accordiRientro';

defineProps<{ condominioId: number; persona: { id: number; nome: string }; accordi: Array<AccordoRientro & { in_corso: boolean }>; oggi: string }>();
const emit = defineEmits<{ (e: 'fatto'): void }>();

const aperta = ref(false);
</script>

<template>
    <section v-if="accordi.length" class="rounded-xl border bg-white dark:bg-slate-900 p-4 space-y-2 text-sm" data-testid="accordi-della-persona">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <h3 class="font-medium">Accordi di rientro</h3>
            <Button size="sm" variant="outline" data-testid="apri-accordo" @click="aperta = true">Apri l'accordo</Button>
        </div>
        <div v-for="a in accordi" :key="a.id" class="rounded-lg border p-2 space-y-1 text-xs" data-testid="accordo-della-persona">
            <p class="font-medium">{{ testoSegnoAccordo(a) }}</p>
            <p>{{ testoAutorizzazione(a) }}</p>
            <CalendarioDellAccordo :accordo="a" />
            <p v-if="a.decadenza_registrata_il">Decadenza registrata il {{ giorno(a.decadenza_registrata_il) }}{{ a.nota_chiusura ? `: ${a.nota_chiusura}` : '.' }}</p>
            <p v-else-if="a.chiuso_il">Chiuso il {{ giorno(a.chiuso_il) }}{{ a.nota_chiusura ? `: ${a.nota_chiusura}` : '.' }}</p>
        </div>
        <AccordoRientroDialog v-model:open="aperta" :condominio-id="condominioId" :persona="persona" :oggi="oggi" @fatto="emit('fatto')" />
    </section>
</template>
