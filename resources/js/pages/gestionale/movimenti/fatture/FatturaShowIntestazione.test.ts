// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * L'intestazione della pagina di una fattura: «Guida» con il suo cassetto, come le altre pagine, e una fila sola di pulsanti alti uguali (il condominio,
 * «Guida», «Storia», «Torna all'elenco», «Dashboard social»), con il nome di un condominio lungo accorciato. Prima «Guida» non c'era e «Torna all'elenco»
 * era un pulsante più alto degli altri, dentro gli altri pulsanti: la fila andava a capo.
 *
 * Che cosa non copre: la larghezza vera della fila (la prova a video).
 */
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { describe, expect, test } from 'vitest';

const pagina = readFileSync(resolve(process.cwd(), 'resources/js/pages/gestionale/movimenti/fatture/FatturaShow.vue'), 'utf8');
const intestazione = pagina.slice(pagina.indexOf('<PageHeaderGuide'), pagina.indexOf('</PageHeaderGuide>'));

describe('l\'intestazione della fattura', () => {
    test('«Guida» apre il cassetto della guida delle fatture', () => {
        expect(intestazione).toContain('has-text-guide');
        expect(intestazione).toContain('@open-text-guide="mostraGuida = true"');
        expect(pagina).toContain('<FattureGuide v-model:open="mostraGuida"');
    });

    test('una fila sola: «Torna all\'elenco» è il ritorno dell\'intestazione, alto come gli altri, non un pulsante a parte', () => {
        expect(intestazione).toContain('una-fila');
        expect(intestazione).toContain(':back-url=');
        expect(intestazione).toContain('back-text="Torna all\'elenco"');
        expect(intestazione).not.toContain('<Button');
    });

    test('i pulsanti della ratifica sono piccoli, alti come quelli dell\'intestazione', () => {
        for (const id of ['collega-la-delibera', 'paga-per-urgenza', 'ratifica']) {
            const riga = pagina.split('\n').find((r) => r.includes(`data-testid="${id}"`))!;
            expect(riga).toContain('h-8');
            expect(riga).toContain('text-xs');
            expect(riga).not.toContain('h-9');
            // Bianchi tutti e tre: «Ratifica» era nero, l'unico pieno della fila.
            expect(riga).toContain('variant="outline"');
        }
        expect(pagina).toContain('<PulsanteDaPortareInAssemblea :condominio-id="condominio.id" origine-tipo="fattura_passiva" :origine-id="fattura.id" forma="riga" />');
    });

    test('il percorso è corto, come nelle altre pagine: niente nome del condominio (c\'è il pulsante accanto) e «Fattura …», non «Dettaglio Fattura …»', () => {
        const percorso = pagina.slice(pagina.indexOf('const breadcrumbs'), pagina.indexOf(']);', pagina.indexOf('const breadcrumbs')));
        expect(percorso).not.toContain('props.condominio.nome');
        expect(percorso).not.toContain("href: '#'");
        expect(percorso).toContain("title: 'Fatture e uscite'");
        expect(percorso).toContain('title: `Fattura ${props.fattura.numero_documento}`');
    });
});
