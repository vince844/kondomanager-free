// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * L'editor del verbale (Tiptap, licenza MIT, i testi delle licenze in `resources/licenze/`): **solo i formati della barra** approvata (grassetto, corsivo,
 * sottolineato, elenco puntato e numerato, titolo a un livello, annulla e ripeti), i nodi di base (il documento, il paragrafo, il testo, l'a capo dentro il
 * paragrafo) e la parte «Dati della sala» con la sua chiave. Niente colori, caratteri, immagini, collegamenti, tabelle: lo schema non li conosce, e un testo
 * incollato da Word perde tutto ciò che la barra non ha.
 *
 * Ciò che esce dall'editor passa sempre da `valida()` di `struttura.ts` (le stesse regole del server): si salva la struttura, mai l'HTML.
 */
import { Extension, Node, mergeAttributes, type AnyExtension, type Editor, type NodeViewRenderer } from '@tiptap/core';
import { Plugin } from '@tiptap/pm/state';
import Document from '@tiptap/extension-document';
import Paragraph from '@tiptap/extension-paragraph';
import Text from '@tiptap/extension-text';
import HardBreak from '@tiptap/extension-hard-break';
import Bold from '@tiptap/extension-bold';
import Italic from '@tiptap/extension-italic';
import Underline from '@tiptap/extension-underline';
import Heading from '@tiptap/extension-heading';
import { BulletList, ListItem, OrderedList } from '@tiptap/extension-list';
import { UndoRedo } from '@tiptap/extensions';
import { LIVELLO_DEL_TITOLO, valida, type EsitoDellaStruttura } from './struttura';

/** I nomi delle estensioni dell'editor: la prova dell'editor controlla che siano solo questi. */
export const NOMI_DELLE_ESTENSIONI = ['doc', 'paragraph', 'text', 'hardBreak', 'bold', 'italic', 'underline', 'heading', 'bulletList', 'orderedList', 'listItem',
    'undoRedo', 'datiDellaSala', 'incollaPulito'] as const;

/** L'etichetta della parte «Dati della sala», a video sopra la parte. */
export const ETICHETTA_DATI_DELLA_SALA = 'Dati della sala';

/**
 * La parte «Dati della sala»: le frasi con i numeri della sala (la costituzione, i voti, le dichiarazioni) che il programma scrive, segnate con lo sfondo e
 * l'etichetta. Si corregge come il resto del testo; la chiave dice al programma quale dato della sala racconta, l'originale com'era quando l'ha scritta.
 */
export function nodoDatiDellaSala(vista?: NodeViewRenderer): Node {
    return Node.create({
        name: 'datiDellaSala',
        group: 'block',
        content: '(paragraph | heading | bulletList | orderedList)+',
        defining: true,
        addAttributes() {
            return {
                chiave: { default: '', parseHTML: (el) => el.getAttribute('data-chiave') ?? '', renderHTML: (a) => ({ 'data-chiave': a.chiave }) },
                originale: { default: '', parseHTML: () => '', renderHTML: () => ({}) },
            };
        },
        parseHTML() {
            return [{ tag: 'div[data-dati-della-sala]' }];
        },
        renderHTML({ HTMLAttributes }) {
            return ['div', mergeAttributes(HTMLAttributes, { 'data-dati-della-sala': '', class: 'dati-della-sala' }), 0];
        },
        ...(vista ? { addNodeView: () => vista } : {}),
    });
}

/** L'elenco numerato senza il tipo di numero (le lettere, i numeri romani): il verbale ha solo il numero da cui parte. */
const ElencoNumerato = OrderedList.extend({
    addAttributes() {
        return { start: { default: 1, parseHTML: (el: HTMLElement) => (el.hasAttribute('start') ? Number.parseInt(el.getAttribute('start') ?? '1', 10) || 1 : 1) } };
    },
});

/** Le estensioni dell'editor; `vista` è il disegno della parte «Dati della sala» (il componente Vue con l'etichetta). */
export function estensioniDelVerbale(opzioni: { vista?: NodeViewRenderer } = {}): AnyExtension[] {
    return [
        Document,
        Paragraph,
        Text,
        HardBreak,
        Bold,
        Italic,
        Underline,
        Heading.configure({ levels: [LIVELLO_DEL_TITOLO] }),
        BulletList,
        ElencoNumerato,
        ListItem,
        UndoRedo,
        nodoDatiDellaSala(opzioni.vista),
        IncollaPulito,
    ];
}

/** Il testo incollato passa da `ripulisciIncollato` prima che lo schema lo legga. */
const IncollaPulito = Extension.create({
    name: 'incollaPulito',
    addProseMirrorPlugins() {
        return [new Plugin({ props: { transformPastedHTML: (html: string) => ripulisciIncollato(html) } })];
    },
});

/**
 * Il testo incollato (da Word, da una pagina web): via i commenti e i blocchi condizionali di Word, gli stili, gli script, le immagini, i segni degli
 * elenchi di Word (`mso-list:Ignore`), gli attributi (classi, stili, gestori, collegamenti). Resta il testo con i tag che lo schema conosce; il resto lo
 * toglie lo schema stesso.
 */
export function ripulisciIncollato(html: string): string {
    let h = html
        .replace(/<!--\[if[\s\S]*?<!\[endif\]-->/gi, '')
        .replace(/<!--[\s\S]*?-->/g, '')
        .replace(/<(style|script|xml|head|title|meta|link)\b[\s\S]*?<\/\1>/gi, '')
        .replace(/<(meta|link|img|input|base)\b[^>]*>/gi, '')
        .replace(/<span\b[^>]*mso-list:\s*Ignore[^>]*>[\s\S]*?<\/span>\s*<\/span>|<span\b[^>]*mso-list:\s*Ignore[^>]*>[\s\S]*?<\/span>/gi, '')
        .replace(/<\/?o:[^>]*>/gi, '')
        .replace(/<\/?(font|a)\b[^>]*>/gi, '');
    // Gli attributi: si tengono solo `start` degli elenchi numerati e `data-chiave` delle parti della sala, che lo schema legge.
    h = h.replace(/<([a-z][a-z0-9]*)\b([^>]*)>/gi, (_t, tag: string, attributi: string) => {
        const tenuti = [...attributi.matchAll(/\s(start|data-chiave|data-dati-della-sala)(?:="([^"]*)")?/gi)]
            .map((m) => (m[2] === undefined ? ` ${m[1].toLowerCase()}` : ` ${m[1].toLowerCase()}="${m[2].replace(/[<>"]/g, '')}"`));
        return `<${tag}${tenuti.join('')}>`;
    });
    return h;
}

/** Il testo dell'editor nella forma della struttura, o la frase dell'errore: ciò che si salva è solo questo. */
export function strutturaDellEditor(editor: Editor): EsitoDellaStruttura {
    return valida(editor.getJSON());
}
