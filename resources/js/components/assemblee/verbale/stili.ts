// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * Le forme del testo del verbale, le stesse nell'editor e nel testo in sola lettura: i paragrafi, il titolo, gli elenchi, la parte «Dati della sala» con lo
 * sfondo e l'etichetta (mai il bordo spesso a sinistra), il tema scuro. Niente sotto i 12 pixel.
 */
export const TESTO_DEL_VERBALE = [
    'text-sm leading-relaxed text-slate-800 dark:text-slate-200 break-words',
    '[&_p]:my-1.5 [&_h2]:mt-3 [&_h2]:mb-1 [&_h2]:text-[15px] [&_h2]:font-semibold [&_h2]:text-slate-900 dark:[&_h2]:text-white',
    '[&_ul]:my-1.5 [&_ul]:list-disc [&_ul]:pl-5 [&_ol]:my-1.5 [&_ol]:list-decimal [&_ol]:pl-5 [&_li>p]:my-0.5',
].join(' ');

/** La parte «Dati della sala»: lo sfondo azzurro chiaro e l'etichetta in testa. */
export const PARTE_DELLA_SALA = 'my-2 rounded-lg border border-sky-100 bg-sky-50/80 px-3 py-2 dark:border-sky-900/60 dark:bg-sky-950/30';
export const ETICHETTA_DELLA_SALA = 'mb-1 inline-flex select-none items-center rounded-md border border-sky-200 bg-white px-1.5 py-px text-xs font-medium text-sky-800 dark:border-sky-800 dark:bg-sky-950 dark:text-sky-300';
