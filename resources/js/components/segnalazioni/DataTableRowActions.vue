<script setup lang="ts">

import { ref } from 'vue'
import { router, Link } from "@inertiajs/vue3"
import { Button } from '@/components/ui/button'
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuLabel, DropdownMenuTrigger } from '@/components/ui/dropdown-menu'
import ConfirmDialog from '@/components/ConfirmDialog.vue'
import { Trash2, FilePenLine, MoreHorizontal } from 'lucide-vue-next'
import { usePermission } from "@/composables/permissions"
import { Permission } from "@/enums/Permission"
import { useSegnalazioni } from '@/composables/useSegnalazioni'
import type { Segnalazione } from '@/types/segnalazioni'


defineProps<{ segnalazione: Segnalazione }>()

const segnalazioneID = ref<number | null>(null)
const isAlertOpen = ref(false)
const isDropdownOpen = ref(false)
const isDeleting = ref(false)

const { removeSegnalazione } = useSegnalazioni()
const { hasPermission, generateRoute } = usePermission()

function handleDelete(segnalazione: Segnalazione) {
  segnalazioneID.value = segnalazione.id
  isDropdownOpen.value = false
  setTimeout(() => {
    isAlertOpen.value = true
  }, 200)
}

function closeModal() {
  isAlertOpen.value = false
  isDropdownOpen.value = false
  segnalazioneID.value = null
}

function deleteSegnalazione() {
  if (segnalazioneID.value === null || isDeleting.value) return

  const id = segnalazioneID.value
  isDeleting.value = true

  router.delete(route('admin.segnalazioni.destroy', { id: String(id) }), {
    preserveScroll: true,
    preserveState: true,
    only: ['flash', 'stats', 'segnalazioni'],
    onSuccess: () => {
      removeSegnalazione(id)
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
  <DropdownMenu
    v-if="hasPermission([
      Permission.EDIT_SEGNALAZIONI,
      Permission.EDIT_OWN_SEGNALAZIONI,
      Permission.DELETE_SEGNALAZIONI
    ])"
  >
    <DropdownMenuTrigger as-child>
      <Button variant="ghost" class="w-8 h-8 p-0" aria-label="Apri menu azioni">
        <MoreHorizontal class="w-4 h-4" />
      </Button>
    </DropdownMenuTrigger>
    <DropdownMenuContent align="end">
      <DropdownMenuLabel>Azioni</DropdownMenuLabel>

      <DropdownMenuItem
        v-if="hasPermission([Permission.EDIT_SEGNALAZIONI, Permission.EDIT_OWN_SEGNALAZIONI])"
      >
        <Link
          :href="route(generateRoute('segnalazioni.edit'), { id: segnalazione.id })"
          preserve-state
          class="flex items-center gap-2"
        >
          <FilePenLine class="w-4 h-4 text-xs" />
          Modifica
        </Link>
      </DropdownMenuItem>

      <DropdownMenuItem
        v-if="hasPermission([Permission.DELETE_SEGNALAZIONI])"
        @click="handleDelete(segnalazione)"
      >
        <Trash2 class="w-4 h-4 text-xs" />
        Elimina
      </DropdownMenuItem>
    </DropdownMenuContent>
  </DropdownMenu>

  <ConfirmDialog
    v-model:modelValue="isAlertOpen"
    title="Sei sicuro di volere eliminare questa segnalazione?"
    description="Questa azione non è reversibile. Eliminerà la segnalazione e tutti i dati ad essa associati."
    :loading="isDeleting"
    @confirm="deleteSegnalazione"
  />

</template>
