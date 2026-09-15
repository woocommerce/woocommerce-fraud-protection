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

type RuleFormData = Pick< CreateRuleRequest, 'action' | 'type' | 'value' > & {
	valueError?: string;
};

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
	type === 'email' ? 'e.g. j.holland@gmail.com' : 'e.g. 111.111.111.111';

type RuleFormDrawerProps = {
	open: boolean;
	onClose: () => void;
	onSuccess?: () => void;
	context?: RuleFormContext;
};

const DUPLICATE_RULE_ERROR = 'woocommerce_fraud_protection_duplicate_rule';

function RuleValueEditControl( {
	data,
	field,
	onChange,
	hideLabelFromVision,
	validity,
}: DataFormControlProps< RuleFormData > ) {
	const duplicateMessageId = useId();
	const value = field.getValue( { item: data } );
	const onValueChange = useCallback(
		( newValue: string ) =>
			onChange( field.setValue( { item: data, value: newValue } ) ),
		[ data, field, onChange ]
	);

	if ( data.valueError ) {
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
				<ValidityIndicator
					id={ duplicateMessageId }
					type="invalid"
					message={ data.valueError }
				/>
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

const form: Form = {
	layout: { type: 'regular' },
	fields: [ 'action', 'type', 'value' ],
};

export function RuleFormDrawer( {
	open,
	onClose,
	onSuccess,
	context,
}: RuleFormDrawerProps ) {
	const { createRule } = useRules();
	const [ data, setData ] = useState< RuleFormData >(
		getInitialRuleFormData( context )
	);
	const [ createError, setCreateError ] = useState< {
		code: string | null;
		message: string;
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
			setData( getInitialRuleFormData( context ) );
			setCreateError( null );
		}
	}, [ open, context ] );

	const fields = useMemo< Field< RuleFormData >[] >(
		() => [
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
						label: __(
							'Email address',
							'woocommerce-fraud-protection'
						),
					},
					{
						value: 'ip',
						label: __(
							'IP address',
							'woocommerce-fraud-protection'
						),
					},
				],
				isDisabled: Boolean( context ),
				isValid: { elements: true },
			},
			{
				id: 'value',
				label: __( 'Value', 'woocommerce-fraud-protection' ),
				type: 'text',
				Edit: RuleValueEditControl,
				placeholder: getRuleValuePlaceholder( data.type ),
				isDisabled: Boolean( context ),
				isValid: {
					required: true,
					custom: ( item ) => {
						if ( item.valueError ) {
							return item.valueError;
						}
						const value = item.value.trim();
						if ( item.type === 'email' ) {
							return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test( value )
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
		],
		[ context, data.type ]
	);
	const { validity, isValid } = useFormValidity( data, fields, form );
	const hasDuplicateError = Boolean( data.valueError );

	const save = async () => {
		if ( ! isValid || hasDuplicateError || isSaving ) {
			return;
		}
		setCreateError( null );
		setIsSaving( true );
		try {
			await createRule( {
				action: data.action,
				type: data.type,
				value: data.value,
				recorded_attempt_id: context?.recordedAttemptId,
				origin: context ? 'checkout_attempts' : 'rules',
			} );
			onClose();
			onSuccess?.();
			noticesDispatch?.createSuccessNotice?.(
				__(
					'Rule created successfully',
					'woocommerce-fraud-protection'
				),
				{ type: 'snackbar' }
			);
		} catch ( caughtError ) {
			const apiError = caughtError as {
				code?: unknown;
				message?: unknown;
			};
			const error = {
				code: typeof apiError.code === 'string' ? apiError.code : null,
				message:
					typeof apiError.message === 'string'
						? apiError.message
						: __(
								'The rule could not be created.',
								'woocommerce-fraud-protection'
						  ),
			};
			setCreateError( error );
			if ( error.code === DUPLICATE_RULE_ERROR ) {
				setData( ( previous ) => ( {
					...previous,
					valueError: error.message,
				} ) );
			}
		} finally {
			setIsSaving( false );
		}
	};

	return (
		<Drawer.Root
			open={ open }
			onOpenChange={ ( nextOpen ) => ! nextOpen && onClose() }
			swipeDirection="right"
		>
			<Drawer.Popup
				size="medium"
				portal={
					<Drawer.Portal
						style={ { position: 'relative', zIndex: 1002 } }
					/>
				}
			>
				<Drawer.Header>
					<Drawer.Title>
						{ __( 'Create rule', 'woocommerce-fraud-protection' ) }
					</Drawer.Title>
					<Drawer.CloseIcon
						label={ __( 'Close', 'woocommerce-fraud-protection' ) }
					/>
				</Drawer.Header>
				<Drawer.Content>
					<Stack direction="column" gap="lg">
						<Text variant="body-md">
							{ __(
								'Create a rule to always allow or block checkout attempt based on an IP or email address.',
								'woocommerce-fraud-protection'
							) }{ ' ' }
							{ __(
								"If an attempt matches both an allow rule and a block rule, it's allowed.",
								'woocommerce-fraud-protection'
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
							form={ form }
							validity={ validity }
							onChange={ ( changes ) => {
								setCreateError( null );
								setData( ( previous ) => ( {
									...previous,
									...changes,
									valueError: undefined,
								} ) );
							} }
						/>
						{ createError?.code !== DUPLICATE_RULE_ERROR &&
							createError?.message && (
								<Notice.Root intent="error">
									<Notice.Description>
										{ createError.message }
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
						{ __( 'Create rule', 'woocommerce-fraud-protection' ) }
					</Button>
				</Drawer.Footer>
			</Drawer.Popup>
		</Drawer.Root>
	);
}
