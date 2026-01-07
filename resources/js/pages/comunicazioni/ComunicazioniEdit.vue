<script setup lang="ts">

import { Head, useForm, Link } from '@inertiajs/vue3';
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
import axios from 'axios';
import { trans } from 'laravel-vue-i18n';
import { usePermission } from "@/composables/permissions";
import type { Building } from '@/types/buildings';
import type { Anagrafica } from '@/types/anagrafiche';
import type { Comunicazione } from '@/types/comunicazioni';
import type { PriorityType, PublishedType } from '@/types/comunicazioni';

const props = defineProps<{
  comunicazione: Comunicazione;
  condomini: Building[];
  anagrafiche: Anagrafica[];
}>();  

const { generateRoute,generatePath } = usePermission();

const anagraficheOptions = ref<Anagrafica[]>(props.anagrafiche);

const form = useForm({
  subject: props.comunicazione?.subject ?? '',
  description: props.comunicazione?.description ?? '',
  priority: props.comunicazione?.priority ?? '',
  condomini_ids: props.comunicazione?.condomini?.options?.map(c => c.value) ?? [],
  can_comment: !!props.comunicazione?.can_comment,
  is_featured: !!props.comunicazione?.is_featured,
  is_published: !!props.comunicazione?.is_published,
  anagrafiche: (props.comunicazione?.anagrafiche ?? []).map(anagrafica => anagrafica.id)
});

onMounted(() => {
  // Initialize form values
  form.condomini_ids = props.comunicazione?.condomini?.options?.map(c => c.value) ?? [];
  form.anagrafiche = (props.comunicazione?.anagrafiche ?? []).map(a => a.id);
});

watch(
  () => props.comunicazione,
  (newComunicazione) => {
    if (newComunicazione) {
      form.condomini_ids = newComunicazione.condomini?.options?.map(c => c.value) ?? [];
      form.anagrafiche = newComunicazione.anagrafiche?.map(a => a.id) ?? [];
    }
  },
  { deep: true }
);

watch(
  () => form.condomini_ids,
  async (newCondominiIds) => {
    if (newCondominiIds.length > 0) {
      try {
        const response = await axios.get(generatePath('fetch-anagrafiche'), {
          params: { condomini_ids: newCondominiIds }
        });

        anagraficheOptions.value = response.data;

        // Remove selected anagrafiche that are no longer valid
        const validIds = response.data.map((a: Anagrafica) => a.id);
        form.anagrafiche = form.anagrafiche.filter((id) => validIds.includes(id));

      } catch (error) {
        console.error('Error fetching anagrafiche:', error);
      }
    } else {
      anagraficheOptions.value = [];
      form.anagrafiche = [];
    }
  }
);

const submit = () => {
  form.put(route(generateRoute('comunicazioni.update'), { id: props.comunicazione.id }), {
    preserveScroll: true
  });
};

</script>


<template>
  <Head :title="trans('comunicazione.header.edit_communication_head')" />
  
  <AppLayout>
    <div class="px-4 py-6">

      <Heading 
            :title="trans('comunicazioni.header.edit_communication_title')" 
            :description="trans('comunicazioni.header.edit_communication_description')" 
      />

      <form class="space-y-2" @submit.prevent="submit">
        
          <!-- Action buttons -->
          <div class="flex flex-col lg:flex-row lg:justify-end gap-2 w-full">
              <Button :disabled="form.processing" class="h-8 w-full lg:w-auto">
                  <Pencil class="w-4 h-4" v-if="!form.processing" />
                  <LoaderCircle v-if="form.processing" class="h-4 w-4 animate-spin" />
                  {{trans('comunicazioni.actions.save_communication')}}
              </Button>

              <Link
                  as="button"
                  :href="route(generateRoute('comunicazioni.index'))"
                  class="w-full lg:w-auto inline-flex items-center justify-center gap-2 rounded-md bg-primary px-3 py-1.5 text-sm font-medium text-white hover:bg-primary/90"
              >
                  <List class="w-4 h-4" />
                  <span>{{ trans('comunicazioni.actions.list_communications') }}</span>
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
                    <Label for="subject">{{ trans('comunicazioni.label.subject') }}</Label>
                    <Input 
                      id="subject" 
                      class="mt-1 block w-full"
                      v-model="form.subject" 
                      v-on:focus="form.clearErrors('subject')"
                      :placeholder="trans('comunicazioni.placeholder.subject')" 
                    />
                    <InputError :message="form.errors.subject" />
                  </div>
                </div> 

                <div class="mt-2 grid grid-cols-1 gap-y-6 gap-x-4 sm:grid-cols-6">
                  <div class="sm:col-span-6">
                    <Label for="description">{{ trans('comunicazioni.label.description') }}</Label>
                    <Textarea 
                      id="description" 
                      class="mt-1 block w-full min-h-[320px]"
                      v-model="form.description" 
                      v-on:focus="form.clearErrors('description')"
                      :placeholder="trans('comunicazioni.placeholder.description')" 
                    />
                    <InputError :message="form.errors.description" />
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
                        <Label for="stato">{{ trans('comunicazioni.label.visibility') }}</Label>

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
                                    {{ trans('comunicazioni.label.visibility') }}
                                </h4>
                                <p class="text-sm">
                                    {{ trans('comunicazioni.tooltip.visibility') }}
                                </p>
                                </div>
                            </div>
                            </HoverCardContent>
                        </HoverCard>
                    </div>

                    <v-select 
                      id="stato" 
                      :options="publishedConstants" 
                      label="label" 
                      v-model="form.is_published"
                      :placeholder="trans('comunicazioni.placeholder.visibility')" 
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
                  <div class="sm:col-span-6">

                    <div class="flex items-center text-sm font-medium mb-1 gap-x-2">
                        <Label for="priority">{{ trans('comunicazioni.label.priority') }}</Label>

                        <HoverCard>
                            <HoverCardTrigger as-child>
                            <button type="button" class="cursor-pointer">
                                <Info class="w-4 h-4 text-muted-foreground" />
                            </button>
                            </HoverCardTrigger>
                            <HoverCardContent class="w-80">
                            <div class="flex justify-between space-x-4">
                                <div class="space-y-1">
                                <h4 class="text-sm font-semibold">{{ trans('comunicazioni.label.priority') }}</h4>
                                <p class="text-sm">
                                    {{ trans('comunicazioni.tooltip.priority') }}
                                </p>
                                </div>
                            </div>
                            </HoverCardContent>
                        </HoverCard>
                    </div>

                    <v-select 
                      id="priority" 
                      :options="priorityConstants" 
                      label="label" 
                      v-model="form.priority"
                      :placeholder="trans('comunicazioni.placeholder.priority')" 
                      @update:modelValue="form.clearErrors('priority')" 
                      :reduce="(priority: PriorityType) => priority.value"
                    >
                      <!-- Dropdown list items -->
                      <template #option="{ label, icon }">
                        <div class="flex items-center gap-2">
                          <component :is="icon" class="w-4 h-4 text-muted-foreground" />
                          <span>{{ trans(label) }}</span>
                        </div>
                      </template>

                      <!-- Selected option display -->
                      <template #selected-option="{ label, icon }">
                        <div class="flex items-center gap-2">
                          <component :is="icon" class="w-4 h-4 text-muted-foreground" />
                          <span>{{ trans(label)}}</span>
                        </div>
                      </template>
                    </v-select>

                    <InputError :message="form.errors.priority" />
                  </div>
                </div>

                <div class="pt-3 grid grid-cols-1 sm:grid-cols-6">
                    <div class="sm:col-span-6">
                    <Label for="condomini">{{ trans('comunicazioni.label.buildings') }}</Label>
                    <v-select 
                      multiple
                      :options="condomini"
                      label="label"
                      v-model="form.condomini_ids"
                      :placeholder="trans('comunicazioni.placeholder.buildings')"
                      @update:modelValue="form.clearErrors('condomini_ids')" 
                      :reduce="(option: Building) => option.value"
                    />
                
                    <InputError :message="form.errors.condomini_ids" />
                    </div>
                </div>

                <div class="pt-3 grid grid-cols-1 sm:grid-cols-6">
                  <div class="sm:col-span-6">
                    <Label for="anagrafiche">{{ trans('comunicazioni.label.residents') }}</Label>
                    <v-select
                      multiple
                      id="anagrafiche"
                      :options="anagraficheOptions"
                      label="nome"
                      v-model="form.anagrafiche"
                      :placeholder="trans('comunicazioni.placeholder.residents')"
                      @update:modelValue="form.clearErrors('anagrafiche')"
                      :reduce="(anagrafica: Anagrafica) => anagrafica.id"
                      :disabled="form.condomini_ids.length === 0"
                    />
                    <InputError :message="form.errors.anagrafiche" />
                  </div>
                </div>

                <Separator class="my-4" />

                <div class="grid grid-cols-1 sm:grid-cols-6">
                  <div class="flex items-center space-x-2 sm:col-span-6">
                    <Checkbox 
                      class="size-4" 
                      :checked="form.can_comment"
                      v-model="form.can_comment" 
                      id="can_comment" 
                      @update:checked="(val: boolean) => form.can_comment = val" 
                    />
                    <label
                      for="comments"
                      class="text-sm font-medium leading-none peer-disabled:cursor-not-allowed peer-disabled:opacity-70"
                    >
                      {{ trans('comunicazioni.label.comments') }}
                    </label>

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
                                    {{ trans('comunicazioni.label.comments') }}
                                </h4>
                                <p class="text-sm">
                                    {{ trans('comunicazioni.tooltip.comments') }}
                                </p>
                            </div>
                        </div>
                        </HoverCardContent>
                    </HoverCard>

                  </div>
                </div>

                <div class="pt-4 grid grid-cols-1 sm:grid-cols-6">
                  <div class="flex items-center space-x-2 sm:col-span-6">
                    <Checkbox 
                      class="size-4" 
                      :checked="form.is_featured"
                      v-model="form.is_featured" 
                      id="is_featured" 
                      @update:checked="(val: boolean) => form.is_featured = val" 
                    />
                    <label
                      for="comments"
                      class="text-sm font-medium leading-none peer-disabled:cursor-not-allowed peer-disabled:opacity-70"
                    >
                      {{ trans('comunicazioni.label.featured') }}
                    </label>
                     <!-- HoverCard moved OUTSIDE label -->
                    <HoverCard>
                        <HoverCardTrigger as-child>
                        <button type="button" class="cursor-pointer">
                            <Info class="w-4 h-4 text-muted-foreground" />
                        </button>
                        </HoverCardTrigger>
                        <HoverCardContent class="w-80 z-50">
                        <div class="flex justify-between space-x-4">
                            <div class="space-y-1">
                            <h4 class="text-sm font-semibold">{{ trans('comunicazioni.label.featured') }}</h4>
                            <p class="text-sm">
                                {{ trans('comunicazioni.tooltip.featured') }}
                            </p>
                            </div>
                        </div>
                        </HoverCardContent>
                    </HoverCard>
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