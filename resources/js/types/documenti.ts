
import { Component } from 'vue';
import { Building } from './buildings';
import { Anagrafica } from './anagrafiche';
import { User } from './users';
import { Categoria } from './categorie';

export type PublishedValue = boolean;

export type Stats = {
  total_storage_bytes: number,
  total_documents: number,
  uploaded_this_month: number,
  average_size_bytes: number
};

export interface PublishedType {
  value: PublishedValue;
  label: string;
  icon: Component;
  colorClass: string;
}

export interface Documento {
  id: number;
  name: string;
  description: string;
  is_published: boolean;
  is_approved: boolean
  created_at: string;
  path?: string; 
  file_size?: number;
  mime_type: string; 
  mime_type_label?: string; 
  condomini: {
    options: Building[];
    full: Building[];
  };
  created_by: {
    user: User;
    anagrafica: Anagrafica;
  };
  anagrafiche: Anagrafica[];
  categoria: Categoria;
}

// Aggiungi queste interfacce
export interface BaseDocumentForm {
  name: string;
  description: string;
  is_published: boolean;
  file: File | null;
  anagrafiche: number[];
}

export interface AdminDocumentForm extends BaseDocumentForm {
  condomini_ids: number[];
  category_id: string | number | null;
}

export interface FornitoreDocumentForm extends BaseDocumentForm {
  // Campi specifici per fornitori
}

export interface ImmobileDocumentForm extends BaseDocumentForm {
  // Campi specifici per immobili
}

// Mantieni anche l'interfaccia generica se serve
export interface DocumentForm extends BaseDocumentForm {
  condomini_ids?: number[];
  category_id?: string | number | null;
}