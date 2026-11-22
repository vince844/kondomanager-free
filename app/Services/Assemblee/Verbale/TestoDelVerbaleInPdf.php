<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Services\Assemblee\Verbale;

/**
 * Il testo del verbale dentro il PDF: **l'unico pezzo di HTML che le viste delle stampe scrivono senza scapparlo** (`{!! !!}`), e per questo lo scrive un
 * componente solo, da una struttura che passa prima da {@see StrutturaDelVerbale::valida()} (una struttura non valida non arriva mai qui: l'eccezione ferma
 * la stampa).
 *
 * - **Ogni testo passa da `e()`**: un `<script>`, un `<b>` o un `&` scritti nel verbale restano testo, nel PDF come nell'editor.
 * - **Solo i tag della barra**, scritti qui e mai presi dal testo: il paragrafo `<p>`, il titolo `<h3>` (un livello solo), gli elenchi `<ul>` e `<ol>` (con il
 *   numero da cui parte, un intero), la voce `<li>`, l'a capo `<br />`, i segni `<b>`, `<i>`, `<u>` nell'ordine della barra. Nessun attributo che venga dal
 *   testo: l'unico è `start`, un numero.
 * - **Le parti «Dati della sala»** si stampano come il resto del testo: la chiave e il testo di prima restano nel programma, il PDF firmato non li mostra.
 *
 * Lo stile è in linea, come le altre stampe di mPDF; il corpo del testo è a 10,5 punti.
 */
final class TestoDelVerbaleInPdf
{
    private const PARAGRAFO = 'margin: 0 0 5px 0; font-size: 10.5pt; line-height: 1.45; text-align: justify;';

    private const TITOLO = 'margin: 10px 0 4px 0; font-size: 11.5pt; font-weight: bold;';

    private const ELENCO = 'margin: 0 0 5px 0; padding-left: 18px; font-size: 10.5pt; line-height: 1.45;';

    private const VOCE = 'margin: 0 0 2px 0;';

    private const SEGNI = ['bold' => 'b', 'italic' => 'i', 'underline' => 'u'];

    /**
     * L'HTML del testo, da mettere nella vista con `{!! !!}`.
     *
     * @throws \Illuminate\Validation\ValidationException se la struttura non è quella dell'editor
     */
    public static function html(mixed $documento): string
    {
        return self::nodo(StrutturaDelVerbale::valida($documento));
    }

    /** @param array<string, mixed> $nodo già valido */
    private static function nodo(array $nodo, bool $inUnaVoce = false): string
    {
        $tipo = (string) $nodo['type'];
        $figli = fn (bool $voce = false) => implode('', array_map(fn ($c) => self::nodo((array) $c, $voce), (array) ($nodo['content'] ?? [])));

        return match ($tipo) {
            'doc', 'datiDellaSala' => $figli(),
            'paragraph' => '<p style="' . ($inUnaVoce ? self::VOCE : self::PARAGRAFO) . '">' . $figli() . '</p>',
            'heading' => '<h3 style="' . self::TITOLO . '">' . $figli() . '</h3>',
            'bulletList' => '<ul style="' . self::ELENCO . '">' . $figli() . '</ul>',
            'orderedList' => '<ol' . (isset($nodo['attrs']['start']) ? ' start="' . (int) $nodo['attrs']['start'] . '"' : '') . ' style="' . self::ELENCO . '">' . $figli() . '</ol>',
            'listItem' => '<li>' . $figli(true) . '</li>',
            'hardBreak' => '<br />',
            'text' => self::testo($nodo),
            default => '',
        };
    }

    /** Un pezzo di testo, scappato, dentro i suoi segni nell'ordine della barra (grassetto fuori, sottolineato dentro). @param array<string, mixed> $nodo */
    private static function testo(array $nodo): string
    {
        $html = e((string) ($nodo['text'] ?? ''));
        $segni = array_map(fn ($m) => (string) ($m['type'] ?? ''), (array) ($nodo['marks'] ?? []));
        foreach (array_reverse(array_values(array_intersect(array_keys(self::SEGNI), $segni))) as $segno) {
            $tag = self::SEGNI[$segno];
            $html = '<' . $tag . '>' . $html . '</' . $tag . '>';
        }

        return $html;
    }
}
