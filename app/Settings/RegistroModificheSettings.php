<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Settings;

/**
 * Il registro delle modifiche, le impostazioni dello studio (1.12.0-beta.13, §11 del progetto, decisione 518 (1)): per quanto tempo si tengono le righe. Di
 * serie 10 anni, come le scritture e i documenti giustificativi del condominio (art. 1130-bis c.c.); le scelte sono 10, 15 e 20 anni
 * ({@see \App\Services\RegistroModifiche\ConservazioneDelRegistro::DURATE}). Il registro non si spegne.
 *
 * Il cambio è una riga del registro come le altre impostazioni (gesto 8, `ImpostazioniNelRegistro`).
 */
class RegistroModificheSettings extends ImpostazioniDelRegistro
{
    public int $anni_di_conservazione = 10;

    public static function group(): string
    {
        return 'registro_modifiche';
    }
}
