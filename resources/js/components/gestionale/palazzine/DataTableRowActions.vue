<!--
SPDX-License-Identifier: AGPL-3.0-or-later
Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
-->
<script setup lang="ts">

import { ref } from 'vue'
import { router, Link } from "@inertiajs/vue3"
import { Button } from '@/components/ui/button'
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuLabel, DropdownMenuTrigger } from '@/components/ui/dropdown-menu'
import ConfirmDialog from '@/components/ConfirmDialog.vue'
import { Trash2, FilePenLine, MoreHorizontal } from 'lucide-vue-next'
import { usePermission } from "@/composables/permissions"
import { Permission } from "@/enums/Permission"
import type { Palazzina } from '@/types/gestionale/palazzine'
import type { Building } from '@/types/buildings'

const { palazzina, condominio } = defineProps<{ palazzina: Palazzina, condominio: Building }>()

const palazzinaID = ref<number | null>(null)
const isAlertOpen = ref(false)
const isDropdownOpen = ref(false)
const isDeleting = ref(false)

const { generateRoute, hasPermission } = usePermission()
// Palazzine, scale, tabelle e millesimi si cambiano con «Modifica condomini» (PalazzinaController, ScalaController, TabellaController, TabellaQuotaController): senza, i pulsanti non ci sono.
const puoModificare = hasPermission([Permission.EDIT_CONDOMINI])

function handleDelete(targetPalazzina: Palazzina) {
  palazzinaID.value = targetPalazzina.id
  isDropdownOpen.value = false
  setTimeout(() => {
    isAlertOpen.value = true
  }, 200)
}

function closeModal() {
  palazzinaID.value = null
  isAlertOpen.value = false
  isDropdownOpen.value = false
}

function deletePalazzina() {
  if (palazzinaID.value === null || isDeleting.value) return

  const id = palazzinaID.value
  isDeleting.value = true

  router.delete(route(generateRoute('gestionale.palazzine.destroy'), { condominio: condominio.id, palazzina: id }), {
    preserveScroll: true,
    preserveState: true,
    only: ['flash', 'palazzine'],
    onSuccess: () => {
      closeModal()
    },
    onError: () => {
      console.error('Errore durante la cancellazione.')
    },
    onFinish: () => {
      isDeleting.value = false
    }
  })
}
</script>

<template>
  <DropdownMenu v-if="puoModificare">
    <DropdownMenuTrigger as-child>
      <Button variant="ghost" class="w-8 h-8 p-0" aria-label="Apri menu azioni">
        <MoreHorizontal class="w-4 h-4" />
      </Button>
    </DropdownMenuTrigger>
    <DropdownMenuContent align="end">
      <DropdownMenuLabel>Azioni</DropdownMenuLabel>

      <DropdownMenuItem>
        <Link
          :href="route(generateRoute('gestionale.palazzine.edit'), { condominio: condominio.id, palazzina: palazzina.id })"
          preserve-state
          class="flex items-center gap-2"
        >
          <FilePenLine class="w-4 h-4 text-xs" />
          Modifica
        </Link>
      </DropdownMenuItem>

      <DropdownMenuItem
        @click="handleDelete(palazzina)"
      >
        <Trash2 class="w-4 h-4 text-xs" />
        Elimina
      </DropdownMenuItem>
    </DropdownMenuContent>
  </DropdownMenu>

  <ConfirmDialog
    v-model:modelValue="isAlertOpen"
    title="Sei sicuro di voler eliminare questa palazzina?"
    description="Questa azione non è reversibile. Eliminerà la palazzina e tutti i dati ad essa associati."
    :loading="isDeleting"
    @confirm="deletePalazzina"
  />

</template>
