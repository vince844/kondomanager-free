<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Services\RegistroModifiche;

use App\Enums\Role;
use Spatie\LaravelSettings\Events\SavingSettings;

/**
 * Il gesto 8 del registro delle modifiche (P20): le impostazioni dello studio (`app/Settings/*`) non hanno modelli, e il pacchetto le salva con un `upsert`.
 * Un ascoltatore solo di `SavingSettings` confronta i valori di prima (`originalValues`) con quelli di dopo e scrive una riga per salvataggio, un campo per
 * impostazione cambiata, con le parole della pagina (`lang/it/impostazioni.php`, le pagine delle impostazioni). I segreti dicono «cambiata» (la password
 * SMTP, il gettone del cron esterno); `version` è del programma; i file (firma, logo) dicono «cambiata» senza il percorso (P18).
 */
final class ImpostazioniNelRegistro
{
    /** Gruppo => [nome nella riga, tipo della scheda]. */
    public const GRUPPI = [
        'general' => ['Impostazioni generali', 'impostazioni_generali'],
        'mail' => ['Configurazione email', 'impostazioni_posta'],
        // La casella PEC dello studio sta nella stessa pagina, e nella stessa «Storia», della posta (decisione 572).
        'pec' => ['Casella PEC dello studio', 'impostazioni_posta'],
        'print' => ['Impostazioni delle stampe', 'impostazioni_stampe'],
        'backup' => ['Impostazioni dei backup', 'impostazioni_backup'],
        'tasso_legale' => ['Tasso legale', 'impostazioni_tasso_legale'],
        // La durata delle righe del registro stesso (§11).
        'registro_modifiche' => ['Registro delle modifiche', 'impostazioni_registro_modifiche'],
        // I giorni della posta per «Entro quando spedire» (decisione 565).
        'assemblee' => ['Impostazioni delle assemblee', 'impostazioni_assemblee'],
    ];

    /** Gruppo => [proprietà => [parola della pagina, formato]]. */
    public const ETICHETTE = [
        'general' => [
            'user_frontend_registration' => ['Abilita registrazione utenti', 'si_no'],
            'language' => ['Lingua applicazione', 'testo'],
            'app_name' => ['Nome applicazione', 'testo'],
            'external_cron_enabled' => ['Automazione cloud (cron esterno)', 'si_no'],
            'external_cron_token' => ['Chiave del cron esterno', 'testo'],
            'default_user_role' => ['Ruolo predefinito nuovi utenti', 'testo'],
            'commenti_segnalazioni_moderati_di_serie' => ['Commenti delle segnalazioni in moderazione di serie', 'si_no'],
            'commenti_comunicazioni_moderati_di_serie' => ['Commenti delle comunicazioni in moderazione di serie', 'si_no'],
            'allegati_moderati_di_serie' => ['Allegati in moderazione di serie', 'si_no'],
            'allegati_massimo_per_elemento' => ['Numero massimo di allegati per elemento', 'numero'],
            'segnalazioni_assegnazione_visibile_nel_portale' => ['Mostra ai condòmini chi segue la segnalazione', 'si_no'],
            'segnalazioni_assegnazione_porta_in_lavorazione' => ['Assegnando una segnalazione aperta, passa a «in lavorazione»', 'si_no'],
            'default_per_page' => ['Righe per pagina negli elenchi', 'numero'],
        ],
        // Le parole della pagina delle caselle dello studio (decisione 572, proposta 14). La porta come testo: 2525 non è «2.525».
        'mail' => [
            'mail_enabled' => ['Manda la posta da questa casella', 'si_no'],
            'mail_host' => ['Server di posta in uscita', 'testo'],
            'mail_port' => ['Porta', 'testo'],
            'mail_username' => ['Utente', 'testo'],
            'mail_password' => ['Password', 'testo'],
            'mail_encryption' => ['Cifratura', ['scelta', ['tls' => 'TLS', 'ssl' => 'SSL', 'null' => 'Nessuna']]],
            'mail_from_address' => ['Indirizzo del mittente', 'testo'],
            'mail_from_name' => ['Nome del mittente', 'testo'],
            'mail_driver' => ['Come si manda', ['scelta', ['smtp' => 'Un server di posta (SMTP)', 'sendmail' => 'La posta del server (Sendmail)']]],
            'promemoria_convocazioni' => ['Promemoria per email delle convocazioni da spedire', 'si_no'],
        ],
        'pec' => [
            'indirizzo' => ['Indirizzo PEC', 'testo'],
            'server' => ['Server di posta in uscita', 'testo'],
            'porta' => ['Porta', 'testo'],
            'cifratura' => ['Cifratura', ['scelta', ['ssl' => 'SSL', 'tls' => 'TLS']]],
            'utente' => ['Utente', 'testo'],
            'password' => ['Password', 'testo'],
        ],
        'print' => [
            'nota_legale_stampe' => ['Nota legale', 'testo'],
            'firma_stampe_path' => ['Firma amministratore', 'testo'],
            'logo_stampe_path' => ['Logo dello studio', 'testo'],
            'studio_nome' => ['Nome dello studio', 'testo'],
            'studio_indirizzo' => ['Indirizzo', 'testo'],
            'studio_email' => ['Email dello studio', 'testo'],
            'studio_pec' => ['PEC', 'testo'],
            'studio_telefono' => ['Telefono', 'testo'],
            'studio_comune' => ['Comune', 'testo'],
            'studio_partita_iva' => ['Partita IVA', 'testo'],
            'studio_codice_fiscale' => ['Codice fiscale', 'testo'],
            'studio_sito' => ['Sito', 'testo'],
            'carta_per_gruppo' => ['La carta di ogni gruppo di stampe', 'testo'],
        ],
        'backup' => [
            'retention_keep_last' => ['Backup da conservare', 'numero'],
        ],
        'tasso_legale' => [
            'scritti_a_mano' => ['Tassi legali scritti a mano', 'testo'],
        ],
        'registro_modifiche' => [
            'anni_di_conservazione' => ['Per quanto tempo si tengono le righe', ['scelta', ['10' => '10 anni', '15' => '15 anni', '20' => '20 anni']]],
        ],
        'assemblee' => [
            'giorni_di_posta' => ['Giorni della posta per una raccomandata', 'numero'],
            'delega_oltre_il_quinto' => ['La delega oltre un quinto', ['scelta', ['rifiuta' => 'Si rifiuta, con l\'articolo',
                'decide_il_presidente' => 'Decide il presidente, con il motivo']]],
            'voto_discorde_comproprietari' => ['Il voto discorde dei comproprietari', ['scelta', ['astenuti' => 'Contano astenuti',
                'decide_il_presidente' => 'Decide il presidente']]],
        ],
    ];

    /** I segreti: «cambiata», senza valori. I file: «cambiata», senza il percorso. */
    public const SEGRETI = ['mail.mail_password', 'pec.password', 'general.external_cron_token', 'print.firma_stampe_path', 'print.logo_stampe_path'];

    /** Del programma, mai nella riga: la versione, e quando una casella è stata impostata e la sua ultima prova (decisione 572). */
    public const ESCLUSE = ['general.version', 'mail.mail_impostata_il', 'mail.mail_ultima_prova', 'pec.impostata_il', 'pec.ultima_prova'];

    /** Le impostazioni non segrete che «Ripristina» può rimettere (§8; non quelle protette da una conferma della password, P3). */
    public const RIPRISTINABILI = ['print.nota_legale_stampe', 'print.studio_nome', 'print.studio_indirizzo', 'print.studio_email', 'print.studio_pec',
        'print.studio_telefono', 'print.studio_comune', 'print.studio_partita_iva', 'print.studio_codice_fiscale', 'print.studio_sito'];

    public static function salvataggio(SavingSettings $evento): void
    {
        $gruppo = $evento->settings::group();
        $prima = $evento->originalValues;
        if ($prima === null) {
            return;
        }

        $campi = [];
        foreach ($evento->properties as $nome => $dopo) {
            $chiave = $gruppo . '.' . $nome;
            if (in_array($chiave, self::ESCLUSE, true) || ! $prima->has($nome)) {
                continue;
            }
            $vPrima = $prima->get($nome);
            if (self::uguali($vPrima, $dopo)) {
                continue;
            }
            [$etichetta, $formato] = self::ETICHETTE[$gruppo][$nome] ?? [ucfirst(str_replace('_', ' ', (string) $nome)), 'testo'];
            $segreto = in_array($chiave, self::SEGRETI, true);
            $campi[] = Campo::nuovo((string) $nome, $etichetta, self::testo($vPrima, $formato, $chiave), self::testo($dopo, $formato, $chiave),
                is_array($vPrima) ? null : $vPrima, is_array($dopo) ? null : $dopo, $segreto);
        }
        if ($campi === []) {
            return;
        }

        [$nome, $scheda] = self::GRUPPI[$gruppo] ?? [ucfirst((string) $gruppo), 'impostazioni_' . $gruppo];
        // «Ripristina» dalla Storia: come nel tratto delle schede, la riga del salvataggio fatto dal ripristino è «ripristinata», con il perché.
        $ripristino = ContestoDelRegistro::ripristinoInCorso();
        RegistroModifiche::gesto(Gesto::su('impostazioni', $scheda, null, $nome)->azione($ripristino !== null ? 'ripristinata' : 'modificata')
            ->perche($ripristino)->campi($campi));
    }

    private static function uguali(mixed $a, mixed $b): bool
    {
        if (is_array($a) || is_array($b)) {
            return json_encode($a) === json_encode($b);
        }

        return (string) json_encode($a) === (string) json_encode($b);
    }

    private static function testo(mixed $valore, string|array $formato, string $chiave): ?string
    {
        if ($chiave === 'general.default_user_role' && is_string($valore)) {
            return Role::tryFrom($valore)?->value ?? $valore;
        }
        if (is_array($valore)) {
            return $valore === [] ? null : count($valore) . (count($valore) === 1 ? ' voce' : ' voci');
        }

        return FormatoDelValore::testo($valore, $formato);
    }
}
