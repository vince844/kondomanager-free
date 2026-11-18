<!--
SPDX-License-Identifier: AGPL-3.0-or-later
Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
-->
<script setup lang="ts">
import { ref, computed } from 'vue';
import { Head, useForm } from "@inertiajs/vue3";
import AppLayout from "@/layouts/AppLayout.vue";
import PageHeaderGuide from '@/components/PageHeaderGuide.vue';
import { Card, CardHeader, CardTitle, CardContent } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { Switch } from '@/components/ui/switch';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Megaphone, Eye, BellRing, CalendarDays, Info, Lock, MessageSquare, Settings2, Clock, Star, ChevronDown, ChevronUp } from "lucide-vue-next";
import { trans, transChoice } from 'laravel-vue-i18n';
import { Tooltip, TooltipContent, TooltipProvider, TooltipTrigger } from '@/components/ui/tooltip';
import { usePermission } from "@/composables/permissions";
import { priorityConstants } from '@/lib/comunicazioni/constants';
import { colorePubblicazione, etichettaPrivacy, etichettaStatoPubblicazione, statoPubblicazione } from '@/lib/pubblicazione';
import AllegatiRiquadro from '@/components/allegati/AllegatiRiquadro.vue';
import DiscussioniComunicazione, { type DiscussioneItem, type CommentiConfig } from '@/components/commenti/DiscussioniComunicazione.vue';
import type { BreadcrumbItem } from '@/types';
import type { Comunicazione } from "@/types/comunicazioni";
import type { AllegatiConfig, AllegatoItem } from '@/types/allegati';

const { generateRoute, getRolePrefix } = usePermission();
const portaCorrente = computed(() => getRolePrefix());

const props = defineProps<{
  comunicazione: Comunicazione;
  allegati?: AllegatoItem[];
  allegati_config?: AllegatiConfig;
  commenti_config?: CommentiConfig;
  discussioni?: DiscussioneItem[];
  // Decisione 467: solo nel pannello.
  destinatari?: { tipo: 'condomini' | 'persone' | 'privata'; totale: number; nomi: string[] };
}>();

// Ricava dinamicamente l'oggetto priorità dal file constants
const currentPriority = computed(() => {
    return priorityConstants.find(p => p.value === props.comunicazione.priority);
});

// Coda 306: lo stato di pubblicazione viene da `is_published` e `is_approved` insieme, e «Privata» è
// un'indicazione a parte, da `is_private`: le due etichette vengono da `@/lib/pubblicazione`, come
// nelle tabelle. La pagina è la stessa per il pannello e per il portale.
const statoCorrente = computed(() =>
    statoPubblicazione(props.comunicazione.is_published, props.comunicazione.is_approved)
);
const etichettaPrivata = computed(() => etichettaPrivacy('comunicazioni', props.comunicazione.is_private));

// Decisione 467: come nella scheda della segnalazione, un testo lungo si chiude a quattro righe e si riapre con «Leggi tutto».
const espanso = ref(false);

// I destinatari nella card (decisione 467): i primi tre nomi, gli altri con «+N» e tutti nel suggerimento.
const nomiVisibili = computed(() => (props.destinatari?.nomi ?? []).slice(0, 3));
const nomiAltri = computed(() => Math.max(0, (props.destinatari?.nomi.length ?? 0) - 3));
const testoLungo = computed(() => (props.comunicazione.description?.length ?? 0) > 280);

const breadcrumbs = computed<BreadcrumbItem[]>(() => [
  {
      title: trans('comunicazioni.breadcrumbs.list'), 
      href: route(generateRoute('comunicazioni.index'))
  },
  {
      title: trans('comunicazioni.breadcrumbs.view'),
      href: '#',
  }
]);

const pageGuides = computed(() => [
  {
    title: trans('comunicazioni.guides.read_title'),
    description: trans('comunicazioni.guides.read_desc'),
    icon: Megaphone,
    colorVariant: 'blue' as const
  },
  {
    title: trans('comunicazioni.guides.visibility_title'),
    description: trans('comunicazioni.guides.visibility_desc'),
    icon: Eye,
    colorVariant: 'amber' as const
  },
  {
    title: trans('comunicazioni.guides.urgency_title'),
    description: trans('comunicazioni.guides.urgency_desc'),
    icon: BellRing,
    colorVariant: 'emerald' as const
  }
]);

const mostraModaleImpostazioniCommenti = ref(false);
const commentiSettingsForm = useForm({
    can_comment: Boolean(props.comunicazione.can_comment ?? props.commenti_config?.can_comment),
    commenti_moderati: Boolean(props.comunicazione.commenti_moderati ?? props.commenti_config?.commenti_moderati),
});

function apriModaleImpostazioniCommenti() {
    commentiSettingsForm.can_comment = Boolean(props.comunicazione.can_comment ?? props.commenti_config?.can_comment);
    commentiSettingsForm.commenti_moderati = Boolean(props.comunicazione.commenti_moderati ?? props.commenti_config?.commenti_moderati);
    mostraModaleImpostazioniCommenti.value = true;
}

function salvaImpostazioniCommenti() {
    commentiSettingsForm.patch(
        route('admin.comunicazioni.commenti.impostazioni', { comunicazione: props.comunicazione.id }),
        {
            // Standard 16: tornano solo la comunicazione, le discussioni, le impostazioni dei commenti e il messaggio.
            preserveScroll: true,
            preserveState: true,
            only: ['comunicazione', 'discussioni', 'commenti_config', 'flash'],
            showProgress: false,
            onSuccess: () => {
                mostraModaleImpostazioniCommenti.value = false;
            },
        }
    );
}

// I pubblicati e gli in attesa, come il contatore dell'elenco; non i nascosti che chi modera legge in grigio (decisione 457).
const totaleCommenti = computed(() => {
    return (props.discussioni ?? []).reduce((acc, d) => acc + (d.commenti ?? []).filter((c) => c.stato !== 'nascosto').length, 0);
});

// Decisione 441: per chi modera, quanti commenti aspettano l'approvazione (come l'intestazione dei commenti delle segnalazioni).
const commentiInAttesa = computed(() => {
    if (!props.commenti_config?.puo_moderare) {
        return 0;
    }

    return (props.discussioni ?? []).reduce((acc, d) => acc + (d.commenti ?? []).filter((c) => c.stato === 'in_attesa').length, 0);
});
</script>

<template>
  <Head :title="trans('comunicazioni.header.view_communication_head')" />

  <AppLayout>
    <div class="px-6 py-8 space-y-6">

        <PageHeaderGuide
            :page-title="trans('comunicazioni.header.view_communication_title')"
            :page-subtitle="trans('comunicazioni.header.view_communication_description')"
            :guides="pageGuides"
            :breadcrumbs="breadcrumbs"
            :video-url="null"
            :back-url="route(generateRoute('comunicazioni.index'))"
            :back-text="trans('comunicazioni.actions.back_to_list')"
        >
        </PageHeaderGuide>

        <div class="space-y-6">
            <!-- Prima riga: il testo a sinistra, i parametri a destra, alti uguali -->
            <div data-riga="scheda" class="grid grid-cols-1 lg:grid-cols-3 gap-6 items-stretch">
                <div class="lg:col-span-2 flex flex-col">
                    <Card class="border-dashed shadow-sm bg-white dark:bg-slate-950 flex-1 flex flex-col">
                        <CardHeader class="pb-4 border-b border-slate-100 dark:border-slate-800 shrink-0">
                            <div class="flex items-start justify-between gap-4">
                                <div class="space-y-1">
                                    <div class="flex items-center gap-3">
                                        <component
                                            :is="currentPriority?.icon || Info"
                                            class="w-6 h-6"
                                            :class="currentPriority?.colorClass || 'text-slate-400'"
                                        />
                                        <CardTitle class="text-2xl font-bold leading-tight text-slate-900 dark:text-white">
                                            {{ comunicazione.subject }}
                                        </CardTitle>
                                    </div>
                                    
                                    <div class="flex items-center gap-2 text-sm text-slate-500 dark:text-slate-400 pt-3">
                                        <CalendarDays class="w-4 h-4 text-slate-400" />
                                        <span>
                                            {{ 
                                                trans('comunicazioni.visibility.sent_on_by', { 
                                                    date: comunicazione.created_at, 
                                                    name: comunicazione.created_by?.user?.name || trans('comunicazioni.label.administrator') 
                                                }) 
                                            }}
                                        </span>
                                    </div>

                                </div>
                            </div>
                        </CardHeader>
                        <CardContent class="pt-6 flex-1 flex flex-col">
                            <div
                                data-testo-comunicazione
                                class="prose prose-slate dark:prose-invert max-w-none text-slate-700 dark:text-slate-300 whitespace-pre-wrap leading-relaxed"
                                :class="{ 'line-clamp-4': testoLungo && !espanso }"
                            >
                                {{ comunicazione.description }}
                            </div>

                            <button
                                v-if="testoLungo"
                                type="button"
                                data-leggi-tutto
                                @click="espanso = !espanso"
                                class="inline-flex items-center gap-1 text-xs font-semibold text-slate-700 dark:text-slate-300 hover:text-slate-900 dark:hover:text-white transition-colors mt-3 cursor-pointer self-start"
                            >
                                <span>{{ espanso ? trans('comunicazioni.label.read_less') : trans('comunicazioni.label.read_more') }}</span>
                                <component :is="espanso ? ChevronUp : ChevronDown" class="w-3.5 h-3.5" />
                            </button>
                        </CardContent>
                    </Card>
                </div>

                <div class="lg:col-span-1 flex flex-col">
                    <Card class="border-dashed shadow-sm bg-slate-50/50 dark:bg-slate-900/20 flex-1 flex flex-col">
                        <CardContent class="space-y-5 pt-5">
                            <div>
                                <p class="text-xs font-bold uppercase tracking-wider text-slate-500 dark:text-slate-400 mb-2">
                                    {{ trans('comunicazioni.label.priority') }}
                                </p>
                                <div v-if="currentPriority" class="flex items-center gap-2">
                                    <component
                                        :is="currentPriority.icon"
                                        class="w-4 h-4"
                                        :class="currentPriority.colorClass"
                                    />
                                    <span class="text-sm font-semibold capitalize text-slate-900 dark:text-slate-100">
                                        {{ trans(currentPriority.label) }}
                                    </span>
                                </div>
                                <div v-else class="text-sm font-semibold text-slate-400">
                                    {{ trans('comunicazioni.label.none') }}
                                </div>
                            </div>

                            <div>
                                <p class="text-xs font-bold uppercase tracking-wider text-slate-500 dark:text-slate-400 mb-2">
                                    {{ trans('comunicazioni.label.visibility') }}
                                </p>
                                
                                <div class="flex items-center gap-2">
                                    <div class="w-2 h-2 rounded-full shrink-0" :class="colorePubblicazione(statoCorrente)"></div>
                                    <span class="text-sm font-semibold text-slate-900 dark:text-slate-100">
                                        {{ trans(etichettaStatoPubblicazione('comunicazioni', comunicazione.is_published, comunicazione.is_approved)) }}
                                    </span>
                                </div>
                                <div v-if="etichettaPrivata" class="flex items-center gap-1.5 mt-1.5 text-xs text-slate-500" :title="trans('comunicazioni.privacy.private_hint')">
                                    <Lock class="w-3 h-3 shrink-0" />
                                    <span>{{ trans(etichettaPrivata) }}</span>
                                </div>
                                <!-- «In evidenza» solo quando lo è; i commenti li dice la loro card (decisione 470) -->
                                <div v-if="comunicazione.is_featured" data-in-evidenza class="flex items-center gap-1.5 mt-1.5 text-xs text-slate-500">
                                    <Star class="w-3 h-3 shrink-0 text-amber-500" />
                                    <span>{{ trans('comunicazioni.label.in_evidenza') }}</span>
                                </div>
                            </div>

                            <!-- I destinatari, solo nel pannello (decisione 467) -->
                            <div v-if="destinatari" data-destinatari class="border-t border-dashed pt-4">
                                <p class="text-xs font-bold uppercase tracking-wider text-slate-500 dark:text-slate-400 mb-2">
                                    {{ trans('comunicazioni.label.recipients') }}
                                </p>
                                <p v-if="destinatari.tipo === 'privata'" class="text-sm font-semibold text-slate-900 dark:text-slate-100">
                                    {{ trans('comunicazioni.label.destinatari_privata') }}
                                </p>
                                <p v-else-if="destinatari.tipo === 'condomini' && destinatari.totale === 1" class="text-sm font-semibold text-slate-900 dark:text-slate-100 truncate">
                                    {{ destinatari.nomi[0] }}
                                </p>
                                <template v-else>
                                    <p class="text-sm font-semibold text-slate-900 dark:text-slate-100">
                                        {{ transChoice(destinatari.tipo === 'persone' ? 'comunicazioni.label.destinatari_persone' : 'comunicazioni.label.destinatari_condomini', destinatari.totale, { count: String(destinatari.totale) }) }}
                                    </p>
                                    <!-- Una riga sola anche con molti condomìni: i nomi si troncano con i puntini, «+N» resta intero (tutti nel suggerimento) -->
                                    <p class="flex items-baseline gap-1 min-w-0 text-xs text-slate-500 dark:text-slate-400 mt-0.5">
                                        <span data-destinatari-nomi class="truncate min-w-0" :title="destinatari.nomi.join(', ')">{{ nomiVisibili.join(', ') }}</span>
                                        <TooltipProvider v-if="nomiAltri > 0">
                                            <Tooltip :delay-duration="200">
                                                <TooltipTrigger as-child>
                                                    <span data-destinatari-altri tabindex="0" class="shrink-0 tabular-nums cursor-default">+{{ nomiAltri }}</span>
                                                </TooltipTrigger>
                                                <TooltipContent side="top" :side-offset="5" class="max-w-xs">
                                                    <p class="text-xs">{{ destinatari.nomi.join(', ') }}</p>
                                                </TooltipContent>
                                            </Tooltip>
                                        </TooltipProvider>
                                    </p>
                                </template>
                            </div>

                        </CardContent>
                    </Card>
                </div>
            </div>

            <!-- Seconda riga: Commenti a sinistra, Allegati a destra -->
            <div
                data-riga="commenti-allegati"
                class="grid grid-cols-1 gap-6 items-start"
                :class="{ 'lg:grid-cols-2': portaCorrente === 'admin' || (props.discussioni && props.discussioni.length > 0) }"
            >
                <!-- Riquadro Commenti -->
                <Card
                    v-if="portaCorrente === 'admin' || (props.discussioni && props.discussioni.length > 0)"
                    id="commenti"
                    class="shadow-none"
                >
                    <CardHeader data-intestazione-commenti-card class="pb-4 border-b border-slate-100 dark:border-slate-800">
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <CardTitle class="text-base font-semibold flex flex-wrap items-center gap-2">
                                <MessageSquare class="h-4 w-4 text-muted-foreground" />
                                <span>{{ trans('commenti.titolo') }}</span>
                                <Badge variant="secondary" class="tabular-nums" data-totale-commenti>
                                    {{ totaleCommenti }}
                                </Badge>
                                <Badge
                                    v-if="commentiInAttesa > 0"
                                    variant="secondary"
                                    class="bg-amber-500/15 text-amber-700 dark:text-amber-300 border-amber-500/30 text-xs font-normal whitespace-nowrap gap-1"
                                >
                                    <Clock class="w-3 h-3" />
                                    <span>{{ commentiInAttesa }} {{ trans('commenti.in_attesa') }}</span>
                                </Badge>
                                <Badge
                                    v-if="!props.comunicazione.can_comment && !props.commenti_config?.can_comment"
                                    variant="outline"
                                    class="text-xs font-normal text-slate-500"
                                >
                                    {{ trans('commenti.chiusi') }}
                                </Badge>
                                <Badge
                                    v-if="props.comunicazione.commenti_moderati ?? props.commenti_config?.commenti_moderati"
                                    variant="secondary"
                                    class="bg-amber-500/15 text-amber-700 dark:text-amber-300 border-amber-500/30 text-xs font-normal whitespace-nowrap"
                                >
                                    {{ trans('commenti.in_moderazione') }}
                                </Badge>
                            </CardTitle>

                            <!-- Impostazioni commenti (solo se puo_impostare dal pannello) -->
                            <Button
                                v-if="commenti_config?.puo_impostare"
                                type="button"
                                variant="outline"
                                size="sm"
                                class="h-7 px-2 sm:px-2.5 text-xs gap-1.5 shrink-0"
                                :aria-label="trans('comunicazioni.settings.comments_title')"
                                :title="trans('comunicazioni.settings.comments_title')"
                                @click="apriModaleImpostazioniCommenti"
                            >
                                <Settings2 class="h-3.5 w-3.5 text-muted-foreground" />
                                <span class="hidden sm:inline">{{ trans('comunicazioni.settings.comments_title') }}</span>
                            </Button>
                        </div>
                        <p
                            v-if="portaCorrente === 'user' && props.discussioni?.some(d => d.tipo === 'persona')"
                            class="text-xs text-muted-foreground mt-1"
                        >
                            {{ trans('commenti.discussione_privata_portale') }}
                        </p>
                    </CardHeader>
                    <!-- La linea sotto l'intestazione e 16 px sotto, come gli allegati: il primo riquadro comincia alla loro stessa altezza (decisione 469) -->
                    <CardContent class="pt-4">
                        <DiscussioniComunicazione
                            :comunicazione-id="comunicazione.id"
                            :porta="portaCorrente"
                            :discussioni="props.discussioni || []"
                            :config="props.commenti_config || { can_comment: false, commenti_moderati: false, puo_impostare: false, puo_moderare: false }"
                            :ricarica="['discussioni', 'flash', 'inbox_count']"
                        />
                    </CardContent>
                </Card>

                <!-- Riquadro Allegati -->
                <AllegatiRiquadro
                    tipo="comunicazioni"
                    :elemento-id="comunicazione.id"
                    :porta="portaCorrente"
                    :allegati="props.allegati || []"
                    :config="props.allegati_config"
                />
            </div>
        </div>

    </div>

    <!-- Modale Impostazioni commenti per la comunicazione -->
    <Dialog v-if="commenti_config?.puo_impostare" v-model:open="mostraModaleImpostazioniCommenti">
        <DialogContent class="sm:max-w-md">
            <DialogHeader>
                <DialogTitle class="flex items-center gap-2">
                    <Settings2 class="h-4 w-4 text-muted-foreground" />
                    <span>{{ trans('comunicazioni.settings.comments_title') }}</span>
                </DialogTitle>
                <DialogDescription>
                    {{ trans('comunicazioni.settings.comments_dialog_description') }}
                </DialogDescription>
            </DialogHeader>

            <form @submit.prevent="salvaImpostazioniCommenti" class="space-y-4 py-2">
                <div class="space-y-1">
                    <div class="flex items-center justify-between space-x-2">
                        <label for="modal_can_comment" class="text-sm font-medium text-slate-700 dark:text-slate-300">
                            {{ trans('comunicazioni.settings.can_comment') }}
                        </label>
                        <Switch
                            id="modal_can_comment"
                            :checked="commentiSettingsForm.can_comment"
                            v-model="commentiSettingsForm.can_comment"
                            @update:checked="(val: boolean) => commentiSettingsForm.can_comment = val"
                        />
                    </div>
                    <p class="text-xs text-muted-foreground">
                        {{ trans('commenti.consenti_hint') }}
                    </p>
                </div>

                <!-- «Commenti in moderazione» la cambia solo chi modera: per gli altri il server la ignora, e qui non c'è. -->
                <div v-if="commenti_config?.puo_impostare_moderazione" class="space-y-1" data-testid="scelta-moderazione">
                    <div class="flex items-center justify-between space-x-2">
                        <label for="modal_commenti_moderati" class="text-sm font-medium text-slate-700 dark:text-slate-300">
                            {{ trans('comunicazioni.settings.commenti_moderati') }}
                        </label>
                        <Switch
                            id="modal_commenti_moderati"
                            :checked="commentiSettingsForm.commenti_moderati"
                            v-model="commentiSettingsForm.commenti_moderati"
                            @update:checked="(val: boolean) => commentiSettingsForm.commenti_moderati = val"
                        />
                    </div>
                    <p class="text-xs text-muted-foreground">
                        {{ trans('commenti.moderazione_hint') }}
                    </p>
                </div>

                <DialogFooter class="pt-4">
                    <Button
                        type="button"
                        variant="outline"
                        @click="mostraModaleImpostazioniCommenti = false"
                    >
                        {{ trans('comunicazioni.actions.cancel') }}
                    </Button>
                    <Button
                        type="submit"
                        :disabled="commentiSettingsForm.processing"
                    >
                        {{ trans('comunicazioni.actions.save') }}
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
  </AppLayout>
</template>
