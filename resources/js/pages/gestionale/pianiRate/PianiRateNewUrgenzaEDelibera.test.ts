// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * Il nuovo piano con la tappa 5 delle assemblee, letto nei file della pagina (come `PianiRateDataAMano.test.ts`):
 *
 * - **«Crea il piano straordinario»**: la pagina riceve `deliberaProposta` dal server e la sceglie con la prima gestione; in testa «Preparato dalla delibera
 *   del …, punto … Niente è salvato finché non premi «Salva il piano».»;
 * - **Coda 688**: sulla gestione straordinaria il riquadro «La delibera, o l'urgenza» (`LaDeliberaOLUrgenza`), uguale sulle due schede; lo «Scudo Legale»
 *   resta per le fatture fuori preventivo di una gestione ordinaria; «Lavori ordinati il» di serie oggi, e il giorno viaggia con «Urgenza» su tutte e due
 *   le schede;
 * - **la fattura registrata dopo** sulla voce di un piano d'urgenza propone lo stesso giorno, e il pannello della competenza lo chiama «Lavori ordinati il …».
 *
 * Che cosa non copre: le pagine a video; il server (`CreaIlPianoStraordinarioTest`, `UrgenzaSulleVociTest`).
 */
import { readFileSync } from 'node:fs';
import { join } from 'node:path';
import { describe, expect, test } from 'vitest';

const compatto = (testo: string) => testo.replace(/\s+/g, ' ');
const leggi = (percorso: string) => compatto(readFileSync(join(__dirname, percorso), 'utf8'));

describe('il nuovo piano dalla delibera e con l\'urgenza', () => {
    test('«Crea il piano straordinario»: la delibera dell\'indirizzo si sceglie con la prima gestione, e la testa lo dice', () => {
        const pagina = leggi('PianiRateNew.vue');
        expect(pagina).toContain('deliberaProposta?: DeliberaDelMenu | null');
        expect(pagina).toContain('let deliberaDallIndirizzo: DeliberaDelMenu | null = props.deliberaProposta ?? null;');
        expect(pagina).toContain('if (deliberaDallIndirizzo !== null && form.gestione_id) { delibera.value = deliberaDallIndirizzo;');
        expect(pagina).toContain('Niente è salvato finché non premi «Salva il piano».');
        expect(pagina).toContain('data-testid="preparato-dalla-delibera"');
    });

    test('il piano che si apre «Preparato dalla delibera» ha già il nome, preso dalla gestione (M6)', () => {
        const pagina = leggi('PianiRateNew.vue');
        expect(pagina).toContain('form.nome = nomeDelPianoPreparato(form.nome, gestioneScelta.value?.nome); deliberaDallIndirizzo = null; return;');
    });

    test('Coda 688: «La delibera, o l\'urgenza» sulla straordinaria, lo Scudo Legale solo sull\'ordinaria, il giorno di serie oggi e con l\'urgenza', () => {
        const pagina = leggi('PianiRateNew.vue');
        expect(pagina).toMatch(/<LaDeliberaOLUrgenza v-if="gestioneStraordinaria" v-model:tipo="form\.tipo_autorizzazione" v-model:motivo="form\.motivazione_autorizzazione"/);
        expect(pagina).toContain(':scheda="schedaDelPiano"');
        // Coda 743: lo Scudo Legale anche con il solo debito ereditato da ripianare nel piano.
        expect(pagina).toContain('<div v-if="(fattureStraordinarie.length > 0 || ripianamentoSiVede) && !gestioneStraordinaria"');
        expect(pagina).toContain('<Card v-else-if="mostraDataDelibera"');
        expect(pagina).toContain('giorno_dei_lavori: oggiLocale() as string | null,');
        expect(pagina).toContain('form.transform((dati) => ({ ...dati, ...campiDellUrgenza(form.tipo_autorizzazione, schedaDelPiano.value, form.giorno_dei_lavori ?? \'\') }))');
        expect(pagina).toContain('if (straordinaria && form.tipo_autorizzazione === \'\') form.tipo_autorizzazione = \'delibera\';');
    });

    test('la fattura registrata dopo sulla voce di un piano d\'urgenza propone lo stesso giorno, e il pannello lo chiama «Lavori ordinati il …»', () => {
        const pagina = leggi('../movimenti/fatture/FatturaRegisterNew.vue');
        expect(pagina).toContain('const urgenzaDelleVoci = computed(() => lavoriUrgentiDelleVoci((form.righe as { conto_id?: number | null }[]).map((r) => competenzaPianoDi(r.conto_id ?? null))));');
        expect(pagina).toContain('if (u && !form.competenza_dal && !form.competenza_al && giornoProposto.value === null) {');
        expect(pagina).toContain(':lavori-urgenti="urgenzaDelleVoci"');
        // La frase sotto il pannello non c'è più: lo dice il pannello, con le parole del piano.
        expect(pagina).not.toContain('data-testid="giorno-dal-piano-d-urgenza"');
    });

    test('anche riaperta: la pagina della fattura in modifica dà al pannello il giorno del piano d\'urgenza della voce', () => {
        const pagina = leggi('../movimenti/fatture/FatturaRegisterEdit.vue');
        expect(pagina).toContain('competenza_piano?: { piano_rate_id: number; piano: string; tratti: { dal: string; al: string }[]; urgenza?: boolean } | null');
        expect(pagina).toContain('const urgenzaDelleVoci = computed(() => lavoriUrgentiDelleVoci((form.righe as { conto_id?: number | null }[]).map((r) => competenzaPianoDi(r.conto_id ?? null))));');
        expect(pagina).toContain(':lavori-urgenti="urgenzaDelleVoci"');
    });

    test('la finestra «Approva» di un piano d\'urgenza con il giorno dice il giorno, non più «dichiarata sulle fatture»', () => {
        const pagina = leggi('PianiRateShow.vue');
        expect(pagina).toContain('<template v-if="isUrgenza && props.urgenza_del_piano?.giorno">Intervento d\'urgenza (art. 1135 co. 2 c.c.): verbale e note sono facoltativi, '
            + 'la competenza è il giorno dei lavori del piano, il {{ props.urgenza_del_piano.giorno }}; la fattura con il suo periodo tiene il suo.</template>');
    });

    test('il giorno dei lavori ha il suo posto nel riquadro su tutte e due le schede: l\'errore sta sotto il campo, non fra quelli del carrello', () => {
        const pagina = leggi('PianiRateNew.vue');
        expect(pagina).toContain('if (campo === \'giorno_dei_lavori\') return gestioneStraordinaria.value && form.tipo_autorizzazione === \'urgenza\';');
        expect(pagina).toContain('giornoDeiLavori: gestioneStraordinaria.value && form.tipo_autorizzazione === \'urgenza\' ? form.giorno_dei_lavori : null');
    });
});

describe('niente testo sotto i 12 px nella riga della delibera', () => {
    test('«obbligatoria» e «(facoltativa)» della riga «La delibera dell\'assemblea» sono text-xs', () => {
        const riga = leggi('PianiRateNew.vue').split('Data della delibera dell\'assemblea</template>')[1]?.split('(facoltativa)')[0] ?? '';
        expect(riga).toContain('obbligatoria');
        expect(riga).not.toContain('text-[10px]');
        expect(leggi('PianiRateNew.vue')).toMatch(/text-xs[^>]*>\(facoltativa\)/);
    });
});
