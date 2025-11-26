<script setup lang="ts">

import { Link, Head, useForm } from '@inertiajs/vue3';
import { ref, watch } from 'vue';
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
import { Item } from "@/components/ui/item"
import { useToast } from '@/components/ui/toast';
import axios from 'axios';
import vSelect from "vue-select";
import { usePermission } from '@/composables/permissions';
import { publishedConstants } from '@/lib/documenti/constants';
import type { PublishedType } from '@/types/documenti';
import type { Building } from '@/types/buildings';
import type { Anagrafica } from '@/types/anagrafiche';
import type { Categoria } from '@/types/categorie';

const props = defineProps<{
  condomini: Building[];
  categories: Categoria[];
  anagrafiche: Anagrafica[];
  
}>()

const { generatePath } = usePermission()
const { toast } = useToast()

const file = ref<File | null>(null)
const newCategoryName = ref('')
const newCategoryDescription = ref('')
const localCategories = ref([...props.categories])
const anagraficheOptions = ref<Anagrafica[]>([]);

const handleFileChange = (e: Event) => {
  const input = e.target as HTMLInputElement
  const selected = input.files?.[0] || null
  if (!selected) return
  file.value = selected
  form.file = selected
}

const removeFile = () => {
  file.value = null
}

const onDrop = (e: DragEvent) => {
  e.preventDefault()
  const droppedFile = e.dataTransfer?.files?.[0] || null
  file.value = droppedFile
}

const form = useForm({
  name: '',
  description: '',
  is_published: true,
  condomini_ids: [],
  category_id: '',
  file: null as File | null,
  anagrafiche: []
})

const createCategory = async () => {

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
  } catch (error: any) {
    console.error('Errore creazione categoria', error)

    const backendMessage = error.response?.data?.error || 'Impossibile creare la categoria. Riprova più tardi.'

    toast({
      title: 'Errore',
      description: backendMessage,
      variant: 'destructive',
    })
  }

}

const fetchAnagrafiche = async (condomini_ids: number[]) => {
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

watch(() => form.condomini_ids, fetchAnagrafiche);

const submit = () => {
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
  <Head title="Crea nuovo documento" />

  <AppLayout >
    <div class="px-4 py-6">

      <Heading
        title="Crea documento archivio"
        description="Compila il seguente modulo per la creazione di un nuovo documento per l'archivo del condominio"
      />

      <form @submit.prevent="submit" class="space-y-2">

        <!-- Action buttons -->
        <div class="flex flex-col lg:flex-row lg:justify-end gap-2 w-full">
          <Button :disabled="form.processing" class="h-8 w-full lg:w-auto">
            <Plus class="w-4 h-4" v-if="!form.processing" />
            <LoaderCircle v-if="form.processing" class="h-4 w-4 animate-spin" />
            Salva
          </Button>

          <Link
            as="button"
            :href="route('admin.documenti.index')"
            class="w-full lg:w-auto inline-flex items-center justify-center gap-2 rounded-md bg-primary px-3 py-1.5 text-sm font-medium text-white hover:bg-primary/90"
          >
            <List class="w-4 h-4" />
            <span>Elenco</span>
          </Link>
        </div>

        <!-- Two-column layout (3:1 ratio) -->
        <div class="grid grid-cols-1 lg:grid-cols-4 gap-3 ">

          <!-- Main Card (3/4 width) -->
          <div class="col-span-1 lg:col-span-3 mt-3">
            <div class="bg-white dark:bg-muted rounded shadow-sm p-3 space-y-4 border">
                
              <div class="mt-2 grid grid-cols-1 gap-y-6 gap-x-4 sm:grid-cols-6">
                <div class="sm:col-span-3">
                  <Label for="nome">Nome documento</Label>
                  <Input 
                      id="name" 
                      class="mt-1 block w-full"
                      v-model="form.name" 
                      v-on:focus="form.clearErrors('name')"
                      placeholder="Nome documento" 
                  />
                  
                  <InputError :message="form.errors.name" />
                </div>
              </div> 

              <div class="mt-2 grid grid-cols-1 gap-y-6 gap-x-4 sm:grid-cols-6">
                <div class="sm:col-span-6">
                  <Label for="nome">Descrizione documento</Label>
                  <Textarea 
                      id="description" 
                      class="mt-1 block w-full min-h-[200px]"
                      v-model="form.description" 
                      v-on:focus="form.clearErrors('description')"
                      placeholder="Descrizone documento" 
                  />
                  
                  <InputError :message="form.errors.description" />
                </div>     
              </div> 
                
              <div class="mt-2 grid grid-cols-1 gap-y-6 gap-x-4 sm:grid-cols-6">
                <div class="sm:col-span-6">
                  <Label for="file-upload">Seleziona documento</Label>

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
                        <EmptyTitle>Trascina qui il tuo documento</EmptyTitle>
                        <EmptyDescription>
                          Oppure <strong>clicca</strong> per selezionarlo dal tuo dispositivo.
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
                            {{ (file.size / 1024).toFixed(1) }} KB
                          </span>
                        </div>
                      </div>
                      <Button variant="ghost" size="icon" @click="removeFile">
                        <X class="w-4 h-4" />
                      </Button>
                    </Item>

                  </div>

                  <InputError :message="form?.errors?.file" />
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
                    <Label for="stato">Stato pubblicazione</Label>

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
                              Stato pubblicazione
                          </h4>
                          <p class="text-sm">
                              Scegli se rendere visibile il documento o mantenerlo nascosto.
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
                    placeholder="Stato pubblicazione"
                    @update:modelValue="form.clearErrors('is_published')" 
                    :reduce="(is_published: PublishedType) => is_published.value"
                  />

                  <InputError :message="form.errors.is_published" />
        
                </div>
              </div>

              <div class="pt-3 grid grid-cols-1 sm:grid-cols-6">
                <div class="sm:col-span-6 space-y-1">

                  <!-- Label + info icon -->
                  <div class="flex items-center gap-x-2 text-sm font-medium mb-1">
                    <Label for="stato">Categoria</Label>
                    <HoverCard>
                      <HoverCardTrigger as-child>
                        <button type="button" class="cursor-pointer">
                          <Info class="w-4 h-4 text-muted-foreground" />
                        </button>
                      </HoverCardTrigger>
                      <HoverCardContent class="w-80">
                        <div class="space-y-1">
                          <h4 class="text-sm font-semibold">Categoria documento</h4>
                          <p class="text-sm">
                            Seleziona una categoria per organizzare meglio i documenti, oppure creane una nuova.
                          </p>
                        </div>
                      </HoverCardContent>
                    </HoverCard>
                  </div>

                  <!-- v-select and plus button in one row -->
                  <div class="flex items-center gap-2">
                    <v-select
                      :options="localCategories"
                      label="name"
                      v-model="form.category_id"
                      :reduce="(option: Categoria) => option.id"
                      placeholder="Seleziona categoria"
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
                          <SheetTitle>Crea nuova categoria</SheetTitle>
                          <SheetDescription>
                            Aggiungi una nuova categoria per i documenti.
                          </SheetDescription>
                        </SheetHeader>

                        <form @submit.prevent="createCategory" class="mt-6 space-y-4">
                          <div>
                            <Label for="new-category-name">Nome</Label>
                            <Input
                              id="new-category-name"
                              v-model="newCategoryName"
                              placeholder="Nome della categoria"
                              class="w-full mt-1"
                            />
                          </div>

                          <div>
                            <Label for="new-category-description">Descrizione</Label>
                            <Textarea
                              id="new-category-description"
                              v-model="newCategoryDescription"
                              placeholder="Descrizione della categoria"
                              class="w-full mt-1 min-h-[200px]"
                            />
                          </div>

                          <div class="flex justify-end">
                            <SheetClose as-child>
                              <Button type="submit">Salva</Button>
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
                      <Label for="condomini">Condominio</Label>

                      <v-select 
                        multiple
                        :options="condomini" 
                        label="nome" 
                        v-model="form.condomini_ids"
                        placeholder="Condomini"
                        @update:modelValue="form.clearErrors('condomini_ids')" 
                        :reduce="(condomini: Building) => condomini.id"
                      />

                      <InputError :message="form.errors.condomini_ids" />
          
                  </div>
              </div>

              <div class="pt-3 grid grid-cols-1 sm:grid-cols-6">
                  <div class="sm:col-span-6">
                      <Label for="condomini">Anagrafiche</Label>

                      <v-select
                          multiple
                          id="anagrafiche"
                          :options="anagraficheOptions"
                          label="nome"
                          v-model="form.anagrafiche"
                          placeholder="Anagrafiche"
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
