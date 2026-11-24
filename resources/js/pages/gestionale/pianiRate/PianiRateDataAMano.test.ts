// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * 1.12.0-beta.13: chi non gestisce le assemblee approva un piano rate e crea uno straordinario **come prima**, con la data della delibera scritta a mano.
 * Marco, contabile con i piani rate e senza i permessi delle assemblee, preme «Approva» sul «Preventivo 2026»: la finestra gli chiede la data e il numero
 * del verbale, e «Conferma» parte con la data; nella pagina nuova lo «Scudo legale» gli offre «Deliberata il». Chi gestisce le assemblee sceglie la delibera.
 *
 * Il test legge i due file della pagina: il campo della data c'è, è dietro il solo «non gestisce le assemblee», ed è ciò che abilita «Conferma».
 *
 * Che cosa non copre: le due pagine a video (la finestra dell'approvazione si apre dall'interruttore dello stato); il server, che la data la accettava già
 * (lo provano i test del piano rate dalla rotta).
 */

import { readFileSync } from 'node:fs';
import { join } from 'node:path';
import { describe, expect, test } from 'vitest';

const leggi = (nome: string) => readFileSync(join(__dirname, nome), 'utf8');
const compatto = (testo: string) => testo.replace(/\s+/g, ' ');

describe('la data scritta a mano a chi non gestisce le assemblee', () => {
    test('«Approva» del piano: il campo della data e del verbale per chi non gestisce, e «Conferma» parte con la data', () => {
        const pagina = compatto(leggi('PianiRateShow.vue'));
        expect(pagina).toContain('const conLaDelibera = computed(() => deliberaDelPiano.value?.gestisce === true);');
        expect(pagina).toMatch(/<div v-else-if="!conLaDelibera"[^>]*data-testid="approvazione-a-mano">.*?<Input v-model="formApprovazione\.data_delibera_assemblea" type="date"/);
        expect(pagina).toMatch(/data-testid="approvazione-a-mano">.*?<Input v-model="formApprovazione\.numero_verbale"/);
        expect(pagina).toContain('conLaDelibera.value ? !!formApprovazione.value.delibera_id : !!formApprovazione.value.data_delibera_assemblea');
        expect(pagina).toContain(':disabled="!approvazionePronta || isProcessingStatus"');
    });

    test('il piano nuovo: «Deliberata il» a mano per chi non gestisce, «Scegli la delibera» per chi gestisce', () => {
        const pagina = compatto(leggi('PianiRateNew.vue'));
        expect(pagina).toContain('const conLaDelibera = computed(() => gestisceLeAssemblee(permessi))');
        expect(pagina).toMatch(/<div v-if="!conLaDelibera"[^>]*data-testid="deliberata-il-a-mano">.*?<Input id="data_delibera_assemblea" type="date" v-model="form\.data_delibera_assemblea"/);
        expect(pagina).toMatch(/data-testid="deliberata-il-a-mano">.*?<div v-else class="grid[^"]*"> <ScegliLaDelibera/);
    });

    test('il titolo del riquadro non dice «Data della delibera» sopra «Scegli la delibera»: con la scelta è «La delibera dell\'assemblea» (750 C4)', () => {
        const pagina = compatto(leggi('PianiRateNew.vue'));
        expect(pagina).toMatch(/<template v-if="conLaDelibera">La delibera dell'assemblea<\/template><template v-else>Data della delibera dell'assemblea<\/template>/);
    });
});
