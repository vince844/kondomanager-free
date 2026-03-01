<script setup lang="ts">
import { computed } from 'vue';
import { Link, Head, useForm } from '@inertiajs/vue3';
import AppLayout from '@/layouts/AppLayout.vue';
import PageHeaderGuide from '@/components/PageHeaderGuide.vue';
import { Button } from '@/components/ui/button';
import { Save, LoaderCircle, Building2, MapPin, Info } from 'lucide-vue-next';
import { Label } from '@/components/ui/label';
import { Input } from '@/components/ui/input';
import InputError from '@/components/InputError.vue';
import { Textarea } from '@/components/ui/textarea';
import { trans } from 'laravel-vue-i18n';
import type { BreadcrumbItem } from '@/types';
import type { Building } from '@/types/buildings';
import { Card, CardHeader, CardTitle, CardDescription, CardContent } from '@/components/ui/card';

const props = defineProps<{ building: Building }>();

const breadcrumbs: BreadcrumbItem[] = [
  {
      title: trans('condomini.header.list_buildings_title'),
      href: route('condomini.index') 
  },
  {
      title: trans('condomini.header.edit_building_title'),
      href: '#',
  }
];

const pageGuides = computed(() => [
  {
    title: trans('condomini.guides.edit_info_title'),
    description: trans('condomini.guides.edit_info_desc'),
    icon: Building2,
    colorVariant: 'blue' as const
  },
  {
    title: trans('condomini.guides.edit_registry_title'),
    description: trans('condomini.guides.edit_registry_desc'),
    icon: MapPin,
    colorVariant: 'amber' as const
  },
  {
    title: trans('condomini.guides.edit_notes_title'),
    description: trans('condomini.guides.edit_notes_desc'),
    icon: Info,
    colorVariant: 'emerald' as const
  }
]);

const form = useForm({
    id: props.building.id,
    nome: props.building.nome || '',
    codice_fiscale: props.building.codice_fiscale || '',
    email: props.building.email || '',
    note: props.building.note || '',
    indirizzo: props.building.indirizzo || '',
    comune: props.building.comune || '',
    provincia: props.building.provincia || '',
    cap: props.building.cap || '',
    anno_costruzione: props.building.anno_costruzione || '',
    anno_acquisizione: props.building.anno_acquisizione || '',
    numero_piani: props.building.numero_piani || '',
    comune_catasto: props.building.comune_catasto || '',
    codice_catasto: props.building.codice_catasto || '',
    sezione_catasto: props.building.sezione_catasto || '',
    foglio_catasto: props.building.foglio_catasto || '',
    particella_catasto: props.building.particella_catasto || '',
});

const submit = () => {
    form.put(route("condomini.update", { id: props.building.id }), {
        preserveScroll: true,
    });
};
</script>

<template>
  <Head :title="trans('condomini.header.edit_building_head')" />

  <AppLayout>
    <div class="px-6 py-8 space-y-6">
      
      <PageHeaderGuide
        :page-title="`Modifica ${props.building.nome}`"
        :page-subtitle="trans('condomini.header.edit_building_description')"
        :guides="pageGuides"
        :breadcrumbs="breadcrumbs"
        :video-url="null"
        :back-url="route('condomini.index')"
        :back-text="trans('condomini.actions.list_buildings')"
      />

      <form @submit.prevent="submit" class="space-y-6">

        <Card class="border-dashed shadow-sm bg-slate-50/50 dark:bg-slate-900/20">
            <CardHeader class="pb-3 border-b border-dashed mb-4">
                <CardTitle class="text-base font-semibold">{{ trans('condomini.cards.info_title') }}</CardTitle>
                <CardDescription>{{ trans('condomini.cards.info_desc') }}</CardDescription>
            </CardHeader>
            <CardContent class="space-y-6">
                <div class="grid grid-cols-1 gap-x-4 gap-y-4 sm:grid-cols-6">
                    
                    <div class="sm:col-span-3">
                        <Label for="nome">{{ trans('condomini.label.name') }} *</Label>
                        <Input 
                            id="nome" 
                            v-model="form.nome" 
                            @focus="form.clearErrors('nome')"
                            :placeholder="trans('condomini.placeholder.name')" 
                            class="mt-1 bg-white" 
                        />
                        <InputError :message="form.errors.nome" />
                    </div>

                    <div class="sm:col-span-3 sm:col-start-1">
                        <Label for="codice_fiscale">{{ trans('condomini.label.tax_code') }}</Label>
                        <Input 
                            id="codice_fiscale" 
                            v-model="form.codice_fiscale" 
                            @focus="form.clearErrors('codice_fiscale')"
                            :placeholder="trans('condomini.placeholder.tax_code')" 
                            class="mt-1 bg-white" 
                        />
                        <InputError :message="form.errors.codice_fiscale" />
                    </div>

                    <div class="sm:col-span-3">
                        <Label for="email">{{ trans('condomini.label.email') }}</Label>
                        <Input 
                            id="email" 
                            type="email"
                            v-model="form.email" 
                            @focus="form.clearErrors('email')"
                            :placeholder="trans('condomini.placeholder.email')" 
                            class="mt-1 bg-white" 
                        />
                        <InputError :message="form.errors.email" />
                    </div>

                    <div class="sm:col-span-6 mt-2 mb-2 border-t border-dashed"></div>

                    <div class="sm:col-span-6">
                        <Label for="note">{{ trans('condomini.label.notes') }}</Label>
                        <Textarea 
                            id="note" 
                            class="mt-1 w-full bg-white dark:bg-slate-950" 
                            :placeholder="trans('condomini.placeholder.notes')" 
                            v-model="form.note" 
                            @focus="form.clearErrors('note')"
                        />
                        <InputError :message="form.errors.note" />
                        <p class="text-[11px] text-muted-foreground mt-1 italic">{{ trans('condomini.cards.notes_helper') }}</p>
                    </div>
                    
                </div>
            </CardContent>
        </Card>

        <Card class="border-dashed shadow-sm bg-slate-50/50 dark:bg-slate-900/20">
            <CardHeader class="pb-3 border-b border-dashed mb-4">
                <CardTitle class="text-base font-semibold">{{ trans('condomini.cards.location_title') }}</CardTitle>
                <CardDescription>{{ trans('condomini.cards.location_desc') }}</CardDescription>
            </CardHeader>
            <CardContent class="space-y-6">
                <div class="grid grid-cols-1 gap-x-4 gap-y-4 sm:grid-cols-6">
                    <div class="sm:col-span-6">
                        <Label for="indirizzo">{{ trans('condomini.label.address') }}</Label>
                        <Input 
                          id="indirizzo"
                          v-model="form.indirizzo" 
                          @focus="form.clearErrors('indirizzo')"
                          :placeholder="trans('condomini.placeholder.address')" 
                          class="mt-1 bg-white" 
                        />
                        <InputError :message="form.errors.indirizzo" />
                    </div>
                    
                    <div class="sm:col-span-3">
                        <Label for="comune">{{ trans('condomini.label.city') }}</Label>
                        <Input 
                          id="comune"
                          v-model="form.comune" 
                          @focus="form.clearErrors('comune')"
                          :placeholder="trans('condomini.placeholder.city')" 
                          class="mt-1 bg-white" 
                        />
                        <InputError :message="form.errors.comune" />
                    </div>

                    <div class="sm:col-span-1">
                        <Label for="provincia">{{ trans('condomini.label.province') }}</Label>
                        <Input 
                          id="provincia"
                          v-model="form.provincia" 
                          @focus="form.clearErrors('provincia')"
                          :placeholder="trans('condomini.placeholder.province')" 
                          class="mt-1 bg-white" 
                          maxlength="2"
                        />
                        <InputError :message="form.errors.provincia" />
                    </div>

                    <div class="sm:col-span-2">
                        <Label for="cap">{{ trans('condomini.label.zip_code') }}</Label>
                        <Input 
                          id="cap"
                          v-model="form.cap" 
                          @focus="form.clearErrors('cap')"
                          :placeholder="trans('condomini.placeholder.zip_code')" 
                          class="mt-1 bg-white" 
                          maxlength="5"
                        />
                        <InputError :message="form.errors.cap" />
                    </div>
                    
                </div>
            </CardContent>
        </Card>

        <Card class="border-dashed shadow-sm bg-slate-50/50 dark:bg-slate-900/20">
            <CardHeader class="pb-3 border-b border-dashed mb-4">
                <CardTitle class="text-base font-semibold">{{ trans('condomini.cards.registry_title') }}</CardTitle>
                <CardDescription>{{ trans('condomini.cards.registry_desc') }}</CardDescription>
            </CardHeader>
            <CardContent class="space-y-6">
                <div class="grid grid-cols-1 gap-x-4 gap-y-4 sm:grid-cols-6">
                    <div class="sm:col-span-2">
                        <Label for="anno_costruzione">{{ trans('condomini.label.build_year') }}</Label>
                        <Input 
                          id="anno_costruzione"
                          v-model="form.anno_costruzione" 
                          @focus="form.clearErrors('anno_costruzione')"
                          :placeholder="trans('condomini.placeholder.build_year')" 
                          class="mt-1 bg-white" 
                        />
                        <InputError :message="form.errors.anno_costruzione" />
                    </div>

                    <div class="sm:col-span-2">
                        <Label for="anno_acquisizione">{{ trans('condomini.label.acquisition_year') }}</Label>
                        <Input 
                          id="anno_acquisizione"
                          v-model="form.anno_acquisizione" 
                          @focus="form.clearErrors('anno_acquisizione')"
                          :placeholder="trans('condomini.placeholder.acquisition_year')" 
                          class="mt-1 bg-white" 
                        />
                        <InputError :message="form.errors.anno_acquisizione" />
                    </div>

                    <div class="sm:col-span-2">
                        <Label for="numero_piani">{{ trans('condomini.label.floors') }}</Label>
                        <Input 
                          id="numero_piani"
                          v-model="form.numero_piani" 
                          @focus="form.clearErrors('numero_piani')"
                          :placeholder="trans('condomini.placeholder.floors')" 
                          class="mt-1 bg-white" 
                        />
                        <InputError :message="form.errors.numero_piani" />
                    </div>

                    <div class="sm:col-span-6 mt-2 mb-2 border-t border-dashed"></div>

                    <div class="sm:col-span-4">
                        <Label for="comune_catasto">{{ trans('condomini.label.municipality') }}</Label>
                        <Input 
                          id="comune_catasto" 
                          v-model="form.comune_catasto" 
                          @focus="form.clearErrors('comune_catasto')"
                          :placeholder="trans('condomini.placeholder.municipality')" 
                          class="mt-1 bg-white" 
                        />
                        <InputError :message="form.errors.comune_catasto" />
                    </div>
                    
                    <div class="sm:col-span-2">
                        <Label for="codice_catasto">{{ trans('condomini.label.municipality_code') }}</Label>
                        <Input 
                          id="codice_catasto" 
                          v-model="form.codice_catasto" 
                          @focus="form.clearErrors('codice_catasto')"
                          :placeholder="trans('condomini.placeholder.municipality_code')" 
                          class="mt-1 bg-white" 
                        />
                        <InputError :message="form.errors.codice_catasto" />
                    </div>

                    <div class="sm:col-span-2">
                        <Label for="sezione_catasto">{{ trans('condomini.label.section') }}</Label>
                        <Input 
                          id="sezione_catasto" 
                          v-model="form.sezione_catasto" 
                          @focus="form.clearErrors('sezione_catasto')"
                          :placeholder="trans('condomini.placeholder.section')" 
                          class="mt-1 bg-white" 
                        />
                        <InputError :message="form.errors.sezione_catasto" />
                    </div>

                    <div class="sm:col-span-2">
                        <Label for="foglio_catasto">{{ trans('condomini.label.sheet') }}</Label>
                        <Input 
                          id="foglio_catasto" 
                          v-model="form.foglio_catasto" 
                          @focus="form.clearErrors('foglio_catasto')"
                          :placeholder="trans('condomini.placeholder.sheet')" 
                          class="mt-1 bg-white" 
                        />
                        <InputError :message="form.errors.foglio_catasto" />
                    </div>

                    <div class="sm:col-span-2">
                        <Label for="particella_catasto">{{ trans('condomini.label.parcel') }}</Label>
                        <Input 
                          id="particella_catasto" 
                          v-model="form.particella_catasto" 
                          @focus="form.clearErrors('particella_catasto')"
                          :placeholder="trans('condomini.placeholder.parcel')" 
                          class="mt-1 bg-white" 
                        />
                        <InputError :message="form.errors.particella_catasto" />
                    </div>
                </div>
            </CardContent>
        </Card>

        <div class="flex items-center justify-end gap-3">
            <Link
                :href="route('condomini.index')"
                class="inline-flex items-center justify-center h-9 px-6 rounded-md border border-input bg-background text-sm font-semibold hover:bg-accent hover:text-accent-foreground transition-all shadow-sm"
            >
                {{ trans('condomini.actions.cancel') }}
            </Link>

            <Button 
                type="submit"
                :disabled="form.processing" 
                class="h-9 px-8 text-sm font-semibold shadow-md gap-2"
            >
                <LoaderCircle v-if="form.processing" class="h-4 w-4 animate-spin" />
                <Save v-else class="h-4 w-4" />
                {{ trans('condomini.actions.update_building') }}
            </Button>
        </div>

      </form>
      
    </div>
  </AppLayout>
</template>