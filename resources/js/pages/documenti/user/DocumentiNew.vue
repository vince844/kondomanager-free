<script setup lang="ts">

import { Link, Head, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { List, Plus, LoaderCircle, UploadCloud, Info } from 'lucide-vue-next';
import Heading from '@/components/Heading.vue';
import { Label } from '@/components/ui/label';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import InputError from '@/components/InputError.vue';
import { HoverCard, HoverCardContent, HoverCardTrigger } from '@/components/ui/hover-card';
import { Separator } from '@/components/ui/separator';
import vSelect from "vue-select";
import { usePermission } from '@/composables/permissions';
import type { Building } from '@/types/buildings';

const props = defineProps<{
  categoria: number;
  condomini: Building[];
}>()

const { generatePath } = usePermission()
const file = ref<File | null>(null)
const progress = ref<number | null>(null)

const form = useForm({
  name: '',
  description: '',
  is_published: true,
  condomini_ids: [],
  category_id: props.categoria,
  is_private: false as boolean,
  file: null as File | null,
})

function handleFileChange(event: Event) {
  const target = event.target as HTMLInputElement
  if (target?.files?.length) {
    const selectedFile = target.files[0]
    if (selectedFile.type !== 'application/pdf') {
      alert("Solo file PDF sono ammessi.")
      return
    }

    file.value = selectedFile
    form.file = selectedFile
  }
}

const submit = () => {
  form.post(route("user.documenti.store"), {
    preserveScroll: true,
    onStart: () => {
      progress.value = 0
    },
    onProgress: (event) => {
      if (event?.percentage) {
        progress.value = Math.round(event.percentage)
      }
    },
    onSuccess: () => {
      progress.value = null
      form.reset()
      file.value = null
    },
    onFinish: () => {
      progress.value = null
    },
  })
}
</script>


<template>
  <Head title="Crea nuovo documento" />

<!--   <AppLayout :breadcrumbs="breadcrumbs"> -->
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
            :href="route('user.categorie-documenti.index')"
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
                          class="mt-2 flex flex-col items-center justify-center w-full h-48 p-6 border-2 border-dashed rounded-lg cursor-pointer bg-white dark:bg-gray-900 hover:bg-gray-100 dark:hover:bg-gray-800 transition"
                        >
                          <UploadCloud class="w-10 h-10 mb-2 text-gray-400" />
                          <span class="text-gray-500 dark:text-gray-400 text-center">
                            <strong>Clicca qui per selezionare il documento</strong>
                          </span>
                          <input
                            id="file-upload"
                            type="file"
                            class="hidden"
                            accept="application/pdf"
                            @change="handleFileChange"
                          />
                        </label>

                        <div v-if="file" class="mt-2 text-sm text-gray-600 dark:text-gray-300">
                          File selezionato: <strong>{{ file.name }}</strong>
                        </div>
                        <InputError :message="form.errors.file" />

                        <!-- Progress bar -->
                        <div v-if="progress !== null" class="mt-4">
                          <div class="w-full h-2 bg-gray-200 rounded overflow-hidden">
                            <div
                              class="h-full bg-blue-600 transition-all duration-300"
                              :style="{ width: `${progress}%` }"
                            ></div>
                          </div>
                          <p class="text-xs text-gray-600 mt-1">{{ progress }}%</p>
                        </div>
                      </div>
                    </div>

                </div>
            </div>

            <!-- Side Card (1/4 width) -->
            <div class="col-span-1 mt-3">
                <div class="bg-white dark:bg-muted rounded shadow-sm p-3 border">

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

                    <Separator class="my-4" />

                    <div class="pt-4 grid grid-cols-1 sm:grid-cols-6">
                        <div class="flex items-center space-x-2 sm:col-span-6">
                            <Checkbox 
                                class="size-4" 
                                :checked="form.is_private"
                                v-model="form.is_private" 
                                id="is_private" 
                                @update:checked="(val) => form.is_private = val" 
                                />
                            <label
                                for="is_private"
                                class="text-sm font-medium leading-none peer-disabled:cursor-not-allowed peer-disabled:opacity-7 flex items-center"
                                >
                                Crea documento privato

                                <HoverCard>
                                    <HoverCardTrigger as-child>
                                        <Info class="w-4 h-4 text-muted-foreground cursor-pointer ml-2" />
                                    </HoverCardTrigger>
                                    <HoverCardContent class="w-80">
                                    <div class="flex justify-between space-x-4">
                                        <div class="space-y-1">
                                            <h4 class="text-sm font-semibold">
                                                Crea documento privato
                                            </h4>
                                            <p class="text-sm">
                                                Quando viene selezionata questa opzione il documento verrà reso privato e sarà solo visibile agli amministratori e non a tutti gli altri condòmini
                                            </p>
                                        </div>
                                    </div>
                                    </HoverCardContent>
                                </HoverCard>

                            </label>
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
