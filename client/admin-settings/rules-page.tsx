import {
	useCallback,
	useEffect,
	useMemo,
	useRef,
	useState,
} from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { dateI18n } from '@wordpress/date';
import {
	Button,
	Dialog,
	EmptyState,
	Icon,
	Notice,
	Stack,
	Tabs,
	Text,
	VisuallyHidden,
} from '@wordpress/ui';
import { Button as ComponentsButton } from '@wordpress/components';
import { notAllowed, published } from '@wordpress/icons';
import { DataForm, DataViews } from '@wordpress/dataviews/wp';
import type { Action, Field, View } from '@wordpress/dataviews';
import { useDispatch } from '@wordpress/data';
import { store as noticesStore } from '@wordpress/notices';
import { Link } from 'react-router-dom';

import type { Rule, RulesQuery } from './data/rules-store';
import { useRules } from './hooks/use-rules';
import { getFraudProtectionRoute } from './navigation';
import {
	getRuleFormFields,
	ruleForm,
	RuleFormDrawer,
} from './components/rule-form-drawer';
import type { RuleFormData } from './components/rule-form-drawer';

const rootSettingsHref = getFraudProtectionRoute( '/' );
const browserTimeZone = Intl.DateTimeFormat().resolvedOptions().timeZone;

const ruleActions = [
	{ value: 'allow', label: __( 'Allow', 'woocommerce-fraud-protection' ) },
	{ value: 'block', label: __( 'Block', 'woocommerce-fraud-protection' ) },
];
const ruleTypes = [
	{ value: 'email', label: __( 'Email', 'woocommerce-fraud-protection' ) },
	{ value: 'ip', label: __( 'IP', 'woocommerce-fraud-protection' ) },
];

const fields: Field< Rule >[] = [
	{
		id: 'action',
		label: __( 'Action', 'woocommerce-fraud-protection' ),
		type: 'text',
		elements: ruleActions,
		filterBy: { operators: [ 'is' ] },
		render: ( { item } ) => (
			<Stack
				className={ `wc-fraud-protection-rules__action wc-fraud-protection-rules__action--${ item.action }` }
				direction="row"
				align="center"
				gap="xs"
				render={ <span /> }
			>
				<Icon
					className="wc-fraud-protection-rules__action-icon"
					icon={ item.action === 'allow' ? published : notAllowed }
					aria-hidden="true"
					size={ 18 }
				/>
				{ item.action === 'allow'
					? __( 'Allow', 'woocommerce-fraud-protection' )
					: __( 'Block', 'woocommerce-fraud-protection' ) }
			</Stack>
		),
	},
	{
		id: 'value',
		label: __( 'Value', 'woocommerce-fraud-protection' ),
		type: 'text',
		filterBy: { operators: [ 'is' ] },
		render: ( { item } ) => (
			<Text
				className="wc-fraud-protection-rules__value"
				variant="body-md"
			>
				{ item.value }
			</Text>
		),
	},
	{
		id: 'type',
		label: __( 'Rule type', 'woocommerce-fraud-protection' ),
		type: 'text',
		elements: ruleTypes,
		filterBy: { operators: [ 'is' ] },
		render: ( { item } ) =>
			item.type === 'email'
				? __( 'Email', 'woocommerce-fraud-protection' )
				: __( 'IP', 'woocommerce-fraud-protection' ),
	},
	{
		id: 'created_at',
		label: __( 'Created', 'woocommerce-fraud-protection' ),
		header: __( 'Created', 'woocommerce-fraud-protection' ),
		type: 'date',
		filterBy: { operators: [ 'between' ] },
		render: ( { item } ) =>
			dateI18n( 'j M Y', item.created_at, browserTimeZone ),
	},
];

export const getUtcDateFilterBound = (
	value: string,
	endOfDay: boolean
): string | undefined => {
	const date = value.slice( 0, 10 );
	if ( ! /^\d{4}-\d{2}-\d{2}$/.test( date ) ) {
		return undefined;
	}

	const [ year, month, day ] = date.split( '-' ).map( Number );
	const localBound = new Date(
		year,
		month - 1,
		day,
		endOfDay ? 23 : 0,
		endOfDay ? 59 : 0,
		endOfDay ? 59 : 0
	);
	if (
		localBound.getFullYear() !== year ||
		localBound.getMonth() !== month - 1 ||
		localBound.getDate() !== day
	) {
		return undefined;
	}

	return localBound.toISOString().replace( '.000Z', 'Z' );
};

const getFields = ( sortField?: string ): Field< Rule >[] =>
	fields.map( ( field ) => {
		const header = field.header ?? field.label ?? field.id;

		return {
			...field,
			header: (
				<>
					{ header }
					{ field.id !== sortField && (
						<span aria-hidden="true"> ↓</span>
					) }
				</>
			),
		};
	} );

export const getQueryFromView = ( view: View ): RulesQuery => {
	const query: RulesQuery = {
		page: view.page ?? 1,
		perPage: view.perPage ?? 20,
		orderby: view.sort?.field ?? 'created_at',
		order: view.sort?.direction ?? 'desc',
	};
	( view.filters ?? [] ).forEach( ( filter ) => {
		if ( filter.field === 'action' ) {
			query.action = Array.isArray( filter.value )
				? String( filter.value[ 0 ] ?? '' )
				: String( filter.value ?? '' );
		}
		if ( filter.field === 'type' ) {
			query.type = Array.isArray( filter.value )
				? String( filter.value[ 0 ] ?? '' )
				: String( filter.value ?? '' );
		}
		if ( filter.field === 'value' && typeof filter.value === 'string' ) {
			query.value = filter.value;
		}
		if ( filter.field === 'created_at' && Array.isArray( filter.value ) ) {
			query.from =
				typeof filter.value[ 0 ] === 'string'
					? getUtcDateFilterBound( filter.value[ 0 ], false )
					: undefined;
			query.to =
				typeof filter.value[ 1 ] === 'string'
					? getUtcDateFilterBound( filter.value[ 1 ], true )
					: undefined;
		}
	} );
	return query;
};

const getActionTab = ( view: View ): 'all' | 'allow' | 'block' => {
	const actionFilter = ( view.filters ?? [] ).find(
		( filter ) => filter.field === 'action'
	);
	const value = Array.isArray( actionFilter?.value )
		? actionFilter?.value[ 0 ]
		: actionFilter?.value;

	return value === 'allow' || value === 'block' ? value : 'all';
};

const getLoadErrorMessage = ( error: string | null ): string | null => {
	if ( ! error ) {
		return null;
	}

	const prefix = __(
		'The fraud prevention rules could not be loaded.',
		'woocommerce-fraud-protection'
	);
	if ( error.startsWith( prefix ) ) {
		return error;
	}

	return sprintf(
		/* translators: %s: Error returned by the server. */
		__(
			'The fraud prevention rules could not be loaded. %s',
			'woocommerce-fraud-protection'
		),
		error
	);
};

export function RulesPage() {
	const [ isDrawerOpen, setIsDrawerOpen ] = useState( false );
	const [ editingRule, setEditingRule ] = useState< Rule | undefined >();
	const [ deletingRule, setDeletingRule ] = useState< Rule | undefined >();
	const [ detailError, setDetailError ] = useState< string | null >( null );
	const [ deleteError, setDeleteError ] = useState< string | null >( null );
	const [ isDeleting, setIsDeleting ] = useState( false );
	const detailRequest = useRef( 0 );
	const [ view, setView ] = useState< View >( {
		type: 'table' as const,
		page: 1,
		perPage: 20,
		sort: { field: 'created_at', direction: 'desc' },
		filters: [],
		fields: [ 'action', 'value', 'type', 'created_at' ],
		layout: {
			styles: {
				action: { width: '25%' },
				value: { width: '25%' },
				type: { width: '25%' },
				created_at: { width: '25%' },
			},
		},
	} );
	const {
		deleteRule,
		error,
		isLoading,
		requestRule,
		requestRules,
		rules,
		totalItems,
		totalPages,
	} = useRules();
	const noticesDispatch = useDispatch( noticesStore ) as {
		createSuccessNotice?: (
			message: string,
			options: { type: string }
		) => void;
	} | null;
	const visibleFields = useMemo(
		() => getFields( view.sort?.field ),
		[ view.sort?.field ]
	);
	const deletingRuleData = useMemo< RuleFormData | undefined >(
		() =>
			deletingRule
				? {
						action: deletingRule.action,
						type: deletingRule.type,
						value: deletingRule.value,
				  }
				: undefined,
		[ deletingRule ]
	);
	const deletingRuleFields = useMemo(
		() =>
			deletingRuleData
				? getRuleFormFields( {
						type: deletingRuleData.type,
						disabled: true,
				  } )
				: [],
		[ deletingRuleData ]
	);
	const actionTab = getActionTab( view );
	const hasActiveFilters = Boolean( view.filters?.length );
	const isInitialLoading = isLoading && rules.length === 0;
	const openEditRule = useCallback(
		async ( id: number ) => {
			const request = ++detailRequest.current;
			setDetailError( null );
			try {
				const rule = await requestRule( id );
				if ( request !== detailRequest.current ) {
					return;
				}
				setEditingRule( rule );
				setIsDrawerOpen( true );
			} catch {
				if ( request === detailRequest.current ) {
					setDetailError(
						__(
							'The rule could not be loaded.',
							'woocommerce-fraud-protection'
						)
					);
				}
			}
		},
		[ requestRule ]
	);
	const actions = useMemo< Action< Rule >[] >(
		() => [
			{
				id: 'edit',
				label: __( 'Edit', 'woocommerce-fraud-protection' ),
				supportsBulk: false,
				callback: ( items ) => {
					if ( items[ 0 ] ) {
						void openEditRule( items[ 0 ].id );
					}
				},
			},
			{
				id: 'delete',
				label: __( 'Delete', 'woocommerce-fraud-protection' ),
				supportsBulk: false,
				callback: ( items ) => {
					detailRequest.current++;
					setDetailError( null );
					setDeleteError( null );
					setDeletingRule( items[ 0 ] );
				},
			},
		],
		[ openEditRule ]
	);

	useEffect( () => {
		requestRules( getQueryFromView( view ) );
	}, [ requestRules, view ] );

	const empty = useMemo(
		() => (
			<EmptyState.Root className="wc-fraud-protection-rules__empty-state">
				<EmptyState.Title>
					{ hasActiveFilters
						? __(
								'No matching rules',
								'woocommerce-fraud-protection'
						  )
						: __( 'No rules', 'woocommerce-fraud-protection' ) }
				</EmptyState.Title>
				<EmptyState.Description>
					{ hasActiveFilters
						? __(
								'Try changing or removing your filters.',
								'woocommerce-fraud-protection'
						  )
						: __(
								'Any custom rules you create will appear here.',
								'woocommerce-fraud-protection'
						  ) }
				</EmptyState.Description>
			</EmptyState.Root>
		),
		[ hasActiveFilters ]
	);
	const loadErrorMessage = getLoadErrorMessage( error );
	return (
		<Stack
			className="wc-fraud-protection-rules"
			direction="column"
			aria-busy={ isLoading }
			style={
				{
					'--wp-dataviews-color-background':
						'var(--wpds-color-background-surface-neutral, #fcfcfc)',
				} as React.CSSProperties
			}
		>
			{ isInitialLoading && (
				<VisuallyHidden>
					{ __( 'Loading rules', 'woocommerce-fraud-protection' ) }
				</VisuallyHidden>
			) }
			<DataViews
				data={ rules }
				actions={ isInitialLoading ? [] : actions }
				fields={ visibleFields }
				view={ view }
				onChangeView={ setView }
				isLoading={ isLoading }
				paginationInfo={ { totalItems, totalPages } }
				getItemId={ ( item ) => String( item.id ) }
				defaultLayouts={ { table: {} } }
				empty={ error ? null : empty }
				search={ false }
				config={ { perPageSizes: [ 20, 50, 100 ] } }
			>
				<Stack direction="column">
					<Stack
						className="wc-fraud-protection-rules__header"
						direction="column"
						gap="none"
						style={ {
							height: 84,
							boxSizing: 'border-box',
							padding: '12px 16px',
						} }
					>
						<Stack
							direction="row"
							justify="space-between"
							align="start"
						>
							<Text
								className="wc-fraud-protection-rules__breadcrumb"
								variant="heading-lg"
								style={ {
									display: 'flex',
									alignItems: 'center',
									gap: 8,
									margin: '0 8px 8px',
									height: 32,
									fontWeight: 500,
								} }
								render={
									<nav
										aria-label={ __(
											'Breadcrumb',
											'woocommerce-fraud-protection'
										) }
									/>
								}
							>
								<Link to={ rootSettingsHref }>
									{ __(
										'Fraud prevention',
										'woocommerce-fraud-protection'
									) }
								</Link>
								<span aria-hidden="true">/</span>
								<span aria-current="page">
									{ __(
										'Rules',
										'woocommerce-fraud-protection'
									) }
								</span>
							</Text>
							<Button
								variant="solid"
								size="compact"
								onClick={ () => {
									detailRequest.current++;
									setDetailError( null );
									setEditingRule( undefined );
									setIsDrawerOpen( true );
								} }
							>
								{ __(
									'Create rule',
									'woocommerce-fraud-protection'
								) }
							</Button>
						</Stack>
						<Text
							className="wc-fraud-protection-rules__description"
							variant="body-md"
							style={ {
								color: 'var(--wpds-color-foreground-content-neutral-weak)',
								marginInline: 8,
							} }
							render={ <p /> }
						>
							{ __(
								'Rules that always let checkout attempts through or always block them, no matter what our fraud detection decides.',
								'woocommerce-fraud-protection'
							) }
						</Text>
					</Stack>
					<Tabs.Root
						value={ actionTab }
						onValueChange={ ( value ) => {
							const filters = ( view.filters ?? [] ).filter(
								( filter ) => filter.field !== 'action'
							);
							if ( value !== 'all' ) {
								filters.push( {
									field: 'action',
									operator: 'is',
									value,
								} );
							}
							setView( {
								...view,
								page: 1,
								filters,
							} );
						} }
					>
						<Stack
							className="wc-fraud-protection-rules__toolbar"
							direction="row"
							align="center"
							justify="space-between"
							style={ {
								height: 40,
								boxSizing: 'border-box',
								padding: '0 24px',
							} }
						>
							<Tabs.List
								variant="minimal"
								style={ { height: 40, gap: 12 } }
							>
								<Tabs.Tab value="all" style={ { height: 40 } }>
									{ __(
										'All',
										'woocommerce-fraud-protection'
									) }
								</Tabs.Tab>
								<Tabs.Tab
									value="allow"
									style={ { height: 40 } }
								>
									{ __(
										'Allow',
										'woocommerce-fraud-protection'
									) }
								</Tabs.Tab>
								<Tabs.Tab
									value="block"
									style={ { height: 40 } }
								>
									{ __(
										'Block',
										'woocommerce-fraud-protection'
									) }
								</Tabs.Tab>
							</Tabs.List>
							<Stack direction="row" align="center" gap="sm">
								<DataViews.FiltersToggle />
								<DataViews.ViewConfig />
							</Stack>
						</Stack>
						{ ( loadErrorMessage || detailError ) && (
							<Stack
								direction="column"
								style={ {
									marginBlock: 'var(--wpds-dimension-gap-lg)',
									marginInline:
										'var(--wpds-dimension-padding-2xl)',
								} }
							>
								<Notice.Root intent="error">
									<Notice.Description>
										{ detailError || loadErrorMessage }
									</Notice.Description>
								</Notice.Root>
							</Stack>
						) }
						<Tabs.Panel value="all">
							{ actionTab === 'all' && (
								<>
									<DataViews.FiltersToggled className="wc-fraud-protection-rules__filters" />
									<DataViews.Layout />
									<DataViews.Pagination />
								</>
							) }
						</Tabs.Panel>
						<Tabs.Panel value="allow">
							{ actionTab === 'allow' && (
								<>
									<DataViews.FiltersToggled className="wc-fraud-protection-rules__filters" />
									<DataViews.Layout />
									<DataViews.Pagination />
								</>
							) }
						</Tabs.Panel>
						<Tabs.Panel value="block">
							{ actionTab === 'block' && (
								<>
									<DataViews.FiltersToggled className="wc-fraud-protection-rules__filters" />
									<DataViews.Layout />
									<DataViews.Pagination />
								</>
							) }
						</Tabs.Panel>
					</Tabs.Root>
				</Stack>
			</DataViews>
			<RuleFormDrawer
				open={ isDrawerOpen }
				rule={ editingRule }
				onClose={ () => {
					detailRequest.current++;
					setIsDrawerOpen( false );
					setEditingRule( undefined );
				} }
				onViewRule={ openEditRule }
			/>
			<Dialog.Root
				open={ Boolean( deletingRule ) }
				onOpenChange={ ( open ) => {
					if ( ! open && ! isDeleting ) {
						setDeletingRule( undefined );
						setDeleteError( null );
					}
				} }
			>
				<Dialog.Popup
					size="small"
					portal={
						<Dialog.Portal
							style={
								{
									'--wp-ui-dialog-z-index': 100000,
								} as React.CSSProperties
							}
						/>
					}
				>
					<Dialog.Header>
						<Dialog.Title>
							{ __(
								'Delete rule',
								'woocommerce-fraud-protection'
							) }
						</Dialog.Title>
						<Dialog.CloseIcon
							label={ __(
								'Close',
								'woocommerce-fraud-protection'
							) }
						/>
					</Dialog.Header>
					<Dialog.Content>
						<Stack direction="column" gap="xl">
							<Dialog.Description>
								{ __(
									'This rule will no longer apply to future checkout attempts. Past attempts won’t be affected.',
									'woocommerce-fraud-protection'
								) }
							</Dialog.Description>
							{ deletingRuleData && (
								<DataForm< RuleFormData >
									data={ deletingRuleData }
									fields={ deletingRuleFields }
									form={ ruleForm }
									onChange={ () => undefined }
								/>
							) }
							{ deleteError && (
								<Notice.Root intent="error">
									<Notice.Description>
										{ deleteError }
									</Notice.Description>
								</Notice.Root>
							) }
						</Stack>
					</Dialog.Content>
					<Dialog.Footer>
						<ComponentsButton
							variant="tertiary"
							disabled={ isDeleting }
							onClick={ () => setDeletingRule( undefined ) }
						>
							{ __( 'Cancel', 'woocommerce-fraud-protection' ) }
						</ComponentsButton>
						<ComponentsButton
							variant="primary"
							isDestructive
							isBusy={ isDeleting }
							disabled={ isDeleting }
							onClick={ async () => {
								if ( ! deletingRule ) {
									return;
								}
								setDeleteError( null );
								setIsDeleting( true );
								try {
									await deleteRule(
										deletingRule.id,
										'rules'
									);
									setDeletingRule( undefined );
									noticesDispatch?.createSuccessNotice?.(
										__(
											'Rule deleted',
											'woocommerce-fraud-protection'
										),
										{ type: 'snackbar' }
									);
								} catch ( caughtError ) {
									setDeleteError(
										typeof caughtError === 'object' &&
											caughtError !== null &&
											'message' in caughtError &&
											typeof caughtError.message ===
												'string'
											? caughtError.message
											: __(
													'The rule could not be deleted.',
													'woocommerce-fraud-protection'
											  )
									);
								} finally {
									setIsDeleting( false );
								}
							} }
						>
							{ __( 'Delete', 'woocommerce-fraud-protection' ) }
						</ComponentsButton>
					</Dialog.Footer>
				</Dialog.Popup>
			</Dialog.Root>
		</Stack>
	);
}
