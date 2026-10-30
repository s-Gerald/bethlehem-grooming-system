const assert = require("node:assert/strict");
const fs = require("node:fs");
const vm = require("node:vm");
const path = require("node:path");

const sandbox = { window: {}, Intl, Date, Map, Object, String, Number };
vm.runInNewContext(
  fs.readFileSync(path.join(__dirname, "../scripts/components/client-notification-ui.js"), "utf8"),
  sandbox,
);
const ui = sandbox.window.ClientNotificationUI;
const today = ui.dateKey(new Date());
const earlier = ui.dateKey(new Date(Date.now() - 2 * 86400000));
const notifications = [
  { id: 1, type: "grooming_finished", display_message: "Max is Finished with grooming.", pet_names: ["Max"], is_read: false, created_at: `${today} 09:30:00` },
  { id: 2, type: "booking_cancelled", display_message: "<script>alert(1)</script>", is_read: true, created_at: `${earlier} 11:00:00` },
];

const dropdown = { innerHTML: "" };
ui.render(dropdown, notifications, "all", true);
assert.match(dropdown.innerHTML, /Today/);
assert.match(dropdown.innerHTML, /Earlier/);
assert.match(dropdown.innerHTML, /phosphor\.svg#check-circle/);
assert.match(dropdown.innerHTML, /phosphor\.svg#x-circle/);
assert.match(dropdown.innerHTML, /<strong class="client-notification-emphasis">Max<\/strong>/);
assert.doesNotMatch(dropdown.innerHTML, /<script>/);
assert.match(dropdown.innerHTML, /&lt;script&gt;/);

const unread = { innerHTML: "" };
ui.render(unread, notifications, "unread", true);
assert.match(unread.innerHTML, /Max<\/strong> is/);
assert.doesNotMatch(unread.innerHTML, /alert\(1\)/);

const page = { innerHTML: "" };
ui.render(page, notifications, "all", false);
assert.match(page.innerHTML, /data-client-notification-index="0"/);
assert.match(page.innerHTML, /data-client-notification-index="1"/);
console.log("Client notification UI regression checks passed.");

const updates = { innerHTML: "" };
ui.render(updates, [{ type: "pet_information_updated", pet_names: ["Andy"],
  message: "Bethlehem Animal Clinic updated Andrew's breed, fur type, and color.", is_read: false }], "all", true, () => "Legacy dashboard formatter");
for (const text of ["Andrew", "breed", "fur type", "color"]) {
  assert.ok(updates.innerHTML.includes(`<strong class="client-notification-emphasis">${text}</strong>`));
}
assert.ok(!updates.innerHTML.includes('<strong class="client-notification-emphasis">Bethlehem'));
ui.render(updates, [{ type: "pet_information_updated", pet_names: ["<img src=x onerror=alert(1)>"],
  message: "Bethlehem Animal Clinic updated <img src=x onerror=alert(1)>'s weight.", is_read: false }]);
assert.doesNotMatch(updates.innerHTML, /<img|<script/);
assert.match(updates.innerHTML, /&lt;img/);
ui.render(updates, [{ type: "pet_information_updated", pet_names: ["Andy"],
  message: "Bethlehem Animal Clinic changed your pet's name from Andrew to Andy.", is_read: false }]);
assert.match(updates.innerHTML, /<strong class="client-notification-emphasis">Andrew<\/strong> to <strong class="client-notification-emphasis">Andy<\/strong>/);
