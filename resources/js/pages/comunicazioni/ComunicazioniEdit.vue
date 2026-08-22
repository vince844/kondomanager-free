<script setup lang="ts">
import { ref, watch, onMounted, computed } from 'vue';
import { Head, useForm, Link } from '@inertiajs/vue3';
import AppLayout from '@/layouts/AppLayout.vue';
import PageHeaderGuide from '@/components/PageHeaderGuide.vue';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { Input } from '@/components/ui/input';
import { Checkbox } from '@/components/ui/checkbox';
import InputError from '@/components/InputError.vue';
import { Textarea } from '@/components/ui/textarea';
import { LoaderCircle, Pencil, Info, Megaphone, Users, BellRing } from 'lucide-vue-next';
import vSelect from "vue-select";
import { Card, CardHeader, CardTitle, CardDescription, CardContent } from '@/components/ui/card';
import { priorityConstants, publishedConstants } from '@/lib/comunicazioni/constants';
import { HoverCard, HoverCardContent, HoverCardTrigger } from '@/components/ui/hover-card';
import { usePermission } from "@/composables/permissions";
import '@vuepic/vue-datepicker/dist/main.css';
import axios from 'axios';
import { trans } from 'laravel-vue-i18n';
import type { BreadcrumbItem } from '@/types';
import type { Building } from '@/types/buildings';
import type { Anagrafica } from '@/types/anagrafiche';
import type { Comunicazione } from '@/types/comunicazioni';
import type { PriorityType, PublishedType } from '@/types/comunicazioni';

const { generatePath, generateRoute } = usePermission();

const props = defineProps<{
  comunicazione: Comunicazione;
  condomini: Building[];
  anagrafiche: Anagrafica[];
}>();  

const anagraficheOptions = ref<Anagrafica[]>(props.anagrafiche);

const breadcrumbs = computed<BreadcrumbItem[]>(() => [
  {
      title: trans('comunicazioni.breadcrumbs.list'), 
      href: route(generateRoute('comunicazioni.index'))
  },
  {
      title: trans('comunicazioni.breadcrumbs.edit'),
      href: '#',
  }
]);

const pageGuides = computed(() => [
  {
    title: trans('comunicazioni.guides.message_title'),
    description: trans('comunicazioni.guides.message_desc'),
    icon: Megaphone,
    colorVariant: 'blue' as const
  },
  {
    title: trans('comunicazioni.guides.audience_title'),
    description: trans('comunicazioni.guides.audience_desc'),
    icon: Users,
    colorVariant: 'amber' as const
  },
  {
    title: trans('comunicazioni.guides.priority_title'),
    description: trans('comunicazioni.guides.priority_desc'),
    icon: BellRing,
    colorVariant: 'emerald' as const
  }
]);

const form = useForm({
    subject: props.comunicazione?.subject ?? '',
    description: props.comunicazione?.description ?? '',
    priority: props.comunicazione?.priority ?? '',
    stato: '',
    condomini_ids: props.comunicazione?.condomini?.options?.map(c => c.value) ?? [],
    can_comment: !!props.comunicazione?.can_comment,
    is_featured: !!props.comunicazione?.is_featured,
    is_published: props.comunicazione?.is_published !== undefined ? Boolean(props.comunicazione.is_published) : true,
    anagrafiche: (props.comunicazione?.anagrafiche ?? []).map(anagrafica => anagrafica.id),
    // ⚠️ Parte **sempre spenta**, a ogni apertura del modulo. Un avviso a tutto il condominio
    // non deve essere il comportamento predefinito di un salvataggio: chi corregge un refuso
    // non se lo aspetta, e se ne accorge solo quando gli rispondono in venti.
    avvisa_destinatari: false
});

onMounted(() => {
  form.condomini_ids = props.comunicazione?.condomini?.options?.map(c => c.value) ?? [];
  form.anagrafiche = (props.comunicazione?.anagrafiche ?? []).map(a => a.id);
});

watch(
  () => props.comunicazione,
  (newComunicazione) => {
    if (newComunicazione) {
      form.condomini_ids = newComunicazione.condomini?.options?.map(c => c.value) ?? [];
      form.anagrafiche = newComunicazione.anagrafiche?.map(a => a.id) ?? [];
      form.subject = newComunicazione.subject;
      form.description = newComunicazione.description;
      form.priority = newComunicazione.priority;
      form.is_published = Boolean(newComunicazione.is_published);
      form.can_comment = !!newComunicazione.can_comment;
      form.is_featured = !!newComunicazione.is_featured;
    }
  },
  { deep: true }
);

const fetchAnagrafiche = async (newCondominiIds: number[]) => {
  if (newCondominiIds.length > 0) {
    try {
      const response = await axios.get(generatePath('fetch-anagrafiche'), {
        params: { condomini_ids: newCondominiIds }
      });

      anagraficheOptions.value = response.data.map((item: { id: number, nome: string }) => ({
        id: item.id,
        nome: item.nome,
      }));

      const validIds = response.data.map((a: Anagrafica) => a.id);
      form.anagrafiche = form.anagrafiche.filter((id) => validIds.includes(id));

    } catch (error) {
      console.error('Error fetching anagrafiche:', error);
    }
  } else {
    anagraficheOptions.value = [];
    form.anagrafiche = [];
  }
};

watch(() => form.condomini_ids, fetchAnagrafiche);

const submit = () => {
    form.put(route(generateRoute('comunicazioni.update'), { id: props.comunicazione.id }), {
        preserveScroll: true
    });
};

</script>

<template>
    <Head :title="trans('comunicazioni.header.edit_communication_head')" />
  
    <AppLayout>
      <div class="px-6 py-8 space-y-6">
        
        <PageHeaderGuide
            :page-title="trans('comunicazioni.header.edit_communication_title')"
            :page-subtitle="trans('comunicazioni.header.edit_communication_description')"
            :guides="pageGuides"
            :breadcrumbs="breadcrumbs"
            :video-url="null"
            :back-url="route(generateRoute('comunicazioni.index'))"
            back-text="Indietro"
        />

        <form @submit.prevent="submit" class="space-y-6">

            <Card class="border-dashed shadow-sm bg-slate-50/50 dark:bg-slate-900/20">
                <CardHeader class="pb-3 border-b border-dashed mb-4">
                    <CardTitle class="text-base font-semibold">{{ trans('comunicazioni.section.content_title') }}</CardTitle>
                    <CardDescription>{{ trans('comunicazioni.section.content_desc') }}</CardDescription>
                </CardHeader>
                <CardContent class="space-y-6">
                    <div class="grid grid-cols-1 gap-x-4 gap-y-4 sm:grid-cols-6">
                        
                        <div class="sm:col-span-6">
                            <Label for="subject">{{ trans('comunicazioni.label.subject') }}</Label>
                            <Input 
                                id="subject" 
                                class="mt-1 block w-full bg-white dark:bg-slate-950"
                                v-model="form.subject" 
                                v-on:focus="form.clearErrors('subject')"
                                :placeholder="trans('comunicazioni.placeholder.subject')" 
                            />
                            <InputError :message="form.errors.subject" />
                        </div>                           

                        <div class="sm:col-span-6">
                            <Label for="description">{{ trans('comunicazioni.label.description') }}</Label>
                            <Textarea 
                                id="description" 
                                class="mt-1 block w-full min-h-[200px] bg-white dark:bg-slate-950"
                                v-model="form.description" 
                                v-on:focus="form.clearErrors('description')"
                                :placeholder="trans('comunicazioni.placeholder.description')" 
                            />
                            <InputError :message="form.errors.description" />
                        </div>  
                    </div> 
                </CardContent>
            </Card>

            <Card class="border-dashed shadow-sm bg-slate-50/50 dark:bg-slate-900/20">
                <CardHeader class="pb-3 border-b border-dashed mb-4">
                    <CardTitle class="text-base font-semibold">{{ trans('comunicazioni.section.recipients_title') }}</CardTitle>
                    <CardDescription>{{ trans('comunicazioni.section.recipients_desc') }}</CardDescription>
                </CardHeader>
                <CardContent class="space-y-6">
                    <div class="grid grid-cols-1 gap-x-4 gap-y-4 sm:grid-cols-6">
                        
                        <div class="sm:col-span-3">
                            <Label for="condomini">{{ trans('comunicazioni.label.buildings') }}</Label>
                            <v-select 
                                multiple
                                class="w-full premium-select bg-white dark:bg-slate-950 mt-1"
                                :options="condomini" 
                                label="label" 
                                v-model="form.condomini_ids"
                                :placeholder="trans('comunicazioni.placeholder.buildings')"
                                @update:modelValue="form.clearErrors('condomini_ids')" 
                                :reduce="(option: any) => option.value"
                            />
                            <InputError :message="form.errors.condomini_ids" />
                        </div>

                        <div class="sm:col-span-3">
                            <Label for="anagrafiche">{{ trans('comunicazioni.label.residents') }}</Label>
                            <v-select
                                multiple
                                id="anagrafiche"
                                class="w-full premium-select bg-white dark:bg-slate-950 mt-1"
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
                </CardContent>
            </Card>

            <Card class="border-dashed shadow-sm bg-slate-50/50 dark:bg-slate-900/20">
                <CardHeader class="pb-3 border-b border-dashed mb-4">
                    <CardTitle class="text-base font-semibold">{{ trans('comunicazioni.section.settings_title') }}</CardTitle>
                    <CardDescription>{{ trans('comunicazioni.section.settings_desc') }}</CardDescription>
                </CardHeader>
                <CardContent class="space-y-6">
                    <div class="grid grid-cols-1 gap-x-4 gap-y-4 sm:grid-cols-6">

                        <div class="sm:col-span-3">
                            <div class="flex items-center gap-2 min-h-[24px]">
                                <Label for="stato">{{ trans('comunicazioni.label.visibility') }}</Label>
                                <HoverCard>
                                    <HoverCardTrigger as-child>
                                        <button type="button" class="text-slate-400 hover:text-primary outline-none">
                                            <Info class="w-4 h-4" />
                                        </button>
                                    </HoverCardTrigger>
                                    <HoverCardContent class="w-80 p-4 bg-white dark:bg-slate-900 border-slate-200 shadow-xl">
                                        <h4 class="text-sm font-bold mb-2">{{ trans('comunicazioni.label.visibility') }}</h4>
                                        <p class="text-xs text-slate-500 leading-relaxed">{{ trans('comunicazioni.tooltip.visibility') }}</p>
                                    </HoverCardContent>
                                </HoverCard>
                            </div>
                            <v-select 
                                id="stato" 
                                class="w-full premium-select bg-white dark:bg-slate-950 mt-1"
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
                                    <div v-if="label" class="flex items-center gap-2">
                                        <component :is="icon" class="w-4 h-4 text-muted-foreground" />
                                        <span>{{ trans(label) }}</span>
                                    </div>
                                </template>
                            </v-select>
                            <InputError :message="form.errors.is_published" />
                        </div>

                        <div class="sm:col-span-3">
                            <div class="flex items-center gap-2 min-h-[24px]">
                                <Label for="priority">{{ trans('comunicazioni.label.priority') }}</Label>
                                <HoverCard>
                                    <HoverCardTrigger as-child>
                                        <button type="button" class="text-slate-400 hover:text-primary outline-none">
                                            <Info class="w-4 h-4" />
                                        </button>
                                    </HoverCardTrigger>
                                    <HoverCardContent class="w-80 p-4 bg-white dark:bg-slate-900 border-slate-200 shadow-xl">
                                        <h4 class="text-sm font-bold mb-2">{{ trans('comunicazioni.label.priority') }}</h4>
                                        <p class="text-xs text-slate-500 leading-relaxed">{{ trans('comunicazioni.tooltip.priority') }}</p>
                                    </HoverCardContent>
                                </HoverCard>
                            </div>
                            <v-select 
                                id="priority" 
                                class="w-full premium-select bg-white dark:bg-slate-950 mt-1"
                                :options="priorityConstants" 
                                label="label" 
                                v-model="form.priority"
                                :placeholder="trans('comunicazioni.placeholder.priority')" 
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
                                        {{ trans('comunicazioni.label.comments') }}
                                    </Label>
                                </div>
                                <HoverCard>
                                    <HoverCardTrigger as-child>
                                        <button type="button" class="text-slate-400 hover:text-primary outline-none">
                                            <Info class="w-4 h-4" />
                                        </button>
                                    </HoverCardTrigger>
                                    <HoverCardContent class="w-80 p-4 bg-white dark:bg-slate-900 border-slate-200 shadow-xl">
                                        <h4 class="text-sm font-bold mb-2">{{ trans('comunicazioni.label.comments') }}</h4>
                                        <p class="text-xs text-slate-500 leading-relaxed">{{ trans('comunicazioni.tooltip.comments') }}</p>
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
                                        {{ trans('comunicazioni.label.featured') }}
                                    </Label>
                                </div>
                                <HoverCard>
                                    <HoverCardTrigger as-child>
                                        <button type="button" class="text-slate-400 hover:text-primary outline-none">
                                            <Info class="w-4 h-4" />
                                        </button>
                                    </HoverCardTrigger>
                                    <HoverCardContent class="w-80 p-4 bg-white dark:bg-slate-900 border-slate-200 shadow-xl">
                                        <h4 class="text-sm font-bold mb-2">{{ trans('comunicazioni.label.featured') }}</h4>
                                        <p class="text-xs text-slate-500 leading-relaxed">{{ trans('comunicazioni.tooltip.featured') }}</p>
                                    </HoverCardContent>
                                </HoverCard>
                            </div>
                        </div>

                        <!--
                          ⚠️ **Questa casella non è una proprietà della comunicazione: è un'azione
                          che si compie salvando.** Per questo sta su una riga sua, con un colore
                          diverso dalle due qui sopra e il testo che dice cosa succede — non
                          «notifiche sì/no», ma «a chi arriva una mail se salvo adesso».

                          Chi viene **aggiunto** alla platea in questa modifica riceve comunque la
                          comunicazione, spuntata o no: per lui è nuova, e non avvisarlo era il
                          difetto corretto nella beta.64. Qui si decide solo per chi c'era già.
                        -->
                        <div class="sm:col-span-6">
                            <div class="flex items-center justify-between p-3 bg-amber-50/60 dark:bg-amber-950/20 border border-amber-200 dark:border-amber-900/50 rounded-lg">
                                <div class="flex items-center space-x-3">
                                    <Checkbox
                                        id="avvisa_destinatari"
                                        :checked="form.avvisa_destinatari"
                                        v-model="form.avvisa_destinatari"
                                        @update:checked="(val: boolean) => form.avvisa_destinatari = val"
                                    />
                                    <Label for="avvisa_destinatari" class="cursor-pointer font-medium text-sm text-amber-900 dark:text-amber-200">
                                        {{ trans('comunicazioni.label.notify_update') }}
                                    </Label>
                                </div>
                                <HoverCard>
                                    <HoverCardTrigger as-child>
                                        <button type="button" class="text-amber-500 hover:text-amber-700 outline-none">
                                            <Info class="w-4 h-4" />
                                        </button>
                                    </HoverCardTrigger>
                                    <HoverCardContent class="w-80 p-4 bg-white dark:bg-slate-900 border-slate-200 shadow-xl">
                                        <h4 class="text-sm font-bold mb-2">{{ trans('comunicazioni.label.notify_update') }}</h4>
                                        <p class="text-xs text-slate-500 leading-relaxed">{{ trans('comunicazioni.tooltip.notify_update') }}</p>
                                    </HoverCardContent>
                                </HoverCard>
                            </div>
                        </div>

                    </div>
                </CardContent>
            </Card>

            <div class="flex items-center justify-end gap-3">
                <Link
                    :href="route(generateRoute('comunicazioni.index'))"
                    class="inline-flex items-center justify-center h-9 px-6 rounded-md border border-input bg-background text-sm font-semibold hover:bg-accent hover:text-accent-foreground transition-all shadow-sm"
                >
                    Annulla
                </Link>

                <Button 
                    type="submit"
                    :disabled="form.processing" 
                    class="h-9 px-8 text-sm font-semibold shadow-md gap-2"
                >
                    <LoaderCircle v-if="form.processing" class="h-4 w-4 animate-spin" />
                    <Pencil v-else class="h-4 w-4" />
                    {{ trans('comunicazioni.actions.save_communication') }}
                </Button>
            </div>

        </form>

      </div>
    </AppLayout> 
</template>

<style src="vue-select/dist/vue-select.css"></style>