<script setup lang="ts">

import { ref } from 'vue';
import { router, useForm } from "@inertiajs/vue3";
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuLabel, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import { Sheet, SheetContent, SheetHeader, SheetTitle, SheetDescription } from "@/components/ui/sheet";
import { Trash2, FilePenLine, MoreHorizontal } from 'lucide-vue-next';
import { usePermission } from '@/composables/permissions';
import { trans } from 'laravel-vue-i18n';
import type { Categoria } from '@/types/categorie';

const props = defineProps<{
  categoria: Categoria;
}>();  

const form = useForm({
  name:  props.categoria.name ?? '',
  description: props.categoria.description ?? '',
})

const categoriaID = ref<number | null>(null)
const isEditCategorySheetOpen = ref(false)
const isAlertOpen = ref(false)
const isDropdownOpen = ref(false)
const isDeleting = ref(false)

const { generateRoute } = usePermission()

function handleEdit(categoria: Categoria) {
  categoriaID.value = categoria.id
  isEditCategorySheetOpen.value = false
  setTimeout(() => {
    isEditCategorySheetOpen.value = true
  }, 200)
}

function handleDelete(categoria: Categoria) {
  categoriaID.value = categoria.id
  isDropdownOpen.value = false
  setTimeout(() => {
    isAlertOpen.value = true
  }, 200)
}

function closeModal() {
  categoriaID.value = null
  isAlertOpen.value = false
  isDropdownOpen.value = false
}

function deleteCategoria() {
  if (categoriaID.value === null || isDeleting.value) return

  const id = categoriaID.value
  isDeleting.value = true

  router.delete(route(generateRoute('categorie.destroy'), { id: String(id) }), {
    preserveScroll: true,
    preserveState: true,
    only: ['flash', 'categorie'],
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

const editCategory = () => {
  const id = categoriaID.value

  form.put(route(generateRoute('categorie.update'), { id: String(id) }), {
    preserveScroll: true,
    onSuccess: () => {
      form.reset()
      isEditCategorySheetOpen.value = false 
    }
  })
}

</script>

<template>
  <DropdownMenu>
    <DropdownMenuTrigger as-child>
      <Button variant="ghost" class="w-8 h-8 p-0" :aria-label="trans('documenti.table.categories.actions')">
        <MoreHorizontal class="w-4 h-4" />
      </Button>
    </DropdownMenuTrigger>

    <DropdownMenuContent align="end">
      <DropdownMenuLabel>{{ trans('documenti.table.categories.actions') }}</DropdownMenuLabel>

      <DropdownMenuItem
        @click="handleEdit(categoria)"
      >

      <FilePenLine class="w-4 h-4 text-xs" />
        {{ trans('documenti.actions.categories.edit_category') }}
      </DropdownMenuItem>

      <DropdownMenuItem
        @click="handleDelete(categoria)"
      >
        <Trash2 class="w-4 h-4 text-xs" />
        {{ trans('documenti.actions.categories.delete_category') }}
      </DropdownMenuItem>
    </DropdownMenuContent>
  </DropdownMenu>

  <ConfirmDialog
    v-model:modelValue="isAlertOpen"
    :title="trans('documenti.dialogs.categories.delete_category_title')"
    :description="trans('documenti.dialogs.categories.delete_category_description')"
    :loading="isDeleting"
    @confirm="deleteCategoria"
  />

  <Sheet v-model:open="isEditCategorySheetOpen">
    <SheetContent side="right" class="p-6">
      <SheetHeader class="mt-4 p-0">
        <SheetTitle>

          {{ 
            trans('documenti.header.categories.edit_category_title', {
              category: props.categoria.name.toLowerCase()
            }) 
          }}

        </SheetTitle>
        <SheetDescription>
          {{ trans('documenti.header.categories.edit_category_description') }}
        </SheetDescription>
      </SheetHeader>

      <form @submit.prevent="editCategory" class="mt-6 space-y-4">
      <!-- Name -->
        <div>
          <Label for="new-category-name">{{ trans('documenti.label.categories.category_name') }}</Label>
          <Input
            id="new-category-name"
            v-model="form.name"
            :class="{ 'border-red-500': form.errors.name }"
            :placeholder="trans('documenti.placeholder.categories.category_name')"
            class="w-full mt-1"
          />
          <p v-if="form.errors.name" class="text-red-500 text-sm mt-1">{{ form.errors.name }}</p>
        </div>

        <!-- Description -->
        <div>
          <Label for="new-category-description">{{ trans('documenti.label.categories.category_description') }}</Label>
          <Textarea
            id="new-category-description"
            v-model="form.description"
            :class="{ 'border-red-500': form.errors.description }"
            :placeholder="trans('documenti.placeholder.categories.category_description')"
            class="w-full mt-1 min-h-[200px]"
          />
          <p v-if="form.errors.description" class="text-red-500 text-sm mt-1">{{ form.errors.description }}</p>
        </div>

        <div class="flex justify-end">
            <Button type="submit">
              {{ trans('documenti.action.categories.save_category') }}
            </Button>
        </div>
      </form>
    </SheetContent>
  </Sheet>

</template>
