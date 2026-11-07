/**
 * Due testi a video che promettevano quello che il programma non fa, letti nel sorgente (sono guide statiche): la guida dei pagamenti parziali
 * dice dove si ritrova il residuo, non «tracciato per i futuri solleciti»; la pagina del fornitore non promette di incrociare i suoi dati con i
 * saldi dei condòmini.
 */
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { describe, expect, test } from 'vitest';

const sorgente = (file: string) => readFileSync(resolve(process.cwd(), file), 'utf8');

describe('i testi delle guide', () => {
    test('i pagamenti parziali: il residuo si ritrova fra le rate scadute', () => {
        const s = sorgente('resources/js/pages/gestionale/movimenti/incassi/IncassoRateList.vue');
        expect(s).toContain('Il residuo resta aperto: lo ritrovi fra le rate in scadenza e, dopo la scadenza, fra le rate scadute.');
        expect(s).not.toContain('tracciato per i futuri solleciti');
    });

    test('la pagina del fornitore non promette un incrocio con i condòmini, e non dice «morosità»', () => {
        const s = sorgente('resources/js/pages/fornitori/FornitoreSituazioneDebitoria.vue');
        expect(s).not.toContain('incrocia questi dati');
        expect(s.toLowerCase()).not.toContain('morosit');
        expect(s).toContain('Chi deve ancora pagare le rate è nell\\\'elenco «Rate scadute» del condominio.');
    });
});
