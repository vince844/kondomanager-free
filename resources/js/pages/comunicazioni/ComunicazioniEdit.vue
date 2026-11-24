<!--
SPDX-License-Identifier: AGPL-3.0-or-later
Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
-->
<script setup lang="ts">
import { ref, watch, onMounted, computed, nextTick } from 'vue';
import { Head, useForm, Link } from '@inertiajs/vue3';
import AppLayout from '@/layouts/AppLayout.vue';
import PageHeaderGuide from '@/components/PageHeaderGuide.vue';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { Input } from '@/components/ui/input';
import { Checkbox } from '@/components/ui/checkbox';
import InputError from '@/components/InputError.vue';
import { Textarea } from '@/components/ui/textarea';
import { LoaderCircle, Pencil, Info, Megaphone, Users, BellRing, Clock } from 'lucide-vue-next';
import vSelect from "vue-select";
import { Card, CardHeader, CardTitle, CardDescription, CardContent } from '@/components/ui/card';
import { priorityConstants, publishedConstants } from '@/lib/comunicazioni/constants';
import { rigaServeApprovazione } from '@/lib/pubblicazione';
import { Permission } from '@/enums/Permission';
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

const { generatePath, generateRoute, hasPermission } = usePermission();

interface ProgrammazioneProp {
  puo_programmare: boolean;
  fuso: string;
  pianificatore_attivo: boolean;
  adesso: string;
}

const props = defineProps<{
  comunicazione: Comunicazione;
  condomini: Building[];
  anagrafiche: Anagrafica[];
  // «Commenti in moderazione» la cambia solo chi può approvare i commenti: per gli altri resta com'è, e la casella non c'è.
  puo_scegliere_moderazione?: boolean;
  programmazione?: ProgrammazioneProp | null;
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

const isProgrammataIniziale = Boolean(!props.comunicazione?.is_published && props.comunicazione?.data_programmata);

const statoSelezionato = ref<boolean | 'scheduled'>(
    isProgrammataIniziale
        ? 'scheduled'
        : (props.comunicazione?.is_published !== undefined ? Boolean(props.comunicazione.is_published) : true)
);

const form = useForm({
    subject: props.comunicazione?.subject ?? '',
    description: props.comunicazione?.description ?? '',
    priority: props.comunicazione?.priority ?? '',
    stato: '',
    condomini_ids: props.comunicazione?.condomini?.options?.map(c => c.value) ?? [],
    can_comment: !!props.comunicazione?.can_comment,
    ...(props.comunicazione?.commenti_moderati !== undefined ? { commenti_moderati: Boolean(props.comunicazione.commenti_moderati) } : {}),
    ...(props.comunicazione?.chiedi_presa_visione !== undefined ? { chiedi_presa_visione: Boolean(props.comunicazione.chiedi_presa_visione) } : {}),
    is_featured: !!props.comunicazione?.is_featured,
    is_published: props.comunicazione?.is_published !== undefined ? Boolean(props.comunicazione.is_published) : true,
    anagrafiche: (props.comunicazione?.anagrafiche ?? []).map(anagrafica => anagrafica.id),
    // ⚠️ Parte **sempre spenta**, a ogni apertura del modulo. Un avviso a tutto il condominio
    // non deve essere il comportamento predefinito di un salvataggio: chi corregge un refuso
    // non se lo aspetta, e se ne accorge solo quando gli rispondono in venti.
    avvisa_destinatari: false,
    data_programmata: (isProgrammataIniziale ? props.comunicazione.data_programmata : null) as string | null
});

const optionsStato = computed(() => {
    const options = [...publishedConstants];
    if (props.programmazione?.puo_programmare) {
        options.push({
            value: 'scheduled' as unknown as boolean,
            label: 'comunicazioni.publication.scheduled',
            icon: Clock,
            colorClass: 'text-sky-500 bg-transparent',
        });
    }
    return options;
});

/**
 * 1.12.0-beta.12 (H2, decisione 504): la tendina della visibilità si chiude da sé dopo la scelta. `vue-select` la chiude con un interruttore
 * (`open = !open`): se un secondo evento di scelta arriva mentre il campo dell'ora compare sotto e la pagina si ridisegna, l'interruttore la riapre
 * sopra il campo. Qui `close-on-select` è spento e la tendina si chiude sempre, a ogni scelta e dopo il ridisegno: una chiusura, non un interruttore.
 */
const menuVisibilita = ref<{ open: boolean; searchEl?: HTMLInputElement } | null>(null);
const chiudiMenuVisibilita = () => {
    const chiudi = () => {
        const menu = menuVisibilita.value;
        if (menu) {
            menu.open = false;
            menu.searchEl?.blur();
        }
    };
    chiudi();
    nextTick(chiudi);
};

const onStatoChange = (val: boolean | 'scheduled') => {
    form.clearErrors('is_published');
    form.clearErrors('data_programmata');
    if (val === 'scheduled') {
        form.is_published = false;
        if (!form.data_programmata && props.comunicazione?.data_programmata) {
            form.data_programmata = props.comunicazione.data_programmata;
        }
    } else {
        form.is_published = val === true;
        form.data_programmata = null;
    }
};

// All'apertura l'elenco dei destinatari si restringe alle anagrafiche dei palazzi della comunicazione,
// ma i destinatari salvati restano scelti, anche quelli che nel frattempo non sono più nel palazzo:
// aperta e salvata senza toccare niente, la comunicazione resta indirizzata a chi lo era. Il
// montaggio carica solo l'elenco, senza filtrare i destinatari scelti (`filtraScelti` falso).
onMounted(() => {
  caricaAnagrafiche(form.condomini_ids, false);
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
      if (newComunicazione.commenti_moderati !== undefined) {
        (form as any).commenti_moderati = Boolean(newComunicazione.commenti_moderati);
      }
      if (newComunicazione.chiedi_presa_visione !== undefined) {
        (form as any).chiedi_presa_visione = Boolean(newComunicazione.chiedi_presa_visione);
      }
      form.is_featured = !!newComunicazione.is_featured;
      const isProg = Boolean(!newComunicazione.is_published && newComunicazione.data_programmata);
      statoSelezionato.value = isProg ? 'scheduled' : Boolean(newComunicazione.is_published);
      form.data_programmata = isProg ? (newComunicazione.data_programmata ?? null) : null;
    }
  },
  { deep: true }
);

/** Gli stessi palazzi, in qualunque ordine: riassegnarli (il `watch` delle props) non è cambiarli. */
const stessiPalazzi = (nuovi: number[], vecchi: number[] | undefined): boolean =>
  vecchi !== undefined && nuovi.length === vecchi.length && nuovi.every((id) => vecchi.includes(id));

/** I palazzi e i destinatari che la comunicazione ha salvati (quelli che il server manda alla pagina). */
const palazziSalvati = (): number[] => props.comunicazione?.condomini?.options?.map(c => c.value) ?? [];
const destinatariSalvati = () => (props.comunicazione?.anagrafiche ?? []).map(a => a.id);

/**
 * Le anagrafiche dei palazzi scelti, per l'elenco dei destinatari. Con `filtraScelti` (i palazzi sono
 * cambiati) i destinatari che non sono fra quelle anagrafiche si tolgono, tranne, quando i palazzi sono
 * di nuovo quelli salvati, i destinatari salvati: tornare ai palazzi salvati non è sceglierne altri, e
 * succede anche quando il server rimanda la pagina dopo un salvataggio rifiutato (Inertia tiene lo
 * stato e il `watch` delle props riscrive palazzi e destinatari). Senza `filtraScelti` (all'apertura)
 * i destinatari restano. Un destinatario scelto che non è fra le anagrafiche dei palazzi resta
 * nell'elenco, con il nome che la comunicazione ha salvato, così si vede come scelto.
 */
const caricaAnagrafiche = async (condominiIds: number[], filtraScelti: boolean) => {
  let deiPalazzi: Anagrafica[] = [];

  if (condominiIds.length > 0) {
    try {
      const response = await axios.get(generatePath('fetch-anagrafiche'), {
        params: { condomini_ids: condominiIds }
      });

      deiPalazzi = response.data.map((item: { id: number, nome: string }) => ({
        id: item.id,
        nome: item.nome,
      }));
    } catch (error) {
      console.error('Error fetching anagrafiche:', error);
      return;
    }
  }

  // La risposta vale solo per i palazzi di adesso: se nel frattempo sono cambiati (chi modifica ne ha
  // scelti altri, o il server ha rimandato la pagina), conta la risposta della richiesta che è partita dopo.
  if (!stessiPalazzi(condominiIds, form.condomini_ids)) {
    return;
  }

  const validIds = deiPalazzi.map((a) => a.id);

  if (filtraScelti) {
    const salvatiCheRestano = stessiPalazzi(condominiIds, palazziSalvati()) ? destinatariSalvati() : [];
    form.anagrafiche = form.anagrafiche.filter((id) => validIds.includes(id) || salvatiCheRestano.includes(id));
  }

  const sceltiFuori = (props.comunicazione?.anagrafiche ?? [])
    .filter((a) => form.anagrafiche.includes(a.id) && !validIds.includes(a.id))
    .map((a) => ({ id: a.id, nome: a.nome }) as Anagrafica);

  anagraficheOptions.value = [...deiPalazzi, ...sceltiFuori];
};

watch(() => form.condomini_ids, (nuovi: number[], vecchi: number[] | undefined) => {
  if (stessiPalazzi(nuovi, vecchi)) {
    return;
  }

  caricaAnagrafiche(nuovi, true);
});

// La modifica non approva (decisione 127.2): su una comunicazione non approvata la riga sotto la
// tendina dice che «Pubblicata» da sola non la mostra ai condòmini; a chi approva le comunicazioni dice
// dove si approva (lo scudo nell'elenco, che vede solo lui), agli altri chi la approva.
const rigaApprovazione = computed(() => rigaServeApprovazione('comunicazioni', props.comunicazione?.is_approved, hasPermission([Permission.APPROVE_COMUNICAZIONI])));

const submit = () => {
    if (statoSelezionato.value === 'scheduled') {
        if (!form.data_programmata) {
            form.setError('data_programmata', trans('comunicazioni.scheduling.date_required'));
            return;
        }
        form.clearErrors('data_programmata');
        form.is_published = false;
    } else {
        form.is_published = statoSelezionato.value === true;
        form.data_programmata = null;
    }
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
            :storia="{ tipo: 'comunicazione', id: props.comunicazione.id }"
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
                                :options="optionsStato" 
                                label="label" 
                                v-model="statoSelezionato"
                                ref="menuVisibilita"
                                :close-on-select="false"
                                :placeholder="trans('comunicazioni.placeholder.visibility')" 
                                @update:modelValue="onStatoChange" 
                                @option:selected="chiudiMenuVisibilita"
                                @option:selecting="chiudiMenuVisibilita"
                                :reduce="(opt: any) => opt.value"
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
                            <p v-if="rigaApprovazione" class="mt-1.5 text-xs text-amber-700 dark:text-amber-400 leading-relaxed">{{ trans(rigaApprovazione) }}</p>

                            <div v-if="statoSelezionato === 'scheduled'" class="mt-4 space-y-2">
                                <Label for="data_programmata">{{ trans('comunicazioni.scheduling.field_label') }}</Label>
                                <input 
                                    id="data_programmata"
                                    type="datetime-local" 
                                    data-campo="data-programmata" 
                                    v-model="form.data_programmata" 
                                    :min="props.programmazione?.adesso"
                                    @input="form.clearErrors('data_programmata')"
                                    class="flex h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-sm ring-offset-background file:border-0 file:bg-transparent file:text-sm file:font-medium placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-50"
                                />
                                <!-- Decisione 504: la riga sotto la data c'è solo con un fuso diverso da quello italiano, e dice solo il fuso. -->
                                <p v-if="props.programmazione?.fuso && props.programmazione.fuso !== 'Europe/Rome'" data-nota="fuso" class="text-xs text-muted-foreground">
                                    {{ trans('comunicazioni.scheduling.timezone_hint', { timezone: props.programmazione?.fuso }) }}
                                </p>
                                <p v-if="props.programmazione && !props.programmazione.pianificatore_attivo" data-avviso="pianificatore-fermo" class="text-xs text-amber-600 dark:text-amber-400 font-medium">
                                    {{ trans('comunicazioni.scheduling.scheduler_down') }}
                                </p>
                                <InputError :message="form.errors.data_programmata" />
                            </div>
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

                        <div v-if="(form as any).commenti_moderati !== undefined && props.puo_scegliere_moderazione !== false" class="sm:col-span-3" data-testid="scelta-moderazione">
                            <div class="flex items-center justify-between p-3 bg-white dark:bg-slate-950 border border-slate-200 dark:border-slate-800 rounded-lg shadow-sm">
                                <div class="flex items-center space-x-3">
                                    <Checkbox 
                                        id="commenti_moderati" 
                                        :checked="(form as any).commenti_moderati"
                                        v-model="(form as any).commenti_moderati" 
                                        @update:checked="(val: boolean) => (form as any).commenti_moderati = val" 
                                    />
                                    <Label for="commenti_moderati" class="cursor-pointer font-medium text-sm text-slate-700 dark:text-slate-300">
                                        {{ trans('comunicazioni.label.commenti_moderati') }}
                                    </Label>
                                </div>
                                <HoverCard>
                                    <HoverCardTrigger as-child>
                                        <button type="button" class="text-slate-400 hover:text-primary outline-none">
                                            <Info class="w-4 h-4" />
                                        </button>
                                    </HoverCardTrigger>
                                    <HoverCardContent class="w-80 p-4 bg-white dark:bg-slate-900 border-slate-200 shadow-xl">
                                        <h4 class="text-sm font-bold mb-2">{{ trans('comunicazioni.label.commenti_moderati') }}</h4>
                                        <p class="text-xs text-slate-500 leading-relaxed">{{ trans('comunicazioni.tooltip.commenti_moderati') }}</p>
                                    </HoverCardContent>
                                </HoverCard>
                            </div>
                        </div>

                        <div v-if="(form as any).chiedi_presa_visione !== undefined" class="sm:col-span-3">
                            <div class="flex items-center justify-between p-3 bg-white dark:bg-slate-950 border border-slate-200 dark:border-slate-800 rounded-lg shadow-sm">
                                <div class="flex items-center space-x-3">
                                    <Checkbox 
                                        id="chiedi_presa_visione" 
                                        :checked="(form as any).chiedi_presa_visione"
                                        v-model="(form as any).chiedi_presa_visione" 
                                        @update:checked="(val: boolean) => (form as any).chiedi_presa_visione = val" 
                                    />
                                    <Label for="chiedi_presa_visione" class="cursor-pointer font-medium text-sm text-slate-700 dark:text-slate-300">
                                        {{ trans('comunicazioni.label.ask_acknowledgement') }}
                                    </Label>
                                </div>
                                <HoverCard>
                                    <HoverCardTrigger as-child>
                                        <button type="button" class="text-slate-400 hover:text-primary outline-none">
                                            <Info class="w-4 h-4" />
                                        </button>
                                    </HoverCardTrigger>
                                    <HoverCardContent class="w-80 p-4 bg-white dark:bg-slate-900 border-slate-200 shadow-xl">
                                        <h4 class="text-sm font-bold mb-2">{{ trans('comunicazioni.label.ask_acknowledgement') }}</h4>
                                        <p class="text-xs text-slate-500 leading-relaxed">{{ trans('comunicazioni.tooltip.ask_acknowledgement') }}</p>
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