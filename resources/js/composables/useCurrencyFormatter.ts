// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

interface EuroFormatOptions {
  locale?: string;
  minimumFractionDigits?: number;
  maximumFractionDigits?: number;
  spacing?: "normal" | "none" | "nbsp";
  
  // Opzioni Logiche
  fromCents?: boolean; 
  
  // Opzioni Visive
  forcePlus?: boolean; 
  showSpaceAfterSign?: boolean;
}

export const useCurrencyFormatter = (globalOptions: EuroFormatOptions = {}) => {
  const baseConfig: EuroFormatOptions = {
    locale: "it-IT",
    minimumFractionDigits: 2,
    maximumFractionDigits: 2,
    spacing: "nbsp", // Spazio non divisibile (consigliato)
    fromCents: true, // Default: input in centesimi
    forcePlus: false,
    showSpaceAfterSign: false, 
    ...globalOptions,
  };

  const format = (amount: number | null | undefined, opts: EuroFormatOptions = {}): string => {
    // Gestione valori null/undefined
    if (amount === undefined || amount === null) return '-';

    const config = { ...baseConfig, ...opts };

    // 1. Calcolo valore reale (se fromCents è true, dividiamo per 100)
    const rawValue = config.fromCents ? amount / 100 : amount;
    const absValue = Math.abs(rawValue);

    // 2. Formattazione numero puro con Intl
    const numberString = new Intl.NumberFormat(config.locale, {
      minimumFractionDigits: config.minimumFractionDigits,
      maximumFractionDigits: config.maximumFractionDigits,
      useGrouping: true, // <--- [FIX IMPORTANTE] Forza il punto delle migliaia (1.000)
    }).format(absValue);

    // 3. Gestione Spazi
    const symbolSpace = config.spacing === "none" ? "" : config.spacing === "nbsp" ? "\u00A0" : " ";
    const signSpace = config.showSpaceAfterSign ? (config.spacing === "nbsp" ? "\u00A0" : " ") : "";

    // 4. Determinazione Segno
    let sign = "";
    if (rawValue < 0) {
      sign = "-";
    } else if (rawValue > 0 && config.forcePlus) {
      sign = "+";
    }

    const signPart = sign ? `${sign}${signSpace}` : "";

    // 5. Output: €[spazio]segno[spazio]numero
    return `€${symbolSpace}${signPart}${numberString}`;
  };

  return {
    euro: format,
    format,
  };
};