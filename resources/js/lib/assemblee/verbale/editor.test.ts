// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
// @vitest-environment jsdom

/**
 * L'editor del verbale: solo i formati della barra (grassetto, corsivo, sottolineato, elenco puntato e numerato, titolo a un livello, annulla e ripeti),
 * la parte «Dati della sala», il testo incollato da Word ripulito, e ciò che esce dall'editor sempre nella forma della struttura (`struttura.ts`).
 */
import { afterEach, describe, expect, test } from 'vitest';
import { Editor } from '@tiptap/core';
import { estensioniDelVerbale, NOMI_DELLE_ESTENSIONI, ripulisciIncollato, strutturaDellEditor } from './editor';
import { datiDellaSala, documento, paragrafo, testo, valida } from './struttura';

// jsdom non ha l'evento degli appunti: ProseMirror lo crea quando incolla.
(globalThis as unknown as { ClipboardEvent: unknown }).ClipboardEvent ??= class extends Event { clipboardData = null; };

let editor: Editor | null = null;
afterEach(() => { editor?.destroy(); editor = null; });

function nuovo(contenuto: unknown = documento([paragrafo('')])) {
    editor = new Editor({ extensions: estensioniDelVerbale(), content: contenuto as never });
    return editor;
}

describe('l\'editor del verbale ha solo i formati della barra', () => {
    test('le estensioni sono quelle della barra, più i nodi di base e la parte «Dati della sala»', () => {
        const e = nuovo();
        const nomi = estensioniDelVerbale().map((x) => x.name).sort();
        expect(nomi).toEqual([...NOMI_DELLE_ESTENSIONI].sort());
        expect(Object.keys(e.schema.nodes).sort()).toEqual(['bulletList', 'datiDellaSala', 'doc', 'hardBreak', 'heading', 'listItem', 'orderedList', 'paragraph', 'text']);
        for (const vietato of ['link', 'image', 'textStyle', 'color', 'highlight', 'table', 'codeBlock', 'blockquote', 'strike', 'code', 'horizontalRule']) {
            expect(nomi, vietato).not.toContain(vietato);
        }
        expect(Object.keys(e.schema.marks).sort()).toEqual(['bold', 'italic', 'underline']);
    });

    test('il titolo ha un livello solo, e ciò che l\'editor scrive passa dalla struttura', () => {
        const e = nuovo();
        e.chain().focus().insertContent('Il portone').toggleHeading({ level: 2 }).run();
        e.commands.toggleHeading({ level: 3 } as never);
        const s = strutturaDellEditor(e);
        expect(s.ok).toBe(true);
        expect(JSON.stringify(s.ok && s.documento)).toContain('"level":2');
        expect(JSON.stringify(s.ok && s.documento)).not.toContain('"level":3');
    });

    test('grassetto, corsivo, sottolineato, gli elenchi e l\'a capo escono nella forma della struttura', () => {
        const e = nuovo();
        e.chain().focus().insertContent('Uno').toggleBold().insertContent(' due').toggleItalic().insertContent(' tre').run();
        e.chain().focus().toggleUnderline().insertContent(' quattro').run();
        e.chain().focus().setHardBreak().insertContent('cinque').run();
        e.commands.insertContentAt(e.state.doc.content.size, '<ul><li><p>voce</p></li></ul><ol><li><p>numero</p></li></ol>');
        const s = strutturaDellEditor(e);
        expect(s.ok, s.ok ? '' : s.errore).toBe(true);
        const json = JSON.stringify(s.ok && s.documento);
        for (const parte of ['"bold"', '"italic"', '"underline"', '"hardBreak"', '"bulletList"', '"orderedList"']) expect(json, parte).toContain(parte);
    });
});

describe('la parte «Dati della sala»', () => {
    test('si legge e si riscrive con la chiave e l\'originale, e resta modificabile', () => {
        const blocco = datiDellaSala('voto:12', ['Favorevoli: 9 condòmini per 506,40 millesimi.']);
        const e = nuovo(documento([paragrafo('Prima'), blocco, paragrafo('Dopo')]));
        const s = strutturaDellEditor(e);
        expect(s.ok).toBe(true);
        const doc = s.ok ? s.documento : null;
        expect(doc?.content?.[1]).toEqual(blocco);
        expect(testo(doc!)).toContain('506,40');
    });
});

describe('il testo incollato da Word si ripulisce', () => {
    const WORD = `<html xmlns:o="urn:schemas-microsoft-com:office:office"><head><style>p.MsoNormal{color:red}</style></head><body>
<!--[if gte mso 9]><xml><o:OfficeDocumentSettings></o:OfficeDocumentSettings></xml><![endif]-->
<p class="MsoNormal" style="font-family:'Comic Sans MS';color:#FF0000;font-size:22pt"><b>L'amministratore</b> illustra <span style="background:yellow">i preventivi</span><o:p></o:p></p>
<p class="MsoListParagraph" style="mso-list:l0 level1 lfo1"><span style="mso-list:Ignore">1.<span style="font:7.0pt 'Times New Roman'">&nbsp;&nbsp;</span></span>Serramenti Marino</p>
<p><img src="https://esempio.invalid/logo.png" onerror="alert(1)"><a href="javascript:alert(1)" onclick="alert(2)">Porte Sicure</a></p>
<h1 style="color:blue">Un titolo grande</h1><script>alert(3)</script>
<table><tr><td>Fabbri Riuniti</td></tr></table></body></html>`;

    test('ripulisciIncollato toglie i commenti di Word, gli stili, le classi, le immagini, gli script e i segni degli elenchi di Word', () => {
        const pulito = ripulisciIncollato(WORD);
        for (const via of ['<!--', 'mso-', 'class=', 'style=', '<img', '<script', 'onerror', 'onclick', 'javascript:', '<o:p', '<style', 'Comic Sans']) {
            expect(pulito, via).not.toContain(via);
        }
        expect(pulito).toContain('Serramenti Marino');
        expect(pulito).not.toMatch(/>\s*1\.\s*</);
    });

    test('incollato nell\'editor resta solo il testo con i formati della barra: niente colori, caratteri, immagini o collegamenti', () => {
        const e = nuovo();
        e.view.pasteHTML(WORD);
        const s = strutturaDellEditor(e);
        expect(s.ok, s.ok ? '' : s.errore).toBe(true);
        const doc = s.ok ? s.documento : null;
        const json = JSON.stringify(doc);
        const parole = testo(doc!);
        for (const parola of ['L\'amministratore', 'i preventivi', 'Serramenti Marino', 'Porte Sicure', 'Un titolo grande', 'Fabbri Riuniti']) {
            expect(parole, parola).toContain(parola);
        }
        for (const via of ['color', 'font', 'image', 'link', 'href', 'alert', 'level":1', 'MsoNormal']) expect(json, via).not.toContain(via);
        expect(json).toContain('"bold"');
        expect(valida(JSON.parse(json)).ok).toBe(true);
    });

    test('un elenco numerato incollato con un tipo di numero (a, i) diventa un elenco numerato semplice', () => {
        const e = nuovo();
        e.view.pasteHTML('<ol type="a" start="3" style="list-style-type: lower-roman"><li><p>Primo</p></li></ol>');
        const s = strutturaDellEditor(e);
        expect(s.ok, s.ok ? '' : s.errore).toBe(true);
        expect(JSON.stringify(s.ok && s.documento)).toContain('"start":3');
        expect(JSON.stringify(s.ok && s.documento)).not.toContain('"type":"a"');
    });
});
