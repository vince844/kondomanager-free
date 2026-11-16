// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

import { CircleCheck,CircleX } from 'lucide-vue-next';
import type { PublishedType } from '@/types/documenti';

// Coda 306: la tendina e il filtro di `is_published` dicono la pubblicazione («Bozza» o
// la pubblicazione), non la privacy: i documenti la privacy non la salvano, e qui non si dice
// mai «Privato».
export const publishedConstants: PublishedType[] = [
  { 
    value: false, 
    label: 'documenti.publication.draft',
    icon: CircleX, 
    colorClass: 'text-red-500 bg-transparent'
  },
  { 
    value: true, 
    label: 'documenti.publication.published',
    icon: CircleCheck, 
    colorClass: 'text-green-500 bg-transparent'
  }
];
  