/**
 * Last Reviewed sidebar panel for ec_doc.
 *
 * Adds a PluginDocumentSettingPanel with a date input and a
 * "Mark reviewed today" button. Reads/writes the
 * `_ec_doc_last_reviewed` post meta (Y-m-d string) via the core
 * editor entity store. No build step — uses wp.element.createElement.
 *
 * @package ExtraChillDocs
 * @since 0.5.0
 */
( function ( wp ) {
	'use strict';

	if ( ! wp || ! wp.plugins || ! wp.editPost || ! wp.element || ! wp.components || ! wp.data || ! wp.i18n ) {
		return;
	}

	var el = wp.element.createElement;
	var Fragment = wp.element.Fragment;
	var registerPlugin = wp.plugins.registerPlugin;
	var PluginDocumentSettingPanel = wp.editPost.PluginDocumentSettingPanel;
	var Button = wp.components.Button;
	var BaseControl = wp.components.BaseControl;
	var useSelect = wp.data.useSelect;
	var useDispatch = wp.data.useDispatch;
	var __ = wp.i18n.__;

	var META_KEY = '_ec_doc_last_reviewed';
	var POST_TYPE = 'ec_doc';

	function todayYmd() {
		var now = new Date();
		var y = now.getFullYear();
		var m = String( now.getMonth() + 1 ).padStart( 2, '0' );
		var d = String( now.getDate() ).padStart( 2, '0' );
		return y + '-' + m + '-' + d;
	}

	function LastReviewedPanel() {
		var data = useSelect(
			function ( select ) {
				var editor = select( 'core/editor' );
				return {
					postType: editor.getCurrentPostType(),
					value: ( editor.getEditedPostAttribute( 'meta' ) || {} )[ META_KEY ] || '',
				};
			},
			[]
		);

		var editPost = useDispatch( 'core/editor' ).editPost;

		// Only render on ec_doc.
		if ( data.postType !== POST_TYPE ) {
			return null;
		}

		var setValue = function ( next ) {
			var meta = {};
			meta[ META_KEY ] = next;
			editPost( { meta: meta } );
		};

		var inputId = 'ec-doc-last-reviewed-date';

		return el(
			PluginDocumentSettingPanel,
			{
				name: 'ec-doc-last-reviewed',
				title: __( 'Last reviewed', 'extrachill-docs' ),
				className: 'ec-doc-last-reviewed-panel',
			},
			el(
				BaseControl,
				{
					id: inputId,
					label: __( 'Review date', 'extrachill-docs' ),
					help: __( 'When this doc was last verified for accuracy. Leave empty if never reviewed.', 'extrachill-docs' ),
				},
				el( 'input', {
					id: inputId,
					type: 'date',
					className: 'components-text-control__input',
					value: data.value || '',
					onChange: function ( event ) {
						setValue( event.target.value || '' );
					},
				} )
			),
			el(
				'div',
				{ style: { display: 'flex', gap: '8px', marginTop: '8px' } },
				el(
					Button,
					{
						variant: 'secondary',
						onClick: function () {
							setValue( todayYmd() );
						},
					},
					__( 'Mark reviewed today', 'extrachill-docs' )
				),
				data.value
					? el(
							Button,
							{
								variant: 'tertiary',
								isDestructive: true,
								onClick: function () {
									setValue( '' );
								},
							},
							__( 'Clear', 'extrachill-docs' )
					  )
					: null
			)
		);
	}

	registerPlugin( 'extrachill-docs-last-reviewed', {
		render: LastReviewedPanel,
		icon: 'calendar-alt',
	} );
} )( window.wp );
