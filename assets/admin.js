(function () {
	'use strict';
	document.addEventListener('click', function (e) {
		var btn = e.target.closest('[data-asc-copy="prev"]');
		if (!btn) return;
		e.preventDefault();
		var prev = btn.previousElementSibling;
		while (prev && prev.tagName !== 'PRE') {
			prev = prev.previousElementSibling;
		}
		if (!prev) return;
		var text = prev.textContent;
		if (navigator.clipboard && navigator.clipboard.writeText) {
			navigator.clipboard.writeText(text).then(function () {
				btn.dataset.label = btn.textContent;
				btn.textContent = 'Copied!';
				setTimeout(function () {
					btn.textContent = btn.dataset.label || 'Copy';
				}, 1500);
			});
		} else {
			var ta = document.createElement('textarea');
			ta.value = text;
			document.body.appendChild(ta);
			ta.select();
			try { document.execCommand('copy'); } catch (err) {}
			document.body.removeChild(ta);
		}
	});
})();
