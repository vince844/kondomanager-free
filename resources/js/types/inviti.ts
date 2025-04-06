export interface Invito {
    id: string
    email: string
    expires_at: string
    created_at: string 
    accepted_at: string | null
    bulding_codes: string[] 
  }