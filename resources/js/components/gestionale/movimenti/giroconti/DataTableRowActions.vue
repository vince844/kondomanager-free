<script setup lang="ts">
import { ref } from 'vue';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuTrigger } from '@/components/ui/dropdown-menu'
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog'
import { Button } from '@/components/ui/button'
import { Textarea } from '@/components/ui/textarea'
import { Label } from '@/components/ui/label'
import { MoreHorizontal, RotateCcw, Loader2, AlertTriangle } from 'lucide-vue-next'
import { useForm } from '@inertiajs/vue3'
import { usePermission } from '@/composables/permissions'
import type { GirocontoRow } from './columns'

const props = defineProps<{
    giroconto: GirocontoRow,
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
        route(generateRoute('gestionale.giroconti.storno'), {
            condominio: props.condominioId,
            scrittura: props.giroconto.id,
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

      <DropdownMenuItem
        @click="openStornoModal"
        class="text-rose-600 focus:text-rose-700 cursor-pointer"
      >
        <RotateCcw class="mr-2 h-4 w-4" />
        Storna giroconto
      </DropdownMenuItem>

    </DropdownMenuContent>
  </DropdownMenu>

  <!-- Modale conferma storno -->
  <Dialog v-model:open="isStornoModalOpen">
    <DialogContent class="sm:max-w-[480px]">
      <DialogHeader>
        <DialogTitle class="flex items-center gap-2 text-rose-600">
          <AlertTriangle class="w-5 h-5" />
          Storna giroconto
        </DialogTitle>
        <DialogDescription>
          Stai per annullare il giroconto
          <span class="font-bold text-slate-900">{{ giroconto.numero_protocollo }}</span>
          ({{ giroconto.origine }} → {{ giroconto.destinazione }}).
          <br />
          <strong class="text-slate-700">Il sistema creerà una scrittura contabile inversa</strong>
          (append-only): la liquidità tornerà alla cassa di origine e l'eventuale copertura confermata tornerà in attesa.
        </DialogDescription>
      </DialogHeader>

      <div class="bg-amber-50 border border-amber-200 rounded-lg p-3 text-xs text-amber-700">
        <strong class="font-bold block mb-1">Operazione irreversibile</strong>
        Lo storno non elimina il giroconto originale: entrambe le scritture rimarranno nel libro giornale per garantire l'integrità del registro contabile.
      </div>

      <div class="grid gap-2">
        <Label for="motivo-storno" class="text-slate-900 font-semibold">
          Motivo dello storno <span class="text-rose-500">*</span>
        </Label>
        <Textarea
          id="motivo-storno"
          v-model="stornoForm.motivo"
          placeholder="Es: Giroconto registrato sul fondo sbagliato. L'accantonamento va ridestinato al fondo per i lavori straordinari..."
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
