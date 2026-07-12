document.addEventListener('click', function (event) {
  var trigger = event.target.closest('[data-copy-target]');
  if (!trigger) return;
  var target = document.querySelector(trigger.getAttribute('data-copy-target'));
  if (!target || !navigator.clipboard) return;
  navigator.clipboard.writeText(target.textContent || target.value || '');
});