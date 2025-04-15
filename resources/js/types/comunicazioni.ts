import { Anagrafica } from './anagrafiche';
import { Building } from './buildings';
import { Component } from 'vue';

export type PriorityValue = 'bassa' | 'media' | 'alta' | 'urgente';
export type PublishedValue = boolean;

export interface PriorityType {
  value: PriorityValue;
  label: string;
  icon: Component;
  colorClass: string;
}

export interface PublishedType {
  value: PublishedValue;
  label: string;
  colorClass: string;
}

export interface Comunicazione {
    id: string;
    subject: string;
    description: string;
    created_at: string;
    created_by: {
      user_id: string;         
      name: string;
      email: string;
      anagrafica: Anagrafica[];
    };
    assigned_to: Anagrafica[];
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