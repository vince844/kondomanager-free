<script setup lang="ts">

import { computed } from 'vue';
import { Link, Head, useForm } from '@inertiajs/vue3';
import GestionaleLayout from '@/layouts/GestionaleLayout.vue';
import StrutturaLayout from '@/layouts/gestionale/StrutturaLayout.vue';
import { usePermission } from "@/composables/permissions";
import CondominioDropdown from "@/components/CondominioDropdown.vue";
import { Button } from '@/components/ui/button';
import { List, Plus, LoaderCircle} from 'lucide-vue-next';
import { Label } from '@/components/ui/label';
import { Input } from '@/components/ui/input';
import InputError from '@/components/InputError.vue';
import { Textarea } from '@/components/ui/textarea';
import type { Building } from '@/types/buildings'
import type { BreadcrumbItem } from '@/types';

const props = defineProps<{
  condominio: Building;
  condomini: Building[];
}>()

const { generatePath, generateRoute } = usePermission();

const breadcrumbs = computed<BreadcrumbItem[]>(() => [
  { title: 'Gestionale', href: generatePath('gestionale/:condominio', { condominio: props.condominio.id }) },
  { title: props.condominio.nome, component: "condominio-dropdown" } as any,
  { title: 'crea palazzina', href: '#' },
]);

const form = useForm({
  name: '',
  description: '',
  note: '',
});

const submit = () => {
    form.post(route(...generateRoute('gestionale.palazzine.store', { condominio: props.condominio.id })), {
        preserveScroll: true,
        onSuccess: () => {
            form.reset()
        }
    });
};

</script>

<template>

    <Head title="Crea nuova palazzina" />

    <GestionaleLayout :breadcrumbs="breadcrumbs">

      <template #breadcrumb-condominio>
        <CondominioDropdown :condominio="props.condominio" :condomini="props.condomini" />
      </template>

      <StrutturaLayout>

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
                :href="generatePath('gestionale/:condominio/palazzine', { condominio: props.condominio.id })"
                class="w-full lg:w-auto inline-flex items-center justify-center gap-2 rounded-md bg-primary px-3 py-1.5 text-sm font-medium text-white hover:bg-primary/90"
              >
                <List class="w-4 h-4" />
                <span>Elenco</span>
              </Link>
            </div>

            <div class="bg-white dark:bg-muted rounded shadow-sm p-3 space-y-4 border mt-3" >

              <div class="mt-2 grid grid-cols-1 gap-y-6 gap-x-4 sm:grid-cols-6">
                  <div class="sm:col-span-3">
                    <Label for="nome">Nome</Label>
                    <Input 
                      id="nome" 
                      class="mt-1 block w-full"
                        v-model="form.name" 
                        v-on:focus="form.clearErrors('name')"
                        placeholder="Nome" 
                    />
                    
                    <InputError :message="form.errors.name" />
          
                  </div>
              </div> 

              <div class="mt-6 grid grid-cols-1 gap-y-6 gap-x-4 sm:grid-cols-6">
                  <div class="sm:col-span-6">
                    <Label for="descrizione">Descrizione</Label>
                    <Input 
                      id="descrizione" 
                      class="mt-1 block w-full"
                        v-model="form.description" 
                        v-on:focus="form.clearErrors('description')"
                        placeholder="Descrizione" 
                    />
                    
                    <InputError class="mt-2" :message="form.errors.description" />
          
                  </div>
              </div>

              <div class="mt-6 grid grid-cols-1 gap-y-6 gap-x-4 sm:grid-cols-6">
                  <div class="sm:col-span-6">
                      <Label for="note">Note</Label>
                      <Textarea 
                          id="note" 
                          placeholder="Inserisci una nota qui" 
                          v-model="form.note" 
                          v-on:focus="form.clearErrors('note')"
                      />
                  </div>

                  <InputError :message="form.errors.note" />
        
              </div>
       
            </div>

          </form>

      </StrutturaLayout>

    </GestionaleLayout>

  </template>