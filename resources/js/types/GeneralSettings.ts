import type { PageProps } from '@inertiajs/core'
import type { Flash } from '@/types/flash'

export interface CondominioOption {
  id: number
  nome: string
}

export interface GeneralSettings extends PageProps {
  flash?: { message?: Flash }
  can_register: boolean
  language: string
  open_condominio_on_login: boolean
  default_condominio_id: number | null
  condomini: CondominioOption[]
}