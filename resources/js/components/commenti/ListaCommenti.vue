<!--
SPDX-License-Identifier: AGPL-3.0-or-later
Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
-->
<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { Link, useForm } from '@inertiajs/vue3';
import { usePermission } from '@/composables/permissions';
import { MessageSquare, Clock, ToggleLeft, ToggleRight, Settings2 } from 'lucide-vue-next';
import { trans } from 'laravel-vue-i18n';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Switch } from '@/components/ui/switch';
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog';
import { Empty, EmptyDescription, EmptyHeader, EmptyMedia, EmptyTitle } from '@/components/ui/empty';
import Commento, { type CommentoData } from '@/components/commenti/Commento.vue';
import FormCommento from '@/components/commenti/FormCommento.vue';

const props = defineProps<{
  segnalazioneId: number;
  commenti: CommentoData[];
  commentiInAttesa?: CommentoData[];
  commentiNascosti?: CommentoData[];
  commentiConfig: {
    can_comment: boolean;
    can_create: boolean;
    can_moderate: boolean;
    can_publish: boolean;
    commenti_moderati?: boolean;
    puo_impostare?: boolean;
    // «Commenti in moderazione»: la cambia solo chi modera (Fase 1-bis della 1.12.0-beta.11).
    puo_impostare_moderazione?: boolean;
  };
  currentUserId: number;
  // Standard 16: le proprietà che il server rimanda dopo un'azione sui commenti (una visita parziale).
  ricarica?: string[];
}>();

const { hasPermission, generateRoute } = usePermission();

const mostraModaleImpostazioniCommenti = ref(false);
const commentiSettingsForm = useForm({
  can_comment: Boolean(props.commentiConfig.can_comment),
  commenti_moderati: Boolean(props.commentiConfig.commenti_moderati),
});

watch(() => props.commentiConfig, (cfg) => {
  if (cfg) {
    commentiSettingsForm.can_comment = Boolean(cfg.can_comment);
    commentiSettingsForm.commenti_moderati = Boolean(cfg.commenti_moderati);
  }
}, { deep: true });

function apriModaleImpostazioniCommenti() {
  commentiSettingsForm.can_comment = Boolean(props.commentiConfig.can_comment);
  commentiSettingsForm.commenti_moderati = Boolean(props.commentiConfig.commenti_moderati);
  mostraModaleImpostazioniCommenti.value = true;
}

function salvaImpostazioniCommenti() {
  commentiSettingsForm.patch(route(generateRoute('segnalazioni.commenti.impostazioni'), { segnalazione: props.segnalazioneId }), {
    preserveScroll: true,
    // Standard 16: con `ricarica` tornano solo quelle proprietà e le impostazioni dei commenti.
    ...(props.ricarica ? { preserveState: true, only: [...props.ricarica, 'commenti_config'], showProgress: false } : {}),
    onSuccess: () => {
      mostraModaleImpostazioniCommenti.value = false;
    },
  });
}

// Tutti i commenti visibili: prima quelli in attesa, poi i pubblicati
const commentiDaMostrare = computed(() => {
  // I nascosti (solo per chi modera) stanno fra i pubblicati, in ordine di scrittura: da lì si rimettono visibili.
  const pubblicatiENascosti = props.commentiNascosti?.length
    ? [...props.commenti, ...props.commentiNascosti].sort((a, b) => a.id - b.id)
    : props.commenti;
  if (props.commentiInAttesa?.length) {
    return [...(props.commentiInAttesa ?? []), ...pubblicatiENascosti];
  }
  return pubblicatiENascosti;
});

// Decisione 479 (1): il numero accanto a «Commenti» conta i pubblicati e quelli in attesa che chi guarda riceve (chi modera tutti, il condòmino
// i suoi), mai i nascosti: la stessa regola dell'elenco e della scheda della comunicazione.
const totaleCommenti = computed(() => props.commenti.length + (props.commentiInAttesa?.length ?? 0));
const inAttesa = computed(() => props.commentiInAttesa?.length ?? 0);
</script>

<template>
  <div class="space-y-4">
    <!-- Header sezione commenti (allineato allo stile di CardTitle di Allegati) -->
    <!-- Una linea sottile sotto l'intestazione, larga quanto la card (`-mx-6 px-6` nel `p-6` della card), e 16 px sopra e sotto, come la card
         degli allegati: il primo riquadro comincia alla loro stessa altezza (decisione 469). In Tailwind 4 `space-y-4` mette il margine in fondo
         a ogni figlio: `mb-4` lo tiene a 16 px anche se un giorno cambia lo spazio fra i figli. -->
    <div data-intestazione-commenti class="flex flex-wrap items-center justify-between gap-2 -mx-6 px-6 pb-4 border-b border-slate-100 dark:border-slate-800 mb-4">
      <div class="flex flex-wrap items-center gap-2">
        <div class="text-base font-semibold flex flex-wrap items-center gap-2">
          <MessageSquare class="h-4 w-4 text-muted-foreground" />
          <span>{{ trans('commenti.titolo') }}</span>
          <Badge variant="secondary" class="tabular-nums">
            {{ totaleCommenti }}
          </Badge>
          <Badge
            v-if="!commentiConfig.can_comment"
            variant="outline"
            class="text-xs font-normal text-slate-500"
          >
            {{ trans('commenti.chiusi') }}
          </Badge>
          <Badge
            v-if="commentiConfig.commenti_moderati"
            variant="secondary"
            class="bg-amber-500/15 text-amber-700 dark:text-amber-300 border-amber-500/30 text-xs font-normal whitespace-nowrap"
          >
            {{ trans('commenti.in_moderazione') }}
          </Badge>
        </div>

        <!-- Badge in attesa (solo moderatori) -->
        <Badge
          v-if="commentiConfig.can_moderate && inAttesa > 0"
          variant="secondary"
          class="bg-amber-500/15 text-amber-700 dark:text-amber-300 border-amber-500/30 text-xs font-normal whitespace-nowrap gap-1"
        >
          <Clock class="w-3 h-3" />
          <span>{{ inAttesa }} {{ trans('commenti.in_attesa') }}</span>
        </Badge>
      </div>

      <div class="flex items-center gap-2">
        <!-- Pulsante impostazioni commenti (moderazione e toggle) -->
        <Button
          v-if="commentiConfig.puo_impostare ?? commentiConfig.can_moderate"
          type="button"
          variant="outline"
          size="sm"
          class="h-7 px-2 sm:px-2.5 text-xs gap-1.5 shrink-0"
          :aria-label="trans('segnalazioni.settings.comments_title', 'Impostazioni commenti')"
          :title="trans('segnalazioni.settings.comments_title', 'Impostazioni commenti')"
          @click="apriModaleImpostazioniCommenti"
        >
          <Settings2 class="h-3.5 w-3.5 text-muted-foreground" />
          <span class="hidden sm:inline">{{ trans('segnalazioni.settings.comments_title', 'Impostazioni commenti') }}</span>
        </Button>
      </div>
    </div>

    <!-- Lista commenti vuota (stesso stile della card degli allegati) -->
    <Empty v-if="commentiDaMostrare.length === 0" class="border border-dashed py-8">
      <EmptyHeader>
        <EmptyMedia variant="icon" class="bg-slate-50/50 dark:bg-slate-800/50 text-slate-500">
          <MessageSquare class="w-6 h-6" />
        </EmptyMedia>
        <EmptyTitle>{{ trans('commenti.nessun_commento') }}</EmptyTitle>
        <EmptyDescription>{{ trans('commenti.nessun_commento_descrizione') }}</EmptyDescription>
      </EmptyHeader>
    </Empty>

    <!-- Lista commenti -->
    <div v-else class="space-y-5">
      <Commento
        v-for="commento in commentiDaMostrare"
        :key="commento.id"
        :commento="commento"
        :can-moderate="commentiConfig.can_moderate"
        :current-user-id="currentUserId"
        :ricarica="ricarica"
      />
    </div>

    <!-- Form nuovo commento -->
    <FormCommento
      :segnalazione-id="segnalazioneId"
      :can-comment="commentiConfig.can_comment"
      :can-create="commentiConfig.can_create"
      :ricarica="ricarica"
      class="mt-4"
    />

    <!-- Modale Impostazioni commenti per la segnalazione -->
    <Dialog v-if="commentiConfig.puo_impostare ?? commentiConfig.can_moderate" v-model:open="mostraModaleImpostazioniCommenti">
      <DialogContent class="sm:max-w-md">
        <DialogHeader>
          <DialogTitle class="flex items-center gap-2">
            <Settings2 class="h-4 w-4 text-muted-foreground" />
            <span>{{ trans('segnalazioni.settings.comments_title', 'Impostazioni commenti') }}</span>
          </DialogTitle>
          <DialogDescription>
            {{ trans('segnalazioni.settings.comments_dialog_description', 'Modifica le impostazioni di scrittura e moderazione dei commenti per questa segnalazione.') }}
          </DialogDescription>
        </DialogHeader>

        <form @submit.prevent="salvaImpostazioniCommenti" class="space-y-4 py-2">
          <div class="space-y-1">
            <div class="flex items-center justify-between space-x-2">
              <label for="modal_segnalazione_can_comment" class="text-sm font-medium text-slate-700 dark:text-slate-300">
                {{ trans('segnalazioni.settings.can_comment', 'Consenti commenti') }}
              </label>
              <Switch
                id="modal_segnalazione_can_comment"
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
          <div v-if="commentiConfig.puo_impostare_moderazione ?? commentiConfig.can_moderate" class="space-y-1" data-testid="scelta-moderazione">
            <div class="flex items-center justify-between space-x-2">
              <label for="modal_segnalazione_commenti_moderati" class="text-sm font-medium text-slate-700 dark:text-slate-300">
                {{ trans('segnalazioni.settings.commenti_moderati', 'Commenti in moderazione') }}
              </label>
              <Switch
                id="modal_segnalazione_commenti_moderati"
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
              {{ trans('segnalazioni.actions.cancel', 'Annulla') }}
            </Button>
            <Button
              type="submit"
              :disabled="commentiSettingsForm.processing"
            >
              {{ trans('segnalazioni.actions.save', 'Salva') }}
            </Button>
          </DialogFooter>
        </form>
      </DialogContent>
    </Dialog>
  </div>
</template>
