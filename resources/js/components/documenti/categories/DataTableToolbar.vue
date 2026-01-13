<script setup lang="ts">

import { ref } from 'vue';
import { watchDebounced } from '@vueuse/core';
import { router, Link, useForm } from '@inertiajs/vue3';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { Plus, List } from 'lucide-vue-next';
import { usePermission } from "@/composables/permissions";
import { Sheet, SheetContent, SheetHeader, SheetTitle, SheetDescription } from "@/components/ui/sheet";
import { trans } from 'laravel-vue-i18n';
import type { Table } from '@tanstack/vue-table';
import type { Categoria } from '@/types/categorie';

const { generateRoute } = usePermission();
const nameFilter = ref('')
const isNewCategorySheetOpen = ref(false)

const { table } = defineProps<{
  table: Table<Categoria>
}>()

const form = useForm({
  name: '',
  description: ''
})

function handleNewCategory() {
  isNewCategorySheetOpen.value = false
  setTimeout(() => {
    isNewCategorySheetOpen.value = true
  }, 200)
}

const createCategory = () => {
  form.post(route(generateRoute('categorie.store')), {
    preserveScroll: true,
    onSuccess: () => {
      form.reset()
      isNewCategorySheetOpen.value = false 
    }
  })
}

watchDebounced(
  [nameFilter],
  ([name]) => {
    const params: Record<string, any> = { page: 1 }

    if (name) params.name = name

    router.get(
      route(generateRoute('categorie.index')),
      params,
      {
        preserveState: true,
        replace: true,
        preserveScroll: true,
        onSuccess: () => {
          if (!name) {
            table.reset()
          }
        }
      }
    )
  },
  { debounce: 300 }
)

</script>

<template>
  <div class="flex items-center justify-between w-full mb-3">
    <div class="flex items-center space-x-2">
      <!-- Subject Filter -->
      <Input
        :placeholder="trans('documenti.table.categories.filter_by')"
        v-model="nameFilter"
        class="h-8 w-[150px] lg:w-[250px]"
      />

    </div>
    
    <div class="flex items-center space-x-2">

      <button
        type="button"
        @click="handleNewCategory"
        class="inline-flex items-center gap-2 rounded-md bg-primary px-3 py-1.5 text-sm font-medium text-white hover:bg-primary/90 order-last lg:order-none lg:ml-auto"
      >
        <Plus class="w-4 h-4" />
        <span>{{ trans('documenti.actions.categories.new_category') }}</span>
      </button>

      <Link 
        as="button"
        :href="route(generateRoute('documenti.index'))" 
        class="inline-flex items-center gap-2 rounded-md bg-primary px-3 py-1.5 text-sm font-medium text-white hover:bg-primary/90 order-last lg:order-none lg:ml-auto"
      >
        <List class="w-4 h-4" />
        <span>{{ trans('documenti.actions.categories.list_documents') }}</span>
      </Link>
    </div>

  </div>

  <Sheet v-model:open="isNewCategorySheetOpen">
    <SheetContent side="right" class="p-6">
      <SheetHeader class="mt-4 p-0">
        <SheetTitle>{{ trans('documenti.header.categories.new_category_title') }}</SheetTitle>
        <SheetDescription>
          {{ trans('documenti.header.categories.new_category_description') }}
        </SheetDescription>
      </SheetHeader>

      <form @submit.prevent="createCategory" class="mt-6 space-y-4">
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
            <Button type="submit">{{ trans('documenti.actions.categories.save_category') }}</Button>
        </div>
      </form>
    </SheetContent>
  </Sheet>

</template>
