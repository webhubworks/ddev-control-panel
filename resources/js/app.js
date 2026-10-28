/*
 * Tooltips for anything carrying `data-tooltip`, in place of the native `title`.
 *
 * Native tooltips in this window take over a second to appear and come and go
 * unpredictably, which is no use for a row of icon buttons. This one shows after
 * a short delay, and instantly while moving from one button to the next.
 *
 * One element serves every tooltip. It is appended to <body>, outside the
 * Livewire root, so a poll's morph never touches it, and it is a manual popover,
 * so it sits in the top layer above the open menu and is not clipped by the
 * scrolling project list. The listeners are delegated to the document for the
 * same reason: morphing replaces elements, the document stays.
 */

const SHOW_DELAY = 250;

// While a tooltip was showing this recently, the next one skips the delay.
const WARM_WINDOW = 500;

const GUTTER = 6;

const tooltip = document.createElement('div');
tooltip.className = 'tooltip';
tooltip.setAttribute('popover', 'manual');
tooltip.setAttribute('role', 'tooltip');
document.body.append(tooltip);

let target = null;
let timer = null;
let hiddenAt = 0;

function show(element) {
    const text = element.dataset.tooltip;

    if (!text) {
        hide();

        return;
    }

    tooltip.textContent = text;
    tooltip.showPopover();
    position(element);
}

function position(element) {
    const anchor = element.getBoundingClientRect();
    const box = tooltip.getBoundingClientRect();

    // Below the element, flipped above when the window ends first.
    let top = anchor.bottom + GUTTER;

    if (top + box.height > window.innerHeight - GUTTER) {
        top = anchor.top - box.height - GUTTER;
    }

    const centred = anchor.left + anchor.width / 2 - box.width / 2;
    const left = Math.min(Math.max(centred, GUTTER), window.innerWidth - box.width - GUTTER);

    tooltip.style.top = `${Math.max(top, GUTTER)}px`;
    tooltip.style.left = `${left}px`;
}

function hide() {
    clearTimeout(timer);

    if (tooltip.matches(':popover-open')) {
        tooltip.hidePopover();
        hiddenAt = performance.now();
    }

    target = null;
}

function schedule(element) {
    if (element === target) {
        return;
    }

    hide();
    target = element;

    const warm = performance.now() - hiddenAt < WARM_WINDOW;

    timer = setTimeout(() => show(element), warm ? 0 : SHOW_DELAY);
}

document.addEventListener('pointerover', (event) => {
    const element = event.target.closest?.('[data-tooltip]');

    if (element) {
        schedule(element);
    } else if (target) {
        hide();
    }
});

// pointerleave does not bubble, so leaving the window is caught on the root.
document.documentElement.addEventListener('pointerleave', hide);

document.addEventListener('focusin', (event) => {
    const element = event.target.closest?.('[data-tooltip]');

    if (element?.matches(':focus-visible')) {
        schedule(element);
    }
});

document.addEventListener('focusout', hide);
document.addEventListener('pointerdown', hide, true);
document.addEventListener('keydown', hide, true);
document.addEventListener('scroll', hide, true);
window.addEventListener('blur', hide);

/*
 * A poll can change the text under the cursor (Running only flips its tooltip),
 * or remove the element altogether (Start replaces Stop once a project is down).
 * Neither fires a pointer event, so the morph itself is watched.
 */
new MutationObserver(() => {
    if (!target) {
        return;
    }

    if (!target.isConnected) {
        hide();

        return;
    }

    if (tooltip.matches(':popover-open') && tooltip.textContent !== target.dataset.tooltip) {
        show(target);
    }
}).observe(document.body, { subtree: true, childList: true, attributes: true, attributeFilter: ['data-tooltip'] });
