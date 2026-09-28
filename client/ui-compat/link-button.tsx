import type { ComponentProps } from 'react';
import { Link } from '@wordpress/ui';

type LinkButtonProps = Omit< ComponentProps< typeof Link >, 'variant' > & {
	variant?: 'minimal';
	size?: 'default' | 'compact';
};

// A link that looks like a minimal brand `Button`. It stays a link for
// assistive technology, unlike a `Button` rendered as an anchor.
export function LinkButton( {
	variant = 'minimal',
	size = 'default',
	className,
	...props
}: LinkButtonProps ) {
	return (
		<Link
			variant="unstyled"
			className={ [
				'wc-fraud-protection-link-button',
				`is-${ variant }`,
				`is-${ size }`,
				className,
			]
				.filter( Boolean )
				.join( ' ' ) }
			{ ...props }
		/>
	);
}
