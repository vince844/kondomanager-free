// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

import { Immobile } from './immobili';

export interface Millesimo {
  id: number;
  tabella_id: number;
  immobile_id: number;
  valore?: string;
  coefficienti?: Record<string, any> | null;
  escluso: boolean;
  created_by?: number | null;
  updated_by?: number | null;
  created_at: string;
  updated_at: string;
  immobile: Immobile
}
