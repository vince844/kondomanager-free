// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

import { Palazzina } from './palazzine';

export interface Scala {
    id: number
    name: string
    description: string
    note: string,
    palazzina: Palazzina             
  }