<script setup lang="ts">

import { ref, computed } from "vue";
import { usePage, router, Link } from "@inertiajs/vue3";
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Trash2, Pencil } from "lucide-vue-next";
import { Badge } from '@/components/ui/badge';
import ConfirmDialog from '@/components/ConfirmDialog.vue'
import { usePermission } from "@/composables/permissions";
import { useDocumenti } from '@/composables/useDocumenti'
import { Permission }  from "@/enums/Permission";
import { trans } from 'laravel-vue-i18n';
import type { Auth } from '@/types';
import type { Documento } from '@/types/documenti';

const { generateRoute, hasPermission } = usePermission();
const { removeDocumento } = useDocumenti()

defineProps<{
  documento: Documento;
}>();

const page = usePage<{ auth: Auth }>();
const auth = computed(() => page.props.auth);
const expandedIds = ref<Set<number>>(new Set());
const documentoID = ref<number | null>(null);
const isAlertOpen = ref(false);
const isDropdownOpen = ref(false)
const isDeleting = ref(false)

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
  documentoID.value = documento.id
  isDropdownOpen.value = false
  setTimeout(() => {
    isAlertOpen.value = true
  }, 200)
}

function closeModal() {
  documentoID.value = null
  isAlertOpen.value = false
  isDropdownOpen.value = false
}

function deleteDocumento() {
  if (documentoID.value === null || isDeleting.value) return

  const id = documentoID.value
  isDeleting.value = true

  router.delete(route(generateRoute('documenti.destroy'), { id: String(id) }), {
    preserveScroll: true,
    preserveState: true,
    only: ['flash', 'stats', 'documenti'],
    onSuccess: () => {
      removeDocumento(id)
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

      <Link
        v-if="hasPermission([Permission.EDIT_ARCHIVE_DOCUMENTS]) || 
              (hasPermission([Permission.EDIT_OWN_ARCHIVE_DOCUMENTS]) && 
              documento.created_by.user.id === auth.user.id)"
        :href="route(generateRoute('documenti.edit'), { id: documento.id })"
        class="text-gray-700 hover:text-blue-600 transition-colors"
        :title="trans('documenti.actions.edit_document')"
      >
        <Pencil class="w-3 h-3" />
      </Link>

      <button
        v-if="hasPermission([Permission.DELETE_ARCHIVE_DOCUMENTS]) || 
              (hasPermission([Permission.DELETE_OWN_ARCHIVE_DOCUMENTS]) && 
              documento.created_by.user.id === auth.user.id)"
        @click="handleDelete(documento)"
        class="text-gray-700 hover:text-red-600 transition-colors"
        :title="trans('documenti.actions.delete_document')"
      >
        <Trash2 class="w-3 h-3" />
      </button>
    </div>
  </div>

    <div class="text-xs text-gray-600 font-light">
      <span>
        {{ 
          trans('documenti.visibility.sent_on_by', { 
              date: documento.created_at, 
              name: documento.created_by.user.name,
          }) 
        }}
      </span>
    </div>
  </CardHeader>

    <CardContent class="p-2">
      <div class="text-sm text-muted-foreground">
 
        <span class="mt-1 text-gray-600 py-1">
          {{ 
            isExpanded(Number(documento.id)) 
            ? documento.description 
            : truncate(documento.description, 50) 
          }}
        </span>
        <button
          v-if="documento.description.length > 50"
          class="text-xs font-semibold text-gray-500 ml-1"
          @click="toggleExpanded(Number(documento.id))"
        >
          {{ 
            isExpanded(Number(documento.id))
            ? trans('documenti.actions.show_less')
            : trans('documenti.actions.show_more')
          }}
        </button>
       
      </div>
    </CardContent>
  </Card>

  <ConfirmDialog
    v-model:modelValue="isAlertOpen"
    :title="trans('documenti.dialogs.delete_document_title')"
    :description="trans('documenti.dialogs.delete_document_description')"
    :loading="isDeleting"
    @confirm="deleteDocumento"
  />

</template>
