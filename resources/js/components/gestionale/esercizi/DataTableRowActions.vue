<script setup lang="ts">

import { Link } from "@inertiajs/vue3"
import { Button } from '@/components/ui/button'
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuLabel, DropdownMenuTrigger } from '@/components/ui/dropdown-menu'
import { FilePenLine, MoreHorizontal } from 'lucide-vue-next'
import { usePermission } from "@/composables/permissions"
import type { Esercizio } from '@/types/gestionale/esercizi'
import type { Building } from '@/types/buildings'

const { esercizio, condominio } = defineProps<{ esercizio: Esercizio, condominio: Building }>()

const { generateRoute } = usePermission()
</script>

<template>
  <DropdownMenu>
    <DropdownMenuTrigger as-child>
      <Button variant="ghost" class="w-8 h-8 p-0" aria-label="Apri menu azioni">
        <MoreHorizontal class="w-4 h-4" />
      </Button>
    </DropdownMenuTrigger>
    <DropdownMenuContent align="end">
      <DropdownMenuLabel>Azioni</DropdownMenuLabel>

      <DropdownMenuItem>
        <Link
          :href="route(generateRoute('gestionale.esercizi.edit'), { condominio: condominio.id, esercizio: esercizio.id })"
          preserve-state
          class="flex items-center gap-2"
        >
          <FilePenLine class="w-4 h-4 text-xs" />
          Modifica
        </Link>
      </DropdownMenuItem>

      <!-- Eliminazione rimossa dall'interfaccia.
           L'esercizio è il contenitore di tutto il libro giornale del periodo:
           la FK `scritture_contabili.esercizio_id` è cascadeOnDelete, quindi
           eliminarlo distruggerebbe scritture, righe e pivot di quel periodo.
           Il muro contabile lato server resta come difesa in profondità, ma un
           pulsante che offre un'operazione irreversibile su un pilastro della
           contabilità non ha ragione di esistere nell'uso quotidiano.
           La MODIFICA resta: è l'unico modo per chiudere un esercizio. -->
    </DropdownMenuContent>
  </DropdownMenu>

</template>
