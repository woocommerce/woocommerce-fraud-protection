import {
	useCallback,
	useEffect,
	useId,
	useMemo,
	useState,
} from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { DataForm, useFormValidity } from '@wordpress/dataviews/wp';
import type { DataFormControlProps, Field, Form } from '@wordpress/dataviews';
import { useDispatch } from '@wordpress/data';
import { store as noticesStore } from '@wordpress/notices';
import { Button as ComponentsButton } from '@wordpress/components';
import {
	Button,
	Drawer,
	InputControl,
	Notice,
	Stack,
	Text,
	ValidatedInputControl,
	ValidityIndicator,
} from '@wordpress/ui';

import type { CreateRuleRequest, Rule } from '../data/rules-store';
import { useRules } from '../hooks/use-rules';

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

export const isCompleteEmail = ( value: string ): boolean => {
	const trimmedValue = value.trim();
	return (
		[ ...trimmedValue ].length <= 254 && /^\S+@\S+$/.test( trimmedValue )
	);
};

type RuleFormDrawerProps = {
	open: boolean;
	onClose: () => void;
	onSuccess?: () => void;
	onViewRule?: ( id: number ) => void;
	context?: RuleFormContext;
	rule?: Rule;
};

const DUPLICATE_RULE_ERROR = 'woocommerce_fraud_protection_duplicate_rule';

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
						<ComponentsButton
							variant="link"
							onClick={ () => onViewRule( duplicateRuleId ) }
						>
							{ __(
								'Edit existing rule',
								'woocommerce-fraud-protection'
							) }
						</ComponentsButton>
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
		type: 'text',
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
			custom: ( item ) => {
				const value = item.value.trim();
				if ( item.type === 'email' ) {
					return isCompleteEmail( value )
						? null
						: __(
								'Enter a complete email address.',
								'woocommerce-fraud-protection'
						  );
				}
				return isCompleteIp( value )
					? null
					: __(
							'Enter a complete IP address.',
							'woocommerce-fraud-protection'
					  );
			},
		},
	},
];

export function RuleFormDrawer( {
	open,
	onClose,
	onSuccess,
	onViewRule,
	context,
	rule,
}: RuleFormDrawerProps ) {
	const { createRule, updateRule } = useRules();
	const [ data, setData ] = useState< RuleFormData >(
		rule
			? { action: rule.action, type: rule.type, value: rule.value }
			: getInitialRuleFormData( context )
	);
	const [ saveError, setSaveError ] = useState< {
		code: string | null;
		message: string;
		ruleId: number | null;
	} | null >( null );
	const [ isSaving, setIsSaving ] = useState( false );
	const noticesDispatch = useDispatch( noticesStore ) as {
		createSuccessNotice?: (
			message: string,
			options: { type: string }
		) => void;
	} | null;
	useEffect( () => {
		if ( open ) {
			setData(
				rule
					? {
							action: rule.action,
							type: rule.type,
							value: rule.value,
					  }
					: getInitialRuleFormData( context )
			);
			setSaveError( null );
		}
	}, [ open, context, rule ] );
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
		setSaveError( null );
		setIsSaving( true );
		try {
			if ( rule ) {
				await updateRule( rule.id, {
					action: data.action,
					type: data.type,
					value: data.value,
					origin: context ? 'checkout_attempts' : 'rules',
				} );
			} else {
				await createRule( {
					action: data.action,
					type: data.type,
					value: data.value,
					recorded_attempt_id: context?.recordedAttemptId,
					origin: context ? 'checkout_attempts' : 'rules',
				} );
			}
			onClose();
			onSuccess?.();
			noticesDispatch?.createSuccessNotice?.(
				rule
					? __(
							'Rule updated successfully',
							'woocommerce-fraud-protection'
					  )
					: __(
							'Rule created successfully',
							'woocommerce-fraud-protection'
					  ),
				{ type: 'snackbar' }
			);
		} catch ( caughtError ) {
			const apiError = caughtError as {
				code?: unknown;
				message?: unknown;
				data?: { rule_id?: unknown };
			};
			const fallbackMessage = rule
				? __(
						'The rule could not be updated.',
						'woocommerce-fraud-protection'
				  )
				: __(
						'The rule could not be created.',
						'woocommerce-fraud-protection'
				  );
			const error = {
				code: typeof apiError.code === 'string' ? apiError.code : null,
				message:
					typeof apiError.message === 'string'
						? apiError.message
						: fallbackMessage,
				ruleId:
					typeof apiError.data?.rule_id === 'number'
						? apiError.data.rule_id
						: null,
			};
			setSaveError( error );
		} finally {
			setIsSaving( false );
		}
	};

	return (
		<Drawer.Root
			open={ open }
			onOpenChange={ ( nextOpen ) =>
				! nextOpen && ! isSaving && onClose()
			}
			swipeDirection="right"
		>
			<Drawer.Popup
				size="medium"
				portal={
					<Drawer.Portal
						style={
							{
								'--wp-ui-drawer-z-index': 100000,
							} as React.CSSProperties
						}
					/>
				}
			>
				<Drawer.Header>
					<Drawer.Title>
						{ rule
							? __( 'Edit rule', 'woocommerce-fraud-protection' )
							: __(
									'Create rule',
									'woocommerce-fraud-protection'
							  ) }
					</Drawer.Title>
					<Drawer.CloseIcon
						label={ __( 'Close', 'woocommerce-fraud-protection' ) }
						disabled={ isSaving }
					/>
				</Drawer.Header>
				<Drawer.Content>
					<Stack direction="column" gap="lg">
						<Text variant="body-md">
							{ rule ? (
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
								{ __(
									'Learn more',
									'woocommerce-fraud-protection'
								) }
							</a>
						</Text>
						<DataForm< RuleFormData >
							data={ data }
							fields={ fields }
							form={ ruleForm }
							validity={ validity }
							onChange={ ( changes ) => {
								if ( isSaving ) {
									return;
								}
								setSaveError( null );
								setData( ( previous ) => ( {
									...previous,
									...changes,
								} ) );
							} }
						/>
						{ saveError?.code !== DUPLICATE_RULE_ERROR &&
							saveError?.message && (
								<Notice.Root intent="error">
									<Notice.Description>
										{ saveError.message }
									</Notice.Description>
								</Notice.Root>
							) }
					</Stack>
				</Drawer.Content>
				<Drawer.Footer>
					<Button
						variant="solid"
						onClick={ save }
						loading={ isSaving }
						disabled={ ! isValid || hasDuplicateError || isSaving }
					>
						{ rule
							? __(
									'Save changes',
									'woocommerce-fraud-protection'
							  )
							: __(
									'Create rule',
									'woocommerce-fraud-protection'
							  ) }
					</Button>
				</Drawer.Footer>
			</Drawer.Popup>
		</Drawer.Root>
	);
}
