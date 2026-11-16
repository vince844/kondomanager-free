// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

export interface Invito {
    id: string
    email: string
    expires_at: string
    created_at: string 
    accepted_at: string | null
    bulding_codes: string[] 
  }