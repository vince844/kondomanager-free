
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
  /** ⚠️ Plurale dalla 1.11.0-beta.10: un documento può stare in più categorie. */
  categorie: Categoria[];
  /** La data di caricamento in forma leggibile («03/07/2026»), accanto al «tre mesi fa». */
  created_at_data: string;
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
  /** ⚠️ Un array dalla 1.11.0-beta.10: almeno una categoria, e possibilmente più d'una. */
  categorie: number[];
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
  categorie?: number[];
}