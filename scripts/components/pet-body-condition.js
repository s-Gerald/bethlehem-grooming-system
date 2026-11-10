export function renderBodyConditionChip(label) {
  if (!label) return "";

  const text = String(label).replaceAll("&", "&amp;").replaceAll("<", "&lt;").replaceAll(">", "&gt;");
  return `<span class="pet-body-condition">${text}</span>`;
}
