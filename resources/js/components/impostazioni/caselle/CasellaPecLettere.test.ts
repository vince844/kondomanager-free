// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * 1.12.0-beta.13 (decisione 588): la casella PEC dello studio serve anche alle lettere a chi è in ritardo che l'amministratore sceglie di mandare per PEC
 * (sollecito, diffida, messa in mora). Le parole della casella non dicono più «più avanti», e la guida della posta lo spiega.
 */
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { describe, expect, test } from 'vitest';

const sorgente = (file: string) => readFileSync(resolve(process.cwd(), file), 'utf8').replace(/\s+/g, ' ');

describe('la casella PEC serve anche alle lettere', () => {
    test('la riga, il riquadro «A che cosa serve», il mittente e il salvataggio dicono le lettere; mai «più avanti»', () => {
        const t = sorgente('resources/js/components/impostazioni/caselle/CasellaPecDelloStudio.vue');
        expect(t).not.toContain('Più avanti');
        expect(t).not.toContain('più avanti');
        expect(t).toContain('le convocazioni delle assemblee e le lettere a chi è in ritardo che scegli di mandare per PEC (sollecito, diffida, messa in mora).');
        expect(t).toContain('Da qui partono le convocazioni per PEC, con «Manda le PEC» nel passo «Convocazione» dell\'assemblea, e le lettere a chi è in ritardo, '
            + 'con «Manda N PEC» nella finestra delle lettere.');
        expect(t).toContain('È il mittente delle convocazioni e delle lettere per PEC.');
        expect(t).toContain('Le convocazioni e le lettere per PEC usano questa casella da quando salvi.');
    });

    test('la guida della posta: la casella serve anche alle lettere, e la ricevuta si trascina anche sulla lettera', () => {
        const t = sorgente('resources/js/components/guides/MailSetupGuide.vue');
        expect(t).toContain('da cui partono le convocazioni e le lettere a chi è in ritardo che mandi per PEC');
        expect(t).toContain('Per ogni convocazione e per ogni lettera salvi la ricevuta di consegna e la trascini sulla sua riga');
    });
});
