import type { ActionButton } from '@wordpress/dataviews';

type Item = {
	id: number;
	value: string;
};

type DataViewsProps = {
	actions?: ActionButton< Item >[];
	children?: React.ReactNode;
	data: Item[];
};

export function DataViews( { actions = [], children, data }: DataViewsProps ) {
	return (
		<>
			{ children }
			<table>
				<tbody>
					{ data.map( ( item ) => (
						<tr key={ item.id }>
							<td>{ item.value }</td>
							<td>
								{ actions.map( ( action ) => (
									<button
										key={ action.id }
										type="button"
										onClick={ () =>
											action.callback( [ item ], {
												registry: undefined,
											} )
										}
									>
										{ typeof action.label === 'function'
											? action.label( [ item ] )
											: action.label }
									</button>
								) ) }
							</td>
						</tr>
					) ) }
				</tbody>
			</table>
		</>
	);
}

export namespace DataViews {
	export function FiltersToggle() {
		return null;
	}

	export function ViewConfig() {
		return null;
	}

	export function FiltersToggled() {
		return null;
	}

	export function Layout() {
		return null;
	}

	export function Footer() {
		return null;
	}
}
