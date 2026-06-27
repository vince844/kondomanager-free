<script setup lang="ts">
import { ref, computed } from 'vue';
import { AlertTriangle, ExternalLink } from 'lucide-vue-next';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { useCurrencyFormatter } from '@/composables/useCurrencyFormatter';
import { usePermission } from "@/composables/permissions";

export interface ScopertoCents {
    immobile_id: number;
    immobile_nome: string;
    conto_id: number;
    conto_nome: string;
    importo: number; // in cents
    ruolo_richiesto: string;
}

const props = defineProps<{
    scoperti: ScopertoCents[];
    processing?: boolean;
}>();

const emit = defineEmits<{
    (e: 'procedi', nota: string): void
}>();

const nota = ref('');
const { euro } = useCurrencyFormatter();
const { generatePath } = usePermission();

const totaleScoperto = computed(() => props.scoperti.reduce((acc, curr) => acc + curr.importo, 0));

const canProceed = computed(() => nota.value.trim().length >= 10);

const handleProcedi = () => {
    if (canProceed.value) {
        emit('procedi', nota.value.trim());
    }
};
</script>

<template>
  <div class="rounded-lg border-2 border-amber-300 bg-amber-50 shadow-sm overflow-hidden mt-6 mb-6">
    <div class="p-4 border-b border-amber-200 bg-amber-100/50 flex items-start gap-3">
      <AlertTriangle class="w-6 h-6 text-amber-600 shrink-0 mt-0.5" />
      <div>
        <h3 class="font-bold text-amber-900 text-base">
          Attenzione: {{ scoperti.length }} {{ scoperti.length === 1 ? 'quota non assegnabile' : 'quote non assegnabili' }} (totale {{ euro(totaleScoperto / 100) }})
        </h3>
        <p class="text-sm text-amber-800 mt-1">
          Queste unità non hanno soggetti attivi a cui addebitare la quota. Le quote degli altri condòmini restano corrette.
        </p>
      </div>
    </div>

    <div class="p-0 overflow-x-auto">
      <table class="w-full text-sm text-left">
        <thead class="bg-amber-100/30 text-amber-900 text-xs uppercase font-semibold">
          <tr>
            <th class="px-4 py-2">Immobile</th>
            <th class="px-4 py-2">Voce di spesa</th>
            <th class="px-4 py-2">Ruolo atteso</th>
            <th class="px-4 py-2 text-right">Importo</th>
            <th class="px-4 py-2 text-center">Azione</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-amber-100 bg-white/50">
          <tr v-for="(scoperto, index) in scoperti" :key="index" class="hover:bg-amber-50/50">
            <td class="px-4 py-2 font-medium text-slate-900">{{ scoperto.immobile_nome }}</td>
            <td class="px-4 py-2 text-slate-700">{{ scoperto.conto_nome }}</td>
            <td class="px-4 py-2">
              <span class="px-2 py-0.5 rounded text-[10px] font-bold uppercase border" 
                    :class="{
                      'bg-blue-50 text-blue-700 border-blue-200': scoperto.ruolo_richiesto === 'inquilino',
                      'bg-purple-50 text-purple-700 border-purple-200': scoperto.ruolo_richiesto === 'usufruttuario',
                      'bg-slate-50 text-slate-700 border-slate-200': scoperto.ruolo_richiesto !== 'inquilino' && scoperto.ruolo_richiesto !== 'usufruttuario'
                    }">
                {{ scoperto.ruolo_richiesto }}
              </span>
            </td>
            <td class="px-4 py-2 text-right font-medium text-amber-700">{{ euro(scoperto.importo / 100) }}</td>
            <td class="px-4 py-2 text-center">
              <a :href="generatePath('gestionale/:condominio/immobili/' + scoperto.immobile_id)" target="_blank" 
                 class="inline-flex items-center gap-1 text-[11px] font-semibold text-indigo-600 hover:text-indigo-800 transition-colors">
                Anagrafiche <ExternalLink class="w-3 h-3" />
              </a>
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <div class="p-4 bg-amber-50 border-t border-amber-200 space-y-4">
      <div class="space-y-2">
        <label for="nota_scoperti" class="block text-sm font-semibold text-amber-900">
          Cosa vuoi fare?
        </label>
        <p class="text-xs text-amber-700">Se vuoi procedere comunque addossando la differenza, specifica una motivazione (min. 10 caratteri) che verrà salvata nello storico.</p>
        <p class="text-xs font-semibold text-amber-800">
          Attenzione: una volta emesse le rate non sarà più possibile includere questa unità tramite Ricalcola. Se vuoi includerla, correggi l'anagrafica prima di emettere.
        </p>
        <div class="flex flex-col sm:flex-row gap-3 items-start sm:items-center">
          <Input 
            id="nota_scoperti"
            v-model="nota"
            placeholder="Es: Immobili in fase di aggiornamento anagrafica..."
            class="flex-1 bg-white border-amber-300 focus-visible:ring-amber-500"
            :disabled="processing"
            @keyup.enter="handleProcedi"
          />
          <Button 
            type="button" 
            @click="handleProcedi" 
            :disabled="!canProceed || processing"
            class="shrink-0 bg-amber-600 hover:bg-amber-700 text-white font-bold"
          >
            {{ processing ? 'Generazione in corso...' : 'Procedi comunque' }}
          </Button>
        </div>
        <p v-if="nota.length > 0 && !canProceed" class="text-xs text-amber-600 font-medium">
          La motivazione è troppo breve ({{ nota.length }}/10 caratteri).
        </p>
      </div>
    </div>
  </div>
</template>
