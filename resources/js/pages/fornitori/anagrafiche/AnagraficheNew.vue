<script setup lang="ts">

import { Link, Head, useForm } from '@inertiajs/vue3';
import AppLayout from '@/layouts/AppLayout.vue';
import FornitoreLayout from '@/layouts/fornitori/FornitoreLayout.vue';
import { usePermission } from "@/composables/permissions";
import { Button } from '@/components/ui/button';
import { List, Plus, LoaderCircle, Info} from 'lucide-vue-next';
import { Label } from '@/components/ui/label';
import InputError from '@/components/InputError.vue';
import { Separator } from '@/components/ui/separator';
import { HoverCard, HoverCardContent, HoverCardTrigger } from '@/components/ui/hover-card';
import '@vuepic/vue-datepicker/dist/main.css';
import vSelect from "vue-select";
import type { Fornitore } from '@/types/fornitori';
import type { Anagrafica } from '@/types/anagrafiche';
import type { DropdownType } from '@/types/dropdown';

const props = defineProps<{
  fornitore: Fornitore;
  anagrafiche: Anagrafica[]
}>()

const { generatePath, generateRoute } = usePermission();

const ruoli = [
  {
      label: 'Titolare',
      id: 'titolare',
  },
  {
      label: "Amministrativo",
      id: 'amministrativo',
  },
  {
      label: "Commerciale",
      id: 'commerciale',
  },
  {
      label: "Tecnico",
      id: 'tecnico',
  },
  {
      label: "Referente",
      id: 'referente',
  },
  {
      label: "Altro",
      id: 'altro',
  }
];

const form = useForm({
  anagrafica_id: '',
  ruolo: ''

});

const submit = () => {
    
    form.post(route(...generateRoute('fornitori.anagrafiche.store', { fornitore: props.fornitore.id})), {
        preserveScroll: true,
        onSuccess: () => {
            form.reset()
        }
    });
};

</script>

<template>

    <Head title="Associa anagrafica fornitore" />

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
                :href="generatePath('fornitori/:fornitore/anagrafiche', { fornitore: props.fornitore.id })"
                class="w-full lg:w-auto inline-flex items-center justify-center gap-2 rounded-md bg-primary px-3 py-1.5 text-sm font-medium text-white hover:bg-primary/90"
              >
                <List class="w-4 h-4" />
                <span>Elenco</span>
              </Link>
            </div>

            <Separator class="my-4" />

            <div class="bg-white dark:bg-muted rounded space-y-4 mt-3" >

              <div class="mt-6 grid grid-cols-1 gap-y-6 gap-x-4 sm:grid-cols-6">

                <div class="sm:col-span-3">
                    <div class="flex items-center text-sm font-medium gap-x-2 pb-2">
                        <Label for="anagrafica">Anagrafica</Label>

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
                                    Associazione anagrafica referente
                                </h4>
                                <p class="text-sm">
                                    Puoi associare un'anagrafica al fornitore, se l'anagrafica è associata ad un utente allora potrà accedere al portale online per visualizzare i dati associati a questo fornitore.
                                </p>
                                </div>
                            </div>
                            </HoverCardContent>
                        </HoverCard>
                    </div>

                    <v-select
                      class="w-full"
                      :options="anagrafiche"
                      v-model="form.anagrafica_id"
                      :reduce="(d: Anagrafica) => d.id"
                      label="nome"
                      placeholder="Seleziona anagrafica"
                    >
                      <!-- Dropdown options: stacked layout -->
                      <template #option="{ nome, indirizzo }">
                        <div class="flex flex-col">
                          <span class="font-medium">{{ nome }}</span>
                          <span class="text-sm text-gray-500">{{ indirizzo }}</span>
                        </div>
                      </template>

                      <!-- Selected option: single-line layout -->
                      <template #selected-option="{ nome, indirizzo }">
                        <div class="flex items-center gap-2">
                          <span class="font-medium">{{ nome }}</span>
                          <span class="text-gray-500 text-sm">– {{ indirizzo }}</span>
                        </div>
                      </template>
                    </v-select>

                    <InputError :message="form.errors.anagrafica_id" />
                  </div>

                   <div class="sm:col-span-3">
                        <Label for="ruolo">Ruolo</Label>

                         <v-select
                            class="w-full"
                            :options="ruoli"
                            v-model="form.ruolo"
                            label="label"
                            :reduce="(d: DropdownType) => d.id"
                            placeholder="Seleziona ruolo"
                          />
                    
                        
                        <InputError :message="form.errors.ruolo" />
              
                      </div>
                </div> 


              </div>


          </form>

      </FornitoreLayout>

    </AppLayout>

  </template>

  <style src="vue-select/dist/vue-select.css"></style>