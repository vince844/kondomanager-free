<script setup lang="ts">

import { ref, computed } from 'vue'
import { router } from "@inertiajs/vue3"
import { Button } from '@/components/ui/button'
import { RadioGroup, RadioGroupItem } from '@/components/ui/radio-group'
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuLabel,
  DropdownMenuTrigger
} from '@/components/ui/dropdown-menu'
import {
  AlertDialog,
  AlertDialogAction,
  AlertDialogCancel,
  AlertDialogContent,
  AlertDialogDescription,
  AlertDialogFooter,
  AlertDialogHeader,
  AlertDialogTitle
} from '@/components/ui/alert-dialog'
import { Trash2, FilePenLine, MoreHorizontal } from 'lucide-vue-next'
import { usePermission } from "@/composables/permissions"
import { Permission } from '@/enums/Permission'
import type { Evento } from '@/types/eventi'

const props = defineProps<{ evento: Evento }>()

const { hasPermission, generateRoute } = usePermission()

const deleteMode = ref<'only_this' | 'this_and_future' | 'all'>('only_this')
const isRecurring = computed(() => !!props.evento.recurrence_id)
const isEditAlertOpen = ref(false)
const editMode = ref<'only_this' | 'all'>('all')
const occurrenceDate = ref<string | null>(null)
const eventoID = ref<number | null>(null)
const isAlertOpen = ref(false)
const isDropdownOpen = ref(false)
const isDeleting = ref(false)

function handleDelete(evento: Evento) {
  eventoID.value = evento.id
  occurrenceDate.value = evento.occurs
  ? typeof evento.occurs === 'string'
    ? evento.occurs
    : evento.occurs.toISOString()
  : null;
  isDropdownOpen.value = false
  setTimeout(() => {
    isAlertOpen.value = true
  }, 200)
}

function closeModal() {
  eventoID.value = null
  isAlertOpen.value = false
  isDropdownOpen.value = false
}

function deleteEvento() {
  
  if (eventoID.value === null || isDeleting.value) return

  const id = eventoID.value
  
  isDeleting.value = true

  router.delete(route(generateRoute('eventi.destroy'), { evento: String(id) }), {
    preserveScroll: true,
    preserveState: true,
    only: ['flash', 'eventi', 'stats'],
    data: {
      mode: deleteMode.value, 
      occurrence_date: occurrenceDate.value,
    },
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

function handleEdit(evento: Evento) {
  eventoID.value = evento.id
  occurrenceDate.value = evento.occurs
    ? typeof evento.occurs === 'string'
      ? evento.occurs
      : evento.occurs.toISOString()
    : null
  isDropdownOpen.value = false
  
  if (evento.recurrence_id) {
    setTimeout(() => {
      isEditAlertOpen.value = true
    }, 200)
  } else {
    goToEdit(evento)
  }
}

async function editEvento() {
  if (eventoID.value === null) return
  
  try {
    const routeParams = { evento: eventoID.value }
    const queryParams = { 
      mode: editMode.value,
      occurrence_date: occurrenceDate.value
    }
    
    router.visit(
      route(generateRoute('eventi.edit'), routeParams),
      {
        data: queryParams,
        preserveScroll: true,
        onSuccess: () => {
          closeEditModal()
        },
        onError: (errors) => {
          console.error('Edit error:', errors)
        }
      }
    )
  } catch (error) {
    console.error('Navigation error:', error)
  }
}

function closeEditModal() {
  isEditAlertOpen.value = false
  eventoID.value = null
  occurrenceDate.value = null
  isDropdownOpen.value = false
}

function goToEdit(evento: Evento, e?: Event) {
  if (e) {
    e.preventDefault()
    e.stopPropagation()
  }
  
  router.visit(route(generateRoute('eventi.edit'), { evento: evento.id }))
}

</script>

<template>
  <DropdownMenu
   v-if="hasPermission([
      Permission.EDIT_EVENTS,
      Permission.EDIT_OWN_EVENTS,
      Permission.DELETE_EVENTS
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
       v-if="hasPermission([Permission.EDIT_EVENTS, Permission.EDIT_OWN_EVENTS])"
       @click="handleEdit(evento)"
      >

        <FilePenLine class="w-4 h-4 text-xs" />
        Modifica
        
      </DropdownMenuItem>

      <DropdownMenuItem
       v-if="hasPermission([Permission.DELETE_EVENTS])"
       @click="handleDelete(evento)"
      >
        <Trash2 class="w-4 h-4 text-xs" />
        Elimina
      </DropdownMenuItem>
    </DropdownMenuContent>
  </DropdownMenu>

  <AlertDialog v-model:open="isAlertOpen">
    <AlertDialogContent>
      <AlertDialogHeader>
        <AlertDialogTitle>Sei sicuro di voler eliminare questo evento?</AlertDialogTitle>
        <AlertDialogDescription>
          <template v-if="isRecurring">
            Questo evento fa parte di una serie ricorrente. Scegli cosa vuoi eliminare:
            <RadioGroup v-model="deleteMode" class="mt-4 space-y-2">
              <div class="flex items-center space-x-2">
                <RadioGroupItem id="only_this" value="only_this" />
                <label for="only_this" class="text-sm">Solo questo evento</label>
              </div>
              <div class="flex items-center space-x-2">
                <RadioGroupItem id="this_and_future" value="this_and_future" />
                <label for="this_and_future" class="text-sm">Questo e tutti i futuri</label>
              </div>
              <div class="flex items-center space-x-2">
                <RadioGroupItem id="all" value="all" />
                <label for="all" class="text-sm">Tutta la serie</label>
              </div>
            </RadioGroup>
          </template>
          <template v-else>
            Questa azione non è reversibile. Eliminerà l'evento definitivamente.
          </template>
        </AlertDialogDescription>
      </AlertDialogHeader>
      <AlertDialogFooter>
        <AlertDialogCancel @click="closeModal">Annulla</AlertDialogCancel>
        <AlertDialogAction :disabled="isDeleting" @click="deleteEvento">
          <span v-if="isDeleting">Eliminazione...</span>
          <span v-else>Continua</span>
        </AlertDialogAction>
      </AlertDialogFooter>
    </AlertDialogContent>
  </AlertDialog>

  <AlertDialog v-model:open="isEditAlertOpen">
    <AlertDialogContent>
      <AlertDialogHeader>
        <AlertDialogTitle>Modifica evento ricorrente</AlertDialogTitle>
        <AlertDialogDescription>
          Questo evento fa parte di una serie ricorrente. Cosa vuoi modificare?
          <RadioGroup v-model="editMode" class="mt-4 space-y-2">
            <div class="flex items-center space-x-2">
              <RadioGroupItem id="edit_only_this" value="only_this" />
              <label for="edit_only_this" class="text-sm">Solo questo evento</label>
            </div>
            <div class="flex items-center space-x-2">
              <RadioGroupItem id="edit_all" value="all" />
              <label for="edit_all" class="text-sm">Tutta la serie</label>
            </div>
          </RadioGroup>
        </AlertDialogDescription>
      </AlertDialogHeader>
      <AlertDialogFooter>
        <AlertDialogCancel @click="closeEditModal">Annulla</AlertDialogCancel>
        <AlertDialogAction @click="editEvento">
          Continua
        </AlertDialogAction>
      </AlertDialogFooter>
    </AlertDialogContent>
  </AlertDialog>

</template>
