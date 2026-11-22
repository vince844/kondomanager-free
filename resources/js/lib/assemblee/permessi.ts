// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * Chi sceglie la delibera nelle pagine di oggi (§1.4 del progetto della tappa 2): chi ha «Gestisce le assemblee», perché scegliere la delibera la collega
 * alla scheda. Senza il permesso la pagina lavora come prima, con la data scritta a mano. Se il composable dei permessi non sa dire i permessi (una pagina
 * montata senza l'utente), la risposta è no: la pagina resta com'era.
 */
import { Permission } from '@/enums/Permission';

export function gestisceLeAssemblee(permessi: { hasPermission?: (p: string[]) => boolean }): boolean {
    return typeof permessi.hasPermission === 'function' && permessi.hasPermission([Permission.MANAGE_ASSEMBLEE]) === true;
}
