<!--
SPDX-License-Identifier: AGPL-3.0-or-later
Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
-->
<script lang="ts">
/**
 * Il testo del verbale in sola lettura (una versione, il testo firmato, la discussione di chi scrive dalla sala): disegnato dalla struttura con le funzioni
 * di Vue, **mai** da una stringa HTML. Passa prima da `valida()`: una struttura che non va bene si mostra come testo semplice, e un nodo che il programma
 * non conosce non si disegna mai. Il testo è sempre testo: un `<script>` scritto nel verbale resta parole.
 */
import { defineComponent, h, type PropType, type VNode, type VNodeChild } from 'vue';
import { ETICHETTA_DATI_DELLA_SALA } from '@/lib/assemblee/verbale/editor';
import { testo as testoSemplice, valida, type NodoDelVerbale } from '@/lib/assemblee/verbale/struttura';
import { ETICHETTA_DELLA_SALA, PARTE_DELLA_SALA, TESTO_DEL_VERBALE } from './stili';

function segni(n: NodoDelVerbale): VNodeChild {
    let v: VNodeChild = n.text ?? '';
    for (const m of [...(n.marks ?? [])].reverse()) {
        v = h(m.type === 'bold' ? 'strong' : m.type === 'italic' ? 'em' : 'u', null, [v]);
    }
    return v;
}

function disegna(n: NodoDelVerbale, i: number): VNodeChild {
    const figli = () => (n.content ?? []).map(disegna);
    switch (n.type) {
        case 'paragraph': return h('p', { key: i }, figli());
        case 'heading': return h('h2', { key: i }, figli());
        case 'bulletList': return h('ul', { key: i }, figli());
        case 'orderedList': return h('ol', { key: i, start: n.attrs?.start ?? undefined }, figli());
        case 'listItem': return h('li', { key: i }, figli());
        case 'hardBreak': return h('br', { key: i });
        case 'text': return segni(n);
        case 'datiDellaSala': return h('div', { key: i, class: PARTE_DELLA_SALA, 'data-testid': 'dati-della-sala', 'data-chiave': String(n.attrs?.chiave ?? '') },
            [h('span', { class: ETICHETTA_DELLA_SALA }, ETICHETTA_DATI_DELLA_SALA), ...figli()]);
        default: return null;
    }
}

export default defineComponent({
    name: 'TestoDelVerbale',
    props: { documento: { type: Object as PropType<NodoDelVerbale | null>, default: null }, vuoto: { type: String, default: 'Ancora niente.' } },
    setup(props) {
        return (): VNode => {
            const esito = props.documento ? valida(props.documento) : null;
            if (!esito) return h('div', { class: TESTO_DEL_VERBALE }, [h('p', { class: 'text-slate-500 dark:text-slate-400' }, props.vuoto)]);
            if (!esito.ok) {
                // Una struttura che non va bene: il solo testo che si riesce a leggere, come testo.
                const t = (() => { try { return testoSemplice(props.documento as NodoDelVerbale); } catch { return ''; } })();
                return h('div', { class: TESTO_DEL_VERBALE, 'data-testid': 'testo-del-verbale' }, [h('p', null, t)]);
            }
            const blocchi = esito.documento.content ?? [];
            return h('div', { class: TESTO_DEL_VERBALE, 'data-testid': 'testo-del-verbale' },
                blocchi.length ? blocchi.map(disegna) : [h('p', { class: 'text-slate-500 dark:text-slate-400' }, props.vuoto)]);
        };
    },
});
</script>
