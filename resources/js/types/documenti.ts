
import { Component } from 'vue';

export type PublishedValue = boolean;

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
}