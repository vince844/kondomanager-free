export type AllegatoAccesso = 'aperti' | 'consultazione' | 'riservati';
export type AllegatoStato = 'pubblicato' | 'in_attesa';
export type AllegatoTipoElemento = 'segnalazioni' | 'comunicazioni';

export interface AllegatoItem {
    id: number;
    nome: string;
    mime_type: string;
    dimensione: number;
    stato: AllegatoStato;
    autore: string;
    caricato_il: string | null;
    url_apri: string;
    url_scarica: string;
    puo_modificare: boolean;
    puo_eliminare: boolean;
    puo_approvare: boolean;
}

export interface AllegatiConfig {
    accesso: AllegatoAccesso;
    moderati: boolean;
    puo_caricare: boolean;
    puo_impostare: boolean;
    massimo: number;
    rimasti: number;
    limite_file: string;
    formati: string[];
}

export interface AllegatoRigaPagina {
    id: number;
    nome: string;
    tipo: AllegatoTipoElemento;
    elemento: string;
    url_elemento: string;
    condomini: string[];
    autore: string;
    caricato_il: string | null;
    stato: AllegatoStato;
    dimensione: number;
    url_apri: string;
    url_scarica: string;
    puo_approvare: boolean;
    puo_eliminare: boolean;
}

export interface AllegatiFiltri {
    stato: string;
    tipo: string;
    condominio_id: number | null;
    autore: string;
    dal: string;
    al: string;
}

