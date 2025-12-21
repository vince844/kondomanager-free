<script setup lang="ts">
  
import { ref } from 'vue';
import { Link, Head, useForm } from '@inertiajs/vue3';
import AppLayout from '@/layouts/AppLayout.vue';
import FornitoreLayout from '@/layouts/fornitori/FornitoreLayout.vue';
import { usePermission } from "@/composables/permissions";
import { Button } from '@/components/ui/button';
import { List, Plus, LoaderCircle, UploadCloud, Info, FileText, X } from 'lucide-vue-next';
import { Label } from '@/components/ui/label';
import { Input } from '@/components/ui/input';
import InputError from '@/components/InputError.vue';
import { Textarea } from '@/components/ui/textarea';
import { Separator } from '@/components/ui/separator';
import { Item } from "@/components/ui/item";
import { HoverCard, HoverCardContent, HoverCardTrigger } from '@/components/ui/hover-card';
import { Empty, EmptyDescription, EmptyHeader, EmptyMedia, EmptyTitle } from '@/components/ui/empty';
import vSelect from "vue-select";
import { publishedConstants } from '@/lib/documenti/constants';
import type { PublishedType } from '@/types/documenti';
import type { Fornitore } from '@/types/fornitori';
import type { BaseDocumentForm } from '@/types/documenti';

const props = defineProps<{
  fornitore: Fornitore;
}>()

const { generatePath, generateRoute } = usePermission();

const file = ref<File | null>(null)
const fileInputRef = ref<HTMLInputElement | null>(null)

const form = useForm<BaseDocumentForm>({
  name: '',
  description: '',
  is_published: true,
  file: null,
  anagrafiche: []
});

// Metodi con tipi
const handleFileChange = (event: Event): void => {
  const target = event.target as HTMLInputElement
  const selectedFile = target.files?.[0] || null
  
  if (selectedFile) {
    file.value = selectedFile
    form.file = selectedFile
    form.clearErrors('file')
  }
}

const onDrop = (event: DragEvent): void => {
  event.preventDefault()
  const droppedFile = event.dataTransfer?.files[0] || null
  
  if (droppedFile) {
    file.value = droppedFile
    form.file = droppedFile
    
    // Aggiorna il valore dell'input file
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
  
  // Resetta l'input file
  if (fileInputRef.value) {
    fileInputRef.value.value = ''
  }
  form.clearErrors('file')
}

const submit = (): void => {
  form.post(route(...generateRoute('fornitori.documenti.store', 
  { 
    fornitore: props.fornitore.id 
  })), {
    preserveScroll: true,
    onSuccess: () => {
      form.reset()
    }
  });
};

</script>

<template>

    <Head title="Crea documento immobile" />

    <AppLayout>
      <FornitoreLayout>
          <form class="space-y-2" @submit.prevent="submit">
            <!-- Action buttons -->
            <div class="flex flex-col lg:flex-row lg:justify-end gap-2 w-full">
              <Button :disabled="form.processing" class="h-8 w-full lg:w-auto">
                <Plus class="w-4 h-4" v-if="!form.processing" />
                <LoaderCircle v-if="form.processing" class="h-4 w-4 animate-spin" />
                Salva
              </Button>

              <Link
                as="button"
                :href="generatePath('fornitori/:fornitore/documenti', { fornitore: props.fornitore.id })"
                class="w-full lg:w-auto inline-flex items-center justify-center gap-2 rounded-md bg-primary px-3 py-1.5 text-sm font-medium text-white hover:bg-primary/90"
              >
                <List class="w-4 h-4" />
                <span>Elenco</span>
              </Link>
            </div>

            <Separator class="my-4" />

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
                            ref="fileInputRef"
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
                  </div>
                </div>
              </div>
          </form>
      </FornitoreLayout>
    </AppLayout>
</template>

<style src="vue-select/dist/vue-select.css"></style>