<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Services\RegistroModifiche\Ripristino;

/**
 * Le parole della finestra di «Ripristina»: il dato con il suo articolo («Rimettere l'email di prima?», la bozza), il documento partito per email («la
 * ricevuta n. INC-2026-00231»).
 */
final class ParoleDelRipristino
{
    /** Il dato con l'articolo, per il titolo e il pulsante. */
    private const OGGETTI = [
        'email' => 'l\'email', 'email_secondaria' => 'l\'email secondaria', 'pec' => 'la PEC', 'telefono' => 'il telefono', 'cellulare' => 'il cellulare',
        'fax' => 'il fax', 'nome' => 'il nome', 'ragione_sociale' => 'la ragione sociale', 'indirizzo' => 'l\'indirizzo', 'note' => 'le note',
        'codice_fiscale' => 'il codice fiscale', 'partita_iva' => 'la partita IVA', 'sito_web' => 'il sito', 'cap' => 'il CAP', 'comune' => 'il comune',
        'provincia' => 'la provincia', 'luogo_nascita' => 'il luogo di nascita', 'data_nascita' => 'la data di nascita',
        'tipologia_documento' => 'la tipologia del documento', 'numero_documento' => 'il numero del documento', 'scadenza_documento' => 'la scadenza del documento',
        'descrizione' => 'la descrizione', 'interno' => 'l\'interno', 'piano' => 'il piano', 'superficie' => 'la superficie', 'numero_vani' => 'i vani',
        'name' => 'il nome', 'nota_legale_stampe' => 'la nota legale', 'studio_nome' => 'il nome dello studio', 'studio_indirizzo' => 'l\'indirizzo dello studio',
        'studio_email' => 'l\'email dello studio', 'studio_pec' => 'la PEC dello studio', 'studio_telefono' => 'il telefono dello studio',
        'studio_comune' => 'il comune dello studio', 'studio_partita_iva' => 'la partita IVA dello studio', 'studio_codice_fiscale' => 'il codice fiscale dello studio',
        'studio_sito' => 'il sito dello studio', 'anno_costruzione' => 'l\'anno di costruzione', 'anno_acquisizione' => 'l\'anno di acquisizione',
        'numero_piani' => 'il numero dei piani',
    ];

    public static function oggetto(string $campo, string $etichetta, string $tipo): string
    {
        // Il titolo e il testo di una comunicazione o di una segnalazione; la descrizione di un documento, di una palazzina, di una scala.
        if ($campo === 'subject') {
            return 'il titolo';
        }
        if ($campo === 'description') {
            return in_array($tipo, ['comunicazione', 'segnalazione'], true) ? 'il testo' : 'la descrizione';
        }

        return self::OGGETTI[$campo] ?? ('il dato «' . $etichetta . '»');
    }

    /** I dati con «l'» davanti che sono femminili: «Email di Ida Romano, cambiata da …». */
    private const FEMMINILI_CON_L = ['email', 'email_secondaria', 'studio_email'];

    /**
     * Il dato per la riga sotto il titolo della finestra, senza l'articolo e con la maiuscola, e «cambiato» accordato al dato (la bozza: «Email di Ida
     * Romano, cambiata da Marco Galli il 16/12/2026 alle 09:12.»): `['Email', 'cambiata']`, `['Telefono', 'cambiato']`, `['Note', 'cambiate']`.
     *
     * @return array{0: string, 1: string}
     */
    public static function datoECambiato(string $campo, string $etichetta, string $tipo): array
    {
        $oggetto = self::oggetto($campo, $etichetta, $tipo);
        if (str_starts_with($oggetto, 'l\'')) {
            [$articolo, $nome] = ['l\'', mb_substr($oggetto, 2)];
        } elseif (preg_match('/^(il|lo|la|i|gli|le) (.+)$/u', $oggetto, $m) === 1) {
            [$articolo, $nome] = [$m[1], $m[2]];
        } else {
            [$articolo, $nome] = ['il', $oggetto];
        }
        $cambiato = match ($articolo) {
            'la' => 'cambiata',
            'le' => 'cambiate',
            'i', 'gli' => 'cambiati',
            'l\'' => in_array($campo, self::FEMMINILI_CON_L, true) ? 'cambiata' : 'cambiato',
            default => 'cambiato',
        };

        return [mb_strtoupper(mb_substr($nome, 0, 1)) . mb_substr($nome, 1), $cambiato];
    }

    /** Il documento partito, con l'articolo: «la ricevuta», «il sollecito». */
    public static function documentoInviato(string $tipo): string
    {
        return match ($tipo) {
            'ricevuta' => 'la ricevuta',
            'promemoria' => 'il promemoria',
            'sollecito' => 'il sollecito',
            'diffida' => 'la diffida',
            'messa_in_mora' => 'la messa in mora',
            'avviso_compensazione' => 'l\'avviso di compensazione',
            'foglio_conguaglio' => 'il foglio del conguaglio',
            default => 'il documento',
        };
    }
}
