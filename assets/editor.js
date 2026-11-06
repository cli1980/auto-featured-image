(function (wp) {
	var el = wp.element.createElement;
	var Panel = (wp.editor && wp.editor.PluginDocumentSettingPanel) || wp.editPost.PluginDocumentSettingPanel;

	function AfiPanel() {
		var mode = wp.data.useSelect(function (select) {
			return (select('core/editor').getEditedPostAttribute('meta') || {})._afi_mode || '';
		}, []);
		var editPost = wp.data.useDispatch('core/editor').editPost;
		var checked = mode === 'on';

		return el(
			Panel,
			{ name: 'afi-toggle', title: 'Featured Image by Tag' },
			el(wp.components.CheckboxControl, {
				label: 'Use auto featured image',
				help: 'Generated each time the post is saved (drafts too), replacing the current featured image. Unticking removes the generated image.',
				checked: checked,
				onChange: function (v) {
					editPost({ meta: { _afi_mode: v ? 'on' : 'off' } });
				}
			})
		);
	}

	wp.plugins.registerPlugin('afi-toggle', { render: AfiPanel });
})(window.wp);
