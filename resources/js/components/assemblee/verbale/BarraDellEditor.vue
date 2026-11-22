<!--
SPDX-License-Identifier: AGPL-3.0-or-later
Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
-->
<script setup lang="ts">
/**
 * La barra dell'editor del verbale (la bozza approvata): grassetto, corsivo, sottolineato, elenco puntato e numerato, titolo, annulla e ripeti; nient'altro.
 * Accanto, la scritta del salvataggio. Al telefono va in due righe: i formati sopra; annulla, ripeti e la scritta sotto.
 */
import type { Editor } from '@tiptap/vue-3';
import { Bold, Heading2, Italic, List, ListOrdered, Redo2, Underline, Undo2 } from 'lucide-vue-next';
import { LIVELLO_DEL_TITOLO } from '@/lib/assemblee/verbale/struttura';

const props = defineProps<{ editor: Editor; scritta?: { testo: string; dettaglio: string } | null }>();

const formati = [
    { nome: 'Grassetto', icona: Bold, attivo: () => props.editor.isActive('bold'), fai: () => props.editor.chain().focus().toggleBold().run() },
    { nome: 'Corsivo', icona: Italic, attivo: () => props.editor.isActive('italic'), fai: () => props.editor.chain().focus().toggleItalic().run() },
    { nome: 'Sottolineato', icona: Underline, attivo: () => props.editor.isActive('underline'), fai: () => props.editor.chain().focus().toggleUnderline().run() },
    { nome: 'Elenco puntato', icona: List, attivo: () => props.editor.isActive('bulletList'), fai: () => props.editor.chain().focus().toggleBulletList().run() },
    { nome: 'Elenco numerato', icona: ListOrdered, attivo: () => props.editor.isActive('orderedList'), fai: () => props.editor.chain().focus().toggleOrderedList().run() },
    { nome: 'Titolo', icona: Heading2, attivo: () => props.editor.isActive('heading'),
        fai: () => props.editor.chain().focus().toggleHeading({ level: LIVELLO_DEL_TITOLO as 2 }).run() },
];
const pulsante = (attivo: boolean) => ['inline-flex h-8 w-8 items-center justify-center rounded-md text-slate-700 transition-colors hover:bg-slate-100 disabled:opacity-40 dark:text-slate-300 dark:hover:bg-slate-800',
    attivo ? 'bg-slate-200 text-slate-900 dark:bg-slate-700 dark:text-white' : ''];
</script>

<template>
    <div class="flex flex-wrap items-center gap-x-1 gap-y-1 border-b border-slate-200 px-1.5 py-1 dark:border-slate-800" role="toolbar" aria-label="I formati del testo"
        data-testid="barra-dell-editor">
        <div class="flex items-center gap-0.5">
            <button v-for="f in formati" :key="f.nome" type="button" :class="pulsante(f.attivo())" :aria-label="f.nome" :title="f.nome" :aria-pressed="f.attivo() ? 'true' : 'false'"
                @click="f.fai()">
                <component :is="f.icona" class="h-4 w-4" aria-hidden="true" />
            </button>
        </div>
        <span class="mx-1 hidden h-5 w-px bg-slate-200 sm:block dark:bg-slate-700" aria-hidden="true" />
        <div class="flex items-center gap-0.5">
            <button type="button" :class="pulsante(false)" aria-label="Annulla" title="Annulla" :disabled="!editor.can().undo()" @click="editor.chain().focus().undo().run()">
                <Undo2 class="h-4 w-4" aria-hidden="true" />
            </button>
            <button type="button" :class="pulsante(false)" aria-label="Ripeti" title="Ripeti" :disabled="!editor.can().redo()" @click="editor.chain().focus().redo().run()">
                <Redo2 class="h-4 w-4" aria-hidden="true" />
            </button>
        </div>
        <p v-if="scritta" class="ml-auto min-w-0 px-1.5 text-xs text-slate-600 dark:text-slate-400" role="status" aria-live="polite" data-testid="scritta-del-salvataggio">
            <b class="font-medium text-slate-800 dark:text-slate-200">{{ scritta.testo }}</b><span v-if="scritta.dettaglio"> · {{ scritta.dettaglio }}</span>
        </p>
        <slot name="stato" />
    </div>
</template>
