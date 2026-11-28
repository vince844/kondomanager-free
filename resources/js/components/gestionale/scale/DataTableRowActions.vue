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
import type { Scala } from '@/types/gestionale/scale'
import type { Building } from '@/types/buildings'

const { scala, condominio } = defineProps<{ scala: Scala, condominio: Building }>()

const scalaID = ref<number | null>(null)
const isAlertOpen = ref(false)
const isDropdownOpen = ref(false)
const isDeleting = ref(false)

const { generateRoute, hasPermission } = usePermission()
// Palazzine, scale, tabelle e millesimi si cambiano con «Modifica condomini» (PalazzinaController, ScalaController, TabellaController, TabellaQuotaController): senza, i pulsanti non ci sono.
const puoModificare = hasPermission([Permission.EDIT_CONDOMINI])

function handleDelete(targetScala: Scala) {
  scalaID.value = targetScala.id
  isDropdownOpen.value = false
  setTimeout(() => {
    isAlertOpen.value = true
  }, 200)
}

function closeModal() {
  scalaID.value = null
  isAlertOpen.value = false
  isDropdownOpen.value = false
}

function deleteScala() {
  if (scalaID.value === null || isDeleting.value) return

  const id = scalaID.value
  isDeleting.value = true

  router.delete(route(generateRoute('gestionale.scale.destroy'), { condominio: condominio.id, scala: id }), {
    preserveScroll: true,
    preserveState: true,
    only: ['flash', 'scale'],
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
          :href="route(generateRoute('gestionale.scale.edit'), { condominio: condominio.id, scala: scala.id })"
          preserve-state
          class="flex items-center gap-2"
        >
          <FilePenLine class="w-4 h-4 text-xs" />
          Modifica
        </Link>
      </DropdownMenuItem>

      <DropdownMenuItem
        @click="handleDelete(scala)"
      >
        <Trash2 class="w-4 h-4 text-xs" />
        Elimina
      </DropdownMenuItem>
    </DropdownMenuContent>
  </DropdownMenu>

  <ConfirmDialog
    v-model:modelValue="isAlertOpen"
    title="Sei sicuro di voler eliminare questa scala?"
    description="  Questa azione non è reversibile. Eliminerà la scala e tutti i dati ad essa associati."
    :loading="isDeleting"
    @confirm="deleteScala"
  />

</template>
