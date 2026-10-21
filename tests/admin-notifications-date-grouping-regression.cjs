const assert = require("node:assert/strict");
const fs = require("node:fs");
const path = require("node:path");
const vm = require("node:vm");

const projectRoot = path.resolve(__dirname, "..");
const source = fs.readFileSync(
  path.join(projectRoot, "scripts/components/admin-notifications.js"),
  "utf8",
);
const context = {
  console,
  Date: class extends Date {
    constructor(...args) { super(...(args.length ? args : ["2026-08-21T00:00:00+08:00"])); }
  },
  Intl,
  Map,
  Object,
  Array,
  Number,
  Boolean,
  String,
  window: {},
};

vm.runInNewContext(source, context);

const component = context.adminNotifications();
component.notifications = [
  { notification_id: 1, created_at: "2026-08-21T12:21:00+08:00" },
  { notification_id: 2, created_at: "2026-08-21T09:00:00+08:00" },
  { notification_id: 3, created_at: "2026-08-20T16:45:00+08:00" },
  { notification_id: 4, created_at: "2026-08-19T17:30:00+08:00" },
  { notification_id: 5, created_at: "2025-12-31T09:15:00+08:00" },
];

const groups = component.notificationGroups();

assert.deepEqual(
  Array.from(groups, (group) => group.label),
  ["Today", "Yesterday", "Aug 19", "Dec 31, 2025"],
);
assert.equal(groups[0].notifications.length, 2, "A date separator must not repeat");
assert.match(component.formatNotificationTime(component.notifications[0].created_at), /12:21/);
assert.doesNotMatch(
  component.formatNotificationTime(component.notifications[0].created_at),
  /Aug|2026/,
  "Notification rows should contain only the time because the separator owns the date",
);

component.notifications = [
  {notification_id: 6, created_at: '2026-08-20T16:00:00Z'},
  {notification_id: 7, created_at: '2026-08-20T15:59:59Z'},
];
assert.deepEqual(Array.from(component.notificationGroups(), group => group.label), ['Today', 'Yesterday'],
  'Shared preview and history date separators use Manila midnight, not the UTC date prefix');

console.log("Admin notification date grouping regression tests passed.");
