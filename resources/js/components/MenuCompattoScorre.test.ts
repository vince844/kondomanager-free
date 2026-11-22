// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * Il menu compatto scorre (Coda 733). Fino a 1.280 pixel di larghezza (1.240 nel gestionale) l'intestazione apre il menu a scomparsa da sinistra: le voci,
 * poi «Repository», «Documentazione» e «Impostazioni» in fondo. Il pannello è alto quanto la finestra e non scorreva: a 1.024×600 si fermava ad «Agenda», e
 * le ultime tre voci restavano sotto il bordo dello schermo; su un telefono a 390×664, con un gruppo aperto, ancora prima. Adesso il pannello dei due header
 * scorre, e «Impostazioni» si raggiunge.
 *
 * Che cosa non copre: il disegno del browser (la prova legge le classi, come `GestionaleHeaderMisura.test.ts`); la prova a video è nel rapporto.
 */
import { readFileSync } from 'node:fs';
import { join } from 'node:path';
import { describe, expect, test } from 'vitest';

const leggi = (file: string) => readFileSync(join(__dirname, file), 'utf8');
/** Le classi del pannello a sinistra dell'header. */
const classiDelPannello = (file: string) => (leggi(file).match(/<SheetContent side="left" class="([^"]+)"/)?.[1] ?? '').split(/\s+/);

describe('il menu compatto scorre (Coda 733)', () => {
    test.each(['AppHeader.vue', 'GestionaleHeader.vue'])('%s: il pannello alto quanto la finestra scorre, così «Impostazioni» si raggiunge a 1.024×600', (file) => {
        // Il pannello a sinistra è fermo sui due bordi della finestra e alto quanto lei: senza lo scorrimento, ciò che sta sotto il bordo non si vede.
        expect(leggi('ui/sheet/SheetContent.vue')).toMatch(/side === 'left'\s*&& '[^']*\binset-y-0 left-0 h-full\b/);
        expect(classiDelPannello(file)).toContain('overflow-y-auto');
    });
});
