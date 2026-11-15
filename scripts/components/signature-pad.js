export function createSignaturePad(canvas, onChange) {
  const context = canvas.getContext('2d');
  let strokes = [];
  let activePointer = null;
  let currentStroke = null;
  canvas.width = 900;
  canvas.height = 360;
  canvas.style.touchAction = 'none';
  context.strokeStyle = '#1e293b';
  context.lineWidth = 3;
  context.lineCap = 'round';
  context.lineJoin = 'round';

  function redraw() {
    context.clearRect(0, 0, canvas.width, canvas.height);
    for (const stroke of strokes) {
      context.beginPath();
      stroke.forEach(([x, y], index) => index ? context.lineTo(x, y) : context.moveTo(x, y));
      context.stroke();
    }
  }

  function point(event) {
    const rect = canvas.getBoundingClientRect();
    return [
      Math.round(Math.max(0, Math.min(900, (event.clientX - rect.left) * 900 / rect.width)) * 100) / 100,
      Math.round(Math.max(0, Math.min(360, (event.clientY - rect.top) * 360 / rect.height)) * 100) / 100,
    ];
  }

  function finish(event) {
    if (event.pointerId !== activePointer) return;
    if (currentStroke.length < 2) strokes.pop();
    const pointer = activePointer;
    activePointer = null;
    currentStroke = null;
    if (canvas.hasPointerCapture(pointer)) canvas.releasePointerCapture(pointer);
    redraw();
    onChange();
  }

  canvas.addEventListener('pointerdown', (event) => {
    if (activePointer !== null || event.button !== 0 || strokes.length >= 100) return;
    event.preventDefault();
    activePointer = event.pointerId;
    currentStroke = [point(event)];
    strokes.push(currentStroke);
    canvas.setPointerCapture(event.pointerId);
    onChange();
  });
  canvas.addEventListener('pointermove', (event) => {
    if (event.pointerId !== activePointer || currentStroke.length >= 1000) return;
    event.preventDefault();
    const next = point(event);
    const previous = currentStroke.at(-1);
    if (Math.hypot(next[0] - previous[0], next[1] - previous[1]) < 1) return;
    currentStroke.push(next);
    context.beginPath();
    context.moveTo(...previous);
    context.lineTo(...next);
    context.stroke();
  });
  ['pointerup', 'pointercancel', 'lostpointercapture'].forEach((name) => canvas.addEventListener(name, finish));

  return {
    get strokes() { return strokes.map(stroke => stroke.map(point => [...point])); },
    get drawing() { return activePointer !== null; },
    get drawn() {
      let length = 0;
      for (const stroke of strokes) {
        for (let i = 1; i < stroke.length; i++) length += Math.hypot(stroke[i][0] - stroke[i - 1][0], stroke[i][1] - stroke[i - 1][1]);
      }
      return length >= 3;
    },
    clear() {
      const pointer = activePointer;
      activePointer = null;
      currentStroke = null;
      if (pointer !== null && canvas.hasPointerCapture(pointer)) canvas.releasePointerCapture(pointer);
      strokes = [];
      redraw();
      onChange();
    },
    restore(saved) {
      strokes = Array.isArray(saved) ? saved : [];
      redraw();
    },
  };
}
