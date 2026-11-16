// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

import type { Comunicazione } from '@/types/comunicazioni'

declare module '@tanstack/table-core' {
  interface TableMeta<TData extends unknown> {
    updateData: (rowIndex: number, updatedRow: TData) => void;
  }
}