<script setup lang="ts">
import { ref } from 'vue';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuSeparator, DropdownMenuTrigger } from '@/components/ui/dropdown-menu'
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog'
import { Button } from '@/components/ui/button'
import { Textarea } from '@/components/ui/textarea'
import { Label } from '@/components/ui/label'
import { MoreHorizontal, Eye, RotateCcw, Download, Loader2, AlertTriangle } from 'lucide-vue-next'
import { router, useForm } from '@inertiajs/vue3'
import { usePermission } from '@/composables/permissions'

const props = defineProps<{
    pagamento: any,
    condominioId: number
}>()

const { generateRoute } = usePermission()

// ── Storno modal state ───────────────────────────────────────────────────────
const isStornoModalOpen = ref(false);

const stornoForm = useForm({
    motivo: '',
});

const openStornoModal = () => {
    stornoForm.reset();
    isStornoModalOpen.value = true;
};

const confirmStorno = () => {
    stornoForm.post(
        route(generateRoute('gestionale.pagamenti-fornitori.storno'), {
            condominio: props.condominioId,
            pagamento: props.pagamento.id,
        }),
        {
            onSuccess: () => {
                isStornoModalOpen.value = false;
                stornoForm.reset();
            },
            preserveScroll: true,
        }
    );
};

// ── Download distinta ───────────────────────────────────────────────────────
const downloadDistinta = () => {
    window.open(
        route(generateRoute('gestionale.pagamenti-fornitori.distinta'), {
            condominio: props.condominioId,
            pagamento: props.pagamento.id,
        }),
        '_blank'
    );
};

// ── Navigazione dettaglio scrittura (beta.7) ────────────────────────────────
const goToScrittura = () => {
    if (!props.pagamento.scrittura_contabile_id) return;
    router.visit(route(generateRoute('gestionale.scritture.show'), {
        condominio: props.condominioId,
        scrittura: props.pagamento.scrittura_contabile_id,
    }));
};
</script>

<template>
  <!-- Trigger dropdown -->
  <DropdownMenu>
    <DropdownMenuTrigger as-child>
      <Button variant="ghost" class="h-8 w-8 p-0">
        <span class="sr-only">Apri menu</span>
        <MoreHorizontal class="h-4 w-4" />
      </Button>
    </DropdownMenuTrigger>
    <DropdownMenuContent align="end" class="w-[200px]">
      
      <!-- Dettaglio scrittura (beta.7) -->
      <DropdownMenuItem @click="goToScrittura" :disabled="!pagamento.scrittura_contabile_id">
        <Eye class="mr-2 h-4 w-4" />
        Dettaglio scrittura
      </DropdownMenuItem>

      <DropdownMenuItem @click="downloadDistinta" :disabled="pagamento.is_storno || pagamento.stato === 'stornato'">
        <Download class="mr-2 h-4 w-4" />
        <span>Scarica distinta</span>
      </DropdownMenuItem>
      
      <DropdownMenuSeparator v-if="pagamento.stato === 'confermato' && !pagamento.is_storno" />
      
      <DropdownMenuItem 
        v-if="pagamento.stato === 'confermato' && !pagamento.is_storno" 
        @click="openStornoModal" 
        class="text-rose-600 focus:text-rose-700 cursor-pointer"
      >
        <RotateCcw class="mr-2 h-4 w-4" />
        Storna pagamento
      </DropdownMenuItem>

      <!-- Badge stornato o record di storno (solo visivo, non cliccabile) -->
      <div v-if="pagamento.stato === 'stornato' || pagamento.is_storno" class="px-2 py-1.5">
        <span class="inline-flex items-center gap-1 text-[10px] font-bold text-slate-400 uppercase tracking-wider">
          <RotateCcw class="w-3 h-3" /> {{ pagamento.is_storno ? 'Operazione di storno' : 'Già stornato' }}
        </span>
      </div>

    </DropdownMenuContent>
  </DropdownMenu>

  <!-- Modale conferma storno -->
  <Dialog v-model:open="isStornoModalOpen">
    <DialogContent class="sm:max-w-[480px]">
      <DialogHeader>
        <DialogTitle class="flex items-center gap-2 text-rose-600">
          <AlertTriangle class="w-5 h-5" />
          Storna pagamento
        </DialogTitle>
        <DialogDescription>
          Stai per annullare il pagamento di
          <span class="font-bold text-slate-900">
            {{ pagamento.fornitore?.ragione_sociale || pagamento.fornitore_snapshot?.ragione_sociale || 'questo fornitore' }}
          </span>.
          <br />
          <strong class="text-slate-700">Il sistema creerà una scrittura contabile inversa</strong>
          (append-only) e riaprirà le fatture collegate. Il saldo del conto corrente sarà ripristinato.
        </DialogDescription>
      </DialogHeader>

      <!-- Avviso se cross-esercizio potenziale -->
      <div class="bg-amber-50 border border-amber-200 rounded-lg p-3 text-xs text-amber-700">
        <strong class="font-bold block mb-1">Operazione irreversibile</strong>
        Lo storno non elimina il pagamento originale: entrambe le scritture rimarranno nel libro giornale per garantire l'integrità del registro contabile.
      </div>

      <div class="grid gap-2">
        <Label for="motivo-storno" class="text-slate-900 font-semibold">
          Motivo dello storno <span class="text-rose-500">*</span>
        </Label>
        <Textarea 
          id="motivo-storno" 
          v-model="stornoForm.motivo" 
          placeholder="Es: Bonifico tornato indietro per IBAN errato del fornitore. Contattato il fornitore per conferma coordinate bancarie aggiornate..."
          class="resize-none min-h-[100px]"
          :class="{'border-red-500 focus-visible:ring-red-500': stornoForm.errors.motivo}"
          maxlength="1000"
        />
        <div class="flex justify-between items-center">
          <p v-if="stornoForm.errors.motivo" class="text-[11px] text-red-600 font-medium">
            {{ stornoForm.errors.motivo }}
          </p>
          <p class="text-[10px] text-slate-400 ml-auto">{{ stornoForm.motivo.length }}/1000</p>
        </div>
      </div>

      <DialogFooter>
        <Button variant="outline" @click="isStornoModalOpen = false" :disabled="stornoForm.processing">
          Annulla
        </Button>
        <Button 
          variant="destructive" 
          @click="confirmStorno" 
          :disabled="stornoForm.processing || stornoForm.motivo.length < 10"
        >
          <Loader2 v-if="stornoForm.processing" class="w-4 h-4 mr-2 animate-spin" />
          Conferma storno
        </Button>
      </DialogFooter>
    </DialogContent>
  </Dialog>
</template>
