<script setup lang="ts">
import { ref } from 'vue';
import { useForm, router } from '@inertiajs/vue3';
import { usePermission } from '@/composables/permissions';
import { Permission } from '@/enums/Permission';
import {
  MoreHorizontal, Pencil, Trash2, ShieldOff, CheckCircle2,
  Clock, EyeOff, User as UserIcon, BadgeCheck
} from 'lucide-vue-next';
import { trans } from 'laravel-vue-i18n';

export interface CommentoAutore {
  id: number;
  name: string;
  anagrafica?: { nome: string } | null;
}

export interface CommentoData {
  id: number;
  corpo: string;
  stato: 'pubblicato' | 'in_attesa' | 'nascosto';
  user_id: number;
  modificato_at: string | null;
  moderato_at: string | null;
  created_at: string;
  autore: CommentoAutore | null;
  deleted_at: string | null;
}

const props = defineProps<{
  commento: CommentoData;
  canModerate: boolean;
  currentUserId: number;
}>();

const { hasPermission, generateRoute } = usePermission();

const isEditing = ref(false);
const editForm = useForm({ corpo: props.commento.corpo });

const isOwnComment = props.commento.user_id === props.currentUserId;
const canEdit = isOwnComment && hasPermission([Permission.EDIT_OWN_COMMENTS_SEGNALAZIONI]);
const canDelete = isOwnComment && hasPermission([Permission.DELETE_OWN_COMMENTS_SEGNALAZIONI]);

const authorName = props.commento.autore?.anagrafica?.nome
  ?? props.commento.autore?.name
  ?? trans('commenti.autore_sconosciuto');

function submitEdit() {
  editForm.patch(route(generateRoute('commenti.update'), { commento: props.commento.id }), {
    preserveScroll: true,
    onSuccess: () => { isEditing.value = false; },
  });
}

function deleteComment() {
  if (!confirm(trans('commenti.conferma_elimina'))) return;
  router.delete(route(generateRoute('commenti.destroy'), { commento: props.commento.id }), {
    preserveScroll: true,
  });
}

function approveComment() {
  router.post(route(generateRoute('commenti.approva'), { commento: props.commento.id }), {}, {
    preserveScroll: true,
  });
}

function moderateComment() {
  if (!confirm(trans('commenti.conferma_nascondi'))) return;
  router.post(route(generateRoute('commenti.modera'), { commento: props.commento.id }), {}, {
    preserveScroll: true,
  });
}

function formatDate(dateStr: string): string {
  return new Date(dateStr).toLocaleDateString('it-IT', {
    day: '2-digit', month: 'short', year: 'numeric',
    hour: '2-digit', minute: '2-digit',
  });
}
</script>

<template>
  <!-- Placeholder per commento nascosto o eliminato (visibile solo ai moderatori) -->
  <div
    v-if="commento.stato === 'nascosto' || commento.deleted_at"
    class="flex items-center gap-2 px-4 py-3 rounded-lg bg-slate-50 dark:bg-slate-900/30 border border-dashed border-slate-200 dark:border-slate-700 text-sm text-slate-400 italic"
  >
    <EyeOff class="w-4 h-4 shrink-0" />
    <span>{{ trans('commenti.rimosso_da_moderatore') }}</span>
    <!-- Solo i moderatori vedono i dettagli -->
    <span v-if="canModerate" class="ml-auto text-xs text-slate-400 not-italic">
      {{ commento.stato === 'nascosto' ? trans('commenti.stato_nascosto') : trans('commenti.stato_eliminato') }}
    </span>
  </div>

  <!-- Commento in attesa (visibile solo ai moderatori) -->
  <div
    v-else-if="commento.stato === 'in_attesa' && !canModerate"
    class="flex items-center gap-2 px-4 py-3 rounded-lg bg-amber-50 dark:bg-amber-900/10 border border-dashed border-amber-200 dark:border-amber-800/50 text-sm text-amber-600 dark:text-amber-400"
  >
    <Clock class="w-4 h-4 shrink-0" />
    <span>{{ trans('commenti.in_attesa_approvazione') }}</span>
  </div>

  <!-- Commento regolare -->
  <div v-else class="group relative flex gap-3">
    <!-- Avatar -->
    <div class="shrink-0 w-8 h-8 rounded-full bg-gradient-to-br from-blue-500 to-indigo-600 flex items-center justify-center text-white text-xs font-bold shadow-sm">
      {{ authorName.charAt(0).toUpperCase() }}
    </div>

    <!-- Corpo -->
    <div class="flex-1 min-w-0">
      <!-- Header commento -->
      <div class="flex items-center gap-2 flex-wrap mb-1.5">
        <span class="text-sm font-semibold text-slate-800 dark:text-slate-100">{{ authorName }}</span>

        <!-- Badge in attesa (solo moderatori) -->
        <span
          v-if="commento.stato === 'in_attesa' && canModerate"
          class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[10px] font-semibold bg-amber-100 dark:bg-amber-900/30 text-amber-700 dark:text-amber-400"
        >
          <Clock class="w-3 h-3" />
          {{ trans('commenti.in_attesa') }}
        </span>

        <span class="text-xs text-slate-400">{{ formatDate(commento.created_at) }}</span>

        <span v-if="commento.modificato_at" class="text-xs text-slate-400 italic">
          · {{ trans('commenti.modificato') }}
        </span>
      </div>

      <!-- Testo commento o form di editing -->
      <div v-if="!isEditing">
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

      <!-- Azioni moderazione (in attesa, visibile ai moderatori) -->
      <div
        v-if="commento.stato === 'in_attesa' && canModerate"
        class="flex items-center gap-2 mt-2"
      >
        <button
          @click="approveComment"
          class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded text-xs font-semibold bg-emerald-50 dark:bg-emerald-900/20 text-emerald-700 dark:text-emerald-400 hover:bg-emerald-100 dark:hover:bg-emerald-900/40 border border-emerald-200 dark:border-emerald-800/50 transition-colors"
        >
          <CheckCircle2 class="w-3.5 h-3.5" />
          {{ trans('commenti.approva') }}
        </button>
        <button
          @click="moderateComment"
          class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded text-xs font-semibold bg-red-50 dark:bg-red-900/20 text-red-600 dark:text-red-400 hover:bg-red-100 dark:hover:bg-red-900/40 border border-red-200 dark:border-red-800/50 transition-colors"
        >
          <ShieldOff class="w-3.5 h-3.5" />
          {{ trans('commenti.nascondi') }}
        </button>
      </div>
    </div>

    <!-- Menu azioni (su hover) -->
    <div
      v-if="(canEdit || canDelete || canModerate) && !isEditing"
      class="opacity-0 group-hover:opacity-100 transition-opacity flex items-center gap-1 shrink-0 self-start mt-0.5"
    >
      <!-- Modera (per commenti pubblicati) -->
      <button
        v-if="canModerate && commento.stato === 'pubblicato'"
        @click="moderateComment"
        title="Nascondi commento"
        class="p-1 rounded text-slate-400 hover:text-red-500 hover:bg-red-50 dark:hover:bg-red-900/20 transition-colors"
      >
        <ShieldOff class="w-3.5 h-3.5" />
      </button>

      <!-- Modifica (autore) -->
      <button
        v-if="canEdit"
        @click="isEditing = true"
        title="Modifica"
        class="p-1 rounded text-slate-400 hover:text-blue-500 hover:bg-blue-50 dark:hover:bg-blue-900/20 transition-colors"
      >
        <Pencil class="w-3.5 h-3.5" />
      </button>

      <!-- Elimina (autore) -->
      <button
        v-if="canDelete"
        @click="deleteComment"
        title="Elimina"
        class="p-1 rounded text-slate-400 hover:text-red-500 hover:bg-red-50 dark:hover:bg-red-900/20 transition-colors"
      >
        <Trash2 class="w-3.5 h-3.5" />
      </button>
    </div>
  </div>
</template>
