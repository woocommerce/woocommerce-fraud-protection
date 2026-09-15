import type { Field, View } from '@wordpress/dataviews';

type Rule = {
	id: number;
	action: string;
	value: string;
	type: string;
	created_at: string;
};

type DataViewsProps = {
	data: Rule[];
	children?: React.ReactNode;
	fields?: Field< Rule >[];
	view?: View;
	onChangeView?: ( view: View ) => void;
};

export const dataViews = {
	props: undefined as DataViewsProps | undefined,
};

export function DataViews( props: DataViewsProps ) {
	dataViews.props = props;
	const { data, children } = props;
	return (
		<>
			{ children }
			<table aria-label="Rules">
				<tbody>
					{ data.map( ( rule ) => (
						<tr key={ rule.id }>
							<td>{ rule.action }</td>
							<td>{ rule.value }</td>
							<td>{ rule.type }</td>
							<td>{ rule.created_at }</td>
						</tr>
					) ) }
				</tbody>
			</table>
		</>
	);
}

export namespace DataViews {
	export function FiltersToggle() {
		return <button type="button" aria-label="Add filter" />;
	}

	export function ViewConfig() {
		return <button type="button" aria-label="View options" />;
	}

	export function FiltersToggled() {
		return null;
	}

	export function Layout() {
		return null;
	}

	export function Pagination() {
		return null;
	}
}
