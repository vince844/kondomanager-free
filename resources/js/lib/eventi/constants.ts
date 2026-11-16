// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

import { CircleCheck, CircleX, EyeOff } from 'lucide-vue-next';
import { VisibilityType } from '@/types/eventi';
  
export const visibilityConstants: VisibilityType[] = [
  { 
    value: 'hidden', 
    label: 'Nascosta',
    icon: EyeOff, 
    colorClass: 'text-slate-500 bg-transparent'
  },
  { 
    value: 'private', 
    label: 'Privata',
    icon: CircleX, 
    colorClass: 'text-red-500 bg-transparent'
  },
  { 
    value: 'public', 
    label: 'Pubblica', 
    icon: CircleCheck, 
    colorClass: 'text-green-500 bg-transparent'
  }
];
  