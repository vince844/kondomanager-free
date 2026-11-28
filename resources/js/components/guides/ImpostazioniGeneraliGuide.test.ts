// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
// @vitest-environment jsdom

/**
 * La guida della pagina delle impostazioni generali, nel cassetto di lato.
 *
 * - Aperta mostra le sue parti; chiusa non c'è.
 * - Spiega tutte le impostazioni che la pagina manda al salvataggio, e la cancellazione delle aperture.
 * - Dice ciò che il programma fa: «Apri condominio al login» vale solo per chi salva, la lingua è una per tutti, la cancellazione delle aperture agisce subito.
 * - Ogni frase porta, accanto nel sorgente, la riga di codice che la prova (`<!-- prova: file:riga «testo» -->`): la riga c'è e contiene quel testo.
 * - Ogni nome fra virgolette basse che la guida cita c'è davvero nei sorgenti.
 */
import { readFileSync, existsSync } from 'node:fs';
import { resolve } from 'node:path';
import { afterEach, describe, expect, test } from 'vitest';
import { flushPromises, mount, type VueWrapper } from '@vue/test-utils';

(globalThis as unknown as { ResizeObserver: unknown }).ResizeObserver = class { observe() {} unobserve() {} disconnect() {} };

const Guida = (await import('./ImpostazioniGeneraliGuide.vue')).default;

const leggi = (file: string) => readFileSync(resolve(process.cwd(), file), 'utf8');
const sorgenteGuida = leggi('resources/js/components/guides/ImpostazioniGeneraliGuide.vue');
const pagina = leggi('resources/js/pages/impostazioni/impostazioniGenerali.vue');
const sorgenti = [
    'resources/js/pages/impostazioni/impostazioniGenerali.vue',
    'lang/it/impostazioni.php',
    'lang/it/errors.php',
    'app/Support/Segnalazioni/AssegnazioneSegnalazioni.php',
    'resources/js/components/registroModifiche/StoriaDellaScheda.vue',
    'resources/js/components/GestionaleHeader.vue',
    'resources/js/layouts/gestionale/StrutturaLayout.vue',
].map(leggi).join('\n').replace(/\\'/g, '\'').replace(/\s+/g, ' ');

let w: VueWrapper | null = null;
afterEach(() => { w?.unmount(); w = null; document.body.innerHTML = ''; });

async function apri() {
    w = mount(Guida, { attachTo: document.body, props: { open: true } });
    await flushPromises();

    return (document.body.textContent ?? '').replace(/\s+/g, ' ');
}

/** Ogni campo che la pagina manda con «Salva impostazioni», e le parole della guida che lo spiegano. */
const CAMPI: Record<string, string> = {
    user_frontend_registration: 'Registrazione utenti',
    language: 'Lingua',
    app_name: 'Nome',
    open_condominio_on_login: 'Apri condominio al login',
    default_condominio_id: 'Condominio predefinito',
    default_user_role: 'Ruolo dei nuovi utenti',
    commenti_segnalazioni_moderati_di_serie: 'segnalazioni (di serie spento)',
    commenti_comunicazioni_moderati_di_serie: 'comunicazioni (di serie acceso)',
    allegati_moderati_di_serie: 'Allegati',
    allegati_massimo_per_elemento: 'Quanti file al massimo per elemento: da 1 a 50 (di serie 5)',
    segnalazioni_assegnazione_visibile_nel_portale: 'Chi segue la segnalazione',
    segnalazioni_assegnazione_porta_in_lavorazione: 'Assegnata, «in lavorazione»',
    comunicazioni_registra_aperture: 'Aperture delle comunicazioni',
    errori_avviso_dalla_pagina: 'Avvisa lo studio',
    due_fattori_obbligatoria_pannello: 'Verifica in due passaggi',
    default_per_page: 'Righe per pagina',
};

describe('la guida delle impostazioni generali', () => {
    test('aperta mostra le sue parti; chiusa non c\'è', async () => {
        const t = await apri();
        for (const parte of ['Guida: impostazioni generali', 'Il programma', 'Chi si registra', 'Segnalazioni, comunicazioni e allegati', 'Sicurezza ed errori']) {
            expect(t).toContain(parte);
        }
        w!.unmount();
        document.body.innerHTML = '';
        w = mount(Guida, { attachTo: document.body, props: { open: false } });
        await flushPromises();
        expect(document.body.querySelector('[data-testid="guida-impostazioni-generali"]')).toBeNull();
    });

    test('spiega ogni impostazione che la pagina salva, e la cancellazione delle aperture', async () => {
        const t = await apri();
        const modulo = pagina.slice(pagina.indexOf('const form = useForm({'), pagina.indexOf('const confermaCancellazioneAperture'));
        const campi = [...modulo.matchAll(/^ {2}([a-z_]+):/gm)].map((m) => m[1]);

        expect(campi.sort()).toEqual(Object.keys(CAMPI).sort());
        for (const [campo, parole] of Object.entries(CAMPI)) {
            expect(t, campo).toContain(parole);
        }
        expect(t).toContain('«Cancella le aperture registrate»');
    });

    test('dice ciò che il programma fa', async () => {
        const t = await apri();

        expect(t).toContain('Valgono per tutto lo studio, tranne «Apri condominio al login», che vale solo per te.');
        // Il percorso come lo mostra il programma: il menu in alto del gestionale, poi la scheda.
        expect(t).toContain('Le scelte di un solo condominio stanno nel suo menu «Condominio», alla scheda «Impostazioni».');
        expect(t).not.toContain('Struttura');
        expect(leggi('resources/js/components/GestionaleHeader.vue')).toContain("title: 'Condominio',");
        expect(leggi('resources/js/layouts/gestionale/StrutturaLayout.vue')).toContain("title: 'Impostazioni',");
        expect(t).toContain('una sola per tutti, non si sceglie utente per utente');
        expect(t).not.toContain('dispositivi non ancora configurati');
        expect(t).toContain('In testa e in firma alle email vale solo se nelle stampe non è scritto il nome dello studio.');
        expect(t).toContain('se sei amministratore o collaboratore');
        expect(t).toContain('Libro giornale, registro di contabilità e mastrini partono da 20.');
        expect(t).toContain('chi accetta un invito lo riceve solo se è «utente» o «fornitore», altrimenti riceve «utente»');
        expect(t).toContain('tranne quelli di chi dal pannello li può approvare o pubblicare');
        expect(t).toContain('agisce subito, dopo la conferma, su tutte le comunicazioni: non si recuperano, e le prese visione restano.');
        expect(t).toContain('e non può toglierla. Condòmini e fornitori non cambiano.');
        expect(t).toContain('I collaboratori dello studio tengono «Avvisa l\'amministratore» anche da spento.');
        expect(t).toContain('Gli interruttori e i campi cambiano solo con «Salva impostazioni».');
        expect(t).toContain('non registra «Apri condominio al login», che è tua, né la cancellazione delle aperture.');
    });

    test('ogni frase ha la riga di codice che la prova, e la riga c\'è', () => {
        const prove = [...sorgenteGuida.matchAll(/<!-- prova: ([^\s:]+):(\d+) «([^»]+)» -->/g)];
        expect(prove.length).toBeGreaterThanOrEqual(30);
        const mancanti = prove.filter(([, file, riga, testo]) => {
            if (!existsSync(resolve(process.cwd(), file))) return true;
            const righe = leggi(file).split('\n');

            return !(righe[Number(riga) - 1] ?? '').replace(/\\'/g, '\'').includes(testo);
        }).map(([, file, riga, testo]) => `${file}:${riga} «${testo}»`);
        expect(mancanti).toEqual([]);
        // Ogni voce dell'elenco, la descrizione e la riga in fondo hanno la loro prova.
        const voci = sorgenteGuida.match(/<li class="flex gap-2">/g) ?? [];
        const voci_con_prova = sorgenteGuida.match(/<li class="flex gap-2"><!-- prova:/g) ?? [];
        expect(voci.length).toBeGreaterThanOrEqual(15);
        expect(voci_con_prova.length).toBe(voci.length);
        expect(sorgenteGuida).toMatch(/<!-- prova: .+ -->\n\s*<SheetDescription/);
        expect(sorgenteGuida).toMatch(/<!-- prova: .+ -->\n\s*<p class="text-xs text-slate-500/);
    });

    test('ogni nome fra virgolette basse c\'è nei sorgenti', async () => {
        const t = await apri();
        const nomi = [...new Set([...t.matchAll(/«([^«»]+)»/g)].map((m) => m[1]))].filter((n) => !n.endsWith('.'));
        expect(nomi.length).toBeGreaterThan(8);
        expect(nomi.filter((n) => !sorgenti.includes(n))).toEqual([]);
    });
});
