<script setup lang="ts">

import { ref, computed } from "vue";
import { usePage, router } from "@inertiajs/vue3";
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Trash2 } from "lucide-vue-next";
import { Badge } from '@/components/ui/badge';
import { AlertDialog, AlertDialogContent, AlertDialogHeader, AlertDialogTitle, AlertDialogDescription, AlertDialogFooter, AlertDialogAction, AlertDialogCancel } from '@/components/ui/alert-dialog';
import { usePermission } from "@/composables/permissions";
import { Permission }  from "@/enums/Permission";
import type { Auth } from '@/types';
import type { Documento } from '@/types/documenti';

const { generateRoute, hasPermission } = usePermission();

defineProps<{
  documento: Documento;
}>();

const page = usePage<{ auth: Auth }>();
const auth = computed(() => page.props.auth);
const expandedIds = ref<Set<number>>(new Set());
const documentoID = ref<number | null>(null);
const isAlertOpen = ref(false);
const errorState = ref<string | null>(null);

const isExpanded = (id: number) => expandedIds.value.has(id);

const toggleExpanded = (id: number) => {
  if (expandedIds.value.has(id)) {
    expandedIds.value.delete(id);
  } else {
    expandedIds.value.add(id);
  }
};

const truncate = (text: string, length: number = 50) => {
  return text.length > length ? `${text.slice(0, length)}...` : text;
};

const truncatedName = (name: string, length: number = 80) => {
  return name.length > length ? `${name.slice(0, length)}...` : name;
};

function handleDelete(documento: Documento) {
  documentoID.value = documento.id;
  isAlertOpen.value = true;
}

async function confirmDelete() {
  const id = documentoID.value;

  if (!id) {
    isAlertOpen.value = false;
    return;
  }

  try {

    router.delete(route(generateRoute('documenti.destroy'), { id }), {
      preserveScroll: true,
      preserveState: true,
      only: ['stats', 'documenti', 'flash'],
      onSuccess: () => {
        isAlertOpen.value = false;
      },
      onError: () => {
        errorState.value = "Errore durante l'eliminazione. Riprova.";
      }
    });

  } catch {
    errorState.value = "Errore durante l'eliminazione. Riprova.";
  } finally {
    isAlertOpen.value = false;
  }
}

</script>

<template>
  <Card>
  <CardHeader class="p-2">
  <div class="flex items-center justify-between">
    <CardTitle class="text-base font-medium">
      <a
        :href="route(generateRoute('documenti.download'), { id: documento.id })"
        class="text-primary hover:text-muted-foreground"
      >
        {{ truncatedName(documento.name, 20) }}
      </a>
    </CardTitle>

    <div class="flex items-center gap-2">
      <Badge
        variant="outline"
        class="rounded-md text-xs"
      >
        {{ documento.mime_type }}
      </Badge>

      <button
        v-if="hasPermission([Permission.DELETE_ARCHIVE_DOCUMENTS]) || 
              (hasPermission([Permission.DELETE_OWN_ARCHIVE_DOCUMENTS]) && 
              documento.created_by.user.id === auth.user.id)"
        @click="handleDelete(documento)"
        class="text-gray-700 hover:text-red-600 transition-colors"
        title="Elimina"
      >
        <Trash2 class="w-3 h-3" />
      </button>
    </div>
  </div>

    <div class="text-xs text-gray-600 font-light">
      <span>Inviato {{ documento.created_at }} da {{ documento.created_by.user.name }}</span>
    </div>
  </CardHeader>

    <CardContent class="p-2">
      <div class="text-sm text-muted-foreground">
 
        <span class="mt-1 text-gray-600 py-1">
          {{ isExpanded(Number(documento.id)) ? documento.description : truncate(documento.description, 50) }}
        </span>
        <button
          v-if="documento.description.length > 50"
          class="text-xs font-semibold text-gray-500 ml-1"
          @click="toggleExpanded(Number(documento.id))"
        >
          {{ isExpanded(Number(documento.id)) ? 'Mostra meno' : 'Mostra tutto' }}
        </button>
       
      </div>
    </CardContent>
  </Card>

     <!-- Delete confirmation dialog -->
    <AlertDialog v-model:open="isAlertOpen">
      <AlertDialogContent>
        <AlertDialogHeader>
          <AlertDialogTitle>Sei sicuro di volere eliminare questo documento?</AlertDialogTitle>
          <AlertDialogDescription>
            Questa azione non è reversibile. Eliminerà il documento e tutti i dati ad esso associati.
          </AlertDialogDescription>
        </AlertDialogHeader>
        <AlertDialogFooter>
          <AlertDialogCancel>Annulla</AlertDialogCancel>
          <AlertDialogAction @click="confirmDelete">Conferma</AlertDialogAction>
        </AlertDialogFooter>
      </AlertDialogContent>
    </AlertDialog>

</template>
