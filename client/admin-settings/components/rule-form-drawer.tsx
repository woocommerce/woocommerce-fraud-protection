import {
	useCallback,
	useEffect,
	useId,
	useMemo,
	useState,
} from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { DataForm, useFormValidity } from '@wordpress/dataviews/wp';
import type { DataFormControlProps, Field, Form } from '@wordpress/dataviews';
import {
	Button,
	Drawer,
	InputControl,
	Notice,
	Stack,
	Spinner,
	Text,
	ValidatedInputControl,
	ValidityIndicator,
	VisuallyHidden,
} from '@wordpress/ui';

import type { CreateRuleRequest, Rule } from '../data/rules-store';
import { useRule } from '../hooks/use-rules';
import {
	DUPLICATE_RULE_ERROR,
	useRuleMutation,
} from '../hooks/use-rule-mutation';
import { formatRuleDate } from '../rule-date';

export type RuleFormContext = {
	recordedAttemptId: number;
	type: Rule[ 'type' ];
	value: string;
	finalStatus: 'allowed' | 'blocked';
};

export type RuleFormData = Pick<
	CreateRuleRequest,
	'action' | 'type' | 'value'
>;

export const getInitialRuleFormData = (
	context?: RuleFormContext
): RuleFormData => ( {
	action:
		context?.finalStatus === undefined || context.finalStatus === 'blocked'
			? 'allow'
			: 'block',
	type: context?.type ?? 'email',
	value: context?.value ?? '',
} );

export const getRuleValuePlaceholder = ( type: Rule[ 'type' ] ): string =>
	type === 'email'
		? __( 'e.g. j.holland@gmail.com', 'woocommerce-fraud-protection' )
		: __( 'e.g. 111.111.111.111', 'woocommerce-fraud-protection' );

type RuleFormDrawerProps = {
	open: boolean;
	onClose: () => void;
	onViewRule?: ( id: number ) => void;
	context?: RuleFormContext;
	ruleId?: number;
};

function RuleValueEditControl( {
	data,
	field,
	onChange,
	hideLabelFromVision,
	validity,
	duplicateError,
	duplicateRuleId,
	onViewRule,
}: DataFormControlProps< RuleFormData > & {
	duplicateError?: string;
	duplicateRuleId?: number;
	onViewRule?: ( id: number ) => void;
} ) {
	const duplicateMessageId = useId();
	const value = field.getValue( { item: data } );
	const onValueChange = useCallback(
		( newValue: string ) =>
			onChange( field.setValue( { item: data, value: newValue } ) ),
		[ data, field, onChange ]
	);

	if ( duplicateError ) {
		return (
			<Stack direction="column" gap="xs">
				<InputControl
					aria-describedby={ duplicateMessageId }
					aria-invalid="true"
					required={ Boolean( field.isValid.required ) }
					label={ field.label }
					placeholder={ field.placeholder }
					value={ value ?? '' }
					onValueChange={ onValueChange }
					hideLabelFromVision={ hideLabelFromVision }
					disabled={ field.isDisabled( { item: data, field } ) }
				/>
				<Stack direction="row" align="center" gap="xs">
					<ValidityIndicator
						id={ duplicateMessageId }
						type="invalid"
						message={ duplicateError }
					/>
					{ duplicateRuleId && onViewRule && (
						<Button
							className="wc-fraud-protection-rule-form__duplicate-link"
							variant="unstyled"
							onClick={ () => onViewRule( duplicateRuleId ) }
						>
							{ __(
								'Edit existing rule',
								'woocommerce-fraud-protection'
							) }
						</Button>
					) }
				</Stack>
			</Stack>
		);
	}

	return (
		<ValidatedInputControl
			required={ Boolean( field.isValid.required ) }
			markWhenOptional={ true }
			customValidity={ validity?.custom }
			label={ field.label }
			placeholder={ field.placeholder }
			value={ value ?? '' }
			onValueChange={ onValueChange }
			hideLabelFromVision={ hideLabelFromVision }
			disabled={ field.isDisabled( { item: data, field } ) }
			type={ field.type === 'email' ? 'email' : 'text' }
			maxLength={ field.isValid.maxLength?.constraint }
		/>
	);
}

export const isCompleteIp = ( value: string ): boolean => {
	const ipv4 = value.split( '.' );
	const validIpv4 =
		ipv4.length === 4 &&
		ipv4.every(
			( part ) =>
				/^\d{1,3}$/.test( part ) &&
				( part.length === 1 || part[ 0 ] !== '0' ) &&
				Number( part ) >= 0 &&
				Number( part ) <= 255
		);
	if ( validIpv4 ) {
		return true;
	}

	let ipv6 = value;
	if ( ipv6.includes( '.' ) ) {
		const lastColon = ipv6.lastIndexOf( ':' );
		const embeddedIpv4 = ipv6.slice( lastColon + 1 );
		if ( lastColon < 0 || ! isCompleteIp( embeddedIpv4 ) ) {
			return false;
		}
		ipv6 = `${ ipv6.slice( 0, lastColon ) }:0:0`;
	}
	if ( ! ipv6.includes( ':' ) || ipv6.includes( ':::' ) ) {
		return false;
	}
	const hasCompression = ipv6.includes( '::' );
	if ( hasCompression && ipv6.indexOf( '::' ) !== ipv6.lastIndexOf( '::' ) ) {
		return false;
	}
	const groups = ipv6.split( '::' );
	const validGroups = groups.every(
		( side ) =>
			side === '' ||
			side
				.split( ':' )
				.every( ( group ) => /^[0-9a-f]{1,4}$/i.test( group ) )
	);
	if ( ! validGroups ) {
		return false;
	}
	const groupCount = groups.reduce(
		( count, side ) =>
			count + ( side === '' ? 0 : side.split( ':' ).length ),
		0
	);
	return hasCompression ? groupCount < 8 : groupCount === 8;
};

export const ruleForm: Form = {
	layout: { type: 'regular' },
	fields: [ 'action', 'type', 'value' ],
};

type RuleFormFieldsOptions = {
	type: RuleFormData[ 'type' ];
	disabled?: boolean;
	matchFieldsDisabled?: boolean;
	duplicateError?: string;
	duplicateRuleId?: number;
	onViewRule?: ( id: number ) => void;
};

export const getRuleFormFields = ( {
	type,
	disabled = false,
	matchFieldsDisabled = false,
	duplicateError,
	duplicateRuleId,
	onViewRule,
}: RuleFormFieldsOptions ): Field< RuleFormData >[] => [
	{
		id: 'action',
		label: __( 'Action', 'woocommerce-fraud-protection' ),
		type: 'text',
		Edit: 'select',
		elements: [
			{
				value: 'allow',
				label: __( 'Allow', 'woocommerce-fraud-protection' ),
			},
			{
				value: 'block',
				label: __( 'Block', 'woocommerce-fraud-protection' ),
			},
		],
		isDisabled: disabled,
		isValid: { elements: true },
	},
	{
		id: 'type',
		label: __( 'Rule type', 'woocommerce-fraud-protection' ),
		type: 'text',
		Edit: 'select',
		elements: [
			{
				value: 'email',
				label: __( 'Email address', 'woocommerce-fraud-protection' ),
			},
			{
				value: 'ip',
				label: __( 'IP address', 'woocommerce-fraud-protection' ),
			},
		],
		isDisabled: disabled || matchFieldsDisabled,
		isValid: { elements: true },
	},
	{
		id: 'value',
		label: __( 'Value', 'woocommerce-fraud-protection' ),
		type: type === 'email' ? 'email' : 'text',
		Edit: ( props ) => (
			<RuleValueEditControl
				{ ...props }
				duplicateError={ duplicateError }
				duplicateRuleId={ duplicateRuleId }
				onViewRule={ onViewRule }
			/>
		),
		placeholder: getRuleValuePlaceholder( type ),
		isDisabled: disabled || matchFieldsDisabled,
		isValid: {
			required: true,
			...( type === 'email'
				? { maxLength: 254 }
				: {
						custom: ( item ) => {
							const value = item.value.trim();
							return isCompleteIp( value )
								? null
								: __(
										'Enter a complete IP address.',
										'woocommerce-fraud-protection'
								  );
						},
				  } ),
		},
	},
];

type RuleMutation = ReturnType< typeof useRuleMutation >;

function RuleFormDescription( { isEdit }: { isEdit: boolean } ) {
	return (
		<Drawer.Description>
			{ isEdit ? (
				__(
					'Edit a rule to always allow or block checkout attempt based on an IP or email address. If a session matches both, it will always be allowed.',
					'woocommerce-fraud-protection'
				)
			) : (
				<>
					{ __(
						'Create a rule to always allow or block checkout attempt based on an IP or email address.',
						'woocommerce-fraud-protection'
					) }{ ' ' }
					{ __(
						"If an attempt matches both an allow rule and a block rule, it's allowed.",
						'woocommerce-fraud-protection'
					) }
				</>
			) }{ ' ' }
			<a
				href="https://woocommerce.com/document/fraud-protection/"
				target="_blank"
				rel="noopener noreferrer"
			>
				{ __( 'Learn more', 'woocommerce-fraud-protection' ) }
			</a>
		</Drawer.Description>
	);
}

function RuleForm( {
	context,
	mutation,
	onClose,
	onViewRule,
	rule,
}: {
	context?: RuleFormContext;
	mutation: RuleMutation;
	onClose: () => void;
	onViewRule?: ( id: number ) => void;
	rule?: Rule;
} ) {
	const { clearSaveError, isSaving, saveError, saveRule } = mutation;
	const [ data, setData ] = useState< RuleFormData >( () =>
		rule
			? { action: rule.action, type: rule.type, value: rule.value }
			: getInitialRuleFormData( context )
	);

	useEffect( () => clearSaveError(), [ clearSaveError ] );

	const hasDuplicateError = saveError?.code === DUPLICATE_RULE_ERROR;
	const fields = useMemo< Field< RuleFormData >[] >(
		() =>
			getRuleFormFields( {
				type: data.type,
				disabled: isSaving,
				matchFieldsDisabled: Boolean( context ),
				duplicateError: hasDuplicateError
					? saveError?.message
					: undefined,
				duplicateRuleId: saveError?.ruleId ?? undefined,
				onViewRule,
			} ),
		[
			context,
			data.type,
			hasDuplicateError,
			isSaving,
			onViewRule,
			saveError,
		]
	);
	const { validity, isValid } = useFormValidity( data, fields, ruleForm );

	const save = async () => {
		if ( ! isValid || hasDuplicateError || isSaving ) {
			return;
		}
		const result = await saveRule( rule?.id, {
			action: data.action,
			type: data.type,
			value: data.value,
			...( rule
				? { origin: context ? 'checkout_attempts' : 'rules' }
				: {
						recorded_attempt_id: context?.recordedAttemptId,
						origin: context ? 'checkout_attempts' : 'rules',
				  } ),
		} );
		if ( result ) {
			onClose();
		}
	};

	return (
		<>
			<Drawer.Content>
				<Stack direction="column" gap="lg">
					<RuleFormDescription isEdit={ Boolean( rule ) } />
					<DataForm< RuleFormData >
						data={ data }
						fields={ fields }
						form={ ruleForm }
						validity={ validity }
						onChange={ ( changes ) => {
							if ( isSaving ) {
								return;
							}
							clearSaveError();
							setData( ( previous ) => ( {
								...previous,
								...changes,
							} ) );
						} }
					/>
					{ saveError && ! hasDuplicateError && (
						<Notice.Root intent="error">
							<Notice.Description>
								{ saveError.message }
							</Notice.Description>
						</Notice.Root>
					) }
					{ rule && (
						<Text
							variant="body-md"
							className="wc-fraud-protection-rule-form__metadata"
						>
							{ rule.updated_at
								? sprintf(
										/* translators: 1: Rule creation date. 2: Rule last update date. */
										__(
											'This rule was created on %1$s and last updated on %2$s.',
											'woocommerce-fraud-protection'
										),
										formatRuleDate( rule.created_at ),
										formatRuleDate( rule.updated_at )
								  )
								: sprintf(
										/* translators: %s: Rule creation date. */
										__(
											'This rule was created on %s.',
											'woocommerce-fraud-protection'
										),
										formatRuleDate( rule.created_at )
								  ) }
						</Text>
					) }
				</Stack>
			</Drawer.Content>
			<Drawer.Footer>
				<Button
					variant="solid"
					type="button"
					onClick={ save }
					loading={ isSaving }
					disabled={ ! isValid || hasDuplicateError || isSaving }
				>
					{ rule
						? __( 'Save changes', 'woocommerce-fraud-protection' )
						: __( 'Create rule', 'woocommerce-fraud-protection' ) }
				</Button>
			</Drawer.Footer>
		</>
	);
}

export function RuleFormDrawer( {
	open,
	onClose,
	onViewRule,
	context,
	ruleId,
}: RuleFormDrawerProps ) {
	const isEdit = ruleId !== undefined;
	const { rule, isLoading, error: detailError, retry } = useRule( ruleId );
	const mutation = useRuleMutation();
	const canRenderForm = ! isEdit || ( ! isLoading && Boolean( rule ) );
	let formIdentity = 'create';
	if ( rule ) {
		formIdentity = `edit-${ rule.id }`;
	} else if ( context ) {
		formIdentity = `context-${ context.recordedAttemptId }`;
	}
	const formKey = `${ open ? 'open' : 'closed' }-${ formIdentity }`;

	return (
		<Drawer.Root
			open={ open }
			onOpenChange={ ( nextOpen, eventDetails ) => {
				if ( ! nextOpen && mutation.isSaving ) {
					eventDetails.cancel();
					return;
				}
				if ( ! nextOpen ) {
					onClose();
				}
			} }
			swipeDirection="right"
		>
			<Drawer.Popup
				portal={
					<Drawer.Portal className="wc-fraud-protection-rule-form__drawer-portal" />
				}
			>
				<Drawer.Header>
					<Drawer.Title>
						{ isEdit
							? __( 'Edit rule', 'woocommerce-fraud-protection' )
							: __(
									'Create rule',
									'woocommerce-fraud-protection'
							  ) }
					</Drawer.Title>
					<Drawer.CloseIcon
						label={ __( 'Close', 'woocommerce-fraud-protection' ) }
						disabled={ mutation.isSaving }
					/>
				</Drawer.Header>
				{ canRenderForm ? (
					<RuleForm
						key={ formKey }
						context={ context }
						mutation={ mutation }
						onClose={ onClose }
						onViewRule={ onViewRule }
						rule={ isEdit ? rule : undefined }
					/>
				) : (
					<Drawer.Content>
						<Stack direction="column" gap="lg">
							<RuleFormDescription isEdit />
							{ isLoading && (
								<Stack className="wc-fraud-protection-rule-form__loading">
									<Spinner />
									<VisuallyHidden>
										{ __(
											'Loading rule',
											'woocommerce-fraud-protection'
										) }
									</VisuallyHidden>
								</Stack>
							) }
							{ ! isLoading && detailError && (
								<Notice.Root intent="error">
									<Notice.Description>
										{ detailError }
									</Notice.Description>
									<Notice.Actions>
										<Notice.ActionButton onClick={ retry }>
											{ __(
												'Retry',
												'woocommerce-fraud-protection'
											) }
										</Notice.ActionButton>
									</Notice.Actions>
								</Notice.Root>
							) }
						</Stack>
					</Drawer.Content>
				) }
			</Drawer.Popup>
		</Drawer.Root>
	);
}
