<script setup lang="ts">

import { Link, Head, useForm } from '@inertiajs/vue3';
import { ref, watch } from 'vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { Button } from '@/components/ui/button';
import { List, Plus, LoaderCircle, UploadCloud, Info } from 'lucide-vue-next';
import Heading from '@/components/Heading.vue';
import { Label } from '@/components/ui/label';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import InputError from '@/components/InputError.vue';
import { HoverCard, HoverCardContent, HoverCardTrigger } from '@/components/ui/hover-card';
import vSelect from "vue-select";
import { usePermission } from '@/composables/permissions';
import { publishedConstants } from '@/lib/documenti/constants';
import type { PublishedType } from '@/types/documenti';
import type { Categoria } from '@/types/categorie';
import type { Documento } from '@/types/documenti';

const props = defineProps<{
  /** Limite di caricamento già scritto per l'utente («2 MB»), calcolato dal server: non è un numero nostro. */
  limiteFile: string;
  documento: Documento;
  categories: Categoria[];
}>()

const { generateRoute } = usePermission();

const localCategories = ref(props.categories);
const file = ref<File | null>(null)
const progress = ref<number | null>(null)

const form = useForm({
  name: props.documento?.name ?? '',
  description: props.documento?.description ?? '',
  is_published: !!props.documento?.is_published,
  category_id: props.documento?.categoria?.id ?? null, 
  file: null as File | null,
});

/*
 * ⚠️ **Il pannello «crea nuova categoria» è stato tolto da questa pagina nella beta.62, e non
 * per scelta di prodotto: non ha mai potuto funzionare.**
 *
 * Chiamava `route(generateRoute('categorie.store'))`, e `generateRoute` antepone il prefisso del
 * ruolo di chi guarda. Questa schermata la vede **solo il condòmino**, quindi il nome risolto era
 * `user.categorie.store` — che non esiste: le categorie dell'archivio si gestiscono dall'area
 * amministratore, e il controller dell'area utente implementa soltanto `index` e `show`. Ziggy
 * sollevava, l'eccezione finiva nel `catch` e diventava un `console.error`: il condòmino apriva il
 * pannello, scriveva nome e descrizione, premeva «Salva» e **non succedeva niente**, senza un
 * messaggio.
 *
 * Tolto invece che riparato, perché la regola del progetto è quella della beta.54: *quando una
 * funzione non è applicabile a una schermata, la si rimuove da quella schermata* — lasciarla
 * inerte insegna che i comandi del prodotto non sono affidabili. Il segnale che era di troppo era
 * già in casa: la pagina di **creazione** di un documento, per lo stesso condòmino, il pannello
 * non ce l'ha.
 *
 * Trovato dalla guardia `NomiDiRottaCheNonEsistonoTest` nella sua seconda regola — quella che sui
 * file dell'area utente pretende che il nome esista col prefisso `user.`.
 */

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

  form.post(route(generateRoute('documenti.update'), { id: props.documento.id }), {
    preserveScroll: true,
    method: 'put',
    onStart: () => {
      progress.value = 0;
    },
    onProgress: (event) => {
      if (event?.percentage) {
        progress.value = Math.round(event.percentage);
      }
    },
    onSuccess: () => {
      progress.value = null;
      form.reset();
      file.value = null;
    },
    onFinish: () => {
      progress.value = null;
    },
  });
};

</script>


<template>
  <Head title="Modifica documento" />

  <AppLayout >
    <div class="px-4 py-6">

      <Heading
        title="Modifica documento archivio"
        description="Compila il seguente modulo per modificare documento per l'archivo del condominio"
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
            :href="route(generateRoute('categorie-documenti.index'))"
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
                            <!-- Il limite lo dice il server: queste due schermate prima non lo
                                 dicevano affatto, e l'utente lo scopriva solo fallendo. -->
                            <span class="mt-1 block text-xs">Solo PDF (max {{ props.limiteFile }})</span>
                          </span>
                          <input
                            id="file-upload"
                            type="file"
                            name="file" 
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
                        </div>

                        <InputError :message="form.errors.category_id" />
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
