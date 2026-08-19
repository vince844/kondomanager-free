<script setup lang="ts">

import { ref, computed, watch } from 'vue';
import { Link, Head, useForm } from '@inertiajs/vue3';
import AppLayout from '@/layouts/AppLayout.vue';
import PageHeaderGuide from '@/components/PageHeaderGuide.vue';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import InputError from '@/components/InputError.vue';
import { Card, CardHeader, CardTitle, CardDescription, CardContent } from '@/components/ui/card';
import { HoverCard, HoverCardContent, HoverCardTrigger } from '@/components/ui/hover-card';
import { Sheet, SheetTrigger, SheetContent, SheetHeader, SheetTitle, SheetDescription, SheetClose } from "@/components/ui/sheet";
import { Empty, EmptyDescription, EmptyHeader, EmptyMedia, EmptyTitle } from '@/components/ui/empty';
import { Item } from "@/components/ui/item";
import { useToast } from '@/components/ui/toast';
import { Pencil, Plus, LoaderCircle, UploadCloud, Info, FileText, X, FileUp, FolderTree, Building2, RefreshCw, AlertTriangle } from 'lucide-vue-next';
import axios from 'axios';
import vSelect from "vue-select";
import { usePermission } from '@/composables/permissions';
import { publishedConstants } from '@/lib/documenti/constants';
import { formatBytes } from '@/utils/formatBytes'; 
import { trans } from 'laravel-vue-i18n';
import type { BreadcrumbItem } from '@/types';
import type { PublishedType } from '@/types/documenti';
import type { Building } from '@/types/buildings';
import type { Anagrafica } from '@/types/anagrafiche';
import type { Categoria } from '@/types/categorie';
import type { Documento } from '@/types/documenti';

const props = defineProps<{
  /** Limite di caricamento già scritto per l'utente («2 MB»), calcolato dal server: non è un numero nostro. */
  limiteFile: string;
  documento: Documento | any; 
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

const breadcrumbs = computed<BreadcrumbItem[]>(() => [
  {
      title: trans('documenti.breadcrumbs.list'), 
      href: route(generateRoute('documenti.index'))
  },
  {
      title: trans('documenti.breadcrumbs.edit'),
      href: '#',
  }
]);

const pageGuides = computed(() => [
  {
    title: trans('documenti.guides.upload_title'),
    description: trans('documenti.guides.upload_desc'),
    icon: FileUp,
    colorVariant: 'blue' as const
  },
  {
    title: trans('documenti.guides.category_title'),
    description: trans('documenti.guides.category_desc'),
    icon: FolderTree,
    colorVariant: 'emerald' as const
  },
  {
    title: trans('documenti.guides.audience_title'),
    description: trans('documenti.guides.audience_desc'),
    icon: Building2,
    colorVariant: 'amber' as const
  }
]);

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
  condomini_ids: props.documento?.condomini?.options?.map((c: any) => c.value) ?? [],
  is_published: props.documento?.is_published !== undefined ? Boolean(props.documento.is_published) : true,
  anagrafiche: (props.documento?.anagrafiche ?? []).map((anagrafica: Anagrafica) => anagrafica.id),
  category_id: props.documento?.categoria?.id ?? null, 
  file: null as File | null,
});

// Validazione file
//
// ⚠️ Il controllo sulla **dimensione** non si fa più qui: era un secondo limite scritto in un posto
// dove nessuno lo cercava, e rifiutava file che il server avrebbe accettato mentre ne lasciava
// partire altri che il server rifiutava. Il limite è uno solo ed è quello del server.
//
// ⚠️ E i formati: la regola a server è `mimes:pdf`, quindi JPEG e PNG non sono mai passati. Offrirli
// qui voleva dire far scegliere un file e respingerlo dopo il caricamento.
const validateFile = (selectedFile: File): boolean => {
  const allowedTypes = ['application/pdf'];

  if (!allowedTypes.includes(selectedFile.type)) {
    form.setError('file', trans('documenti.dialogs.document_supported_types'));
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
      title: trans('documenti.toast.success_title'),
      description: trans('documenti.toast.success_message'),
      variant: 'default',
    })
  } catch (error: any) {
    const backendMessage = error.response?.data?.error || trans('documenti.toast.error_message')

    toast({
      title: trans('documenti.toast.error_title'),
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
    <div class="px-6 py-8 space-y-6">

      <PageHeaderGuide
          :page-title="trans('documenti.header.edit_document_title')"
          :page-subtitle="trans('documenti.header.edit_document_description')"
          :guides="pageGuides"
          :breadcrumbs="breadcrumbs"
          :video-url="null"
          :back-url="route(generateRoute('documenti.index'))"
          :back-text="trans('documenti.actions.back')"
      />

      <form @submit.prevent="submit" class="space-y-6">

        <Card class="border-dashed shadow-sm bg-slate-50/50 dark:bg-slate-900/20">
            <CardHeader class="pb-3 border-b border-dashed mb-4">
                <CardTitle class="text-base font-semibold">{{ trans('documenti.section.content_title') }}</CardTitle>
                <CardDescription>{{ trans('documenti.section.content_desc') }}</CardDescription>
            </CardHeader>
            <CardContent class="space-y-2">
                
                <div class="grid grid-cols-1 gap-x-4 gap-y-4 sm:grid-cols-6">
                    <div class="sm:col-span-6">
                        <Label for="name">{{ trans('documenti.label.name') }}</Label>
                        <Input 
                            id="name" 
                            class="mt-1 block w-full bg-white dark:bg-slate-950"
                            v-model="form.name" 
                            v-on:focus="form.clearErrors('name')"
                            :placeholder="trans('documenti.placeholder.name')" 
                        />
                        <InputError :message="form.errors.name" />
                    </div>

                    <div class="sm:col-span-6">
                        <Label for="description">{{ trans('documenti.label.description') }}</Label>
                        <Textarea 
                            id="description" 
                            class="mt-1 block w-full min-h-[160px] bg-white dark:bg-slate-950"
                            v-model="form.description" 
                            v-on:focus="form.clearErrors('description')"
                            :placeholder="trans('documenti.placeholder.description')" 
                        />
                        <InputError :message="form.errors.description" />
                    </div>  
                </div> 

                <div class="grid grid-cols-1 sm:grid-cols-6">
                    <div class="sm:col-span-6">
                        <!-- <Label for="file-upload" class="mb-2 block">{{ trans('documenti.label.document') }}</Label> -->

                        <div v-if="showExistingFile" class="mt-2">
                            <Item class="flex items-center justify-between border rounded-lg p-3 shadow-sm bg-white dark:bg-slate-950">
                                <div class="flex items-center gap-3">
                                    <div class="w-10 h-10 flex items-center justify-center rounded-lg bg-accent/20">
                                        <FileText class="w-5 h-5 text-muted-foreground" />
                                    </div>
                                    <div class="flex flex-col">
                                        <div class="text-sm font-medium truncate max-w-[250px]">{{ getFileName() }}</div>
                                        <div class="text-xs text-muted-foreground">
                                            {{ props.documento.mime_type_label || 'PDF' }}
                                            <template v-if="props.documento.file_size">
                                                • {{ formatBytes(props.documento.file_size, undefined, true) }}
                                            </template>
                                        </div>
                                    </div>
                                </div>
                                <Button 
                                    type="button"
                                    variant="ghost" 
                                    size="icon" 
                                    @click="showFileUpload"
                                    :title="trans('documenti.label.replace_document')"
                                >
                                    <RefreshCw class="w-4 h-4 text-primary hover:text-primary/80" />
                                </Button>
                            </Item>
                        </div>

                        <div v-if="showFileInput || !hasExistingFile" class="mt-2">
                            <label
                                for="file-upload"
                                @dragover.prevent
                                @drop="onDrop"
                                class="block cursor-pointer"
                            >
                                <Empty class="border border-dashed bg-white dark:bg-slate-950 hover:bg-accent/10 transition">
                                    <EmptyHeader>
                                        <EmptyMedia variant="icon">
                                            <UploadCloud class="w-8 h-8 text-muted-foreground" />
                                        </EmptyMedia>
                                        <EmptyTitle>{{ trans('documenti.dialogs.select_document_title') }}</EmptyTitle>
                                        <EmptyDescription>
                                            {{ trans('documenti.dialogs.select_document_description') }}
                                            <div class="text-xs text-muted-foreground mt-1">
                                                {{ trans('documenti.dialogs.document_supported_types') }} (max {{ props.limiteFile }})
                                            </div>
                                        </EmptyDescription>
                                    </EmptyHeader>
                                </Empty>

                                <input
                                    id="file-upload"
                                    type="file"
                                    class="hidden"
                                    accept="application/pdf"
                                    @change="handleFileChange"
                                    ref="fileInputRef"
                                />
                            </label>

                            <div v-if="showFileInput && hasExistingFile" class="mt-3 flex justify-end">
                                <Button 
                                    type="button" 
                                    variant="outline" 
                                    size="sm"
                                    @click="cancelFileUpload"
                                    class="gap-2 shadow-sm"
                                >
                                    <X class="w-3 h-3" />
                                    {{ trans('documenti.actions.cancel') }}
                                </Button>
                            </div>
                        </div>

                        <div v-if="file" class="mt-2">
                            <div class="border rounded-lg p-3 shadow-sm bg-white dark:bg-slate-950">
                                <div class="flex items-center justify-between">
                                    <div class="flex items-center gap-3">
                                        <div class="w-10 h-10 flex items-center justify-center rounded-lg bg-accent/20">
                                            <FileText class="w-5 h-5 text-muted-foreground" />
                                        </div>
                                        <div class="flex flex-col">
                                            <span class="text-sm font-medium truncate max-w-[250px]">{{ file.name }}</span>
                                            <span class="text-xs text-muted-foreground">{{ formatBytes(file.size, undefined, true) }}</span>
                                        </div>
                                    </div>
                                    <Button 
                                        type="button"
                                        variant="ghost" 
                                        size="icon" 
                                        @click="removeFile"
                                        :title="trans('documenti.label.remove_document')"
                                    >
                                        <X class="w-4 h-4 text-red-500 hover:text-red-600" />
                                    </Button>
                                </div>
                                <div v-if="hasExistingFile" class="text-[11px] font-semibold text-amber-600 dark:text-amber-500 mt-3 pt-3 border-t border-dashed">
                                    <AlertTriangle class="w-3 h-3 inline mr-1 -mt-0.5" />
                                    {{ trans('documenti.label.replace_existing_document') }}
                                </div>
                            </div>
                        </div>

                        <InputError :message="form.errors.file" class="mt-2" />
                    </div>
                </div>

            </CardContent>
        </Card>

        <Card class="border-dashed shadow-sm bg-slate-50/50 dark:bg-slate-900/20">
            <CardHeader class="pb-3 border-b border-dashed mb-4">
                <CardTitle class="text-base font-semibold">{{ trans('documenti.section.settings_title') }}</CardTitle>
                <CardDescription>{{ trans('documenti.section.settings_desc') }}</CardDescription>
            </CardHeader>
            <CardContent class="space-y-6">
                <div class="grid grid-cols-1 gap-x-4 gap-y-4 sm:grid-cols-6">
                    
                    <div class="sm:col-span-3">
                        <div class="flex items-center gap-x-2 text-sm font-medium mb-1 min-h-[24px]">
                            <Label for="category">{{ trans('documenti.label.category') }}</Label>
                            <HoverCard>
                                <HoverCardTrigger as-child>
                                    <button type="button" class="text-slate-400 hover:text-primary outline-none">
                                        <Info class="w-4 h-4" />
                                    </button>
                                </HoverCardTrigger>
                                <HoverCardContent class="w-80 p-4 bg-white dark:bg-slate-900 border-slate-200 shadow-xl">
                                    <h4 class="text-sm font-bold mb-2">{{ trans('documenti.label.category') }}</h4>
                                    <p class="text-xs text-slate-500 leading-relaxed">{{ trans('documenti.tooltip.category') }}</p>
                                </HoverCardContent>
                            </HoverCard>
                        </div>
                        <div class="flex items-center gap-2 mt-1">
                            <v-select
                                class="w-full premium-select bg-white dark:bg-slate-950 flex-1"
                                :options="localCategories"
                                label="name"
                                v-model="form.category_id"
                                :reduce="(option: Categoria) => option.id"
                                :placeholder="trans('documenti.placeholder.category')"
                                @update:modelValue="form.clearErrors('category_id')" 
                            />
                            <Sheet>
                                <SheetTrigger as-child>
                                    <button type="button" class="p-2 rounded-md border bg-white dark:bg-slate-950 hover:bg-slate-50 transition shadow-sm h-8">
                                        <Plus class="w-4 h-4 text-slate-500 hover:text-primary" />
                                    </button>
                                </SheetTrigger>
                                <SheetContent side="right" class="p-6 sm:max-w-md">
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
                                                class="w-full mt-1 bg-white dark:bg-slate-950"
                                            />
                                        </div>
                                        <div>
                                            <Label for="new-category-description">{{ trans('documenti.label.categories.category_description') }}</Label>
                                            <Textarea
                                                id="new-category-description"
                                                v-model="newCategoryDescription"
                                                :placeholder="trans('documenti.placeholder.categories.category_description')"
                                                class="w-full mt-1 min-h-[160px] bg-white dark:bg-slate-950"
                                            />
                                        </div>
                                        <div class="flex justify-end pt-4">
                                            <SheetClose as-child>
                                                <Button type="submit" class="w-full sm:w-auto">
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

                    <div class="sm:col-span-3">
                        <div class="flex items-center gap-2 min-h-[24px] mb-1">
                            <Label for="is_published">{{ trans('documenti.label.visibility') }}</Label>
                            <HoverCard>
                                <HoverCardTrigger as-child>
                                    <button type="button" class="text-slate-400 hover:text-primary outline-none">
                                        <Info class="w-4 h-4" />
                                    </button>
                                </HoverCardTrigger>
                                <HoverCardContent class="w-80 p-4 bg-white dark:bg-slate-900 border-slate-200 shadow-xl">
                                    <h4 class="text-sm font-bold mb-2">{{ trans('documenti.label.visibility') }}</h4>
                                    <p class="text-xs text-slate-500 leading-relaxed">{{ trans('documenti.tooltip.visibility') }}</p>
                                </HoverCardContent>
                            </HoverCard>
                        </div>
                        <v-select 
                            id="is_published"
                            class="w-full premium-select bg-white dark:bg-slate-950 mt-1"
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
                                <div v-if="label" class="flex items-center gap-2">
                                    <component :is="icon" class="w-4 h-4 text-muted-foreground" />
                                    <span>{{ trans(label) }}</span>
                                </div>
                            </template>
                        </v-select>
                        <InputError :message="form.errors.is_published" />
                    </div>

                </div>
            </CardContent>
        </Card>

        <Card class="border-dashed shadow-sm bg-slate-50/50 dark:bg-slate-900/20">
            <CardHeader class="pb-3 border-b border-dashed mb-4">
                <CardTitle class="text-base font-semibold">{{ trans('documenti.section.recipients_title') }}</CardTitle>
                <CardDescription>{{ trans('documenti.section.recipients_desc') }}</CardDescription>
            </CardHeader>
            <CardContent class="space-y-6">
                <div class="grid grid-cols-1 gap-x-4 gap-y-4 sm:grid-cols-6">
                    
                    <div class="sm:col-span-3">
                        <Label for="condomini">{{ trans('documenti.label.buildings') }}</Label>
                        <v-select 
                            multiple
                            class="w-full premium-select bg-white dark:bg-slate-950 mt-1"
                            :options="condomini" 
                            label="label" 
                            v-model="form.condomini_ids"
                            :placeholder="trans('documenti.placeholder.buildings')"
                            @update:modelValue="form.clearErrors('condomini_ids')" 
                            :reduce="(condominio: any) => condominio.value"
                        />
                        <InputError :message="form.errors.condomini_ids" />
                    </div>

                    <div class="sm:col-span-3">
                        <Label for="anagrafiche">{{ trans('documenti.label.residents') }}</Label>
                        <v-select
                            multiple
                            id="anagrafiche"
                            class="w-full premium-select bg-white dark:bg-slate-950 mt-1"
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
            </CardContent>
        </Card>

        <div class="flex items-center justify-end gap-3">
            <Link
                :href="route(generateRoute('documenti.index'))"
                class="inline-flex items-center justify-center h-9 px-6 rounded-md border border-input bg-background text-sm font-semibold hover:bg-accent hover:text-accent-foreground transition-all shadow-sm"
            >
                {{ trans('documenti.actions.cancel') }}
            </Link>

            <Button 
                type="submit"
                :disabled="form.processing" 
                class="h-9 px-8 text-sm font-semibold shadow-md gap-2"
            >
                <LoaderCircle v-if="form.processing" class="h-4 w-4 animate-spin" />
                <Pencil v-else class="h-4 w-4" />
                {{ trans('documenti.actions.save_document') }}
            </Button>
        </div>

      </form>
    </div>
  </AppLayout>
</template>

<style src="vue-select/dist/vue-select.css"></style>