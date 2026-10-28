/**
 * Coda 404 — `@inertiajs/core` è dichiarato, e di copie ce n'è una sola.
 *
 * `lib/clientHttpConConferma.ts` importa `HttpResponseError` e `HttpCancelledError` da `@inertiajs/core` e li usa come
 * valori: Inertia riconosce l'annullamento di una visita con `errore instanceof HttpCancelledError`, quindi l'errore che
 * il client lancia deve essere della stessa copia del pacchetto che usa Inertia. Un pacchetto che arriva solo come
 * dipendenza di `@inertiajs/vue3` può cambiare posto, o duplicarsi in un `node_modules` annidato, senza che nessuno lo
 * veda. Qui si fissa che `package.json` lo dichiara con lo stesso intervallo di `@inertiajs/vue3`, che il file di blocco
 * lo ha nella radice con la versione che `@inertiajs/vue3` richiede, e che non ne esiste una seconda copia annidata.
 */

import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { describe, expect, test } from 'vitest';

interface Pacchetto {
    version?: string;
    dependencies?: Record<string, string>;
}

const leggi = (nome: string) => JSON.parse(readFileSync(fileURLToPath(new URL(`../../../${nome}`, import.meta.url)), 'utf8'));

const manifesto: { dependencies: Record<string, string> } = leggi('package.json');
const blocco: { packages: Record<string, Pacchetto> } = leggi('package-lock.json');

describe('@inertiajs/core', () => {
    test('Coda 404 — package.json lo dichiara fra le dipendenze, con lo stesso intervallo di @inertiajs/vue3', () => {
        expect(manifesto.dependencies['@inertiajs/core']).toBeDefined();
        expect(manifesto.dependencies['@inertiajs/core']).toBe(manifesto.dependencies['@inertiajs/vue3']);
    });

    test('Coda 404 — il file di blocco lo ha fra le dipendenze della radice, con lo stesso intervallo', () => {
        const radice = blocco.packages[''].dependencies ?? {};

        expect(radice['@inertiajs/core']).toBe(manifesto.dependencies['@inertiajs/vue3']);
        expect(Object.keys(radice)).toEqual(Object.keys(radice).slice().sort((a, b) => a.localeCompare(b, 'en')));
    });

    test('Coda 404 — nel file di blocco la copia è una sola, ed è quella che @inertiajs/vue3 richiede', () => {
        const copie = Object.keys(blocco.packages).filter((chiave) => chiave === 'node_modules/@inertiajs/core' || chiave.endsWith('/node_modules/@inertiajs/core'));

        expect(copie).toEqual(['node_modules/@inertiajs/core']);
        expect(blocco.packages['node_modules/@inertiajs/vue3/node_modules/@inertiajs/core']).toBeUndefined();
        expect(blocco.packages['node_modules/@inertiajs/core'].version).toBe(blocco.packages['node_modules/@inertiajs/vue3'].dependencies?.['@inertiajs/core']);
    });
});
