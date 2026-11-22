<!--
SPDX-License-Identifier: AGPL-3.0-or-later
Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
-->
<script setup lang="ts">

import { computed, ref } from 'vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import AppLayout from '@/layouts/AppLayout.vue';
import PageHeaderGuide from '@/components/PageHeaderGuide.vue';
import PulsanteDaPortareInAssemblea from '@/components/assemblee/PulsanteDaPortareInAssemblea.vue';
import { Card, CardHeader, CardTitle, CardContent } from '@/components/ui/card';
import { Lock, LockOpen, ShieldCheck, User, CalendarDays, AlertTriangle, Building2, SlidersHorizontal, Globe, ChevronDown, ChevronUp } from 'lucide-vue-next';
import { usePermission } from '@/composables/permissions';
import { priorityConstants, statoConstants } from '@/lib/segnalazioni/constants';
import { Permission } from '@/enums/Permission';
import { trans } from 'laravel-vue-i18n';
import type { BreadcrumbItem } from '@/types';
import type { Segnalazione } from '@/types/segnalazioni';
import '@vuepic/vue-datepicker/dist/main.css';
import ListaCommenti from '@/components/commenti/ListaCommenti.vue';
import AllegatiRiquadro from '@/components/allegati/AllegatiRiquadro.vue';
import AssegnazioneSegnalazione from '@/components/segnalazioni/AssegnazioneSegnalazione.vue';
import ParametroVeloce from '@/components/segnalazioni/ParametroVeloce.vue';
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import { colorePubblicazione, etichettaPrivacy, etichettaStatoPubblicazione, statoPubblicazione } from '@/lib/pubblicazione';
import { argomentiConfermaRendiPubblica, chiaveAutorePerdeLaScheda, chiaveConfermaRendiPubblica, offreRendiPubblica } from '@/lib/segnalazioni/privacy';
import type { AllegatiConfig, AllegatoItem } from '@/types/allegati';

const props = defineProps<{
  segnalazione: Segnalazione | any;
  assegnazione?: {
    puo_assegnare: boolean;
    io: number;
    assegnabili: Array<{ id: number; name: string }>;
  } | any;
  commenti_config: {
    can_comment: boolean;
    can_create: boolean;
    can_moderate: boolean;
    can_publish: boolean;
  };
  allegati?: AllegatoItem[];
  allegati_config?: AllegatiConfig;
  /** Coda 671: chi può cambiare lo stato e la priorità dalla scheda, con lo stesso permesso di «Modifica» (lo calcola il server). */
  puo_modificare?: boolean;
  /** Coda 304: se con «Rendi pubblica» l'autore non la vedrà più dal portale (lo calcola il server). */
  rendi_pubblica?: {
    autore_perde_la_scheda: boolean;
  };
}>();  

const { hasPermission, generateRoute } = usePermission();

const page = usePage();
const currentUserId = computed(() => (page.props.auth as any).user.id);

// Estrazione sicura dei metadati da visualizzare nella sidebar
const priorityItem = computed(() => {
  return priorityConstants.find(p => p.value === props.segnalazione.priority);
});

// Coda 306: lo stato di pubblicazione e la privacy sono due indicazioni distinte.
const statoPubblicazioneCorrente = computed(() =>
  statoPubblicazione(props.segnalazione.is_published, props.segnalazione.is_approved)
);
const etichettaPrivata = computed(() => etichettaPrivacy('segnalazioni', props.segnalazione.is_private));

// Coda 671 (decisioni 466 (2) e 487): lo stato lavorazione e la priorità si cambiano dalla card dei parametri e si salvano subito, per la stessa
// strada di «Modifica» (la rotta `segnalazioni.parametro`), con una visita parziale. «Chiusa» chiede prima una conferma: il compito esce
// dall'Action inbox. Un rifiuto del server si legge sotto il valore.
const confermaChiusura = ref(false);
const invioParametro = ref(false);
const erroreParametro = ref<{ campo: 'stato' | 'priority'; testo: string } | null>(null);

function inviaParametro(campo: 'stato' | 'priority', valore: string) {
  erroreParametro.value = null;
  router.patch(route(generateRoute('segnalazioni.parametro'), { segnalazione: props.segnalazione.id }), { [campo]: valore }, {
    only: ['segnalazione', 'puo_modificare', 'inbox_count'],
    preserveState: true,
    preserveScroll: true,
    onStart: () => { invioParametro.value = true; },
    onError: (errori: Record<string, string>) => {
      erroreParametro.value = { campo, testo: errori[campo] ?? Object.values(errori)[0] ?? '' };
    },
    onFinish: () => {
      invioParametro.value = false;
      confermaChiusura.value = false;
    },
  });
}

function scegliParametro(campo: 'stato' | 'priority', valore: string) {
  if (props.segnalazione[campo] === valore) {
    return;
  }
  if (campo === 'stato' && valore === 'chiusa') {
    confermaChiusura.value = true;
    return;
  }
  inviaParametro(campo, valore);
}

// Coda 591 (giro 2): «Approva» chiama lo stesso comando dello scudo dell'elenco con lo stato voluto
// (`is_approved: true`). C'è solo se non è approvata e per chi approva le segnalazioni.
const mostraApprova = computed(() =>
  !props.segnalazione.is_approved && hasPermission([Permission.APPROVE_SEGNALAZIONI])
);

const approva = () => {
  router.put(
    route(generateRoute('segnalazioni.toggle-approval'), { id: props.segnalazione.id }),
    { is_approved: true },
    // P7 della lente P (Fase 1-bis della beta.12): una visita parziale (standard 16), con quello che l'approvazione può cambiare nella scheda.
    {
      only: ['segnalazione', 'commenti_config', 'allegati', 'allegati_config', 'rendi_pubblica', 'inbox_count', 'flash'],
      preserveState: true,
      preserveScroll: true,
    }
  );
};

// Coda 304: «Rendi pubblica», con la conferma che dice chi la vedrà e con quale nome (decisione
// 129.2). Il pulsante c'è solo su una privata e per chi approva le segnalazioni, come il server. Su
// una privata non ancora pubblicata e approvata la conferma dice che la vedranno quando lo sarà.
const mostraRendiPubblica = computed(() =>
  offreRendiPubblica(props.segnalazione, hasPermission([Permission.APPROVE_SEGNALAZIONI]))
);
const confermaRendiPubblica = ref(false);
const invioRendiPubblica = ref(false);
const espanso = ref(false);
const testoLungo = computed(() => (props.segnalazione.description?.length ?? 0) > 280);

// Se l'autore non è più nel palazzo, la conferma dice anche che dopo non la vedrà più dal portale.
const descrizioneRendiPubblica = computed(() => {
  const argomenti = argomentiConfermaRendiPubblica(props.segnalazione, {
    condominio: trans('segnalazioni.label.building'),
    autore: trans('segnalazioni.details.admin_sender'),
  });
  const frasi = [trans(chiaveConfermaRendiPubblica(props.segnalazione), argomenti)];
  const autoreFuori = chiaveAutorePerdeLaScheda(props.rendi_pubblica?.autore_perde_la_scheda);

  if (autoreFuori) {
    frasi.push(trans(autoreFuori, argomenti));
  }

  return frasi.join(' ');
});

const rendiPubblica = () => {
  router.put(route(generateRoute('segnalazioni.rendi-pubblica'), { segnalazione: props.segnalazione.id }), {}, {
    preserveScroll: true,
    onStart: () => { invioRendiPubblica.value = true; },
    onFinish: () => {
      invioRendiPubblica.value = false;
      confermaRendiPubblica.value = false;
    },
  });
};

const breadcrumbs = computed<BreadcrumbItem[]>(() => [
  {
      title: trans('segnalazioni.breadcrumbs.list'), 
      href: route(generateRoute('segnalazioni.index'))
  },
  {
      title: trans('segnalazioni.breadcrumbs.view'),
      href: '#',
  }
]);

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
</script>

<template>
  <Head :title="trans('segnalazioni.header.view_ticket_head')" />
  
  <AppLayout>
    <div class="px-6 py-8 space-y-6">
        
        <PageHeaderGuide
            :storia="{ tipo: 'segnalazione', id: props.segnalazione.id }"
            :page-title="trans('segnalazioni.header.view_ticket_title')"
            :page-subtitle="trans('segnalazioni.header.view_ticket_description')"
            :guides="pageGuides"
            :breadcrumbs="breadcrumbs"
            :video-url="null"
            :back-url="route(generateRoute('segnalazioni.index'))"
            :back-text="trans('segnalazioni.actions.back_to_list')"
        >
            <template #actions>
                <!-- «Da portare in assemblea» per i lavori da decidere (1.12.0-beta.13, P8): la frase proposta dice solo il giorno della segnalazione. -->
                <PulsanteDaPortareInAssemblea v-if="props.segnalazione.condominio?.id" :condominio-id="props.segnalazione.condominio.id" origine-tipo="segnalazione"
                    :origine-id="props.segnalazione.id" forma="riga" />
                <Link 
                    as="button"
                    method="post"
                    v-if="hasPermission([Permission.EDIT_SEGNALAZIONI])"
                    :href="route(generateRoute('segnalazioni.toggleResolve'), { id: props.segnalazione.id })" 
                    class="inline-flex items-center justify-center gap-2 rounded-md bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 shadow-sm text-sm font-medium text-slate-700 dark:text-slate-300 px-3 py-1.5 h-8 hover:bg-slate-50 dark:hover:bg-slate-800 transition-colors cursor-pointer"
                >
                    <LockOpen v-if="props.segnalazione.is_locked" class="w-4 h-4 text-emerald-500" />
                    <Lock v-else class="w-4 h-4 text-amber-500" />
                    <span>
                        {{ 
                            props.segnalazione.is_locked 
                            ? trans('segnalazioni.actions.unlock_ticket') 
                            : trans('segnalazioni.actions.lock_ticket') 
                        }}
                    </span>
                </Link>

                <button
                    v-if="mostraApprova"
                    type="button"
                    data-azione="approva"
                    @click="approva"
                    class="inline-flex items-center justify-center gap-2 rounded-md bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 shadow-sm text-sm font-medium text-slate-700 dark:text-slate-300 px-3 py-1.5 h-8 hover:bg-slate-50 dark:hover:bg-slate-800 transition-colors cursor-pointer"
                >
                    <ShieldCheck class="w-4 h-4 text-emerald-500" />
                    <span>{{ trans('segnalazioni.actions.approve') }}</span>
                </button>

                <button
                    v-if="mostraRendiPubblica"
                    type="button"
                    @click="confermaRendiPubblica = true"
                    class="inline-flex items-center justify-center gap-2 rounded-md bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 shadow-sm text-sm font-medium text-slate-700 dark:text-slate-300 px-3 py-1.5 h-8 hover:bg-slate-50 dark:hover:bg-slate-800 transition-colors cursor-pointer"
                >
                    <Globe class="w-4 h-4 text-sky-500" />
                    <span>{{ trans('segnalazioni.privacy.make_public') }}</span>
                </button>
            </template>
        </PageHeaderGuide>

        <ConfirmDialog
            v-model="confermaRendiPubblica"
            :title="trans('segnalazioni.privacy.make_public_title')"
            :description="descrizioneRendiPubblica"
            :confirm-text="trans('segnalazioni.privacy.make_public_confirm')"
            :loading="invioRendiPubblica"
            variant="warning"
            @confirm="rendiPubblica"
        />

        <!-- Coda 671 (decisione 487): prima di «Chiusa» una conferma, che dice solo ciò che succede davvero. -->
        <ConfirmDialog
            v-model="confermaChiusura"
            :title="trans('segnalazioni.quick.close_title')"
            :description="trans('segnalazioni.quick.close_description')"
            :confirm-text="trans('segnalazioni.quick.close_confirm')"
            :loading="invioParametro"
            variant="warning"
            @confirm="inviaParametro('stato', 'chiusa')"
        />

        <div class="space-y-6">
            <!-- Prima riga: due colonne per i dettagli della segnalazione (descrizione e parametri) -->
            <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 items-stretch">
                <div class="lg:col-span-2 flex flex-col">
                    <Card class="border-dashed shadow-sm bg-white dark:bg-slate-950 flex-1 flex flex-col">
                        <CardHeader class="pb-4 border-b border-slate-100 dark:border-slate-800 shrink-0">
                            <div class="flex items-start justify-between gap-4">
                                <div class="space-y-1">
                                    <div class="flex items-center gap-3">
                                        <!-- L'icona della priorità, nel suo colore, come nella scheda della comunicazione -->
                                        <component
                                            :is="priorityItem?.icon || AlertTriangle"
                                            data-icona-titolo
                                            class="w-6 h-6 shrink-0"
                                            :class="priorityItem?.colorClass || 'text-slate-400'"
                                        />
                                        <CardTitle class="text-2xl font-bold leading-tight text-slate-900 dark:text-white">
                                            {{ props.segnalazione.subject }}
                                        </CardTitle>
                                    </div>
                                    <div class="flex flex-wrap items-center gap-x-6 gap-y-2 text-sm text-slate-500 dark:text-slate-400 pt-3">
                                        <div class="flex items-center gap-2">
                                            <User class="w-4 h-4 text-slate-400" />
                                            <span class="font-medium">
                                                {{ props.segnalazione.created_by?.user?.name || trans('segnalazioni.details.admin_sender') }}
                                            </span>
                                        </div>
                                        <div class="flex items-center gap-2">
                                            <CalendarDays class="w-4 h-4 text-slate-400" />
                                            <span>
                                                {{ 
                                                    trans('segnalazioni.visibility.sent_on_by', { 
                                                        date: props.segnalazione.created_at, 
                                                        name: props.segnalazione.created_by?.user?.name || trans('segnalazioni.details.admin_sender') 
                                                    }) 
                                                }}
                                            </span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </CardHeader>
                        <CardContent class="pt-6 flex-1 flex flex-col justify-between">
                            <div
                                class="prose prose-slate dark:prose-invert max-w-none text-slate-700 dark:text-slate-300 whitespace-pre-wrap leading-relaxed"
                                :class="{ 'line-clamp-4': testoLungo && !espanso }"
                            >
                                {{ props.segnalazione.description }}
                            </div>

                            <button
                                v-if="testoLungo"
                                type="button"
                                @click="espanso = !espanso"
                                class="inline-flex items-center gap-1 text-xs font-semibold text-slate-700 dark:text-slate-300 hover:text-slate-900 dark:hover:text-white transition-colors mt-3 cursor-pointer self-start"
                            >
                                <span>{{ espanso ? 'Leggi meno' : 'Leggi tutto' }}</span>
                                <component :is="espanso ? ChevronUp : ChevronDown" class="w-3.5 h-3.5" />
                            </button>
                        </CardContent>
                    </Card>
                </div>

                <div class="lg:col-span-1 flex flex-col">
                    <Card class="border-dashed shadow-sm bg-slate-50/50 dark:bg-slate-900/20 flex-1 flex flex-col">
                        <!-- Le righe in alto: senza «Interazioni» non c'è più niente da spingere in fondo (decisione 470) -->
                        <CardContent data-card-parametri class="space-y-6 pt-5 p-3 flex-1 flex flex-col">
                            <div class="grid grid-cols-2 gap-4 border-b border-dashed pb-4">
                                <div class="min-w-0">
                                    <p class="text-xs font-bold uppercase tracking-wider text-slate-500 dark:text-slate-400 mb-1.5">
                                        {{ trans('segnalazioni.details.current_status') }}
                                    </p>
                                    <ParametroVeloce
                                        campo="stato"
                                        :opzioni="statoConstants"
                                        :valore="props.segnalazione.stato"
                                        :comando="trans('segnalazioni.quick.change_status')"
                                        :vuoto="trans('segnalazioni.label.no_status')"
                                        :puo-modificare="props.puo_modificare === true"
                                        :in-corso="invioParametro"
                                        @scegli="(v: string) => scegliParametro('stato', v)"
                                    />
                                    <p v-if="erroreParametro?.campo === 'stato'" data-errore="stato" class="mt-1 text-xs font-medium text-red-600 dark:text-red-400">{{ erroreParametro.testo }}</p>
                                </div>
                                
                                <div class="min-w-0">
                                    <p class="text-xs font-bold uppercase tracking-wider text-slate-500 dark:text-slate-400 mb-1.5">
                                        {{ trans('segnalazioni.details.priority_level') }}
                                    </p>
                                    <ParametroVeloce
                                        campo="priority"
                                        :opzioni="priorityConstants"
                                        :valore="props.segnalazione.priority"
                                        :comando="trans('segnalazioni.quick.change_priority')"
                                        :vuoto="trans('segnalazioni.label.no_priority')"
                                        :puo-modificare="props.puo_modificare === true"
                                        :in-corso="invioParametro"
                                        @scegli="(v: string) => scegliParametro('priority', v)"
                                    />
                                    <p v-if="erroreParametro?.campo === 'priority'" data-errore="priority" class="mt-1 text-xs font-medium text-red-600 dark:text-red-400">{{ erroreParametro.testo }}</p>
                                </div>
                            </div>

                            <div class="grid grid-cols-2 gap-4">
                                <div class="min-w-0">
                                    <p class="text-xs font-bold uppercase tracking-wider text-slate-500 dark:text-slate-400 mb-1.5">
                                        {{ trans('segnalazioni.label.building') }}
                                    </p>
                                    <div data-parametro="condominio" class="flex items-center gap-2 group cursor-help">
                                        <span 
                                            class="text-sm font-semibold text-slate-900 dark:text-slate-100 truncate block"
                                            :title="segnalazione.condominio?.full?.nome || segnalazione.condominio?.nome"
                                        >
                                            {{ segnalazione.condominio?.full?.nome || segnalazione.condominio?.nome }}
                                        </span>
                                    </div>
                                </div>

                                <div class="min-w-0">
                                    <p class="text-xs font-bold uppercase tracking-wider text-slate-500 dark:text-slate-400 mb-1.5">
                                        {{ trans('segnalazioni.details.visibility_status') }}
                                    </p>
                                    <div class="flex items-start gap-2 min-w-0">
                                        <div class="w-2 h-2 rounded-full shrink-0 mt-1.5" :class="colorePubblicazione(statoPubblicazioneCorrente)"></div>
                                        <span class="text-sm font-semibold text-slate-900 dark:text-slate-100 leading-snug">
                                            {{ trans(etichettaStatoPubblicazione('segnalazioni', props.segnalazione.is_published, props.segnalazione.is_approved)) }}
                                        </span>
                                    </div>
                                    <div v-if="etichettaPrivata" class="flex items-center gap-1.5 mt-1.5 text-xs text-slate-500" :title="trans('segnalazioni.privacy.private_hint')">
                                        <Lock class="w-3 h-3 shrink-0" />
                                        <span>{{ trans(etichettaPrivata) }}</span>
                                    </div>
                                    <!-- «Bloccata» solo quando lo è: aperta non dice niente, per non confondersi con lo stato «Aperta» (decisioni 466 e 470) -->
                                    <div v-if="props.segnalazione.is_locked" data-bloccata class="flex items-center gap-1.5 mt-1.5 text-xs text-amber-600 dark:text-amber-400">
                                        <Lock class="w-3 h-3 shrink-0" />
                                        <span>{{ trans('segnalazioni.details.locked') }}</span>
                                    </div>
                                </div>
                            </div>

                            <!-- Chi segue la segnalazione (Coda 591), sotto la visibilità: il riquadro «Interazioni» non c'è più (decisione 470) -->
                            <div class="border-t border-dashed pt-4">
                                <AssegnazioneSegnalazione
                                    :segnalazione-id="props.segnalazione.id"
                                    :assegnata-a="props.segnalazione.assegnata_a ?? null"
                                    :assegnazione="props.assegnazione"
                                />
                            </div>

                        </CardContent>
                    </Card>
                </div>
            </div>

            <!-- Seconda riga: due colonne affiancate su tutta la larghezza (prima i Commenti, poi gli Allegati) -->
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 items-start">
                <!-- Sezione Commenti -->
                <Card id="commenti" class="shadow-none">
                    <CardContent class="p-6">
                        <ListaCommenti 
                            :segnalazione-id="props.segnalazione.id"
                            :commenti="props.segnalazione.commenti || []"
                            :commenti-in-attesa="props.segnalazione.commentiInAttesa || []"
                            :commenti-nascosti="props.segnalazione.commentiNascosti || []"
                            :commenti-config="props.commenti_config"
                            :current-user-id="currentUserId"
                            :ricarica="['segnalazione', 'flash', 'inbox_count']"
                        />
                    </CardContent>
                </Card>

                <!-- Sezione Allegati -->
                <AllegatiRiquadro
                    tipo="segnalazioni"
                    :elemento-id="props.segnalazione.id"
                    porta="admin"
                    :allegati="props.allegati || []"
                    :config="props.allegati_config"
                />
            </div>
        </div>
      </div>
    </AppLayout> 
</template>

<style src="vue-select/dist/vue-select.css"></style>