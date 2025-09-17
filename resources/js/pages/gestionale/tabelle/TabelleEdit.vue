<script setup lang="ts">

import { computed } from 'vue';
import { Link, Head, useForm } from '@inertiajs/vue3';
import GestionaleLayout from '@/layouts/GestionaleLayout.vue';
import { usePermission } from "@/composables/permissions";
import { Button } from '@/components/ui/button';
import { List, Plus, LoaderCircle } from 'lucide-vue-next';
import { Label } from '@/components/ui/label';
import { Input } from '@/components/ui/input';
import { Separator } from '@/components/ui/separator';
import InputError from '@/components/InputError.vue';
import { Textarea } from '@/components/ui/textarea';
import vSelect from "vue-select";
import type { Building } from '@/types/buildings';
import type { BreadcrumbItem } from '@/types';
import type { Palazzina } from '@/types/gestionale/palazzine';
import type { Scala } from '@/types/gestionale/scale';
import type { Tabella } from '@/types/gestionale/tabelle';

const props = defineProps<{
  condominio: Building;
  tabella: Tabella;
  palazzine: Palazzina[];
  scale: Scala[];
}>()

type TabellaType = {
  label: string;
  id: string;
};

const tipologieTabelle = [
  { label: 'Standard', id: 'standard' },
  { label: "Ascensore", id: 'ascensore' },
  { label: "Riscaldamento", id: 'riscaldamento' },
  { label: "Acqua", id: 'acqua' },
  { label: "Lastrico", id: 'lastrico' },
  { label: "Speciale", id: 'speciale' },
  { label: "Altro", id: 'altro' }
];

const unitaMisura = [
  { label: 'Millesimi', id: 'millesimi' },
  { label: "Persone", id: 'persone' },
  { label: "Quote", id: 'quote' },
  { label: "Kilowatt", id: 'kwatt' },
  { label: "Metri cubi", id: 'mtcubi' },
];

const { generatePath, generateRoute } = usePermission();

const breadcrumbs = computed<BreadcrumbItem[]>(() => [
  { title: 'Gestionale', href: generatePath('gestionale/:condominio', { condominio: props.condominio.id }) },
  { title: props.condominio.nome, href: '#' },
  { title: 'tabelle', href: generatePath('gestionale/:condominio/tabelle', { condominio: props.condominio.id }) },
  { title: props.tabella.nome, href: '#' },
  { title: 'modifica tabella', href: '#' },
]);

const form = useForm({
  nome: props.tabella.nome,
  tipo: props.tabella.tipo,
  quota: props.tabella.quota,
  descrizione: props.tabella.descrizione,
  note: props.tabella.note,
  palazzina_id: props.tabella.palazzina ? props.tabella.palazzina.id : '',
  scala_id: props.tabella.scala ? props.tabella.scala.id : '',
});


const submit = () => {
    form.put(route(...generateRoute('gestionale.tabelle.update', { condominio: props.condominio.id, tabella: props.tabella.id })), {
        preserveScroll: true,
        onSuccess: () => {
            form.reset()
        }
    });
};

</script>

<template>
  <Head title="Modifica tabella" />

  <GestionaleLayout :breadcrumbs="breadcrumbs">

    <div class="px-4 py-6">

      <div class="w-full shadow ring-1 ring-black/5 md:rounded-lg p-4">
        <section class="w-full">
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
                :href="generatePath('gestionale/:condominio/tabelle', { condominio: props.condominio.id })"
                class="w-full lg:w-auto inline-flex items-center justify-center gap-2 rounded-md bg-primary px-3 py-1.5 text-sm font-medium text-white hover:bg-primary/90"
              >
                <List class="w-4 h-4" />
                <span>Elenco</span>
              </Link>
            </div>

            <Separator class="my-4" />

            <div class="col-span-1 mt-3">
              <div class="grid grid-cols-1 sm:grid-cols-6 gap-4">
                <div class="sm:col-span-4">
                  <Label for="nome">Nome tabella</Label>
                  <Input
                    id="nome"
                    class="mt-1 block w-full"
                    v-model="form.nome"
                    @focus="form.clearErrors('nome')"
                    placeholder="Nome tabella"
                  />
                  <InputError :message="form.errors.nome" />
                </div>

                <div class="sm:col-span-2">
                  <Label for="unit">Unità di misura</Label>
                  <v-select
                    class="w-full mt-1"
                    :options="unitaMisura"
                    label="label"
                    v-model="form.quota"
                    :reduce="(d: TabellaType) => d.id"
                    placeholder="Seleziona unità di misura"
                  />
   
                  <InputError :message="form.errors.quota" />
                </div>
              </div>

              <div class="mt-6 grid grid-cols-1 gap-y-6 gap-x-4 sm:grid-cols-6">

                <div class="sm:col-span-2">
                  <Label for="tipologia">Tipologia</Label>
                  <v-select
                    class="w-full mt-1"
                    :options="tipologieTabelle"
                    label="label"
                    v-model="form.tipo"
                    :reduce="(d: TabellaType) => d.id"
                    placeholder="Seleziona tipologia tabella"
                  />
                  <InputError :message="form.errors.tipo" />
                </div>

                <div class="sm:col-span-2">
                  <Label for="palazzina">Palazzina</Label>
                    <v-select 
                        :options="condominio.palazzine" 
                        label="name" 
                        class="mt-1 block w-full"
                        v-model="form.palazzina_id"
                        placeholder="Associa ad una palazzina"
                        @update:modelValue="form.clearErrors('palazzina_id')" 
                        :reduce="(palazzina: Palazzina) => palazzina.id"
                    />
                  <InputError :message="form.errors.palazzina_id" />
                </div>

                <div class="sm:col-span-2">
                  <Label for="scala">Scala</Label>
                  <v-select 
                      :options="condominio.scale" 
                      label="name" 
                      class="mt-1 block w-full"
                      v-model="form.scala_id"
                      placeholder="Associa ad una scala"
                      @update:modelValue="form.clearErrors('scala_id')" 
                      :reduce="(scala: Scala) => scala.id"
                  />
                  <InputError :message="form.errors.scala_id" />
                </div>
                
              </div>

              <div class="mt-6 grid grid-cols-1 gap-y-6 gap-x-4 sm:grid-cols-6">
                <div class="sm:col-span-6">
                  <Label for="descrizione">Descrizione</Label>
                  <Textarea
                    id="descrizione"
                    class="mt-1 block w-full"
                    v-model="form.descrizione"
                    placeholder="Descrizione tabella"
                  />
                  <InputError :message="form.errors.descrizione" />
                </div>
              </div>

              <div class="mt-6 grid grid-cols-1 gap-y-6 gap-x-4 sm:grid-cols-6">
                <div class="sm:col-span-6">
                  <Label for="note">Note</Label>
                  <Textarea
                    id="note"
                    class="mt-1 block w-full"
                    v-model="form.note"
                    placeholder="Inserisci una nota qui"
                  />
                  <InputError :message="form.errors.note" />
                </div>
              </div>

            </div>

          </form>
        </section>
      </div>

    </div>

  </GestionaleLayout>
</template>

<style src="vue-select/dist/vue-select.css"></style>
