// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

import { Permission } from './permissions';

export interface Role {
    id: number
    name: string
    description: string
    users_count: number
    is_protected: boolean
    /** È il ruolo dei nuovi iscritti: non si elimina finché non se ne sceglie un altro (Coda 258). */
    is_registration_role?: boolean
    permissions: Permission[]
}