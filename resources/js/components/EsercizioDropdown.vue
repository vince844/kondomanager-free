<script setup lang="ts">
import { ChevronDown } from "lucide-vue-next";
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuTrigger, DropdownMenuPortal} from "@/components/ui/dropdown-menu";
import { useEsercizioDropdown } from "@/composables/useEsercizioDropdown";
import { ref, watch } from "vue";
import type { Esercizio } from "@/types/gestionale/esercizi";
import type { Building } from "@/types/buildings";

const props = defineProps<{
  condominio: Building;
  esercizio: Esercizio | null;
  esercizi: Esercizio[];
}>();

const { selectEsercizio } = useEsercizioDropdown();
const selected = ref<Esercizio | null>(props.esercizio ?? null);

// if condominio changes, reset the selected esercizio if it doesn’t exist
watch(() => props.condominio.id, () => {
  if (!props.esercizi.find(e => e.id === selected.value?.id)) {
    selected.value = props.esercizi[0] ?? null;
  }
});
</script>

<template>
  <DropdownMenu>
  <DropdownMenuTrigger class="flex items-center gap-1 font-medium">
    <span v-if="selected">{{ selected.nome }}</span>
    <span v-else class="text-gray-400">Seleziona esercizio</span>
    <ChevronDown class="h-4 w-4 mt-1" />
  </DropdownMenuTrigger>

  <!-- Usa esplicitamente il portale -->
  <DropdownMenuPortal>
    <DropdownMenuContent align="start">
      <template v-if="props.esercizi.length">
        <DropdownMenuItem
          v-for="e in props.esercizi"
          :key="e.id"
          @click="selectEsercizio(props.condominio.id, e.id)"
          class="cursor-pointer"
        >
          {{ e.nome }}
        </DropdownMenuItem>
      </template>

      <!-- Nessun esercizio -->
      <div v-else class="px-3 py-2 text-sm text-gray-500">
        Nessun esercizio disponibile
      </div>
    </DropdownMenuContent>
  </DropdownMenuPortal>
</DropdownMenu>


</template>
