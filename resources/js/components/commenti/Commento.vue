<!--
SPDX-License-Identifier: AGPL-3.0-or-later
Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
-->
<script setup lang="ts">
import { ref, computed } from 'vue';
import { useForm, router } from '@inertiajs/vue3';
import { usePermission } from '@/composables/permissions';
import { Permission } from '@/enums/Permission';
import {
  MoreHorizontal, Pencil, Trash2, ShieldOff, CheckCircle2,
  Clock, EyeOff, Eye, User as UserIcon, BadgeCheck
} from 'lucide-vue-next';
import { trans } from 'laravel-vue-i18n';
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import CondominioDiChiScrive from '@/components/commenti/CondominioDiChiScrive.vue';
import { Tooltip, TooltipContent, TooltipProvider, TooltipTrigger } from '@/components/ui/tooltip';

export interface CommentoAutore {
  id?: number;
  name?: string;
  nome?: string;
  anagrafica?: { nome: string } | null;
}

export interface CommentoAzioni {
  modifica?: boolean;
  elimina?: boolean;
  approva?: boolean;
  nascondi?: boolean;
  mostra?: boolean;
}

export interface CommentoData {
  id: number;
  corpo: string;
  stato: 'pubblicato' | 'in_attesa' | 'nascosto';
  user_id?: number;
  modificato_at?: string | null;
  modificato?: boolean;
  moderato_at?: string | null;
  created_at: string;
  autore: CommentoAutore | null;
  deleted_at?: string | null;
  azioni?: CommentoAzioni;
  // Il condominio di chi scrive, il primo è quello del commento (decisione 458 (b)): solo allo studio, nella discussione unica
  // di una comunicazione a più condomìni. Il portale non lo riceve.
  condomini?: string[];
}

const props = withDefaults(defineProps<{
  commento: CommentoData;
  canModerate?: boolean;
  currentUserId?: number;
  porta?: 'admin' | 'user';
  // Standard 16, la potenza di Inertia: le proprietà che il server rimanda dopo un'azione (una visita parziale, lo stato della pagina
  // resta com'è). Senza, la visita rimanda la pagina intera e la ricostruisce.
  ricarica?: string[];
}>(), {
  canModerate: false,
  currentUserId: 0,
});

const { hasPermission, generateRoute } = usePermission();

const isEditing = ref(false);
const editForm = useForm({ corpo: props.commento.corpo });

const isOwnComment = computed(() =>
  Boolean(props.commento.user_id && props.currentUserId && props.commento.user_id === props.currentUserId)
);

const hasServerActions = computed(() => props.commento.azioni !== undefined);

const canEdit = computed(() => {
  if (hasServerActions.value) {
    return Boolean(props.commento.azioni?.modifica);
  }
  return isOwnComment.value && hasPermission([Permission.EDIT_OWN_COMMENTS_SEGNALAZIONI]);
});

const canDelete = computed(() => {
  if (hasServerActions.value) {
    return Boolean(props.commento.azioni?.elimina);
  }
  return (isOwnComment.value && hasPermission([Permission.DELETE_OWN_COMMENTS_SEGNALAZIONI])) || props.canModerate;
});

const canApprove = computed(() => {
  if (hasServerActions.value) {
    return Boolean(props.commento.azioni?.approva);
  }
  return props.canModerate && props.commento.stato === 'in_attesa';
});

const canHide = computed(() => {
  if (hasServerActions.value) {
    return Boolean(props.commento.azioni?.nascondi);
  }
  return props.canModerate;
});

const canShowAgain = computed(() => Boolean(props.commento.azioni?.mostra));

// Fra le icone (decisione 462): «Approva» sul commento in attesa; «Nascondi» sul pubblicato, e sull'in attesa quando la pagina
// non riceve le azioni dal server e ripiega su `canModerate` (prima era il pulsante rosso sotto il testo).
const mostraApprova = computed(() => canApprove.value && props.commento.stato === 'in_attesa');
const mostraNascondi = computed(() => canHide.value && (props.commento.stato === 'pubblicato' || props.commento.stato === 'in_attesa'));

function getActionRoute(name: string) {
  if (props.porta) {
    return `${props.porta}.${name}`;
  }
  return generateRoute(name);
}

/*
 * ⚠️ **`computed` e non una costante, e le due righe vanno lette insieme.**
 *
 * Le traduzioni dei file PHP arrivano al browser in un pacchetto **caricato in modo asincrono**:
 * una costante di modulo dentro `<script setup>` è valutata una volta sola all'avvio del
 * componente, e può girare prima che quel pacchetto sia arrivato. `trans()` su una chiave non
 * ancora caricata restituisce **la chiave stessa**, quindi il ramo di ripiego congelava a video
 * la stringa tecnica `commenti.autore_sconosciuto` al posto di «Utente sconosciuto».
 *
 * Il secondo guasto era più subdolo del primo: `authorInitials` confrontava il valore
 * **congelato** con una `trans()` **viva**. A traduzioni arrivate il confronto non combaciava
 * più, e l'avatar mostrava «CO» — le prime due lettere di `commenti.autore_sconosciuto` — invece
 * del «?» previsto. Lo stesso succedeva a chiunque cambiasse lingua a pagina aperta.
 *
 * È l'ultimo caso della bonifica della beta.60, che ne aveva chiusi diciannove e non aveva visto
 * questo: la sua guardia ritaglia il valore di una costante fermandosi al primo a capo, e questa
 * sta su tre righe. Il buco è documentato in `TraduzioniNonSiCongelanoTest`.
 */
const authorName = computed(() => props.commento.autore?.nome
  ?? props.commento.autore?.anagrafica?.nome
  ?? props.commento.autore?.name
  ?? trans('commenti.autore_sconosciuto'));

const authorInitials = computed(() => {
  const name = authorName.value.trim();
  if (!name || name === trans('commenti.autore_sconosciuto')) return '?';
  const parts = name.split(/\s+/);
  if (parts.length === 1) return parts[0].substring(0, 2).toUpperCase();
  return (parts[0][0] + parts[parts.length - 1][0]).toUpperCase();
});

const showDeleteConfirm = ref(false);
const isDeleting = ref(false);

const showHideConfirm = ref(false);
const isHiding = ref(false);

// Le opzioni di ogni azione: lo scorrimento resta; con `ricarica` tornano solo quelle proprietà, la pagina tiene il suo stato e la
// barra di Inertia in cima alla pagina non compare: finché la risposta non arriva il commento si attenua (standard 16).
const inCorso = ref(false);

function opzioniVisita(altre: { onSuccess?: () => void; onFinish?: () => void } = {}) {
  const { onFinish, ...resto } = altre;
  return {
    preserveScroll: true,
    ...(props.ricarica ? { preserveState: true, only: props.ricarica, showProgress: false } : {}),
    ...resto,
    onStart: () => { inCorso.value = true; },
    onFinish: () => {
      inCorso.value = false;
      onFinish?.();
    },
  };
}

function submitEdit() {
  editForm.patch(route(getActionRoute('commenti.update'), { commento: props.commento.id }), opzioniVisita({
    onSuccess: () => { isEditing.value = false; },
  }));
}

function deleteComment() {
  showDeleteConfirm.value = true;
}

function confirmDelete() {
  isDeleting.value = true;
  router.delete(route(getActionRoute('commenti.destroy'), { commento: props.commento.id }), opzioniVisita({
    onFinish: () => {
      isDeleting.value = false;
      showDeleteConfirm.value = false;
    },
  }));
}

function approveComment() {
  router.post(route(getActionRoute('commenti.approva'), { commento: props.commento.id }), {}, opzioniVisita());
}

function moderateComment() {
  showHideConfirm.value = true;
}

function confirmModerate() {
  isHiding.value = true;
  router.post(route(getActionRoute('commenti.modera'), { commento: props.commento.id }), {}, opzioniVisita({
    onFinish: () => {
      isHiding.value = false;
      showHideConfirm.value = false;
    },
  }));
}

function showAgainComment() {
  router.post(route(getActionRoute('commenti.mostra'), { commento: props.commento.id }), {}, opzioniVisita());
}

function formatDate(dateStr: string): string {
  return new Date(dateStr).toLocaleDateString('it-IT', {
    day: '2-digit', month: 'short', year: 'numeric',
    hour: '2-digit', minute: '2-digit',
  });
}
</script>

<template>
  <!-- Commento nascosto, per chi lo può rimettere visibile (decisione 457): nome, data e testo in grigio, con «Nascosto» e «Mostra di nuovo» -->
  <div
    v-if="commento.stato === 'nascosto' && !commento.deleted_at && canShowAgain"
    :data-commento="commento.id"
    data-nascosto-leggibile
    :aria-busy="inCorso"
    class="group flex gap-3 transition-opacity"
    :class="{ 'opacity-60': inCorso }"
  >
    <div class="shrink-0 w-8 h-8 rounded-full bg-slate-100 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 flex items-center justify-center text-slate-400 dark:text-slate-500 text-[10px] font-bold">
      {{ authorInitials }}
    </div>
    <div class="flex-1 min-w-0">
      <!-- Il nome, la data e lo stato vanno a capo fra loro; le icone restano in alto a destra e non lasciano una riga vuota (Fase 2 della 1.12.0-beta.11) -->
      <div data-intestazione-commento class="flex items-start gap-2 mb-3">
        <div class="flex flex-1 min-w-0 items-center gap-x-2 gap-y-0.5 flex-wrap">
          <span data-autore-commento class="flex min-w-0 max-w-full items-center gap-1">
            <span class="shrink-0 max-w-full text-sm font-semibold text-slate-500 dark:text-slate-400">{{ authorName }}</span>
            <CondominioDiChiScrive v-if="commento.condomini?.length" :condomini="commento.condomini" />
          </span>
          <span data-data-commento class="text-xs text-slate-400">{{ formatDate(commento.created_at) }}</span>
          <span data-stato-commento class="inline-flex items-center gap-1 px-2 py-0.5 rounded-md text-xs font-semibold bg-slate-100 dark:bg-slate-800 text-slate-600 dark:text-slate-300">
            <EyeOff class="w-3 h-3" />
            {{ trans('commenti.stato_nascosto') }}
          </span>
        </div>
        <!-- «Mostra di nuovo» è un'icona come le altre azioni, senza finestra di conferma (decisione 462) -->
        <TooltipProvider>
          <div data-azioni-commento class="opacity-0 group-hover:opacity-100 focus-within:opacity-100 pointer-coarse:opacity-100 transition-opacity flex shrink-0 items-center gap-1 ml-auto">
            <Tooltip :delay-duration="300">
              <TooltipTrigger as-child>
                <button
                  type="button"
                  data-azione="mostra-di-nuovo"
                  :aria-label="trans('commenti.mostra_di_nuovo')"
                  @click="showAgainComment"
                  class="p-1 rounded text-emerald-600 dark:text-emerald-400 hover:bg-emerald-50 dark:hover:bg-emerald-900/20 transition-colors"
                >
                  <Eye class="w-3.5 h-3.5" />
                </button>
              </TooltipTrigger>
              <TooltipContent side="top" :side-offset="5">
                <p class="text-xs">{{ trans('commenti.mostra_di_nuovo') }}</p>
              </TooltipContent>
            </Tooltip>
            <Tooltip v-if="canDelete" :delay-duration="300">
              <TooltipTrigger as-child>
                <button
                  type="button"
                  :aria-label="trans('commenti.elimina')"
                  @click="deleteComment"
                  class="p-1 rounded text-slate-400 hover:text-red-500 hover:bg-red-50 dark:hover:bg-red-900/20 transition-colors"
                >
                  <Trash2 class="w-3.5 h-3.5" />
                </button>
              </TooltipTrigger>
              <TooltipContent side="top" :side-offset="5">
                <p class="text-xs">{{ trans('commenti.elimina') }}</p>
              </TooltipContent>
            </Tooltip>
          </div>
        </TooltipProvider>
      </div>
      <div class="relative px-4 py-3 rounded-lg border border-dashed border-slate-300 dark:border-slate-600 bg-slate-50 dark:bg-slate-900/30 break-words">
        <!-- La punta della nuvoletta verso il nome (decisione 471): un quadrato ruotato di cui la cornice mostra la metà alta -->
        <span aria-hidden="true" data-codina class="pointer-events-none absolute -top-[8px] left-4 h-[9px] w-5 overflow-hidden">
          <span class="absolute left-[4px] top-[4px] h-3 w-3 rotate-45 border-t border-l border-dashed border-slate-300 dark:border-slate-600 bg-slate-50 dark:bg-slate-900/30"></span>
        </span>
        <p class="text-sm text-slate-400 dark:text-slate-500 whitespace-pre-wrap leading-relaxed">{{ commento.corpo }}</p>
      </div>
    </div>
  </div>

  <!-- Placeholder per commento nascosto o eliminato, per chi non lo può rimettere visibile -->
  <div
    v-else-if="commento.stato === 'nascosto' || commento.deleted_at"
    :data-commento="commento.id"
    class="flex items-center gap-2 px-4 py-3 rounded-lg bg-slate-50 dark:bg-slate-900/30 border border-dashed border-slate-200 dark:border-slate-700 text-sm text-slate-400 italic"
  >
    <EyeOff class="w-4 h-4 shrink-0" />
    <span>{{ trans('commenti.rimosso_da_moderatore') }}</span>
    <span v-if="canModerate" class="ml-auto text-xs text-slate-400 not-italic">
      {{ commento.stato === 'nascosto' ? trans('commenti.stato_nascosto') : trans('commenti.stato_eliminato') }}
    </span>
  </div>

  <!-- Commento in attesa (visibile all'autore senza moderazione) -->
  <div
    v-else-if="commento.stato === 'in_attesa' && !canApprove"
    :data-commento="commento.id"
    :aria-busy="inCorso"
    class="group flex gap-3 relative transition-opacity"
    :class="{ 'opacity-60': inCorso }"
  >
    <!-- Avatar -->
    <div class="shrink-0 w-8 h-8 rounded-full bg-slate-100 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 flex items-center justify-center text-slate-600 dark:text-slate-300 text-[10px] font-bold shadow-sm">
      {{ authorInitials }}
    </div>

    <!-- Corpo -->
    <div class="flex-1 min-w-0">
      <!-- Header commento -->
      <!-- Il nome, la data e lo stato vanno a capo fra loro; le icone restano in alto a destra e non lasciano una riga vuota (Fase 2 della 1.12.0-beta.11) -->
      <div data-intestazione-commento class="flex items-start gap-2 mb-3">
        <div class="flex flex-1 min-w-0 items-center gap-x-2 gap-y-0.5 flex-wrap">
          <span data-autore-commento class="flex min-w-0 max-w-full items-center gap-1">
            <span class="shrink-0 max-w-full text-sm font-semibold text-slate-800 dark:text-slate-100">{{ authorName }}</span>
            <CondominioDiChiScrive v-if="commento.condomini?.length" :condomini="commento.condomini" />
          </span>

          <span data-data-commento class="text-xs text-slate-400">{{ formatDate(commento.created_at) }}</span>

          <!-- Lo stato dopo la data, a 12 px (decisione 462) -->
          <span data-stato-commento class="inline-flex items-center gap-1 px-2 py-0.5 rounded-md text-xs font-semibold bg-amber-100 dark:bg-amber-900/30 text-amber-700 dark:text-amber-400">
            <Clock class="w-3 h-3" />
            {{ trans('commenti.in_attesa') }}
          </span>
        </div>
        <!-- Menu azioni (su hover) -->
        <TooltipProvider v-if="(canEdit || canDelete) && !isEditing">
          <div data-azioni-commento class="opacity-0 group-hover:opacity-100 focus-within:opacity-100 pointer-coarse:opacity-100 transition-opacity flex shrink-0 items-center gap-1 ml-auto">
            <!-- Modifica (autore) -->
            <Tooltip v-if="canEdit" :delay-duration="300">
              <TooltipTrigger as-child>
                <button
                  type="button"
                  @click="isEditing = true"
                  class="p-1 rounded text-slate-400 hover:text-blue-500 hover:bg-blue-50 dark:hover:bg-blue-900/20 transition-colors"
                >
                  <Pencil class="w-3.5 h-3.5" />
                </button>
              </TooltipTrigger>
              <TooltipContent side="top" :side-offset="5">
                <p class="text-xs">{{ trans('commenti.modifica') }}</p>
              </TooltipContent>
            </Tooltip>
            <!-- Elimina (autore) -->
            <Tooltip v-if="canDelete" :delay-duration="300">
              <TooltipTrigger as-child>
                <button
                  type="button"
                  @click="deleteComment"
                  class="p-1 rounded text-slate-400 hover:text-red-500 hover:bg-red-50 dark:hover:bg-red-900/20 transition-colors"
                >
                  <Trash2 class="w-3.5 h-3.5" />
                </button>
              </TooltipTrigger>
              <TooltipContent side="top" :side-offset="5">
                <p class="text-xs">{{ trans('commenti.elimina') }}</p>
              </TooltipContent>
            </Tooltip>
          </div>
        </TooltipProvider>
      </div>

      <!-- Testo commento -->
      <div v-if="!isEditing" class="relative w-full p-3 bg-amber-50 dark:bg-amber-900/10 border border-dashed border-amber-300 dark:border-amber-700/60 rounded-lg">
        <!-- La punta della nuvoletta verso il nome (decisione 471): un quadrato ruotato di cui la cornice mostra la metà alta -->
        <span aria-hidden="true" data-codina class="pointer-events-none absolute -top-[8px] left-4 h-[9px] w-5 overflow-hidden">
          <span class="absolute left-[4px] top-[4px] h-3 w-3 rotate-45 border-t border-l border-dashed border-amber-300 dark:border-amber-700/60 bg-amber-50 dark:bg-amber-900/10"></span>
        </span>

        <div class="flex items-center gap-2 mb-2 text-amber-700 dark:text-amber-400">
          <Clock class="w-4 h-4 shrink-0" />
          <span class="text-xs font-semibold">{{ trans('commenti.in_attesa_approvazione') }}</span>
        </div>
        <p class="text-sm text-slate-700 dark:text-slate-300 whitespace-pre-wrap leading-relaxed">
          {{ commento.corpo }}
        </p>
      </div>

      <!-- Form editing inline -->
      <div v-else class="mt-1 rounded-lg border border-blue-200 dark:border-blue-800/50 bg-white dark:bg-slate-950 overflow-hidden">
        <textarea
          v-model="editForm.corpo"
          rows="3"
          autofocus
          class="w-full resize-none px-3 pt-2 pb-1 text-sm text-slate-800 dark:text-slate-200 bg-transparent border-0 outline-none"
          @keydown.ctrl.enter="submitEdit"
          @keydown.meta.enter="submitEdit"
          @keydown.esc="isEditing = false"
        />
        <div class="flex items-center justify-end gap-2 px-3 py-2 border-t border-slate-100 dark:border-slate-800 bg-slate-50/50 dark:bg-slate-900/30">
          <button
            type="button"
            @click="isEditing = false; editForm.reset()"
            class="px-2.5 py-1 text-xs font-medium text-slate-500 hover:text-slate-700 dark:text-slate-400 rounded transition-colors"
          >
            {{ trans('commenti.cancel') }}
          </button>
          <button
            type="button"
            @click="submitEdit"
            :disabled="editForm.processing || !editForm.corpo.trim()"
            class="px-3 py-1 text-xs font-semibold rounded bg-blue-600 text-white hover:bg-blue-700 disabled:opacity-50 transition-colors"
          >
            {{ trans('commenti.save') }}
          </button>
        </div>
        <p v-if="editForm.errors.corpo" class="px-3 pb-2 text-xs text-red-500">{{ editForm.errors.corpo }}</p>
      </div>
    </div>
  </div>

  <!-- Commento regolare (o in attesa con approvazione) -->
  <div v-else :data-commento="commento.id" :aria-busy="inCorso" class="group relative flex gap-3 transition-opacity" :class="{ 'opacity-60': inCorso }">
    <!-- Avatar -->
    <div class="shrink-0 w-8 h-8 rounded-full bg-slate-100 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 flex items-center justify-center text-slate-600 dark:text-slate-300 text-[10px] font-bold shadow-sm">
      {{ authorInitials }}
    </div>

    <!-- Corpo -->
    <div class="flex-1 min-w-0">
      <!-- Header commento -->
      <!-- Il nome, la data e lo stato vanno a capo fra loro; le icone restano in alto a destra e non lasciano una riga vuota (Fase 2 della 1.12.0-beta.11) -->
      <div data-intestazione-commento class="flex items-start gap-2 mb-3">
        <div class="flex flex-1 min-w-0 items-center gap-x-2 gap-y-0.5 flex-wrap">
          <span data-autore-commento class="flex min-w-0 max-w-full items-center gap-1">
            <span class="shrink-0 max-w-full text-sm font-semibold text-slate-800 dark:text-slate-100">{{ authorName }}</span>
            <CondominioDiChiScrive v-if="commento.condomini?.length" :condomini="commento.condomini" />
          </span>

          <!-- La data e «· modificato» vanno a capo insieme, mai «· modificato» da solo (decisione 472) -->
          <span class="inline-flex items-center gap-2 whitespace-nowrap">
            <span data-data-commento class="text-xs text-slate-400">{{ formatDate(commento.created_at) }}</span>

            <!-- La modifica si dice corta, sulla riga del nome; la data intera nel suggerimento e per chi non vede (decisione 472) -->
            <TooltipProvider v-if="commento.modificato || commento.modificato_at">
              <Tooltip :delay-duration="200">
                <TooltipTrigger as-child>
                  <span
                    data-modificato
                    tabindex="0"
                    :aria-label="trans('commenti.modificato', { date: formatDate(commento.modificato_at ?? commento.created_at) })"
                    class="text-xs text-slate-400 italic whitespace-nowrap cursor-default"
                  >· {{ trans('commenti.modificato_breve') }}</span>
                </TooltipTrigger>
                <TooltipContent side="top" :side-offset="5">
                  <p class="text-xs">{{ trans('commenti.modificato', { date: formatDate(commento.modificato_at ?? commento.created_at) }) }}</p>
                </TooltipContent>
              </Tooltip>
            </TooltipProvider>
          </span>

          <!-- «In attesa» (solo chi può approvare) dopo la data, a 12 px (decisione 462) -->
          <span
            v-if="commento.stato === 'in_attesa' && canApprove"
            data-stato-commento
            class="inline-flex items-center gap-1 px-2 py-0.5 rounded-md text-xs font-semibold bg-amber-100 dark:bg-amber-900/30 text-amber-700 dark:text-amber-400"
          >
            <Clock class="w-3 h-3" />
            {{ trans('commenti.in_attesa') }}
          </span>
        </div>
        <!-- Menu azioni (su hover) -->
        <TooltipProvider v-if="(canEdit || canDelete || mostraApprova || mostraNascondi) && !isEditing">
          <div data-azioni-commento class="opacity-0 group-hover:opacity-100 focus-within:opacity-100 pointer-coarse:opacity-100 transition-opacity flex shrink-0 items-center gap-1 ml-auto">
            <!-- Approva, la prima delle icone, senza finestra di conferma (decisione 462) -->
            <Tooltip v-if="mostraApprova" :delay-duration="300">
              <TooltipTrigger as-child>
                <button
                  type="button"
                  data-azione="approva"
                  :aria-label="trans('commenti.approva')"
                  @click="approveComment"
                  class="p-1 rounded text-emerald-600 dark:text-emerald-400 hover:bg-emerald-50 dark:hover:bg-emerald-900/20 transition-colors"
                >
                  <CheckCircle2 class="w-3.5 h-3.5" />
                </button>
              </TooltipTrigger>
              <TooltipContent side="top" :side-offset="5">
                <p class="text-xs">{{ trans('commenti.approva') }}</p>
              </TooltipContent>
            </Tooltip>
            <!-- Nascondi -->
            <Tooltip v-if="mostraNascondi" :delay-duration="300">
              <TooltipTrigger as-child>
                <button
                  type="button"
                  @click="moderateComment"
                  class="p-1 rounded text-slate-400 hover:text-red-500 hover:bg-red-50 dark:hover:bg-red-900/20 transition-colors"
                >
                  <ShieldOff class="w-3.5 h-3.5" />
                </button>
              </TooltipTrigger>
              <TooltipContent side="top" :side-offset="5">
                <p class="text-xs">{{ trans('commenti.nascondi_commento') }}</p>
              </TooltipContent>
            </Tooltip>
            <!-- Modifica -->
            <Tooltip v-if="canEdit" :delay-duration="300">
              <TooltipTrigger as-child>
                <button
                  type="button"
                  @click="isEditing = true"
                  class="p-1 rounded text-slate-400 hover:text-blue-500 hover:bg-blue-50 dark:hover:bg-blue-900/20 transition-colors"
                >
                  <Pencil class="w-3.5 h-3.5" />
                </button>
              </TooltipTrigger>
              <TooltipContent side="top" :side-offset="5">
                <p class="text-xs">{{ trans('commenti.modifica') }}</p>
              </TooltipContent>
            </Tooltip>
            <!-- Elimina -->
            <Tooltip v-if="canDelete" :delay-duration="300">
              <TooltipTrigger as-child>
                <button
                  type="button"
                  @click="deleteComment"
                  class="p-1 rounded text-slate-400 hover:text-red-500 hover:bg-red-50 dark:hover:bg-red-900/20 transition-colors"
                >
                  <Trash2 class="w-3.5 h-3.5" />
                </button>
              </TooltipTrigger>
              <TooltipContent side="top" :side-offset="5">
                <p class="text-xs">{{ trans('commenti.elimina') }}</p>
              </TooltipContent>
            </Tooltip>
          </div>
        </TooltipProvider>
      </div>

      <!-- Testo commento o form di editing -->
      <div v-if="!isEditing">
        <!-- In attesa: l'ambra dell'etichetta, tratteggiato, come lo vede l'autore; pubblicato: azzurro (decisione 465) -->
        <div
          data-corpo-commento
          class="relative w-full p-3 rounded-lg"
          :class="commento.stato === 'in_attesa'
            ? 'bg-amber-50 dark:bg-amber-900/10 border border-dashed border-amber-300 dark:border-amber-700/60'
            : 'bg-sky-50 dark:bg-sky-900/10 border border-sky-200 dark:border-sky-700/50 shadow-sm'"
        >
          <!-- La punta della nuvoletta verso il nome (decisione 471): un quadrato ruotato di cui la cornice mostra la metà alta -->
          <span aria-hidden="true" data-codina class="pointer-events-none absolute -top-[8px] left-4 h-[9px] w-5 overflow-hidden">
            <span
              class="absolute left-[4px] top-[4px] h-3 w-3 rotate-45 border-t border-l"
              :class="commento.stato === 'in_attesa'
                ? 'border-dashed border-amber-300 dark:border-amber-700/60 bg-amber-50 dark:bg-amber-900/10'
                : 'border-sky-200 dark:border-sky-700/50 bg-sky-50 dark:bg-sky-900/10'"
            ></span>
          </span>
          <p class="text-sm text-slate-700 dark:text-slate-300 whitespace-pre-wrap leading-relaxed">
            {{ commento.corpo }}
          </p>
        </div>
      </div>

      <!-- Form editing inline -->
      <div v-else class="mt-1 rounded-lg border border-blue-200 dark:border-blue-800/50 bg-white dark:bg-slate-950 overflow-hidden">
        <textarea
          v-model="editForm.corpo"
          rows="3"
          autofocus
          class="w-full resize-none px-3 pt-2 pb-1 text-sm text-slate-800 dark:text-slate-200 bg-transparent border-0 outline-none"
          @keydown.ctrl.enter="submitEdit"
          @keydown.meta.enter="submitEdit"
          @keydown.esc="isEditing = false"
        />
        <div class="flex items-center justify-end gap-2 px-3 py-2 border-t border-slate-100 dark:border-slate-800 bg-slate-50/50 dark:bg-slate-900/30">
          <button
            type="button"
            @click="isEditing = false; editForm.reset()"
            class="px-2.5 py-1 text-xs font-medium text-slate-500 hover:text-slate-700 dark:text-slate-400 rounded transition-colors"
          >
            {{ trans('commenti.cancel') }}
          </button>
          <button
            type="button"
            @click="submitEdit"
            :disabled="editForm.processing || !editForm.corpo.trim()"
            class="px-3 py-1 text-xs font-semibold rounded bg-blue-600 text-white hover:bg-blue-700 disabled:opacity-50 transition-colors"
          >
            {{ trans('commenti.save') }}
          </button>
        </div>
        <p v-if="editForm.errors.corpo" class="px-3 pb-2 text-xs text-red-500">{{ editForm.errors.corpo }}</p>
      </div>
    </div>
  </div>

  <!-- Dialog per Eliminare -->
  <ConfirmDialog
    v-if="showDeleteConfirm"
    v-model="showDeleteConfirm"
    title="Elimina commento"
    :description="trans('commenti.conferma_elimina')"
    variant="destructive"
    confirm-text="Elimina"
    cancel-text="Annulla"
    :loading="isDeleting"
    @confirm="confirmDelete"
  />

  <!-- Dialog per Nascondere -->
  <ConfirmDialog
    v-if="showHideConfirm"
    v-model="showHideConfirm"
    title="Nascondi commento"
    :description="trans('commenti.conferma_nascondi')"
    variant="warning"
    confirm-text="Nascondi"
    cancel-text="Annulla"
    :loading="isHiding"
    @confirm="confirmModerate"
  />
</template>
