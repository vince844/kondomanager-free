<script setup lang="ts">
  
import { Link, Head, useForm } from '@inertiajs/vue3';
import { ref, watch, type Ref } from 'vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { Button } from '@/components/ui/button';
import { List, Plus, LoaderCircle, UploadCloud, Info, FileText, X } from 'lucide-vue-next';
import Heading from '@/components/Heading.vue';
import { Label } from '@/components/ui/label';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import InputError from '@/components/InputError.vue';
import { HoverCard, HoverCardContent, HoverCardTrigger } from '@/components/ui/hover-card';
import { Sheet, SheetTrigger, SheetContent, SheetHeader, SheetTitle, SheetDescription, SheetClose } from "@/components/ui/sheet";
import { Empty, EmptyDescription, EmptyHeader, EmptyMedia, EmptyTitle } from '@/components/ui/empty'
import { formatBytes } from '@/utils/formatBytes'; 
import { Item } from "@/components/ui/item"
import { useToast } from '@/components/ui/toast';
import axios from 'axios';
import vSelect from "vue-select";
import { trans } from 'laravel-vue-i18n';
import { usePermission } from '@/composables/permissions';
import { publishedConstants } from '@/lib/documenti/constants';
import type { PublishedType } from '@/types/documenti';
import type { Building } from '@/types/buildings';
import type { Anagrafica } from '@/types/anagrafiche';
import type { Categoria } from '@/types/categorie';
import type { AdminDocumentForm } from '@/types/documenti';

const props = defineProps<{
  condomini: Building[];
  categories: Categoria[];
  anagrafiche: Anagrafica[];
}>()

const { generatePath } = usePermission()
const { toast } = useToast()

const file: Ref<File | null> = ref(null)
const newCategoryName = ref('')
const newCategoryDescription = ref('')
const localCategories = ref<Categoria[]>([...props.categories])
const anagraficheOptions = ref<Anagrafica[]>([]);

const form = useForm<AdminDocumentForm>({
  name: '',
  description: '',
  is_published: true,
  condomini_ids: [],
  category_id: null,
  file: null,
  anagrafiche: []
})

const handleFileChange = (e: Event): void => {
  const input = e.target as HTMLInputElement
  const selected = input.files?.[0] || null
  if (!selected) return
  file.value = selected
  form.file = selected
  form.clearErrors('file')
}

const removeFile = (): void => {
  file.value = null
  form.file = null
  form.clearErrors('file')
}

const onDrop = (e: DragEvent): void => {
  e.preventDefault()
  const droppedFile = e.dataTransfer?.files?.[0] || null
  if (droppedFile) {
    file.value = droppedFile
    form.file = droppedFile
    form.clearErrors('file')
  }
}

const createCategory = async (): Promise<void> => {
  if (!newCategoryName.value) return

  try {
    const response = await axios.post(route('admin.categorie.store'), {
      name: newCategoryName.value,
      description: newCategoryDescription.value
    })

    const newCat = response.data
    localCategories.value.push(newCat)
    form.category_id = newCat.id

    newCategoryName.value = ''
    newCategoryDescription.value = ''
    
    toast({
      title: 'Successo',
      description: 'Categoria creata con successo',
      variant: 'default',
    })

  } catch (error: any) {
    const backendMessage = error.response?.data?.error || 'Impossibile creare la categoria. Riprova più tardi.'

    toast({
      title: 'Errore',
      description: backendMessage,
      variant: 'destructive',
    })
  }
}

const fetchAnagrafiche = async (condomini_ids: number[]): Promise<void> => {
  try {
    const response = await axios.get(generatePath('fetch-anagrafiche'), {
      params: { condomini_ids },
    });

    form.anagrafiche = []; // clear selected items
    anagraficheOptions.value = response.data.map((item: { id: number, nome: string }) => ({
      id: item.id,
      nome: item.nome,
    }));
  } catch (error) {
    console.error('Error fetching anagrafiche:', error);
  }
};

watch(() => form.condomini_ids, (newIds: number[]) => {
  if (newIds.length > 0) {
    fetchAnagrafiche(newIds);
  } else {
    anagraficheOptions.value = [];
    form.anagrafiche = [];
  }
});

const submit = (): void => {
  form.post(route("admin.documenti.store"), {
    preserveScroll: true,
    onSuccess: () => {
      form.reset()
      file.value = null
    }
  })
}
</script>

<template>
  <Head :title="trans('documenti.header.new_document_head')" />

  <AppLayout >
    <div class="px-4 py-6">

      <Heading
        :title="trans('documenti.header.new_document_title')"
        :description="trans('documenti.header.new_document_description')"
      />

      <form @submit.prevent="submit" class="space-y-2">

        <!-- Action buttons -->
        <div class="flex flex-col lg:flex-row lg:justify-end gap-2 w-full">
          <Button :disabled="form.processing" class="h-8 w-full lg:w-auto">
            <Plus class="w-4 h-4" v-if="!form.processing" />
            <LoaderCircle v-if="form.processing" class="h-4 w-4 animate-spin" />
            {{ trans('documenti.actions.save_document') }}
          </Button>

          <Link
            as="button"
            :href="route('admin.documenti.index')"
            class="w-full lg:w-auto inline-flex items-center justify-center gap-2 rounded-md bg-primary px-3 py-1.5 text-sm font-medium text-white hover:bg-primary/90"
          >
            <List class="w-4 h-4" />
            <span>{{ trans('documenti.actions.list_documents') }}</span>
          </Link>
        </div>

        <!-- Two-column layout (3:1 ratio) -->
        <div class="grid grid-cols-1 lg:grid-cols-4 gap-3 ">

          <!-- Main Card (3/4 width) -->
          <div class="col-span-1 lg:col-span-3 mt-3">
            <div class="bg-white dark:bg-muted rounded shadow-sm p-3 space-y-4 border">
                
              <div class="mt-2 grid grid-cols-1 gap-y-6 gap-x-4 sm:grid-cols-6">
                <div class="sm:col-span-3">
                  <Label for="nome">{{ trans('documenti.label.name') }}</Label>
                  <Input 
                      id="name" 
                      class="mt-1 block w-full"
                      v-model="form.name" 
                      v-on:focus="form.clearErrors('name')"
                      :placeholder="trans('documenti.placeholder.name')" 
                  />
                  
                  <InputError :message="form.errors.name" />
                </div>
              </div> 

              <div class="mt-2 grid grid-cols-1 gap-y-6 gap-x-4 sm:grid-cols-6">
                <div class="sm:col-span-6">
                  <Label for="description">{{ trans('documenti.label.description') }}</Label>
                  <Textarea 
                      id="description" 
                      class="mt-1 block w-full min-h-[200px]"
                      v-model="form.description" 
                      v-on:focus="form.clearErrors('description')"
                      :placeholder="trans('documenti.placeholder.description')" 
                  />
                  
                  <InputError :message="form.errors.description" />
                </div>     
              </div> 
                
              <div class="mt-2 grid grid-cols-1 gap-y-6 gap-x-4 sm:grid-cols-6">
                <div class="sm:col-span-6">
                  <Label for="file-upload">{{ trans('documenti.label.select_document') }}</Label>

                  <label
                    for="file-upload"
                    @dragover.prevent
                    @drop="onDrop"
                    class="block cursor-pointer mt-2"
                  >
                    <Empty class="border border-dashed hover:bg-accent/10 transition">
                      <EmptyHeader>
                        <EmptyMedia variant="icon">
                          <UploadCloud class="w-8 h-8 text-muted-foreground" />
                        </EmptyMedia>
                        <EmptyTitle>{{ trans('documenti.dialogs.select_document_title') }}</EmptyTitle>
                        <EmptyDescription>
                          {{ trans('documenti.dialogs.select_document_description') }}
                          <div class="text-xs text-muted-foreground mt-1">
                            {{ trans('documenti.dialogs.document_supported_types') }}
                          </div>
                        </EmptyDescription>
                      </EmptyHeader>
                    </Empty>

                    <!-- input nascosto -->
                    <input
                      id="file-upload"
                      type="file"
                      class="hidden"
                      accept="application/pdf,image/*"
                      @change="handleFileChange"
                    />
                  </label>

                  <!-- Stato: file selezionato -->
                  <div v-if="file" class="mt-4">
                    <Item class="flex items-center justify-between border rounded-lg p-3 shadow-sm bg-card/60">
                      <div class="flex items-center gap-3">
                        <div class="w-10 h-10 flex items-center justify-center rounded-lg bg-accent/20">
                          <FileText class="w-5 h-5 text-muted-foreground" />
                        </div>
                        <div class="flex flex-col">
                          <span class="text-sm font-medium truncate max-w-[180px]">
                            {{ file.name }}
                          </span>
                          <span class="text-xs text-muted-foreground">
                            {{ formatBytes(file.size, undefined, true) }}
                          </span>
                        </div>
                      </div>
                        <Button 
                          variant="ghost" 
                          size="icon" 
                          @click="removeFile"
                          :title="trans('documenti.label.remove_document')"
                        >
                        <X class="w-4 h-4" />
                      </Button>
                    </Item>
                  </div>

                  <InputError :message="form.errors.file" />
                </div>
              </div>
            </div>
          </div>

          <!-- Side Card (1/4 width) -->
          <div class="col-span-1 mt-3">
            <div class="bg-white dark:bg-muted rounded shadow-sm p-3 border">

              <div class="grid grid-cols-1 sm:grid-cols-6">
                <div class="sm:col-span-6">
                  <div class="flex items-center text-sm font-medium mb-1 gap-x-2">
                    <Label for="stato">{{ trans('documenti.label.visibility') }}</Label>
                    <HoverCard>
                      <HoverCardTrigger as-child>
                        <button type="button" class="cursor-pointer">
                          <Info class="w-4 h-4 text-muted-foreground" />
                        </button>
                      </HoverCardTrigger>
                      <HoverCardContent class="w-80">
                        <div class="flex justify-between space-x-4">
                          <div class="space-y-1">
                            <h4 class="text-sm font-semibold">
                              {{ trans('documenti.label.visibility') }}
                            </h4>
                            <p class="text-sm">
                              {{ trans('documenti.tooltip.visibility') }}
                            </p>
                          </div>
                        </div>
                      </HoverCardContent>
                    </HoverCard>
                  </div>

                  <v-select 
                    :options="publishedConstants" 
                    label="label" 
                    v-model="form.is_published"
                    :placeholder="trans('documenti.placeholder.visibility')"
                    @update:modelValue="form.clearErrors('is_published')" 
                    :reduce="(is_published: PublishedType) => is_published.value"
                  >
                    <template #option="{ label, icon }">
                      <div class="flex items-center gap-2">
                          <component :is="icon" class="w-4 h-4 text-muted-foreground" />
                          <span>{{ trans(label) }}</span> 
                      </div>
                    </template>

                    <template #selected-option="{ label, icon }">
                      <div class="flex items-center gap-2">
                          <component :is="icon" class="w-4 h-4 text-muted-foreground" />
                          <span>{{ trans(label) }}</span>
                      </div>
                    </template>
                  
                  </v-select>

                  <InputError :message="form.errors.is_published" />
                </div>
              </div>

              <div class="pt-3 grid grid-cols-1 sm:grid-cols-6">
                <div class="sm:col-span-6 space-y-1">
                  <div class="flex items-center gap-x-2 text-sm font-medium mb-1">
                    <Label for="stato">{{ trans('documenti.label.category') }}</Label>
                    <HoverCard>
                      <HoverCardTrigger as-child>
                        <button type="button" class="cursor-pointer">
                          <Info class="w-4 h-4 text-muted-foreground" />
                        </button>
                      </HoverCardTrigger>
                      <HoverCardContent class="w-80">
                        <div class="space-y-1">
                          <h4 class="text-sm font-semibold">{{ trans('documenti.label.category') }}</h4>
                          <p class="text-sm">
                            {{ trans('documenti.tooltip.category') }}
                          </p>
                        </div>
                      </HoverCardContent>
                    </HoverCard>
                  </div>

                  <div class="flex items-center gap-2">
                    <v-select
                      :options="localCategories"
                      label="name"
                      v-model="form.category_id"
                      :reduce="(option: Categoria) => option.id"
                      :placeholder="trans('documenti.placeholder.category')"
                      class="flex-1"
                      @update:modelValue="form.clearErrors('category_id')" 
                    />
                    <Sheet>
                      <SheetTrigger as-child>
                        <button type="button" class="p-2 rounded-md border hover:bg-muted transition">
                          <Plus class="w-4 h-4 text-muted-foreground hover:text-primary" />
                        </button>
                      </SheetTrigger>
                      <SheetContent side="right" class="p-6">
                        <SheetHeader class="mt-4 p-0">
                          <SheetTitle>{{ trans('documenti.header.categories.new_category_title') }}</SheetTitle>
                          <SheetDescription>
                            {{ trans('documenti.header.categories.new_category_description') }}
                          </SheetDescription>
                        </SheetHeader>

                        <form @submit.prevent="createCategory" class="mt-6 space-y-4">
                          <div>
                            <Label for="new-category-name">{{ trans('documenti.label.categories.category_name') }}</Label>
                            <Input
                              id="new-category-name"
                              v-model="newCategoryName"
                              :placeholder="trans('documenti.placeholder.categories.category_name')"
                              class="w-full mt-1"
                            />
                          </div>

                          <div>
                            <Label for="new-category-description">{{ trans('documenti.label.categories.category_description') }}</Label>
                            <Textarea
                              id="new-category-description"
                              v-model="newCategoryDescription"
                              :placeholder="trans('documenti.placeholder.categories.category_description')"
                              class="w-full mt-1 min-h-[200px]"
                            />
                          </div>

                          <div class="flex justify-end">
                            <SheetClose as-child>
                              <Button type="submit">
                                {{ trans('documenti.actions.categories.save_category') }}
                              </Button>
                            </SheetClose>
                          </div>
                        </form>
                      </SheetContent>
                    </Sheet>
                  </div>

                  <InputError :message="form.errors.category_id" />
                </div>
              </div>

              <div class="pt-3 grid grid-cols-1 sm:grid-cols-6">
                <div class="sm:col-span-6">
                  <Label for="condomini">{{ trans('documenti.label.buildings') }}</Label>
                  <v-select 
                    multiple
                    :options="condomini" 
                    label="nome" 
                    v-model="form.condomini_ids"
                    :placeholder="trans('documenti.placeholder.buildings')"
                    @update:modelValue="form.clearErrors('condomini_ids')" 
                    :reduce="(condominio: Building) => condominio.id"
                  />
                  <InputError :message="form.errors.condomini_ids" />
                </div>
              </div>

              <div class="pt-3 grid grid-cols-1 sm:grid-cols-6">
                <div class="sm:col-span-6">
                  <Label for="anagrafiche">{{ trans('documenti.label.residents') }}</Label>
                  <v-select
                    multiple
                    id="anagrafiche"
                    :options="anagraficheOptions"
                    label="nome"
                    v-model="form.anagrafiche"
                    :placeholder="trans('documenti.placeholder.residents')"
                    @update:modelValue="form.clearErrors('anagrafiche')"
                    :reduce="(anagrafica: Anagrafica) => anagrafica.id"
                    :disabled="form.condomini_ids.length === 0"
                  />
                  <InputError :message="form.errors.anagrafiche" />
                </div>
              </div>
            </div>
          </div>
        </div>
      </form>
    </div>
  </AppLayout>
</template>

<style src="vue-select/dist/vue-select.css"></style>