// Shared recovery for both walk-in submission workflows.
import "./human-name.js?v=names-20261110";

export const WalkInOwnerValidation = (() => {
  const key = 'walkInOwnerStep';
  function errors(owner) {
    if (owner?.ownerRecordType && owner.ownerRecordType !== 'new') return {};
    return Object.fromEntries([['firstName', 'first_name'], ['lastName', 'last_name']]
      .map(([field, type]) => [field, window.HumanName.validate(owner?.[field], { type })])
      .filter(([, message]) => message));
  }
  function recover(owner, messages, appointmentType = owner?.appointmentType || 'grooming') {
    let current;
    try { current = JSON.parse(sessionStorage.getItem(key)) || {}; }
    catch { current = {}; }
    if (current.appointmentType && current.appointmentType !== appointmentType) return false;
    const fields = Object.keys(messages).filter(field => current[field] === owner?.[field]);
    if (!fields.length) return false;
    current.appointmentType = appointmentType;
    current.nameErrors = Object.fromEntries(fields.map(field => [field, { value: String(current[field] ?? ''), message: messages[field] }]));
    current.recoveringName = true;
    sessionStorage.setItem(key, JSON.stringify(current));
    window.location.href = `/admin/walk-in/customer?flow=${appointmentType}`;
    return true;
  }
  function validate(owner, appointmentType) {
    const messages = errors(owner);
    if (!Object.keys(messages).length) return true;
    recover(owner, messages, appointmentType);
    return false;
  }
  function serverErrors(owner, responseErrors = {}, appointmentType) {
    const messages = {};
    for (const [source, field] of [['fname', 'firstName'], ['lname', 'lastName']]) {
      if (responseErrors[source]) messages[field] = [].concat(responseErrors[source])[0];
    }
    return recover(owner, messages, appointmentType);
  }
  return { validate, serverErrors };
})();
