
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
  id: string;
  name: string;
  description: string;
  is_published: boolean;
  created_at: string;
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