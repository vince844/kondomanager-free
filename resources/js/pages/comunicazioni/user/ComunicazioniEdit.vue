<script setup lang="ts">

import { Head, useForm, Link, usePage } from '@inertiajs/vue3';
import { ref, watch, onMounted } from 'vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { Button } from '@/components/ui/button';
import Heading from '@/components/Heading.vue';
import { Label } from '@/components/ui/label';
import { Input } from '@/components/ui/input';
import { Checkbox } from '@/components/ui/checkbox';
import InputError from '@/components/InputError.vue';
import { Textarea } from '@/components/ui/textarea';
import { LoaderCircle, Pencil, List, Info } from 'lucide-vue-next';
import vSelect from "vue-select";
import { Separator } from '@/components/ui/separator';
import { priorityConstants, publishedConstants } from '@/lib/comunicazioni/constants';
import { HoverCard, HoverCardContent, HoverCardTrigger } from '@/components/ui/hover-card';
import { usePermission } from "@/composables/permissions";
import { Permission } form '@/enums/permissions';
import type { Building } from '@/types/buildings';
import type { Anagrafica } from '@/types/anagrafiche';
import type { Comunicazione } from '@/types/comunicazioni';

const props = defineProps<{
  comunicazione: Comunicazione;
  condomini: Building[];
}>();  

const { hasPermission, generateRoute } = usePermission();

const form = useForm({
  subject: props.comunicazione?.subject ?? '',
  description: props.comunicazione?.description ?? '',
  priority: props.comunicazione?.priority ?? '',
  condomini_ids: props.comunicazione?.condomini?.options?.map(c => c.value) ?? [],
  is_featured: !!props.comunicazione?.is_featured,
  is_private: !!props.comunicazione?.is_private,
});

onMounted(() => {
    form.condomini_ids = props.comunicazione?.condomini?.options?.map(c => c.value) ?? [];
})

watch(
    () => props.anagrafica,
    () => {
        form.condomini_ids = props.comunicazione?.condomini?.options?.map(c => c.value) ?? [];
    }
) 

const submit = () => {
  form.put(route(generateRoute('comunicazioni.update'), { id: props.comunicazione.id }), {
    preserveScroll: true
  });
};

</script>

<template>
  <Head title="Modifica comunicazione" />
  
  <AppLayout>
    <div class="px-4 py-6">
      <Heading title="Modifica comunicazione" description="Compila il seguente modulo per modificare la comunicazione per la bacheca del condominio" />

      <form class="space-y-2" @submit.prevent="submit">
        <!-- Container for buttons (wraps buttons for alignment) -->
        <div class="flex flex-col lg:flex-row lg:justify-end space-y-2 lg:space-y-0 lg:space-x-2 items-start lg:items-center">
          <!-- Button for "Modifica" -->
          <Button :disabled="form.processing" class="lg:flex h-8 w-full lg:w-auto">
            <Pencil class="w-4 h-4" v-if="!form.processing" />
            <LoaderCircle v-if="form.processing" class="h-4 w-4 animate-spin" />
            Salva
          </Button>

          <Link 
            v-if="hasPermission([Permission.VIEW_COMUNICAZIONI])"
            as="button"
            :href="route(generateRoute('comunicazioni.index'))" 
            class="inline-flex items-center gap-2 rounded-md bg-primary text-sm font-medium text-white px-3 py-1.5 h-8 w-full lg:w-auto lg:h-8 hover:bg-primary/90 order-last lg:order-none lg:ml-auto"
          >
            <List class="w-4 h-4" />
            <span>Elenco</span>
          </Link>

        </div>

        <!-- Two-column layout (3:1 ratio) -->
        <div class="grid grid-cols-1 lg:grid-cols-4 gap-3">
          <!-- Main Card (3/4 width) -->
          <div class="col-span-1 lg:col-span-3 mt-3">
            <div class="bg-white dark:bg-muted rounded shadow-sm p-6 space-y-4 border">
              <!-- subject field -->
              <div class="mt-2 grid grid-cols-1 gap-y-6 gap-x-4 sm:grid-cols-6">
                <div class="sm:col-span-3">
                  <Label for="subject">Oggetto comunicazione</Label>
                  <Input 
                    id="subject" 
                    class="mt-1 block w-full"
                    v-model="form.subject" 
                    v-on:focus="form.clearErrors('subject')"
                    placeholder="Oggetto comunicazione" 
                  />
                  <InputError :message="form.errors.subject" />
                </div>
              </div> 

              <div class="mt-2 grid grid-cols-1 gap-y-6 gap-x-4 sm:grid-cols-6">
                <div class="sm:col-span-6">
                  <Label for="description">Descrizione comunicazione</Label>
                  <Textarea 
                    id="description" 
                    class="mt-1 block w-full min-h-[320px]"
                    v-model="form.description" 
                    v-on:focus="form.clearErrors('description')"
                    placeholder="Descrizione comunicazione" 
                  />
                  <InputError :message="form.errors.description" />
                </div>
              </div> 
            </div>
          </div>

          <!-- Side Card (1/4 width) -->
          <div class="col-span-1 mt-3">
            <div class="bg-white dark:bg-muted rounded shadow-sm p-3 border">

              <div class="pt-3 grid grid-cols-1 sm:grid-cols-6">
                <div class="sm:col-span-6">
                  <Label for="priority">Priorità comunicazione</Label>
                  <v-select 
                    id="priority" 
                    :options="priorityConstants" 
                    label="label" 
                    v-model="form.priority"
                    placeholder="Priorità segnalazione"
                    @update:modelValue="form.clearErrors('priority')" 
                    :reduce="(priority: PriorityType) => priority.value"
                  >
                    <!-- Dropdown list items -->
                    <template #option="{ label, icon }">
                      <div class="flex items-center gap-2">
                        <component :is="icon" class="w-4 h-4 text-muted-foreground" />
                        <span>{{ label }}</span>
                      </div>
                    </template>

                    <!-- Selected option display -->
                    <template #selected-option="{ label, icon }">
                      <div class="flex items-center gap-2">
                        <component :is="icon" class="w-4 h-4 text-muted-foreground" />
                        <span>{{ label }}</span>
                      </div>
                    </template>
                  </v-select>
                  <InputError :message="form.errors.priority" />
                </div>
              </div>

              <div class="pt-3 grid grid-cols-1 sm:grid-cols-6">
                  <div class="sm:col-span-6">
                  <Label for="condomini">Condomini</Label>
                  <v-select 
                    multiple
                    :options="condomini"
                    label="label"
                    v-model="form.condomini_ids"
                    placeholder="Condomini"
                    @update:modelValue="form.clearErrors('condomini_ids')" 
                    :reduce="(option: Building) => option.value"
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
                            id="is_featured" 
                            @update:checked="(val) => form.is_private = val" 
                            />
                        <label
                            for="is_private"
                            class="text-sm font-medium leading-none peer-disabled:cursor-not-allowed peer-disabled:opacity-7 flex items-center"
                            >
                            Crea comunicazione privata

                            <HoverCard>
                                <HoverCardTrigger as-child>
                                    <Info class="w-4 h-4 text-muted-foreground cursor-pointer ml-2" />
                                </HoverCardTrigger>
                                <HoverCardContent class="w-80">
                                <div class="flex justify-between space-x-4">
                                    <div class="space-y-1">
                                        <h4 class="text-sm font-semibold">
                                            Crea comunicazione privata
                                        </h4>
                                        <p class="text-sm">
                                            Quando viene selezionata questa opzione la comunicazione verrà resa privata e sarà solo visibile agli amministratori e non a tutti gli altri condòmini
                                        </p>
                                    </div>
                                </div>
                                </HoverCardContent>
                            </HoverCard>

                        </label>
                    </div>
                </div>

              <div class="pt-4 grid grid-cols-1 sm:grid-cols-6">
                <div class="flex items-center space-x-2 sm:col-span-6">
                  <Checkbox 
                    class="size-4" 
                    :checked="form.is_featured"
                    v-model="form.is_featured" 
                    id="is_featured" 
                    @update:checked="(val) => form.is_featured = val" 
                  />
                  <label
                    for="comments"
                    class="text-sm font-medium leading-none peer-disabled:cursor-not-allowed peer-disabled:opacity-70"
                  >
                    Metti comunicazione in evidenza
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