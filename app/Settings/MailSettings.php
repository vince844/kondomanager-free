<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Settings;


class MailSettings extends ImpostazioniDelRegistro
{

    public bool $mail_enabled = false;
    public ?string $mail_host = null;
    public ?int $mail_port = 587;
    public ?string $mail_username = null;
    public ?string $mail_password = null;
    public ?string $mail_encryption = 'tls';
    public ?string $mail_from_address = null;
    public string $mail_from_name = 'KondoManager';
    public string $mail_driver = 'smtp';       // 'smtp' | 'sendmail'
    // Quando il modo di mandare, il server, la porta, la cifratura, l'utente o la password sono cambiati l'ultima volta, e l'ultima prova con l'impronta
    // della casella provata (decisione 572): la riga «Adesso» della pagina. Non entrano nel registro delle modifiche.
    public ?string $mail_impostata_il = null;
    public ?array $mail_ultima_prova = null;
    // «Promemoria per email delle convocazioni da spedire»: acceso di serie. È dello studio, non della casella: si cambia anche dove la posta la prepara
    // chi gestisce l'installazione ({@see \App\Services\Assemblee\Convocazione\PromemoriaDellaConvocazione}).
    public bool $promemoria_convocazioni = true;

    public static function group(): string
    {
        return 'mail';
    }
}