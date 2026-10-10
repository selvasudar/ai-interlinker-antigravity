/**
 * Block editor panel: "Internal links" for the post being edited.
 *
 * Shows the page's primary keyword (editable), link counts, and pending suggestions with
 * accept / apply / dismiss, plus "Find link suggestions for this post" with a cost estimate.
 */
import apiFetch from '@wordpress/api-fetch';
import {
	Button,
	Modal,
	Notice,
	Spinner,
	TextControl,
	Flex,
	FlexItem,
} from '@wordpress/components';
import { useDispatch, useSelect } from '@wordpress/data';
import { PluginDocumentSettingPanel } from '@wordpress/editor';
import { useCallback, useEffect, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { registerPlugin } from '@wordpress/plugins';
import { store as noticesStore } from '@wordpress/notices';

const NS = '/ai-internal-link-suggester/v1';

const money = ( micros ) => {
	if ( micros === null || micros === undefined ) {
		return __( 'unknown', 'ai-internal-link-suggester' );
	}
	const usd = micros / 1000000;
	return '$' + ( usd > 0 && usd < 0.01 ? usd.toFixed( 4 ) : usd.toFixed( 2 ) );
};

function InternalLinksPanel() {
	const { postId, isDirty, isSaving } = useSelect( ( select ) => {
		const editor = select( 'core/editor' );
		return {
			postId: editor.getCurrentPostId(),
			isDirty: editor.isEditedPostDirty(),
			isSaving: editor.isSavingPost(),
		};
	}, [] );
	const { createSuccessNotice } = useDispatch( noticesStore );

	const [ data, setData ] = useState( null );
	const [ error, setError ] = useState( '' );
	const [ keyword, setKeyword ] = useState( '' );
	const [ busy, setBusy ] = useState( '' );
	const [ confirmApply, setConfirmApply ] = useState( null );
	const [ estimate, setEstimate ] = useState( null );

	const load = useCallback( () => {
		if ( ! postId ) {
			return;
		}
		apiFetch( { path: `${ NS }/posts/${ postId }/links` } )
			.then( ( res ) => {
				setData( res );
				setKeyword( res.keyword ? res.keyword.primary_keyword : '' );
				setError( '' );
			} )
			.catch( ( e ) => setError( e.message ) );
	}, [ postId ] );

	useEffect( () => {
		load();
	}, [ load, isSaving ] );

	const saveKeyword = () => {
		setBusy( 'keyword' );
		apiFetch( { path: `${ NS }/keywords/${ postId }`, method: 'POST', data: { keyword } } )
			.then( () => load() )
			.catch( ( e ) => setError( e.message ) )
			.finally( () => setBusy( '' ) );
	};

	const setStatus = ( id, status ) => {
		setBusy( 'status-' + id );
		apiFetch( { path: `${ NS }/suggestions/${ id }`, method: 'POST', data: { status } } )
			.then( () => load() )
			.catch( ( e ) => setError( e.message ) )
			.finally( () => setBusy( '' ) );
	};

	const apply = ( id ) => {
		setConfirmApply( null );
		setBusy( 'apply-' + id );
		apiFetch( { path: `${ NS }/suggestions/apply`, method: 'POST', data: { ids: [ id ], confirmed: true } } )
			.then( ( res ) => {
				const r = res.results[ 0 ];
				if ( r && r.status === 'applied' ) {
					// The page was saved on the server; reload so the editor never holds (and re-saves) the old content.
					// Applying is only possible without unsaved edits, so nothing is lost.
					createSuccessNotice( __( 'Link added and saved as a new revision. Reloading the editor…', 'ai-internal-link-suggester' ), { type: 'snackbar' } );
					window.setTimeout( () => window.location.reload(), 1200 );
					return;
				} else if ( r ) {
					setError( r.message );
				}
				load();
			} )
			.catch( ( e ) => setError( e.message ) )
			.finally( () => setBusy( '' ) );
	};

	const findSuggestions = () => {
		setBusy( 'estimate' );
		apiFetch( { path: `${ NS }/estimate/run?type=regenerate&post_ids[]=${ postId }` } )
			.then( ( e ) => setEstimate( e ) )
			.catch( ( e ) => setError( e.message ) )
			.finally( () => setBusy( '' ) );
	};

	const runRegenerate = () => {
		setEstimate( null );
		setBusy( 'run' );
		const loop = ( runId ) =>
			apiFetch( { path: `${ NS }/runs/${ runId }/process`, method: 'POST' } ).then( ( p ) =>
				p.status === 'running' ? loop( runId ) : p
			);
		apiFetch( { path: `${ NS }/runs`, method: 'POST', data: { type: 'regenerate', post_ids: [ postId ], confirmed: true } } )
			.then( ( p ) => ( p.status === 'running' ? loop( p.run_id ) : p ) )
			.then( ( p ) => {
				if ( p.status === 'budget' ) {
					setError( __( 'AI spending is paused (budget reached or price unknown). See Settings → AI & Cost.', 'ai-internal-link-suggester' ) );
				}
				load();
			} )
			.catch( ( e ) => setError( e.message ) )
			.finally( () => setBusy( '' ) );
	};

	if ( ! data && ! error ) {
		return (
			<PluginDocumentSettingPanel name="ailsug-panel" title={ __( 'Internal links', 'ai-internal-link-suggester' ) }>
				<Spinner />
			</PluginDocumentSettingPanel>
		);
	}

	return (
		<PluginDocumentSettingPanel name="ailsug-panel" title={ __( 'Internal links', 'ai-internal-link-suggester' ) }>
			{ error && (
				<Notice status="warning" onRemove={ () => setError( '' ) }>
					{ error }
				</Notice>
			) }
			{ data && ! data.indexed && (
				<p>{ __( 'This page is added to the link index after it is published.', 'ai-internal-link-suggester' ) }</p>
			) }
			{ data && data.indexed && (
				<>
					<TextControl
						__nextHasNoMarginBottom
						__next40pxDefaultSize
						label={ __( 'Primary keyword', 'ai-internal-link-suggester' ) }
						help={ __( 'The phrase people search to find this page. Other pages link here using it.', 'ai-internal-link-suggester' ) }
						value={ keyword }
						onChange={ setKeyword }
					/>
					<Button variant="secondary" size="small" onClick={ saveKeyword } isBusy={ busy === 'keyword' } disabled={ !! busy }>
						{ __( 'Save keyword', 'ai-internal-link-suggester' ) }
					</Button>
					<p>
						{ sprintf(
							/* translators: 1: links in, 2: links out. */
							__( 'Links to this page: %1$d · Links from this page: %2$d', 'ai-internal-link-suggester' ),
							data.keyword.inbound_count,
							data.keyword.outbound_count
						) }
					</p>
					{ data.keyword.inbound_count === 0 && (
						<Notice status="info" isDismissible={ false }>
							{ __( 'No other page links here yet (orphan page).', 'ai-internal-link-suggester' ) }
						</Notice>
					) }
				</>
			) }

			{ data && data.view_only && (
				<Notice status="info" isDismissible={ false }>
					{ __( 'This page uses a page builder. Suggestions are view only: copy the link and add it in the builder.', 'ai-internal-link-suggester' ) }
				</Notice>
			) }

			{ data && data.suggestions.length > 0 && (
				<ul className="ailsug-panel-list">
					{ data.suggestions.map( ( s ) => (
						<li key={ s.id }>
							<p>
								<mark>{ s.anchor_text }</mark> → <a href={ s.target.url } target="_blank" rel="noreferrer">{ s.target.title }</a>
								<br />
								<small>
									{ sprintf( /* translators: %d: score. */ __( 'Relevance %d', 'ai-internal-link-suggester' ), s.score ) }
									{ s.reason ? ' · ' + s.reason : '' }
								</small>
							</p>
							<Flex justify="flex-start" wrap>
								{ s.status === 'pending' && (
									<FlexItem>
										<Button variant="secondary" size="small" onClick={ () => setStatus( s.id, 'accepted' ) } disabled={ !! busy }>
											{ __( 'Accept', 'ai-internal-link-suggester' ) }
										</Button>
									</FlexItem>
								) }
								{ ! data.view_only && (
									<FlexItem>
										<Button
											variant="primary"
											size="small"
											onClick={ () => setConfirmApply( s ) }
											disabled={ !! busy || isDirty }
											isBusy={ busy === 'apply-' + s.id }
											label={ isDirty ? __( 'Save your changes first', 'ai-internal-link-suggester' ) : undefined }
											showTooltip={ isDirty }
										>
											{ __( 'Apply…', 'ai-internal-link-suggester' ) }
										</Button>
									</FlexItem>
								) }
								<FlexItem>
									<Button variant="tertiary" size="small" onClick={ () => setStatus( s.id, 'rejected' ) } disabled={ !! busy }>
										{ __( 'Dismiss', 'ai-internal-link-suggester' ) }
									</Button>
								</FlexItem>
							</Flex>
						</li>
					) ) }
				</ul>
			) }
			{ data && data.indexed && data.suggestions.length === 0 && (
				<p>{ __( 'No link suggestions for this page yet.', 'ai-internal-link-suggester' ) }</p>
			) }
			{ isDirty && data && data.suggestions.length > 0 && (
				<p><small>{ __( 'Save your changes before applying a link.', 'ai-internal-link-suggester' ) }</small></p>
			) }

			{ data && data.indexed && data.can_run && data.ai && (
				<Button variant="secondary" onClick={ findSuggestions } isBusy={ busy === 'estimate' || busy === 'run' } disabled={ !! busy }>
					{ busy === 'run' ? __( 'AI is reading this page…', 'ai-internal-link-suggester' ) : __( 'Find link suggestions for this post…', 'ai-internal-link-suggester' ) }
				</Button>
			) }
			{ data && data.indexed && ! data.can_run && (
				<p><small>{ __( 'Ask an administrator to scan for new link suggestions.', 'ai-internal-link-suggester' ) }</small></p>
			) }

			{ confirmApply && (
				<Modal title={ __( 'Add this link?', 'ai-internal-link-suggester' ) } onRequestClose={ () => setConfirmApply( null ) }>
					<p>
						{ sprintf(
							/* translators: 1: link text, 2: target title. */
							__( 'The words "%1$s" will link to "%2$s". Only the link is added; the page is saved as a new revision and you can undo it from Internal Links → Suggestions.', 'ai-internal-link-suggester' ),
							confirmApply.anchor_text,
							confirmApply.target.title
						) }
					</p>
					<Flex justify="flex-end">
						<Button variant="tertiary" onClick={ () => setConfirmApply( null ) }>{ __( 'Cancel', 'ai-internal-link-suggester' ) }</Button>
						<Button variant="primary" onClick={ () => apply( confirmApply.id ) }>{ __( 'Add link', 'ai-internal-link-suggester' ) }</Button>
					</Flex>
				</Modal>
			) }

			{ estimate && (
				<Modal title={ __( 'Before AI reads this page', 'ai-internal-link-suggester' ) } onRequestClose={ () => setEstimate( null ) }>
					<p>
						{ estimate.known
							? sprintf(
								/* translators: 1: low, 2: high. */
								__( 'Estimated cost: %1$s – %2$s. This is only an estimate; your provider\'s invoice is the source of truth.', 'ai-internal-link-suggester' ),
								money( estimate.low ),
								money( estimate.high )
							)
							: __( 'A cost estimate is not available because the AI model\'s price is unknown.', 'ai-internal-link-suggester' ) }
					</p>
					{ estimate.blocker && <Notice status="warning" isDismissible={ false }>{ estimate.blocker }</Notice> }
					<Flex justify="flex-end">
						<Button variant="tertiary" onClick={ () => setEstimate( null ) }>{ __( 'Cancel', 'ai-internal-link-suggester' ) }</Button>
						<Button variant="primary" onClick={ runRegenerate } disabled={ !! estimate.blocker || ! estimate.posts }>
							{ __( 'Run', 'ai-internal-link-suggester' ) }
						</Button>
					</Flex>
				</Modal>
			) }
		</PluginDocumentSettingPanel>
	);
}

registerPlugin( 'ai-internal-link-suggester', { render: InternalLinksPanel } );
