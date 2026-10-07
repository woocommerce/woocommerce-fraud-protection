import type { ComponentProps } from 'react';

// A placeholder shown while content is loading. Decorative: the loading region
// announces itself with aria-busy and a status message.
export function Skeleton( { className, ...props }: ComponentProps< 'div' > ) {
	return (
		<div
			aria-hidden="true"
			className={ [ 'wc-fraud-protection-skeleton', className ]
				.filter( Boolean )
				.join( ' ' ) }
			{ ...props }
		/>
	);
}
