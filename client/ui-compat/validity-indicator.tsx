import { Icon } from '@wordpress/ui';
import { error, published } from '@wordpress/icons';

type ValidityIndicatorProps = {
	id?: string;
	type: 'valid' | 'invalid';
	message?: string;
};

// A validity message for a form control, with an icon for its state.
export function ValidityIndicator( {
	id,
	type,
	message,
}: ValidityIndicatorProps ) {
	return (
		<p
			id={ id }
			className={ `wc-fraud-protection-validity-indicator is-${ type }` }
		>
			<Icon
				className="wc-fraud-protection-validity-indicator__icon"
				icon={ type === 'valid' ? published : error }
				size={ 16 }
				fill="currentColor"
				aria-hidden="true"
			/>
			{ message }
		</p>
	);
}
