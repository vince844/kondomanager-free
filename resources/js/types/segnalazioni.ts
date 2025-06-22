import { Anagrafica } from './anagrafiche';
import { User } from './users';
import { Building } from './buildings';
import { Component } from 'vue';

export type PriorityValue = 'bassa' | 'media' | 'alta' | 'urgente';
export type StatoValue = 'aperta' | 'in lavorazione' | 'chiusa';
export type PublishedValue = boolean;

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

export interface StatoType {
  value: StatoValue;
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

export interface Segnalazione {
    id: number;
    subject: string;
    description: string;
    created_at: string;
    created_by: {
      user: User
      anagrafica: Anagrafica;
    };
    assigned_to: Anagrafica;
    condominio: {
      option: any;             
      full: Building;           
    };
    priority: PriorityValue;  
    stato: StatoValue;  
    is_resolved: boolean;
    is_locked: boolean;
    is_featured: boolean;
    is_private: boolean;
    is_published: boolean;
    is_approved: boolean;
    can_comment: boolean; 
    anagrafiche: Anagrafica[];
  }