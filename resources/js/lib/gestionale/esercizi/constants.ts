// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

import { CircleCheck, CircleX } from 'lucide-vue-next';
import type { StatusType } from '@/types/gestionale/esercizi';

  
  export const statusConstants: StatusType[] = [
    { 
      value: 'aperto', 
      label: 'Aperto',
      icon: CircleCheck, 
      colorClass: 'text-green-500 bg-transparent'
    },
    { 
      value: 'chiuso', 
      label: 'Chiuso',
      icon: CircleX, 
      colorClass: 'text-red-500 bg-transparent'
    }
  ];
  