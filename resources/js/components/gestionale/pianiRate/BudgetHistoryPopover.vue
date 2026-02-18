<script setup lang="ts">
import { computed } from 'vue';
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover';
import { History, ArrowRight, ArrowLeft, User } from 'lucide-vue-next';
import { useCurrencyFormatter } from '@/composables/useCurrencyFormatter';
import { Badge } from '@/components/ui/badge';

const props = defineProps<{
  capitoloId: number;
  currentAmount: number; // In Centesimi
  movements: Array<any>; // Tutti i movimenti del piano rate
}>();

const { euro } = useCurrencyFormatter();

// FIX: Convertiamo tutto in Number per evitare errori di confronto (String vs Int)
const targetId = computed(() => Number(props.capitoloId));

// Filtriamo solo i movimenti che riguardano QUESTO capitolo
const relevantMovements = computed(() => {
  return props.movements.filter(m => 
    Number(m.source_conto_id) === targetId.value || 
    Number(m.destination_conto_id) === targetId.value
  ).sort((a, b) => new Date(b.created_at).getTime() - new Date(a.created_at).getTime()); // Dal più recente
});

// Calcolo Importo Originario (Ingegneria Inversa)
const originalAmount = computed(() => {
  let amount = Number(props.currentAmount); // Cast di sicurezza
  
  relevantMovements.value.forEach(m => {
    // Se ero la sorgente (ho perso soldi), li riaggiungo
    if (Number(m.source_conto_id) === targetId.value) {
      amount += Number(m.amount); 
    } else {
      // Se ero la destinazione (ho ricevuto soldi), li tolgo
      amount -= Number(m.amount); 
    }
  });
  return amount;
});

const hasHistory = computed(() => relevantMovements.value.length > 0);
</script>

<template>
  <div v-if="hasHistory" class="flex items-center">
    <Popover>
      <PopoverTrigger as-child>
        <button 
            class="text-slate-400 hover:text-indigo-600 transition-colors p-1 rounded-full"
            title="Vedi storico modifiche budget"
        >
          <History class="w-3.5 h-3.5" />
        </button>
      </PopoverTrigger>
      <PopoverContent :side-offset="8" side="left" align="center" class="w-80 p-0 shadow-lg border-slate-200" >
        
        <div class="bg-slate-50 p-3 border-b border-slate-100 flex justify-between items-center">
            <span class="text-xs font-bold text-slate-700 uppercase tracking-wider">Storia Budget</span>
            <Badge variant="outline" class="text-[10px] bg-white text-slate-500">
                Orig: {{ euro(originalAmount) }}
            </Badge>
        </div>

        <div class="max-h-[250px] overflow-y-auto p-2 space-y-2">
            <div v-for="move in relevantMovements" :key="move.id" class="text-xs p-2 rounded-md border border-slate-100 bg-white shadow-sm">
                
                <div class="flex justify-between items-center text-[10px] text-slate-400 mb-1.5">
                    <span>{{ new Date(move.created_at).toLocaleDateString('it-IT') }}</span>
                    <span class="flex items-center gap-1 bg-slate-50 px-1.5 py-0.5 rounded text-slate-600">
                        <User class="w-2.5 h-2.5" /> {{ move.user?.name ?? 'Sistema' }}
                    </span>
                </div>

                <div class="flex items-center justify-between gap-2 font-medium">
                    <template v-if="Number(move.source_conto_id) === targetId">
                        <div class="flex items-center gap-1.5 text-amber-700">
                            <ArrowRight class="w-3.5 h-3.5" />
                            <span>A: {{ move.destination_conto?.nome }}</span>
                        </div>
                        <span class="text-red-600 font-bold">- {{ euro(move.amount) }}</span>
                    </template>

                    <template v-else>
                        <div class="flex items-center gap-1.5 text-emerald-700">
                            <ArrowLeft class="w-3.5 h-3.5" />
                            <span>Da: {{ move.source_conto?.nome }}</span>
                        </div>
                        <span class="text-emerald-600 font-bold">+ {{ euro(move.amount) }}</span>
                    </template>
                </div>

                <div class="mt-1.5 pt-1.5 border-t border-slate-50 text-slate-500 italic">
                    "{{ move.reason }}"
                </div>
            </div>
        </div>

        <div class="bg-slate-50 p-2 border-t border-slate-100 text-center">
            <span class="text-[10px] text-slate-500 font-medium">Attuale: <span class="text-slate-800 font-bold text-xs">{{ euro(currentAmount) }}</span></span>
        </div>

      </PopoverContent>
    </Popover>
  </div>
</template>