import type {
	DataViewRenderFieldProps,
	Field,
	NormalizedField,
	View,
} from '@wordpress/dataviews';
import type { ComponentType } from 'react';

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
	empty?: React.ReactNode;
	fields?: Field< Rule >[];
	isLoading?: boolean;
	view?: View;
	onChangeView?: ( view: View ) => void;
};

export const dataViews = {
	props: undefined as DataViewsProps | undefined,
};

export function DataViews( props: DataViewsProps ) {
	dataViews.props = props;
	const { data, children, empty, fields = [] } = props;
	return (
		<>
			{ children }
			<table aria-label="Rules">
				<tbody>
					{ data.map( ( rule ) => {
						return (
							<tr key={ rule.id }>
								{ fields.map( ( field ) => {
									const FieldRenderer = field.render as
										| ComponentType<
												DataViewRenderFieldProps< Rule >
										  >
										| undefined;
									return (
										<td key={ field.id }>
											{ FieldRenderer ? (
												<FieldRenderer
													item={ rule }
													field={
														field as NormalizedField< Rule >
													}
												/>
											) : (
												String(
													rule[
														field.id as keyof Rule
													]
												)
											) }
										</td>
									);
								} ) }
							</tr>
						);
					} ) }
				</tbody>
			</table>
			{ data.length === 0 && empty }
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
