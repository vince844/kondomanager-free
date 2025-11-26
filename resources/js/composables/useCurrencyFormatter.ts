// composables/useEuroFormatter.ts
interface EuroFormatOptions {
  locale?: string;
  minimumFractionDigits?: number;
  maximumFractionDigits?: number;

  // ✨ personalizzazioni extra
  spacing?: "normal" | "none" | "nbsp";
  negativeStyle?: "after-symbol" | "before-symbol";
}

export const useCurrencyFormatter = (globalOptions: EuroFormatOptions = {}) => {
  const baseConfig: EuroFormatOptions = {
    locale: "it-IT",
    minimumFractionDigits: 2,
    maximumFractionDigits: 2,
    spacing: "normal",
    negativeStyle: "after-symbol",
    ...globalOptions,
  };

  /**
   * FORMAT: accetta centesimi → restituisce stringa (€ 00,00)
   */
  const format = (cents: number, opts: EuroFormatOptions = {}): string => {
    const config = { ...baseConfig, ...opts };

    const number = new Intl.NumberFormat(config.locale, {
      minimumFractionDigits: config.minimumFractionDigits,
      maximumFractionDigits: config.maximumFractionDigits,
    }).format(Math.abs(cents) / 100);

    // Tipo di spazio
    const space =
      config.spacing === "none"
        ? ""
        : config.spacing === "nbsp"
        ? "\u00A0"
        : " "; // normal

    // 💡 Negativi personalizzati
    if (cents < 0) {
      if (config.negativeStyle === "after-symbol") {
        return `€${space}-${number}`;
      } else {
        return `-${space}€${space}${number}`.trim();
      }
    }

    // Positivi
    return `€${space}${number}`;
  };

  return {
    euro: format,
    format,
  };
};
