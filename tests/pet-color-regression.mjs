import assert from "node:assert/strict";
import { test } from "node:test";

// DOM boundary only: filtering, validation, and interactions use the production control.
function element() {
  const listeners = new Map(), attributes = new Map(), classes = new Set();
  return {
    value: "", children: [], dataset: {}, style: {}, disabled: false,
    scrollTop: 0, clientHeight: 160, offsetTop: 0, offsetHeight: 40,
    classList: {
      add(...names) { names.forEach(n => classes.add(n)); },
      remove(...names) { names.forEach(n => classes.delete(n)); },
      contains(n) { return classes.has(n); },
      toggle(n, force = !classes.has(n)) { force ? classes.add(n) : classes.delete(n); },
    },
    set innerHTML(value) { this.children = []; },
    appendChild(child) { child.offsetTop = this.children.length * 40; this.children.push(child); },
    setAttribute(k, v) { attributes.set(k, String(v)); },
    getAttribute(k) { return attributes.get(k) ?? null; },
    removeAttribute(k) { attributes.delete(k); },
    addEventListener(k, fn) { if (!listeners.has(k)) listeners.set(k, new Set()); listeners.get(k).add(fn); },
    removeEventListener(k, fn) { listeners.get(k)?.delete(fn); },
    dispatchEvent(e) { for (const fn of listeners.get(e.type) || []) fn(e); return true; },
    contains(target) { return this === target || this.children.some(c => c.contains(target)); },
    closest() { return this.dataset.colorValue ? this : null; },
    querySelectorAll() { return this.children.filter(c => c.dataset.colorValue); },
    getBoundingClientRect() { return { left: 20, right: 300, top: 100, bottom: 144, width: 280 }; },
    focus() { document.activeElement = this; this.dispatchEvent({ type: "focus" }); },
    setSelectionRange(start, end) { this.selectionStart = start; this.selectionEnd = end; },
    showPopover() { this.visible = true; }, hidePopover() { this.visible = false; },
  };
}
globalThis.document = Object.assign(element(), { createElement: element, body: element() });
globalThis.window = Object.assign(element(), { innerHeight: 900, innerWidth: 400, setTimeout });
const { createColorCombobox } = await import("../scripts/components/color-combobox.js");

function ui() {
  const input = element(), listbox = element(), errorElement = element();
  input.id = "petColor"; listbox.id = "petColorOptions"; errorElement.id = "petColorError";
  const control = createColorCombobox({ input, listbox, errorElement });
  const key = key => {
    const event = { type: "keydown", key, preventDefault() {}, stopPropagation() { this.stopped = true; } };
    input.dispatchEvent(event);
    return event;
  };
  const type = value => { input.value = value; input.selectionStart = input.selectionEnd = value.length; input.dispatchEvent(new Event("input")); };
  return { input, listbox, errorElement, control, key, type, labels: () => listbox.children.map(c => c.textContent) };
}

test("focus opens Black, White, Brown, Gray without selecting; scrolling stays inside the list", () => {
  const f = ui(); f.input.focus();
  assert.deepEqual(f.labels().slice(0, 4), ["Black", "White", "Brown", "Gray"]);
  assert.equal(f.input.getAttribute("aria-expanded"), "true");
  assert.equal(f.input.getAttribute("aria-activedescendant"), null);
  assert.equal(f.input.value, "");
  for (let i = 0; i < 5; i++) f.key("ArrowDown");
  assert.equal(f.listbox.scrollTop, 40);
  assert.equal(document.activeElement, f.input);
});

test("filtering is case insensitive and whitespace tolerant; custom colors remain valid", () => {
  const f = ui(); f.type("  BR  ");
  assert.equal(f.labels()[0], "Brown");
  assert.ok(f.labels().includes("Brindle"));
  for (const color of ["Light Brown", "Dark Gray", "Black & White", "White and Brown", "Cream", "Blue-gray", "Brown/White", "Gris bleuté"]) {
    f.type(color); assert.equal(f.control.getValidationMessage(), "");
  }
  f.type("  White   and  Brown  ");
  assert.equal(f.control.getValue(), "White and Brown");
  f.type("Custom sable coat"); assert.deepEqual(f.labels(), []);
  assert.equal(f.control.showValidation(), true);
});

test("keyboard selects only the active option; Escape, Tab, blur and clicking reopen behave normally", () => {
  const f = ui(); f.input.focus(); f.key("Enter"); assert.equal(f.input.value, "");
  f.key("ArrowDown"); f.key("ArrowDown"); f.key("Enter");
  assert.equal(f.input.value, "White"); assert.equal(f.input.getAttribute("aria-expanded"), "false");
  f.input.dispatchEvent(new Event("click")); assert.equal(f.input.getAttribute("aria-expanded"), "true");
  assert.equal(f.key("Escape").stopped, true, "Escape closes the popup before it can dismiss the pet modal");
  assert.equal(f.input.getAttribute("aria-activedescendant"), null);
  f.input.dispatchEvent(new Event("click")); f.key("Tab"); assert.equal(f.input.getAttribute("aria-expanded"), "false");
  f.input.dispatchEvent(new Event("click")); f.input.dispatchEvent({ type: "blur", relatedTarget: null });
  assert.equal(f.input.getAttribute("aria-expanded"), "false");
});

test("pointer selection updates form listeners and closes without moving focus into the list", () => {
  const f = ui(); let changed = "";
  f.input.addEventListener("change", () => { changed = f.input.value; });
  f.input.focus(); const option = f.listbox.children[2];
  let prevented = false;
  f.listbox.dispatchEvent({ type: "pointerdown", target: option, preventDefault() { prevented = true; } });
  assert.ok(prevented);
  f.listbox.dispatchEvent({ type: "click", target: option });
  assert.equal(changed, "Brown"); assert.equal(document.activeElement, f.input);
  assert.equal(f.input.getAttribute("aria-expanded"), "false");
});

test("typing, paste and autofill remove digits without clearing letters; direct invalid values cannot save", () => {
  const f = ui();
  for (const [value, expected] of [["Brown123", "Brown"], ["456White", "White"], ["123", ""], ["Brown１２", "Brown"]]) {
    f.type(value); assert.equal(f.input.value, expected); assert.match(f.errorElement.textContent, /numbers/i);
  }
  for (const value of ["Brown123", "12.5", "---", "& /", "x".repeat(51), "#Brown"]) {
    f.input.value = value; assert.notEqual(f.control.getValidationMessage(), "");
  }
  f.type(""); assert.equal(f.control.showValidation(), true);
  assert.equal(f.input.getAttribute("aria-invalid"), "false");
});

test("saved colors survive edit initialization; unchanged legacy colors survive unrelated edits only", () => {
  const f = ui();
  f.control.setValue("White"); f.input.focus(); assert.equal(f.input.value, "White");
  f.control.setValue("Brown123", { preserveLegacy: true });
  assert.equal(f.control.getValue(), "Brown123"); assert.equal(f.control.getValidationMessage(), "");
  f.input.focus(); f.input.dispatchEvent({ type: "blur" }); assert.equal(f.input.value, "Brown123");
  f.input.value = "Brown456"; assert.notEqual(f.control.getValidationMessage(), "");
  f.control.reset(); assert.equal(f.control.getValue(), "");
  f.input.value = "Brown123"; assert.notEqual(f.control.getValidationMessage(), "");
});

test("initializing the same input twice reuses the control", () => {
  const f = ui();
  assert.equal(createColorCombobox({ input: f.input, listbox: f.listbox, errorElement: f.errorElement }), f.control);
});
