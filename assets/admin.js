jQuery(function ($) {
	var $form = $('#afi-tag-table').closest('form');
	var $table = $('#afi-tag-table tbody');
	var $img = $('#afi-preview');
	var $error = $('#afi-preview-error');
	var $spinner = $('#afi-preview-spinner');
	var timer = null;
	var pending = null;
	var objectUrl = null;

	function refreshPreview() {
		if (!$img.length) {
			return;
		}
		var data = new FormData($form[0]);
		data.append('action', 'afi_preview');
		data.append('afi_nonce', afiPreview.nonce);
		data.append('afi_preview_tag', $('#afi-preview-tag').val() || 0);
		if (pending) {
			pending.abort();
		}
		pending = new AbortController();
		$spinner.addClass('is-active');
		fetch(afiPreview.ajaxUrl, { method: 'POST', body: data, credentials: 'same-origin', signal: pending.signal })
			.then(function (r) {
				if (!r.ok) {
					return r.json().catch(function () { return {}; }).then(function (result) {
						throw new Error((result.data && result.data.message) || 'Preview failed (HTTP ' + r.status + ').');
					});
				}
				return r.blob();
			})
			.then(function (blob) {
				if (objectUrl) {
					URL.revokeObjectURL(objectUrl);
				}
				objectUrl = URL.createObjectURL(blob);
				$img.attr('src', objectUrl).show();
				$error.hide().text('');
			})
			.catch(function (e) {
				if (e.name !== 'AbortError') {
					$img.hide();
					$error.text(e.message).show();
					if (window.console) {
						console.error('AFI preview failed', e);
					}
				}
			})
			.finally(function () {
				$spinner.removeClass('is-active');
			});
	}

	function schedulePreview() {
		clearTimeout(timer);
		timer = setTimeout(refreshPreview, 400);
	}

	$('.afi-color').wpColorPicker({ change: schedulePreview, clear: schedulePreview });
	$form.on('input change', 'input, select', schedulePreview);
	$('#afi-preview-tag').on('change', schedulePreview);

	$('#afi-add-row').on('click', function () {
		var $row = $table.find('.afi-tag-row').first().clone();
		$row.find('select').val('');
		$row.find('.afi-img-id').val('');
		$row.find('.afi-img-preview').attr('src', '').hide();
		$table.append($row);
	});

	$table.on('click', '.afi-remove', function () {
		var $rows = $table.find('.afi-tag-row');
		var $row = $(this).closest('tr');
		if ($rows.length > 1) {
			$row.remove();
		} else {
			$row.find('select').val('');
			$row.find('.afi-img-id').val('');
			$row.find('.afi-img-preview').hide();
		}
		schedulePreview();
	});

	$table.on('click', '.afi-pick', function () {
		var $row = $(this).closest('tr');
		var frame = wp.media({ title: 'Select background image', library: { type: 'image' }, multiple: false });
		frame.on('select', function () {
			var a = frame.state().get('selection').first().toJSON();
			var url = (a.sizes && a.sizes.thumbnail) ? a.sizes.thumbnail.url : a.url;
			$row.find('.afi-img-id').val(a.id);
			$row.find('.afi-img-preview').attr('src', url).show();
			schedulePreview();
		});
		frame.open();
	});

	refreshPreview();
});
