<script setup lang="ts">
import { computed } from 'vue';
import { Link, Head, useForm } from '@inertiajs/vue3';
import AppLayout from '@/layouts/AppLayout.vue';
import PageHeaderGuide from '@/components/PageHeaderGuide.vue';
import { Button } from '@/components/ui/button';
import { Plus, LoaderCircle, Building2, MapPin, Info } from 'lucide-vue-next';
import { Label } from '@/components/ui/label';
import { Input } from '@/components/ui/input';
import InputError from '@/components/InputError.vue';
import CercaComune from '@/components/comuni/CercaComune.vue';
import { Textarea } from '@/components/ui/textarea';
import { trans } from 'laravel-vue-i18n';
import type { BreadcrumbItem } from '@/types';
import { Card, CardHeader, CardTitle, CardDescription, CardContent } from '@/components/ui/card';

const breadcrumbs = computed<BreadcrumbItem[]>(() => [
  {
      title: trans('condomini.header.list_buildings_title'),
      href: route('condomini.index') 
  },
  {
      title: trans('condomini.header.new_building_title'),
      href: '#',
  }
]);

const pageGuides = computed(() => [
  {
    title: trans('condomini.guides.create_info_title'),
    description: trans('condomini.guides.create_info_desc'),
    icon: Building2,
    colorVariant: 'blue' as const
  },
  {
    title: trans('condomini.guides.create_registry_title'),
    description: trans('condomini.guides.create_registry_desc'),
    icon: MapPin,
    colorVariant: 'amber' as const
  },
  {
    title: trans('condomini.guides.create_notes_title'),
    description: trans('condomini.guides.create_notes_desc'),
    icon: Info,
    colorVariant: 'emerald' as const
  }
]);

const form = useForm({
    nome: '',
    codice_fiscale: '',
    email: '',
    note: '',
    indirizzo: '',
    comune: '',
    provincia: '',
    cap: '',
    anno_costruzione: '',
    anno_acquisizione: '',
    numero_piani: '',
    comune_catasto: '',
    codice_catasto: '',
    sezione_catasto: '',
    foglio_catasto: '',
    particella_catasto: '',
});

/**
 * Il Comune scelto dall'elenco riempie **due** campi, non uno: senza il codice catastale accanto al
 * nome, l'aiuto avrebbe risparmiato la parte facile e lasciato quella che nessuno ricorda.
 */
const comuneScelto = (c: { nome: string; codice_catasto: string }) => {
  form.comune_catasto = c.nome;
  form.codice_catasto = c.codice_catasto;
};

const submit = () => {
    form.post(route("condomini.store"), {
        preserveScroll: true,
        onSuccess: () => {
            form.reset()
        }
    });
};
</script>

<template>
  <Head :title="trans('condomini.header.new_building_head')" />

  <AppLayout>
    <div class="px-6 py-8 space-y-6">
      
      <PageHeaderGuide
        :page-title="trans('condomini.header.new_building_title')"
        :page-subtitle="trans('condomini.header.new_building_description')"
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
                          :placeholder="trans('condomini.placeholder.floors')" 
                          class="mt-1 bg-white" 
                        />
                        <InputError :message="form.errors.numero_piani" />
                    </div>

                    <div class="sm:col-span-6 mt-2 mb-2 border-t border-dashed"></div>

                    <div class="sm:col-span-4">
                        <Label for="comune_catasto">{{ trans('condomini.label.municipality') }}</Label>
                        <div class="mt-1 flex items-center gap-2">
                          <Input 
                            id="comune_catasto" 
                            v-model="form.comune_catasto" 
                            :placeholder="trans('condomini.placeholder.municipality')" 
                            class="bg-white" 
                          />
                          <CercaComune @scelto="comuneScelto" />
                        </div>
                        <InputError :message="form.errors.comune_catasto" />
                    </div>
                    
                    <div class="sm:col-span-2">
                        <Label for="codice_catasto">{{ trans('condomini.label.municipality_code') }}</Label>
                        <Input 
                          id="codice_catasto" 
                          v-model="form.codice_catasto" 
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
                <Plus v-else class="h-4 w-4" />
                {{ trans('condomini.actions.save_building') }}
            </Button>
        </div>

      </form>
      
    </div>
  </AppLayout>
</template>