<script setup lang="ts">

import { ref, watch, computed, type Ref } from 'vue';
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
import { formatBytes } from '@/utils/formatBytes'; 
import { useToast } from '@/components/ui/toast';
import { Plus, LoaderCircle, UploadCloud, Info, FileText, X, FileUp, FolderTree, Building2 } from 'lucide-vue-next';
import axios from 'axios';
import vSelect from "vue-select";
import { trans } from 'laravel-vue-i18n';
import { usePermission } from '@/composables/permissions';
import { publishedConstants } from '@/lib/documenti/constants';
import type { BreadcrumbItem } from '@/types';
import type { PublishedType } from '@/types/documenti';
import type { Building } from '@/types/buildings';
import type { Anagrafica } from '@/types/anagrafiche';
import type { Categoria } from '@/types/categorie';
import type { AdminDocumentForm } from '@/types/documenti';

const props = defineProps<{
  /** Limite di caricamento già scritto per l'utente («2 MB»), calcolato dal server: non è un numero nostro. */
  limiteFile: string;
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

const breadcrumbs = computed<BreadcrumbItem[]>(() => [
  {
      title: trans('documenti.breadcrumbs.list'), 
      href: route('admin.documenti.index')
  },
  {
      title: trans('documenti.breadcrumbs.new'),
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

const form = useForm<AdminDocumentForm>({
  name: '',
  description: '',
  is_published: true,
  condomini_ids: [],
  // ⚠️ Un **array** dalla 1.11.0-beta.10: un documento può stare in più categorie.
  categorie: [] as number[],
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
    // La categoria appena creata si **aggiunge** a quelle scelte, non le sostituisce.
    form.categorie = [...form.categorie, newCat.id]

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

  <AppLayout>
    <div class="px-6 py-8 space-y-6">

      <PageHeaderGuide
          :page-title="trans('documenti.header.new_document_title')"
          :page-subtitle="trans('documenti.header.new_document_description')"
          :guides="pageGuides"
          :breadcrumbs="breadcrumbs"
          :video-url="null"
          :back-url="route('admin.documenti.index')"
          :back-text="trans('documenti.actions.back')"
      />

      <form @submit.prevent="submit" class="space-y-6">

        <Card class="border-dashed shadow-sm bg-slate-50/50 dark:bg-slate-900/20">
            <CardHeader class="pb-3 border-b border-dashed mb-4">
                <CardTitle class="text-base font-semibold">{{ trans('documenti.section.content_title') }}</CardTitle>
                <CardDescription>{{ trans('documenti.section.content_desc') }}</CardDescription>
            </CardHeader>
            <CardContent class="space-y-6">
                
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
                            />
                        </label>

                        <div v-if="file" class="mt-4">
                            <Item class="flex items-center justify-between border rounded-lg p-3 shadow-sm bg-white dark:bg-slate-950">
                                <div class="flex items-center gap-3">
                                    <div class="w-10 h-10 flex items-center justify-center rounded-lg bg-accent/20">
                                        <FileText class="w-5 h-5 text-muted-foreground" />
                                    </div>
                                    <div class="flex flex-col">
                                        <span class="text-sm font-medium truncate max-w-[250px]">
                                            {{ file.name }}
                                        </span>
                                        <span class="text-xs text-muted-foreground">
                                            {{ formatBytes(file.size, undefined, true) }}
                                        </span>
                                    </div>
                                </div>
                                <Button 
                                    type="button"
                                    variant="ghost" 
                                    size="icon" 
                                    @click.prevent="removeFile"
                                    :title="trans('documenti.label.remove_document')"
                                >
                                    <X class="w-4 h-4 text-red-500 hover:text-red-600" />
                                </Button>
                            </Item>
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
                                multiple
                                v-model="form.categorie"
                                :reduce="(option: Categoria) => option.id"
                                :placeholder="trans('documenti.placeholder.category')"
                                @update:modelValue="form.clearErrors('categorie')"
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
                        <InputError :message="form.errors.categorie" />
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
                            label="nome" 
                            v-model="form.condomini_ids"
                            :placeholder="trans('documenti.placeholder.buildings')"
                            @update:modelValue="form.clearErrors('condomini_ids')" 
                            :reduce="(condominio: Building) => condominio.id"
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
                :href="route('admin.documenti.index')"
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
                <Plus v-else class="h-4 w-4" />
                {{ trans('documenti.actions.save_document') }}
            </Button>
        </div>

      </form>
    </div>
  </AppLayout>
</template>

<style src="vue-select/dist/vue-select.css"></style>