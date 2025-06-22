// comunicazioni.ts

import { Anagrafica } from './anagrafiche';
import { User } from './users';
import { Building } from './buildings';
import { Component } from 'vue';
import { CircleArrowDown, CircleArrowRight, CircleArrowUp, CircleAlert, CircleCheck, CircleX } from 'lucide-vue-next';
import { PaginationMeta } from './pagination';

export type PriorityValue = 'bassa' | 'media' | 'alta' | 'urgente';
export type PublishedValue = boolean;

export type PaginatedComunicazioni = {
  data: Comunicazione[];
} & PaginationMeta;

export type Stats = {
  bassa: number;
  media: number;
  alta: number;
  urgente: number;
};

export interface PriorityType {
  value: PriorityValue;
  label: string;
  icon: Component;
  colorClass: string;
}

export interface PublishedType {
  value: PublishedValue;
  label: string;
  icon: Component;
  colorClass: string;
}

export interface Comunicazione {
  id: number;
  subject: string;
  description: string;
  created_at: string;
  created_by: {
    user: User;
    anagrafica: Anagrafica;
  };
  condomini: {
    options: Building[];
    full: Building[];
  };
  priority: PriorityValue;
  is_featured: boolean;
  is_private: boolean;
  is_published: boolean;
  is_approved: boolean;
  can_comment: boolean;
  anagrafiche: Anagrafica[];
}

// Priority options
export const PRIORITY_OPTIONS: PriorityType[] = [
  {
    value: 'bassa',
    label: 'Bassa',
    icon: CircleArrowDown,
    colorClass: 'text-green-600',
  },
  {
    value: 'media',
    label: 'Media',
    icon: CircleArrowRight,
    colorClass: 'text-yellow-500',
  },
  {
    value: 'alta',
    label: 'Alta',
    icon: CircleArrowUp,
    colorClass: 'text-orange-600',
  },
  {
    value: 'urgente',
    label: 'Urgente',
    icon: CircleAlert,
    colorClass: 'text-red-600',
  },
];

// Published options
export const PUBLISHED_OPTIONS: PublishedType[] = [
  {
    value: true,
    label: 'Pubblicata',
    icon: CircleCheck,
    colorClass: 'text-green-600',
  },
  {
    value: false,
    label: 'Bozza',
    icon: CircleX,
    colorClass: 'text-red-500',
  },
];

// Helper to get priority metadata
export const getPriorityMeta = (priority: PriorityValue): PriorityType => {
  return PRIORITY_OPTIONS.find(p => p.value === priority)!;
};

// Helper to get published metadata
export const getPublishedMeta = (isPublished: PublishedValue): PublishedType => {
  return PUBLISHED_OPTIONS.find(p => p.value === isPublished)!;
};
