<script setup lang="ts">

import { Link, Head, useForm } from '@inertiajs/vue3';
import { ref, computed, watch } from 'vue';
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
import { Empty, EmptyDescription, EmptyHeader, EmptyMedia, EmptyTitle } from '@/components/ui/empty';
import { useToast } from '@/components/ui/toast';
import axios from 'axios';
import vSelect from "vue-select";
import { usePermission } from '@/composables/permissions';
import { publishedConstants } from '@/lib/documenti/constants';
import { formatBytes } from '@/utils/formatBytes'; 
import { trans } from 'laravel-vue-i18n';
import type { PublishedType } from '@/types/documenti';
import type { Building } from '@/types/buildings';
import type { Anagrafica } from '@/types/anagrafiche';
import type { Categoria } from '@/types/categorie';
import type { Documento } from '@/types/documenti';

const props = defineProps<{
  documento: Documento;
  condomini: Building[];
  categories: Categoria[];
  anagrafiche: Anagrafica[];
}>()

const { generatePath, generateRoute } = usePermission();
const { toast } = useToast();

const anagraficheOptions = ref<Anagrafica[]>(props.anagrafiche);
const localCategories = ref<Categoria[]>([...props.categories]);
const newCategoryName = ref('')
const newCategoryDescription = ref('')
const file = ref<File | null>(null)
const fileInputRef = ref<HTMLInputElement | null>(null)
const showFileInput = ref(false)

// Computed per determinare se mostrare il file esistente o l'input
const hasExistingFile = computed(() => {
  return !!(props.documento.path || props.documento.mime_type);
})

const showExistingFile = computed(() => {
  return hasExistingFile.value && !file.value && !showFileInput.value;
})

const form = useForm({
  name: props.documento?.name ?? '',
  description: props.documento?.description ?? '',
  condomini_ids: props.documento?.condomini?.options?.map(c => c.value) ?? [],
  is_published: !!props.documento?.is_published,
  anagrafiche: (props.documento?.anagrafiche ?? []).map(anagrafica => anagrafica.id),
  category_id: props.documento?.categoria?.id ?? null, 
  file: null as File | null,
});

// Validazione file
const validateFile = (selectedFile: File): boolean => {
  const allowedTypes = ['application/pdf', 'image/jpeg', 'image/png', 'image/jpg'];
  const maxSize = 20 * 1024 * 1024; // 20MB
  
  if (!allowedTypes.includes(selectedFile.type)) {
    form.setError('file', trans('documenti.dialogs.document_supported_types'));
    return false;
  }
  
  if (selectedFile.size > maxSize) {
    form.setError('file', trans('documenti.dialogs.max_document_size'));
    return false;
  }
  
  return true;
}

// Gestione file
const handleFileChange = (event: Event): void => {
  const target = event.target as HTMLInputElement
  const selectedFile = target.files?.[0] || null
  
  if (selectedFile && validateFile(selectedFile)) {
    file.value = selectedFile
    form.file = selectedFile
    showFileInput.value = false
    form.clearErrors('file')
  }
}

const onDrop = (event: DragEvent): void => {
  event.preventDefault()
  const droppedFile = event.dataTransfer?.files[0] || null
  
  if (droppedFile && validateFile(droppedFile)) {
    file.value = droppedFile
    form.file = droppedFile
    showFileInput.value = false
    
    if (fileInputRef.value) {
      const dataTransfer = new DataTransfer()
      dataTransfer.items.add(droppedFile)
      fileInputRef.value.files = dataTransfer.files
    }
    form.clearErrors('file')
  }
}

const removeFile = (): void => {
  file.value = null
  form.file = null
  
  if (fileInputRef.value) {
    fileInputRef.value.value = ''
  }
  
  if (hasExistingFile.value) {
    showFileInput.value = true
  }
  
  form.clearErrors('file')
}

const showFileUpload = (): void => {
  showFileInput.value = true
}

const cancelFileUpload = (): void => {
  showFileInput.value = false
  file.value = null
  form.file = null
  if (fileInputRef.value) {
    fileInputRef.value.value = ''
  }
  form.clearErrors('file')
}

const getFileName = (): string => {
  if (props.documento.path) {
    const pathParts = props.documento.path.split('/');
    return pathParts[pathParts.length - 1] || props.documento.name;
  }
  return props.documento.name;
}

const createCategory = async (): Promise<void> => {
  if (!newCategoryName.value) return

  try {
    const response = await axios.post(route(generateRoute('categorie.store')), {
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

    form.anagrafiche = []; 
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
  form.put(route(generateRoute('documenti.update'), { id: props.documento.id }), {
    preserveScroll: true,
    onSuccess: () => {
      form.reset()
      file.value = null
      showFileInput.value = false
    }
  });
};

</script>


<template>
  <Head :title="trans('documenti.header.edit_document_head')" />

  <AppLayout>
    <div class="px-4 py-6">
      <Heading
        :title="trans('documenti.header.edit_document_title')"
        :description="trans('documenti.header.edit_document_description')"
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
            :href="route(generateRoute('documenti.index'))"
            class="w-full lg:w-auto inline-flex items-center justify-center gap-2 rounded-md bg-primary px-3 py-1.5 text-sm font-medium text-white hover:bg-primary/90"
          >
            <List class="w-4 h-4" />
            <span>{{ trans('documenti.actions.list_documents') }}</span>
          </Link>
        </div>

        <!-- Two-column layout (3:1 ratio) -->
        <div class="grid grid-cols-1 lg:grid-cols-4 gap-3">
          <!-- Main Card (3/4 width) -->
          <div class="col-span-1 lg:col-span-3 mt-3">
            <div class="bg-white dark:bg-muted rounded shadow-sm p-3 space-y-4 border">
              <div class="mt-2 grid grid-cols-1 gap-y-6 gap-x-4 sm:grid-cols-6">
                <div class="sm:col-span-3">
                  <Label for="name" class="font-medium">{{ trans('documenti.label.name') }}</Label>
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
                  <Label for="description" class="font-medium">{{ trans('documenti.label.description') }}</Label>
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
                  <Label for="file-upload" class="font-medium">{{ trans('documenti.label.document') }}</Label>

                  <!-- File esistente -->
                  <div v-if="showExistingFile" class="mt-4">
                    <div class="bg-muted/30 border rounded-lg p-3">
                      <div class="flex items-center justify-between">
                        <div class="flex items-center gap-3">
                          <div class="w-10 h-10 flex items-center justify-center rounded-lg bg-accent/20">
                            <FileText class="w-5 h-5 text-muted-foreground" />
                          </div>
                          <div>
                            <div class="text-sm font-medium">{{ getFileName() }}</div>
                            <div class="text-xs text-muted-foreground">
                              {{ props.documento.mime_type_label || 'PDF' }}
                              <template v-if="props.documento.file_size">
                                • {{ formatBytes(props.documento.file_size, undefined, true) }}
                              </template>
                            </div>
                          </div>
                        </div>
                        <Button 
                          variant="ghost" 
                          size="icon" 
                          @click="showFileUpload"
                          :title="trans('documenti.label.replace_document')"
                        >
                          <UploadCloud class="w-4 h-4" />
                        </Button>
                      </div>
                    </div>
                  </div>

                  <!-- Area upload -->
                  <div v-if="showFileInput || !hasExistingFile" class="mt-4">
                    <label
                      for="file-upload"
                      @dragover.prevent
                      @drop="onDrop"
                      class="block cursor-pointer"
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

                      <input
                        id="file-upload"
                        type="file"
                        class="hidden"
                        accept=".pdf,.jpg,.jpeg,.png,application/pdf,image/jpeg,image/png,image/jpg"
                        @change="handleFileChange"
                        ref="fileInputRef"
                      />
                    </label>

                    <div v-if="showFileInput && hasExistingFile" class="mt-2">
                      <Button 
                        type="button" 
                        variant="outline" 
                        size="sm"
                        @click="cancelFileUpload"
                        class="gap-2"
                      >
                        <X class="w-3 h-3" />
                        {{ trans('documenti.actions.cancel') }}
                      </Button>
                    </div>
                  </div>

                  <!-- Nuovo file selezionato -->
                  <div v-if="file" class="mt-4">
                    <div class="border rounded-lg p-3">
                      <div class="flex items-center justify-between">
                        <div class="flex items-center gap-3">
                          <div class="w-10 h-10 flex items-center justify-center rounded-lg bg-accent/20">
                            <FileText class="w-5 h-5 text-muted-foreground" />
                          </div>
                          <div>
                            <div class="text-sm font-medium">{{ file.name }}</div>
                            <div class="text-xs text-muted-foreground">
                              {{ formatBytes(file.size, undefined, true) }}
                            </div>
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
                      </div>
                      <div v-if="hasExistingFile" class="text-xs text-muted-foreground mt-2">
                        {{trans('documenti.label.replace_existing_document')}}
                      </div>
                    </div>
                  </div>

                  <InputError :message="form.errors.file" />
                </div>
              </div>
            </div>
          </div>

          <!-- Side Card (1/4 width) -->
          <div class="col-span-1 mt-3">
            <div class="bg-white dark:bg-muted rounded shadow-sm p-3 border space-y-4">
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
                    class="mt-1"
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
                          <SheetTitle>{{ trans('documenti.header.new_category_title') }}</SheetTitle>
                          <SheetDescription>
                            {{ trans('documenti.header.new_category_description') }}
                          </SheetDescription>
                        </SheetHeader>

                        <form @submit.prevent="createCategory" class="mt-6 space-y-4">
                          <div>
                            <Label for="new-category-name">{{ trans('documenti.label.category_name') }}</Label>
                            <Input
                              id="new-category-name"
                              v-model="newCategoryName"
                              :placeholder="trans('documenti.placeholder.category_name')"
                              class="w-full mt-1"
                            />
                          </div>

                          <div>
                            <Label for="new-category-description">{{ trans('documenti.label.category_description') }}</Label>
                            <Textarea
                              id="new-category-description"
                              v-model="newCategoryDescription"
                              :placeholder="trans('documenti.placeholder.category_description')"
                              class="w-full mt-1 min-h-[200px]"
                            />
                          </div>

                          <div class="flex justify-end">
                            <SheetClose as-child>
                              <Button type="submit">{{ trans('documenti.actions.save_category') }}</Button>
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
                    label="label"
                    v-model="form.condomini_ids"
                    :placeholder="trans('documenti.placeholder.buildings')"
                    @update:modelValue="form.clearErrors('condomini_ids')" 
                    :reduce="(option: Building) => option.value"
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

              <div class="pt-3 border-t">
                <h4 class="text-sm font-medium mb-2">{{ trans('documenti.label.document_info') }}</h4>
                <div class="space-y-2 text-sm">
                  <div v-if="props.documento.created_at" class="flex justify-between">
                    <span class="text-muted-foreground">{{ trans('documenti.label.created') }}</span>
                    <span>{{ props.documento.created_at }}</span>
                  </div>
                  <div class="flex justify-between">
                    <span class="text-muted-foreground">{{ trans('documenti.label.status') }}</span>
                    <span :class="hasExistingFile ? 'text-green-600' : 'text-amber-600'">
                      {{ 
                        hasExistingFile 
                        ? trans('documenti.label.existing')
                        : trans('documenti.label.missing')
                      }}
                    </span>
                  </div>
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