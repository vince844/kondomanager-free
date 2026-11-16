// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

import { Sun, Zap } from 'lucide-vue-next';
import type { StatusType } from '@/types/gestionale/gestioni';

  
  export const typeConstants: StatusType[] = [
    { 
      value: 'ordinaria', 
      label: 'Ordinaria',
      icon: Sun, 
      colorClass: 'text-green-500 bg-transparent'
    },
    { 
      value: 'straordinaria', 
      label: 'Straordinaria',
      icon: Zap, 
      colorClass: 'text-yellow-500 bg-transparent'
    }
  ];
  