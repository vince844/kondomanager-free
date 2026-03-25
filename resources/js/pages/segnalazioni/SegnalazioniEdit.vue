<script setup lang="ts">

import { watch, onMounted, computed } from "vue";
import { Head, useForm, Link } from '@inertiajs/vue3';
import AppLayout from '@/layouts/AppLayout.vue';
import PageHeaderGuide from '@/components/PageHeaderGuide.vue';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { Input } from '@/components/ui/input';
import { Checkbox } from '@/components/ui/checkbox';
import InputError from '@/components/InputError.vue';
import { Textarea } from '@/components/ui/textarea';
import { LoaderCircle, Pencil, Info, AlertTriangle, Building2, SlidersHorizontal } from 'lucide-vue-next';
import vSelect from "vue-select";
import { Card, CardHeader, CardTitle, CardDescription, CardContent } from '@/components/ui/card';
import { priorityConstants, statoConstants, publishedConstants } from '@/lib/segnalazioni/constants';
import { HoverCard, HoverCardContent, HoverCardTrigger } from '@/components/ui/hover-card';
import { usePermission } from "@/composables/permissions";
import { trans } from 'laravel-vue-i18n';
import type { BreadcrumbItem } from '@/types';
import type { Building } from '@/types/buildings';
import type { Segnalazione } from '@/types/segnalazioni';
import type { Anagrafica } from '@/types/anagrafiche';
import type { PriorityType, StatoType, PublishedType } from '@/types/segnalazioni';
import '@vuepic/vue-datepicker/dist/main.css';

const props = defineProps<{
  condomini: Building[];
  segnalazione: Segnalazione | any; 
  anagrafiche: Anagrafica[];
}>();  

const { generateRoute } = usePermission();

const breadcrumbs: BreadcrumbItem[] = [
  {
      title: trans('segnalazioni.breadcrumbs.list') || 'Segnalazioni', 
      href: route(generateRoute('segnalazioni.index'))
  },
  {
      title: trans('segnalazioni.breadcrumbs.edit') || 'Modifica segnalazione',
      href: '#',
  }
];

const pageGuides = computed(() => [
  {
    title: trans('segnalazioni.guides.issue_title'),
    description: trans('segnalazioni.guides.issue_desc'),
    icon: AlertTriangle,
    colorVariant: 'blue' as const
  },
  {
    title: trans('segnalazioni.guides.location_title'),
    description: trans('segnalazioni.guides.location_desc'),
    icon: Building2,
    colorVariant: 'amber' as const
  },
  {
    title: trans('segnalazioni.guides.settings_title'),
    description: trans('segnalazioni.guides.settings_desc'),
    icon: SlidersHorizontal,
    colorVariant: 'emerald' as const
  }
]);

// Gestione sicura del popolamento del Condominio_id
const getInitialCondominioId = () => {
    if (props.segnalazione?.condominio?.option?.value) return props.segnalazione.condominio.option.value;
    if (props.segnalazione?.condominio?.id) return props.segnalazione.condominio.id;
    if (props.segnalazione?.condominio_id) return props.segnalazione.condominio_id;
    return '';
};

const form = useForm({
    subject: props.segnalazione?.subject ?? '',
    description: props.segnalazione?.description ?? '',
    priority: props.segnalazione?.priority ?? '',
    stato: props.segnalazione?.stato ?? '',
    condominio_id: getInitialCondominioId(),
    can_comment: !!props.segnalazione?.can_comment,
    is_featured: !!props.segnalazione?.is_featured,
    is_published: props.segnalazione?.is_published !== undefined ? Boolean(props.segnalazione.is_published) : true,
    anagrafiche: props.segnalazione?.anagrafiche?.map((a: Anagrafica) => a.id) || [],
});

onMounted(() => {
  form.anagrafiche = props.segnalazione?.anagrafiche?.map((a: Anagrafica) => a.id) || []
})

watch(
    () => props.segnalazione,
    (newSegnalazione) => {
        if(newSegnalazione) {
            form.subject = newSegnalazione.subject;
            form.description = newSegnalazione.description;
            form.priority = newSegnalazione.priority;
            form.stato = newSegnalazione.stato;
            form.condominio_id = getInitialCondominioId();
            form.can_comment = !!newSegnalazione.can_comment;
            form.is_featured = !!newSegnalazione.is_featured;
            form.is_published = Boolean(newSegnalazione.is_published);
            form.anagrafiche = newSegnalazione.anagrafiche?.map((a: Anagrafica) => a.id) || [];
        }
    },
    { deep: true }
)  

const submit = () => {
    form.put(route(generateRoute('segnalazioni.update'), {id: props.segnalazione.id}), {
        preserveScroll: true
    });
};
</script>

<template>
    <Head :title="trans('segnalazioni.header.edit_ticket_head')" />
  
    <AppLayout>
      <div class="px-6 py-8 space-y-6">
        
        <PageHeaderGuide
            :page-title="trans('segnalazioni.header.edit_ticket_title')"
            :page-subtitle="trans('segnalazioni.header.edit_ticket_description')"
            :guides="pageGuides"
            :breadcrumbs="breadcrumbs"
            :video-url="null"
            :back-url="route(generateRoute('segnalazioni.index'))"
            :back-text="trans('segnalazioni.actions.back')"
        />

        <form @submit.prevent="submit" class="space-y-6">

            <Card class="border-dashed shadow-sm bg-slate-50/50 dark:bg-slate-900/20">
                <CardHeader class="pb-3 border-b border-dashed mb-4">
                    <CardTitle class="text-base font-semibold">{{ trans('segnalazioni.section.content_title') || 'Dettagli Segnalazione' }}</CardTitle>
                    <CardDescription>{{ trans('segnalazioni.section.content_desc') || 'Inserisci le informazioni relative al problema da segnalare.' }}</CardDescription>
                </CardHeader>
                <CardContent class="space-y-6">
                    <div class="grid grid-cols-1 gap-x-4 gap-y-4 sm:grid-cols-6">
                        
                        <div class="sm:col-span-6">
                            <Label for="subject">{{ trans('segnalazioni.label.object') }}</Label>
                            <Input 
                                id="subject" 
                                class="mt-1 block w-full bg-white dark:bg-slate-950"
                                v-model="form.subject" 
                                v-on:focus="form.clearErrors('subject')"
                                :placeholder="trans('segnalazioni.placeholder.object')" 
                            />
                            <InputError :message="form.errors.subject" />
                        </div>                           

                        <div class="sm:col-span-6">
                            <Label for="description">{{ trans('segnalazioni.label.description') }}</Label>
                            <Textarea 
                                id="description" 
                                class="mt-1 block w-full min-h-[200px] bg-white dark:bg-slate-950"
                                v-model="form.description" 
                                v-on:focus="form.clearErrors('description')"
                                :placeholder="trans('segnalazioni.placeholder.description')" 
                            />
                            <InputError :message="form.errors.description" />
                        </div>  
                    </div> 
                </CardContent>
            </Card>

            <Card class="border-dashed shadow-sm bg-slate-50/50 dark:bg-slate-900/20">
                <CardHeader class="pb-3 border-b border-dashed mb-4">
                    <CardTitle class="text-base font-semibold">{{ trans('segnalazioni.section.location_title') || 'Riferimenti Geografici' }}</CardTitle>
                    <CardDescription>{{ trans('segnalazioni.section.location_desc') || 'Indica il condominio e le anagrafiche legate a questa segnalazione.' }}</CardDescription>
                </CardHeader>
                <CardContent class="space-y-6">
                    <div class="grid grid-cols-1 gap-x-4 gap-y-4 sm:grid-cols-6">
                        
                        <div class="sm:col-span-3">
                            <Label for="condominio_id">{{ trans('segnalazioni.label.building') }}</Label>
                            <v-select 
                                id="condominio_id"
                                class="w-full premium-select bg-white dark:bg-slate-950 mt-1"
                                :options="condomini" 
                                label="label" 
                                v-model="form.condominio_id"
                                :placeholder="trans('segnalazioni.placeholder.building')"
                                @update:modelValue="form.clearErrors('condominio_id')" 
                                :reduce="(condominio: any) => condominio.value"
                            />
                            <InputError :message="form.errors.condominio_id" />
                        </div>

                        <div class="sm:col-span-3">
                            <Label for="anagrafiche">{{ trans('segnalazioni.label.resident') }}</Label>
                            <v-select
                                multiple
                                id="anagrafiche"
                                class="w-full premium-select bg-white dark:bg-slate-950 mt-1"
                                :options="anagrafiche"
                                label="nome"
                                v-model="form.anagrafiche"
                                :placeholder="trans('segnalazioni.placeholder.resident')"
                                @update:modelValue="form.clearErrors('anagrafiche')"
                                :reduce="(anagrafica: Anagrafica) => anagrafica.id"
                            />
                            <InputError :message="form.errors.anagrafiche" />
                        </div>

                    </div>
                </CardContent>
            </Card>

            <Card class="border-dashed shadow-sm bg-slate-50/50 dark:bg-slate-900/20">
                <CardHeader class="pb-3 border-b border-dashed mb-4">
                    <CardTitle class="text-base font-semibold">{{ trans('segnalazioni.section.settings_title') || 'Gestione Operativa' }}</CardTitle>
                    <CardDescription>{{ trans('segnalazioni.section.settings_desc') || 'Imposta lo stato di lavorazione, la priorità e la visibilità della segnalazione.' }}</CardDescription>
                </CardHeader>
                <CardContent class="space-y-6">
                    <div class="grid grid-cols-1 gap-x-4 gap-y-4 sm:grid-cols-6">

                        <div class="sm:col-span-2">
                            <div class="flex items-center gap-2 min-h-[24px]">
                                <Label for="stato">{{ trans('segnalazioni.label.status') }}</Label>
                                <HoverCard>
                                    <HoverCardTrigger as-child>
                                        <button type="button" class="text-slate-400 hover:text-primary outline-none">
                                            <Info class="w-4 h-4" />
                                        </button>
                                    </HoverCardTrigger>
                                    <HoverCardContent class="w-80 p-4 bg-white dark:bg-slate-900 border-slate-200 shadow-xl">
                                        <h4 class="text-sm font-bold mb-2">{{ trans('segnalazioni.label.status') }}</h4>
                                        <p class="text-xs text-slate-500 leading-relaxed">{{ trans('segnalazioni.tooltip.status') }}</p>
                                    </HoverCardContent>
                                </HoverCard>
                            </div>
                            <v-select 
                                id="stato" 
                                class="w-full premium-select bg-white dark:bg-slate-950 mt-1"
                                :options="statoConstants" 
                                label="label" 
                                v-model="form.stato"
                                :placeholder="trans('segnalazioni.placeholder.status')"
                                @update:modelValue="form.clearErrors('stato')" 
                                :reduce="(stato: StatoType) => stato.value"
                            >
                                <template #option="{ label, icon }">
                                    <div class="flex items-center gap-2">
                                        <component :is="icon" class="w-4 h-4 text-muted-foreground" />
                                        <span>{{ trans(label) }}</span>
                                    </div>
                                </template>
                                <template #selected-option="{ label, icon }">
                                    <div v-if="label" class="flex items-center gap-2">
                                        <component :is="icon" class="w-4 h-4 text-muted-foreground" />
                                        <span>{{ trans(label) }}</span>
                                    </div>
                                </template>
                            </v-select>
                            <InputError :message="form.errors.stato" />
                        </div>

                        <div class="sm:col-span-2">
                            <div class="flex items-center gap-2 min-h-[24px]">
                                <Label for="priority">{{ trans('segnalazioni.label.priority') }}</Label>
                                <HoverCard>
                                    <HoverCardTrigger as-child>
                                        <button type="button" class="text-slate-400 hover:text-primary outline-none">
                                            <Info class="w-4 h-4" />
                                        </button>
                                    </HoverCardTrigger>
                                    <HoverCardContent class="w-80 p-4 bg-white dark:bg-slate-900 border-slate-200 shadow-xl">
                                        <h4 class="text-sm font-bold mb-2">{{ trans('segnalazioni.label.priority') }}</h4>
                                        <p class="text-xs text-slate-500 leading-relaxed">{{ trans('segnalazioni.tooltip.priority') }}</p>
                                    </HoverCardContent>
                                </HoverCard>
                            </div>
                            <v-select 
                                id="priority" 
                                class="w-full premium-select bg-white dark:bg-slate-950 mt-1"
                                :options="priorityConstants" 
                                label="label" 
                                v-model="form.priority"
                                :placeholder="trans('segnalazioni.placeholder.priority')"
                                @update:modelValue="form.clearErrors('priority')" 
                                :reduce="(priority: PriorityType) => priority.value"
                            >
                                <template #option="{ label, icon }">
                                    <div class="flex items-center gap-2">
                                        <component :is="icon" class="w-4 h-4 text-muted-foreground" />
                                        <span>{{ trans(label) }}</span>
                                    </div>
                                </template>
                                <template #selected-option="{ label, icon }">
                                    <div v-if="label" class="flex items-center gap-2">
                                        <component :is="icon" class="w-4 h-4 text-muted-foreground" />
                                        <span>{{ trans(label) }}</span>
                                    </div>
                                </template>
                            </v-select>
                            <InputError :message="form.errors.priority" />
                        </div>

                        <div class="sm:col-span-2">
                            <div class="flex items-center gap-2 min-h-[24px]">
                                <Label for="visibility">{{ trans('segnalazioni.label.visibility') }}</Label>
                                <HoverCard>
                                    <HoverCardTrigger as-child>
                                        <button type="button" class="text-slate-400 hover:text-primary outline-none">
                                            <Info class="w-4 h-4" />
                                        </button>
                                    </HoverCardTrigger>
                                    <HoverCardContent class="w-80 p-4 bg-white dark:bg-slate-900 border-slate-200 shadow-xl">
                                        <h4 class="text-sm font-bold mb-2">{{ trans('segnalazioni.label.visibility') }}</h4>
                                        <p class="text-xs text-slate-500 leading-relaxed">{{ trans('segnalazioni.tooltip.visibility') }}</p>
                                    </HoverCardContent>
                                </HoverCard>
                            </div>
                            <v-select 
                                id="visibility"
                                class="w-full premium-select bg-white dark:bg-slate-950 mt-1"
                                :options="publishedConstants" 
                                label="label" 
                                v-model="form.is_published"
                                :placeholder="trans('segnalazioni.placeholder.visibility')"
                                @update:modelValue="form.clearErrors('is_published')" 
                                :reduce="(option: PublishedType) => option.value"
                            >
                                <template #option="{ label, icon }">
                                    <div class="flex items-center gap-2">
                                        <component :is="icon" class="w-4 h-4 text-muted-foreground" />
                                        <span>{{ trans(label) }}</span> 
                                    </div>
                                </template>
                                <template #selected-option="{ label, icon }">
                                    <div v-if="label" class="flex items-center gap-2">
                                        <component :is="icon" class="w-4 h-4 text-muted-foreground" />
                                        <span>{{ trans(label) }}</span>
                                    </div>
                                </template>
                            </v-select>
                            <InputError :message="form.errors.is_published" />
                        </div>

                        <div class="sm:col-span-6 mt-2 mb-2 border-t border-dashed"></div>

                        <div class="sm:col-span-3">
                            <div class="flex items-center justify-between p-3 bg-white dark:bg-slate-950 border border-slate-200 dark:border-slate-800 rounded-lg shadow-sm">
                                <div class="flex items-center space-x-3">
                                    <Checkbox 
                                        id="can_comment" 
                                        :checked="form.can_comment"
                                        v-model="form.can_comment" 
                                        @update:checked="(val: boolean) => form.can_comment = val" 
                                    />
                                    <Label for="can_comment" class="cursor-pointer font-medium text-sm text-slate-700 dark:text-slate-300">
                                        {{ trans('segnalazioni.label.comments') }}
                                    </Label>
                                </div>
                                <HoverCard>
                                    <HoverCardTrigger as-child>
                                        <button type="button" class="text-slate-400 hover:text-primary outline-none">
                                            <Info class="w-4 h-4" />
                                        </button>
                                    </HoverCardTrigger>
                                    <HoverCardContent class="w-80 p-4 bg-white dark:bg-slate-900 border-slate-200 shadow-xl">
                                        <h4 class="text-sm font-bold mb-2">{{ trans('segnalazioni.label.comments') }}</h4>
                                        <p class="text-xs text-slate-500 leading-relaxed">{{ trans('segnalazioni.tooltip.comments') }}</p>
                                    </HoverCardContent>
                                </HoverCard>
                            </div>
                        </div>

                        <div class="sm:col-span-3">
                            <div class="flex items-center justify-between p-3 bg-white dark:bg-slate-950 border border-slate-200 dark:border-slate-800 rounded-lg shadow-sm">
                                <div class="flex items-center space-x-3">
                                    <Checkbox 
                                        id="is_featured" 
                                        :checked="form.is_featured"
                                        v-model="form.is_featured" 
                                        @update:checked="(val: boolean) => form.is_featured = val" 
                                    />
                                    <Label for="is_featured" class="cursor-pointer font-medium text-sm text-slate-700 dark:text-slate-300">
                                        {{ trans('segnalazioni.label.featured') }}
                                    </Label>
                                </div>
                                <HoverCard>
                                    <HoverCardTrigger as-child>
                                        <button type="button" class="text-slate-400 hover:text-primary outline-none">
                                            <Info class="w-4 h-4" />
                                        </button>
                                    </HoverCardTrigger>
                                    <HoverCardContent class="w-80 p-4 bg-white dark:bg-slate-900 border-slate-200 shadow-xl">
                                        <h4 class="text-sm font-bold mb-2">{{ trans('segnalazioni.label.featured') }}</h4>
                                        <p class="text-xs text-slate-500 leading-relaxed">{{ trans('segnalazioni.tooltip.featured') }}</p>
                                    </HoverCardContent>
                                </HoverCard>
                            </div>
                        </div>

                    </div>
                </CardContent>
            </Card>

            <div class="flex items-center justify-end gap-3">
                <Link
                    :href="route(generateRoute('segnalazioni.index'))"
                    class="inline-flex items-center justify-center h-9 px-6 rounded-md border border-input bg-background text-sm font-semibold hover:bg-accent hover:text-accent-foreground transition-all shadow-sm"
                >
                    {{ trans('segnalazioni.actions.cancel') }}
                </Link>

                <Button 
                    type="submit"
                    :disabled="form.processing" 
                    class="h-9 px-8 text-sm font-semibold shadow-md gap-2"
                >
                    <LoaderCircle v-if="form.processing" class="h-4 w-4 animate-spin" />
                    <Pencil v-else class="h-4 w-4" />
                    {{ trans('segnalazioni.actions.save_ticket') }}
                </Button>
            </div>

        </form>

      </div>
    </AppLayout> 
</template>

<style src="vue-select/dist/vue-select.css"></style>