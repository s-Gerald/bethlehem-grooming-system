// Shared movement presentation for Overview and Stock Movements.
var InventoryMovement = {
  reasonStyle(reason) {
    return {
      purchase: 'background-color:#dcfce7;color:#166534',
      return: 'background-color:#dbeafe;color:#1d4ed8',
      used: 'background-color:#fef3c7;color:#92400e',
      sold: 'background-color:#d1fae5;color:#065f46',
      expired: 'background-color:#fee2e2;color:#b91c1c',
      damaged: 'background-color:#fee2e2;color:#b91c1c',
      adjustment: 'background-color:#f1f5f9;color:#475569',
    }[reason] ?? 'background-color:#f1f5f9;color:#475569';
  },

  reasonLabel(reason) {
    return {
      purchase: 'Purchase', return: 'Return', used: 'Used', sold: 'Sold',
      expired: 'Expired', damaged: 'Damaged', adjustment: 'Adjustment',
    }[reason] ?? reason;
  },
};
