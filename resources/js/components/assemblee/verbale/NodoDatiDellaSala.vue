<!--
SPDX-License-Identifier: AGPL-3.0-or-later
Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
-->
<script setup lang="ts">
/**
 * La parte «Dati della sala» dentro l'editor: l'etichetta (che non si scrive) e il testo, che si corregge come il resto. Le cifre che non tornano con i voti
 * registrati le dice l'avviso sopra l'editor, non questa parte; se il testo non è più quello scritto dal programma (`originale`), l'etichetta lo dice:
 * «Dati della sala · cambiata a mano».
 */
import { computed } from 'vue';
import { NodeViewContent, NodeViewWrapper, nodeViewProps } from '@tiptap/vue-3';
import { ETICHETTA_DATI_DELLA_SALA } from '@/lib/assemblee/verbale/editor';
import { ETICHETTA_DELLA_SALA, PARTE_DELLA_SALA } from './stili';

const props = defineProps(nodeViewProps);
const normale = (t: string) => t.replace(/\s+/g, ' ').trim();
/** Il testo della parte non è più quello del programma: lo si vede dall'etichetta. */
const aMano = computed(() => {
    const originale = String(props.node.attrs.originale ?? '');
    // Le frasi separate da un a capo, come il programma scrive l'originale (`StrutturaDelVerbale::testo`): `textContent` le incollava, e ogni parte di più
    // frasi risultava «cambiata a mano» appena generata (rilievo C58).
    const testo = props.node.textBetween(0, props.node.content.size, '\n');
    return originale !== '' && normale(testo) !== normale(originale);
});
</script>

<template>
    <NodeViewWrapper :class="PARTE_DELLA_SALA" data-testid="dati-della-sala" :data-chiave="node.attrs.chiave">
        <span :class="ETICHETTA_DELLA_SALA" contenteditable="false" data-testid="etichetta-della-sala">{{ ETICHETTA_DATI_DELLA_SALA }}{{ aMano ? ' · cambiata a mano' : '' }}</span>
        <NodeViewContent />
    </NodeViewWrapper>
</template>
