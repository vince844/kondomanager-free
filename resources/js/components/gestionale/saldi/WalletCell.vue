<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { Plus, Lock, Pencil } from 'lucide-vue-next';
import type { ImmobileConSaldi } from '@/types/gestionale/saldi';

const props = defineProps<{
    immobile: ImmobileConSaldi;
    gestioni: any[];
}>();

const formatCurrency = (value: number) => {
  return new Intl.NumberFormat('it-IT', { style: 'currency', currency: 'EUR' }).format(value / 100);
};

const openModal = (anagraficaId: number) => {
    // Questa funzione emetterà un evento globale o aggiornerà uno store 
    // per aprire il modal di inserimento saldo. Per ora stampiamo un log.
    console.log(`Apri modal per Immobile ${props.immobile.id}, Anagrafica ${anagraficaId}`);
    // TODO: Integrare con il Modal
}
</script>

<template>
  <div class="flex flex-col gap-3">
    <div v-for="anagrafica in immobile.anagrafiche" :key="anagrafica.id" class="flex flex-wrap items-center gap-2">
      
      <div v-for="saldo in anagrafica.saldi" :key="saldo.id" 
           class="flex items-center gap-2 p-1.5 px-2 rounded-md border min-w-[120px]"
           :class="saldo.saldo_iniziale < 0 ? 'bg-red-50/50 border-red-200' : 'bg-green-50/50 border-green-200'">
        
        <div class="flex flex-col flex-1">
          <span class="text-[9px] uppercase font-bold text-muted-foreground truncate max-w-[100px]">{{ saldo.gestione.nome }}</span>
          <span class="font-mono font-bold text-xs" :class="saldo.saldo_iniziale < 0 ? 'text-red-700' : 'text-green-700'">
            {{ formatCurrency(saldo.saldo_iniziale) }}
          </span>
        </div>

        <Lock v-if="saldo.is_applicato" class="h-3 w-3 text-muted-foreground opacity-50 shrink-0" />
        <button v-else @click.prevent class="hover:text-primary shrink-0 transition-colors">
          <Pencil class="h-3 w-3 text-muted-foreground" />
        </button>
      </div>

      <Button variant="outline" size="sm" class="h-7 w-7 p-0 border-dashed rounded-full text-muted-foreground hover:text-primary hover:border-primary" @click="openModal(anagrafica.id)">
        <Plus class="h-3.5 w-3.5" />
      </Button>

    </div>
  </div>
</template>