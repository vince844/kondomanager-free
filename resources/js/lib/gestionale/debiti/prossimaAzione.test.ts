// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
/**
 * A video si legge «la prossima azione», mai «il passo»: per l'amministratore «il passo» non dice niente. I nomi interni del codice (`PassoMenu`,
 * `passo_proposto`, i casi della regola) restano: qui si guardano solo le frasi che la pagina mostra, nei file del riquadro dei sei mesi, del compito
 * dell'Inbox, delle risposte e della scheda «Impostazioni» del condominio.
 *
 * Le frasi a video: il testo fra i tag dei template (senza le espressioni `{{ }}`) e le stringhe con almeno uno spazio (le frasi, non le chiavi), tolti i
 * commenti.
 */
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { describe, expect, test } from 'vitest';

const FILE = [
    'resources/js/components/gestionale/seiMesi/SeiMesiPerAgireWidget.vue',
    'resources/js/components/gestionale/seiMesi/PassoMenu.vue',
    'resources/js/components/gestionale/seiMesi/SeiMesiCompito.vue',
    'resources/js/components/gestionale/seiMesi/SeiMesiRisposte.vue',
    'resources/js/components/gestionale/seiMesi/SeiMesiFattoDialog.vue',
    'resources/js/lib/gestionale/debiti/seiMesiPerAgire.ts',
    'resources/js/components/inbox/MenuDelCompito.vue',
    'resources/js/components/inbox/RisposteDelCompito.vue',
    'resources/js/lib/inbox/risposteDelCompito.ts',
    'resources/js/pages/gestionale/struttura/ImpostazioniCondominio.vue',
    'resources/js/components/guides/ImpostazioniCondominioGuide.vue',
    'resources/js/components/guides/DispenseSeiMesiGuide.vue',
    'resources/js/components/gestionale/fascicolo/FascicoloLegaleDialog.vue',
    'resources/js/components/gestionale/fascicolo/FascicoliDellaPersona.vue',
    'resources/js/lib/gestionale/debiti/fascicoloLegale.ts',
    'resources/js/components/gestionale/seiMesi/SeiMesiDelloStudio.vue',
    'resources/js/lib/gestionale/debiti/seiMesiDelloStudio.ts',
    // La tesoreria a 30 giorni.
    'resources/js/pages/gestionale/dashboard/components/TreasuryGuardianWidget.vue',
    'resources/js/pages/gestionale/dashboard/components/DaDoveViene.vue',
    'resources/js/pages/gestionale/dashboard/components/SaldoPrevistoCard.vue',
    'resources/js/lib/gestionale/tesoreria/tesoreria.ts',
    'resources/js/lib/gestionale/tesoreria/saldoPrevisto.ts',
    // L'Action inbox.
    'resources/js/pages/dashboard/ActionInbox.vue',
    'resources/js/lib/inbox/actionInbox.ts',
    'resources/js/components/inbox/CardDellInbox.vue',
    'resources/js/components/inbox/RigaDelCompito.vue',
    'resources/js/components/gestionale/seiMesi/SeiMesiColonna.vue',
    'resources/js/components/gestionale/seiMesi/SeiMesiCassetto.vue',
    'resources/js/components/gestionale/seiMesi/RigaDelloStudio.vue',
    'resources/js/components/guides/ActionInboxGuide.vue',
    // Le azioni consigliate della tesoreria, l'Inbox del condominio e la guida della dashboard del condominio.
    'resources/js/pages/gestionale/dashboard/components/AzioniConsigliate.vue',
    'resources/js/lib/gestionale/tesoreria/azioniConsigliate.ts',
    'resources/js/lib/inbox/inboxDelCondominio.ts',
    'resources/js/components/guides/DashboardCondominioGuide.vue',
];

/** Le frasi a video di un sorgente. */
function frasiAVideo(sorgente: string): string[] {
    const senzaCommenti = sorgente
        .replace(/<!--[\s\S]*?-->/g, ' ')
        .replace(/\/\*[\s\S]*?\*\//g, ' ')
        .replace(/(^|[^:'"`])\/\/[^\n]*/g, '$1');
    const frasi: string[] = [];
    // Il testo fra i tag del template.
    const template = senzaCommenti.match(/<template>[\s\S]*<\/template>/)?.[0] ?? '';
    for (const m of template.matchAll(/>([^<>]+)</g)) {
        const t = m[1].replace(/\{\{[\s\S]*?\}\}/g, ' ').trim();
        if (/[A-Za-zÀ-ú]/.test(t)) frasi.push(t);
    }
    // Le stringhe con uno spazio: le frasi del codice e degli attributi.
    for (const m of senzaCommenti.matchAll(/'((?:[^'\\\n]|\\.)*)'|`([^`]*)`|"([^"\n]*)"/g)) {
        const t = (m[1] ?? m[2] ?? m[3] ?? '').replace(/\\'/g, '\'');
        if (/\s/.test(t.trim()) && /[A-Za-zÀ-ú]{3}/.test(t)) frasi.push(t);
    }

    return frasi;
}

describe('a video «la prossima azione», non «il passo»', () => {
    test('la guardia trova una frase con «passo», e non una chiave', () => {
        expect(frasiAVideo('<template><p>Scegli il passo</p></template>').some((f) => /\bpass[oi]\b/i.test(f))).toBe(true);
        expect(frasiAVideo('<script>const x = p.passo; const y = \'passo\';</script><template><p :a="p.passo">{{ p.passo }}</p></template>')
            .some((f) => /\bpass[oi]\b/i.test(f))).toBe(false);
    });

    test.each(FILE)('%s', (file) => {
        const trovate = frasiAVideo(readFileSync(resolve(process.cwd(), file), 'utf8')).filter((f) => /\bpass[oi]\b/i.test(f));
        expect(trovate).toEqual([]);
    });
});
