export function formatPhpAmount(amount) {
  return `₱${Number(amount || 0).toLocaleString("en-PH")}`;
}

export function formatPriceOption(priceOption) {
  if (!priceOption) return "";
  if (["plus", "starting_at"].includes(priceOption.pricingType)) {
    return `${formatPhpAmount(priceOption.minAmount)}+`;
  }
  if (priceOption.pricingType === "range") {
    return `${formatPhpAmount(priceOption.minAmount)}–${formatPhpAmount(priceOption.maxAmount)}`;
  }
  return formatPhpAmount(priceOption.minAmount);
}
