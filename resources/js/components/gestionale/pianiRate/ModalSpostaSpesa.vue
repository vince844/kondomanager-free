<script setup lang="ts">
import { ref, computed, watch } from 'vue';
import { useForm } from '@inertiajs/vue3';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { AlertTriangle, ArrowRightLeft, Wallet, Lightbulb, Info } from 'lucide-vue-next';
import { useCurrencyFormatter } from '@/composables/useCurrencyFormatter';

const props = defineProps<{
  show: boolean;
  pianoRateId: number;
  condominioId: number;
  // Sources deve avere importo_residuo (int) per la logica e formatted_residuo (string) per la UI
  sources: Array<{ id: number; nome: string; importo_residuo: number; formatted_residuo: string }>;
  destinations: Array<{ id: number; nome: string }>;
}>();

const emit = defineEmits(['update:show', 'success']);
const { euro } = useCurrencyFormatter();

const form = useForm({
  source_id: '',
  destination_id: '',
  amount: '',
  reason: '',
});

// Computed per ottenere il saldo della sorgente selezionata
const selectedSource = computed(() => {
  return props.sources.find(s => s.id === Number(form.source_id));
});

// Validazione Importo: Confrontiamo Centesimi con Centesimi per evitare errori di virgola mobile
const isAmountValid = computed(() => {
  if (!form.amount || !selectedSource.value) return true;
  
  // Convertiamo l'input dell'utente (es. "150.50") in centesimi (15050)
  const inputCents = Math.round(parseFloat(form.amount) * 100);
  
  // Il residuo arriva dal backend GIÀ in centesimi
  return inputCents <= selectedSource.value.importo_residuo;
});

// Max Amount per l'attributo HTML (convertito in euro per l'input)
const maxAmount = computed(() => {
    return selectedSource.value ? (selectedSource.value.importo_residuo / 100) : 0;
});

const submit = () => {
  if (!isAmountValid.value) return;

  // Usa la rotta corretta col prefisso admin
  form.post(route('admin.gestionale.piani-rate.move-budget', { 
      condominio: props.condominioId,
      pianoRate: props.pianoRateId 
  }), {
    onSuccess: () => {
      form.reset();
      emit('update:show', false);
      emit('success');
    },
  });
};

// Reset form quando si apre la modale
watch(() => props.show, (val) => {
    if (val) form.reset();
});
</script>

<template>
  <Dialog :open="show" @update:open="$emit('update:show', $event)">
    <DialogContent class="sm:max-w-[600px]">
      <DialogHeader>
        <DialogTitle class="flex items-center gap-2 text-indigo-700">
          <ArrowRightLeft class="w-5 h-5" /> Sposta Budget (Bilancio Liquido)
        </DialogTitle>
        <DialogDescription>
          Rialloca fondi da una voce all'altra senza modificare il totale del piano rate.
        </DialogDescription>
      </DialogHeader>

      <div class="grid gap-4 py-4">

        <div class="bg-blue-50/80 border border-blue-200 rounded-lg p-4 space-y-3">
            <div class="flex items-start gap-3">
                <div class="bg-blue-100 p-1.5 rounded-full shrink-0">
                    <Info class="w-4 h-4 text-blue-700" />
                </div>
                <div class="text-sm text-blue-900">
                    <strong>Come funziona:</strong>
                    Stai modificando la destinazione d'uso interna dei fondi.
                    <span class="block mt-1 text-blue-800/80">
                        Le rate già emesse ai condomini <strong>NON VERRANNO RICALCOLATE</strong>.
                        La voce che cede i fondi resta scoperta di quell'importo: <strong>non è automatico</strong> —
                        comparirà come voce da finanziare la prossima volta che ricalcoli o crei un nuovo
                        piano rate, con l'indicazione che il residuo viene da uno spostamento.
                    </span>
                </div>
            </div>
            
            <div class="flex items-start gap-3 border-t border-blue-200/60 pt-3">
                <div class="bg-amber-100 p-1.5 rounded-full shrink-0">
                    <Lightbulb class="w-4 h-4 text-amber-700" />
                </div>
                <div class="text-xs text-slate-700">
                    <strong>Esempio pratico:</strong>
                    Hai avanzato € 200 dalle "Pulizie" e si rompe il cancello?
                    Sposta € 200 su "Manutenzione Cancello".
                    Il totale del piano non cambia, ma ora hai la copertura contabile corretta per pagare il fabbro.
                </div>
            </div>
        </div>
        
        <div class="grid gap-2">
          <Label>Preleva da (Sorgente)</Label>
          <Select v-model="form.source_id">
            <SelectTrigger :class="{'border-red-500': form.errors.source_id}">
              <SelectValue placeholder="Seleziona voce..." />
            </SelectTrigger>
            
            <SelectContent 
                position="popper" 
                :style="{ width: 'var(--reka-select-trigger-width)' }" 
                class="max-h-[200px]"
            >
         
              <SelectItem 
                v-for="source in sources" 
                :key="source.id" 
                :value="String(source.id)"
                :disabled="source.importo_residuo <= 0"
              >
                {{ source.nome }} (Disp: € {{ source.formatted_residuo }})
              </SelectItem>
            </SelectContent>
          </Select>
          <p v-if="selectedSource" class="text-xs text-emerald-600 font-medium flex items-center gap-1">
              <Wallet class="w-3 h-3" /> Disponibili: {{ euro(selectedSource.importo_residuo) }}
          </p>
          <span v-if="form.errors.source_id" class="text-xs text-red-500">{{ form.errors.source_id }}</span>
        </div>

        <div class="grid gap-2">
          <Label>Sposta su (Destinazione)</Label>
          <Select v-model="form.destination_id">
            <SelectTrigger :class="{'border-red-500': form.errors.destination_id}">
              <SelectValue placeholder="Seleziona destinazione..." />
            </SelectTrigger>
            
            <SelectContent 
                position="popper" 
                :style="{ width: 'var(--reka-select-trigger-width)' }"
                class="max-h-[200px]"
            >
              <SelectItem 
                v-for="dest in destinations" 
                :key="dest.id" 
                :value="String(dest.id)"
                :disabled="dest.id === Number(form.source_id)"
              >
                {{ dest.nome }}
              </SelectItem>
            </SelectContent>
          </Select>
          <span v-if="form.errors.destination_id" class="text-xs text-red-500">{{ form.errors.destination_id }}</span>
        </div>

        <div class="grid grid-cols-2 gap-4">
            <div class="grid gap-2">
              <Label>Importo (€)</Label>
              <div class="relative">
                  <Input 
                    v-model="form.amount" 
                    :max="maxAmount"
                    :class="{'border-red-500': !isAmountValid || form.errors.amount}"
                    placeholder="0.00" 
                  />
                  <div v-if="!isAmountValid && form.amount" class="absolute right-2 top-2.5 text-red-500">
                      <AlertTriangle class="w-4 h-4" />
                  </div>
              </div>
              <span v-if="!isAmountValid" class="text-[10px] text-red-500 font-bold">Importo superiore alla disponibilità!</span>
              <span v-if="form.errors.amount" class="text-xs text-red-500">{{ form.errors.amount }}</span>
            </div>

            <div class="grid gap-2">
              <Label>Motivazione</Label>
              <Input v-model="form.reason" placeholder="Es. Rottura Cancello" :class="{'border-red-500': form.errors.reason}" />
              <span v-if="form.errors.reason" class="text-xs text-red-500">{{ form.errors.reason }}</span>
            </div>
        </div>

      </div>

      <DialogFooter>
        <Button variant="outline" @click="$emit('update:show', false)">Annulla</Button>
        <Button 
            class="bg-indigo-600 hover:bg-indigo-700 whitespace-nowrap" 
            :disabled="form.processing || !isAmountValid || !form.source_id || !form.destination_id"
            @click="submit"
        >
            <ArrowRightLeft class="w-4 h-4 mr-2" /> Esegui spostamento
        </Button>
      </DialogFooter>
    </DialogContent>
  </Dialog>
</template>