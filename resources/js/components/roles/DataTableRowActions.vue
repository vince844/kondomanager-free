<script setup lang="ts">
import { ref, computed } from 'vue'
import { router } from "@inertiajs/vue3";
import { Button } from '@/components/ui/button'
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuLabel, DropdownMenuTrigger } from '@/components/ui/dropdown-menu'
import { MoreHorizontal } from 'lucide-vue-next'
import { Trash2, FilePenLine } from 'lucide-vue-next'
import { AlertDialog, AlertDialogAction, AlertDialogCancel, AlertDialogContent, AlertDialogDescription, AlertDialogFooter, AlertDialogHeader, AlertDialogTitle } from "@/components/ui/alert-dialog"
import { trans } from 'laravel-vue-i18n'
import type { Role } from '@/types/roles';

interface Props {
  role: Role
}

const props = defineProps<Props>()

const roleID = ref('');
const isAlertOpen = ref(false)
const isProtectedDialogOpen = ref(false)
const isRegistrationDialogOpen = ref(false)
const isDropdownOpen = ref(false)
const protectedDialogType = ref<'edit' | 'delete'>('edit')

// Computed properties
const userCount = computed(() => props.role.users_count || 0)
const isProtected = computed(() => props.role.is_protected || false)
const isRegistrationRole = computed(() => props.role.is_registration_role || false)

function handleDelete(role: Role) {
  roleID.value = role.id;
  isDropdownOpen.value = false 
  
  setTimeout(() => {
    if (isProtected.value) {
      protectedDialogType.value = 'delete'
      isProtectedDialogOpen.value = true
    } else if (isRegistrationRole.value) {
      // Il ruolo dei nuovi iscritti il server non lo elimina (Coda 258): la finestra lo dice prima dell'invio,
      // invece della conferma dell'eliminazione.
      isRegistrationDialogOpen.value = true
    } else {
      isAlertOpen.value = true 
    }
  }, 200) 
}

function handleEdit(role: Role) {
  isDropdownOpen.value = false 
  
  setTimeout(() => {
    if (isProtected.value) {
      protectedDialogType.value = 'edit'
      isProtectedDialogOpen.value = true
    } else {
      router.get(route('ruoli.edit', { id: role.id}))
    }
  }, 200) 
}

const closeModal = () => {
  isDropdownOpen.value = false
}

const deleteRole = () => {
  router.delete(route('ruoli.destroy', { id: roleID.value }),{
    preserveScroll: true,
    onSuccess: () => closeModal()
  })
}

// Computed per i messaggi dinamici
const protectedDialogTitle = computed(() => {
  return protectedDialogType.value === 'edit' 
    ? 'Modifica ruolo protetto'
    : 'Eliminazione ruolo protetto'
})

const protectedDialogAction = computed(() => {
  return protectedDialogType.value === 'edit' ? 'modificato' : 'eliminato'
})

const protectedDialogDescription = computed(() => {
  const base = `Il ruolo <strong class="font-semibold">"${props.role.name}"</strong> è un ruolo di sistema protetto e non può essere <strong>${protectedDialogAction.value}</strong>.`
  
  if (protectedDialogType.value === 'edit') {
    return base + ' I ruoli di sistema sono essenziali per garantire la stabilità e sicurezza dell\'applicazione.'
  } else {
    return base + ' Questo ruolo è critico per il funzionamento del sistema.'
  }
})
</script>

<template>
  <DropdownMenu>
    <DropdownMenuTrigger as-child>
      <Button variant="ghost" class="w-8 h-8 p-0">
        <span class="sr-only">Azioni</span>
        <MoreHorizontal class="w-4 h-4" />
      </Button>
    </DropdownMenuTrigger>
    <DropdownMenuContent align="end">
      <DropdownMenuLabel>Azioni</DropdownMenuLabel>
      
      <DropdownMenuItem @click="handleEdit(role)">
        <FilePenLine class="w-4 h-4 text-xs" />
        Modifica 
      </DropdownMenuItem>

      <DropdownMenuItem @click="handleDelete(role)">
        <Trash2 class="w-4 h-4 text-xs" />
        Elimina 
      </DropdownMenuItem>
    </DropdownMenuContent>
  </DropdownMenu>

  <!-- Dialog normale per eliminazione -->
  <AlertDialog v-model:open="isAlertOpen">
    <AlertDialogContent>
      <AlertDialogHeader>
        <AlertDialogTitle>
          Sei sicuro di volere eliminare il ruolo <strong class="font-semibold">"{{ role.name }}"</strong>?
        </AlertDialogTitle>
        <AlertDialogDescription>
          <!-- Come fa il server (`RoleController::destroy`): il ruolo si toglie a tutti i suoi utenti, e «utente» lo
               riceve solo chi resta senza ruoli. `userCount` conta tutti gli utenti del ruolo, anche chi ne ha due. -->
          <div v-if="userCount === 1" class="mt-2">
            Il ruolo <strong class="font-semibold">"{{ role.name }}"</strong> ha
            <strong class="font-semibold">1</strong> utente associato: lo perderà, e se non ha un altro ruolo
            riceverà il ruolo «utente».
          </div>
          <div v-else-if="userCount > 1" class="mt-2">
            Il ruolo <strong class="font-semibold">"{{ role.name }}"</strong> ha
            <strong class="font-semibold">{{ userCount }}</strong> utenti associati: lo perderanno, e chi non ha un
            altro ruolo riceverà il ruolo «utente».
          </div>
          <div v-else class="mt-2">
            Questa azione non è reversibile. Eliminerà il ruolo 
            <strong class="font-semibold">"{{ role.name }}"</strong> e tutti i dati ad esso associati.
          </div>
        </AlertDialogDescription>
      </AlertDialogHeader>
      <AlertDialogFooter>
        <AlertDialogCancel>Annulla</AlertDialogCancel>
        <AlertDialogAction @click="deleteRole">Elimina</AlertDialogAction>
      </AlertDialogFooter>
    </AlertDialogContent>
  </AlertDialog>

  <!-- Il ruolo dei nuovi iscritti: il nome è testo, non HTML (lo sceglie chi crea il ruolo) -->
  <AlertDialog v-model:open="isRegistrationDialogOpen">
    <AlertDialogContent>
      <AlertDialogHeader>
        <AlertDialogTitle>
          {{ trans('ruoli.registration_role_dialog_title') }}
        </AlertDialogTitle>
        <AlertDialogDescription>
          {{ trans('ruoli.cannot_delete_registration_role', { role: role.name }) }}
        </AlertDialogDescription>
      </AlertDialogHeader>
      <AlertDialogFooter>
        <AlertDialogAction @click="isRegistrationDialogOpen = false">{{ trans('ruoli.registration_role_dialog_close') }}</AlertDialogAction>
      </AlertDialogFooter>
    </AlertDialogContent>
  </AlertDialog>

  <!-- Dialog riutilizzabile per ruoli protetti -->
  <AlertDialog v-model:open="isProtectedDialogOpen">
    <AlertDialogContent>
      <AlertDialogHeader>
        <AlertDialogTitle class="text-destructive">
          {{ protectedDialogTitle }}
        </AlertDialogTitle>
        <AlertDialogDescription>
          <span v-html="protectedDialogDescription" />
        </AlertDialogDescription>
      </AlertDialogHeader>
      <AlertDialogDescription class="text-sm text-muted-foreground">
        <div v-if="protectedDialogType === 'edit'" class="mt-2">
          <strong>Informazioni:</strong> I ruoli protetti mantengono le configurazioni di sicurezza e permessi essenziali per il corretto funzionamento dell'applicazione.
        </div>
        <div v-else class="mt-2">
          <strong>Informazioni:</strong> L'eliminazione di ruoli di sistema potrebbe compromettere la stabilità e sicurezza della piattaforma.
        </div>
      </AlertDialogDescription>
      <AlertDialogFooter>
        <AlertDialogAction @click="isProtectedDialogOpen = false">Ho capito</AlertDialogAction>
      </AlertDialogFooter>
    </AlertDialogContent>
  </AlertDialog>
</template>