import type { ComponentProps } from 'react';
import { useEffect, useId, useRef, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { InputControl, Stack } from '@wordpress/ui';

import { ValidityIndicator } from './validity-indicator';

type ValidatedInputControlProps = ComponentProps< typeof InputControl > & {
	required?: boolean;
	markWhenOptional?: boolean;
	customValidity?: {
		type: 'valid' | 'invalid' | 'validating';
		message?: string;
	};
};

const getLabel = (
	label: string,
	required?: boolean,
	markWhenOptional?: boolean
): string => {
	if ( required && ! markWhenOptional ) {
		return `${ label } (${ __(
			'Required',
			'woocommerce-fraud-protection'
		) })`;
	}
	if ( ! required && markWhenOptional ) {
		return `${ label } (${ __(
			'Optional',
			'woocommerce-fraud-protection'
		) })`;
	}
	return label;
};

// An `InputControl` that reports native constraint violations and the custom
// validity message inline, once the control has lost focus for the first time.
export function ValidatedInputControl( {
	required,
	markWhenOptional,
	customValidity,
	label,
	value,
	onBlur,
	...props
}: ValidatedInputControlProps ) {
	const inputRef = useRef< HTMLInputElement >( null );
	const messageId = useId();
	const [ isTouched, setIsTouched ] = useState( false );
	const [ errorMessage, setErrorMessage ] = useState( '' );

	useEffect( () => {
		const input = inputRef.current;
		if ( ! input ) {
			return;
		}
		input.setCustomValidity(
			customValidity?.type === 'invalid'
				? customValidity.message ?? ''
				: ''
		);
		setErrorMessage( input.validationMessage );
	}, [ customValidity, value ] );

	const showError = isTouched && errorMessage !== '';

	return (
		<Stack
			className="wc-fraud-protection-validated-input"
			direction="column"
			gap="sm"
		>
			<InputControl
				{ ...props }
				ref={ inputRef }
				label={ getLabel( label, required, markWhenOptional ) }
				required={ required }
				value={ value }
				aria-invalid={ showError || undefined }
				aria-describedby={ showError ? messageId : undefined }
				onBlur={ ( event ) => {
					setIsTouched( true );
					onBlur?.( event );
				} }
			/>
			{ showError && (
				<ValidityIndicator
					id={ messageId }
					type="invalid"
					message={ errorMessage }
				/>
			) }
		</Stack>
	);
}
