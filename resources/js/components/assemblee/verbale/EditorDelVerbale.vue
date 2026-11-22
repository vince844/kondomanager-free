<!--
SPDX-License-Identifier: AGPL-3.0-or-later
Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
-->
<script setup lang="ts">
/**
 * L'editor del verbale (decisioni 514 e 515): la barra con i soli formati approvati, il testo, la parte «Dati della sala» segnata. `v-model` è la
 * struttura del testo (`struttura.ts`), mai l'HTML: a ogni cambio esce solo ciò che passa da `valida()`. Un testo che arriva da fuori (il server, una
 * versione ripristinata) entra senza far partire un salvataggio. In sola lettura la barra non c'è e il testo non si cambia: chi legge mentre scrive un altro.
 */
import { onBeforeUnmount, shallowRef, watch } from 'vue';
import { Editor, EditorContent, VueNodeViewRenderer } from '@tiptap/vue-3';
import BarraDellEditor from './BarraDellEditor.vue';
import NodoDatiDellaSala from './NodoDatiDellaSala.vue';
import { TESTO_DEL_VERBALE } from './stili';
import { estensioniDelVerbale, strutturaDellEditor } from '@/lib/assemblee/verbale/editor';
import { canonico, valida, type NodoDelVerbale } from '@/lib/assemblee/verbale/struttura';

const props = withDefaults(defineProps<{
    modelValue: NodoDelVerbale | null;
    soloLettura?: boolean;
    scritta?: { testo: string; dettaglio: string } | null;
    /** Il nome del campo per chi legge lo schermo («La discussione del punto 4»). */
    etichetta?: string;
    /** L'altezza minima del testo: la discussione è più bassa del verbale intero. */
    alto?: boolean;
}>(), { soloLettura: false, scritta: null, etichetta: 'Il testo del verbale', alto: false });
const emit = defineEmits<{ 'update:modelValue': [documento: NodoDelVerbale]; errore: [frase: string] }>();

const vuoto: NodoDelVerbale = { type: 'doc', content: [{ type: 'paragraph' }] };
const editor = shallowRef<Editor>(new Editor({
    extensions: estensioniDelVerbale({ vista: VueNodeViewRenderer(NodoDatiDellaSala as never) }),
    content: (props.modelValue ?? vuoto) as never,
    editable: !props.soloLettura,
    editorProps: { attributes: { 'aria-label': props.etichetta, 'aria-multiline': 'true', role: 'textbox',
        class: `${TESTO_DEL_VERBALE} px-3 py-2 outline-none ${props.alto ? 'min-h-[24rem]' : 'min-h-[7rem]'}` } },
    onUpdate: ({ editor: e }) => {
        const s = strutturaDellEditor(e);
        if (s.ok) emit('update:modelValue', s.documento);
        else emit('errore', s.errore);
    },
}));

// Il testo che arriva da fuori entra solo se è diverso da quello dell'editor, e senza far partire un salvataggio.
watch(() => props.modelValue, (nuovo) => {
    const e = editor.value;
    if (!nuovo) return;
    const adesso = strutturaDellEditor(e);
    const arrivato = valida(nuovo);
    if (!arrivato.ok || (adesso.ok && canonico(adesso.documento) === canonico(arrivato.documento))) return;
    e.commands.setContent(arrivato.documento as never, { emitUpdate: false });
});
watch(() => props.soloLettura, (s) => editor.value.setEditable(!s, false));
onBeforeUnmount(() => editor.value.destroy());

defineExpose({ editor });
</script>

<template>
    <div class="overflow-hidden rounded-lg border border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-950" data-testid="editor-del-verbale">
        <BarraDellEditor v-if="!soloLettura" :editor="editor" :scritta="scritta">
            <template #stato><slot name="stato" /></template>
        </BarraDellEditor>
        <EditorContent :editor="editor" />
    </div>
</template>
